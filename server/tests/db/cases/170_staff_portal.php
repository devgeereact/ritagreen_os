<?php
// The staff portal (section S4): a worker reads and changes their own records
// through me.php and leave.php, and nobody else's. Launch plan Phase 4 exit
// criterion: "another worker's record cannot be accessed".
//
// A second worker login is made here for Bola Adeyemi, so every cross-worker
// case has a real account on the other side rather than a guessed id.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$one = static fn(mysqli $db, string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$day = static fn(int $n): string => date('Y-m-d', strtotime("+$n days"));

$amara = (int) $one($db, "SELECT id FROM workers WHERE name = 'Amara Chen'");
$amaraPerson = (int) $one($db, "SELECT person_id FROM workers WHERE id = $amara");
$bola = (int) $one($db, "SELECT id FROM workers WHERE name = 'Bola Adeyemi'");
$bolaPerson = (int) $one($db, "SELECT person_id FROM workers WHERE id = $bola");
$hash = $db->real_escape_string((string) $one($db, "SELECT password_hash FROM users WHERE email = 'amara.login@local.invalid'"));
$db->query("INSERT INTO users (name, email, password_hash, role, division, worker_id, person_id)
            VALUES ('Bola Adeyemi', 'bola.login@local.invalid', '$hash', 'employee', 'care', $bola, $bolaPerson)");

$admin = $client->login('admin@local.invalid');
$amaraApp = $client->login('amara.login@local.invalid');
$bolaApp = $client->login('bola.login@local.invalid');
$priya = $client->login('priya@local.invalid');
$amaraUser = (int) $one($db, "SELECT id FROM users WHERE email = 'amara.login@local.invalid'");

// ---------------------------------------------------------------------------
Rgt::group('portal: only a worker login gets worker data');

foreach (['', '?action=records', '?action=timesheets', '?action=availability', '?action=profile',
          '?action=payslip&from=' . date('Y-m-01') . '&to=' . date('Y-m-t')] as $q) {
    Rgt::denied("a client login gets nothing from me.php$q", $priya->get("/me.php$q"));
    Rgt::denied("an office login gets nothing from me.php$q", $admin->get("/me.php$q"));
}
Rgt::denied('a client login cannot save emergency contacts', $priya->post('/me.php?action=emergency', ['emergency_contact_name' => 'X']));
Rgt::denied('nor can an office login through the portal', $admin->post('/me.php?action=profile', ['phone' => '07700 900999']));
Rgt::status('nobody signed in gets a 401', $client->get('/me.php'), 401);

// ---------------------------------------------------------------------------
Rgt::group('portal: home');

$home = $amaraApp->get('/me.php');
Rgt::status('Amara reads her home screen', $home, 200);
Rgt::same('it is her', $home->get('worker.id'), $amara);
Rgt::ok('every shift is hers and published', !array_diff(
    array_map('intval', array_column($home->get('shifts', []), 'id')),
    array_map('intval', array_column($db->query("SELECT id FROM shifts WHERE worker_id = $amara AND is_draft = 0")->fetch_all(MYSQLI_ASSOC), 'id'))
));
$first = $home->get('shifts', [])[0] ?? [];
Rgt::ok('each shift says when clocking in opens', isset($first['clock_in_opens_at']), $home->body);
Rgt::same('she is clear to work', $home->get('eligible'), true);
Rgt::same('so nothing is blocking her', $home->get('blockers'), []);
Rgt::ok('Moving & Handling, due in 55 days, is not yet "due soon"',
    !in_array('Moving & Handling', array_column($home->get('training_due', []), 'label'), true));

$cerysHome = null;
$cerys = (int) $one($db, "SELECT id FROM workers WHERE name = 'Cerys Hughes'");
$cerysPerson = (int) $one($db, "SELECT person_id FROM workers WHERE id = $cerys");
$db->query("INSERT INTO users (name, email, password_hash, role, division, worker_id, person_id)
            VALUES ('Cerys Hughes', 'cerys.login@local.invalid', '$hash', 'employee', 'care', $cerys, $cerysPerson)");
$cerysApp = $client->login('cerys.login@local.invalid');
$cerysHome = $cerysApp->get('/me.php');
Rgt::same('a worker with an expired DBS is not clear to work', $cerysHome->get('eligible'), false);
$dbsBlock = array_values(array_filter($cerysHome->get('blockers', []), static fn($b) => $b['key'] === 'dbs'))[0] ?? [];
Rgt::ok('and is told which check', str_starts_with((string) ($dbsBlock['title'] ?? ''), 'DBS'), $cerysHome->body);
Rgt::ok('and what to do: nothing is waiting with the office, so send a copy',
    str_contains((string) ($dbsBlock['todo'] ?? ''), 'Upload a current copy'), $cerysHome->body);

// ---------------------------------------------------------------------------
Rgt::group('portal: offers');

$create = static fn(array $body): int => (int) $admin->post('/scheduling.php', $body + ['title' => 'Day carer', 'division' => 'care'])->get('id');
$offered = $create(['starts_at' => $day(95) . ' 08:00', 'ends_at' => $day(95) . ' 16:00']);
$admin->post('/scheduling.php?action=publish', ['ids' => [$offered]]);
$res = $admin->post("/scheduling.php?action=offer&id=$offered", ['worker_ids' => [$amara], 'acknowledge_warnings' => true]);
Rgt::ok('the office offers a shift to Amara', $res->status < 300, $res->body);
// And to Bola, written directly: whether the office may offer it to him is the
// rota's test (160), not this one's. This is about whose offer is whose.
$db->query("INSERT INTO shift_offers (shift_id, worker_id, token, status) VALUES ($offered, $bola, '" . bin2hex(random_bytes(20)) . "', 'sent')");
$amaraOffer = (int) $one($db, "SELECT id FROM shift_offers WHERE shift_id = $offered AND worker_id = $amara");
$bolaOffer = (int) $one($db, "SELECT id FROM shift_offers WHERE shift_id = $offered AND worker_id = $bola");
Rgt::ok('Amara sees her offer', in_array($amaraOffer, array_column($amaraApp->get('/me.php')->get('offers', []), 'id'), true));
Rgt::ok('and not Bola\'s', !in_array($bolaOffer, array_column($amaraApp->get('/me.php')->get('offers', []), 'id'), true));
Rgt::status('Amara cannot answer Bola\'s offer', $amaraApp->post("/me.php?action=offer_decline&id=$bolaOffer"), 404);
Rgt::same('Bola\'s offer is untouched', $one($db, "SELECT status FROM shift_offers WHERE id = $bolaOffer"), 'sent');
$res = $amaraApp->post("/me.php?action=offer_accept&id=$amaraOffer");
Rgt::status('Amara accepts hers', $res, 200);
Rgt::same('she is booked', (int) $one($db, "SELECT worker_id FROM shifts WHERE id = $offered"), $amara);
Rgt::same('Bola\'s offer lapses', $one($db, "SELECT status FROM shift_offers WHERE id = $bolaOffer"), 'expired');
Rgt::status('an offer cannot be answered twice', $amaraApp->post("/me.php?action=offer_accept&id=$amaraOffer"), 200);

// Declined, and the refusal path: a clash is refused with the reason.
$clashing = $create(['starts_at' => $day(95) . ' 12:00', 'ends_at' => $day(95) . ' 20:00']);
$admin->post('/scheduling.php?action=publish', ['ids' => [$clashing]]);
$open = array_values(array_filter($amaraApp->get('/me.php')->get('open_shifts', []), static fn($s) => $s['id'] === $clashing))[0] ?? [];
Rgt::same('an open shift she cannot take says so before she tries', $open['eligible'] ?? null, false);
Rgt::ok('with the reason', str_contains((string) ($open['reason'] ?? ''), 'overlapping'), json_encode($open));
$res = $amaraApp->post("/me.php?action=accept&shift_id=$clashing");
Rgt::status('picking it up is refused', $res, 409);
Rgt::ok('saying why', str_contains($res->error(), 'overlapping'), $res->error());

// ---------------------------------------------------------------------------
Rgt::group('portal: records and evidence');

$rec = $amaraApp->get('/me.php?action=records');
Rgt::status('Amara reads her records', $rec, 200);
$keys = array_column($rec->get('checks', []), 'key');
Rgt::ok('her checks are her service\'s checks', in_array('dbs', $keys, true), $rec->body);
$dbs = array_values(array_filter($rec->get('checks', []), static fn($c) => $c['key'] === 'dbs'))[0] ?? [];
Rgt::same('her DBS reads as valid', $dbs['chip'] ?? null, 'valid');

$tmp = tempnam(sys_get_temp_dir(), 'rg') . '.pdf';
file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
$txt = tempnam(sys_get_temp_dir(), 'rg') . '.txt';
file_put_contents($txt, 'not a document');

Rgt::status('a contract cannot be uploaded by a worker',
    $amaraApp->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'contract']), 422);
