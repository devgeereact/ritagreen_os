<?php
// The rota (section S3): drafts workers cannot see, publishing, the one
// assignment check on every path, warnings the office must acknowledge, sites
// with their own requirements, copy last week, timesheets from the clock, and
// shifts made from a cover request.
//
// Every date here is weeks ahead so nothing collides with the seeded shifts,
// which sit today and in the next four days.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');     // manager, security, scoped
$amaraApp = $client->login('amara.login@local.invalid');

$one = static fn(mysqli $db, string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$day = static fn(int $n): string => date('Y-m-d', strtotime("+$n days"));

$amara = (int) $one($db, "SELECT id FROM workers WHERE name = 'Amara Chen'");
$amaraPerson = (int) $one($db, "SELECT person_id FROM workers WHERE id = $amara");
$amaraUser = (int) $one($db, "SELECT id FROM users WHERE email = 'amara.login@local.invalid'");
$oakleaf = (int) $one($db, "SELECT id FROM sites WHERE name = 'Oakleaf — Main House'");
$oakleafClient = (int) $one($db, "SELECT client_id FROM sites WHERE id = $oakleaf");
$notes = static fn(mysqli $db, int $uid, string $title): int =>
    (int) $db->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND title = '" . $db->real_escape_string($title) . "'")->fetch_row()[0];

$create = static function (RgtClient $as, array $body): RgtResponse {
    return $as->post('/scheduling.php', $body + ['title' => 'Day carer', 'division' => 'care']);
};

// ---------------------------------------------------------------------------
Rgt::group('rota: the seeded rota is published and linked to sites');

Rgt::same('every seeded shift counts as published',
    (int) $one($db, 'SELECT COUNT(*) FROM shifts WHERE is_draft = 1'), 0);
Rgt::ok('seeded shifts point at their site record',
    (int) $one($db, "SELECT COUNT(*) FROM shifts WHERE site_name = 'Oakleaf — Main House' AND site_id = $oakleaf") > 0);

$check = $admin->get("/scheduling.php?action=match&division=care&starts_at={$day(20)}%2008:00&ends_at={$day(20)}%2016:00");
Rgt::status('find eligible answers', $check, 200);
Rgt::ok('Amara Chen is eligible for an ordinary care shift',
    in_array($amara, array_column($check->get('workers', []), 'id'), true), $check->body);

// ---------------------------------------------------------------------------
Rgt::group('rota: draft, publish, assign, cancel');

$res = $create($admin, ['site_id' => $oakleaf, 'starts_at' => "{$day(20)} 08:00", 'ends_at' => "{$day(20)} 16:00"]);
Rgt::status('a new shift is created', $res, 201);
$draft = (int) $res->get('id');
Rgt::same('as a draft', (int) $res->get('shift.is_draft'), 1);
Rgt::same('at the site, with its name', $res->get('shift.site_name'), 'Oakleaf — Main House');
Rgt::same('and the site\'s client', (int) $res->get('shift.client_id'), $oakleafClient);

$me = $amaraApp->get('/me.php');
Rgt::ok('a worker does not see a draft open shift',
    !in_array($draft, array_column($me->get('open_shifts', []), 'id'), true));
Rgt::status('nor can they claim it by id', $amaraApp->post("/me.php?action=accept&shift_id=$draft"), 409);
Rgt::status('a draft cannot be broadcast', $admin->post("/scheduling.php?action=broadcast&id=$draft"), 409);
Rgt::status('nor offered by name', $admin->post("/scheduling.php?action=offer&id=$draft", ['worker_ids' => [$amara]]), 409);
Rgt::same('nothing was offered for it', (int) $one($db, "SELECT COUNT(*) FROM shift_offers WHERE shift_id = $draft"), 0);

$pub = $admin->post('/scheduling.php?action=publish', ['from' => $day(20), 'to' => $day(20)]);
Rgt::status('publishing the day', $pub, 200);
Rgt::ok('publishes the draft', (int) $pub->get('published') >= 1);
Rgt::same('it is published', (int) $one($db, "SELECT is_draft FROM shifts WHERE id = $draft"), 0);
Rgt::ok('publishing is audited', (int) $one($db, "SELECT COUNT(*) FROM audit_log WHERE action = 'rota.publish'") >= 1);
$me = $amaraApp->get('/me.php');
Rgt::ok('now the worker sees it as an open shift', in_array($draft, array_column($me->get('open_shifts', []), 'id'), true));

$before = $notes($db, $amaraUser, 'New shift');
$mailBefore = (int) $one($db, "SELECT COUNT(*) FROM email_queue WHERE related_type = 'shift' AND related_id = $draft");
Rgt::status('the office assigns Amara', $admin->post("/scheduling.php?action=assign&id=$draft", ['worker_id' => $amara]), 200);
Rgt::same('she is told in the app', $notes($db, $amaraUser, 'New shift'), $before + 1);
Rgt::same('and by email', (int) $one($db, "SELECT COUNT(*) FROM email_queue WHERE related_type = 'shift' AND related_id = $draft"), $mailBefore + 1);
Rgt::ok('her rota shows it', in_array($draft, array_column($amaraApp->get('/me.php')->get('shifts', []), 'id'), true));

Rgt::status('cancelling needs a reason', $admin->post("/scheduling.php?action=cancel&id=$draft", []), 400);
$before = $notes($db, $amaraUser, 'Shift cancelled');
$res = $admin->post("/scheduling.php?action=cancel&id=$draft", ['reason' => 'The home has covered it internally']);
Rgt::status('cancelling with a reason', $res, 200);
Rgt::same('records the reason on the shift', $one($db, "SELECT cancel_reason FROM shifts WHERE id = $draft"), 'The home has covered it internally');
Rgt::same('tells the worker', $notes($db, $amaraUser, 'Shift cancelled'), $before + 1);
Rgt::ok('with the reason in the notice', str_contains((string) $one($db,
    "SELECT body FROM notifications WHERE user_id = $amaraUser AND title = 'Shift cancelled' ORDER BY id DESC LIMIT 1"),
    'The home has covered it internally'));
$hist = $admin->get("/scheduling.php?id=$draft");
$actions = array_column($hist->get('history', []), 'action');
Rgt::ok('the history has create, publish, assign and cancel',
    !array_diff(['shift.create', 'shift.publish', 'shift.assign', 'shift.cancel'], $actions), implode(', ', $actions));
Rgt::same('and the shift reads as cancelled', $hist->get('shift.state'), 'cancelled');

// A change after publishing tells the worker what changed.
$res = $create($admin, ['worker_id' => $amara, 'site_id' => $oakleaf, 'starts_at' => "{$day(21)} 08:00", 'ends_at' => "{$day(21)} 16:00"]);
$moved = (int) $res->get('id');
$before = $notes($db, $amaraUser, 'Your shift has changed');
$admin->post("/scheduling.php?action=update&id=$moved", ['starts_at' => "{$day(21)} 09:00"]);
Rgt::same('editing a draft tells nobody', $notes($db, $amaraUser, 'Your shift has changed'), $before);
$admin->post('/scheduling.php?action=publish', ['ids' => [$moved]]);
$res = $admin->post("/scheduling.php?action=update&id=$moved", ['ends_at' => "{$day(21)} 17:00"]);
Rgt::status('editing a published shift', $res, 200);
Rgt::same('tells the worker', $notes($db, $amaraUser, 'Your shift has changed'), $before + 1);
Rgt::ok('what changed', str_contains((string) $one($db,
    "SELECT body FROM notifications WHERE user_id = $amaraUser AND title = 'Your shift has changed' ORDER BY id DESC LIMIT 1"),
    'Time:'));
Rgt::ok('the edit is audited with before and after', (int) $one($db,
    "SELECT COUNT(*) FROM audit_log WHERE action = 'shift.edit' AND entity_id = $moved AND after_json LIKE '%17:00%'") === 1);

// ---------------------------------------------------------------------------
Rgt::group('rota: approved leave blocks every path');

$leaveFrom = $day(30);
$leaveTo = $day(31);
$db->query("INSERT INTO leave_records (person_id, kind, status, starts_on, ends_on, hours, leave_year)
            VALUES ($amaraPerson, 'annual', 'approved', '$leaveFrom', '$leaveTo', 15, '2026/27')");

$res = $create($admin, ['worker_id' => $amara, 'starts_at' => "$leaveFrom 08:00", 'ends_at' => "$leaveFrom 16:00"]);
Rgt::status('creating a shift for her on leave is refused', $res, 409);
Rgt::ok('saying it is leave', str_contains($res->error(), 'leave'), $res->error());
Rgt::same('with the reason as a blocking code', $res->get('check.blocking.0.code'), 'leave');

$open = (int) $create($admin, ['starts_at' => "$leaveFrom 08:00", 'ends_at' => "$leaveFrom 16:00"])->get('id');
Rgt::status('assigning her is refused', $admin->post("/scheduling.php?action=assign&id=$open", ['worker_id' => $amara]), 409);
Rgt::same('and is audited on the shift', (int) $one($db,
    "SELECT COUNT(*) FROM audit_log WHERE action = 'shift.blocked' AND entity_id = $open"), 1);
$check = $admin->get("/scheduling.php?action=check&id=$open&worker_id=$amara");
Rgt::same('the check says why before anyone presses assign', $check->get('check.ok'), false);
Rgt::ok('find eligible leaves her out',
    !in_array($amara, array_column($admin->get("/scheduling.php?action=match&id=$open")->get('workers', []), 'id'), true));

// A night shift the evening before runs into her first day off.
$night = (int) $create($admin, ['starts_at' => $day(29) . ' 20:00', 'ends_at' => "$leaveFrom 08:00"])->get('id');
Rgt::status('a night shift into the first day of leave is refused',
    $admin->post("/scheduling.php?action=assign&id=$night", ['worker_id' => $amara]), 409);

$admin->post('/scheduling.php?action=publish', ['ids' => [$open]]);
$res = $amaraApp->post("/me.php?action=accept&shift_id=$open");
Rgt::status('she cannot claim it in the app', $res, 409);
Rgt::ok('and is told why', str_contains($res->error(), 'leave'), $res->error());

$offer = $admin->post("/scheduling.php?action=offer&id=$open", ['worker_ids' => [$amara]]);
Rgt::same('offering it to her by name offers nothing', (int) $offer->get('offered'), 0);
Rgt::ok('and says why', str_contains((string) $offer->get('refused.0.reason'), 'leave'), $offer->body);

$db->query('DELETE FROM rate_limits');
$admin->post("/scheduling.php?action=broadcast&id=$open");
Rgt::same('a broadcast does not reach her', (int) $one($db,
    "SELECT COUNT(*) FROM shift_offers WHERE shift_id = $open AND worker_id = $amara"), 0);

// An offer sent before the leave was approved is refused when she replies.
$token = bin2hex(random_bytes(20));
$db->query("INSERT INTO shift_offers (shift_id, worker_id, token, status, expires_at)
            VALUES ($open, $amara, '$token', 'sent', '$leaveFrom 08:00:00')");
$res = $client->post("/offer.php?token=$token", ['action' => 'accept']);
Rgt::status('accepting an old offer by link is refused', $res, 409);
Rgt::same('as blocked', $res->get('result'), 'blocked');
Rgt::same('and the shift is still open', $one($db, "SELECT status FROM shifts WHERE id = $open"), 'open');

// Leave approved after she was booked shows on the rota as a conflict.
$booked = (int) $create($admin, ['worker_id' => $amara, 'starts_at' => $day(33) . ' 08:00', 'ends_at' => $day(33) . ' 16:00'])->get('id');
$db->query("INSERT INTO leave_records (person_id, kind, status, starts_on, ends_on, hours, leave_year)
            VALUES ($amaraPerson, 'annual', 'approved', '{$day(33)}', '{$day(33)}', 7.5, '2026/27')");
$week = $admin->get("/scheduling.php?from={$day(33)}&to={$day(33)}");
$row = array_values(array_filter($week->get('shifts', []), static fn($s) => $s['id'] === $booked))[0] ?? [];
Rgt::same('leave approved later shows on the shift as a conflict', $row['conflicts'][0]['code'] ?? null, 'leave');

// ---------------------------------------------------------------------------
Rgt::group('rota: warnings need acknowledging');

$res = $amaraApp->post('/me.php?action=availability', [
    'starts_at' => $day(40) . ' 00:00', 'ends_at' => $day(40) . ' 23:59', 'note' => 'Hospital appointment',
]);
Rgt::status('a worker marks a day unavailable', $res, 201);
$blockId = (int) $res->get('id');
Rgt::ok('and can see it', in_array($blockId, array_column($amaraApp->get('/me.php?action=availability')->get('unavailable', []), 'id'), true));

$warned = (int) $create($admin, ['starts_at' => $day(40) . ' 08:00', 'ends_at' => $day(40) . ' 16:00'])->get('id');
$res = $admin->post("/scheduling.php?action=assign&id=$warned", ['worker_id' => $amara]);
Rgt::status('assigning her that day is held', $res, 409);
Rgt::same('for acknowledgement', $res->get('needs_acknowledgement'), true);
Rgt::same('naming the warning', $res->get('check.warnings.0.code'), 'unavailable');
Rgt::same('nothing was written', $one($db, "SELECT worker_id FROM shifts WHERE id = $warned"), null);
$res = $admin->post("/scheduling.php?action=assign&id=$warned", ['worker_id' => $amara, 'acknowledge_warnings' => true]);
Rgt::status('acknowledged, she is booked', $res, 200);
Rgt::same('and the acknowledgement is audited', (int) $one($db,
    "SELECT COUNT(*) FROM audit_log WHERE action = 'shift.warnings_acknowledged' AND entity_id = $warned"), 1);

// Rest gap: a night shift, then an afternoon shift eight hours after it ends.
$create($admin, ['worker_id' => $amara, 'starts_at' => $day(50) . ' 20:00', 'ends_at' => $day(51) . ' 08:00']);
$after = (int) $create($admin, ['starts_at' => $day(51) . ' 16:00', 'ends_at' => $day(51) . ' 22:00'])->get('id');
$res = $admin->post("/scheduling.php?action=assign&id=$after", ['worker_id' => $amara]);
Rgt::status('eight hours of rest is held for acknowledgement', $res, 409);
Rgt::same('as a rest warning', $res->get('check.warnings.0.code'), 'rest');
Rgt::ok('saying how long', str_contains((string) $res->get('check.warnings.0.reason'), 'Only 8h rest'), $res->body);

Rgt::denied('a manager cannot change the minimum rest', $scoped->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 6]));
$res = $admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 6]);
Rgt::status('an admin can', $res, 200);
Rgt::same('it is stored as configuration', $one($db, "SELECT value FROM app_settings WHERE name = 'rest_gap_min_hours'"), '6');
Rgt::status('with six hours as the minimum, eight is fine',
    $admin->post("/scheduling.php?action=assign&id=$after", ['worker_id' => $amara]), 200);
$admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 11]);
Rgt::status('nonsense is refused', $admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 99]), 400);

// A worker choosing a shift is not held by a warning, but the office is told.
$db->query('DELETE FROM rate_limits');
$early = (int) $create($admin, ['starts_at' => $day(52) . ' 06:00', 'ends_at' => $day(52) . ' 10:00'])->get('id');
$create($admin, ['worker_id' => $amara, 'starts_at' => $day(51) . ' 23:00', 'ends_at' => $day(52) . ' 02:00']);
$admin->post('/scheduling.php?action=publish', ['ids' => [$early]]);
$res = $amaraApp->post("/me.php?action=accept&shift_id=$early");
Rgt::status('a worker can claim a shift with a short rest gap', $res, 200);
Rgt::same('and is shown the warning', $res->get('warnings.0.code'), 'rest');

// ---------------------------------------------------------------------------
Rgt::group('rota: a site\'s own requirements are part of eligibility');

$firstAid = (int) $one($db, "SELECT id FROM compliance_requirements WHERE req_key = 'first_aid'");
$res = $admin->post('/sites.php', [
    'client_id' => $oakleafClient, 'name' => 'Oakleaf — Garden Wing', 'postcode' => 'e1 1ab',
    'access_notes' => 'Ring the bell at the side gate', 'contact_name' => 'Nurse in charge', 'contact_phone' => '020 7123 4001',
    'requirement_ids' => [$firstAid],
]);
Rgt::status('the office adds a site with a training requirement', $res, 201);
$wing = (int) $res->get('id');
Rgt::same('postcode tidied', $one($db, "SELECT postcode FROM sites WHERE id = $wing"), 'E1 1AB');
Rgt::status('a second site with the same name is refused',
    $admin->post('/sites.php', ['client_id' => $oakleafClient, 'name' => 'Oakleaf — Garden Wing']), 409);
