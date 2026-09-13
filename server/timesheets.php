<?php
// Timesheets & time tracking.
// GET  /timesheets.php?from=&to=&worker_id=&status=  -> { timesheets: [...], totals: {...} }
// POST /timesheets.php {worker_id, work_date, start_time, end_time, break_minutes?, shift_id?}
// POST /timesheets.php?action=approve&id=5           -> approve (manager+)
// POST /timesheets.php?action=reject&id=5  {note}    -> reject with a reason (manager+)
//
// Only 'approved' entries reach pay-lib.php, so rejecting one takes it out of
// pay without deleting the record of it having been claimed.
require __DIR__ . '/lib.php';

/** Mirrors the timesheets.status enum (migration 021). */
const TIMESHEET_STATUSES = ['pending', 'approved', 'rejected'];

/** Rows returned per read. Totals are computed over the whole filter, not this. */
const TIMESHEET_PAGE = 500;

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

    $clause = implode(' AND ', $where);

    $sql =
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, t.work_date, t.start_time,
                t.end_time, t.minutes, t.break_minutes, t.status, t.decided_at, t.decision_note,
                t.shift_id, s.title AS shift_title,
                u.name AS decided_by
         FROM timesheets t
         JOIN workers w ON w.id = t.worker_id
         LEFT JOIN users u ON u.id = t.approved_by
         LEFT JOIN shifts s ON s.id = t.shift_id
         WHERE ' . $clause . '
         ORDER BY t.work_date DESC, t.start_time DESC
         LIMIT ' . TIMESHEET_PAGE;
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();

    $timesheets = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = (int) $row['worker_id'];
        $row['minutes'] = (int) $row['minutes'];
        $row['break_minutes'] = (int) $row['break_minutes'];
        $row['shift_id'] = $row['shift_id'] !== null ? (int) $row['shift_id'] : null;
        $timesheets[] = $row;
    }

    // Totalled by the database over the whole filter, not by PHP over the page.
    //
    // The tiles used to be summed from the rows that had just been fetched, so
    // once a filter matched more than the page size the "awaiting approval"
    // figure quietly became "awaiting approval, in the most recent N rows" --
    // an under-count on the one number the office acts on, and one that
    // disagreed with the count action-centre.php shows for the same thing.
    $totals = ['pending_minutes' => 0, 'approved_minutes' => 0, 'rejected_minutes' => 0];
    $sumStmt = $conn->prepare(
        'SELECT t.status, SUM(GREATEST(t.minutes - t.break_minutes, 0)) AS mins
         FROM timesheets t
         JOIN workers w ON w.id = t.worker_id
         WHERE ' . $clause . '
         GROUP BY t.status'
    );
    if ($types !== '') $sumStmt->bind_param($types, ...$args);
    $sumStmt->execute();
    $sums = $sumStmt->get_result();
    while ($row = $sums->fetch_assoc()) {
        $totals[$row['status'] . '_minutes'] = (int) $row['mins'];
    }

    json_out([
        'timesheets' => $timesheets,
        'totals' => $totals,
        // So the page can say so rather than silently showing a partial list.
        'truncated' => count($timesheets) === TIMESHEET_PAGE,
    ]);
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

    // An unpaid break is time on the premises that is not time worked.
    $break = (int) ($data['break_minutes'] ?? 0);
    if ($break < 0) fail('A break cannot be negative.');
    if ($break >= $minutes) fail('The break cannot be as long as the shift.');

    $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Worker not found.', 404);

    // Optional link to the rota entry these hours were worked on (migration 029).
    // Hours are still recorded for work that never had a shift -- a call-out, a
    // cover arranged by phone -- so this stays nullable; when it IS given it has
    // to be that worker's shift, or the link says something untrue.
    $shiftId = isset($data['shift_id']) && $data['shift_id'] !== '' ? (int) $data['shift_id'] : null;
    if ($shiftId !== null) {
        $stmt = $conn->prepare('SELECT worker_id FROM shifts WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('i', $shiftId);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();
        if (!$shift) fail('Shift not found.', 404);
        if ((int) $shift['worker_id'] !== $workerId) fail('That shift belongs to a different worker.', 409);

        // One claim per shift. Without this the same shift could be claimed
        // twice and paid twice, and nothing would show it.
        $stmt = $conn->prepare('SELECT id FROM timesheets WHERE shift_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $shiftId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) fail('That shift already has a timesheet.', 409);
    }

    $stmt = $conn->prepare(
        'INSERT INTO timesheets (worker_id, shift_id, work_date, start_time, end_time, minutes, break_minutes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssii', $workerId, $shiftId, $date, $start, $end, $minutes, $break);
    $stmt->execute();

    $detail = "worker #$workerId $date $start-$end" . ($break > 0 ? " less {$break}m break" : '');
    audit($conn, (int) $user['id'], 'timesheet.create', $detail);
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
