<?php
// §18 Worker self-service (powers the mobile PWA).
// GET  /me.php                          -> { worker, compliance, shifts, open_shifts }
// POST /me.php?action=accept&shift_id=N -> claim an open shift (atomic)
// GET  /me.php?action=payslip&from=&to= -> own payslip for a period
// GET  /me.php?action=timesheets          -> own timesheets, newest first
// POST /me.php?action=submit_timesheet&id=N {break_minutes?} -> submit a draft, or answer a query
// GET  /me.php?action=availability        -> own unavailable periods still to come
// POST /me.php?action=availability {starts_at, ends_at, note?} -> mark a period unavailable
// POST /me.php?action=availability_remove&id=N
// Clock-in/out, check-in and panic go through field.php (it reads worker_id off the token).
// Workers never see draft shifts: every shift read here filters is_draft = 0.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';
require __DIR__ . '/rota-lib.php';

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
    // Must be a published open shift in the worker's division. A draft is not
    // there to be claimed, so it reads as not open.
    $stmt = $conn->prepare(
        'SELECT s.division FROM shifts s
          WHERE s.id = ? AND s.status = "open" AND s.is_draft = 0 AND s.deleted_at IS NULL LIMIT 1'
    );
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
    $stmt = $conn->prepare(
        'UPDATE shifts SET worker_id = ?, status = "scheduled"
          WHERE id = ? AND status = "open" AND is_draft = 0 AND deleted_at IS NULL'
    );
    $stmt->bind_param('ii', $workerId, $shiftId);
    $stmt->execute();
    if ($conn->affected_rows !== 1) fail('Sorry, that shift was just taken.', 409);
    $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired" WHERE shift_id = ? AND status = "sent"');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    // A worker choosing a shift has made the choice a warning exists to prompt,
    // so it does not stop them. The office is still told, because a short rest
    // gap is the company's responsibility as well as theirs.
    $warn = $claim['warnings'] ? ' Note: ' . implode(' ', array_column($claim['warnings'], 'reason')) : '';
    notify($conn, null, 'Open shift filled', $user['name'] . ' accepted "' . $claim['shift']['title'] . '" in the app.' . $warn,
        'shift', '/app/scheduling');
    rg_shift_audit($conn, (int) $user['id'], 'me.accept_shift', $shiftId, $user['name'] . ' claimed it in the app.' . $warn);
    json_out(['ok' => true, 'warnings' => $claim['warnings']]);
}

// ---------- Own timesheets ----------
if ($action === 'timesheets' && method() === 'GET') {
    $stmt = $conn->prepare(
        'SELECT t.id, t.shift_id, s.title AS shift_title, t.work_date, t.start_time, t.end_time, t.minutes,
                t.break_minutes, t.status, t.source, t.submitted_at, t.decided_at, t.decision_note
           FROM timesheets t LEFT JOIN shifts s ON s.id = t.shift_id
          WHERE t.worker_id = ? AND t.deleted_at IS NULL
          ORDER BY t.work_date DESC, t.start_time DESC LIMIT 60'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['shift_id'] = $r['shift_id'] !== null ? (int) $r['shift_id'] : null;
        $r['minutes'] = (int) $r['minutes'];
        $r['break_minutes'] = (int) $r['break_minutes'];
    }
    unset($r);
    json_out(['timesheets' => $rows]);
}

