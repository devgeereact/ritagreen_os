<?php
// §18 Worker self-service (powers the mobile PWA).
// GET  /me.php                          -> { worker, compliance, shifts, open_shifts }
// POST /me.php?action=accept&shift_id=N -> claim an open shift (atomic)
// GET  /me.php?action=payslip&from=&to= -> own payslip for a period
// Clock-in/out, check-in and panic go through field.php (it reads worker_id off the token).
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
$workerId = $user['worker_id'] !== null ? (int) $user['worker_id'] : 0;
if ($workerId <= 0) fail('This account is not linked to a worker.', 403);
$action = $_GET['action'] ?? '';

function classify_me(?string $expiry): array
{
    if (!$expiry) return ['status' => 'unknown', 'days' => null];
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['status' => 'expired', 'days' => $days];
    if ($days <= 30) return ['status' => 'expiring', 'days' => $days];
    return ['status' => 'valid', 'days' => $days];
}

if ($action === 'payslip' && method() === 'GET') {
    require __DIR__ . '/pay-lib.php';
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    if (!pay_valid_date($from) || !pay_valid_date($to)) fail('A valid from and to date are required.');
    $out = pay_compute($conn, $from, $to, $workerId);
    $agg = $out['workers'][0] ?? ['hours' => 0, 'gross_pence' => 0, 'holiday_pay_pence' => 0];
    json_out(['period' => ['from' => $from, 'to' => $to], 'lines' => $out['lines'], 'totals' => $agg]);
}

if ($action === 'accept' && method() === 'POST') {
    $shiftId = (int) ($_GET['shift_id'] ?? 0);
    if ($shiftId <= 0) fail('A shift is required.');
    // Must be an open shift in the worker's division.
    $stmt = $conn->prepare('SELECT s.division FROM shifts s WHERE s.id = ? AND s.status = "open" LIMIT 1');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) fail('That shift is no longer open.', 409);
    $stmt = $conn->prepare('SELECT division FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w || $w['division'] !== $shift['division']) fail('This shift is not for your division.', 403);

    // The deployment gate, before the claim.
    //
    // Matching divisions was the whole test. A worker with a lapsed DBS could
    // claim a care shift from the app, and one already booked elsewhere could
    // claim a shift overlapping it -- verified against a local database: a
    // worker scheduled 08:00-16:00 accepted an overlapping 09:00-17:00 shift
    // here and ended up on both, while the office screen refused the same
    // assignment with a 409.
    $claim = rg_claim_check($conn, $workerId, $shiftId);
    if (!$claim['ok']) {
        audit($conn, (int) $user['id'], 'shift.blocked', "app accept, worker #$workerId: " . $claim['reason']);
        fail($claim['reason'], 409);
    }

    // Atomic claim.
    $stmt = $conn->prepare('UPDATE shifts SET worker_id = ?, status = "scheduled" WHERE id = ? AND status = "open"');
    $stmt->bind_param('ii', $workerId, $shiftId);
    $stmt->execute();
    if ($conn->affected_rows !== 1) fail('Sorry — that shift was just taken.', 409);
    $conn->query('UPDATE shift_offers SET status = "expired" WHERE shift_id = ' . $shiftId . ' AND status = "sent"');
    notify($conn, null, 'Open shift filled', $user['name'] . ' accepted a shift via the app', 'shift', '/app/scheduling');
    audit($conn, (int) $user['id'], 'me.accept_shift', "shift #$shiftId");
    json_out(['ok' => true]);
}

if (method() === 'GET') {
    // Profile.
    $stmt = $conn->prepare(
        'SELECT id, name, role, division, status, right_to_work, dbs_status, dbs_expiry, sia_number, sia_expiry
         FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $worker = $stmt->get_result()->fetch_assoc();
    if (!$worker) fail('Worker not found.', 404);
    $worker['id'] = (int) $worker['id'];
    $worker['right_to_work'] = (bool) $worker['right_to_work'];

    // Compliance items (DBS/SIA + extra certs).
    $compliance = [];
    if ($worker['dbs_expiry'] || $worker['dbs_status']) {
        $c = classify_me($worker['dbs_expiry']);
        $compliance[] = ['type' => 'DBS check', 'expiry' => $worker['dbs_expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }
    if ($worker['sia_number'] || $worker['sia_expiry']) {
        $c = classify_me($worker['sia_expiry']);
        $compliance[] = ['type' => 'SIA licence', 'expiry' => $worker['sia_expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }
    $stmt = $conn->prepare('SELECT type, expiry FROM certifications WHERE worker_id = ? ORDER BY expiry IS NULL, expiry');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $c = classify_me($row['expiry']);
        $compliance[] = ['type' => $row['type'], 'expiry' => $row['expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }

    // My upcoming shifts.
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.division, s.site_name, s.starts_at, s.ends_at, s.status, c.name AS client,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_in") AS clocked_in,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_out") AS clocked_out
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.worker_id = ? AND s.ends_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         ORDER BY s.starts_at LIMIT 50'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $shifts = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['clocked_in'] = (int) $row['clocked_in'];
        $row['clocked_out'] = (int) $row['clocked_out'];
        $shifts[] = $row;
    }

    // Open shifts in my division I could accept.
    $div = $worker['division'];
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.site_name, s.starts_at, s.ends_at, c.name AS client
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.status = "open" AND s.division = ? AND s.starts_at >= NOW()
         ORDER BY s.starts_at LIMIT 30'
    );
    $stmt->bind_param('s', $div);
    $stmt->execute();
    $open = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $row['id'] = (int) $row['id']; $open[] = $row; }

    json_out(['worker' => $worker, 'compliance' => $compliance, 'shifts' => $shifts, 'open_shifts' => $open]);
}

fail('Method not allowed.', 405);
