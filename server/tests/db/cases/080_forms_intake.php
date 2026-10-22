<?php
// Google Forms intake: authenticated, replay-safe, and recoverable.
//
// The CMS owns the record and Forms is an integration, which is what makes it
// replaceable. That only holds if a submission reliably becomes a candidate
// exactly once, and a submission that fails to map stays visible.

/** @var RgtClient $client */
/** @var mysqli $db */
/** @var string $base */

$admin = $client->login('admin@local.invalid');

// Set a secret on one integration. It is stored as a hash, so it is set here the
// way Settings would: by writing the hash.
$secret = 'test-shared-secret-not-a-real-one';
$hash = hash('sha256', $secret);
$db->query("UPDATE integrations SET secret_hash = '$hash', active = 1 WHERE integration_key = 'careers_care'");
$integrationId = (int) $db->query("SELECT id FROM integrations WHERE integration_key = 'careers_care'")->fetch_row()[0];

$post = static function (array $payload, ?string $withSecret = null, string $key = 'careers_care')
    use ($client, $secret): RgtResponse {
    $c = $client->withHeader('X-RitaGreen-Secret', $withSecret ?? $secret);
    return $c->post('/forms-intake.php?key=' . $key, $payload);
};

$answers = [
    'Full name' => 'Form Candidate',
    'Email address' => 'form.candidate@example.test',
    'Phone number' => '07700900841',
    'Which service are you applying for?' => 'care',
    'Position applied for' => 'Care assistant',
];

Rgt::group('intake — the secret is the authentication');

$db->query('DELETE FROM rate_limits');
Rgt::status('no secret is refused',
    $client->post('/forms-intake.php?key=careers_care', ['response_id' => 'x1', 'answers' => $answers]), 401);
Rgt::status('a wrong secret is refused', $post(['response_id' => 'x2', 'answers' => $answers], 'wrong'), 401);
Rgt::status('an unknown integration key is refused',
    $post(['response_id' => 'x3', 'answers' => $answers], null, 'no_such_form'), 404);

// A refused submission is still recorded against the integration, because
// repeated failures here are worth seeing.
$reason = $db->query("SELECT last_failure_reason FROM integrations WHERE id = $integrationId")->fetch_row()[0];
Rgt::same('and the refusal is recorded on the integration', $reason, 'Bad or missing shared secret');
Rgt::same('but no submission row was created for it',
    (int) $db->query("SELECT COUNT(*) FROM integration_submissions WHERE integration_id = $integrationId")->fetch_row()[0], 0);

Rgt::group('intake — a submission becomes a candidate');

$res = $post(['response_id' => 'resp-0001', 'answers' => $answers]);
Rgt::status('a good submission is accepted', $res, 201);
$applicantId = (int) $res->get('applicant_id');
Rgt::ok('and names the candidate it created', $applicantId > 0, $res->body);

$app = $db->query("SELECT name, email, division, source, stage FROM applicants WHERE id = $applicantId")->fetch_assoc();
Rgt::same('with the mapped name', $app['name'], 'Form Candidate');
Rgt::same('the mapped address', $app['email'], 'form.candidate@example.test');
Rgt::same('the mapped service', $app['division'], 'care');
Rgt::same('recorded as coming from the form', $app['source'], 'google_form');
Rgt::same('and entering at applied', $app['stage'], 'applied');

Rgt::same('the candidate has a stage history like any other',
    (int) $db->query("SELECT COUNT(*) FROM applicant_stage_history WHERE applicant_id = $applicantId")->fetch_row()[0], 1);

Rgt::group('intake — a redelivery does not duplicate');

$again = $post(['response_id' => 'resp-0001', 'answers' => $answers]);
Rgt::status('the same response id is accepted again', $again, 200);
Rgt::same('and reports it as a duplicate', $again->get('duplicate'), true);
Rgt::same('pointing at the candidate already created', (int) $again->get('applicant_id'), $applicantId);
Rgt::same('one submission row, not two',
    (int) $db->query("SELECT COUNT(*) FROM integration_submissions WHERE integration_id = $integrationId AND external_id = 'resp-0001'")->fetch_row()[0], 1);
Rgt::same('and one candidate',
    (int) $db->query("SELECT COUNT(*) FROM applicants WHERE email = 'form.candidate@example.test'")->fetch_row()[0], 1);

Rgt::group('intake — four simultaneous deliveries of one response');

// What a retrying Apps Script actually does. uniq_external is what makes this
// safe; handling its refusal is what stops Google seeing a 500 and retrying yet
// again.
$raceAnswers = $answers;
$raceAnswers['Full name'] = 'Race Form Candidate';
$raceAnswers['Email address'] = 'race.form@example.test';
$db->query('DELETE FROM rate_limits');

$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 4; $i++) {
    $ch = curl_init($base . '/forms-intake.php?key=careers_care');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['response_id' => 'resp-race', 'answers' => $raceAnswers]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-RitaGreen-Secret: ' . $secret],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
$running = null;
do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.1); } while ($running > 0);
$serverErrors = [];
foreach ($handles as $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code >= 500) $serverErrors[] = $code . ' ' . substr((string) curl_multi_getcontent($ch), 0, 160);
    curl_multi_remove_handle($multi, $ch);
}
curl_multi_close($multi);

