<?php
// The person profile: one read for everything on the page, behind every layer of
// access the records underneath it already have.
//
// people.php reads across workers, applications, verifications, right-to-work
// checks, contracts, pay, leave and the audit log. Each of those has its own
// rules about who may see it, and an aggregate is exactly where those rules get
// lost -- one endpoint that is careful about the person and careless about the
// fifth table it joins. These cases hold it to the same boundaries as the
// endpoints it reads from: the role, the service scope, and the zone.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');      // manager, security, scoped
$manager = $client->login('manager@local.invalid');    // manager, care, not scoped; no health zone
$trainer = $client->login('trainer@local.invalid');    // staff; compliance zone, no payroll or employment
$editor = $client->login('editor@local.invalid');      // staff; no compliance zone
$workerLogin = $client->login('amara.login@local.invalid');
$clientLogin = $client->login('priya@local.invalid');

$carePerson = (int) $db->query(
    "SELECT person_id FROM workers WHERE name = 'Amara Chen' AND deleted_at IS NULL LIMIT 1"
)->fetch_row()[0];
$securityPerson = (int) $db->query(
    "SELECT person_id FROM workers WHERE name = 'Dmitri Volkov' AND deleted_at IS NULL LIMIT 1"
)->fetch_row()[0];

Rgt::group('person profile — the aggregate read');

$res = $admin->get("/people.php?id=$carePerson");
Rgt::status('an admin can open a profile', $res, 200);
Rgt::same('identity is read from people', $res->get('person.full_name'), 'Amara Chen');
Rgt::ok('with a payroll number', str_starts_with((string) $res->get('payroll_no'), 'RG-'), (string) $res->get('payroll_no'));
$services = $res->get('services', []);
Rgt::same('one service, care', array_column($services, 'service'), ['care']);
Rgt::same('approved and active', $services[0]['status'] ?? null, 'active');
Rgt::ok('every tab has its section', count(array_intersect(
    ['checks', 'training', 'shifts', 'employment', 'emergency', 'activity'], array_keys($res->json ?? [])
)) === 6, $res->body);
$items = $res->get('checks.assessments.0.items', []);
Rgt::ok('each requirement carries a status chip',
    $items !== [] && count(array_filter($items, static fn($i) => in_array($i['chip'] ?? '', ['valid', 'due_soon', 'expired', 'awaiting_review', 'missing'], true))) === count($items),
    json_encode($items));
Rgt::ok('shifts are split into upcoming and past', is_array($res->get('shifts.upcoming')) && is_array($res->get('shifts.past')));
// Bola's seeded shift starts tomorrow, so it is upcoming whatever time this runs.
$bolaPerson = (int) $db->query("SELECT person_id FROM workers WHERE name = 'Bola Adeyemi' LIMIT 1")->fetch_row()[0];
Rgt::same('and the next one is named', $admin->get("/people.php?id=$bolaPerson")->get('next_shift.title'), 'Nurse cover');
Rgt::ok('an admin sees the activity trail', is_array($res->get('activity')) && $res->get('activity.restricted') === null);

Rgt::status('an unknown person is a 404', $admin->get('/people.php?id=999999'), 404);
Rgt::status('no id is refused', $admin->get('/people.php'), 400);

Rgt::group('person profile — who may open it at all');

Rgt::denied('a worker login cannot open a profile', $workerLogin->get("/people.php?id=$carePerson"));
Rgt::denied('not even their own, from the office endpoint', $workerLogin->get('/people.php?id=' . (int) $db->query(
    "SELECT w.person_id FROM users u JOIN workers w ON w.id = u.worker_id WHERE u.email = 'amara.login@local.invalid'"
)->fetch_row()[0]));
Rgt::denied('a client login cannot open a profile', $clientLogin->get("/people.php?id=$carePerson"));
// Staff, but a content role with no compliance zone. Matches /app/people in access.ts.
Rgt::denied('an editor cannot open a profile', $editor->get("/people.php?id=$carePerson"));
Rgt::denied('nobody signed in cannot open a profile', $client->get("/people.php?id=$carePerson"));

Rgt::group('person profile — service scope');

Rgt::status('a security manager can open a security worker', $scoped->get("/people.php?id=$securityPerson"), 200);
Rgt::denied('but not a care worker', $scoped->get("/people.php?id=$carePerson"));
// The same person-level endpoints the profile acts through.
Rgt::denied('nor their verifications', $scoped->get("/verifications.php?person_id=$carePerson"));
Rgt::denied('nor their right-to-work checks', $scoped->get("/right-to-work.php?person_id=$carePerson"));
Rgt::denied('nor their leave', $scoped->get("/leave.php?person_id=$carePerson"));
Rgt::denied('nor their contracts', $scoped->get("/employment-contracts.php?person_id=$carePerson"));
Rgt::denied('nor save their emergency contacts', $scoped->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'Somebody', 'emergency_contact_phone' => '07700 900999',
]));

