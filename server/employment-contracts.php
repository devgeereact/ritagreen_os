<?php
// Employment contract evidence.
//
//   GET  /employment-contracts.php?person_id=N     every contract for one person
//   GET  /employment-contracts.php?action=queue    contracts waiting on somebody
//   POST /employment-contracts.php                 draft a contract {person_id, document_file_id?, ...}
//   POST /employment-contracts.php?action=document&id=N  attach the agreed version to a draft
//   POST /employment-contracts.php?action=sent&id=N      record that it went out
//   POST /employment-contracts.php?action=accepted&id=N  record that they agreed
//   POST /employment-contracts.php?action=signed&id=N    attach the signed copy
//   POST /employment-contracts.php?action=review&id=N    an authorised person read it
//   POST /employment-contracts.php?action=supersede&id=N replace it with a new version
//
// NOT the Contracts screen. server/contracts.php is commercial: agreements with
// CLIENTS, with rates and terms of business. This is employment evidence about a
// PERSON, and the two were confused often enough in the review to be worth saying
// at the top of the file.
//
// NOT a document designer and NOT an electronic signature service. The agreed
// version is prepared outside and uploaded; the signed copy is signed outside and
// uploaded. What this owns is the record: which version, sent when, accepted when,
// signed by whom, and read by which authorised person.
//
// Versioned rather than edited. A signed contract is evidence of what was agreed
// at a point in time, and changing it in place destroys that.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Employment terms are personal data about a named person, and they carry pay.
// Staff is not enough on its own.
require_staff($user);
if (!can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);

$action = $_GET['action'] ?? '';

const EC_ENGAGEMENTS = ['employee', 'worker', 'bank', 'agency', 'self_employed', 'apprentice'];
const EC_FREQUENCIES = ['hourly', 'daily', 'weekly', 'fortnightly', 'monthly'];

// The only transitions that make sense, in the order the work actually happens.
// Defined in pure.php (RG_CONTRACT_TRANSITIONS) so the person profile reads the
// same rules rather than keeping its own copy.
const EC_TRANSITIONS = RG_CONTRACT_TRANSITIONS;

// ---------------------------------------------------------------------------
// Reads

if (method() === 'GET' && $action === 'queue') {
    // What is waiting on somebody. Sent but not signed, and signed but not read.
    $res = $conn->query(
        'SELECT c.id, c.reference, c.version, c.status, c.engagement_type, c.job_title,
                c.start_date, c.sent_at, c.signed_at, c.reviewed_at,
                p.full_name AS person, w.id AS worker_id, w.division
           FROM employment_contracts c
           JOIN people p ON p.id = c.person_id
           LEFT JOIN workers w ON w.id = c.worker_id
          WHERE (c.status IN ("draft","sent","viewed","accepted"))
             OR (c.status = "signed" AND c.reviewed_at IS NULL)
          ORDER BY c.status = "signed" DESC, c.sent_at IS NULL, c.sent_at'
    );
    $items = [];
    $scope = service_scope($user);
    while ($row = $res->fetch_assoc()) {
        // Scoped in PHP rather than in the query because a contract may have no
        // worker yet, and therefore no division. rg_in_service_scope() treats an
        // unknown service as out of scope for a scoped account, deliberately.
        if (!rg_in_service_scope($scope, $row['division'])) continue;
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $items[] = $row;
    }
    json_out(['contracts' => $items]);
}

