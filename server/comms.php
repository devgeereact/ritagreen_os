<?php
// Communication hub — broadcast announcements to the whole team or one division,
// and the outgoing email queue.
// GET  /comms.php                          -> { announcements: [...] }  (visible to the user)
// POST /comms.php {audience, title, body}  -> create (role-gated)
// GET  /comms.php?action=queue[&status=]   -> { messages, counts, mail_enabled }
// POST /comms.php?action=requeue&id=N      -> put a failed or cancelled message back
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Internal announcements. A client portal or worker login is not the team.
require_staff($user);

$action = $_GET['action'] ?? '';

// ---- The outgoing email queue ----------------------------------------------
//
// queue_mail() has written to email_queue since migration 008 and nothing read
// it back. A message that failed five times, or that was never sent because mail
// is switched off, left no trace anybody would see -- so "we emailed them" and
// "nobody emailed them" looked identical from inside the application.
//
// Recipients and subjects are correspondence with named people, so this is
// office-only rather than staff-wide.

if ($action === 'queue' && method() === 'GET') {
    require_role($user, 'super_admin', 'admin', 'manager');

    $counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
    $res = $conn->query('SELECT status, COUNT(*) c FROM email_queue GROUP BY status');
    while ($row = $res->fetch_assoc()) $counts[$row['status']] = (int) $row['c'];

    $status = (string) ($_GET['status'] ?? '');
    $valid = array_keys($counts);
    $where = '';
    if ($status !== '' && in_array($status, $valid, true)) {
        $where = " WHERE status = '" . $conn->real_escape_string($status) . "'";
    }

    // Newest first, and the ones that need attention are the reason to look.
    $res = $conn->query(
        'SELECT id, recipient, subject, template_key, related_type, related_id,
                status, attempts, last_error, send_after, created_at, sent_at
           FROM email_queue' . $where . '
          ORDER BY (status IN ("failed","cancelled")) DESC, id DESC
          LIMIT 200'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['attempts'] = (int) $row['attempts'];
        $row['related_id'] = $row['related_id'] !== null ? (int) $row['related_id'] : null;
        $items[] = $row;
    }

    json_out([
        'messages' => $items,
        'counts' => $counts,
        // Said plainly rather than inferred from an empty sent list. Locally and
        // in tests this is false, and every message is cancelled as "skipped" --
        // which is not the same as a delivery failure and must not read like one.
        'mail_enabled' => mail_enabled(),
        'max_attempts' => 5,
    ]);
}

if ($action === 'requeue' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A message is required.');

    $stmt = $conn->prepare('SELECT id, recipient, subject, status FROM email_queue WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Message not found.', 404);

    // Only something that has stopped. Re-queueing a message that is already
    // queued or in flight is how one person gets the same email twice.
    if (!in_array($row['status'], ['failed', 'cancelled'], true)) {
        fail('That message has not stopped — it is ' . $row['status'] . '.', 409);
    }

    // attempts back to 0, because the drainer refuses anything at the limit and
    // a retry that cannot be attempted is not a retry.
    $conn->query('UPDATE email_queue SET status = "queued", attempts = 0, last_error = NULL WHERE id = ' . $id);
    audit($conn, (int) $user['id'], 'mail.requeue', $row['recipient'] . ' — ' . $row['subject'],
        'email_queue', $id, ['status' => $row['status']], ['status' => 'queued']);
    json_out(['ok' => true]);
}

if (method() === 'GET') {
    // A user sees group-wide announcements plus those for their division.
    $division = $user['division'] ?? '';
    $stmt = $conn->prepare(
        'SELECT a.id, a.audience, a.title, a.body, a.created_at, u.name AS author
         FROM announcements a LEFT JOIN users u ON u.id = a.author_id
         WHERE a.audience = "all" OR a.audience = ?
         ORDER BY a.id DESC LIMIT 100'
    );
    $stmt->bind_param('s', $division);
    $stmt->execute();
    $items = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $items[] = $row;
    }
    json_out(['announcements' => $items]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $audience = trim((string) ($data['audience'] ?? 'all'));
    $title = trim((string) ($data['title'] ?? ''));
    $text = trim((string) ($data['body'] ?? ''));

    if ($title === '' || $text === '') fail('A title and message are required.');
    if (!in_array($audience, ['all', 'care', 'academy', 'cleaning', 'security'], true)) fail('Invalid audience.');
    if (mb_strlen($text) > 5000) fail('That message is too long.');

    $authorId = (int) $user['id'];
    $stmt = $conn->prepare('INSERT INTO announcements (author_id, audience, title, body) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $authorId, $audience, $title, $text);
    $stmt->execute();
    $id = $conn->insert_id;

    // Surface the announcement on every user's notification bell.
    notify($conn, null, $title, mb_substr($text, 0, 160), 'announcement', '/app/comms');

    audit($conn, $authorId, 'announcement.create', "$audience: $title");
    json_out([
        'announcement' => [
            'id' => (int) $id, 'audience' => $audience, 'title' => $title,
            'body' => $text, 'author' => $user['name'],
        ],
    ], 201);
}

fail('Method not allowed.', 405);
