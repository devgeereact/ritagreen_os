<?php
// Which piece of evidence wins.
//
// rg_load_evidence() builds one picture of a person from four sources: the
// denormalised columns on workers, right_to_work_checks, verifications, and
// certifications matched to requirements by label. They disagree, and what the
// deployment gate does about the disagreement is what decides whether somebody
// works.
//
// The rule the plan sets: a rejection is somebody having looked and said no. It
// cannot be undone by an older success, by a legacy column, or by a loosely
// matched certificate.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin = $client->login('admin@local.invalid');

// A worker to experiment on, and the requirement catalogue it is judged against.
$worker = $db->query(
    "SELECT id, person_id, division FROM workers WHERE division = 'security' AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch_assoc();
$workerId = (int) $worker['id'];
$personId = (int) $worker['person_id'];

$reqKey = 'first_aid';
$reqLabel = (string) ($db->query(
    "SELECT label FROM compliance_requirements WHERE req_key = '$reqKey' LIMIT 1"
)->fetch_row()[0] ?? '');

Rgt::group('evidence — the catalogue this depends on exists');

Rgt::ok("the '$reqKey' requirement is in the catalogue", $reqLabel !== '', $reqKey);

/** Read the evidence the engine builds, via a tiny CLI shim over the real function. */
$root = dirname(__DIR__, 3);
$loadEvidence = static function (int $personId) use ($root): array {
    $script = <<<'PHP'
<?php
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';
echo json_encode(rg_load_evidence($conn, (int) $argv[1]));
PHP;
    $tmp = $root . '/_evidence_probe.php';
    file_put_contents($tmp, $script);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' ' . $personId . ' 2>&1');
    @unlink($tmp);
    $parsed = json_decode($out, true);
    return is_array($parsed) ? $parsed : ['__raw' => $out];
};

// ---------------------------------------------------------------------------
Rgt::group('evidence — a newer rejection beats an older success');

$db->query("DELETE FROM verifications WHERE person_id = $personId AND requirement_key = '$reqKey'");
$db->query("DELETE FROM certifications WHERE worker_id = $workerId AND type = " . quote($db, $reqLabel));

// Verified first, rejected afterwards. Inserted in that order so the rejection
// has the higher id -- which is what "newer" means to the loader.
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, verified_at, expires_on)
     VALUES ($personId, '$reqKey', 'document', 'verified', NOW(), '2030-01-01')"
);
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, reviewed_at, rejection_reason)
     VALUES ($personId, '$reqKey', 'document', 'rejected', NOW(), 'Certificate could not be confirmed with the provider')"
);

$evidence = $loadEvidence($personId);
Rgt::ok('the probe returned evidence', !isset($evidence['__raw']), $evidence['__raw'] ?? '');
Rgt::ok("a rejected requirement is not counted as held",
    !isset($evidence[$reqKey]) || empty($evidence[$reqKey]['verified']),
    json_encode($evidence[$reqKey] ?? null));

// ---------------------------------------------------------------------------
Rgt::group('evidence — a rejection is not revived by a certificate');

// The certifications pass runs after the verifications pass and matches on a
// lower-cased label. A rejected requirement had been removed from the picture by
// then, so a certificate carrying the same words put it straight back.
$db->query(
    "INSERT INTO certifications (worker_id, type, number, expiry, status)
     VALUES ($workerId, " . quote($db, $reqLabel) . ", 'CERT-PRECEDENCE-1', '2030-01-01', 'valid')"
);

$evidence = $loadEvidence($personId);
Rgt::ok('a rejected requirement stays rejected when a certificate says otherwise',
    !isset($evidence[$reqKey]) || empty($evidence[$reqKey]['verified']),
    json_encode($evidence[$reqKey] ?? null));

// ---------------------------------------------------------------------------
Rgt::group('evidence — a verification is not silently re-dated by a certificate');

