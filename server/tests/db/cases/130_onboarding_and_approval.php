<?php
// Onboarding checklists per service, and approval as the profile offers it.
//
// The checklist used to be five fixed tasks for every service, so a cleaner was
// asked for a DBS check and a security officer was never asked for an SIA licence.
// The office now keeps one list per service (migration 036) and starting
// onboarding seeds from it.
//
// Approval is offered on the profile only when the server says nothing blocks it,
// and the reasons it shows are the reasons the approve action would give. These
// cases hold the two to the same answer.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');     // manager, security, scoped
$trainer = $client->login('trainer@local.invalid');
$workerLogin = $client->login('amara.login@local.invalid');

Rgt::group('onboarding templates — one list per service');

$res = $admin->get('/onboarding-templates.php');
Rgt::status('an admin can read the lists', $res, 200);
Rgt::same('one for each service', array_keys($res->get('services', [])), ['care', 'cleaning', 'security', 'academy']);
$securityLabels = array_column($res->get('services.security', []), 'label');
Rgt::ok('security asks for the SIA licence', in_array('SIA licence checked on the public register', $securityLabels, true),
    implode(' | ', $securityLabels));
Rgt::ok('cleaning does not ask for a DBS check',
    !preg_grep('/DBS/', array_column($res->get('services.cleaning', []), 'label')));

$mine = $scoped->get('/onboarding-templates.php');
Rgt::status('a scoped manager can read the lists', $mine, 200);
Rgt::same('but only their own service', array_keys($mine->get('services', [])), ['security']);

Rgt::denied('a trainer cannot read or edit them', $trainer->get('/onboarding-templates.php'));
Rgt::denied('nor a worker login', $workerLogin->get('/onboarding-templates.php'));
Rgt::denied('a scoped manager cannot add to another service\'s list',
    $scoped->post('/onboarding-templates.php', ['service' => 'care', 'label' => 'Something']));

$added = $scoped->post('/onboarding-templates.php', ['service' => 'security', 'label' => 'Uniform issued']);
Rgt::status('a scoped manager can add to their own list', $added, 201);
$addedId = (int) $added->get('id');
Rgt::status('the same task twice is refused',
    $scoped->post('/onboarding-templates.php', ['service' => 'security', 'label' => 'Uniform issued']), 409);
Rgt::status('an empty task is refused',
    $scoped->post('/onboarding-templates.php', ['service' => 'security', 'label' => '  ']), 400);
Rgt::ok('adding is audited', (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'onboarding_template.add' AND entity_id = $addedId"
)->fetch_row()[0] === 1);

// Retire an item, so the seeding below proves it reads only active items.
$retire = (int) $db->query(
    "SELECT id FROM onboarding_template_items WHERE service = 'security' AND label = 'Two references received'"
)->fetch_row()[0];
Rgt::status('an item can be retired', $admin->post("/onboarding-templates.php?action=update&id=$retire", ['active' => false]), 200);
$careItem = (int) $db->query("SELECT id FROM onboarding_template_items WHERE service = 'care' LIMIT 1")->fetch_row()[0];
Rgt::denied('a scoped manager cannot retire another service\'s item',
    $scoped->post("/onboarding-templates.php?action=update&id=$careItem", ['active' => false]));

Rgt::group('onboarding templates — starting onboarding seeds from the list');

