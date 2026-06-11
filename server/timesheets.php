<?php
// Timesheets & time tracking.
// GET  /timesheets.php                     -> { timesheets: [...], totals: {...} }
// POST /timesheets.php {worker_id, work_date, start_time, end_time} -> add entry
// POST /timesheets.php?action=approve&id=5 -> approve (manager+)
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, t.work_date, t.start_time,
                t.end_time, t.minutes, t.status
         FROM timesheets t JOIN workers w ON w.id = t.worker_id
         ORDER BY t.work_date DESC, t.start_time DESC LIMIT 300'
    );
    $timesheets = [];
    $pending = 0;
    $approved = 0;
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = (int) $row['worker_id'];
        $row['minutes'] = (int) $row['minutes'];
        if ($row['status'] === 'pending') $pending += $row['minutes'];
        else $approved += $row['minutes'];
        $timesheets[] = $row;
    }
    json_out(['timesheets' => $timesheets, 'totals' => ['pending_minutes' => $pending, 'approved_minutes' => $approved]]);
}

if ($action === 'approve' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A timesheet entry is required.');
    $uid = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE timesheets SET status = "approved", approved_by = ? WHERE id = ?');
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();
    audit($conn, $uid, 'timesheet.approve', "#$id");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'employee');
    $data = body();
    $workerId = (int) ($data['worker_id'] ?? 0);
    $date = trim((string) ($data['work_date'] ?? ''));
    $start = trim((string) ($data['start_time'] ?? ''));
    $end = trim((string) ($data['end_time'] ?? ''));

    if ($workerId <= 0 || $date === '' || $start === '' || $end === '') {
        fail('Worker, date, start and end are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('The date must be YYYY-MM-DD.');
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) fail('Times must be HH:MM.');

    $startTs = strtotime("$date $start");
    $endTs = strtotime("$date $end");
    if ($endTs <= $startTs) fail('The end time must be after the start time.');
    $minutes = (int) round(($endTs - $startTs) / 60);
    if ($minutes > 16 * 60) fail('An entry cannot be longer than 16 hours.');

    $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Worker not found.', 404);

    $stmt = $conn->prepare(
        'INSERT INTO timesheets (worker_id, work_date, start_time, end_time, minutes) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssi', $workerId, $date, $start, $end, $minutes);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'timesheet.create', "worker #$workerId $date $start-$end");
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
