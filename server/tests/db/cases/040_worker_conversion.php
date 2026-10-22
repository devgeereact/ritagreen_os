<?php
// Turning an applicant into a worker: once, completely, or not at all.
//
// ats.php?action=onboard allocates a payroll number, inserts a worker, links the
// person to the service, marks the application and seeds the checklist -- five
// writes, with no transaction around them and no lock on the row it checks.

/** @var RgtClient $client */
/** @var mysqli $db */
/** @var string $base */

$admin = $client->login('admin@local.invalid');

Rgt::group('worker conversion — the happy path');

$id = (int) $db->query(
    "SELECT id FROM applicants WHERE worker_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch_row()[0];
$personId = (int) $db->query("SELECT person_id FROM applicants WHERE id = $id")->fetch_row()[0];

$res = $admin->post('/ats.php?action=onboard&id=' . $id, []);
Rgt::status("applicant $id converts to a worker", $res, 201);

$workerId = (int) $res->get('worker_id');
Rgt::ok('a worker id comes back', $workerId > 0);
Rgt::same('the worker is the SAME person as the applicant',
    (int) $db->query("SELECT person_id FROM workers WHERE id = $workerId")->fetch_row()[0], $personId);
Rgt::same('the application now points at the worker',
    (int) $db->query("SELECT worker_id FROM applicants WHERE id = $id")->fetch_row()[0], $workerId);

$payroll = (string) $db->query("SELECT payroll_no FROM workers WHERE id = $workerId")->fetch_row()[0];
Rgt::ok('a payroll number was allocated', preg_match('/^RG-\d{6}$/', $payroll) === 1, $payroll);
Rgt::ok('the checklist was seeded',
    (int) $db->query("SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = $id")->fetch_row()[0] > 0);

Rgt::group('worker conversion — starting onboarding is not finishing it');

// The review's finding: the application was marked "onboarded" at the moment a
// pending worker and an UNFINISHED checklist were created. The stage a candidate
// is at and the state of the work are then in direct contradiction.
$stage = (string) $db->query("SELECT stage FROM applicants WHERE id = $id")->fetch_row()[0];
$outstanding = (int) $db->query(
    "SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = $id AND done = 0"
)->fetch_row()[0];
Rgt::ok("the checklist has outstanding tasks ($outstanding)", $outstanding > 0);
Rgt::same('so the stage says onboarding, not onboarded', $stage, 'onboarding');
Rgt::same('and the worker is pending, not active',
    (string) $db->query("SELECT status FROM workers WHERE id = $workerId")->fetch_row()[0], 'onboarding');

Rgt::group('worker conversion — a repeat is refused, not repeated');

$before = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
$again = $admin->post('/ats.php?action=onboard&id=' . $id, []);
Rgt::status('converting the same applicant twice is refused', $again, 409);
Rgt::same('and no second worker exists',
    (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0], $before);

Rgt::group('worker conversion — two requests at the same time make one worker');

// The check and the write were separate statements with nothing holding the row
// between them, so two requests could both read worker_id as NULL and both
// insert. curl_multi is how two requests are actually in flight at once; a
// sequential pair only proves the repeat case above.
$target = (int) $db->query(
    "SELECT id FROM applicants WHERE worker_id IS NULL AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch_row()[0];
$workersBefore = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];

$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 4; $i++) {
    $ch = curl_init($base . '/ats.php?action=onboard&id=' . $target);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $admin->token(),
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
$running = null;
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.1);
} while ($running > 0);

$created = 0;
$refused = 0;
$other = [];
foreach ($handles as $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $body = (string) curl_multi_getcontent($ch);
    if ($code === 201) $created++;
    elseif ($code === 409) $refused++;
    else $other[] = "$code $body";
    curl_multi_remove_handle($multi, $ch);
}
curl_multi_close($multi);

Rgt::same('exactly one of four concurrent requests creates the worker', $created, 1);
Rgt::same('the other three are refused as already converted', $refused, 3);
Rgt::same('and none fails some other way', $other, []);
Rgt::same('exactly one worker row was added',
    (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0], $workersBefore + 1);