// ---------- Submit a timesheet (the worker confirms the break) ----------
if ($action === 'submit_timesheet' && method() === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A timesheet is required.');
    // Proving ownership: the row must be this worker's, or it does not exist as
    // far as this login is concerned.
    $stmt = $conn->prepare(
        'SELECT id, status, minutes, break_minutes FROM timesheets WHERE id = ? AND worker_id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ii', $id, $workerId);
    $stmt->execute();
    $ts = $stmt->get_result()->fetch_assoc();
    if (!$ts) fail('Timesheet not found.', 404);
    if (!rg_timesheet_can('submit', $ts['status'])) fail('That timesheet has already been submitted.', 409);

    $data = body();
    $break = array_key_exists('break_minutes', $data) ? (int) $data['break_minutes'] : (int) $ts['break_minutes'];
    if ($break < 0) fail('A break cannot be negative.');
    if ($break >= (int) $ts['minutes']) fail('The break cannot be as long as the shift.');

    $stmt = $conn->prepare('UPDATE timesheets SET status = "pending", break_minutes = ?, submitted_at = NOW() WHERE id = ?');
    $stmt->bind_param('ii', $break, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'timesheet.submit', "#$id", 'timesheet', $id,
        ['status' => $ts['status'], 'break_minutes' => (int) $ts['break_minutes']],
        ['status' => 'pending', 'break_minutes' => $break]);
    json_out(['ok' => true, 'status' => 'pending']);
}

// ---------- Unavailability the worker marks ----------
// A warning the office must acknowledge before booking them (rg_assignment_check),
// never a block: the worker may change their mind.
if ($action === 'availability' || $action === 'availability_remove') {
    $stmt = $conn->prepare('SELECT person_id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $personId = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    if ($personId <= 0) fail('This worker is not linked to a person record.', 409);
    $uid = (int) $user['id'];

    if ($action === 'availability_remove' && method() === 'POST') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $conn->prepare(
            'UPDATE availability_blocks SET deleted_at = NOW() WHERE id = ? AND person_id = ? AND deleted_at IS NULL'
        );
        $stmt->bind_param('ii', $id, $personId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) fail('Not found.', 404);
        audit($conn, $uid, 'availability.remove', "#$id", 'availability', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'availability' && method() === 'POST') {
        $data = body();
        $starts = strtotime(trim((string) ($data['starts_at'] ?? '')));
        $ends = strtotime(trim((string) ($data['ends_at'] ?? '')));
        if (!$starts || !$ends) fail('Give a start and an end.');
        if ($ends <= $starts) fail('The end must be after the start.');
        if ($ends - $starts > 92 * 86400) fail('Mark up to three months at a time. For longer, request leave.');
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
        $startSql = date('Y-m-d H:i:s', $starts);
        $endSql = date('Y-m-d H:i:s', $ends);
        $noteVal = $note !== '' ? $note : null;
        $stmt = $conn->prepare(
            'INSERT INTO availability_blocks (person_id, starts_at, ends_at, note, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssi', $personId, $startSql, $endSql, $noteVal, $uid);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        audit($conn, $uid, 'availability.add', "$startSql to $endSql", 'availability', $id);
        json_out(['ok' => true, 'id' => $id], 201);
    }

    if (method() === 'GET') {
        $stmt = $conn->prepare(
            'SELECT id, starts_at, ends_at, note FROM availability_blocks
              WHERE person_id = ? AND deleted_at IS NULL AND ends_at >= NOW() ORDER BY starts_at'
        );
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) $r['id'] = (int) $r['id'];
        unset($r);
        json_out(['unavailable' => $rows]);
    }
    fail('Method not allowed.', 405);
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
        'SELECT s.id, s.title, s.division, COALESCE(t.name, s.site_name) AS site_name, s.starts_at, s.ends_at,
                s.status, s.cancel_reason, c.name AS client,
                t.address AS site_address, t.postcode AS site_postcode, t.access_notes AS site_notes,
                t.contact_name AS site_contact, t.contact_phone AS site_contact_phone,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_in") AS clocked_in,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_out") AS clocked_out
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN sites t ON t.id = s.site_id
         WHERE s.worker_id = ? AND s.is_draft = 0 AND s.deleted_at IS NULL
           AND s.ends_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
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
        'SELECT s.id, s.title, COALESCE(t.name, s.site_name) AS site_name, s.starts_at, s.ends_at, c.name AS client
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN sites t ON t.id = s.site_id
         WHERE s.status = "open" AND s.is_draft = 0 AND s.deleted_at IS NULL AND s.division = ? AND s.starts_at >= NOW()
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