Rgt::status('nor right to work', $amaraApp->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'right_to_work']), 422);
Rgt::status('nor a check from another service', $amaraApp->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'sia_licence']), 422);
Rgt::status('nor a text file', $amaraApp->upload('/me.php?action=upload', $txt, 'text/plain', ['requirement_key' => 'dbs']), 400);
Rgt::denied('a client login cannot upload through the portal', $priya->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'dbs']));

$before = (int) $one($db, "SELECT COUNT(*) FROM verifications WHERE person_id = $amaraPerson AND status = 'verified'");
$res = $amaraApp->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'dbs']);
Rgt::status('she sends a new DBS certificate', $res, 201);
$fileId = (int) $res->get('file_id');
$vid = (int) $res->get('verification_id');
Rgt::same('it waits for review', $one($db, "SELECT status FROM verifications WHERE id = $vid"), 'received');
Rgt::same('nobody has verified it', $one($db, "SELECT verified_by FROM verifications WHERE id = $vid"), null);
Rgt::same('nothing became verified', (int) $one($db, "SELECT COUNT(*) FROM verifications WHERE person_id = $amaraPerson AND status = 'verified'"), $before);
Rgt::same('the file is filed against her person, not her worker row',
    $one($db, "SELECT CONCAT(person_id, '/', IFNULL(worker_id, 'null'), '/', category) FROM files WHERE id = $fileId"), "$amaraPerson/null/certificate");