Rgt::status('a phone nobody could ring is refused',
    $admin->post('/sites.php', ['client_id' => $oakleafClient, 'name' => 'Annexe', 'contact_phone' => 'call reception']), 400);
$list = $admin->get("/sites.php?client_id=$oakleafClient");
$wingRow = array_values(array_filter($list->get('sites', []), static fn($s) => $s['id'] === $wing))[0] ?? [];
Rgt::same('the site lists its requirement', array_column($wingRow['requirements'] ?? [], 'req_key'), ['first_aid']);
Rgt::denied('a worker login cannot read sites (access notes can be door codes)', $amaraApp->get('/sites.php'));

$atWing = (int) $create($admin, ['site_id' => $wing, 'starts_at' => $day(60) . ' 08:00', 'ends_at' => $day(60) . ' 16:00'])->get('id');
$res = $admin->post("/scheduling.php?action=assign&id=$atWing", ['worker_id' => $amara]);
Rgt::status('Amara has no first aid on file, so the site refuses her', $res, 409);
Rgt::same('as evidence', $res->get('check.blocking.0.code'), 'evidence');
Rgt::ok('naming the requirement', in_array('First aid', array_column($res->get('check.eligibility.blocking', []), 'requirement'), true), $res->body);
$plain = (int) $create($admin, ['site_id' => $oakleaf, 'starts_at' => $day(60) . ' 08:00', 'ends_at' => $day(60) . ' 16:00'])->get('id');
Rgt::status('the same day at a site without it is fine',
    $admin->post("/scheduling.php?action=assign&id=$plain", ['worker_id' => $amara]), 200);
