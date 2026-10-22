<?php
// Allocate a payroll number to every worker that has none.
//
//     php server/backfill-payroll.php          report only (the default)
//     php server/backfill-payroll.php --go     allocate
//
// A payroll number is the permanent identifier the company knows somebody by.
// Migration 001 added workers.payroll_no as a NULLable column with a unique key,
// so every worker that existed before it has NULL -- and both paths that create
// a worker today (ats.php onboard, workers.php create) allocate one, which means
// a workforce can be split between those who have a number and those who do not
// with nothing to say so.
//
// Safe to run twice. It only ever fills a NULL, and it never changes a number
// that has been allocated: renumbering somebody is worse than leaving them
// unnumbered, because the old number is already on payslips and in records.
//
// Run migration 032 first. It sets the counter to the highest number already in
// use; without that this would start at RG-000001 and collide with the unique key
// on the first worker it reached.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("backfill-payroll.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$go = in_array('--go', array_slice($argv, 1), true);

echo "Database: " . DB_NAME . " on " . DB_HOST . (rg_is_local_database() ? " (local)" : " (REMOTE)") . "\n\n";

// Archived workers are included. A leaver's payroll number is still cited by the
// records of the work they did, and a gap in the sequence is not a tidier thing
// than a worker nobody can identify.
$res = $conn->query(
    'SELECT id, name, division, status, deleted_at
       FROM workers WHERE payroll_no IS NULL ORDER BY id'
);
$missing = [];
while ($row = $res->fetch_assoc()) $missing[] = $row;

$total = (int) $conn->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
$counter = $conn->query("SELECT val FROM counters WHERE name = 'payroll'")->fetch_row();
$counterVal = $counter ? (int) $counter[0] : null;

echo "Workers:            $total\n";
echo "Without a number:   " . count($missing) . "\n";
echo "Counter 'payroll':  " . ($counterVal === null ? "NOT SET — run migration 032 first" : $counterVal) . "\n\n";

if (!$missing) {
    echo "Nothing to do.\n";
    exit(0);
}

if ($counterVal === null) {
    exit("REFUSED: the payroll counter has not been seeded. Apply migration 032, then re-run.\n");
}

// A number already in use that the counter does not know about would collide on
// the unique key half way through. Check before writing anything rather than
// finding out on the fourth worker.
$highest = $conn->query(
    "SELECT COALESCE(MAX(CAST(SUBSTRING(payroll_no, 4) AS UNSIGNED)), 0)
       FROM workers WHERE payroll_no REGEXP '^RG-[0-9]+$'"
)->fetch_row()[0];
if ((int) $highest > $counterVal) {
    exit("REFUSED: RG-" . sprintf('%06d', (int) $highest) . " is in use but the counter is at $counterVal.\n"
        . "Apply migration 032, which raises the counter to the highest number allocated.\n");
}

foreach ($missing as $w) {
    $state = $w['deleted_at'] !== null ? 'archived' : $w['status'];
    echo sprintf("  #%-5d %-24s %-10s %s\n", $w['id'], $w['name'], $w['division'], $state);
}
echo "\n";

if (!$go) {
    echo "Report only. Re-run with --go to allocate.\n";
    exit(0);
}

$update = $conn->prepare('UPDATE workers SET payroll_no = ? WHERE id = ? AND payroll_no IS NULL');
$allocated = 0;
foreach ($missing as $w) {
    $no = next_payroll_no($conn);
    $id = (int) $w['id'];
    $update->bind_param('si', $no, $id);
    $update->execute();
    // payroll_no IS NULL in the WHERE clause makes this a no-op for a worker
    // another run has already numbered, so a second run cannot overwrite one.
    if ($update->affected_rows === 1) {
        echo "  #$id  $no  {$w['name']}\n";
        $allocated++;
    }
}

echo "\nAllocated $allocated payroll number(s).\n";