Rgt::same('none of them fails with a server error', $serverErrors, []);
Rgt::same('one submission row', (int) $db->query(
    "SELECT COUNT(*) FROM integration_submissions WHERE external_id = 'resp-race'")->fetch_row()[0], 1);
Rgt::same('and one candidate', (int) $db->query(
    "SELECT COUNT(*) FROM applicants WHERE email = 'race.form@example.test'")->fetch_row()[0], 1);

Rgt::group('intake — a submission that cannot be mapped stays visible');

$db->query('DELETE FROM rate_limits');
$broken = $post(['response_id' => 'resp-bad', 'answers' => [
    'Full name' => 'No Address Person',
    'Email address' => 'not-an-email-at-all',
]]);
Rgt::status('a submission that cannot be mapped is reported as such', $broken, 422);
$subId = (int) $broken->get('submission_id');
Rgt::ok('and is still given an id', $subId > 0, $broken->body);

$row = $db->query("SELECT status, failure_reason, applicant_id, raw_payload FROM integration_submissions WHERE id = $subId")->fetch_assoc();
Rgt::same('recorded as failed', $row['status'], 'failed');
Rgt::ok('with a reason a person can act on', str_contains((string) $row['failure_reason'], 'not valid'), (string) $row['failure_reason']);
Rgt::same('no candidate was created', $row['applicant_id'], null);
// The raw payload is what makes the person recoverable once the mapping is
// fixed. A submission that fails to map is somebody who applied.
Rgt::ok('and the payload it arrived with is kept',
    str_contains((string) $row['raw_payload'], 'No Address Person'), (string) $row['raw_payload']);

Rgt::group('intake — staff can see and recover failures');

$health = $admin->get('/forms-intake.php?action=health');
Rgt::status('staff can read integration health', $health, 200);
$careRow = null;
foreach ($health->get('integrations', []) as $row) if (($row['integration_key'] ?? '') === 'careers_care') $careRow = $row;
Rgt::ok('the configured integration reports as configured', !empty($careRow['configured']));
Rgt::ok('and its submission total has moved', (int) ($careRow['submissions_total'] ?? 0) >= 1);

$subs = $admin->get('/forms-intake.php?action=submissions');
Rgt::status('staff can list recent submissions', $subs, 200);
$ids = array_column($subs->get('submissions', []), 'id');
Rgt::ok('including the one that failed', in_array($subId, array_map('intval', $ids), true));

Rgt::denied('a client login cannot read integration health',
    $client->login('priya@local.invalid')->get('/forms-intake.php?action=health'));
Rgt::denied('nor the submissions',
    $client->login('amara.login@local.invalid')->get('/forms-intake.php?action=submissions'));

Rgt::group('intake — retrying a failed submission recovers the person');

// Fix the mapping problem the way the office would: correct the stored payload,
// then retry. The point is that the person is not lost.
$fixed = json_encode(['Full name' => 'No Address Person', 'Email address' => 'recovered@example.test']);
$stmt = $db->prepare('UPDATE integration_submissions SET raw_payload = ? WHERE id = ?');
$stmt->bind_param('si', $fixed, $subId);
$stmt->execute();

$retry = $admin->post("/forms-intake.php?action=retry&id=$subId", []);
Rgt::status('a failed submission can be retried', $retry, 200);
Rgt::same('and now maps', $retry->get('ok'), true);
$recovered = (int) $retry->get('applicant_id');
Rgt::ok('creating the candidate it should have created', $recovered > 0, $retry->body);
Rgt::same('the submission is now marked mapped',
    (string) $db->query("SELECT status FROM integration_submissions WHERE id = $subId")->fetch_row()[0], 'mapped');

Rgt::group('intake — retrying a submission that already worked does not duplicate');

$applicantsBefore = (int) $db->query('SELECT COUNT(*) FROM applicants')->fetch_row()[0];
$twice = $admin->post("/forms-intake.php?action=retry&id=$subId", []);
Rgt::status('retrying a mapped submission is accepted', $twice, 200);
Rgt::same('and says it was already mapped', $twice->get('already_mapped'), true);
Rgt::same('pointing at the same candidate', (int) $twice->get('applicant_id'), $recovered);
Rgt::same('no second candidate was created',
    (int) $db->query('SELECT COUNT(*) FROM applicants')->fetch_row()[0], $applicantsBefore);

Rgt::denied('a worker login cannot retry a submission',
    $client->login('amara.login@local.invalid')->post("/forms-intake.php?action=retry&id=$subId", []));

Rgt::group('intake — a paused integration accepts nothing');

$db->query("UPDATE integrations SET active = 0 WHERE id = $integrationId");
$db->query('DELETE FROM rate_limits');
Rgt::status('a paused integration refuses submissions',
    $post(['response_id' => 'resp-paused', 'answers' => $answers]), 409);
$db->query("UPDATE integrations SET active = 1 WHERE id = $integrationId");