Rgt::ok('the service verdict on record is not the site\'s', in_array(
    $one($db, "SELECT eligibility_status FROM person_services WHERE person_id = $amaraPerson AND service = 'care'"),
    ['eligible', 'eligible_with_warnings'], true));

Rgt::status('dropping the requirement', $admin->post("/sites.php?action=update&id=$wing", ['requirement_ids' => []]), 200);
$admin->post("/scheduling.php?action=unassign&id=$plain");
Rgt::status('lets her work there', $admin->post("/scheduling.php?action=assign&id=$atWing", ['worker_id' => $amara]), 200);
Rgt::status('a closed site takes no new shifts', (function () use ($admin, $wing, $create, $day) {
    $admin->post("/sites.php?action=update&id=$wing", ['active' => false]);
    return $create($admin, ['site_id' => $wing, 'starts_at' => $day(61) . ' 08:00', 'ends_at' => $day(61) . ' 16:00']);
})(), 409);

// ---------------------------------------------------------------------------
Rgt::group('rota: copy last week');

$weekA = date('Y-m-d', strtotime('monday next week +10 weeks'));
$weekB = date('Y-m-d', strtotime("$weekA +7 days"));
$create($admin, ['site_id' => $oakleaf, 'title' => 'Copy me', 'starts_at' => "$weekA 08:00", 'ends_at' => "$weekA 16:00"]);
$create($admin, ['worker_id' => $amara, 'site_id' => $oakleaf, 'title' => 'Copy me too', 'starts_at' => date('Y-m-d', strtotime("$weekA +1 day")) . ' 08:00', 'ends_at' => date('Y-m-d', strtotime("$weekA +1 day")) . ' 16:00']);
$res = $admin->post('/scheduling.php?action=copy_week', ['week_start' => $weekB]);
Rgt::status('copying last week', $res, 200);
Rgt::same('creates both', (int) $res->get('created'), 2);
Rgt::same('as drafts', (int) $one($db, "SELECT COUNT(*) FROM shifts WHERE title LIKE 'Copy me%' AND starts_at >= '$weekB' AND is_draft = 1"), 2);
Rgt::same('keeping the worker who is free', (int) $one($db,
    "SELECT worker_id FROM shifts WHERE title = 'Copy me too' AND starts_at >= '$weekB'"), $amara);