// One checklist, tested as "no label appears twice" rather than as a count. A
// count would have to hard-code the size of ONBOARDING_CHECKLIST, and four
// seedings show up as four copies of every label -- which is the actual fault.
$repeated = (int) $db->query(
    "SELECT COUNT(*) FROM (
        SELECT label FROM onboarding_tasks WHERE applicant_id = $target
         GROUP BY label HAVING COUNT(*) > 1) d"
)->fetch_row()[0];
Rgt::same('the checklist was seeded once, so no task label is repeated', $repeated, 0);
Rgt::ok('and the checklist is not empty',
    (int) $db->query("SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = $target")->fetch_row()[0] > 0);

Rgt::group('worker conversion — payroll numbers are unique and never reissued');

$dupes = (int) $db->query(
    'SELECT COUNT(*) FROM (SELECT payroll_no FROM workers WHERE payroll_no IS NOT NULL
      GROUP BY payroll_no HAVING COUNT(*) > 1) d'
)->fetch_row()[0];
Rgt::same('no payroll number is held by two workers', $dupes, 0);

// Changing something about a worker must not reissue the number. It is the
// permanent identifier the company knows somebody by.
$was = (string) $db->query("SELECT payroll_no FROM workers WHERE id = $workerId")->fetch_row()[0];
$upd = $admin->post('/workers.php?action=availability&id=' . $workerId, [
    'avail_days' => true, 'avail_nights' => false, 'avail_weekends' => true,
    'travel_radius_mi' => 12, 'home_postcode' => 'SE1 7PB',
]);
Rgt::ok('a worker record can be changed', $upd->status < 400, "HTTP {$upd->status} {$upd->body}");
Rgt::same('and the payroll number is unchanged',
    (string) $db->query("SELECT payroll_no FROM workers WHERE id = $workerId")->fetch_row()[0], $was);

// Every worker ever created has one, and the sequence does not go backwards.
Rgt::same('every worker has a payroll number',
    (int) $db->query('SELECT COUNT(*) FROM workers WHERE payroll_no IS NULL')->fetch_row()[0], 0);

Rgt::group('worker conversion — service scope on the worker record too');

$scoped = $client->login('scoped@local.invalid');      // manager, security
$careWorker = (int) $db->query(
    "SELECT id FROM workers WHERE division = 'care' AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetch_row()[0];

$radiusWas = (int) $db->query("SELECT travel_radius_mi FROM workers WHERE id = $careWorker")->fetch_row()[0];
$res = $scoped->post('/workers.php?action=availability&id=' . $careWorker, [
    'avail_days' => false, 'avail_nights' => false, 'avail_weekends' => false,
    'travel_radius_mi' => 99, 'home_postcode' => 'XX1 1XX',
]);
Rgt::denied("a security manager cannot change care worker $careWorker's availability", $res);
Rgt::same('and the record is untouched',
    (int) $db->query("SELECT travel_radius_mi FROM workers WHERE id = $careWorker")->fetch_row()[0], $radiusWas);

$rateWas = (int) $db->query("SELECT pay_rate_pence FROM workers WHERE id = $careWorker")->fetch_row()[0];
$res = $scoped->post('/workers.php?action=pay&id=' . $careWorker, [
    'pay_rate_pence' => 1, 'pay_type' => 'paye',
]);
Rgt::denied("a security manager cannot set care worker $careWorker's pay", $res);
Rgt::same('and the rate is unchanged',
    (int) $db->query("SELECT pay_rate_pence FROM workers WHERE id = $careWorker")->fetch_row()[0], $rateWas);
Rgt::same('and no rate history row was written',
    (int) $db->query("SELECT COUNT(*) FROM worker_pay_rates WHERE worker_id = $careWorker AND rate_pence = 1")->fetch_row()[0], 0);

$countWas = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
$res = $scoped->post('/workers.php', [
    'name' => 'Wrong Service Worker', 'email' => 'wrong@local.invalid',
    'role' => 'carer', 'division' => 'care',
]);
Rgt::denied('a security manager cannot create a care worker', $res);
Rgt::same('and no worker was created',
    (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0], $countWas);

// The same account inside its own service still works. A scope that refuses
// everything is not a scope, it is an outage.
$res = $scoped->post('/workers.php', [
    'name' => 'Right Service Worker', 'email' => 'right@local.invalid',
    'role' => 'guard', 'division' => 'security',
]);
Rgt::status('but can create a security worker', $res, 201);