Rgt::ok('it appears in the office verification queue', in_array($vid,
    array_map('intval', array_column($admin->get('/verifications.php?action=queue')->get('items', []), 'id')), true));
$dbs = array_values(array_filter($amaraApp->get('/me.php?action=records')->get('checks', []), static fn($c) => $c['key'] === 'dbs'))[0] ?? [];
Rgt::same('her DBS still reads as valid, on the evidence already checked', $dbs['chip'] ?? null, 'valid');
Rgt::same('with a copy waiting', $dbs['waiting'] ?? null, true);
Rgt::status('a second copy while one is waiting is refused',
    $amaraApp->upload('/me.php?action=upload', $tmp, 'application/pdf', ['requirement_key' => 'dbs']), 409);

$dl = $amaraApp->get("/me.php?action=download&id=$fileId");
Rgt::status('she can download what she sent', $dl, 200);
Rgt::status('Bola cannot download it', $bolaApp->get("/me.php?action=download&id=$fileId"), 404);
$officeFile = (int) $one($db, "SELECT id FROM files WHERE worker_id = $amara ORDER BY id LIMIT 1");
Rgt::status('nor can Amara download a file the office filed about her', $amaraApp->get("/me.php?action=download&id=$officeFile"), 404);

// ---------------------------------------------------------------------------
Rgt::group('portal: timesheets and gross pay are her own');

