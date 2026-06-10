<?php
// Scheduling & dispatch.
// GET  /scheduling.php?from=YYYY-MM-DD&to=YYYY-MM-DD -> { shifts: [...] }
// POST /scheduling.php {worker_id, title, client_id?, site_name?, starts_at, ends_at}
//
// Server-side guards: no double-booking, and a worker with a lapsed required
// check (DBS for care, SIA for security) or no right to work cannot be scheduled.
require __DIR__ . '/lib.php';

$user = require_auth($conn);

if (method() === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fail('from and to must be dates (YYYY-MM-DD).');
    }
    $stmt = $conn->prepare(
        'SELECT s.id, s.worker_id, w.name AS worker, s.division, s.title, s.site_name,
                s.client_id, c.name AS client, s.starts_at, s.ends_at, s.status
         FROM shifts s
         JOIN workers w ON w.id = s.worker_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.starts_at >= ? AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY s.starts_at'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $shifts = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = (int) $row['worker_id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $shifts[] = $row;
    }
    json_out(['shifts' => $shifts]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $workerId = (int) ($data['worker_id'] ?? 0);
    $title = trim((string) ($data['title'] ?? ''));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $site = trim((string) ($data['site_name'] ?? ''));
    $starts = trim((string) ($data['starts_at'] ?? ''));
    $ends = trim((string) ($data['ends_at'] ?? ''));

    if ($workerId <= 0 || $title === '' || $starts === '' || $ends === '') {
        fail('Worker, title, start and end are required.');
    }
    $startTs = strtotime($starts);
    $endTs = strtotime($ends);
    if (!$startTs || !$endTs) fail('Start and end must be valid date-times.');
    if ($endTs <= $startTs) fail('The shift must end after it starts.');

    // Worker must exist and carry their compliance.
    $stmt = $conn->prepare(
        'SELECT name, division, right_to_work, dbs_expiry, sia_expiry
         FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    // Compliance-aware assignment.
    if (!$w['right_to_work']) fail($w['name'] . ' has no right-to-work on file and cannot be scheduled.', 409);
    $today = date('Y-m-d');
    if ($w['division'] === 'security') {
        if (!$w['sia_expiry'] || $w['sia_expiry'] < $today) {
            fail($w['name'] . "'s SIA licence is missing or expired. They cannot be scheduled.", 409);
        }
    }
    if ($w['division'] === 'care') {
        if ($w['dbs_expiry'] && $w['dbs_expiry'] < $today) {
            fail($w['name'] . "'s DBS check has expired. They cannot be scheduled.", 409);
        }
    }

    // Double-booking: any overlapping scheduled shift for this worker.
    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    $stmt = $conn->prepare(
        'SELECT id FROM shifts
         WHERE worker_id = ? AND status = "scheduled"
           AND starts_at < ? AND ends_at > ? LIMIT 1'
    );
    $stmt->bind_param('iss', $workerId, $endSql, $startSql);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        fail($w['name'] . ' is already booked for an overlapping shift.', 409);
    }

    $division = $w['division'];
    $siteVal = $site !== '' ? $site : null;
    $stmt = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssss', $workerId, $clientId, $division, $title, $siteVal, $startSql, $endSql);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'shift.create', "$title for worker #$workerId");
    json_out([
        'shift' => [
            'id' => (int) $id, 'worker_id' => $workerId, 'worker' => $w['name'],
            'division' => $division, 'title' => $title, 'site_name' => $siteVal,
            'client_id' => $clientId, 'starts_at' => $startSql, 'ends_at' => $endSql,
            'status' => 'scheduled',
        ],
    ], 201);
}

fail('Method not allowed.', 405);
