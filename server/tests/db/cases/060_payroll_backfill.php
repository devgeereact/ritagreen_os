<?php
// Payroll numbers: allocated once, never reissued, and never colliding.
//
// A payroll number is the permanent identifier the company knows somebody by.
// Migration 001 added workers.payroll_no as NULLable with a unique key, so every
// worker created before it has none, and nothing filled them in.

/** @var RgtClient $client */
/** @var mysqli $db */

$root = dirname(__DIR__, 3);                        // server/
$php  = escapeshellarg(PHP_BINARY);
// RG_CONFIG is already in the environment (run.php putenv's it), so these run
// against the same disposable database and not against anybody's config.php.
$backfill = static fn (string $args = '') => (string) shell_exec(
    "$php " . escapeshellarg($root . '/backfill-payroll.php') . " $args 2>&1"
);

Rgt::group('payroll numbers — the fixtures look like the application');

Rgt::same('every seeded worker has a payroll number',
    (int) $db->query('SELECT COUNT(*) FROM workers WHERE payroll_no IS NULL')->fetch_row()[0], 0);
Rgt::same('and none is shared',
    (int) $db->query(
        'SELECT COUNT(*) FROM (SELECT payroll_no FROM workers
          WHERE payroll_no IS NOT NULL GROUP BY payroll_no HAVING COUNT(*) > 1) d'
    )->fetch_row()[0], 0);

Rgt::group('payroll numbers — the counter knows what is already allocated');

// Migration 032. Without it the counter starts at 1 on a database that already
// holds RG-000001, and the next conversion fails on the unique key.
$counter = (int) $db->query("SELECT val FROM counters WHERE name = 'payroll'")->fetch_row()[0];
$highest = (int) $db->query(
    "SELECT COALESCE(MAX(CAST(SUBSTRING(payroll_no, 4) AS UNSIGNED)), 0)
       FROM workers WHERE payroll_no REGEXP '^RG-[0-9]+$'"
)->fetch_row()[0];
Rgt::ok("the counter ($counter) is not behind the highest number allocated ($highest)", $counter >= $highest);

Rgt::group('payroll numbers — the backfill reports before it writes');

// A worker from before migration 001: no number at all.
$person = (int) $db->query('SELECT id FROM people ORDER BY id LIMIT 1')->fetch_row()[0];
$db->query(
    "INSERT INTO workers (person_id, name, email, phone, role, division, status)
     VALUES ($person, 'Legacy Unnumbered', 'legacy@example.test', '07700900931', 'carer', 'care', 'active')"
);
$legacyId = (int) $db->insert_id;

$report = $backfill();
Rgt::ok('the report names the worker without a number', str_contains($report, 'Legacy Unnumbered'), $report);
Rgt::ok('and says it has written nothing', str_contains($report, 'Report only'), $report);
Rgt::same('and nothing was written',
    $db->query("SELECT payroll_no FROM workers WHERE id = $legacyId")->fetch_row()[0], null);

Rgt::group('payroll numbers — the backfill allocates, once');

$out = $backfill('--go');
$allocated = (string) $db->query("SELECT payroll_no FROM workers WHERE id = $legacyId")->fetch_row()[0];
Rgt::ok("the worker now has a payroll number ($allocated)",
    preg_match('/^RG-\d{6}$/', $allocated) === 1, $out);
Rgt::ok('and it does not collide with one already in use',
    (int) $db->query(
        "SELECT COUNT(*) FROM workers WHERE payroll_no = '$allocated'"
    )->fetch_row()[0] === 1);

// Safe to run twice: the second run must not renumber anybody. The old number is
// already on payslips and in records, so reissuing one is worse than the gap.
$second = $backfill('--go');
Rgt::same('a second run changes nothing',
    (string) $db->query("SELECT payroll_no FROM workers WHERE id = $legacyId")->fetch_row()[0], $allocated);
Rgt::ok('and says there is nothing to do', str_contains($second, 'Nothing to do'), $second);

Rgt::group('payroll numbers — an archived worker keeps theirs');

// A leaver's number is still cited by the records of the work they did.
$db->query("UPDATE workers SET deleted_at = NOW() WHERE id = $legacyId");
$backfill('--go');
Rgt::same('archiving does not clear the payroll number',
    (string) $db->query("SELECT payroll_no FROM workers WHERE id = $legacyId")->fetch_row()[0], $allocated);
$db->query("UPDATE workers SET deleted_at = NULL WHERE id = $legacyId");
