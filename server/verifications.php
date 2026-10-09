<?php
// Verification lifecycle.
//
//   GET  /verifications.php?person_id=N          everything being checked for one person
//   GET  /verifications.php?action=queue[&service=&requirement=]
//                                                what is waiting on somebody, oldest first
//   POST /verifications.php                      request a check
//   POST /verifications.php?action=received&id=N record that evidence arrived
//   POST /verifications.php?action=review&id=N   take it for review
//   POST /verifications.php?action=verify&id=N   sign it off
//   POST /verifications.php?action=reject&id=N   reject it, with a reason
//
// "Received" and "verified" are different events with different people behind
// them. A document arriving is not a check, and collapsing the two is how "we
// have it" quietly becomes "we checked it".
//
// Right to work keeps its own endpoint: it carries methods, share codes and
// recheck rules that belong to it alone, and flattening those into this generic
// shape would lose them.
require __DIR__ . '/lib.php';
// Verifying or rejecting something changes what the deployment gate concludes, so
// the worker's derived status is recalculated here rather than left to the
// nightly run.
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
require_staff($user);
// As in right-to-work.php: verification records are vetting evidence, so they
// take the compliance zone as well as a staff account.
if (!can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);
$action = $_GET['action'] ?? '';

const VERIFY_SUBJECTS = ['certification', 'licence', 'document', 'reference', 'qualification', 'other'];

// The only transitions that make sense; anything else is refused rather than
// silently allowed. Defined in pure.php (RG_VERIFY_TRANSITIONS) so the person
// profile reads the same rules.
const VERIFY_TRANSITIONS = RG_VERIFY_TRANSITIONS;

function load_verification(mysqli $conn, int $id): array
{
    $stmt = $conn->prepare('SELECT * FROM verifications WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Verification not found.', 404);
    return $row;
}

/**
 * The evidence file for a verification, refused unless it is filed against the
 * same person (directly, or through one of their worker records).
 *
 * A check pointing at somebody else's document is worse than one pointing at
 * nothing: it records an assurance about the wrong human.
 */
function verification_file(mysqli $conn, $raw, int $personId): ?int
{
    if ($raw === null || $raw === '' || (int) $raw <= 0) return null;
    $fileId = (int) $raw;
    $stmt = $conn->prepare(
        'SELECT f.id FROM files f
           LEFT JOIN workers w ON w.id = f.worker_id
          WHERE f.id = ? AND f.deleted_at IS NULL AND (f.person_id = ? OR w.person_id = ?) LIMIT 1'
    );
    $stmt->bind_param('iii', $fileId, $personId, $personId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('That document is not filed against this person.', 422);
    return $fileId;
}

function move_to(mysqli $conn, array $user, array $verification, string $to, array $set = [], ?string $reason = null): void
{
    $from = $verification['status'];
    $allowed = VERIFY_TRANSITIONS[$from] ?? [];
    if (!in_array($to, $allowed, true)) {
        fail("A verification cannot go from '$from' to '$to'." .
             ($allowed ? ' From here it can only become: ' . implode(', ', $allowed) . '.' : ''), 409);
    }

    $columns = ['status = ?'];
    $types = 's';
    $values = [$to];
    foreach ($set as $column => $value) {
        $columns[] = "$column = ?";
        $types .= is_int($value) ? 'i' : 's';
        $values[] = $value;
    }
    $id = (int) $verification['id'];
    $types .= 'i';
    $values[] = $id;

    $stmt = $conn->prepare('UPDATE verifications SET ' . implode(', ', $columns) . ' WHERE id = ?');
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'verification.' . $to, "#$id {$verification['requirement_key']}",
        'verification', $id, ['status' => $from], ['status' => $to], $reason);
}

