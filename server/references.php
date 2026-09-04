<?php
// Worker reference-check tracker (2+ references, gaps explained).
// GET  /references.php?worker_id=N    -> { references: [...] }
// POST /references.php {worker_id, referee_name, referee_org, relationship, email, phone} -> add
// POST /references.php?action=status&id=N {status, gap_note} -> update status / explain a gap
require __DIR__ . '/lib.php';

$user = require_auth($conn);

if (method() === 'GET') {
    // Referee name, employer, email and phone belong to a third party who gave
    // them to us, not to the worker's client. Same roles as the POST paths below.
    require_role($user, 'super_admin', 'admin', 'manager');
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');
    $stmt = $conn->prepare(
        'SELECT id, worker_id, referee_name, referee_org, relationship, email, phone, status, gap_note, created_at
         FROM worker_references WHERE worker_id = ? ORDER BY created_at ASC'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $refs = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int) $r['id'];
        $r['worker_id'] = (int) $r['worker_id'];
        $refs[] = $r;
    }
    json_out(['references' => $refs]);
}

if (method() === 'POST' && ($_GET['action'] ?? '') === 'status') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $status = trim((string) ($data['status'] ?? ''));
    $gapNote = trim((string) ($data['gap_note'] ?? ''));
    $valid = ['requested', 'received', 'verified', 'gap_explained'];
    if ($id <= 0 || !in_array($status, $valid, true)) fail('A reference and a valid status are required.');
    $gapVal = $gapNote !== '' ? $gapNote : null;
    $stmt = $conn->prepare('UPDATE worker_references SET status = ?, gap_note = ? WHERE id = ?');
    $stmt->bind_param('ssi', $status, $gapVal, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'reference.status', "ref #$id -> $status");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $workerId = (int) ($data['worker_id'] ?? 0);
    $name = trim((string) ($data['referee_name'] ?? ''));
    $org = trim((string) ($data['referee_org'] ?? ''));
    $rel = trim((string) ($data['relationship'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));

    if ($workerId <= 0 || $name === '') fail('A worker and referee name are required.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That referee email is not valid.');

    $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Worker not found.', 404);

    $orgVal = $org !== '' ? $org : null;
    $relVal = $rel !== '' ? $rel : null;
    $emailVal = $email !== '' ? $email : null;
    $phoneVal = $phone !== '' ? $phone : null;

    $stmt = $conn->prepare(
        'INSERT INTO worker_references (worker_id, referee_name, referee_org, relationship, email, phone)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssss', $workerId, $name, $orgVal, $relVal, $emailVal, $phoneVal);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'reference.create', "$name for worker #$workerId");
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
