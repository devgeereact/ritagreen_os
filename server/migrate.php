<?php
// Migration runner.
//
//     php server/migrate.php               show what would run (dry run)
//     php server/migrate.php --go          apply pending migrations
//     php server/migrate.php --status      list applied and pending
//
// server/schema.sql remains the from-scratch baseline for a new database.
// Everything after it goes in server/migrations/NNN_name.sql, applied in order
// and recorded, because a single re-runnable file is not a safe basis for
// changing a database that already holds employment records.
//
// Dry run is the default. Applying takes --go, and applying to anything other
// than a local database additionally takes --production and a typed database
// name, because MySQL DDL is not transactional: a migration that fails halfway
// leaves the schema halfway, and there is no rollback to reach for.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
$go = in_array('--go', $args, true);
$statusOnly = in_array('--status', $args, true);
$productionFlag = in_array('--production', $args, true);

$localHosts = ['localhost', '127.0.0.1', '::1'];
$isLocal = in_array(DB_HOST, $localHosts, true);

$dir = __DIR__ . '/migrations';
if (!is_dir($dir)) exit("No migrations directory at $dir\n");

// Tracking table. Created on first run so a fresh database needs no bootstrap.
$conn->query(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(190) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        checksum CHAR(64) NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = [];
$res = $conn->query('SELECT filename, checksum, applied_at FROM schema_migrations');
while ($row = $res->fetch_assoc()) $applied[$row['filename']] = $row;

$files = glob($dir . '/*.sql');
sort($files, SORT_STRING);

$pending = [];
$drift = [];
foreach ($files as $path) {
    $name = basename($path);
    $checksum = hash('sha256', (string) file_get_contents($path));
    if (!isset($applied[$name])) {
        $pending[] = ['name' => $name, 'path' => $path, 'checksum' => $checksum];
    } elseif ($applied[$name]['checksum'] !== $checksum) {
        // An applied migration has been edited. The database no longer matches
        // the file, and re-running it would not fix that. Write a new migration.
        $drift[] = $name;
    }
}

echo "Database: " . DB_NAME . " on " . DB_HOST . ($isLocal ? " (local)" : " (REMOTE)") . "\n";
echo "Applied:  " . count($applied) . "\n";
echo "Pending:  " . count($pending) . "\n\n";

if ($drift) {
    echo "WARNING: these migrations were edited after being applied. The database does\n";
    echo "not match the file, and re-running will not reconcile it. Write a new migration.\n";
    foreach ($drift as $name) echo "  ! $name\n";
    echo "\n";
}

if ($statusOnly) {
    foreach ($files as $path) {
        $name = basename($path);
        $mark = isset($applied[$name]) ? '[applied ' . $applied[$name]['applied_at'] . ']' : '[pending]';
        echo "  $mark $name\n";
    }
    exit($drift ? 1 : 0);
}

if (!$pending) {
    echo "Nothing to do.\n";
    exit($drift ? 1 : 0);
}

foreach ($pending as $m) echo "  pending  {$m['name']}\n";
echo "\n";

if (!$go) {
    echo "Dry run. Re-run with --go to apply.\n";
    exit(0);
}

// Applying to production is deliberate, and confirmed by typing the name.
if (!$isLocal) {
    if (!$productionFlag) {
        exit("REFUSED: " . DB_HOST . " is not a local database. Add --production if you mean it.\n");
    }
    echo "You are about to migrate a NON-LOCAL database: " . DB_NAME . "\n";
    echo "MySQL DDL is not transactional. Take a restore-tested backup first.\n";
    echo "Type the database name to continue: ";
    $typed = trim((string) fgets(STDIN));
    if ($typed !== DB_NAME) exit("Names did not match. Nothing was applied.\n");
}

foreach ($pending as $m) {
    echo "Applying {$m['name']}… ";
    $sql = (string) file_get_contents($m['path']);

    if (!$conn->multi_query($sql)) {
        echo "FAILED\n  {$conn->error}\n";
        echo "\nThe schema may be partly changed. DDL does not roll back — inspect it before retrying.\n";
        exit(1);
    }
    // Drain every result set, or the next statement fails with "commands out of sync".
    do {
        if ($result = $conn->store_result()) $result->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());

    if ($conn->errno) {
        echo "FAILED\n  {$conn->error}\n";
        exit(1);
    }

    $stmt = $conn->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)');
    $stmt->bind_param('ss', $m['name'], $m['checksum']);
    $stmt->execute();
    echo "ok\n";
}

echo "\nDone. " . count($pending) . " migration(s) applied.\n";