// The reviewed decision carries the expiry somebody checked. A certificate row
// with a later date must not quietly extend it.
$db->query("DELETE FROM verifications WHERE person_id = $personId AND requirement_key = '$reqKey'");
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, verified_at, expires_on)
     VALUES ($personId, '$reqKey', 'document', 'verified', NOW(), '2027-06-30')"
);
$db->query("UPDATE certifications SET expiry = '2035-01-01' WHERE worker_id = $workerId AND number = 'CERT-PRECEDENCE-1'");

$evidence = $loadEvidence($personId);
Rgt::same('the reviewed expiry is the one that counts',
    $evidence[$reqKey]['expiry'] ?? null, '2027-06-30');

// ---------------------------------------------------------------------------
Rgt::group('evidence — a rejection beats the legacy column on workers');

// workers.dbs_status is a field somebody typed. A reviewed rejection outranks it.
$db->query("DELETE FROM verifications WHERE person_id = $personId AND requirement_key = 'dbs'");
$db->query("UPDATE workers SET dbs_status = 'valid', dbs_expiry = '2030-01-01' WHERE id = $workerId");
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, reviewed_at, rejection_reason)
     VALUES ($personId, 'dbs', 'document', 'rejected', NOW(), 'Disclosure showed an unspent conviction')"
);

$evidence = $loadEvidence($personId);
Rgt::ok('a rejected DBS is not held, whatever the column says',
    !isset($evidence['dbs']) || empty($evidence['dbs']['verified']),
    json_encode($evidence['dbs'] ?? null));

// ---------------------------------------------------------------------------
Rgt::group('evidence — a later verification can clear an earlier rejection');

// The rule is "newest wins", not "a rejection is permanent". Somebody who
// produces a valid document afterwards is verified.
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, verified_at, expires_on, outcome_note)
     VALUES ($personId, 'dbs', 'document', 'verified', NOW(), '2031-01-01', 'New enhanced disclosure seen')"
);

$evidence = $loadEvidence($personId);
Rgt::ok('the newer verification is the one that counts', !empty($evidence['dbs']['verified']),
    json_encode($evidence['dbs'] ?? null));
Rgt::same('with its own expiry', $evidence['dbs']['expiry'] ?? null, '2031-01-01');

// ---------------------------------------------------------------------------
Rgt::group('evidence — the deployment gate sees the same picture');

// Whatever rg_load_evidence() concludes has to be what decides who may work.
$db->query("DELETE FROM verifications WHERE person_id = $personId AND requirement_key = 'right_to_work'");
$db->query("DELETE FROM right_to_work_checks WHERE person_id = $personId");
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, reviewed_at, rejection_reason)
     VALUES ($personId, 'right_to_work', 'document', 'rejected', NOW(), 'Share code did not validate')"
);

// The employment gate runs first and would block this worker for want of an
// approval, which is correct but is not what this group is about. Satisfying it
// here isolates the evidence decision -- the approval path itself is tested in
// server/tests/db/cases/110_activation.php.
$db->query("UPDATE workers SET status = 'active', approved_at = NOW(), left_on = NULL WHERE id = $workerId");
$db->query(
    "UPDATE onboarding_tasks t JOIN applicants a ON a.id = t.applicant_id
        SET t.done = 1 WHERE a.person_id = $personId"
);

$check = $admin->get('/compliance.php?action=eligibility&worker_id=' . $workerId . '&service=security');
Rgt::status('the eligibility answer is available', $check, 200);
Rgt::same('and it refuses somebody whose right to work was rejected',
    $check->get('status'), 'not_eligible');
Rgt::same('on the evidence rather than on employment state',
    $check->get('blocked_by'), 'evidence');

$blocking = array_column($check->get('blocking', []), 'key');
Rgt::ok('naming right to work as what blocks them',
    in_array('right_to_work', $blocking, true), implode(',', $blocking));

/** Quote a string for an inline query. The labels come from the catalogue, not from input. */
function quote(mysqli $db, string $value): string
{
    return "'" . $db->real_escape_string($value) . "'";
}
