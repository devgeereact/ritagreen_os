<?php
// Spreadsheet import (S8): upload, map, preview, match, apply, report.
//
// What has to hold: a person exists once, nothing merges silently, nothing is
// written before apply, the same file twice creates nothing new, a scoped
// manager cannot touch another service's people, and an import can reach
// contact details and applications only, never compliance, pay or status.

/** @var RgtClient $client */
/** @var mysqli $db */
/** @var string $base */

require_once __DIR__ . '/../../../pure.php';

$admin = $client->login('admin@local.invalid');
$fixture = (string) file_get_contents(__DIR__ . '/../../fixtures/sheet-import-sample.csv');
$count = static fn(string $sql): int => (int) $db->query($sql)->fetch_row()[0];

Rgt::group('import — who may use it');

foreach (['priya@local.invalid' => 'a client portal login', 'amara.login@local.invalid' => "a worker's own login",
          'trainer@local.invalid' => 'a trainer', 'editor@local.invalid' => 'an editor'] as $email => $who) {
    $c = $client->login($email);
    Rgt::denied("$who cannot read the import history", $c->get('/sheet-import.php?action=history'));
    Rgt::denied("$who cannot upload", $c->post('/sheet-import.php?action=upload', ['file_name' => 'x.csv', 'csv' => $fixture]));
}
Rgt::denied('nobody signed in cannot upload', $client->post('/sheet-import.php?action=upload', ['file_name' => 'x.csv', 'csv' => $fixture]));

Rgt::group('import — upload');

Rgt::status('an XLSX file is refused with how to get a CSV',
    $admin->post('/sheet-import.php?action=upload', ['file_name' => 'responses.xlsx', 'csv' => 'PK']), 422);
Rgt::status('a file with no rows under the header is refused',
    $admin->post('/sheet-import.php?action=upload', ['file_name' => 'empty.csv', 'csv' => "Name,Email\n"]), 422);

// Row 8 of the sample is somebody who already applied through the careers page.
$db->query('DELETE FROM rate_limits');
Rgt::status('a candidate applies through the careers page first', $client->post('/ats.php', [
    'name' => 'Kemi Bakare', 'email' => 'kemi.bakare@example.test', 'phone' => '07700900428',
    'role_applied' => 'Care assistant', 'division' => 'care', 'consent' => true,
]), 201);

$applicantsBefore = $count('SELECT COUNT(*) FROM applicants');
$peopleBefore = $count('SELECT COUNT(*) FROM people');
$up = $admin->post('/sheet-import.php?action=upload', ['file_name' => 'Support worker (Responses).csv', 'csv' => "\xEF\xBB\xBF" . $fixture]);
Rgt::status('the sample sheet uploads', $up, 201);
$importId = (int) $up->get('id');
Rgt::same('with every response row', $up->get('rows'), 16);
Rgt::same('the file itself is not stored, only its cells per row',
    $count("SELECT COUNT(*) FROM sheet_import_rows WHERE import_id = $importId AND cells_json IS NOT NULL"), 16);

$view = $admin->get("/sheet-import.php?id=$importId");
Rgt::status('the import can be opened', $view, 200);
$cols = $view->get('columns', []);
Rgt::same('Timestamp is guessed as the response time', $cols['timestamp'] ?? null, 'response_timestamp');
Rgt::same('the email column is guessed', $cols['email address'] ?? null, 'email');
Rgt::same('the emergency phone is not taken for the phone', $cols['emergency contact phone'] ?? null, 'emergency_phone');

Rgt::group('import — map, with forbidden fields refused');

$columns = $cols;
$columns['How did you hear about us?'] = 'status';
$columns['Postcode'] = 'pay_rate_pence';
$res = $admin->post("/sheet-import.php?action=map&id=$importId", [
    'columns' => $columns, 'purpose' => 'applications',
    'save' => ['name' => 'Support worker form'],
]);
Rgt::status('a mapping that names forbidden fields is accepted', $res, 200);
Rgt::same('but a column mapped to status is stored as ignore', $res->get('columns.how did you hear about us?'), 'ignore');
Rgt::same('and so is one mapped to pay', $res->get('columns.postcode'), 'ignore');
$mappingId = $count("SELECT id FROM sheet_import_mappings WHERE name = 'Support worker form'");
Rgt::ok('the mapping is saved for next time', $mappingId > 0);
$saved = json_decode((string) $db->query("SELECT columns_json FROM sheet_import_mappings WHERE id = $mappingId")->fetch_row()[0], true);
Rgt::same('with nothing but known fields in it',
    array_values(array_diff(array_unique(array_values($saved)), array_merge(['ignore'], array_keys(RG_IMPORT_FIELDS)))), []);

