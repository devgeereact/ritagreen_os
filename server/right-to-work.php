<?php
// Right-to-work checks.
//
//   GET  /right-to-work.php?person_id=N        -> every check for a person
//   GET  /right-to-work.php?action=due&days=60 -> checks needing attention
//   POST /right-to-work.php                    -> record a check
//   POST /right-to-work.php?action=verify&id=N -> sign a check off
//
// A check is evidence, not a flag. Recording one captures how it was done, when,
// what it showed and what happens next; verifying it captures who says so. The
// two are separate on purpose -- the person who collects a document is often not
// the person who signs it off, and "verified" with nobody's name against it is
// not verification.
//
// The legacy workers.right_to_work flag is kept in step so nothing that still
// reads it regresses, but the check record is the source of truth.
require __DIR__ . '/lib.php';
// A recorded or verified check changes what the deployment gate concludes, so
// the worker's derived status is recalculated here, not left to the nightly run.
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

// Compliance data: internal staff only.
require_staff($user);
// And staff is not enough: a right-to-work check is vetting evidence, so it
// takes the compliance zone too. Editor is staff and does not hold it.
if (!can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);

const RTW_METHODS = ['online_share_code', 'manual_document', 'idsp_digital', 'other'];
const RTW_OUTCOMES = ['pending', 'valid', 'invalid', 'requires_follow_up'];

// ----- Checks needing attention -----
if (method() === 'GET' && $action === 'due') {
    $days = max(0, min(365, (int) ($_GET['days'] ?? 60)));
    $stmt = $conn->prepare(
        'SELECT r.id, r.person_id, p.full_name, r.method, r.checked_on, r.outcome,
                r.recheck_due, r.follow_up_required, r.verified_at,
                DATEDIFF(r.recheck_due, CURDATE()) AS days_left
         FROM right_to_work_checks r
         JOIN people p ON p.id = r.person_id
         WHERE r.outcome <> "invalid"
           AND (r.verified_at IS NULL
                OR r.follow_up_required = 1
                OR (r.recheck_due IS NOT NULL AND r.recheck_due <= DATE_ADD(CURDATE(), INTERVAL ? DAY)))
         ORDER BY r.recheck_due IS NULL, r.recheck_due'
    );
    $stmt->bind_param('i', $days);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    // Names and right-to-work state of people in other services are not this
    // account's to see when it is confined to one.
    $scope = service_scope($user);
    $services = $scope !== null ? rg_people_services($conn, array_column($rows, 'person_id')) : [];

    $items = [];
    foreach ($rows as $row) {
        if ($scope !== null && !rg_person_in_scope($scope, $services[(int) $row['person_id']] ?? [])) continue;
        $row['id'] = (int) $row['id'];
        $row['person_id'] = (int) $row['person_id'];
        $row['follow_up_required'] = (bool) $row['follow_up_required'];
        $row['verified'] = $row['verified_at'] !== null;
        $row['days_left'] = $row['days_left'] !== null ? (int) $row['days_left'] : null;
        // Why this row is on the list, so the reader does not have to work it out.
        $row['attention'] = $row['verified_at'] === null
            ? 'Not yet verified'
            : ($row['follow_up_required'] ? 'Follow-up required' : 'Recheck due');
        $items[] = $row;
    }
    json_out(['items' => $items, 'window_days' => $days]);
}

// ----- One person's history -----
if (method() === 'GET') {
    $personId = (int) ($_GET['person_id'] ?? 0);
    if ($personId <= 0) fail('A person is required.');
    require_person_scope($conn, $user, $personId);

    $stmt = $conn->prepare(
        'SELECT r.id, r.method, r.method_note, r.reference, r.checked_on, r.outcome,
                r.follow_up_required, r.recheck_due, r.evidence_file_id, r.notes,
                r.created_at, r.verified_at, u.name AS verified_by,
                f.original_name AS evidence_file_name
         FROM right_to_work_checks r
         LEFT JOIN users u ON u.id = r.verified_by
         LEFT JOIN files f ON f.id = r.evidence_file_id AND f.deleted_at IS NULL
         WHERE r.person_id = ? ORDER BY r.checked_on DESC, r.id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();

    $checks = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['follow_up_required'] = (bool) $row['follow_up_required'];
        $row['evidence_file_id'] = $row['evidence_file_id'] !== null ? (int) $row['evidence_file_id'] : null;
        $row['verified'] = $row['verified_at'] !== null;
        $checks[] = $row;
    }
    json_out(['person_id' => $personId, 'checks' => $checks, 'current' => $checks[0] ?? null]);
}

