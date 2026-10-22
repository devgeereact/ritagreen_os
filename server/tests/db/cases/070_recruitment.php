<?php
// Recruitment: a stage that cannot be skipped, a named recruiter, interviews,
// tasks that can be chased, and a history that says what happened.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin = $client->login('admin@local.invalid');
$managerId = (int) $db->query("SELECT id FROM users WHERE email = 'manager@local.invalid'")->fetch_row()[0];
$workerLoginId = (int) $db->query("SELECT id FROM users WHERE email = 'amara.login@local.invalid'")->fetch_row()[0];
$clientLoginId = (int) $db->query("SELECT id FROM users WHERE email = 'priya@local.invalid'")->fetch_row()[0];

// This case creates its own candidate rather than taking whichever applicant
// happens to be first. It walks one through every stage, and the scope group at
// the end needs it to be in a service the security-scoped manager is NOT in --
// which "the first applicant at stage applied" stops being as soon as an earlier
// case converts one.
$db->query('DELETE FROM rate_limits');
$created = $client->post('/ats.php', [
    'name' => 'Pipeline Candidate', 'email' => 'pipeline.candidate@example.test',
    'phone' => '07700900861', 'role_applied' => 'Care assistant',
    'division' => 'care', 'consent' => true,
]);
Rgt::status('a candidate is created for this case', $created, 201);
$id = (int) $db->query(
    "SELECT id FROM applicants WHERE email = 'pipeline.candidate@example.test' ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];

Rgt::group('recruitment — a stage cannot be skipped');

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'offer']);
Rgt::status('applied straight to offer is refused', $res, 409);
Rgt::ok('and says which stage comes next', str_contains($res->error(), 'next stage is screened'), $res->error());
Rgt::same('the candidate has not moved',
    (string) $db->query("SELECT stage FROM applicants WHERE id = $id")->fetch_row()[0], 'applied');

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'onboarded']);
Rgt::status('and cannot be set to onboarded by hand', $res, 409);
Rgt::ok('with the action that does set it named',
    str_contains($res->error(), 'Approving activation'), $res->error());

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'onboarding']);
Rgt::status('nor to onboarding', $res, 409);

Rgt::group('recruitment — forward, one stage at a time');

foreach (['screened', 'interview', 'vetting', 'offer'] as $stage) {
    $res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => $stage]);
    Rgt::status("moves to $stage", $res, 200);
}
Rgt::same('and is at offer', (string) $db->query("SELECT stage FROM applicants WHERE id = $id")->fetch_row()[0], 'offer');

Rgt::group('recruitment — going back needs a reason');

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'interview']);
Rgt::status('moving back without a reason is refused', $res, 409);
Rgt::ok('and says so', str_contains($res->error(), 'reason is required'), $res->error());

$res = $admin->post("/ats.php?action=stage&id=$id", [
    'stage' => 'interview', 'reason' => 'Second interview requested by the client',
]);
Rgt::status('moving back with a reason is allowed', $res, 200);
Rgt::same('and lands there',
    (string) $db->query("SELECT stage FROM applicants WHERE id = $id")->fetch_row()[0], 'interview');

Rgt::group('recruitment — leaving the pipeline records why');

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'rejected']);
Rgt::status('a rejection without a reason is refused', $res, 409);

$res = $admin->post("/ats.php?action=stage&id=$id", [
    'stage' => 'rejected', 'reason' => 'No enhanced DBS and unwilling to apply',
]);
Rgt::status('a rejection with a reason is accepted', $res, 200);
Rgt::same('and the reason is on the record',
    (string) $db->query("SELECT outcome_reason FROM applicants WHERE id = $id")->fetch_row()[0],
    'No enhanced DBS and unwilling to apply');

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'withdrawn', 'reason' => 'Changed my mind']);
Rgt::status('a candidate cannot leave twice', $res, 409);

$res = $admin->post("/ats.php?action=onboard&id=$id", []);
Rgt::status('and a rejected candidate cannot be onboarded', $res, 409);
Rgt::ok('with the reason given', str_contains($res->error(), 'left the pipeline'), $res->error());

$res = $admin->post("/ats.php?action=stage&id=$id", ['stage' => 'offer', 'reason' => 'DBS now in hand']);
Rgt::status('bringing them back in is allowed with a reason', $res, 200);
Rgt::same('and clears the outcome, because they did not leave',
    $db->query("SELECT outcome_reason FROM applicants WHERE id = $id")->fetch_row()[0], null);

Rgt::group('recruitment — withdrawal is not rejection');