// Put the real mapping back for the rest of the case.
$res = $admin->post("/sheet-import.php?action=map&id=$importId", [
    'columns' => $cols, 'purpose' => 'applications',
    'save' => ['name' => 'Support worker form', 'mapping_id' => $mappingId],
]);
Rgt::status('the saved mapping can be updated', $res, 200);
Rgt::status('a mapping without a name column is refused',
    $admin->post("/sheet-import.php?action=map&id=$importId", ['columns' => ['Full name' => 'ignore', 'Email address' => 'email']]), 422);

Rgt::group('import — preview, nothing written');

$rows = [];
foreach ($res->get('rows', []) as $r) $rows[(int) $r['row']] = $r;
$outcomes = array_map(static fn($r) => $r['outcome'], $rows);
ksort($outcomes);
Rgt::same('every row has the outcome the sample was written for', $outcomes, [
    2 => 'new', 3 => 'new', 4 => 'new', 5 => 'update', 6 => 'update', 7 => 'duplicate', 8 => 'duplicate',
    9 => 'uncertain', 10 => 'uncertain', 11 => 'problem', 12 => 'problem', 13 => 'problem',
    14 => 'new', 15 => 'new', 16 => 'no_change', 17 => 'new',
]);
Rgt::same('a repeat within the file says which row it repeats', $rows[7]['reason'] ?? null, 'The same as row 2 in this file.');
Rgt::ok('an existing open application is recognised', str_contains((string) ($rows[8]['reason'] ?? ''), 'open application'));
Rgt::same('an update shows old and new values',
    array_column($rows[5]['changes'] ?? [], 'field'),
    ['postcode', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'availability']);
Rgt::same('a shared phone with a different name is offered as a choice',
    array_column($rows[9]['candidates'] ?? [], 'name'), ['Grace Okafor']);
Rgt::same('a matching name alone is a choice, not a merge', $rows[10]['match']['basis'] ?? null, 'name');
Rgt::same('the phone that lost its leading zero is read correctly', $rows[15]['phone'] ?? null, '07700900426');
Rgt::same('the preview wrote no applicant', $count('SELECT COUNT(*) FROM applicants'), $applicantsBefore);
Rgt::same('and no person', $count('SELECT COUNT(*) FROM people'), $peopleBefore);

$grace = $rows[9]['candidates'][0]['id'] ?? 0;
$chosen = $admin->get("/sheet-import.php?action=row&id=$importId&row={$rows[9]['id']}&choice=$grace");
Rgt::same('choosing the member of staff turns the row into an update', $chosen->get('row.outcome'), 'update');
$bogus = $admin->get("/sheet-import.php?action=row&id=$importId&row={$rows[9]['id']}&choice=1");
Rgt::same('a person who was not offered cannot be chosen', $bogus->get('row.outcome'), 'uncertain');

Rgt::group('import — apply');