if (method() === 'GET') {
    $personId = (int) ($_GET['person_id'] ?? 0);
    if ($personId <= 0) fail('A person is required.');

    // Scoped by every service the person holds. This read the FIRST worker
    // record's division and applied no scope at all to a person with no worker
    // record, so an applicant in another service was readable by id.
    require_person_scope($conn, $user, $personId);

    $stmt = $conn->prepare(
        'SELECT c.*, u.name AS created_by_name, r.name AS reviewed_by_name
           FROM employment_contracts c
           LEFT JOIN users u ON u.id = c.created_by
           LEFT JOIN users r ON r.id = c.reviewed_by
          WHERE c.person_id = ?
          ORDER BY c.version DESC, c.id DESC'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        // The token is how a worker opens their own copy without a login. It is a
        // bearer credential and has no business in a staff listing.
        unset($row['access_token']);
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['person_id'] = (int) $row['person_id'];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $row['pay_rate_pence'] = $row['pay_rate_pence'] !== null ? (int) $row['pay_rate_pence'] : null;
        $row['supersedes_id'] = $row['supersedes_id'] !== null ? (int) $row['supersedes_id'] : null;
        $items[] = $row;
    }
    json_out(['contracts' => $items]);
}

// ---------------------------------------------------------------------------
// Writes

require_role($user, 'super_admin', 'admin', 'manager');
$uid = (int) $user['id'];

if (method() !== 'POST') fail('Method not allowed.', 405);

