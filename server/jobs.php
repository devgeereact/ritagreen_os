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
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if (!in_array($type, ['care_visit', 'clean', 'guard_shift', 'course', 'other'], true)) fail('Invalid type.');
    if (!in_array($priority, ['emergency', 'high', 'standard'], true)) fail('Invalid priority.');

    // A client_id that does not exist reached the INSERT and came back as a
    // foreign-key 500 with the constraint name in it, plus a logged exception,
    // for what is only a stale id from a page left open.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    $scheduledVal = $scheduled !== '' ? date('Y-m-d H:i:s', strtotime($scheduled)) : null;
    $notesVal = $notes !== '' ? $notes : null;

    $stmt = $conn->prepare(
        'INSERT INTO jobs (client_id, division, type, title, priority, scheduled_for, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $clientId, $division, $type, $title, $priority, $scheduledVal, $notesVal);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'job.create', $title);
    json_out([
        'job' => [
            'id' => (int) $id, 'client_id' => $clientId, 'client' => null,
            'division' => $division, 'type' => $type, 'title' => $title,
            'priority' => $priority, 'status' => 'booked', 'scheduled_for' => $scheduledVal,
            'notes' => $notesVal,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