// ----- One person -----
if (method() === 'GET' && $action === '') {
    $personId = (int) ($_GET['person_id'] ?? 0);
    if ($personId <= 0) fail('A person is required.');
    // A person id was all it took to read somebody in another service.
    require_person_scope($conn, $user, $personId);

    $stmt = $conn->prepare(
        'SELECT v.id, v.requirement_key, v.subject_type, v.subject_label, v.status,
                v.requested_at, v.received_at, v.reviewed_at, v.verified_at, v.expires_on,
                v.method, v.outcome_note, v.rejection_reason, v.evidence_file_id,
                f.original_name AS evidence_file_name,
                rq.name AS requested_by, vb.name AS verified_by,
                r.label AS requirement
         FROM verifications v
         LEFT JOIN users rq ON rq.id = v.requested_by
         LEFT JOIN users vb ON vb.id = v.verified_by
         LEFT JOIN files f ON f.id = v.evidence_file_id AND f.deleted_at IS NULL
         LEFT JOIN compliance_requirements r ON r.req_key = v.requirement_key
         WHERE v.person_id = ? ORDER BY v.id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['evidence_file_id'] = $row['evidence_file_id'] !== null ? (int) $row['evidence_file_id'] : null;
        // What can happen next, so a client does not have to encode the rules.
        $row['next'] = VERIFY_TRANSITIONS[$row['status']] ?? [];
        $items[] = $row;
    }
    json_out(['person_id' => $personId, 'verifications' => $items]);
}

// ----- Everything waiting on somebody -----
//
// Oldest first: the check that has waited longest is the one somebody is
// chasing. Filtered by service and by requirement when asked, and always by the
// caller's service scope -- the queue names people and what is being checked
// about them, and it named everybody to every manager.
if (method() === 'GET' && $action === 'queue') {
    $service = trim((string) ($_GET['service'] ?? ''));
    if ($service !== '' && !in_array($service, ['care', 'academy', 'cleaning', 'security'], true)) {
        fail('Unknown service.');
    }
    $requirement = trim((string) ($_GET['requirement'] ?? ''));

    $sql = 'SELECT v.id, v.person_id, p.full_name, v.requirement_key, v.subject_label, v.status,
                   v.requested_at, v.received_at, v.expires_on, v.evidence_file_id,
                   COALESCE(r.label, v.requirement_key) AS requirement,
                   DATEDIFF(NOW(), COALESCE(v.received_at, v.requested_at, v.created_at)) AS waiting_days
            FROM verifications v
            JOIN people p ON p.id = v.person_id
            LEFT JOIN compliance_requirements r ON r.req_key = v.requirement_key
            WHERE v.status IN ("requested","received","under_review") AND p.deleted_at IS NULL';
    if ($requirement !== '') {
        $stmt = $conn->prepare($sql . ' AND v.requirement_key = ? ORDER BY waiting_days DESC, v.id');
        $stmt->bind_param('s', $requirement);
    } else {
        $stmt = $conn->prepare($sql . ' ORDER BY waiting_days DESC, v.id');
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;

    $services = rg_people_services($conn, array_column($rows, 'person_id'));
    $scope = service_scope($user);
    $items = [];
    foreach ($rows as $row) {
        $personServices = $services[(int) $row['person_id']] ?? [];
        if (!rg_person_in_scope($scope, $personServices)) continue;
        if ($service !== '' && !in_array($service, $personServices, true)) continue;
        $row['id'] = (int) $row['id'];
        $row['person_id'] = (int) $row['person_id'];
        $row['evidence_file_id'] = $row['evidence_file_id'] !== null ? (int) $row['evidence_file_id'] : null;
        $row['waiting_days'] = $row['waiting_days'] !== null ? (int) $row['waiting_days'] : null;
        $row['services'] = $personServices;
        $row['next'] = VERIFY_TRANSITIONS[$row['status']] ?? [];
        $items[] = $row;
    }
    json_out(['items' => $items, 'count' => count($items)]);
}

if (method() !== 'POST') fail('Method not allowed.', 405);
require_role($user, 'super_admin', 'admin', 'manager');
$uid = (int) $user['id'];

// ----- Request -----
if ($action === '') {
    $data = body();
    $personId = (int) ($data['person_id'] ?? 0);
    $requirement = trim((string) ($data['requirement_key'] ?? ''));
    $subjectType = trim((string) ($data['subject_type'] ?? 'document'));
    $label = trim((string) ($data['subject_label'] ?? ''));

    if ($personId <= 0) fail('A person is required.');
    if ($requirement === '') fail('A requirement is required.');
    if (!in_array($subjectType, VERIFY_SUBJECTS, true)) fail('Unknown subject type.');

    $stmt = $conn->prepare('SELECT id FROM people WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Person not found.', 404);
    require_person_scope($conn, $user, $personId);
    $fileId = verification_file($conn, $data['evidence_file_id'] ?? null, $personId);

    // One open check per requirement per person. Two people chasing the same
    // document is how a candidate gets asked for it twice.
    $stmt = $conn->prepare(
        'SELECT id FROM verifications WHERE person_id = ? AND requirement_key = ?
           AND status IN ("requested","received","under_review") LIMIT 1'
    );
    $stmt->bind_param('is', $personId, $requirement);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) fail('There is already an open check for that requirement.', 409);

    $labelVal = $label !== '' ? $label : null;
    $stmt = $conn->prepare(
        'INSERT INTO verifications (person_id, requirement_key, subject_type, subject_label, status, requested_at, requested_by, evidence_file_id)
         VALUES (?, ?, ?, ?, "requested", NOW(), ?, ?)'
    );
    $stmt->bind_param('isssii', $personId, $requirement, $subjectType, $labelVal, $uid, $fileId);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    audit($conn, $uid, 'verification.request', "person #$personId $requirement", 'verification', $id);
    json_out(['ok' => true, 'id' => $id], 201);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) fail('A verification is required.');
