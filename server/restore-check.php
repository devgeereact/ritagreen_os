<?php
// Prove a restored backup matches the database it came from.
//
//     php server/restore-check.php --restored=NAME --uploads=DIR [--record]
//
//   --restored=NAME  the database the backup was restored into. Same server and
//                    the same credentials as config.php; never the live name.
//   --uploads=DIR    the restored copy of server/uploads. Every document row is
//                    looked for there and its bytes compared with the original.
//   --ignore=a,b     tables left out of the row comparison. Default
//                    cron_runs,rate_limits: the backup run itself writes to
//                    cron_runs after the dump, and rate_limits changes with every
//                    sign-in. Add audit_log, sessions and tokens when checking a dump
//                    of a live system that kept working after it was taken.
//   --record         when everything matches, record the restore test as passed
//                    (cron_runs, job "restore_test"), which is what Settings,
//                    System health reads. Nothing is recorded on a mismatch.
//
// Read-only on both databases, apart from that one heartbeat row in the source.
// The procedure, and the evidence of the last rehearsal, are in
// docs/runbooks/backup-and-restore.md.
//
// A backup nobody has restored is a hope, not a backup (docs/RULES.md). System
// health used to say "not automated" for ever, because there was nothing to
// read; this is the thing it now reads.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("restore-check.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$opts = getopt('', ['restored:', 'uploads:', 'record', 'ignore:']);
$restored = (string) ($opts['restored'] ?? '');
$uploadsCopy = isset($opts['uploads']) ? rtrim((string) $opts['uploads'], '/') : null;
$record = array_key_exists('record', $opts);
$ignore = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['ignore'] ?? 'cron_runs,rate_limits')))));

if ($restored === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $restored) !== 1) {
    fwrite(STDERR, "Give the restored database: --restored=NAME (letters, digits and underscores).\n");
    exit(2);
}
if ($restored === DB_NAME) {
    fwrite(STDERR, "The restored database must not be the one config.php points at.\n");
    exit(2);
}

echo "Source:   " . DB_NAME . " on " . DB_HOST . (rg_is_local_database() ? " (local)" : " (production)") . "\n";
echo "Restored: $restored\n\n";

try {
    $copy = new mysqli(DB_HOST, DB_USER, DB_PASS, $restored);
    $copy->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "Could not open the restored database: " . $e->getMessage() . "\n");
    exit(1);
}

/** @return array<string,int> table => row count, base tables only */
function restore_counts(mysqli $db): array
{
    $counts = [];
    $res = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    $tables = [];
    while ($row = $res->fetch_row()) $tables[] = (string) $row[0];
    foreach ($tables as $t) {
        // Names come from the server's own catalogue, never from input.
        $counts[$t] = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetch_row()[0];
    }
    return $counts;
}

$source = array_diff_key(restore_counts($conn), array_flip($ignore));
$copied = array_diff_key(restore_counts($copy), array_flip($ignore));
if ($ignore) echo "Not compared: " . implode(', ', $ignore) . "\n";
$problems = rg_restore_differences($source, $copied);

echo "Tables: " . count($source) . " in the source, " . count($copied) . " in the restored copy\n";
echo "Rows:   " . array_sum($source) . " in the source, " . array_sum($copied) . " in the restored copy\n";

// Documents. Every row in files, archived ones too, because archiving keeps the
// bytes on disk. Compared by SHA-256 with the original where the original is
// readable here.
$docs = 0;
$absentAtSource = 0;
$docProblems = [];
if ($uploadsCopy === null) {
    echo "Documents: NOT CHECKED (no --uploads given)\n";
} elseif (!is_dir($uploadsCopy)) {
    $docProblems[] = "uploads copy $uploadsCopy is not a directory";
} else {
    $res = $copy->query('SELECT id, stored_name FROM files ORDER BY id');
    while ($f = $res->fetch_assoc()) {
        $name = basename((string) $f['stored_name']);
        $restoredPath = "$uploadsCopy/$name";
        $originalPath = __DIR__ . "/uploads/$name";
        if (!is_file($restoredPath)) {
            // Missing from the source as well is not a restore difference: the
            // backup cannot hold bytes the server never had. Counted and shown,
            // because a document row with no document is worth knowing about.
            if (!is_file($originalPath)) { $absentAtSource++; continue; }
            $docProblems[] = "file #{$f['id']}: missing from the uploads copy";
            continue;
        }
        if (is_file($originalPath) && hash_file('sha256', $originalPath) !== hash_file('sha256', $restoredPath)) {
            $docProblems[] = "file #{$f['id']}: bytes differ from the original";
            continue;
        }
        $docs++;
    }
    echo "Documents: $docs match" . ($docProblems ? ', ' . count($docProblems) . ' do not' : '') . "\n";
    if ($absentAtSource) echo "           $absentAtSource document row(s) have no file on the source server either (not a restore difference)\n";
}

$problems = array_merge($problems, $docProblems);
echo "\n";
if ($problems) {
    echo "FAILED. The restored copy does not match:\n";
    foreach ($problems as $p) echo "  - $p\n";
    exit(1);
}

echo "PASS. Every table and row count matches" . ($uploadsCopy !== null ? ", and every document is present and identical" : '') . ".\n";

if ($record) {
    if ($uploadsCopy === null) {
        echo "Not recorded: a restore test that did not check the documents is half a test. Give --uploads.\n";
        exit(1);
    }
    rg_cron_started($conn, 'restore_test');
    rg_cron_succeeded($conn, 'restore_test', sprintf('%d tables, %d rows, %d documents into %s',
        count($source), array_sum($source), $docs, $restored));
    echo "Recorded: System health shows today's date as the last restore test.\n";
}
exit(0);