Rgt::same('copying again skips what is already there', (int) $admin->post('/scheduling.php?action=copy_week', ['week_start' => $weekB])->get('skipped'), 2);

// ---------------------------------------------------------------------------
Rgt::group('rota: workers never see drafts');

$hidden = (int) $create($admin, ['worker_id' => $amara, 'starts_at' => $day(70) . ' 08:00', 'ends_at' => $day(70) . ' 16:00'])->get('id');
Rgt::ok('an assigned draft is not on the worker\'s rota',
    !in_array($hidden, array_column($amaraApp->get('/me.php')->get('shifts', []), 'id'), true));
Rgt::status('they cannot clock in on it', $amaraApp->post('/field.php', ['shift_id' => $hidden, 'kind' => 'clock_in']), 403);
Rgt::status('nor read its trail', $amaraApp->get("/field.php?shift_id=$hidden"), 403);

// ---------------------------------------------------------------------------
Rgt::group('rota: service scope');

$list = $scoped->get("/scheduling.php?from={$day(0)}&to={$day(80)}");
Rgt::status('a scoped manager reads the rota', $list, 200);
Rgt::same('only their own service', array_values(array_unique(array_column($list->get('shifts', []), 'division'))), ['security']);
Rgt::denied('and cannot open a care shift by id', $scoped->get("/scheduling.php?id=$hidden"));
Rgt::denied('or cancel one', $scoped->post("/scheduling.php?action=cancel&id=$hidden", ['reason' => 'x']));
Rgt::denied('or create one', $create($scoped, ['starts_at' => $day(71) . ' 08:00', 'ends_at' => $day(71) . ' 16:00']));
Rgt::denied('a worker login cannot read the rota', $amaraApp->get('/scheduling.php'));