// ----- Draft a contract -----
if ($action === '') {
    $data = body();
    $personId = (int) ($data['person_id'] ?? 0);
    if ($personId <= 0) fail('A person is required.');

    $stmt = $conn->prepare('SELECT id FROM people WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Person not found.', 404);

    // Link the employment record to the worker record where one exists, and take
    // the service to scope against from it.
    $stmt = $conn->prepare('SELECT id, division FROM workers WHERE person_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    $workerId = $w ? (int) $w['id'] : null;
    require_service_scope($user, $w['division'] ?? null);

    $engagement = trim((string) ($data['engagement_type'] ?? 'worker'));
    if (!in_array($engagement, EC_ENGAGEMENTS, true)) fail('That is not an engagement type.');

    $frequency = trim((string) ($data['pay_frequency'] ?? ''));
    if ($frequency !== '' && !in_array($frequency, EC_FREQUENCIES, true)) fail('That is not a pay frequency.');

    $start = trim((string) ($data['start_date'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) fail('A start date must be YYYY-MM-DD.');

    $rate = $data['pay_rate_pence'] ?? null;
    // Money is integers in pence, and a negative rate is not a rate.
    if ($rate !== null && $rate !== '' && ((int) $rate) < 0) fail('A pay rate cannot be negative.');

    $reference = 'EC-' . sprintf('%06d', next_seq($conn, 'employment_contract'));
    $jobTitle = trim((string) ($data['job_title'] ?? '')) ?: null;
    $place = trim((string) ($data['place_of_work'] ?? '')) ?: null;
    $hours = trim((string) ($data['hours_pattern'] ?? '')) ?: null;
    $notice = trim((string) ($data['notice_period_worker'] ?? '')) ?: null;
    $noticeEmployer = trim((string) ($data['notice_period_employer'] ?? '')) ?: null;
    $startVal = $start !== '' ? $start : null;
    $freqVal = $frequency !== '' ? $frequency : null;
    $rateVal = ($rate === null || $rate === '') ? null : (int) $rate;
    // The agreed version, prepared outside the system and uploaded. Optional at
    // drafting: it can be attached to the draft afterwards with action=document.
    $documentId = ec_person_file($conn, $data['document_file_id'] ?? null, $personId, $workerId);

    $stmt = $conn->prepare(
        'INSERT INTO employment_contracts
            (person_id, worker_id, reference, engagement_type, job_title, start_date,
             place_of_work, pay_rate_pence, pay_frequency, hours_pattern,
             notice_period_worker, notice_period_employer, document_file_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iissssssssssii',
        $personId, $workerId, $reference, $engagement, $jobTitle, $startVal,
        $place, $rateVal, $freqVal, $hours, $notice, $noticeEmployer, $documentId, $uid
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;

    audit($conn, $uid, 'contract.draft', $reference, 'employment_contract', $id,
        null, ['person_id' => $personId, 'engagement_type' => $engagement]);
    json_out(['ok' => true, 'id' => $id, 'reference' => $reference], 201);
}

// Everything below acts on one existing contract.
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) fail('A contract is required.');

$stmt = $conn->prepare(
    'SELECT c.*, w.division
       FROM employment_contracts c
       LEFT JOIN workers w ON w.id = c.worker_id
      WHERE c.id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$contract = $stmt->get_result()->fetch_assoc();
if (!$contract) fail('Contract not found.', 404);
require_service_scope($user, $contract['division'] ?? null);

$was = (string) $contract['status'];

/** Refuse a transition the lifecycle does not allow, saying what is possible. */
function ec_require_transition(string $from, string $to): void
{
    $allowed = EC_TRANSITIONS[$from] ?? [];
    if (!in_array($to, $allowed, true)) {
        fail(
            "A contract at '$from' cannot move to '$to'."
            . ($allowed ? ' From here it can go to: ' . implode(', ', $allowed) . '.' : ''),
            409
        );
    }
}

// ----- The agreed version -----
// Attached while the contract is still a draft. Once it has been sent, the
// version the person received is the version on file; changing it then would
// make the record say they were sent something they were not. Supersede instead.
if ($action === 'document') {
    if ($was !== 'draft') fail('The agreed version can only be attached to a draft. Supersede this contract to change it.', 409);
    $fileId = ec_person_file($conn, body()['document_file_id'] ?? null, (int) $contract['person_id'],
        $contract['worker_id'] !== null ? (int) $contract['worker_id'] : null);
    if ($fileId === null) fail('Attach the agreed version.');
    $stmt = $conn->prepare('UPDATE employment_contracts SET document_file_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $fileId, $id);
    $stmt->execute();
    audit($conn, $uid, 'contract.document', $contract['reference'], 'employment_contract', $id,
        ['document_file_id' => $contract['document_file_id']], ['document_file_id' => $fileId]);
    json_out(['ok' => true, 'document_file_id' => $fileId]);
}

// ----- It went out -----
if ($action === 'sent') {
    ec_require_transition($was, 'sent');
    $stmt = $conn->prepare('UPDATE employment_contracts SET status = "sent", sent_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, $uid, 'contract.sent', $contract['reference'], 'employment_contract', $id,
        ['status' => $was], ['status' => 'sent']);
    json_out(['ok' => true, 'status' => 'sent']);
}

// ----- They agreed to it -----
if ($action === 'accepted') {
    ec_require_transition($was, 'accepted');
    $stmt = $conn->prepare('UPDATE employment_contracts SET status = "accepted", accepted_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, $uid, 'contract.accepted', $contract['reference'], 'employment_contract', $id,
        ['status' => $was], ['status' => 'accepted']);
    json_out(['ok' => true, 'status' => 'accepted']);
}

// ----- The signed copy is on file -----
if ($action === 'signed') {
    ec_require_transition($was, 'signed');
    $data = body();
    $fileId = (int) ($data['signed_file_id'] ?? 0);
    $signedName = trim((string) ($data['signed_name'] ?? ''));
    if ($fileId <= 0) fail('Attach the signed copy before recording a signature.');
    if ($signedName === '') fail('Record the name of the person who signed.');
    if (mb_strlen($signedName) > 160) fail('That name is too long.');

    // The file has to exist, and it has to be filed against this person. A
    // contract pointing at somebody else's document is worse than one pointing
    // at nothing.
    $stmt = $conn->prepare('SELECT id, person_id, worker_id FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    if (!$file) fail('That document is not in the vault.', 404);

    $filePerson = $file['person_id'] !== null ? (int) $file['person_id'] : null;
    $fileWorker = $file['worker_id'] !== null ? (int) $file['worker_id'] : null;
    $belongs = ($filePerson !== null && $filePerson === (int) $contract['person_id'])
        || ($fileWorker !== null && $contract['worker_id'] !== null && $fileWorker === (int) $contract['worker_id']);
    if (!$belongs) fail('That document is filed against a different person.', 422);

    $stmt = $conn->prepare(
        'UPDATE employment_contracts
            SET status = "signed", signed_at = NOW(), signed_name = ?, signed_file_id = ?
          WHERE id = ?'
    );
    $stmt->bind_param('sii', $signedName, $fileId, $id);
    $stmt->execute();

    audit($conn, $uid, 'contract.signed', $contract['reference'], 'employment_contract', $id,
        ['status' => $was], ['status' => 'signed', 'signed_name' => $signedName, 'signed_file_id' => $fileId]);
    json_out(['ok' => true, 'status' => 'signed']);
}

// ----- Somebody with the authority read the signed copy -----
if ($action === 'review') {
    // Not a status change: reviewing is a fact recorded ABOUT the signed
    // contract, and a contract that is reviewed is still signed. Receiving a
    // document is not checking it, and a signature is not a review either.
    if ($was !== 'signed') fail('Only a signed contract can be reviewed. This one is ' . $was . '.', 409);
    if ($contract['reviewed_at'] !== null) fail('This contract has already been reviewed.', 409);

    $note = trim((string) (body()['note'] ?? ''));
    if (mb_strlen($note) > 500) fail('Please keep the note under 500 characters.');
    $noteVal = $note !== '' ? $note : null;

    $stmt = $conn->prepare(
        'UPDATE employment_contracts SET reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE id = ?'
    );
    $stmt->bind_param('isi', $uid, $noteVal, $id);
    $stmt->execute();

    audit($conn, $uid, 'contract.review', $contract['reference'], 'employment_contract', $id,
        null, ['reviewed_by' => $uid]);
    json_out(['ok' => true, 'reviewed' => true]);
}

// ----- Replace it with a new version -----
if ($action === 'supersede') {
    ec_require_transition($was, 'superseded');

    // The old row is kept and marked, never edited. It is evidence of what was
    // agreed at the time, and a new set of terms does not change what the last
    // set said.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE employment_contracts SET status = "superseded" WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $reference = 'EC-' . sprintf('%06d', next_seq($conn, 'employment_contract'));
        $nextVersion = (int) $contract['version'] + 1;
        $stmt = $conn->prepare(
            'INSERT INTO employment_contracts
                (person_id, worker_id, reference, version, supersedes_id, engagement_type,
                 job_title, start_date, place_of_work, pay_rate_pence, pay_frequency,
                 hours_pattern, notice_period_worker, notice_period_employer, created_by)
             SELECT person_id, worker_id, ?, ?, id, engagement_type,
                    job_title, start_date, place_of_work, pay_rate_pence, pay_frequency,
                    hours_pattern, notice_period_worker, notice_period_employer, ?
               FROM employment_contracts WHERE id = ?'
        );
        $stmt->bind_param('siii', $reference, $nextVersion, $uid, $id);
        $stmt->execute();
        $newId = (int) $conn->insert_id;
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    audit($conn, $uid, 'contract.supersede', $contract['reference'] . " -> $reference",
        'employment_contract', $id, ['status' => $was], ['status' => 'superseded', 'replaced_by' => $newId]);
    json_out(['ok' => true, 'id' => $newId, 'reference' => $reference, 'version' => $nextVersion], 201);
}

fail('Unknown action.', 404);

/**
 * A document id from the request, refused unless it is filed against this person
 * or their worker record. Null when none was given.
 */
function ec_person_file(mysqli $conn, $raw, int $personId, ?int $workerId): ?int
{
    if ($raw === null || $raw === '' || (int) $raw <= 0) return null;
    $fileId = (int) $raw;
    $stmt = $conn->prepare('SELECT person_id, worker_id FROM files WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    if (!$file) fail('That document is not in the vault.', 404);
    $belongs = ($file['person_id'] !== null && (int) $file['person_id'] === $personId)
        || ($file['worker_id'] !== null && $workerId !== null && (int) $file['worker_id'] === $workerId);
    if (!$belongs) fail('That document is filed against a different person.', 422);
    return $fileId;
}
