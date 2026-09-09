<?php
// Leave and absence.
//
//   GET  /leave.php?person_id=N[&year=2026/27]   one person's records + balance
//   GET  /leave.php?action=calendar&from=&to=    everyone's approved leave in a range
//   GET  /leave.php?action=pending               requests waiting on a decision
//   POST /leave.php                              request or record leave
//   POST /leave.php?action=decide&id=N           approve or decline
//   POST /leave.php?action=return&id=N           record a return-to-work discussion
//
// Counted in HOURS, not days. Shift work is not made of whole days, and a
// balance in days is wrong for anyone on variable hours.
//
// Holiday taken, carried over, holiday pay and payments in lieu are all recorded
// because they all have to be. Retention is a configured policy, not a constant
// in this file -- see retention_policies and docs/UK_GOVERNANCE.md.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

const LEAVE_KINDS = ['annual','sickness','unpaid','parental','bereavement','compassionate','jury_service','training','other'];
const LEAVE_YEAR_START_MONTH = 4;   // April. A setting once Settings can hold it.

/** The person behind this login, where it is a worker account. */
function own_person_id(mysqli $conn, array $user): ?int
{
    $workerId = linked_worker_id($user);
    if ($workerId === null) return null;
    $stmt = $conn->prepare('SELECT person_id FROM workers WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['person_id'] : null;
}

// ----- One person's record -----
if (method() === 'GET' && $action === '') {
    $personId = (int) ($_GET['person_id'] ?? 0);
    $own = own_person_id($conn, $user);

    // A worker sees their own leave and nobody else's.
    if (!is_staff($user)) {
        if ($own === null) fail('This login is not linked to a worker record.', 403);
        if ($personId !== 0 && $personId !== $own) fail('You can only see your own leave.', 403);
        $personId = $own;
    }
    if ($personId <= 0) fail('A person is required.');

    $year = trim((string) ($_GET['year'] ?? rg_leave_year(date('Y-m-d'), LEAVE_YEAR_START_MONTH)));

    $stmt = $conn->prepare(
        'SELECT l.id, l.kind, l.status, l.starts_on, l.ends_on, l.hours, l.paid_pence,
                l.in_lieu, l.carried_over, l.leave_year, l.reason,
                l.return_to_work_on, l.return_to_work_note, l.created_at, l.decided_at,
                d.name AS decided_by
         FROM leave_records l
         LEFT JOIN users d ON d.id = l.decided_by
         WHERE l.person_id = ? AND (l.leave_year = ? OR l.leave_year IS NULL)
         ORDER BY l.starts_on DESC'
    );
    $stmt->bind_param('is', $personId, $year);
    $stmt->execute();
    $res = $stmt->get_result();

    // The HEALTH zone, enforced. Why somebody was absent, and what was said in
    // their return-to-work discussion, is health information -- a manager needs
    // to know a person is off and for how long to arrange cover, which is not
    // the same as needing to know why. Seeded so only super_admin and admin hold
    // the zone; see migration 015. A person always sees their own record in full.
    $seesHealth = ($own !== null && $personId === $own) || can_view_zone($conn, $user, 'health');

    $records = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['hours'] = (float) $row['hours'];
        $row['in_lieu'] = (bool) $row['in_lieu'];
        $row['carried_over'] = (bool) $row['carried_over'];
        if (!$seesHealth) {
            $row['reason'] = null;
            $row['return_to_work_note'] = null;
            $row['health_redacted'] = true;
        }
        $records[] = $row;
    }

    // Entitlement lives on the employment record for now. It moves to the
    // contract once contracts are the source of the particulars.
    $stmt = $conn->prepare('SELECT holiday_hours FROM workers WHERE person_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    $entitlement = $w ? (float) $w['holiday_hours'] : 0.0;

    json_out([
        'person_id' => $personId,
        'leave_year' => $year,
        'balance' => rg_leave_balance($entitlement, $records),
        'records' => $records,
    ]);
}

// ----- Approved leave across a range, for planning cover -----
if (method() === 'GET' && $action === 'calendar') {
    require_staff($user);
    $from = trim((string) ($_GET['from'] ?? date('Y-m-01')));
    $to = trim((string) ($_GET['to'] ?? date('Y-m-t')));
    foreach ([$from, $to] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Dates must be YYYY-MM-DD.');
    }

    $stmt = $conn->prepare(
        'SELECT l.id, l.person_id, p.full_name, l.kind, l.status, l.starts_on, l.ends_on, l.hours
         FROM leave_records l JOIN people p ON p.id = l.person_id
         WHERE l.status IN ("approved","taken") AND l.starts_on <= ? AND l.ends_on >= ?
         ORDER BY l.starts_on'
    );
    $stmt->bind_param('ss', $to, $from);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['person_id'] = (int) $row['person_id'];
        $row['hours'] = (float) $row['hours'];
        $items[] = $row;
    }
    json_out(['from' => $from, 'to' => $to, 'items' => $items]);
}

// ----- Waiting on a decision -----
if (method() === 'GET' && $action === 'pending') {
    require_staff($user);
    $seesHealth = can_view_zone($conn, $user, 'health');
    $res = $conn->query(
        'SELECT l.id, l.person_id, p.full_name, l.kind, l.starts_on, l.ends_on, l.hours, l.reason, l.created_at
         FROM leave_records l JOIN people p ON p.id = l.person_id
         WHERE l.status = "requested" ORDER BY l.starts_on'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['hours'] = (float) $row['hours'];
        if (!$seesHealth) {
            $row['reason'] = null;
            $row['health_redacted'] = true;
        }
        $items[] = $row;
    }
    json_out(['items' => $items]);
}