$db->query("INSERT INTO timesheets (worker_id, work_date, start_time, end_time, minutes, status)
            VALUES ($bola, '" . $day(-3) . "', '08:00', '16:00', 480, 'draft')");
$bolaTs = (int) $db->insert_id;
Rgt::ok('Amara does not see Bola\'s timesheet',
    !in_array($bolaTs, array_column($amaraApp->get('/me.php?action=timesheets')->get('timesheets', []), 'id'), true));
Rgt::status('nor submit it', $amaraApp->post("/me.php?action=submit_timesheet&id=$bolaTs", ['break_minutes' => 0]), 404);
Rgt::same('it is still a draft', $one($db, "SELECT status FROM timesheets WHERE id = $bolaTs"), 'draft');
$pay = $amaraApp->get('/me.php?action=payslip&worker_id=' . $bola . '&from=' . $day(-10) . '&to=' . $day(0));
Rgt::status('asking for Bola\'s gross pay', $pay, 200);
Rgt::same('answers with Amara\'s own, whatever the request says', $pay->get('worker.id'), $amara);

// ---------------------------------------------------------------------------
Rgt::group('portal: leave and availability are her own');

Rgt::denied('Amara cannot read Bola\'s leave', $amaraApp->get("/leave.php?person_id=$bolaPerson"));
$res = $amaraApp->post('/leave.php', ['person_id' => $bolaPerson, 'kind' => 'annual', 'starts_on' => $day(60), 'ends_on' => $day(61), 'hours' => 15]);
Rgt::denied('nor request leave for him', $res);
$res = $amaraApp->post('/leave.php', ['kind' => 'annual', 'starts_on' => $day(60), 'ends_on' => $day(61), 'hours' => 15,
    'paid_pence' => 999999, 'in_lieu' => true, 'status' => 'approved']);
Rgt::status('she requests her own', $res, 201);
$leaveId = (int) $res->get('id');
Rgt::same('as a request, whatever she sends', $one($db, "SELECT status FROM leave_records WHERE id = $leaveId"), 'requested');
Rgt::same('with no money figure from the request', $one($db, "SELECT paid_pence FROM leave_records WHERE id = $leaveId"), null);
Rgt::same('and not in lieu', (int) $one($db, "SELECT in_lieu FROM leave_records WHERE id = $leaveId"), 0);
Rgt::same('for her own person', (int) $one($db, "SELECT person_id FROM leave_records WHERE id = $leaveId"), $amaraPerson);
$mine = $amaraApp->get('/leave.php');
Rgt::ok('she sees it with its status', in_array($leaveId, array_column($mine->get('records', []), 'id'), true));

$db->query("INSERT INTO availability_blocks (person_id, starts_at, ends_at, note)
            VALUES ($bolaPerson, '" . $day(50) . " 00:00:00', '" . $day(51) . " 00:00:00', 'Bola away')");
$bolaBlock = (int) $db->insert_id;
Rgt::ok('Amara does not see Bola\'s unavailability',
    !in_array($bolaBlock, array_column($amaraApp->get('/me.php?action=availability')->get('unavailable', []), 'id'), true));
Rgt::status('nor remove it', $amaraApp->post("/me.php?action=availability_remove&id=$bolaBlock"), 404);
Rgt::same('it is still there', $one($db, "SELECT deleted_at FROM availability_blocks WHERE id = $bolaBlock"), null);
$res = $amaraApp->post('/me.php?action=availability', ['starts_at' => $day(52) . ' 00:00', 'ends_at' => $day(53) . ' 00:00', 'note' => 'Exam']);
Rgt::status('she marks herself unavailable', $res, 201);
Rgt::same('on her own person', (int) $one($db, 'SELECT person_id FROM availability_blocks WHERE id = ' . (int) $res->get('id')), $amaraPerson);

// ---------------------------------------------------------------------------
Rgt::group('portal: contact details and emergency contacts');

$bolaBefore = $db->query("SELECT phone, emergency_contact_name FROM people WHERE id = $bolaPerson")->fetch_assoc();
$res = $amaraApp->post('/me.php?action=emergency', [
    'person_id' => $bolaPerson,
    'emergency_contact_name' => 'Wei Chen', 'emergency_contact_phone' => '07700 900555', 'emergency_contact_relationship' => 'Brother',
]);
Rgt::status('she saves an emergency contact', $res, 200);
Rgt::same('on her own record', $one($db, "SELECT emergency_contact_name FROM people WHERE id = $amaraPerson"), 'Wei Chen');
Rgt::same('a person id in the request is ignored, Bola is untouched',
    $one($db, "SELECT emergency_contact_name FROM people WHERE id = $bolaPerson"), $bolaBefore['emergency_contact_name']);
Rgt::ok('audited with the field names, not the values', (int) $one($db,
    "SELECT COUNT(*) FROM audit_log WHERE action = 'person.emergency_contacts' AND entity_id = $amaraPerson AND after_json LIKE '%portal%' AND after_json NOT LIKE '%Wei Chen%'") === 1);
Rgt::status('a contact with no number is refused', $amaraApp->post('/me.php?action=emergency', ['emergency_contact_name' => 'Nobody']), 422);

$res = $amaraApp->post('/me.php?action=profile', ['person_id' => $bolaPerson, 'phone' => '07700 900777', 'address_line1' => '12 Park Road', 'city' => 'London', 'postcode' => 'se13 6aa']);
Rgt::status('she changes her phone and address', $res, 200);
Rgt::same('on her person record', $one($db, "SELECT CONCAT(phone, '|', postcode) FROM people WHERE id = $amaraPerson"), '07700 900777|SE13 6AA');
Rgt::same('the worker row the texts read follows it', $one($db, "SELECT phone FROM workers WHERE id = $amara"), '07700 900777');
Rgt::same('Bola is untouched', $one($db, "SELECT phone FROM people WHERE id = $bolaPerson"), $bolaBefore['phone']);
Rgt::ok('audited', (int) $one($db, "SELECT COUNT(*) FROM audit_log WHERE action = 'person.contact_details' AND entity_id = $amaraPerson") === 1);
Rgt::status('a bad postcode is refused', $amaraApp->post('/me.php?action=profile', ['phone' => '07700 900777', 'postcode' => 'nowhere']), 422);
$profile = $amaraApp->get('/me.php?action=profile');
Rgt::same('her profile reads back from people', $profile->get('person.address_line1'), '12 Park Road');

// ---------------------------------------------------------------------------
Rgt::group('portal: reporting a problem and clocking in');

$bolaShift = (int) $one($db, "SELECT id FROM shifts WHERE worker_id = $bola AND is_draft = 0 ORDER BY id LIMIT 1");
Rgt::status('she cannot report against Bola\'s shift', $amaraApp->post('/me.php?action=report', ['shift_id' => $bolaShift, 'note' => 'x']), 404);
Rgt::status('a report needs words', $amaraApp->post('/me.php?action=report', ['note' => '']), 400);
$res = $amaraApp->post('/me.php?action=report', ['shift_id' => $offered, 'note' => 'The key safe code has changed.']);
Rgt::status('she reports a problem on her shift', $res, 201);
Rgt::ok('the office is told', (int) $one($db, "SELECT COUNT(*) FROM notifications WHERE user_id IS NULL AND title = 'Problem reported' AND body LIKE '%key safe%'") === 1);
Rgt::ok('and it is on the shift\'s trail', (int) $one($db, "SELECT COUNT(*) FROM shift_events WHERE shift_id = $offered AND kind = 'note'") === 1);

$res = $amaraApp->post('/field.php', ['shift_id' => $offered, 'kind' => 'clock_in']);
Rgt::status('clocking in weeks before the shift is refused', $res, 409);
Rgt::ok('saying when it opens', str_contains($res->error(), 'You can clock in from'), $res->error());
Rgt::status('the office is not held to the window', $admin->post('/field.php', ['shift_id' => $offered, 'kind' => 'clock_in']), 201);

@unlink($tmp);
@unlink($txt);
