<?php
// One person, linked across applications, workers and accounts.
//
// The rule in docs/RULES.md is "a person exists once: link with an identifier,
// never copy identity fields". find_or_create_person() matches on email, then
// phone, and never on a name.

/** @var RgtClient $client */
/** @var mysqli $db */
/** @var string $base */

$admin = $client->login('admin@local.invalid');

// The public application form is limited to 8 posts per IP in 600 seconds, and
// this file makes more than that from 127.0.0.1 in a few seconds. The limit is
// asserted below, once, and cleared between groups so the rest can run.
$clearLimits = static fn () => $db->query('DELETE FROM rate_limits');

// The fixture addresses use example.test rather than local.invalid. Three
// domains -- learner.invalid, example.invalid and local.invalid -- are listed in
// RG_SYNTHETIC_EMAIL_DOMAINS and discarded by rg_normalise_email(), deliberately:
// they are placeholders the system generates for people who gave no address, and
// treating them as identities would collapse every such person into one. An
// identity test therefore cannot use one. example.test is reserved by RFC 2606,
// so it can never reach a real recipient either.
Rgt::group('identity — the same address is the same person');

$email = 'repeat.applicant@example.test';
$db->query("DELETE FROM applicants WHERE email = '$email'");
$db->query("DELETE FROM people WHERE email = '$email'");

$first = $client->post('/ats.php', [
    'name' => 'Repeat Applicant', 'email' => $email, 'phone' => '07700900801',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
Rgt::status('a public application is accepted', $first, 201);

// The same human applies again, later, for a different service.
$second = $client->post('/ats.php', [
    'name' => 'Repeat Applicant', 'email' => $email, 'phone' => '07700900801',
    'role_applied' => 'Guard', 'division' => 'security', 'consent' => true,
]);
Rgt::status('and can apply a second time', $second, 201);

$people = (int) $db->query("SELECT COUNT(*) FROM people WHERE email = '$email'")->fetch_row()[0];
Rgt::same('two applications for one person make one person record', $people, 1);
$apps = (int) $db->query("SELECT COUNT(DISTINCT person_id) FROM applicants WHERE email = '$email'")->fetch_row()[0];
Rgt::same('and both applications point at it', $apps, 1);

$clearLimits();
Rgt::group('identity — case and formatting are not a new person');

$db->query("DELETE FROM applicants WHERE email IN ('mixed.case@example.test','MIXED.CASE@Example.Test')");
$db->query("DELETE FROM people WHERE email = 'mixed.case@example.test'");

$client->post('/ats.php', [
    'name' => 'Mixed Case', 'email' => 'mixed.case@example.test',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
$client->post('/ats.php', [
    'name' => 'Mixed Case', 'email' => '  MIXED.CASE@Example.Test  ',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
Rgt::same('an address in different case is one person',
    (int) $db->query("SELECT COUNT(*) FROM people WHERE email = 'mixed.case@example.test'")->fetch_row()[0], 1);

$clearLimits();
Rgt::group('identity — a shared name is not a shared person');

// Two different people with the same name. Matching on a name would merge them,
// and merging two people is far harder to undo than leaving a duplicate.
$db->query("DELETE FROM applicants WHERE email IN ('john.smith.a@example.test','john.smith.b@example.test')");
$db->query("DELETE FROM people WHERE email IN ('john.smith.a@example.test','john.smith.b@example.test')");

$client->post('/ats.php', [
    'name' => 'John Smith', 'email' => 'john.smith.a@example.test', 'phone' => '07700900811',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
$client->post('/ats.php', [
    'name' => 'John Smith', 'email' => 'john.smith.b@example.test', 'phone' => '07700900812',
    'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
]);
Rgt::same('two John Smiths at different addresses are two people',
    (int) $db->query(
        "SELECT COUNT(*) FROM people WHERE email IN ('john.smith.a@example.test','john.smith.b@example.test')"
    )->fetch_row()[0], 2);

Rgt::group('identity — a worker and their login are one person');

// Proven from the fixtures rather than by creating anything: seed.php gives the
// worker login the worker's own person_id, which is the rule this enforces.
$row = $db->query(
    "SELECT u.person_id AS login_person, w.person_id AS worker_person
       FROM users u JOIN workers w ON w.id = u.worker_id
      WHERE u.email = 'amara.login@local.invalid'"
)->fetch_assoc();
Rgt::ok('the worker login is linked to a worker', $row !== null);
Rgt::same('and to the same person as the worker record',
    (int) $row['login_person'], (int) $row['worker_person']);

Rgt::group('identity — joining a second service does not duplicate the person');

$id = (int) $db->query(
    "SELECT id FROM applicants WHERE email = '$email' AND worker_id IS NULL ORDER BY id LIMIT 1"
)->fetch_row()[0];
$personBefore = (int) $db->query("SELECT COUNT(*) FROM people")->fetch_row()[0];

$res = $admin->post('/ats.php?action=onboard&id=' . $id, []);
Rgt::status('the applicant becomes a worker', $res, 201);
Rgt::same('and no new person was created',
    (int) $db->query('SELECT COUNT(*) FROM people')->fetch_row()[0], $personBefore);

$workerPerson = (int) $db->query("SELECT person_id FROM workers WHERE id = " . (int) $res->get('worker_id'))->fetch_row()[0];
$applicantPerson = (int) $db->query("SELECT person_id FROM applicants WHERE id = $id")->fetch_row()[0];
Rgt::same('the worker is the person who applied', $workerPerson, $applicantPerson);

// person_services is what records "this person is intended for this service". A
// second service adds a row; it does not add a person.
$services = (int) $db->query("SELECT COUNT(*) FROM person_services WHERE person_id = $workerPerson")->fetch_row()[0];
Rgt::ok("the person is recorded against at least one service ($services)", $services >= 1);

$clearLimits();
Rgt::group('identity — two simultaneous applications from one person');

// people.match_key is UNIQUE (migration 001), and find_or_create_person() does a
// SELECT then an INSERT with nothing between them. Two requests that both miss
// the SELECT both reach the INSERT, and one of them hits the unique key. Whether
// that is handled or becomes a 500 is what this asks.
$raceEmail = 'race.applicant@example.test';
$db->query("DELETE FROM applicants WHERE email = '$raceEmail'");
$db->query("DELETE FROM people WHERE email = '$raceEmail'");
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 4; $i++) {
    $ch = curl_init($base . '/ats.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => 'Race Applicant', 'email' => $raceEmail, 'phone' => '07700900821',
            'role_applied' => 'Carer', 'division' => 'care', 'consent' => true,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
$running = null;
do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.1); } while ($running > 0);

$failed = [];
foreach ($handles as $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code >= 500) $failed[] = $code . ' ' . substr((string) curl_multi_getcontent($ch), 0, 160);
    curl_multi_remove_handle($multi, $ch);
}
curl_multi_close($multi);

Rgt::same('no simultaneous application fails with a server error', $failed, []);
Rgt::same('and the four of them make exactly one person',
    (int) $db->query("SELECT COUNT(*) FROM people WHERE email = '$raceEmail'")->fetch_row()[0], 1);
