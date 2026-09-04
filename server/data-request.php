<?php
// Data-subject requests.
//
//   POST /data-request.php                       public — make a request
//   GET  /data-request.php                       staff  — the queue
//   POST /data-request.php?action=status&id=N    staff  — move it on
//
// The privacy notice used to say "email us", which is a mailbox, not a process:
// nothing recorded that a request had arrived, nothing showed how long it had
// been waiting, and nothing evidenced what was done about it.
//
// What this deliberately does NOT do:
//
//   - It does not ask for identity documents. A public form is the wrong place
//     to collect a passport scan, and collecting more personal data in order to
//     handle a request about personal data is the wrong instinct. Verification
//     happens afterwards, over a channel the requester already controls.
//   - It does not self-serve. Nothing here reads, changes or erases a record.
//     It opens a case for a person to handle, because the right answer to
//     "delete everything about me" depends on employment and safeguarding
//     duties that a form cannot weigh.
//   - It states no deadline. The period, and how it is counted, is a matter for
//     the business and its advisers; a number hard-coded here would be trusted
//     and would go stale. See docs/UK_GOVERNANCE.md.
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

const REQUEST_KINDS = [
    'access', 'correction', 'deletion', 'objection',
    'restriction', 'portability', 'withdraw_consent', 'other',
];

// ----- Public: make a request ----------------------------------------------
if (method() === 'POST' && $action === '') {
    rate_limit($conn, 'data-request', 5, 600);

    $data = body();
    // CR/LF out of anything that reaches a mail header.
    $clean = static fn (string $v): string => trim(str_replace(["\r", "\n"], ' ', $v));

    $name = $clean((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $kind = trim((string) ($data['kind'] ?? 'access'));
    $relationship = $clean((string) ($data['relationship'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));

    if ($name === '' || $email === '') fail('Please give a name and an email address we can reply to.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');
    if (mb_strlen($name) > 160) fail('That name is too long.');
    if (mb_strlen($detail) > 2000) fail('Please keep the detail under 2000 characters.');
    if (!in_array($kind, REQUEST_KINDS, true)) $kind = 'other';

    $ref = 'DSR-' . str_pad((string) next_seq($conn, 'data_request'), 4, '0', STR_PAD_LEFT);
    $ip = client_ip();
    $relationshipVal = $relationship !== '' ? mb_substr($relationship, 0, 120) : null;
    $detailVal = $detail !== '' ? $detail : null;

    $stmt = $conn->prepare(
        'INSERT INTO data_requests (ref, kind, name, email, relationship, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssss', $ref, $kind, $name, $email, $relationshipVal, $detailVal, $ip);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    // Staff need to see it, and the requester needs to know it landed. Queued
    // rather than sent, like every other outbound message here.
    notify($conn, null, "Data request $ref", "$name asked to $kind their data.", 'warning', '/app/settings');

    if (defined('MAIL_TO') && MAIL_TO !== '') {
        queue_mail(
            $conn,
            MAIL_TO,
            "Data-subject request $ref — $kind",
            "A data request came in from the website.\n\n"
            . "Reference:    $ref\n"
            . "Type:         $kind\n"
            . "Name:         $name\n"
            . "Email:        $email\n"
            . "Relationship: " . ($relationshipVal ?? '—') . "\n\n"
            . "Detail:\n" . ($detailVal ?? '—') . "\n\n"
            . "Acknowledge it, confirm who they are before disclosing anything, "
            . "and record the outcome against $ref.",
            $email,
            null,
            'data_request',
            $id
        );
    }

    queue_mail(
        $conn,
        $email,
        "We have your data request ($ref)",
        "Hi $name,\n\n"
        . "We have received your request and given it the reference $ref.\n\n"
        . "Someone will reply to this address. Before we can share or change anything "
        . "we need to be sure the request came from you, so we may ask you to confirm "
        . "something we already hold — we will never ask you to email us a passport or "
        . "driving licence.\n\n"
        . "If you did not make this request, reply to this email and tell us.\n\n"
        . "— RitaGreen Services",
        defined('MAIL_TO') ? MAIL_TO : null,
        null,
        'data_request',
        $id
    );

    audit($conn, null, 'data_request.create', "$ref ($kind)");
    json_out(['ok' => true, 'ref' => $ref], 201);
}

// ----- Staff: the queue ------------------------------------------------------
$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT r.id, r.ref, r.kind, r.name, r.email, r.relationship, r.detail, r.status,
                r.acknowledged_at, r.completed_at, r.outcome_note, r.created_at, u.name AS handled_by
         FROM data_requests r
         LEFT JOIN users u ON u.id = r.handled_by
         ORDER BY FIELD(r.status, "received","verifying","in_progress","completed","refused","withdrawn"), r.created_at'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $items[] = $row;
    }
    // Reading a list of who has asked about their own data is itself worth
    // recording, for the same reason the audit log filter is.
    audit($conn, (int) $user['id'], 'data_request.list', (string) count($items) . ' request(s)');
    json_out(['requests' => $items]);
}

if (method() === 'POST' && $action === 'status') {
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $status = trim((string) ($data['status'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $valid = ['received', 'verifying', 'in_progress', 'completed', 'refused', 'withdrawn'];
    if ($id <= 0 || !in_array($status, $valid, true)) fail('A request and a valid status are required.');
    if (in_array($status, ['completed', 'refused'], true) && mb_strlen($note) < 10) {
        fail('Say what was done, in at least 10 characters. A closed request with no reason is not a record.');
    }

    $stmt = $conn->prepare('SELECT ref, status FROM data_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    if (!$before) fail('Request not found.', 404);

    $uid = (int) $user['id'];
    $noteVal = $note !== '' ? mb_substr($note, 0, 1000) : null;
    $stmt = $conn->prepare(
        'UPDATE data_requests
         SET status = ?, outcome_note = COALESCE(?, outcome_note), handled_by = ?,
             acknowledged_at = COALESCE(acknowledged_at, NOW()),
             completed_at = CASE WHEN ? IN ("completed","refused","withdrawn") THEN NOW() ELSE completed_at END
         WHERE id = ?'
    );
    $stmt->bind_param('ssisi', $status, $noteVal, $uid, $status, $id);
    $stmt->execute();

    audit(
        $conn, $uid, 'data_request.status', $before['ref'],
        'data_request', $id,
        ['status' => $before['status']], ['status' => $status], $noteVal
    );
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
