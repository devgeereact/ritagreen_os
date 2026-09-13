<?php
// Timesheets & time tracking.
// GET  /timesheets.php?from=&to=&worker_id=&status=  -> { timesheets: [...], totals: {...} }
// POST /timesheets.php {worker_id, work_date, start_time, end_time} -> add entry
// POST /timesheets.php?action=approve&id=5           -> approve (manager+)
// POST /timesheets.php?action=reject&id=5  {note}    -> reject with a reason (manager+)
//
// Only 'approved' entries reach pay-lib.php, so rejecting one takes it out of
// pay without deleting the record of it having been claimed.
require __DIR__ . '/lib.php';

/** Mirrors the timesheets.status enum (migration 021). */
const TIMESHEET_STATUSES = ['pending', 'approved', 'rejected'];

$user = require_auth($conn);
// Timesheets are other people's hours and pay-bearing records. Internal staff
// only; the worker app records its own time through field.php.
require_staff($user);
// Staff is not enough. Hours are what pay is calculated from, so they are the
// payroll zone -- the same zone workers.php:17 uses to hide a pay rate. Being
// internal did not previously narrow this at all, so an editor, a trainer or an
// employee could read every worker's hours for every day on record.
if (!can_view_zone($conn, $user, 'payroll')) fail('You do not have access to this.', 403);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    // Filters, because approving hours is a periodic job. Without them the only
    // view was the most recent 300 entries across all of time, which answers
    // neither "what is outstanding for last week" nor "what did this worker
    // claim" -- and silently truncated once the business had more than 300.
    $where = ['t.deleted_at IS NULL'];
    $types = '';
    $args = [];

    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    foreach ([['from', $from, '>='], ['to', $to, '<=']] as [$label, $value, $op]) {
        if ($value === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) fail("The \"$label\" date must be YYYY-MM-DD.");
        $where[] = "t.work_date $op ?";
        $types .= 's';
        $args[] = $value;
    }
    if ($from !== '' && $to !== '' && $from > $to) fail('The "from" date must be on or before the "to" date.');

    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId > 0) {
        $where[] = 't.worker_id = ?';
        $types .= 'i';
        $args[] = $workerId;
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, TIMESHEET_STATUSES, true)) fail('Unknown status filter.');
        $where[] = 't.status = ?';
        $types .= 's';
        $args[] = $status;
    }

    $sql =
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, t.work_date, t.start_time,
                t.end_time, t.minutes, t.status, t.decided_at, t.decision_note,
                u.name AS decided_by
         FROM timesheets t
         JOIN workers w ON w.id = t.worker_id
         LEFT JOIN users u ON u.id = t.approved_by
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY t.work_date DESC, t.start_time DESC
         LIMIT 500';
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();

    $timesheets = [];
    $totals = ['pending_minutes' => 0, 'approved_minutes' => 0, 'rejected_minutes' => 0];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = (int) $row['worker_id'];
        $row['minutes'] = (int) $row['minutes'];
        $totals[$row['status'] . '_minutes'] += $row['minutes'];
        $timesheets[] = $row;
    }
    json_out(['timesheets' => $timesheets, 'totals' => $totals]);
}

if (($action === 'approve' || $action === 'reject') && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A timesheet entry is required.');

    // A rejection a worker cannot understand is a query to the office, not a
    // decision, so the reason is required rather than optional.
    $note = trim((string) (body()['note'] ?? ''));
    if ($action === 'reject' && $note === '') fail('Give a reason for rejecting the entry.');
    if (mb_strlen($note) > 255) fail('The reason must be 255 characters or fewer.');
    $noteVal = $note !== '' ? $note : null;

    $status = $action === 'approve' ? 'approved' : 'rejected';
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE timesheets SET status = ?, approved_by = ?, decided_at = NOW(), decision_note = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('siss', $status, $uid, $noteVal, $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'timesheets', $id)) fail('Timesheet entry not found.', 404);
    audit($conn, $uid, "timesheet.$action", $note !== '' ? "#$id: $note" : "#$id");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    // 'employee' was listed here and could log hours against ANY worker, with
    // nothing tying the entry to the person making it. The payroll zone above
    // now stops that role reaching this file at all, so the list says what it
    // means: logging someone else's hours is an office job.
    require_role($user, 'super_admin', 'admin', 'manager');
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

    // Overnight shifts are shifts, not typos -- see rg_shift_minutes(). The
    // 16-hour cap still catches a genuinely wrong entry.
    $minutes = rg_shift_minutes($date, $start, $end);
    if ($minutes <= 0) fail('The end time must differ from the start time.');
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
