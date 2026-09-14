<?php
// Activation: contract evidence, an authorised approval, and a gate that every
// assignment path passes through.
//
// rg_deployment_check() weighed compliance evidence and nothing else, so a worker
// who was suspended, had left, was half way through onboarding, or had never been
// approved for work could be put on a shift as long as their DBS was in date.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin = $client->login('admin@local.invalid');
$adminId = (int) $db->query("SELECT id FROM users WHERE email = 'admin@local.invalid'")->fetch_row()[0];

// A worker to take through activation, created the way the application creates
// one: from an application, with a checklist.
$db->query('DELETE FROM rate_limits');
$client->post('/ats.php', [
    'name' => 'Activation Candidate', 'email' => 'activation.candidate@example.test',
    'phone' => '07700900871', 'role_applied' => 'Guard', 'division' => 'security', 'consent' => true,
]);
$applicantId = (int) $db->query(
    "SELECT id FROM applicants WHERE email = 'activation.candidate@example.test' ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];
$onboard = $admin->post("/ats.php?action=onboard&id=$applicantId", []);
Rgt::status('the candidate becomes a pending worker', $onboard, 201);
$workerId = (int) $onboard->get('worker_id');
$personId = (int) $onboard->get('person_id');

Rgt::group('activation — a new worker is not approved');

$w = $db->query("SELECT status, approved_at, approved_by FROM workers WHERE id = $workerId")->fetch_assoc();
Rgt::same('the worker starts at onboarding', $w['status'], 'onboarding');
Rgt::same('with no approval', $w['approved_at'], null);
Rgt::same('and nobody named as approver', $w['approved_by'], null);

Rgt::group('activation — the gate refuses an unapproved worker');

$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::status('the gate answers', $check, 200);
Rgt::same('and refuses', $check->get('status'), 'not_eligible');
// Named separately from a credential failure: the two need different people.
Rgt::same('because of employment state, not evidence', $check->get('blocked_by'), 'employment');
Rgt::ok('naming the outstanding onboarding',
    str_contains((string) $check->get('reason'), 'onboarding'), (string) $check->get('reason'));

Rgt::group('activation — onboarding must be finished first');

$res = $admin->post("/workers.php?action=approve&id=$workerId", []);
Rgt::status('approving a worker mid-onboarding is refused', $res, 409);
Rgt::ok('and says what is outstanding', str_contains($res->error(), 'onboarding'), $res->error());
Rgt::same('nothing was approved',
    $db->query("SELECT approved_at FROM workers WHERE id = $workerId")->fetch_row()[0], null);

// Finish the checklist, through the endpoint rather than by writing rows.
$tasks = $admin->get("/ats.php?id=$applicantId")->get('tasks', []);
Rgt::ok('the checklist was seeded', count($tasks) > 0);
foreach ($tasks as $t) {
    $admin->post('/ats.php?action=task_done&task_id=' . (int) $t['id'], ['done' => true]);
}
Rgt::same('and is now finished',
    (int) $db->query(
        "SELECT COUNT(*) FROM onboarding_tasks t JOIN applicants a ON a.id = t.applicant_id
          WHERE a.person_id = $personId AND t.done = 0"
    )->fetch_row()[0], 0);

Rgt::group('activation — a reviewed signed contract is required');

$res = $admin->post("/workers.php?action=approve&id=$workerId", []);
Rgt::status('approving without a contract is refused', $res, 409);
Rgt::ok('and says so', str_contains($res->error(), 'contract'), $res->error());

$draft = $admin->post('/employment-contracts.php', [
    'person_id' => $personId,
    'engagement_type' => 'worker',
    'job_title' => 'Security officer',
    'start_date' => '2026-10-01',
    'pay_rate_pence' => 1500,
    'pay_frequency' => 'hourly',
]);
Rgt::status('a contract can be drafted', $draft, 201);
$contractId = (int) $draft->get('id');
Rgt::ok('with a reference', str_starts_with((string) $draft->get('reference'), 'EC-'), $draft->body);

Rgt::group('activation — the contract lifecycle is a sequence');

// A contract that jumped from draft to signed would record an agreement nobody
// made.
$res = $admin->post("/employment-contracts.php?action=review&id=$contractId", []);
Rgt::status('a draft cannot be reviewed', $res, 409);

$res = $admin->post("/employment-contracts.php?action=accepted&id=$contractId", []);
Rgt::status('a draft cannot be accepted before it is sent', $res, 409);
Rgt::ok('and says where it can go from here', str_contains($res->error(), 'sent'), $res->error());

Rgt::status('it can be sent', $admin->post("/employment-contracts.php?action=sent&id=$contractId", []), 200);
Rgt::status('then accepted', $admin->post("/employment-contracts.php?action=accepted&id=$contractId", []), 200);

Rgt::group('activation — the signed copy must be a real document, filed correctly');

$res = $admin->post("/employment-contracts.php?action=signed&id=$contractId", ['signed_name' => 'Activation Candidate']);
Rgt::status('a signature with no document is refused', $res, 400);
$res = $admin->post("/employment-contracts.php?action=signed&id=$contractId", [
    'signed_file_id' => 999999, 'signed_name' => 'Activation Candidate',
]);
Rgt::status('a document that is not in the vault is refused', $res, 404);

// Somebody else's document.
$otherWorker = (int) $db->query("SELECT id FROM workers WHERE id <> $workerId ORDER BY id LIMIT 1")->fetch_row()[0];
$db->query(
    "INSERT INTO files (stored_name, original_name, mime, size_bytes, category, worker_id)
     VALUES ('other.pdf', 'Someone else contract.pdf', 'application/pdf', 100, 'contract', $otherWorker)"
);
$foreignFile = (int) $db->insert_id;
$res = $admin->post("/employment-contracts.php?action=signed&id=$contractId", [
    'signed_file_id' => $foreignFile, 'signed_name' => 'Activation Candidate',
]);
Rgt::status("another person's document is refused", $res, 422);

$db->query(
    "INSERT INTO files (stored_name, original_name, mime, size_bytes, category, worker_id, person_id)
     VALUES ('signed.pdf', 'Signed contract.pdf', 'application/pdf', 2048, 'contract', $workerId, $personId)"
);
$signedFile = (int) $db->insert_id;
$res = $admin->post("/employment-contracts.php?action=signed&id=$contractId", [
    'signed_file_id' => $signedFile, 'signed_name' => 'Activation Candidate',
]);
Rgt::status('the right document is accepted', $res, 200);

Rgt::group('activation — a signature is not a review');

$res = $admin->post("/workers.php?action=approve&id=$workerId", []);
Rgt::status('a signed but unreviewed contract does not allow approval', $res, 409);
Rgt::ok('and says the contract has not been reviewed',
    str_contains($res->error(), 'reviewed'), $res->error());

$res = $admin->post("/employment-contracts.php?action=review&id=$contractId", [
    'note' => 'Terms match the offer; signature matches the identity document',
]);
Rgt::status('an authorised person can review it', $res, 200);
$c = $db->query("SELECT reviewed_at, reviewed_by, status FROM employment_contracts WHERE id = $contractId")->fetch_assoc();
Rgt::ok('the review is dated', $c['reviewed_at'] !== null);
Rgt::same('and attributed', (int) $c['reviewed_by'], $adminId);
// Reviewing is a fact about a signed contract, not a state it moves to.
Rgt::same('the contract is still signed', $c['status'], 'signed');

Rgt::status('and it cannot be reviewed twice',
    $admin->post("/employment-contracts.php?action=review&id=$contractId", []), 409);

Rgt::group('activation — approval is attributable, and it is a decision');

$res = $admin->post("/workers.php?action=approve&id=$workerId", ['note' => 'Cleared by the security manager']);
Rgt::status('the worker can now be approved', $res, 200);

$w = $db->query("SELECT status, approved_at, approved_by, approval_note, derived_status FROM workers WHERE id = $workerId")->fetch_assoc();
Rgt::ok('the approval is dated', $w['approved_at'] !== null);
Rgt::same('and names who made it', (int) $w['approved_by'], $adminId);
Rgt::same('with the note kept', $w['approval_note'], 'Cleared by the security manager');
Rgt::same('the worker is active', $w['status'], 'active');

// Recalculated now, not at the next cron run.
Rgt::ok('and the derived status was recalculated immediately',
    $w['derived_status'] !== null && $w['derived_status'] !== 'onboarding', (string) $w['derived_status']);

Rgt::same('approving twice is refused',
    $admin->post("/workers.php?action=approve&id=$workerId", [])->status, 409);

$audited = (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'worker.approve' AND entity_id = $workerId"
)->fetch_row()[0];
Rgt::same('and the approval is audited exactly once', $audited, 1);

Rgt::group('activation — the gate now lets them through employment state');

$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::ok('employment state no longer blocks them',
    $check->get('blocked_by') !== 'employment', $check->body);

Rgt::group('activation — withdrawing approval stops them again');

$res = $admin->post("/workers.php?action=unapprove&id=$workerId", []);
Rgt::status('withdrawing approval without a reason is refused', $res, 400);

$res = $admin->post("/workers.php?action=unapprove&id=$workerId", ['reason' => 'DBS disclosure under review']);
Rgt::status('withdrawing approval with a reason is accepted', $res, 200);
Rgt::same('the approval is gone',
    $db->query("SELECT approved_at FROM workers WHERE id = $workerId")->fetch_row()[0], null);

$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::same('and the gate blocks them again', $check->get('blocked_by'), 'employment');
Rgt::ok('for want of approval',
    str_contains((string) $check->get('reason'), 'approved'), (string) $check->get('reason'));

// Put it back so later groups have an approved worker.
$admin->post("/workers.php?action=approve&id=$workerId", ['note' => 'Disclosure cleared']);

Rgt::group('activation — suspension and leaving stop a worker at the gate');

$db->query("UPDATE workers SET status = 'suspended' WHERE id = $workerId");
$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::same('a suspended worker is blocked', $check->get('blocked_by'), 'employment');
Rgt::ok('and told they are suspended',
    str_contains((string) $check->get('reason'), 'suspended'), (string) $check->get('reason'));

$db->query("UPDATE workers SET status = 'active', left_on = '2026-06-30' WHERE id = $workerId");
$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::same('a leaver is blocked', $check->get('blocked_by'), 'employment');
// A leaver is not a compliance problem and must not be reported as one.
Rgt::ok('and reported as having left, not as non-compliant',
    str_contains((string) $check->get('reason'), 'left on'), (string) $check->get('reason'));
$db->query("UPDATE workers SET left_on = NULL WHERE id = $workerId");

Rgt::group('activation — contract records are versioned, never edited');

$res = $admin->post("/employment-contracts.php?action=supersede&id=$contractId", []);
Rgt::status('a signed contract can be superseded', $res, 201);
$newId = (int) $res->get('id');
Rgt::same('the new version is version 2', (int) $res->get('version'), 2);

$old = $db->query("SELECT status, signed_at, reviewed_at FROM employment_contracts WHERE id = $contractId")->fetch_assoc();
Rgt::same('the old one is marked superseded', $old['status'], 'superseded');
// It is evidence of what was agreed at the time. A new set of terms does not
// change what the last set said.
Rgt::ok('but keeps its signature and its review', $old['signed_at'] !== null && $old['reviewed_at'] !== null);

$new = $db->query("SELECT status, supersedes_id, version FROM employment_contracts WHERE id = $newId")->fetch_assoc();
Rgt::same('the new one starts as a draft', $new['status'], 'draft');
Rgt::same('and points back at what it replaced', (int) $new['supersedes_id'], $contractId);

Rgt::group('activation — who may do any of this');

Rgt::denied('a worker login cannot read contracts',
    $client->login('amara.login@local.invalid')->get("/employment-contracts.php?person_id=$personId"));
Rgt::denied('a client login cannot read contracts',
    $client->login('priya@local.invalid')->get("/employment-contracts.php?person_id=$personId"));
// An editor is staff but holds no compliance zone.
Rgt::denied('an editor cannot read contracts',
    $client->login('editor@local.invalid')->get("/employment-contracts.php?person_id=$personId"));
Rgt::denied('a trainer cannot approve a worker',
    $client->login('trainer@local.invalid')->post("/workers.php?action=approve&id=$workerId", []));
Rgt::denied('a worker cannot approve themselves',
    $client->login('amara.login@local.invalid')->post("/workers.php?action=approve&id=$workerId", []));

// Service scope reaches this too: the worker is in security.
$scoped = $client->login('scoped@local.invalid');   // manager, security
Rgt::ok('a security manager can read a security worker\'s contracts',
    $scoped->get("/employment-contracts.php?person_id=$personId")->status === 200);

$careWorker = $db->query(
    "SELECT id, person_id FROM workers WHERE division = 'care' AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch_assoc();
Rgt::denied('but not a care worker\'s',
    $scoped->get('/employment-contracts.php?person_id=' . (int) $careWorker['person_id']));
Rgt::denied('and cannot approve a care worker',
    $scoped->post('/workers.php?action=approve&id=' . (int) $careWorker['id'], []));
