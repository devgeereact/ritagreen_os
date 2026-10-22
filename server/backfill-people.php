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

// Declared, not inferred. See rg_is_local_database() in lib.php: DB_HOST is
// localhost on the production host as well, so it cannot answer this.
$isLocal = rg_is_local_database();

// Refuse to run before the schema is ready, rather than failing halfway through.
if (!$conn->query("SHOW TABLES LIKE 'people'")->num_rows) {
    exit("REFUSED: the people table does not exist. Run: php server/migrate.php --go\n");
}

echo "Database: " . DB_NAME . " on " . DB_HOST . ($isLocal ? " (local)\n" : " (REMOTE)\n");
echo $go ? "Mode:     APPLY\n\n" : "Mode:     dry run (add --go to apply)\n\n";

if ($go && !$isLocal) {
    if (!$productionFlag) {
        exit("REFUSED: " . DB_NAME . " is not declared as a local database. Add --production if you mean it.\n"
            . rg_env_hint());
    }
    echo "About to write to a NON-LOCAL database: " . DB_NAME . "\n";
    echo "Take a restore-tested backup first. Type the database name to continue: ";
    if (trim((string) fgets(STDIN)) !== DB_NAME) exit("Names did not match. Nothing written.\n");
    echo "\n";
}

// ---- Gather every record that describes a human --------------------------
$rows = [];

// link_key carries the joins the database ALREADY records — users.worker_id and
// applicants.worker_id. Those are facts somebody entered, and they outrank any
// email or phone heuristic. Without them the backfill splits a worker from their
// own login, which is what the first run of this script actually did.
$res = $conn->query('SELECT id, name, email, phone, person_id FROM workers');
while ($r = $res->fetch_assoc()) $rows[] = $r + ['source' => 'workers', 'link_key' => 'w:' . $r['id']];

$res = $conn->query('SELECT id, name, email, phone, worker_id, person_id FROM applicants');
while ($r = $res->fetch_assoc()) {
    $rows[] = $r + ['source' => 'applicants', 'link_key' => $r['worker_id'] ? 'w:' . $r['worker_id'] : null];
}

$res = $conn->query('SELECT id, name, email, NULL AS phone, worker_id, person_id FROM users');
while ($r = $res->fetch_assoc()) {
    $rows[] = $r + ['source' => 'users', 'link_key' => $r['worker_id'] ? 'w:' . $r['worker_id'] : null];
}

$explicit = count(array_filter($rows, static fn($r) => !empty($r['link_key']) && $r['source'] !== 'workers'));
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

// Only groups that still have something to link. A group whose rows already
// share a person is not a merge waiting to happen, it is a merge that happened.
$merges = array_filter(
    $groups,
    static fn($g) => count($g) > 1 && array_filter($g, static fn($r) => empty($r['person_id']))
);

$alreadyLinked = count(array_filter($rows, static fn($r) => !empty($r['person_id'])));
$toCreate = 0;
foreach (array_merge(array_values($groups), array_map(static fn($r) => [$r], $unmatchable)) as $g) {
    $hasPerson = array_filter(array_map(static fn($r) => $r['person_id'] ?? null, $g));
    $hasUnlinked = array_filter($g, static fn($r) => empty($r['person_id']));
    if (!$hasPerson && $hasUnlinked) $toCreate++;
}

echo "Analysis\n";
// What will actually change, not what a first run would have done: on a database
// that has already been backfilled or seeded these are all zero, and reporting
// otherwise made a no-op look like a rewrite of the spine.
echo sprintf("  %-34s %d\n", "already linked, left alone", $alreadyLinked);
echo sprintf("  %-34s %d\n", "people to create", $toCreate);
echo sprintf("  %-34s %d\n", "records that collapse into one", array_sum(array_map('count', $merges)));
echo sprintf("  %-34s %d\n", "  across this many people", count($merges));
echo sprintf("  %-34s %d\n", "no email or phone, kept separate", count($unmatchable));
echo sprintf("  %-34s %d\n", "AMBIGUOUS, needs a human", count($ambiguous));
echo sprintf("  %-34s %d\n", "joined by an existing worker_id link", $explicit);
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
$skipped = 0;
$conflicts = [];

// Each group is one person. Prefer the richest record for the canonical values:
// a worker record generally holds more than an application.
$rank = ['workers' => 0, 'applicants' => 1, 'users' => 2];

/**
 * Apply one group.
 *
 * Rows that already carry a person_id are left exactly as they are, and their
 * person is reused for anything unlinked beside them. Without this the script
 * was not safe to run twice: it inserted a fresh person for every group
 * regardless, repointed every record at the duplicate, and orphaned the
 * original — along with the right-to-work checks, verifications, eligibility
 * and leave records still hanging off it. The README quickstart and the
 * production runbook both run it after a seed, by which time everything is
 * already linked.
 *
 * A group whose rows point at DIFFERENT people is a genuine conflict, not
 * something to resolve by picking one: merging two employment histories is the
 * hardest thing here to undo. Reported and skipped.
 */
$apply = function (array $group, ?string $key) use (
    $conn, $insertPerson, $linkers, $rank, &$created, &$linked, &$skipped, &$conflicts
): void {
    $existing = array_values(array_unique(array_filter(
        array_map(static fn($r) => $r['person_id'] ?? null, $group)
    )));
    if (count($existing) > 1) {
        $conflicts[] = $group;
        return;
    }

    $unlinked = array_values(array_filter($group, static fn($r) => empty($r['person_id'])));
    if (!$unlinked) {
        $skipped += count($group);
        return;
    }

    if ($existing) {
        $personId = (int) $existing[0];
    } else {
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
        $personId = (int) $conn->insert_id;
        $created++;
    }

    foreach ($unlinked as $r) {
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

echo "Created $created people, linked $linked records";
echo $skipped ? ", left $skipped already-linked record(s) alone.\n" : ".\n";

if ($conflicts) {
    echo "\n" . count($conflicts) . " group(s) point at MORE THAN ONE person and were left alone.\n";
    echo "Merging two people is the hardest thing here to undo, so resolve these by hand:\n";
    foreach ($conflicts as $group) {
        $refs = implode(', ', array_map(
            static fn($r) => "{$r['source']}#{$r['id']}→person " . ($r['person_id'] ?? 'NULL'),
            $group
        ));
        echo "  {$group[0]['name']} — $refs\n";
    }
}

if ($ambiguous) {
    echo "\n" . count($ambiguous) . " ambiguous group(s) were left untouched. Their records still\n";
    echo "have person_id NULL, so migration 002 (foreign keys) will refuse to run until\n";
    echo "they are resolved. That is deliberate.\n";
}

// ---- Verify --------------------------------------------------------------
echo "\nVerification\n";
// Soft-deleted rows are included deliberately: their history is retained, and an
// unlinked row would block the NOT NULL migration that enforces the spine.
foreach (['workers', 'applicants', 'users'] as $table) {
    $unlinked = (int) $conn->query("SELECT COUNT(*) FROM $table WHERE person_id IS NULL")->fetch_row()[0];
    echo sprintf("  %-12s %s\n", $table, $unlinked === 0 ? 'all linked, including soft-deleted' : "$unlinked still unlinked");
}
$dupes = (int) $conn->query(
    'SELECT COUNT(*) FROM (SELECT match_key FROM people WHERE match_key IS NOT NULL
     GROUP BY match_key HAVING COUNT(*) > 1) d'
)->fetch_row()[0];
echo sprintf("  %-12s %s\n", 'duplicates', $dupes === 0 ? 'none — no match key maps to two people' : "$dupes DUPLICATE KEYS");
