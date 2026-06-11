<?php
// Communication hub — broadcast announcements to the whole team or one division.
// GET  /comms.php                 -> { announcements: [...] }  (visible to the user)
// POST /comms.php {audience, title, body}  -> create (role-gated)
require __DIR__ . '/lib.php';

$user = require_auth($conn);

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

    audit($conn, $authorId, 'announcement.create', "$audience: $title");
    json_out([
        'announcement' => [
            'id' => (int) $id, 'audience' => $audience, 'title' => $title,
            'body' => $text, 'author' => $user['name'],
        ],
    ], 201);
}

fail('Method not allowed.', 405);