// ----- Record a check -----
if (method() === 'POST' && $action === '') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();

    $personId = (int) ($data['person_id'] ?? 0);
    $method = trim((string) ($data['method'] ?? 'manual_document'));
    $outcome = trim((string) ($data['outcome'] ?? 'pending'));
    $checkedOn = trim((string) ($data['checked_on'] ?? date('Y-m-d')));
    $recheckDue = trim((string) ($data['recheck_due'] ?? ''));
    $reference = trim((string) ($data['reference'] ?? ''));
    $methodNote = trim((string) ($data['method_note'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));
    $followUp = !empty($data['follow_up_required']) ? 1 : 0;
    $fileId = isset($data['evidence_file_id']) && $data['evidence_file_id'] !== '' ? (int) $data['evidence_file_id'] : null;

    if ($personId <= 0) fail('A person is required.');
    if (!in_array($method, RTW_METHODS, true)) fail('Unknown check method.');
    if (!in_array($outcome, RTW_OUTCOMES, true)) fail('Unknown outcome.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkedOn)) fail('The check date must be YYYY-MM-DD.');
    if ($recheckDue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $recheckDue)) fail('The recheck date must be YYYY-MM-DD.');
    if ($checkedOn > date('Y-m-d')) fail('A check cannot be dated in the future.');

    $stmt = $conn->prepare('SELECT id FROM people WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Person not found.', 404);
    require_person_scope($conn, $user, $personId);

    // The scan or screenshot of the check, filed against this person. A check
    // pointing at somebody else's document records the wrong human's status.
    if ($fileId !== null) {
        $stmt = $conn->prepare(
            'SELECT f.id FROM files f LEFT JOIN workers w ON w.id = f.worker_id
              WHERE f.id = ? AND f.deleted_at IS NULL AND (f.person_id = ? OR w.person_id = ?) LIMIT 1'
        );
        $stmt->bind_param('iii', $fileId, $personId, $personId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) fail('That document is not filed against this person.', 422);
    }

    $recheckVal = $recheckDue !== '' ? $recheckDue : null;
    $refVal = $reference !== '' ? $reference : null;
    $noteVal = $methodNote !== '' ? $methodNote : null;
    $notesVal = $notes !== '' ? $notes : null;

    $stmt = $conn->prepare(
        'INSERT INTO right_to_work_checks
           (person_id, method, method_note, reference, checked_on, outcome, follow_up_required, recheck_due, evidence_file_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssssisis', $personId, $method, $noteVal, $refVal, $checkedOn, $outcome, $followUp, $recheckVal, $fileId, $notesVal);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    sync_legacy_rtw_flag($conn, $personId);
    rg_refresh_person_workers($conn, $personId);
    audit($conn, (int) $user['id'], 'rtw.record', "person #$personId", 'person', $personId,
        null, ['method' => $method, 'outcome' => $outcome, 'checked_on' => $checkedOn, 'recheck_due' => $recheckVal]);
    json_out(['ok' => true, 'id' => $id], 201);
}

// ----- Verify a check -----
if (method() === 'POST' && $action === 'verify') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A check is required.');

    $stmt = $conn->prepare('SELECT person_id, outcome, verified_at FROM right_to_work_checks WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    if (!$check) fail('Check not found.', 404);
    require_person_scope($conn, $user, (int) $check['person_id']);
    if ($check['verified_at'] !== null) fail('That check is already verified.', 409);
    // Signing off a check whose outcome is still unresolved would be signing off
    // nothing. Record the outcome first.
    if ($check['outcome'] === 'pending') fail('Record the outcome of the check before verifying it.', 409);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE right_to_work_checks SET verified_by = ?, verified_at = NOW() WHERE id = ?');
    $stmt->bind_param('ii', $uid, $id);
    $stmt->execute();

    sync_legacy_rtw_flag($conn, (int) $check['person_id']);
    rg_refresh_person_workers($conn, (int) $check['person_id']);
    // The question this exists to answer: who signed this off, and when?
    audit($conn, $uid, 'rtw.verify', "check #$id", 'right_to_work_check', $id,
        ['verified' => false], ['verified' => true, 'outcome' => $check['outcome']]);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);

/**
 * Keep workers.right_to_work in step with the check record.
 *
 * The boolean is still read by scheduling, reports and the worker list. It is no
 * longer the source of truth, but leaving it stale would make two parts of the
 * system disagree about the same person.
 */
function sync_legacy_rtw_flag(mysqli $conn, int $personId): void
{
    $stmt = $conn->prepare(
        'SELECT outcome FROM right_to_work_checks
         WHERE person_id = ? ORDER BY checked_on DESC, id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $latest = $stmt->get_result()->fetch_assoc();

    $flag = ($latest && in_array($latest['outcome'], ['valid', 'requires_follow_up'], true)) ? 1 : 0;
    $stmt = $conn->prepare('UPDATE workers SET right_to_work = ? WHERE person_id = ?');
    $stmt->bind_param('ii', $flag, $personId);
    $stmt->execute();
}