// ----- Request or record -----
if (method() === 'POST' && $action === '') {
    $data = body();
    $own = own_person_id($conn, $user);
    $personId = (int) ($data['person_id'] ?? 0);

    // A worker may request their own leave; staff may record anyone's.
    $isStaff = is_staff($user);
    if (!$isStaff) {
        if ($own === null) fail('This login is not linked to a worker record.', 403);
        if ($personId !== 0 && $personId !== $own) fail('You can only request your own leave.', 403);
        $personId = $own;
    }
    if ($personId <= 0) fail('A person is required.');

    $kind = trim((string) ($data['kind'] ?? 'annual'));
    $start = trim((string) ($data['starts_on'] ?? ''));
    $end = trim((string) ($data['ends_on'] ?? ''));
    $hours = (float) ($data['hours'] ?? 0);
    $reason = trim((string) ($data['reason'] ?? ''));
    $inLieu = !empty($data['in_lieu']) ? 1 : 0;
    $carried = !empty($data['carried_over']) ? 1 : 0;
    $paid = isset($data['paid_pence']) && $data['paid_pence'] !== '' ? (int) $data['paid_pence'] : null;

    if (!in_array($kind, LEAVE_KINDS, true)) fail('Unknown leave type.');
    foreach ([$start, $end] as $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Dates must be YYYY-MM-DD.');
    }
    if (strtotime($end) < strtotime($start)) fail('Leave cannot end before it starts.');
    if ($hours < 0) fail('Hours cannot be negative.');

    // Only staff may record leave as already approved or taken. A worker's own
    // submission is a request, whatever they send.
    $status = $isStaff ? trim((string) ($data['status'] ?? 'approved')) : 'requested';
    if (!in_array($status, ['requested','approved','declined','cancelled','taken'], true)) fail('Unknown status.');

    // Refuse an overlap rather than quietly double-booking somebody's holiday.
    $stmt = $conn->prepare(
        'SELECT starts_on, ends_on FROM leave_records
         WHERE person_id = ? AND status IN ("requested","approved","taken")'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $existing = $stmt->get_result();
    while ($row = $existing->fetch_assoc()) {
        if (rg_leave_overlaps($start, $end, $row['starts_on'], $row['ends_on'])) {
            fail('That overlaps leave already recorded for ' . $row['starts_on'] . ' to ' . $row['ends_on'] . '.', 409);
        }
    }

    $stmt = $conn->prepare('SELECT id FROM workers WHERE person_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    $workerId = $w ? (int) $w['id'] : null;

    $year = rg_leave_year($start, LEAVE_YEAR_START_MONTH);
    $reasonVal = $reason !== '' ? $reason : null;
    $uid = (int) $user['id'];
    $decidedBy = $status === 'requested' ? null : $uid;
    $decidedAt = $status === 'requested' ? null : date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        'INSERT INTO leave_records
           (person_id, worker_id, kind, status, starts_on, ends_on, hours, paid_pence,
            in_lieu, carried_over, leave_year, reason, requested_by, decided_by, decided_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissssdiiissiis', $personId, $workerId, $kind, $status, $start, $end, $hours,
        $paid, $inLieu, $carried, $year, $reasonVal, $uid, $decidedBy, $decidedAt);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    audit($conn, $uid, 'leave.create', "person #$personId", 'leave', $id,
        null, ['kind' => $kind, 'status' => $status, 'starts_on' => $start, 'ends_on' => $end, 'hours' => $hours]);
    json_out(['ok' => true, 'id' => $id, 'leave_year' => $year], 201);
}

// ----- Decide -----
if (method() === 'POST' && $action === 'decide') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $decision = trim((string) (body()['status'] ?? ''));
    if ($id <= 0) fail('A leave record is required.');
    if (!in_array($decision, ['approved', 'declined', 'cancelled'], true)) fail('Decision must be approved, declined or cancelled.');

    $stmt = $conn->prepare('SELECT person_id, status FROM leave_records WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    if (!$rec) fail('Leave record not found.', 404);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE leave_records SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?');
    $stmt->bind_param('sii', $decision, $uid, $id);
    $stmt->execute();

    audit($conn, $uid, 'leave.decide', "#$id", 'leave', $id, ['status' => $rec['status']], ['status' => $decision]);
    json_out(['ok' => true]);
}

// ----- Return to work -----
if (method() === 'POST' && $action === 'return') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $on = trim((string) ($data['return_to_work_on'] ?? date('Y-m-d')));
    $note = trim((string) ($data['note'] ?? ''));
    if ($id <= 0) fail('A leave record is required.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) fail('The date must be YYYY-MM-DD.');

    $noteVal = $note !== '' ? $note : null;
    $stmt = $conn->prepare('UPDATE leave_records SET return_to_work_on = ?, return_to_work_note = ? WHERE id = ?');
    $stmt->bind_param('ssi', $on, $noteVal, $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'leave_records', $id, false)) {
        fail('Leave record not found.', 404);
    }

    audit($conn, (int) $user['id'], 'leave.return_to_work', "#$id", 'leave', $id, null, ['return_to_work_on' => $on]);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
