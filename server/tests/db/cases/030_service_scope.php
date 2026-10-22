<?php
// Service scope, on every branch rather than only on the list.
//
// service_scope() exists and is opt-in per account through users.scope_to_division
// (migration 015). The seeded account for it is scoped@local.invalid, a manager
// for security.
//
// The list query applied it. The detail read and every mutation did not, so a
// manager for one service could read and change another service's candidates by
// asking for them by id -- which is the only thing an id is for.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin  = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');   // manager, security, scoped

// Two applicants: one inside this account's service, one outside it.
$mine   = (int) $db->query("SELECT id FROM applicants WHERE division = 'security' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch_row()[0];
$theirs = (int) $db->query("SELECT id FROM applicants WHERE division = 'care'     AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetch_row()[0];

Rgt::group('service scope — the list');

$list = $scoped->get('/ats.php');
Rgt::status('a scoped manager can list candidates', $list, 200);
$divisions = array_values(array_unique(array_column($list->get('applicants', []), 'division')));
Rgt::same('and sees only their own service', $divisions, ['security']);

$all = $admin->get('/ats.php');
Rgt::ok('an unscoped admin sees more than one service',
    count(array_unique(array_column($all->get('applicants', []), 'division'))) > 1);

Rgt::group('service scope — reading one record by id');

$ok = $scoped->get('/ats.php?id=' . $mine);
Rgt::status("a scoped manager can read applicant $mine, in their own service", $ok, 200);

// The substitution. Same endpoint, same role, an id belonging to another service.
$cross = $scoped->get('/ats.php?id=' . $theirs);
Rgt::denied("a scoped manager cannot read applicant $theirs, in another service", $cross);
Rgt::ok('and the refusal carries no applicant record',
    !str_contains($cross->body, '"person_id"') && !str_contains($cross->body, '"role_applied"'),
    $cross->body);

Rgt::group('service scope — changing another service\'s record');

$was = $db->query("SELECT stage FROM applicants WHERE id = $theirs")->fetch_row()[0];

$res = $scoped->post('/ats.php?action=stage&id=' . $theirs, ['stage' => 'rejected']);
Rgt::denied("a scoped manager cannot change the stage of applicant $theirs", $res);
$now = $db->query("SELECT stage FROM applicants WHERE id = $theirs")->fetch_row()[0];
Rgt::same('and the stage is unchanged', $now, $was);

$tasksBefore = (int) $db->query("SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = $theirs")->fetch_row()[0];
$res = $scoped->post('/ats.php?action=add_task&id=' . $theirs, ['label' => 'Should never be written']);
Rgt::denied("a scoped manager cannot add a task to applicant $theirs", $res);
$tasksAfter = (int) $db->query("SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = $theirs")->fetch_row()[0];
Rgt::same('and no task was written', $tasksAfter, $tasksBefore);

$workersBefore = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
$res = $scoped->post('/ats.php?action=onboard&id=' . $theirs, []);
Rgt::denied("a scoped manager cannot start onboarding for applicant $theirs", $res);
$workersAfter = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
Rgt::same('and no worker was created', $workersAfter, $workersBefore);

Rgt::group('service scope — a task belongs to its applicant\'s service');

// task_done takes a task id, not an applicant id, so the scope has to be reached
// through the join. An unscoped id is exactly how a scope gets bypassed.
$admin->post('/ats.php?action=add_task&id=' . $theirs, ['label' => 'Scope probe task']);
$taskId = (int) $db->query(
    "SELECT id FROM onboarding_tasks WHERE applicant_id = $theirs ORDER BY id DESC LIMIT 1"
)->fetch_row()[0];

$res = $scoped->post('/ats.php?action=task_done&task_id=' . $taskId, ['done' => 1]);
Rgt::denied("a scoped manager cannot complete task $taskId on another service's applicant", $res);
$done = (int) $db->query("SELECT done FROM onboarding_tasks WHERE id = $taskId")->fetch_row()[0];
Rgt::same('and the task is still outstanding', $done, 0);