// One human in two services. The security manager may open them, because they
// are a security worker; the care side of the same person is not theirs.
$db->query(
    "INSERT INTO applicants (person_id, name, email, phone, role_applied, division, stage, source)
     SELECT id, full_name, 'dmitri@local.invalid', phone, 'Care assistant', 'care', 'screened', 'test' FROM people WHERE id = $securityPerson"
);
$careApplication = (int) $db->insert_id;
Rgt::ok('the care application is on the same person', $careApplication > 0);

$both = $admin->get("/people.php?id=$securityPerson");
Rgt::same('an admin sees both services', array_column($both->get('services', []), 'service'), ['care', 'security']);
$mine = $scoped->get("/people.php?id=$securityPerson");
Rgt::status('the security manager can still open them', $mine, 200);
Rgt::same('and sees only the security service', array_column($mine->get('services', []), 'service'), ['security']);
Rgt::same('and no care application', array_column($mine->get('applications', []), 'division'), []);

$queue = $scoped->get('/verifications.php?action=queue');
Rgt::status('the verification queue answers a scoped manager', $queue, 200);
Rgt::same('with nobody outside security in it',
    count(array_filter($queue->get('items', []), static fn($i) => !in_array('security', $i['services'], true))), 0);
$adminQueue = $admin->get('/verifications.php?action=queue&service=care');
Rgt::ok('the queue filters by service', $adminQueue->get('items') !== null
    && count(array_filter($adminQueue->get('items', []), static fn($i) => !in_array('care', $i['services'], true))) === 0,
    $adminQueue->body);

Rgt::group('person profile — zones decide what each section shows');

$asManager = $manager->get("/people.php?id=$carePerson");
Rgt::status('a care manager can open a care worker', $asManager, 200);
Rgt::same('and holds the payroll zone, so sees pay history', $asManager->get('employment.pay_rates.restricted'), null);
Rgt::same('but not the health zone', $asManager->get('zones.health'), false);

$asTrainer = $trainer->get("/people.php?id=$carePerson");
Rgt::status('a trainer can open a profile', $asTrainer, 200);
Rgt::same('pay history is restricted, not sent', $asTrainer->get('employment.pay_rates'), ['restricted' => true]);
Rgt::same('emergency contacts are restricted', $asTrainer->get('emergency'), ['restricted' => true]);
Rgt::same('the activity trail is office only', $asTrainer->get('activity'), ['restricted' => true]);
Rgt::ok('checks are visible to the compliance zone', is_array($asTrainer->get('checks.assessments')));
Rgt::same('references, a third party\'s details, are office only', $asTrainer->get('checks.references'), null);
Rgt::same('a trainer cannot act on the record', $asTrainer->get('can.manage'), false);

// A reason for absence is health information.
$db->query("INSERT INTO leave_records (person_id, kind, status, starts_on, ends_on, hours, leave_year, reason)
            VALUES ($carePerson, 'sickness', 'taken', '2026-09-01', '2026-09-02', 15, '2026/27', 'Migraine')");
$asManager = $manager->get("/people.php?id=$carePerson");
$sick = array_values(array_filter($asManager->get('employment.leave.records', []), static fn($l) => $l['kind'] === 'sickness'));
Rgt::ok('the manager sees that somebody was off', count($sick) === 1, $asManager->body);
Rgt::same('but not why, without the health zone', array_key_exists('reason', $sick[0] ?? []) ? $sick[0]['reason'] : 'absent', null);
Rgt::same('and is told it was withheld', $sick[0]['health_redacted'] ?? null, true);
$asAdmin = $admin->get("/people.php?id=$carePerson");
$sick = array_values(array_filter($asAdmin->get('employment.leave.records', []), static fn($l) => $l['kind'] === 'sickness'));
Rgt::same('an admin, who holds it, does', $sick[0]['reason'] ?? null, 'Migraine');

Rgt::group('emergency contacts — edited by the office, validated, audited');

$res = $admin->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'Wei Chen', 'emergency_contact_phone' => '07700 900555',
    'emergency_contact_relationship' => 'Brother',
    'emergency_contact2_name' => 'Li Chen', 'emergency_contact2_phone' => '+44 20 7946 0018',
]);
Rgt::status('an admin can save emergency contacts', $res, 200);
$row = $db->query("SELECT emergency_contact_name, emergency_contact_phone, emergency_contact2_name
                     FROM people WHERE id = $carePerson")->fetch_assoc();
Rgt::same('saved on the person', [$row['emergency_contact_name'], $row['emergency_contact_phone'], $row['emergency_contact2_name']],
    ['Wei Chen', '07700 900555', 'Li Chen']);
Rgt::same('and shown on the profile', $admin->get("/people.php?id=$carePerson")->get('emergency.emergency_contact_name'), 'Wei Chen');

$audit = $db->query("SELECT after_json FROM audit_log WHERE action = 'person.emergency_contacts'
                       AND entity_type = 'person' AND entity_id = $carePerson ORDER BY id DESC LIMIT 1")->fetch_assoc();
Rgt::ok('the change is audited', $audit !== null);
Rgt::ok('naming the fields that changed', str_contains((string) ($audit['after_json'] ?? ''), 'emergency_contact_name'));
Rgt::ok('without copying the number into the log', !str_contains((string) ($audit['after_json'] ?? ''), '900555'));

$res = $admin->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'Wei Chen', 'emergency_contact_phone' => 'ring the office',
]);
Rgt::status('a number nobody could ring is refused', $res, 422);
Rgt::ok('and says why', str_contains($res->error(), 'phone number'), $res->error());
Rgt::same('nothing was overwritten',
    $db->query("SELECT emergency_contact_phone FROM people WHERE id = $carePerson")->fetch_row()[0], '07700 900555');
