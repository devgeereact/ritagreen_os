<?php
// Backfill the person spine.
//
//     php server/backfill-people.php            dry run: report only
//     php server/backfill-people.php --go       create people and link records
//
// Run after migration 001 and before 002, which adds the foreign keys and will
// fail while any person_id is unresolved.
//
// The matching logic lives in pure.php and is covered by server/tests/run.php.
// It matches on email first, then phone, and NEVER on a name — merging every
// John Smith into one worker is worse than leaving two records unmerged.
//
// Where a group disagrees about who it is (two names sharing a landline, say),
// nothing is merged and the group is reported for a person to decide. A wrong
// merge joins two employment histories, which is far harder to undo than a
// duplicate.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("backfill-people.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
$go = in_array('--go', $args, true);
$productionFlag = in_array('--production', $args, true);

$localHosts = ['localhost', '127.0.0.1', '::1'];
$isLocal = in_array(DB_HOST, $localHosts, true);

// Refuse to run before the schema is ready, rather than failing halfway through.
if (!$conn->query("SHOW TABLES LIKE 'people'")->num_rows) {
    exit("REFUSED: the people table does not exist. Run: php server/migrate.php --go\n");
}

echo "Database: " . DB_NAME . " on " . DB_HOST . ($isLocal ? " (local)\n" : " (REMOTE)\n");
echo $go ? "Mode:     APPLY\n\n" : "Mode:     dry run (add --go to apply)\n\n";

if ($go && !$isLocal) {
    if (!$productionFlag) {
        exit("REFUSED: " . DB_HOST . " is not local. Add --production if you mean it.\n");
    }
    echo "About to write to a NON-LOCAL database: " . DB_NAME . "\n";
    echo "Take a restore-tested backup first. Type the database name to continue: ";
    if (trim((string) fgets(STDIN)) !== DB_NAME) exit("Names did not match. Nothing written.\n");
    echo "\n";
}

// ---- Gather every record that describes a human --------------------------
$rows = [];

$res = $conn->query('SELECT id, name, email, phone, person_id FROM workers WHERE deleted_at IS NULL');
while ($r = $res->fetch_assoc()) $rows[] = $r + ['source' => 'workers'];

$res = $conn->query('SELECT id, name, email, phone, person_id FROM applicants');
while ($r = $res->fetch_assoc()) $rows[] = $r + ['source' => 'applicants'];

$res = $conn->query('SELECT id, name, email, NULL AS phone, person_id FROM users WHERE deleted_at IS NULL');
while ($r = $res->fetch_assoc()) $rows[] = $r + ['source' => 'users'];

echo "Source records: " . count($rows) . "\n";
foreach (['workers', 'applicants', 'users'] as $src) {
    $n = count(array_filter($rows, static fn($r) => $r['source'] === $src));
    echo sprintf("  %-12s %d\n", $src, $n);
}

// enrolments carry only a learner_name — no email, no phone. There is nothing to
// match on that would not be a guess, so they are reported, never linked here.
$orphanEnrolments = (int) $conn->query('SELECT COUNT(*) FROM enrolments WHERE person_id IS NULL')->fetch_row()[0];
echo "\n";

// ---- Group them ----------------------------------------------------------
$grouped = rg_group_people($rows);
$groups = $grouped['groups'];
$unmatchable = $grouped['unmatchable'];

$ambiguous = [];
foreach ($groups as $key => $group) {
    if (rg_group_is_ambiguous($group)) $ambiguous[$key] = $group;
}
foreach (array_keys($ambiguous) as $key) unset($groups[$key]);

$merges = array_filter($groups, static fn($g) => count($g) > 1);

echo "Analysis\n";
echo sprintf("  %-34s %d\n", "people to create", count($groups) + count($unmatchable));
echo sprintf("  %-34s %d\n", "records that collapse into one", array_sum(array_map('count', $merges)));
echo sprintf("  %-34s %d\n", "  across this many people", count($merges));
echo sprintf("  %-34s %d\n", "no email or phone, kept separate", count($unmatchable));
echo sprintf("  %-34s %d\n", "AMBIGUOUS, needs a human", count($ambiguous));
echo sprintf("  %-34s %d\n", "enrolments needing manual linking", $orphanEnrolments);
echo "\n";

