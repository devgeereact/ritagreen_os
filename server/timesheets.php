<?php
// Timesheets & time tracking.
// GET  /timesheets.php?from=&to=&worker_id=&status=&division=  -> { timesheets: [...], totals: {...} }
// GET  /timesheets.php?id=N                         -> { timesheet, history }
// POST /timesheets.php {worker_id, work_date, start_time, end_time, break_minutes?, shift_id?}
// POST /timesheets.php?action=approve&id=5           -> approve (manager+)
// POST /timesheets.php?action=reject&id=5  {note}    -> reject with a reason (manager+)
// POST /timesheets.php?action=query&id=5   {note}    -> ask the worker; they see the reason (manager+)
// POST /timesheets.php?action=correct&id=5 {work_date?, start_time?, end_time?, break_minutes?, reason}
//
// A timesheet starts as a draft when the worker clocks out (field.php), is
// submitted by the worker (me.php?action=submit_timesheet), and is approved or
// queried here. Corrections keep history: each one is an audit row with the
// values before and after and the reason.
//
// Only 'approved' entries reach pay-lib.php, so rejecting one takes it out of
// pay without deleting the record of it having been claimed. Draft and queried
// entries are not paid either.
require __DIR__ . '/lib.php';
require __DIR__ . '/rota-lib.php';

/** Mirrors the timesheets.status enum (migrations 021 and 038). */
const TIMESHEET_STATUSES = ['draft', 'pending', 'approved', 'rejected', 'queried'];

