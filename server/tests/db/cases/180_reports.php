<?php
// Reports (section S5): one page of reliable reports. Totals over the whole
// filtered dataset, scope and zones applied, exports built on the server with
// the declared columns only and recorded in export_log.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$manager = $client->login('manager@local.invalid');   // manager, care, not scoped
$scoped = $client->login('scoped@local.invalid');     // manager, security, scoped
$editor = $client->login('editor@local.invalid');

$one = static fn(string $sql): int => (int) ($db->query($sql)->fetch_row()[0] ?? 0);

Rgt::group('reports: who may open them');

$list = $admin->get('/reports.php?action=list');
Rgt::status('an admin lists the reports', $list, 200);
Rgt::same('all six', array_column($list->get('reports', []), 'key'),
    ['pipeline', 'workforce', 'expiring', 'unfilled', 'hours', 'workload']);
Rgt::denied('an editor cannot', $editor->get('/reports.php?action=list'));
Rgt::denied('nor run one', $editor->get('/reports.php?action=run&report=pipeline'));
Rgt::denied('nor export one', $editor->get('/reports.php?action=export&report=pipeline'));

// A manager without the payroll zone loses the hours report, and only that.
$db->query("UPDATE zone_permissions SET can_view = 0 WHERE role_key = 'manager' AND zone = 'payroll'");
$noPay = $manager->get('/reports.php?action=list');
Rgt::ok('without the payroll zone the hours report is not listed',
    !in_array('hours', array_column($noPay->get('reports', []), 'key'), true));
Rgt::denied('and cannot be run', $manager->get('/reports.php?action=run&report=hours'));
Rgt::denied('or exported', $manager->get('/reports.php?action=export&report=hours'));
$db->query("UPDATE zone_permissions SET can_view = 1 WHERE role_key = 'manager' AND zone = 'payroll'");

Rgt::group('reports: totals count the whole filtered dataset');

$p = $admin->get('/reports.php?action=run&report=pipeline');
Rgt::status('the pipeline runs', $p, 200);
$dbPipe = $one("SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL
    AND (stage NOT IN ('rejected','withdrawn','onboarded') OR updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))");
Rgt::same('its total is every matching candidate', $p->get('totals.candidates'), $dbPipe);
Rgt::same('and equals the sum of its rows', array_sum(array_column($p->get('rows', []), 'candidates')), $dbPipe);

$assessedBefore = (string) $db->query('SELECT CONCAT(COUNT(*), "|", COALESCE(MAX(assessed_at), "")) FROM person_services')->fetch_row()[0];
$w = $admin->get('/reports.php?action=run&report=workforce');
Rgt::same('running it writes nothing about anybody (screening, not deciding)',
    (string) $db->query('SELECT CONCAT(COUNT(*), "|", COALESCE(MAX(assessed_at), "")) FROM person_services')->fetch_row()[0], $assessedBefore);
Rgt::status('the workforce report runs', $w, 200);
Rgt::same('it lists every current worker',
    $w->get('totals.workers'), $one("SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND status NOT IN ('left','archived')"));
Rgt::same('deployable plus blocked is the whole', $w->get('totals.deployable') + $w->get('totals.blocked'), $w->get('totals.workers'));
$blockedRows = array_filter($w->get('rows', []), static fn($r) => !$r['deployable']);
Rgt::ok('every blocked worker carries a reason',
    $blockedRows !== [] && array_filter($blockedRows, static fn($r) => ($r['reason'] ?? '') === '') === []);

foreach (['expiring', 'unfilled', 'hours', 'workload'] as $key) {
    $r = $admin->get("/reports.php?action=run&report=$key");
    Rgt::status("the $key report runs", $r, 200);
}

Rgt::group('reports: a scoped manager sees their own service');

foreach (['pipeline', 'workforce', 'expiring', 'unfilled'] as $key) {
    $r = $scoped->get("/reports.php?action=run&report=$key");
    $services = array_values(array_unique(array_column($r->get('rows', []), 'service')));
    Rgt::ok("$key: only security rows", $services === [] || $services === ['security'], json_encode($services));
}
$sp = $scoped->get('/reports.php?action=run&report=pipeline');
Rgt::same('the scoped pipeline total is security only', $sp->get('totals.candidates'), $one(
    "SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL AND division = 'security'
       AND (stage NOT IN ('rejected','withdrawn','onboarded') OR updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))"));
Rgt::denied('asking for another service is refused, not quietly widened',
    $scoped->get('/reports.php?action=run&report=pipeline&service=care'));
Rgt::denied('the care regulator pack is refused to a security-scoped manager',
    $scoped->get('/reports.php?action=cqc_pack'));
$narrow = $admin->get('/reports.php?action=run&report=pipeline&service=care');
Rgt::same('an admin can narrow to one service', array_values(array_unique(array_column($narrow->get('rows', []), 'service'))), ['care']);

Rgt::group('reports: export is the declared columns, and is recorded');

$before = $one("SELECT COUNT(*) FROM export_log WHERE export_type = 'report.workforce'");
$csv = $admin->get('/reports.php?action=export&report=workforce&purpose=' . rawurlencode('Monthly owner review'));
Rgt::status('the workforce export downloads', $csv, 200);
Rgt::ok('as CSV', str_starts_with($csv->headers['content-type'] ?? '', 'text/csv'), $csv->headers['content-type'] ?? '');
$lines = preg_split("/\r\n/", trim(substr($csv->body, 3)));
Rgt::same('with the declared header row', $lines[0], '"Name","Role","Service","Status","Can work","Why not"');
Rgt::same('and one line per worker', count($lines) - 1, (int) $w->get('totals.workers'));
$email = (string) $db->query("SELECT email FROM workers WHERE deleted_at IS NULL AND email IS NOT NULL LIMIT 1")->fetch_row()[0];
Rgt::ok('no contact details leave in it', !str_contains($csv->body, $email) && !str_contains($csv->body, '07700'));
Rgt::same('one export_log row was written', $one("SELECT COUNT(*) FROM export_log WHERE export_type = 'report.workforce'"), $before + 1);
$log = $db->query("SELECT record_count, purpose, contains_personal_data, scope, user_id FROM export_log
                   WHERE export_type = 'report.workforce' ORDER BY id DESC LIMIT 1")->fetch_assoc();
Rgt::same('recording how many rows, why, and that it names people',
    [(int) $log['record_count'], $log['purpose'], (int) $log['contains_personal_data'], $log['scope']],
    [(int) $w->get('totals.workers'), 'Monthly owner review', 1, 'report=workforce; service=all']);
Rgt::same('and who', (int) $log['user_id'], $one("SELECT id FROM users WHERE email = 'admin@local.invalid'"));

$scopedCsv = $scoped->get('/reports.php?action=export&report=unfilled');
Rgt::status('a scoped export downloads', $scopedCsv, 200);
Rgt::same('and is recorded with its scope', (string) $db->query(
    "SELECT scope FROM export_log WHERE export_type = 'report.unfilled' ORDER BY id DESC LIMIT 1")->fetch_row()[0],
    'report=unfilled; service=security');
Rgt::same('an aggregate export is recorded as not naming people', $one(
    "SELECT contains_personal_data FROM export_log WHERE export_type = 'report.unfilled' ORDER BY id DESC LIMIT 1"), 0);

$refusedBefore = $one('SELECT COUNT(*) FROM export_log');
$editor->get('/reports.php?action=export&report=workforce');
Rgt::same('a refused export writes nothing to the log', $one('SELECT COUNT(*) FROM export_log'), $refusedBefore);