if ($merges) {
    echo "Records that will be joined into one person:\n";
    foreach ($merges as $key => $group) {
        $who = $group[0]['name'];
        $refs = implode(', ', array_map(static fn($r) => "{$r['source']}#{$r['id']}", $group));
        echo "  $who — $refs\n";
    }
    echo "\n";
}

if ($ambiguous) {
    echo "AMBIGUOUS — not merged. Different names share a match key; resolve by hand:\n";
    foreach ($ambiguous as $key => $group) {
        echo "  key $key\n";
        foreach ($group as $r) echo "      {$r['source']}#{$r['id']}  {$r['name']}  {$r['email']}  {$r['phone']}\n";
    }
    echo "\n";
}

if (!$go) {
    echo "Dry run. Nothing was written. Re-run with --go to apply.\n";
    exit(0);
}

// ---- Apply ---------------------------------------------------------------
$insertPerson = $conn->prepare(
    'INSERT INTO people (full_name, email, phone, match_key) VALUES (?, ?, ?, ?)'
);
$linkers = [
    'workers'    => $conn->prepare('UPDATE workers    SET person_id = ? WHERE id = ?'),
    'applicants' => $conn->prepare('UPDATE applicants SET person_id = ? WHERE id = ?'),
    'users'      => $conn->prepare('UPDATE users      SET person_id = ? WHERE id = ?'),
];

$created = 0;
$linked = 0;

// Each group is one person. Prefer the richest record for the canonical values:
// a worker record generally holds more than an application.
$rank = ['workers' => 0, 'applicants' => 1, 'users' => 2];
$apply = function (array $group, ?string $key) use ($conn, $insertPerson, $linkers, $rank, &$created, &$linked): void {
    usort($group, static fn($a, $b) => ($rank[$a['source']] ?? 9) <=> ($rank[$b['source']] ?? 9));
    $best = $group[0];

    $name = (string) $best['name'];
    $email = rg_normalise_email($best['email'] ?? null);
    $phone = $best['phone'] ?? null;
    foreach ($group as $r) {
        if ($email === null) $email = rg_normalise_email($r['email'] ?? null);
        if (!$phone) $phone = $r['phone'] ?? null;
    }

    $insertPerson->bind_param('ssss', $name, $email, $phone, $key);
    $insertPerson->execute();
    $personId = $conn->insert_id;
    $created++;

    foreach ($group as $r) {
        $stmt = $linkers[$r['source']];
        $stmt->bind_param('ii', $personId, $r['id']);
        $stmt->execute();
        $linked++;
    }
};

foreach ($groups as $key => $group) $apply($group, $key);
// Unmatchable records each become their own person. That can leave a duplicate,
// which is the safe direction to be wrong in.
foreach ($unmatchable as $row) $apply([$row], null);

echo "Created $created people, linked $linked records.\n";

if ($ambiguous) {
    echo "\n" . count($ambiguous) . " ambiguous group(s) were left untouched. Their records still\n";
    echo "have person_id NULL, so migration 002 (foreign keys) will refuse to run until\n";
    echo "they are resolved. That is deliberate.\n";
}

// ---- Verify --------------------------------------------------------------
echo "\nVerification\n";
foreach (['workers', 'applicants', 'users'] as $table) {
    $where = in_array($table, ['workers', 'users'], true) ? ' AND deleted_at IS NULL' : '';
    $unlinked = (int) $conn->query("SELECT COUNT(*) FROM $table WHERE person_id IS NULL$where")->fetch_row()[0];
    echo sprintf("  %-12s %s\n", $table, $unlinked === 0 ? 'all linked' : "$unlinked still unlinked");
}
$dupes = (int) $conn->query(
    'SELECT COUNT(*) FROM (SELECT match_key FROM people WHERE match_key IS NOT NULL
     GROUP BY match_key HAVING COUNT(*) > 1) d'
)->fetch_row()[0];
echo sprintf("  %-12s %s\n", 'duplicates', $dupes === 0 ? 'none — no match key maps to two people' : "$dupes DUPLICATE KEYS");