$res = $admin->post("/people.php?action=emergency&id=$carePerson", ['emergency_contact_name' => 'Wei Chen']);
Rgt::status('a name with no number is refused', $res, 422);

$before = (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'person.emergency_contacts'")->fetch_row()[0];
Rgt::denied('a trainer cannot edit emergency contacts', $trainer->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'X', 'emergency_contact_phone' => '07700 900111',
]));
Rgt::denied('a worker login cannot edit them here', $workerLogin->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'X', 'emergency_contact_phone' => '07700 900111',
]));
Rgt::denied('a client login cannot edit them', $clientLogin->post("/people.php?action=emergency&id=$carePerson", [
    'emergency_contact_name' => 'X', 'emergency_contact_phone' => '07700 900111',
]));
Rgt::same('and no refused attempt wrote an audit entry',
    (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'person.emergency_contacts'")->fetch_row()[0], $before);
Rgt::same('nor changed the contact',
    $db->query("SELECT emergency_contact_name FROM people WHERE id = $carePerson")->fetch_row()[0], 'Wei Chen');

Rgt::group('training records — a record and its evidence, linked to the person');

$provider = (int) $db->query("SELECT id FROM training_providers WHERE name = 'St John Ambulance'")->fetch_row()[0];
$res = $admin->post("/people.php?action=training&id=$carePerson", [
    'provider_id' => $provider, 'course' => 'Emergency first aid at work',
    'completed_on' => '2026-03-01', 'expires_on' => '2029-03-01',
]);
Rgt::status('an admin can add a training record', $res, 201);
$rec = $db->query('SELECT person_id, learner_name, programme_id, status FROM enrolments WHERE id = ' . (int) $res->get('id'))->fetch_assoc();
Rgt::same('linked to the person', (int) $rec['person_id'], $carePerson);
// Link, never copy: the name lives on people.
Rgt::same('without copying their name', $rec['learner_name'], null);
Rgt::same('as completed training, not a course enrolment', [$rec['programme_id'], $rec['status']], [null, 'completed']);
$records = $admin->get("/people.php?id=$carePerson")->get('training.records', []);
Rgt::ok('and it appears on the training tab', in_array('Emergency first aid at work', array_column($records, 'course'), true));

Rgt::status('expiry before completion is refused', $admin->post("/people.php?action=training&id=$carePerson", [
    'course' => 'Fire safety', 'completed_on' => '2026-03-01', 'expires_on' => '2026-02-01',
]), 400);
Rgt::status('completion in the future is refused', $admin->post("/people.php?action=training&id=$carePerson", [
    'course' => 'Fire safety', 'completed_on' => '2099-01-01',
]), 400);
$db->query("INSERT INTO files (stored_name, original_name, mime, size_bytes, category, person_id)
                           VALUES ('x.pdf', 'Not theirs.pdf', 'application/pdf', 10, 'certificate', $securityPerson)");
$foreignFileId = (int) $db->insert_id;
Rgt::status("another person's certificate is refused", $admin->post("/people.php?action=training&id=$carePerson", [
    'course' => 'Fire safety', 'completed_on' => '2026-03-01', 'evidence_file_id' => $foreignFileId,
]), 422);
Rgt::denied('a trainer cannot add one', $trainer->post("/people.php?action=training&id=$carePerson", [
    'course' => 'Fire safety', 'completed_on' => '2026-03-01',
]));
Rgt::denied('nor a scoped manager for another service', $scoped->post("/people.php?action=training&id=$carePerson", [
    'course' => 'Fire safety', 'completed_on' => '2026-03-01',
]));

Rgt::group('evidence — a document filed against the wrong person is refused everywhere');

Rgt::status('a verification cannot point at another person\'s document',
    $admin->post('/verifications.php', [
        'person_id' => $carePerson, 'requirement_key' => 'first_aid', 'evidence_file_id' => $foreignFileId,
    ]), 422);
Rgt::status('nor can a right-to-work check',
    $admin->post('/right-to-work.php', [
        'person_id' => $carePerson, 'method' => 'manual_document', 'outcome' => 'valid',
        'checked_on' => date('Y-m-d'), 'evidence_file_id' => $foreignFileId,
    ]), 422);
