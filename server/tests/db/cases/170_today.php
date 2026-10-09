<?php
// Today and the Action Centre (section S5): counts obey service scope and zones.
//
// Launch plan Phase 5: "Do not expose the existing Action Centre until its
// aggregate queries obey those boundaries". Every count was over the whole
// company for every member of staff.
//
// The figures below are compared with the database, filtered the way the
// account is filtered, rather than with fixed numbers: earlier cases change the
// seeded records, and the property under test is "the count is the scoped
// count", not "the count is 3".

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');     // manager, security, scoped
$editor = $client->login('editor@local.invalid');     // no compliance or payroll zone
$employee = $client->login('employee@local.invalid'); // compliance zone, no payroll

$one = static fn(string $sql): int => (int) ($db->query($sql)->fetch_row()[0] ?? 0);
$item = static function (RgtResponse $res, string $kind, string $titleContains): ?array {
    foreach ($res->get('items', []) as $it) {
        if ($it['kind'] === $kind && str_contains($it['title'], $titleContains)) return $it;
    }
    return null;
};
$countOf = static fn(?array $it): int => $it === null ? 0 : (int) $it['count'];

// Make sure both services have something to count, whatever earlier cases did.
$care = $one("SELECT id FROM workers WHERE division = 'care' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$sec  = $one("SELECT id FROM workers WHERE division = 'security' AND deleted_at IS NULL ORDER BY id LIMIT 1");
$db->query("UPDATE workers SET dbs_expiry = DATE_SUB(CURDATE(), INTERVAL 5 DAY) WHERE id = $care");
$db->query("UPDATE workers SET sia_expiry = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id = $sec");
$start = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
$end = date('Y-m-d H:i:s', strtotime('+1 day 18:00'));
foreach (['care', 'care', 'security'] as $div) {
    $db->query("INSERT INTO shifts (division, title, starts_at, ends_at, status, is_draft)
                VALUES ('$div', 'S5 probe', '$start', '$end', 'open', 0)");
}

$a = $admin->get('/action-centre.php');
$s = $scoped->get('/action-centre.php');
Rgt::group('today: the Action Centre answers every member of staff');
Rgt::status('an admin reads it', $a, 200);
Rgt::status('a scoped manager reads it', $s, 200);
Rgt::same('the scoped account is told its scope', $s->get('scope'), 'security');

Rgt::group('today: a scoped manager counts only their service');

$dbUnfilled = static fn(?string $div): int => $one(
    "SELECT COUNT(*) FROM shifts WHERE deleted_at IS NULL AND status = 'open'
       AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)" . ($div ? " AND division = '$div'" : ''));
Rgt::same('unfilled shifts within 3 days: admin sees the whole company',
    $countOf($item($a, 'scheduling', 'unfilled')), $dbUnfilled(null));
Rgt::same('and the scoped manager sees security only',
    $countOf($item($s, 'scheduling', 'unfilled')), $dbUnfilled('security'));
Rgt::ok('which is fewer than the whole company', $dbUnfilled('security') < $dbUnfilled(null));

$dbDbs = static fn(?string $div): int => $one(
    "SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND dbs_expiry < CURDATE()" . ($div ? " AND division = '$div'" : ''));
Rgt::same('expired DBS: admin', $countOf($item($a, 'compliance', 'DBS')), $dbDbs(null));
Rgt::same('expired DBS: scoped manager counts security only', $countOf($item($s, 'compliance', 'DBS')), $dbDbs('security'));

$dbSia = static fn(?string $div): int => $one(
    "SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND sia_expiry < CURDATE()" . ($div ? " AND division = '$div'" : ''));
Rgt::same('expired SIA: scoped manager sees their own', $countOf($item($s, 'compliance', 'SIA')), $dbSia('security'));

$dbApplied = static fn(?string $div): int => $one(
    "SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL AND stage = 'applied'" . ($div ? " AND division = '$div'" : ''));
Rgt::same('new applicants: scoped manager counts security only',
    $countOf($item($s, 'recruitment', 'new applicant')), $dbApplied('security'));
Rgt::same('new applicants: admin counts all', $countOf($item($a, 'recruitment', 'new applicant')), $dbApplied(null));

$tile = $s->get('tiles', [])['unfilled_shifts']['count'] ?? null;
Rgt::same('the unfilled tile is scoped too', $tile, $one(
    "SELECT COUNT(*) FROM shifts WHERE deleted_at IS NULL AND status = 'open' AND division = 'security'
       AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"));

// Every person named to the scoped account holds a security record.
$named = [];
foreach ($s->get('items', []) as $it) foreach ($it['people'] as $p) $named[] = (int) $p['id'];
$outside = array_filter($named, static fn(int $id): bool => $one(
    "SELECT COUNT(*) FROM (SELECT person_id FROM workers WHERE person_id = $id AND division = 'security' AND deleted_at IS NULL
       UNION SELECT person_id FROM applicants WHERE person_id = $id AND division = 'security' AND deleted_at IS NULL
       UNION SELECT person_id FROM person_services WHERE person_id = $id AND service = 'security' AND active = 1) x") === 0);
Rgt::same('nobody outside security is named to the scoped account', array_values($outside), []);

$kinds = array_column($s->get('items', []), 'kind');
Rgt::ok('no system-wide counts for a scoped account (email, form intake carry no service)',
    !in_array('system', $kinds, true) && !in_array('training', $kinds, true), implode(',', $kinds));

Rgt::group('today: zones decide which groups appear');

$e = $editor->get('/action-centre.php');
Rgt::status('an editor reads it', $e, 200);
$eKinds = array_column($e->get('items', []), 'kind');
$restricted = array_intersect($eKinds, ['eligibility', 'compliance', 'verification', 'right_to_work', 'timesheets', 'recruitment', 'scheduling']);
Rgt::same('an editor sees no compliance, hours, recruitment or rota items', array_values($restricted), []);
$eTiles = $e->get('tiles', []);
Rgt::ok('and no compliance tiles (present and null, so the page leaves them out)',
    array_key_exists('checks_to_review', $eTiles) && $eTiles['checks_to_review'] === null
    && array_key_exists('expiring_30', $eTiles) && $eTiles['expiring_30'] === null, json_encode($eTiles));
Rgt::ok('the refusal is silent rather than a leak: no person is named', !str_contains($e->body, '"people":[{'));

$db->query("INSERT INTO timesheets (worker_id, work_date, start_time, end_time, minutes, status)
            VALUES ($care, CURDATE(), '09:00', '17:00', 480, 'pending')");
$m = $employee->get('/action-centre.php');
Rgt::status('an employee reads it', $m, 200);
Rgt::ok('an employee (no payroll zone) sees no timesheet counts', !in_array('timesheets', array_column($m->get('items', []), 'kind'), true));
$a2 = $admin->get('/action-centre.php');
Rgt::same('but the admin does, over every pending timesheet',
    $countOf($item($a2, 'timesheets', 'waiting')),
    $one("SELECT COUNT(*) FROM timesheets t JOIN workers w ON w.id = t.worker_id WHERE t.status = 'pending' AND t.deleted_at IS NULL"));
$s2 = $scoped->get('/action-centre.php');
Rgt::same('and the scoped manager counts security hours only',
    $countOf($item($s2, 'timesheets', 'waiting')),
    $one("SELECT COUNT(*) FROM timesheets t JOIN workers w ON w.id = t.worker_id WHERE t.status = 'pending' AND t.deleted_at IS NULL AND w.division = 'security'"));
Rgt::ok('an employee keeps compliance items (they hold the zone)', ($m->get('tiles', [])['checks_to_review'] ?? null) !== null);

Rgt::group('today: the old dashboard summary obeys the same boundaries');

$d = $scoped->get('/dashboard.php?division=all');
Rgt::status('a scoped manager reads the summary', $d, 200);
Rgt::same('asking for every division still answers for security only',
    $d->get('open_shifts'), $one("SELECT COUNT(*) FROM shifts WHERE deleted_at IS NULL AND status = 'open' AND starts_at > NOW() AND division = 'security'"));
Rgt::same('and revenue covers security only', array_column($d->get('revenue_by_division', []), 'division'), ['security']);
$de = $editor->get('/dashboard.php');
Rgt::same('an editor is shown no expiring checks', $de->get('compliance_alerts'), []);
Rgt::same('and no money', [$de->get('outstanding_invoices_pence'), $de->get('revenue_by_division')], [0, []]);

$guest = $client->get('/action-centre.php');
Rgt::denied('nobody signed out reads it', $guest);

// Tidy up the probes so later cases see the fixture they expect.
$db->query("DELETE FROM shifts WHERE title = 'S5 probe'");
$db->query("DELETE FROM timesheets WHERE worker_id = $care AND work_date = CURDATE() AND minutes = 480 AND status = 'pending'");