$amara = $db->query("SELECT w.id, w.person_id, w.status, w.approved_at, w.pay_rate_pence, w.right_to_work
                       FROM workers w WHERE w.email = 'amara@local.invalid'")->fetch_assoc();
$bola = (int) $db->query("SELECT person_id FROM workers WHERE email = 'bola@local.invalid'")->fetch_row()[0];
$bolaBefore = $db->query("SELECT email, phone, postcode FROM people WHERE id = $bola")->fetch_assoc();
$gracePerson = (int) $grace;
$gracePhone = $db->query("SELECT phone FROM people WHERE id = $gracePerson")->fetch_row()[0];

$tick = [];
foreach ($rows as $no => $r) {
    if (in_array($r['outcome'], ['new', 'update', 'duplicate', 'no_change'], true)) $tick[] = ['id' => $r['id']];
}
$tick[] = ['id' => $rows[9]['id'], 'choice' => 'new'];   // Gemma is not Grace
$tick[] = ['id' => $rows[10]['id']];                      // Bola: no decision made
$tick[] = ['id' => $rows[11]['id']];                      // a problem, ticked anyway

$applied = $admin->post("/sheet-import.php?action=apply&id=$importId", ['rows' => $tick]);
Rgt::status('the import applies', $applied, 200);
Rgt::same('the report counts every row',
    [$applied->get('counts.created'), $applied->get('counts.updated'), $applied->get('counts.skipped'), $applied->get('counts.failed'), $applied->get('counts.pending')],
    [7, 2, 4, 3, 0]);
Rgt::status('it cannot be applied twice', $admin->post("/sheet-import.php?action=apply&id=$importId", ['rows' => $tick]), 409);

$ada = $db->query("SELECT id, person_id, source, stage, division, notes FROM applicants WHERE email = 'ada.lovell@example.test'")->fetch_all(MYSQLI_ASSOC);
Rgt::same('one application for the person who appeared twice', count($ada), 1);
Rgt::same('marked as a spreadsheet import', $ada[0]['source'] ?? null, 'spreadsheet_import');
Rgt::same('entering at applied', $ada[0]['stage'] ?? null, 'applied');
Rgt::ok('with the file and row in its notes', str_contains((string) ($ada[0]['notes'] ?? ''), 'row 2'));
$adaId = (int) ($ada[0]['id'] ?? 0);
Rgt::same('and a first history row like any other', $count("SELECT COUNT(*) FROM applicant_stage_history WHERE applicant_id = $adaId"), 1);
$adaPerson = $db->query('SELECT full_name, emergency_contact_name, postcode FROM people WHERE id = ' . (int) $ada[0]['person_id'])->fetch_assoc();
Rgt::same('the person holds the identity', $adaPerson['full_name'], 'Ada Lovell');
Rgt::same('and the emergency contact', $adaPerson['emergency_contact_name'], 'Sam Lovell');

$amaraPerson = $db->query("SELECT postcode, emergency_contact_name FROM people WHERE id = {$amara['person_id']}")->fetch_assoc();
Rgt::same('a worker\'s postcode is updated on the person', $amaraPerson['postcode'], 'LS1 4GH');
Rgt::same('and their emergency contact', $amaraPerson['emergency_contact_name'], 'Joe Chen');
$amaraAfter = $db->query("SELECT status, approved_at, pay_rate_pence, right_to_work, avail_days, avail_nights, avail_weekends
                            FROM workers WHERE id = {$amara['id']}")->fetch_assoc();
Rgt::same('their availability is updated', [(int) $amaraAfter['avail_days'], (int) $amaraAfter['avail_nights'], (int) $amaraAfter['avail_weekends']], [0, 1, 1]);
Rgt::same('their employment status, approval, pay and right to work are untouched',
    [$amaraAfter['status'], $amaraAfter['approved_at'], $amaraAfter['pay_rate_pence'], $amaraAfter['right_to_work']],
    [$amara['status'], $amara['approved_at'], $amara['pay_rate_pence'], $amara['right_to_work']]);

$gemma = $db->query("SELECT person_id FROM applicants WHERE email = 'gemma.okafor@example.test'")->fetch_assoc();
Rgt::ok('"create new" made a new person, not Grace', $gemma !== null && (int) $gemma['person_id'] !== $gracePerson);
Rgt::same("and left Grace's record alone", $db->query("SELECT phone FROM people WHERE id = $gracePerson")->fetch_row()[0], $gracePhone);

Rgt::same('an uncertain match with no decision was not applied',
    $db->query("SELECT outcome, reason FROM sheet_import_rows WHERE id = {$rows[10]['id']}")->fetch_assoc(),
    ['outcome' => 'skipped', 'reason' => 'Needs a decision about who this is.']);
Rgt::same('Bola was not changed', $db->query("SELECT email, phone, postcode FROM people WHERE id = $bola")->fetch_assoc(), $bolaBefore);
Rgt::same('and no application was made from that row', $count("SELECT COUNT(*) FROM applicants WHERE email = 'bola.a@example.test'"), 0);

Rgt::same('every created row is audited with the file and row',
    $count("SELECT COUNT(*) FROM audit_log WHERE action = 'import.row_created' AND detail LIKE 'Support worker (Responses).csv, row %'"), 7);
Rgt::same('and every update', $count("SELECT COUNT(*) FROM audit_log WHERE action = 'import.row_updated' AND detail LIKE 'Support worker (Responses).csv, row %'"), 2);
Rgt::same('only failed rows keep their cells', $count("SELECT COUNT(*) FROM sheet_import_rows WHERE import_id = $importId AND cells_json IS NOT NULL"), 3);

Rgt::group('import — report and failures download');

$fails = $admin->get("/sheet-import.php?action=failures&id=$importId");
Rgt::status('the failures download', $fails, 200);
Rgt::ok('is a CSV', str_contains((string) ($fails->headers['content-type'] ?? ''), 'text/csv'));
Rgt::ok('with the row, the reason and the original cells',
    str_contains($fails->body, 'Row,Reason') && str_contains($fails->body, 'eve at example') && str_contains($fails->body, 'Catering'));
$history = $admin->get('/sheet-import.php?action=history');
Rgt::ok('the import is in the history', in_array($importId, array_column($history->get('imports', []), 'id'), true));

Rgt::status('the import can be closed', $admin->post("/sheet-import.php?action=close&id=$importId", []), 200);
Rgt::same('which clears every held cell', $count("SELECT COUNT(*) FROM sheet_import_rows WHERE import_id = $importId AND cells_json IS NOT NULL"), 0);

Rgt::group('import — the same file again creates nothing');

$applicantsMid = $count('SELECT COUNT(*) FROM applicants');
$peopleMid = $count('SELECT COUNT(*) FROM people');
$again = $admin->post('/sheet-import.php?action=upload', ['file_name' => 'Support worker (Responses) (1).csv', 'csv' => $fixture]);
$againId = (int) $again->get('id');
Rgt::same('the saved mapping is picked for the same headings', $again->get('mapping_id'), $mappingId);
$view = $admin->get("/sheet-import.php?id=$againId");
$dups = array_filter($view->get('rows', []), static fn($r) => $r['outcome'] === 'duplicate'
    && str_starts_with((string) $r['reason'], 'Already imported from Support worker (Responses).csv'));
Rgt::same('every row applied last time is recognised', count($dups), 9);
$all = array_map(static fn($r) => ['id' => $r['id']], $view->get('rows', []));
$res = $admin->post("/sheet-import.php?action=apply&id=$againId", ['rows' => $all]);
Rgt::same('applying everything creates nothing', [$res->get('counts.created'), $res->get('counts.updated')], [0, 0]);
Rgt::same('no new applicant', $count('SELECT COUNT(*) FROM applicants'), $applicantsMid);
Rgt::same('no new person', $count('SELECT COUNT(*) FROM people'), $peopleMid);

Rgt::group('import — a response the form feed already delivered');

$secret = 'test-shared-secret-not-a-real-one';
$db->query("UPDATE integrations SET secret_hash = '" . hash('sha256', $secret) . "', active = 1 WHERE integration_key = 'careers_care'");
$db->query("INSERT IGNORE INTO integration_field_maps (integration_id, source_field, target_field)
            SELECT id, 'Full name', 'name' FROM integrations WHERE integration_key = 'careers_care'");
$db->query("INSERT IGNORE INTO integration_field_maps (integration_id, source_field, target_field)
            SELECT id, 'Email address', 'email' FROM integrations WHERE integration_key = 'careers_care'");
$db->query("INSERT IGNORE INTO integration_field_maps (integration_id, source_field, target_field)
            SELECT id, 'Position applied for', 'role_applied' FROM integrations WHERE integration_key = 'careers_care'");
$db->query('DELETE FROM rate_limits');
$fed = $client->withHeader('X-RitaGreen-Secret', $secret)->post('/forms-intake.php?key=careers_care', [
    'response_id' => 'sheet-feed-0001',
    'answers' => ['Full name' => 'Fed Applicant', 'Email address' => 'fed.applicant@example.test', 'Position applied for' => 'Care assistant'],
]);
Rgt::status('the feed delivers an application', $fed, 201);

$today = gmdate('d/m/Y H:i:s');
$csv = "Timestamp,Full name,Email address,Role,Service,Response id\n"
     . "$today,Fed Applicant,fed.applicant@example.test,Care assistant,Healthcare,\n"
     . "$today,Other Fed,other.fed@example.test,Care assistant,Healthcare,sheet-feed-0001\n";
$up = $admin->post('/sheet-import.php?action=upload', ['file_name' => 'feed-overlap.csv', 'csv' => $csv]);
$view = $admin->get('/sheet-import.php?id=' . (int) $up->get('id'));
$r = $view->get('rows', []);
Rgt::same('the same person, role and day is a duplicate of the feed', $r[0]['outcome'] ?? null, 'duplicate');
Rgt::ok('and says it came through the form feed', str_contains((string) ($r[0]['reason'] ?? ''), 'through the form feed'));
Rgt::same('a response id the feed recorded is a duplicate', [$r[1]['outcome'] ?? null, $r[1]['reason'] ?? null],
    ['duplicate', 'Already received through the form feed.']);

Rgt::group('import — service scope');

$scoped = $client->login('scoped@local.invalid');
Rgt::denied("a scoped manager cannot open another person's import", $scoped->get("/sheet-import.php?id=$importId"));
$csv = "Full name,Email address,Phone,Postcode,Role,Service\n"
     . "Amara Chen,,07700900101,ZZ1 1ZZ,,\n"
     . "Care Person,care.person@example.test,07700900601,,Support worker,Healthcare\n"
     . "Guard Person,guard.person@example.test,07700900602,,Security officer,Security\n";
$up = $scoped->post('/sheet-import.php?action=upload', ['file_name' => 'scoped.csv', 'csv' => $csv]);
Rgt::status('a scoped manager can upload', $up, 201);
$sid = (int) $up->get('id');
Rgt::denied('but cannot default rows to another service',
    $scoped->post("/sheet-import.php?action=map&id=$sid", ['columns' => ['Full name' => 'name', 'Email address' => 'email', 'Phone' => 'phone'], 'default_service' => 'care']));
$view = $scoped->get("/sheet-import.php?id=$sid");
$r = $view->get('rows', []);
Rgt::same('a care worker is outside a security manager\'s service', [$r[0]['outcome'] ?? null, $r[0]['reason'] ?? null],
    ['problem', 'This matches a person outside your service.']);
Rgt::same('and no detail about them is shown', [$r[0]['person'] ?? null, $r[0]['changes'] ?? null], [null, []]);
Rgt::same('a care application is outside it too', $r[1]['outcome'] ?? null, 'problem');
Rgt::same('a security application is not', $r[2]['outcome'] ?? null, 'new');
$res = $scoped->post("/sheet-import.php?action=apply&id=$sid", ['rows' => array_map(static fn($x) => ['id' => $x['id']], $r)]);
Rgt::same('only the security row is applied', [$res->get('counts.created'), $res->get('counts.updated'), $res->get('counts.failed')], [1, 0, 2]);
Rgt::same("Amara's postcode was not changed by the scoped manager",
    $db->query("SELECT postcode FROM people WHERE id = {$amara['person_id']}")->fetch_row()[0], 'LS1 4GH');
Rgt::same('and no care application was made', $count("SELECT COUNT(*) FROM applicants WHERE email = 'care.person@example.test'"), 0);

Rgt::group('import — two simultaneous applies of one import');

$csv = "Full name,Email address,Role,Service\nRace Import,race.import@example.test,Cleaner,Cleaning\n";
$up = $admin->post('/sheet-import.php?action=upload', ['file_name' => 'race.csv', 'csv' => $csv]);
$rid = (int) $up->get('id');
$rowId = (int) $admin->get("/sheet-import.php?id=$rid")->get('rows.0.id');
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 4; $i++) {
    $ch = curl_init("$base/sheet-import.php?action=apply&id=$rid");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['rows' => [['id' => $rowId]]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $admin->token()],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_multi_add_handle($multi, $ch);
    $handles[] = $ch;
}
$running = null;
do { curl_multi_exec($multi, $running); curl_multi_select($multi, 0.1); } while ($running > 0);
$codes = [];
foreach ($handles as $ch) {
    $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_multi_remove_handle($multi, $ch);
}
curl_multi_close($multi);
sort($codes);
Rgt::same('one apply wins and the others are refused', $codes, [200, 409, 409, 409]);
Rgt::same('one application, not four', $count("SELECT COUNT(*) FROM applicants WHERE email = 'race.import@example.test'"), 1);