$db->query('DELETE FROM rate_limits');
$client->post('/ats.php', [
    'name' => 'Withdrawing Candidate', 'email' => 'withdrawing.candidate@example.test',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
$other = (int) $db->query(
    "SELECT id FROM applicants WHERE email = 'withdrawing.candidate@example.test' ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];
$res = $admin->post("/ats.php?action=stage&id=$other", [
    'stage' => 'withdrawn', 'reason' => 'Accepted a role elsewhere',
]);
Rgt::status('a candidate can withdraw', $res, 200);
Rgt::same('and it is recorded as withdrawn, not rejected',
    (string) $db->query("SELECT stage FROM applicants WHERE id = $other")->fetch_row()[0], 'withdrawn');

Rgt::group('recruitment — the history says what happened');

$detail = $admin->get("/ats.php?id=$id");
Rgt::status('the candidate detail loads', $detail, 200);
$history = $detail->get('history', []);
Rgt::ok('the history has the moves in it', count($history) >= 6, (string) count($history));

$latest = $history[0] ?? [];
Rgt::same('newest first', $latest['to_stage'] ?? null, 'offer');
Rgt::same('with the stage it came from', $latest['from_stage'] ?? null, 'rejected');
Rgt::same('the reason', $latest['reason'] ?? null, 'DBS now in hand');
Rgt::same('and who did it', $latest['changed_by_name'] ?? null, 'Olu Admin');

$rejection = null;
foreach ($history as $row) if (($row['to_stage'] ?? '') === 'rejected') { $rejection = $row; break; }
Rgt::ok('the rejection is still in the history after they came back', $rejection !== null);
Rgt::same('with its own reason', $rejection['reason'] ?? null, 'No enhanced DBS and unwilling to apply');

Rgt::group('recruitment — the screen is told what it may offer');

$next = array_column($detail->get('next_stages', []), 'stage');
Rgt::ok('onboarding is never offered', !in_array('onboarding', $next, true), implode(',', $next));
Rgt::ok('onboarded is never offered', !in_array('onboarded', $next, true), implode(',', $next));
Rgt::ok('the next stage forward is offered', in_array('onboarding', $next, true) === false);
Rgt::ok('rejected is offered', in_array('rejected', $next, true), implode(',', $next));
$needsReason = [];
foreach ($detail->get('next_stages', []) as $row) if (!empty($row['needs_reason'])) $needsReason[] = $row['stage'];
Rgt::ok('and leaving the pipeline is flagged as needing a reason',
    in_array('rejected', $needsReason, true), implode(',', $needsReason));

Rgt::group('recruitment — a named recruiter');

$res = $admin->post("/ats.php?action=recruiter&id=$id", ['recruiter_id' => $managerId]);
Rgt::status('a candidate can be assigned to a recruiter', $res, 200);
Rgt::same('and the record says who',
    (int) $db->query("SELECT recruiter_id FROM applicants WHERE id = $id")->fetch_row()[0], $managerId);
Rgt::same('the detail carries the name', $admin->get("/ats.php?id=$id")->get('applicant.recruiter_name'), 'Mara Manager');

// A worker's login and a client portal login are ordinary user rows. Neither may
// be handed a candidate: they cannot open the record.
$res = $admin->post("/ats.php?action=recruiter&id=$id", ['recruiter_id' => $workerLoginId]);
Rgt::status('a worker login cannot be the recruiter', $res, 422);
$res = $admin->post("/ats.php?action=recruiter&id=$id", ['recruiter_id' => $clientLoginId]);
Rgt::status('nor can a client login', $res, 422);
Rgt::same('and the recruiter is unchanged',
    (int) $db->query("SELECT recruiter_id FROM applicants WHERE id = $id")->fetch_row()[0], $managerId);

$res = $admin->post("/ats.php?action=recruiter&id=$id", ['recruiter_id' => null]);
Rgt::status('the recruiter can be cleared', $res, 200);
Rgt::same('and is',
    $db->query("SELECT recruiter_id FROM applicants WHERE id = $id")->fetch_row()[0], null);

Rgt::group('recruitment — interviews');

$res = $admin->post("/ats.php?action=interview&id=$id", [
    'scheduled_at' => '2026-10-01 10:30',
    'interviewer_id' => $managerId,
    'location' => 'Head office',
    'notes' => 'Bring right-to-work documents',
]);
Rgt::status('an interview can be booked', $res, 201);
$interviewId = (int) $res->get('id');

$bad = $admin->post("/ats.php?action=interview&id=$id", ['scheduled_at' => 'next Tuesday']);
Rgt::status('a date that is not a date is refused', $bad, 400);
$bad = $admin->post("/ats.php?action=interview&id=$id", [
    'scheduled_at' => '2026-10-02 09:00', 'interviewer_id' => $clientLoginId,
]);
Rgt::status('a client login cannot be the interviewer', $bad, 422);

$detail = $admin->get("/ats.php?id=$id");
$interview = ($detail->get('interviews', [])[0] ?? []);
Rgt::same('the interview is on the candidate', (int) ($interview['id'] ?? 0), $interviewId);
Rgt::same('with the interviewer named', $interview['interviewer_name'] ?? null, 'Mara Manager');
Rgt::same('and starts as scheduled', $interview['outcome'] ?? null, 'scheduled');
// ?? would report a null value as absent, which is the opposite of the point.
Rgt::ok('with no decision recorded',
    array_key_exists('decided_at', $interview) && $interview['decided_at'] === null);

$res = $admin->post("/ats.php?action=interview_outcome&interview_id=$interviewId", [
    'outcome' => 'passed', 'notes' => 'Strong on safeguarding',
]);
Rgt::status('an outcome can be recorded', $res, 200);
$row = $db->query("SELECT outcome, notes, decided_at, decided_by FROM applicant_interviews WHERE id = $interviewId")->fetch_assoc();
Rgt::same('the outcome is stored', $row['outcome'], 'passed');
Rgt::same('the notes are stored', $row['notes'], 'Strong on safeguarding');
Rgt::ok('and who decided it, and when', $row['decided_at'] !== null && $row['decided_by'] !== null);

// An interview outcome is evidence. It must not move the candidate by itself:
// a passed interview is not the only thing that has to be true before vetting.
Rgt::same('recording an outcome does not move the candidate',
    (string) $db->query("SELECT stage FROM applicants WHERE id = $id")->fetch_row()[0], 'offer');

$bad = $admin->post("/ats.php?action=interview_outcome&interview_id=$interviewId", ['outcome' => 'brilliant']);
Rgt::status('an outcome that is not an outcome is refused', $bad, 400);

Rgt::group('recruitment — tasks with an owner and a date');

$res = $admin->post("/ats.php?action=add_task&id=$id", [
    'label' => 'Chase second reference', 'owner_id' => $managerId, 'due_on' => '2026-10-05',
]);
Rgt::status('a task can be created with an owner and a due date', $res, 201);
$taskId = (int) $res->get('id');

$bad = $admin->post("/ats.php?action=add_task&id=$id", ['label' => 'Bad owner', 'owner_id' => $clientLoginId]);
Rgt::status('a client login cannot own a task', $bad, 422);
$bad = $admin->post("/ats.php?action=add_task&id=$id", ['label' => 'Bad date', 'due_on' => '05/10/2026']);
Rgt::status('a due date that is not a date is refused', $bad, 400);

$detail = $admin->get("/ats.php?id=$id");
$task = null;
foreach ($detail->get('tasks', []) as $t) if ((int) $t['id'] === $taskId) $task = $t;
Rgt::same('the task carries its owner', $task['owner_name'] ?? null, 'Mara Manager');
Rgt::same('and its due date', $task['due_on'] ?? null, '2026-10-05');

$res = $admin->post("/ats.php?action=task_done&task_id=$taskId", ['done' => true]);
Rgt::status('a task can be completed', $res, 200);
$row = $db->query("SELECT done, done_at, done_by FROM onboarding_tasks WHERE id = $taskId")->fetch_assoc();
Rgt::same('it is done', (int) $row['done'], 1);
Rgt::ok('and it records who ticked it and when', $row['done_at'] !== null && $row['done_by'] !== null);

// "Right to work verified" with nobody's name against it is not evidence that
// anybody verified anything, so un-ticking must clear the completion too.
$admin->post("/ats.php?action=task_done&task_id=$taskId", ['done' => false]);
$row = $db->query("SELECT done, done_at, done_by FROM onboarding_tasks WHERE id = $taskId")->fetch_assoc();
Rgt::same('un-ticking clears the completion', [$row['done_at'], $row['done_by']], [null, null]);

$res = $admin->post("/ats.php?action=task_assign&task_id=$taskId", ['owner_id' => null, 'due_on' => null]);
Rgt::status('an owner and date can be removed', $res, 200);
$row = $db->query("SELECT owner_id, due_on FROM onboarding_tasks WHERE id = $taskId")->fetch_assoc();
Rgt::same('and are', [$row['owner_id'], $row['due_on']], [null, null]);

Rgt::group('recruitment — scope applies to the new branches too');

$scoped = $client->login('scoped@local.invalid');      // manager, security
// The candidate this case created, which is in care -- so a manager scoped to
// security is genuinely outside it.
$careApplicant = $id;
Rgt::same('the candidate under test is in care',
    (string) $db->query("SELECT division FROM applicants WHERE id = $careApplicant")->fetch_row()[0], 'care');

Rgt::denied('a security manager cannot assign a care candidate a recruiter',
    $scoped->post("/ats.php?action=recruiter&id=$careApplicant", ['recruiter_id' => $managerId]));
Rgt::denied('nor book them an interview',
    $scoped->post("/ats.php?action=interview&id=$careApplicant", ['scheduled_at' => '2026-10-03 09:00']));
Rgt::denied('nor record an outcome on one',
    $scoped->post("/ats.php?action=interview_outcome&interview_id=$interviewId", ['outcome' => 'failed']));
Rgt::denied('nor reassign a task on one',
    $scoped->post("/ats.php?action=task_assign&task_id=$taskId", ['owner_id' => $managerId]));
Rgt::same('and the interview outcome is untouched',
    (string) $db->query("SELECT outcome FROM applicant_interviews WHERE id = $interviewId")->fetch_row()[0], 'passed');