// ---------------------------------------------------------------------------
Rgt::group('rota: timesheets from the clock');

$worked = (int) $create($admin, ['worker_id' => $amara, 'site_id' => $oakleaf, 'starts_at' => $day(75) . ' 08:00', 'ends_at' => $day(75) . ' 16:00'])->get('id');
$admin->post('/scheduling.php?action=publish', ['ids' => [$worked]]);
// Clocking in opens fifteen minutes before the start (S4), so a shift weeks
// ahead cannot be clocked in on. Move this one to now, in London wall-clock time.
Rgt::status('not weeks early', $amaraApp->post('/field.php', ['shift_id' => $worked, 'kind' => 'clock_in']), 409);
$nowLondon = new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
$db->query("UPDATE shifts SET starts_at = '" . $nowLondon->format('Y-m-d H:i:s') . "', ends_at = '"
    . $nowLondon->modify('+8 hours')->format('Y-m-d H:i:s') . "' WHERE id = $worked");
Rgt::status('she clocks in', $amaraApp->post('/field.php', ['shift_id' => $worked, 'kind' => 'clock_in']), 201);
// Eight hours pass.
$db->query("UPDATE shift_events SET created_at = DATE_SUB(created_at, INTERVAL 8 HOUR) WHERE shift_id = $worked AND kind = 'clock_in'");
Rgt::status('and out', $amaraApp->post('/field.php', ['shift_id' => $worked, 'kind' => 'clock_out']), 201);
$tsId = (int) $one($db, "SELECT id FROM timesheets WHERE shift_id = $worked");
Rgt::ok('a timesheet is made from the clock', $tsId > 0);
Rgt::same('as a draft', $one($db, "SELECT status FROM timesheets WHERE id = $tsId"), 'draft');
Rgt::same('from the clock', $one($db, "SELECT source FROM timesheets WHERE id = $tsId"), 'clock');
require_once dirname(__DIR__, 3) . '/pure.php';   // rg_shift_minutes(), the rule the timesheet uses
// Eight hours of real time, read on the London wall clock the timesheet uses.
// That is 480 minutes on every night of the year but two: across a clock change
// the wall clock shows seven or nine. Worked out from the stored instants rather
// than written as 480, so the case passes whatever the hour or date it runs
// (it used to depend on the time of day; see the correction below too).
$instants = $db->query("SELECT MIN(CASE WHEN kind = 'clock_in' THEN created_at END), MAX(CASE WHEN kind = 'clock_out' THEN created_at END)
                          FROM shift_events WHERE shift_id = $worked")->fetch_row();
$london = static fn(string $utc): DateTimeImmutable => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/London'));
$wallIn = $london((string) $instants[0]);
$wallOut = $london((string) $instants[1]);
$expectedMinutes = rg_shift_minutes($wallIn->format('Y-m-d'), $wallIn->format('H:i'), $wallOut->format('H:i'));
Rgt::ok('eight hours of real time', abs(strtotime((string) $instants[1]) - strtotime((string) $instants[0]) - 8 * 3600) < 120);
Rgt::same('priced on the wall clock', (int) $one($db, "SELECT minutes FROM timesheets WHERE id = $tsId"), $expectedMinutes);
Rgt::same('nothing approved, so nothing paid', (int) $one($db, "SELECT COUNT(*) FROM timesheets WHERE id = $tsId AND status = 'approved'"), 0);

Rgt::ok('she sees it', in_array($tsId, array_column($amaraApp->get('/me.php?action=timesheets')->get('timesheets', []), 'id'), true));
Rgt::status('a break as long as the shift is refused',
    $amaraApp->post("/me.php?action=submit_timesheet&id=$tsId", ['break_minutes' => 480]), 400);
Rgt::status('she submits it with her break', $amaraApp->post("/me.php?action=submit_timesheet&id=$tsId", ['break_minutes' => 30]), 200);
Rgt::same('now waiting for the office', $one($db, "SELECT status FROM timesheets WHERE id = $tsId"), 'pending');
Rgt::status('it cannot be submitted twice', $amaraApp->post("/me.php?action=submit_timesheet&id=$tsId"), 409);
Rgt::denied('an office login is not a worker', $admin->post("/me.php?action=submit_timesheet&id=$tsId"));

Rgt::status('a query needs a reason', $admin->post("/timesheets.php?action=query&id=$tsId", []), 400);
$before = $notes($db, $amaraUser, 'Timesheet queried');
Rgt::status('the office queries it', $admin->post("/timesheets.php?action=query&id=$tsId", ['note' => 'Did you take a longer break?']), 200);
Rgt::same('it is queried', $one($db, "SELECT status FROM timesheets WHERE id = $tsId"), 'queried');
Rgt::same('she is told', $notes($db, $amaraUser, 'Timesheet queried'), $before + 1);
$mine = array_values(array_filter($amaraApp->get('/me.php?action=timesheets')->get('timesheets', []), static fn($t) => $t['id'] === $tsId))[0] ?? [];
Rgt::same('and sees the reason', $mine['decision_note'] ?? null, 'Did you take a longer break?');
Rgt::status('she answers by submitting again', $amaraApp->post("/me.php?action=submit_timesheet&id=$tsId", ['break_minutes' => 45]), 200);

Rgt::status('a correction needs a reason', $admin->post("/timesheets.php?action=correct&id=$tsId", ['end_time' => '15:30']), 400);
// The clock times are now minus eight hours, so after about 22:45 London time a
// lone end of 15:30 left less than the 45-minute break and the correction was
// refused. The start is set too, so the case does not depend on the hour it runs.
Rgt::status('the office corrects it', $admin->post("/timesheets.php?action=correct&id=$tsId",
    ['start_time' => '07:30', 'end_time' => '15:30', 'reason' => 'Left early, agreed with the home']), 200);
$detail = $admin->get("/timesheets.php?id=$tsId");
$corr = array_values(array_filter($detail->get('history', []), static fn($h) => $h['action'] === 'timesheet.correct'))[0] ?? [];
Rgt::same('history keeps the value before', $corr['after']['end_time'] ?? null, '15:30');
Rgt::ok('and after', ($corr['before']['end_time'] ?? null) !== null && ($corr['before']['end_time'] ?? null) !== '15:30');
Rgt::same('and why', $corr['reason'] ?? null, 'Left early, agreed with the home');
Rgt::status('the office approves it', $admin->post("/timesheets.php?action=approve&id=$tsId"), 200);
Rgt::status('approved hours are not corrected in place', $admin->post("/timesheets.php?action=correct&id=$tsId",
    ['end_time' => '15:00', 'reason' => 'x']), 409);
Rgt::denied('a scoped security manager cannot touch a care timesheet', $scoped->post("/timesheets.php?action=reject&id=$tsId", ['note' => 'x']));
// The hours are dated by when the clock events happened (today, in this test),
// not by the rota entry's planned date.
$workDate = (string) $one($db, "SELECT work_date FROM timesheets WHERE id = $tsId");
$filtered = $admin->get("/timesheets.php?status=approved&division=care&from=$workDate&to=$workDate");
Rgt::ok('the office list filters by week, service and status',
    in_array($tsId, array_column($filtered->get('timesheets', []), 'id'), true), $filtered->body);

// ---------------------------------------------------------------------------
Rgt::group('rota: shifts from a cover request');

$db->query('DELETE FROM rate_limits');
$client->post('/bookings.php', [
    'contact_name' => 'Rota Test Home', 'email' => 'rota.test@example.test', 'division' => 'care',
    'detail' => 'Two day shifts please', 'consent' => true,
]);
$reqId = (int) $one($db, "SELECT id FROM service_requests WHERE email = 'rota.test@example.test' ORDER BY id DESC LIMIT 1");
$res = $admin->post("/bookings.php?action=confirm&id=$reqId", [
    'site_id' => $oakleaf, 'slots' => [
        ['starts_at' => $day(80) . ' 08:00', 'ends_at' => $day(80) . ' 16:00'],
        ['starts_at' => $day(81) . ' 08:00', 'ends_at' => $day(81) . ' 16:00'],
    ],
]);
Rgt::status('the office creates shifts from the request', $res, 201);
$ids = array_map('intval', $res->get('shift_ids', []));
Rgt::same('two of them', count($ids), 2);
Rgt::same('both drafts at the site', (int) $one($db,
    'SELECT COUNT(*) FROM shifts WHERE is_draft = 1 AND site_id = ' . $oakleaf . ' AND id IN (' . implode(',', $ids ?: [0]) . ')'), 2);
Rgt::status('a second time is refused', $admin->post("/bookings.php?action=confirm&id=$reqId", []), 409);

// ---------------------------------------------------------------------------
Rgt::group('rota: requests in one list, with an owner');

$adminId = (int) $one($db, "SELECT id FROM users WHERE email = 'admin@local.invalid'");
$list = $admin->get('/requests.php');
Rgt::status('the office reads requests', $list, 200);
$types = array_values(array_unique(array_column($list->get('requests', []), 'type')));
sort($types);
Rgt::same('cover requests and enquiries together', $types, ['cover', 'enquiry']);
Rgt::status('an owner can be set', $admin->post("/requests.php?action=owner&type=cover&id=$reqId", ['user_id' => $adminId]), 200);
$mine = array_values(array_filter($admin->get('/requests.php?type=cover')->get('requests', []), static fn($r) => $r['id'] === $reqId))[0] ?? [];
Rgt::same('and is shown', $mine['owner_id'] ?? null, $adminId);
Rgt::status('a worker login cannot own a request',
    $admin->post("/requests.php?action=owner&type=cover&id=$reqId", ['user_id' => $amaraUser]), 422);
Rgt::denied('nor read them', $amaraApp->get('/requests.php'));
$sec = $scoped->get('/requests.php');
Rgt::ok('a scoped manager sees only their service',
    !array_diff(array_unique(array_column($sec->get('requests', []), 'division')), ['security']), $sec->body);