$db->query('DELETE FROM rate_limits');
$client->post('/ats.php', [
    'name' => 'Template Candidate', 'email' => 'template.candidate@example.test',
    'phone' => '07700900881', 'role_applied' => 'Security officer', 'division' => 'security', 'consent' => true,
]);
$applicantId = (int) $db->query(
    "SELECT id FROM applicants WHERE email = 'template.candidate@example.test' ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];
$onboard = $scoped->post("/ats.php?action=onboard&id=$applicantId", []);
Rgt::status('a scoped manager starts onboarding a security candidate', $onboard, 201);
$workerId = (int) $onboard->get('worker_id');
$personId = (int) $onboard->get('person_id');

$tasks = array_column($admin->get("/ats.php?id=$applicantId")->get('tasks', []), 'label');
$expected = [];
$res = $db->query("SELECT label FROM onboarding_template_items WHERE service = 'security' AND active = 1 ORDER BY sort_order, id");
while ($row = $res->fetch_row()) $expected[] = $row[0];
Rgt::same('the checklist is the active security list, in order', $tasks, $expected);
Rgt::ok('including the item just added', in_array('Uniform issued', $tasks, true));
Rgt::ok('and not the one retired', !in_array('Two references received', $tasks, true));
Rgt::status('the office can still add an ad-hoc task',
    $admin->post("/ats.php?action=add_task&id=$applicantId", ['label' => 'First aid certificate (site requires it)']), 201);

Rgt::group('approval on the profile — offered only when the server would accept it');

$profile = $admin->get("/people.php?id=$personId");
Rgt::status('the new worker has a profile', $profile, 200);
Rgt::same('reading as onboarding in security', $profile->get('services.0.status'), 'onboarding');
$blockers = array_column($profile->get('workers.0.approval.blockers', []), 'code');
Rgt::same('approval is blocked by onboarding and the contract', $blockers, ['employment', 'contract']);
Rgt::same('and the next action is the checklist', $profile->get('next_action.key'), 'finish_onboarding');

// The button the profile would hide is refused by the endpoint with the same reason.
$res = $admin->post("/workers.php?action=approve&id=$workerId", []);
Rgt::status('approving with prerequisites unmet is refused', $res, 409);
Rgt::ok('with the reason the profile showed',
    str_contains($res->error(), (string) $profile->get('workers.0.approval.blockers.0.reason')), $res->error());

foreach ($admin->get("/ats.php?id=$applicantId")->get('tasks', []) as $t) {
    $admin->post('/ats.php?action=task_done&task_id=' . (int) $t['id'], ['done' => true]);
}
$profile = $admin->get("/people.php?id=$personId");
Rgt::same('with the checklist done, only the contract blocks',
    array_column($profile->get('workers.0.approval.blockers', []), 'code'), ['contract']);

// The contract, with the agreed version attached and then the signed copy.
$db->query("INSERT INTO files (stored_name, original_name, mime, size_bytes, category, person_id)
            VALUES ('agreed.pdf', 'Agreed contract.pdf', 'application/pdf', 1000, 'contract', $personId)");
$agreed = (int) $db->insert_id;
$db->query("INSERT INTO files (stored_name, original_name, mime, size_bytes, category, person_id)
            VALUES ('signed2.pdf', 'Signed contract.pdf', 'application/pdf', 1000, 'contract', $personId)");
$signed = (int) $db->insert_id;
$otherPerson = (int) $db->query("SELECT person_id FROM workers WHERE id <> $workerId ORDER BY id LIMIT 1")->fetch_row()[0];
$db->query("INSERT INTO files (stored_name, original_name, mime, size_bytes, category, person_id)
            VALUES ('theirs.pdf', 'Their contract.pdf', 'application/pdf', 1000, 'contract', $otherPerson)");
$theirs = (int) $db->insert_id;

$res = $admin->post('/employment-contracts.php', ['person_id' => $personId, 'document_file_id' => $theirs]);
Rgt::status("drafting against another person's document is refused", $res, 422);
$draft = $admin->post('/employment-contracts.php', ['person_id' => $personId, 'job_title' => 'Security officer']);
Rgt::status('a contract can be drafted', $draft, 201);
$contractId = (int) $draft->get('id');
// Evidence comes before the contract in the journey, and none is on file yet.
Rgt::same('the next action is the missing evidence, not the contract',
    $admin->get("/people.php?id=$personId")->get('next_action.key'), 'request_evidence');
Rgt::status('the agreed version can be attached to the draft',
    $admin->post("/employment-contracts.php?action=document&id=$contractId", ['document_file_id' => $agreed]), 200);
Rgt::same('and is recorded on it', (int) $db->query(
    "SELECT document_file_id FROM employment_contracts WHERE id = $contractId")->fetch_row()[0], $agreed);
$admin->post("/employment-contracts.php?action=sent&id=$contractId", []);
Rgt::status('once sent, the agreed version cannot be swapped',
    $admin->post("/employment-contracts.php?action=document&id=$contractId", ['document_file_id' => $signed]), 409);
$admin->post("/employment-contracts.php?action=accepted&id=$contractId", []);
$admin->post("/employment-contracts.php?action=signed&id=$contractId", ['signed_file_id' => $signed, 'signed_name' => 'Template Candidate']);
Rgt::same('a signed contract is waiting for review', $admin->get("/people.php?id=$personId")->get('next_action.key'), 'review_contract');
$admin->post("/employment-contracts.php?action=review&id=$contractId", ['note' => 'Matches the offer']);

$profile = $admin->get("/people.php?id=$personId");
Rgt::same('nothing blocks approval now', $profile->get('workers.0.approval.blockers'), []);
// Approval is offered on the overview whenever nothing blocks it. The single
// next action still points at the missing evidence, because an approved worker
// without it is refused at the gate all the same.
Rgt::same('the next action stays on the missing evidence', $profile->get('next_action.key'), 'request_evidence');
Rgt::ok('and the profile says which', str_starts_with((string) $profile->get('next_action.label'), 'Request '),
    (string) $profile->get('next_action.label'));

$res = $scoped->post("/workers.php?action=approve&id=$workerId", ['note' => 'Cleared']);
Rgt::status('the security manager approves', $res, 200);
Rgt::same('and the application is now onboarded',
    $db->query("SELECT stage FROM applicants WHERE id = $applicantId")->fetch_row()[0], 'onboarded');
Rgt::same('with the stage change in its history', $db->query(
    "SELECT to_stage FROM applicant_stage_history WHERE applicant_id = $applicantId ORDER BY id DESC LIMIT 1"
)->fetch_row()[0], 'onboarded');
$profile = $admin->get("/people.php?id=$personId");
Rgt::same('the profile reads active', $profile->get('services.0.status'), 'active');
Rgt::same('naming who approved', $profile->get('workers.0.approved_by_name'), 'Sonia Scoped');

Rgt::group('suspension — a decision with a reason, shown on the profile');

Rgt::status('suspending without a reason is refused',
    $admin->post("/workers.php?action=suspend&id=$workerId", []), 400);
Rgt::denied('a trainer cannot suspend', $trainer->post("/workers.php?action=suspend&id=$workerId", ['reason' => 'x']));
$careWorker = (int) $db->query("SELECT id FROM workers WHERE division = 'care' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch_row()[0];
Rgt::denied('a scoped manager cannot suspend another service\'s worker',
    $scoped->post("/workers.php?action=suspend&id=$careWorker", ['reason' => 'x']));

$res = $admin->post("/workers.php?action=suspend&id=$workerId", ['reason' => 'Complaint under investigation']);
Rgt::status('an admin suspends with a reason', $res, 200);
$profile = $admin->get("/people.php?id=$personId");
Rgt::same('the profile reads suspended', $profile->get('services.0.status'), 'suspended');
Rgt::same('with the reason', $profile->get('workers.0.suspension_reason'), 'Complaint under investigation');
Rgt::same('and the next action is to review it', $profile->get('next_action.key'), 'review_suspension');
$check = $admin->get("/compliance.php?action=eligibility&worker_id=$workerId&service=security");
Rgt::same('the gate refuses a suspended worker', $check->get('blocked_by'), 'employment');
Rgt::status('suspending twice is refused', $admin->post("/workers.php?action=suspend&id=$workerId", ['reason' => 'again']), 409);
Rgt::ok('the suspension is audited with its reason', (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = 'worker.suspend' AND entity_id = $workerId AND reason = 'Complaint under investigation'"
)->fetch_row()[0] === 1);

$res = $admin->post("/workers.php?action=reinstate&id=$workerId", ['reason' => 'Complaint not upheld']);
Rgt::status('lifting the suspension with a reason', $res, 200);
Rgt::same('returns an approved worker to active', $res->get('status'), 'active');
Rgt::same('and the profile says so', $admin->get("/people.php?id=$personId")->get('services.0.status'), 'active');