/** Load one entry with its worker's service, held to the account's scope. */
function timesheet_load(mysqli $conn, array $user, int $id): array
{
    if ($id <= 0) fail('A timesheet entry is required.');
    $stmt = $conn->prepare(
        'SELECT t.id, t.worker_id, w.name AS worker, w.division, t.shift_id, t.work_date, t.start_time, t.end_time,
                t.minutes, t.break_minutes, t.status, t.source, t.submitted_at, t.decided_at, t.decision_note
           FROM timesheets t JOIN workers w ON w.id = t.worker_id
          WHERE t.id = ? AND t.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Timesheet entry not found.', 404);
    require_service_scope($user, $row['division']);
    return $row;
}

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

if (method() === 'GET' && isset($_GET['id'])) {
    $ts = timesheet_load($conn, $user, (int) $_GET['id']);
    $id = (int) $ts['id'];
    $stmt = $conn->prepare(
        'SELECT a.action, a.detail, a.reason, a.before_json, a.after_json, a.created_at, u.name AS by_name
           FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
          WHERE a.entity_type = "timesheet" AND a.entity_id = ? ORDER BY a.id DESC LIMIT 50'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $history = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $h) {
        $history[] = [
            'action' => $h['action'], 'detail' => $h['detail'], 'reason' => $h['reason'],
            'before' => $h['before_json'] !== null ? json_decode($h['before_json'], true) : null,
            'after' => $h['after_json'] !== null ? json_decode($h['after_json'], true) : null,
            'by' => $h['by_name'], 'at' => $h['created_at'],
        ];
    }
    json_out(['timesheet' => $ts, 'history' => $history]);
}

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

    $division = trim((string) ($_GET['division'] ?? ''));
    if ($division !== '') {
        if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Unknown service.');
        $where[] = 'w.division = ?';
        $types .= 's';
        $args[] = $division;
    }
    // A manager confined to one service sees that service's hours.
    if (($scope = service_scope($user)) !== null) {
        $where[] = 'w.division = ?';
        $types .= 's';
        $args[] = $scope;
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
                t.end_time, t.minutes, t.break_minutes, t.status, t.source, t.submitted_at, t.decided_at, t.decision_note,
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
    $totals = ['draft_minutes' => 0, 'pending_minutes' => 0, 'approved_minutes' => 0, 'rejected_minutes' => 0, 'queried_minutes' => 0];
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

if (in_array($action, ['approve', 'reject', 'query'], true) && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $ts = timesheet_load($conn, $user, (int) ($_GET['id'] ?? 0));
    $id = (int) $ts['id'];
    if (!rg_timesheet_can($action, $ts['status'])) {
        fail($action === 'query'
            ? 'Only an entry still waiting for a decision can be queried.'
            : 'That entry cannot be changed now.', 409);
    }

    // A rejection or a query a worker cannot understand is a phone call to the
    // office, not a decision, so the reason is required for both.
    $note = trim((string) (body()['note'] ?? ''));
    if ($action === 'reject' && $note === '') fail('Give a reason for rejecting the entry.');
    if ($action === 'query' && $note === '') fail('Say what the worker needs to check.');
    if (mb_strlen($note) > 255) fail('The reason must be 255 characters or fewer.');
    $noteVal = $note !== '' ? $note : null;

    $status = ['approve' => 'approved', 'reject' => 'rejected', 'query' => 'queried'][$action];
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE timesheets SET status = ?, approved_by = ?, decided_at = NOW(), decision_note = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('siss', $status, $uid, $noteVal, $id);
    $stmt->execute();
    audit($conn, $uid, "timesheet.$action", $note !== '' ? "#$id: $note" : "#$id", 'timesheet', $id,
        ['status' => $ts['status']], ['status' => $status], $noteVal);

    // The worker sees the reason (plan file 03: "queries with a reason the
    // worker sees"), in the app and by email.
    if ($action === 'query' || $action === 'reject') {
        $when = date('D j M', strtotime($ts['work_date'])) . ' ' . substr($ts['start_time'], 0, 5) . ' to ' . substr($ts['end_time'], 0, 5);
        rg_notify_worker($conn, (int) $ts['worker_id'],
            $action === 'query' ? 'Timesheet queried' : 'Timesheet rejected',
            "Your hours for $when " . ($action === 'query' ? 'need checking' : 'were not accepted') . ".\nReason: $note"
            . ($action === 'query' ? "\nCorrect them if needed and submit again in the staff app." : ''),
            $ts['shift_id'] !== null ? (int) $ts['shift_id'] : null, $uid);
    }
    json_out(['ok' => true, 'status' => $status]);
}

// A correction by the office. The entry keeps its status; what changed, from
// what, and why are in the audit row, which is the entry's history.
if ($action === 'correct' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $ts = timesheet_load($conn, $user, (int) ($_GET['id'] ?? 0));
    $id = (int) $ts['id'];
    if (!rg_timesheet_can('correct', $ts['status'])) {
        fail('Approved hours may already have been paid. Reject the entry first, then correct it.', 409);
    }
    $data = body();
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($reason === '') fail('Say why the entry is being corrected.');
    if (mb_strlen($reason) > 255) fail('The reason must be 255 characters or fewer.');

    $after = [
        'work_date' => trim((string) ($data['work_date'] ?? $ts['work_date'])),
        'start_time' => substr(trim((string) ($data['start_time'] ?? $ts['start_time'])), 0, 5),
        'end_time' => substr(trim((string) ($data['end_time'] ?? $ts['end_time'])), 0, 5),
        'break_minutes' => (int) ($data['break_minutes'] ?? $ts['break_minutes']),
    ];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $after['work_date'])) fail('The date must be YYYY-MM-DD.');
    if (!preg_match('/^\d{2}:\d{2}$/', $after['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $after['end_time'])) {
        fail('Times must be HH:MM.');
    }
    $minutes = rg_shift_minutes($after['work_date'], $after['start_time'], $after['end_time']);
    if ($minutes <= 0) fail('The end time must differ from the start time.');
    if ($minutes > 16 * 60) fail('An entry cannot be longer than 16 hours.');
    if ($after['break_minutes'] < 0) fail('A break cannot be negative.');
    if ($after['break_minutes'] >= $minutes) fail('The break cannot be as long as the shift.');
    $after['minutes'] = $minutes;

    $before = [
        'work_date' => $ts['work_date'], 'start_time' => substr($ts['start_time'], 0, 5),
        'end_time' => substr($ts['end_time'], 0, 5), 'break_minutes' => (int) $ts['break_minutes'],
        'minutes' => (int) $ts['minutes'],
    ];
    [$b, $a] = audit_diff($before, $after, array_keys($after));
    if (!$a) json_out(['ok' => true, 'changed' => false]);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE timesheets SET work_date = ?, start_time = ?, end_time = ?, minutes = ?, break_minutes = ? WHERE id = ?'
    );
    $stmt->bind_param('sssiii', $after['work_date'], $after['start_time'], $after['end_time'], $minutes,
        $after['break_minutes'], $id);
    $stmt->execute();
    audit($conn, $uid, 'timesheet.correct', "#$id: $reason", 'timesheet', $id, $b, $a, $reason);
    json_out(['ok' => true, 'changed' => true]);
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

    $stmt = $conn->prepare('SELECT id, division FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $workerRow = $stmt->get_result()->fetch_assoc();
    if (!$workerRow) fail('Worker not found.', 404);
    require_service_scope($user, $workerRow['division']);

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
    $newId = (int) $conn->insert_id;

    $detail = "worker #$workerId $date $start-$end" . ($break > 0 ? " less {$break}m break" : '');
    audit($conn, (int) $user['id'], 'timesheet.create', $detail, 'timesheet', $newId);
    json_out(['ok' => true, 'id' => $newId], 201);
}

fail('Method not allowed.', 405);