$verification = load_verification($conn, $id);
// The record names a person, and the scope is theirs.
require_person_scope($conn, $user, (int) $verification['person_id']);

if ($action === 'received') {
    // The document that arrived, where there is one. Received with nothing
    // attached is still a fact worth recording -- a paper copy in the office --
    // but an attached file is what lets the reviewer see what they are signing off.
    $set = ['received_at' => date('Y-m-d H:i:s')];
    $fileId = verification_file($conn, body()['evidence_file_id'] ?? null, (int) $verification['person_id']);
    if ($fileId !== null) $set['evidence_file_id'] = $fileId;
    move_to($conn, $user, $verification, 'received', $set);
    json_out(['ok' => true, 'status' => 'received']);
}

if ($action === 'review') {
    move_to($conn, $user, $verification, 'under_review', ['reviewed_at' => date('Y-m-d H:i:s')]);
    json_out(['ok' => true, 'status' => 'under_review']);
}

if ($action === 'verify') {
    $data = body();
    $method = trim((string) ($data['method'] ?? ''));
    // What was actually checked. "Checked the SIA register" and "saw a photocopy"
    // are not the same assurance, and only this can tell them apart later.
    if ($method === '') fail('Record what you checked, so the entry means something later.');

    $set = [
        'verified_by' => $uid,
        'verified_at' => date('Y-m-d H:i:s'),
        'method' => substr($method, 0, 190),
    ];
    if (!empty($data['outcome_note'])) $set['outcome_note'] = substr((string) $data['outcome_note'], 0, 500);
    if (!empty($data['expires_on'])) {
        $expires = (string) $data['expires_on'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) fail('The expiry must be YYYY-MM-DD.');
        $set['expires_on'] = $expires;
    }
    move_to($conn, $user, $verification, 'verified', $set, $method);
    // Now, not at 3am. A credential signed off at 10am left the worker reading as
    // blocked on every screen until the following morning.
    rg_refresh_person_workers($conn, (int) $verification['person_id']);
    json_out(['ok' => true, 'status' => 'verified']);
}

if ($action === 'reject') {
    $reason = trim((string) (body()['reason'] ?? ''));
    if ($reason === '') fail('Give a reason, so the person can be told what was wrong.');
    move_to($conn, $user, $verification, 'rejected',
        ['rejection_reason' => substr($reason, 0, 500), 'reviewed_at' => date('Y-m-d H:i:s')], $reason);
    // A rejection is the direction that matters most: somebody who should stop
    // being offered work must stop being offered it now, not tomorrow.
    rg_refresh_person_workers($conn, (int) $verification['person_id']);
    json_out(['ok' => true, 'status' => 'rejected']);
}

fail('Unknown action.', 404);
