<?php
// Jobs / work orders.
// GET  /jobs.php                       -> { jobs: [...] }
// POST /jobs.php {title, division, type, priority, client_id?, scheduled_for?, notes?}
// POST /jobs.php?action=status&id=12 {status} -> advance the pipeline
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Jobs name clients and sites. Internal staff only -- the reads were open to
// any logged-in account, which includes client portal and worker logins.
require_staff($user);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        // notes are collected when a job is raised and were never returned, so
        // whatever the office wrote about the visit was invisible from the moment
        // the dialog closed.
        'SELECT j.id, j.client_id, c.name AS client, j.division, j.type, j.title,
                j.priority, j.status, j.scheduled_for, j.notes, j.created_at
         FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
         WHERE j.deleted_at IS NULL
         ORDER BY FIELD(j.priority,"emergency","high","standard"), j.created_at DESC
         LIMIT 500'
    );
    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $jobs[] = $row;
    }
    json_out(['jobs' => $jobs]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'employee');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    $allowed = ['booked', 'assigned', 'in_progress', 'done', 'cancelled'];
    if ($id <= 0 || !in_array($status, $allowed, true)) fail('A job and a valid status are required.');

    $stmt = $conn->prepare('UPDATE jobs SET status = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        // affected_rows is 0 if the value is unchanged; confirm the row exists.
        $check = $conn->prepare('SELECT id FROM jobs WHERE id = ? AND deleted_at IS NULL');
        $check->bind_param('i', $id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) fail('Job not found.', 404);
    }
    audit($conn, (int) $user['id'], 'job.status', "#$id -> $status");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    $type = trim((string) ($data['type'] ?? 'other'));
    $priority = trim((string) ($data['priority'] ?? 'standard'));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $scheduled = trim((string) ($data['scheduled_for'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($title === '') fail('A job title is required.');
    // title is VARCHAR(190); an over-length one reached MySQL in strict mode and
    // came back as a 500 with a logged exception. ats.php caps every field.
    if (mb_strlen($title) > 190) fail('That title is too long. Keep it under 190 characters.');
    if (mb_strlen($notes) > 5000) fail('Those notes are too long.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if (!in_array($type, ['care_visit', 'clean', 'guard_shift', 'course', 'other'], true)) fail('Invalid type.');
    if (!in_array($priority, ['emergency', 'high', 'standard'], true)) fail('Invalid priority.');

    // A client_id that does not exist reached the INSERT and came back as a
    // foreign-key 500 with the constraint name in it, plus a logged exception,
    // for what is only a stale id from a page left open.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    // strtotime() returns false on anything it cannot read, which date() then
    // treated as the epoch: a typo in the date field silently scheduled the job
    // for 1970-01-01 00:00:00, with no error and no way to notice. Verified
    // against a local database before this check existed.
    $scheduledVal = null;
    if ($scheduled !== '') {
        $ts = strtotime($scheduled);
        if ($ts === false) fail('That scheduled date is not a date we can read.');
        $scheduledVal = date('Y-m-d H:i:s', $ts);
    }
    $notesVal = $notes !== '' ? $notes : null;

    $stmt = $conn->prepare(
        'INSERT INTO jobs (client_id, division, type, title, priority, scheduled_for, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $clientId, $division, $type, $title, $priority, $scheduledVal, $notesVal);
    $stmt->execute();
    $id = $conn->insert_id;

    // Read the row back rather than reassembling it. The hand-built body said
    // 'client' => null even when a client was given, and omitted created_at,
    // which src/lib/types.ts declares required -- a shape the API documented and
    // never actually returned.
    $stmt = $conn->prepare(
        'SELECT j.id, j.client_id, c.name AS client, j.division, j.type, j.title,
                j.priority, j.status, j.scheduled_for, j.notes, j.created_at
         FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
         WHERE j.id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $job['id'] = (int) $job['id'];
    $job['client_id'] = $job['client_id'] !== null ? (int) $job['client_id'] : null;

    audit($conn, (int) $user['id'], 'job.create', $title);
    json_out(['job' => $job], 201);
}

fail('Method not allowed.', 405);
