<?php
// Database-backed HTTP tests.
//
//     npm run test:db                 build, serve, run every case, tear down
//     npm run test:db -- --keep       leave the database and server up
//     npm run test:db -- auth         run only cases whose filename matches
//
// server/tests/run.php covers the pure logic. This covers what that suite
// explicitly cannot: authorisation, scope, transitions and persistence, which
// are the parts that decide who can read a worker's compliance state -- and the
// parts nothing had ever exercised.
//
// Everything here happens against a database this script creates and drops. It
// loads server/tests/db/config.php through RG_CONFIG, never server/config.php,
// so it cannot reach a developer's working data and cannot reach production.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("run.php runs from the command line only.\n");
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/client.php';

$args = array_slice($argv, 1);
$keep = in_array('--keep', $args, true);
$filters = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));

$root = dirname(__DIR__, 2);                // server/tests/db -> server
$configPath = __DIR__ . '/config.php';
$port = (int) (getenv('RG_TEST_PORT') ?: 8123);
$base = "http://127.0.0.1:$port";

putenv('RG_CONFIG=' . $configPath);
$_ENV['RG_CONFIG'] = $configPath;

// ---------------------------------------------------------------------------
// 1. Fresh database: schema.sql, then every migration, then fixtures.

[$admin, $dbName] = rgt_admin_connect();
echo "building $dbName\n";
rgt_recreate($admin, $dbName);

$statements = rgt_apply_sql_file($admin, $root . '/schema.sql');
echo "  schema.sql        $statements statements\n";

$migrateOut = rgt_run_cli($root . '/migrate.php', ['--go'], $configPath);

// Count the files and the recorded rows, and insist they match. Scanning the
// output for a success word does not work: "Applying" contains "Applied", so a
// run that died on migration 020 read as a success and eleven migrations were
// quietly missing from every table the tests then asserted against.
$migrationFiles = count(glob($root . '/migrations/*.sql') ?: []);
$appliedCount = rgt_count_migrations($admin);
if ($appliedCount !== $migrationFiles) {
    fwrite(STDERR, "migrate.php applied $appliedCount of $migrationFiles migrations:\n$migrateOut\n");
    exit(2);
}
echo "  migrations        $appliedCount of $migrationFiles applied\n";

// Applying twice must be a no-op. A migration runner that re-runs a migration
// is a migration runner that can corrupt a database it has already migrated.
$again = rgt_run_cli($root . '/migrate.php', ['--go'], $configPath);
$appliedTwice = rgt_count_migrations($admin);

$seedOut = rgt_run_cli($root . '/seed.php', ['--confirm'], $configPath);
if (str_contains($seedOut, 'REFUSED') || str_contains($seedOut, 'SQL failed')) {
    fwrite(STDERR, "seed.php failed:\n$seedOut\n");
    exit(2);
}
echo "  fixtures          seeded\n";

// backfill-people.php is documented as safe to run twice. Prove it, here, where
// the fixtures already link everybody: a second run must not invent a person.
$peopleBefore = (int) $admin->query('SELECT COUNT(*) FROM people')->fetch_row()[0];
rgt_run_cli($root . '/backfill-people.php', ['--go'], $configPath);
rgt_run_cli($root . '/backfill-people.php', ['--go'], $configPath);
$peopleAfter = (int) $admin->query('SELECT COUNT(*) FROM people')->fetch_row()[0];

// ---------------------------------------------------------------------------
// 2. Serve.

$log = sys_get_temp_dir() . '/rg-test-server.log';
@unlink($log);
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
// Redirected in the command rather than through the descriptor spec: the dev
// server's request log goes to stderr, and left on the terminal it buries the
// assertion failures under a line per request.
$cmd = sprintf('exec %s -S 127.0.0.1:%d -t %s >>%s 2>&1',
    escapeshellarg(PHP_BINARY), $port, escapeshellarg($root), escapeshellarg($log));
$proc = proc_open($cmd, $descriptors, $pipes, null, ['RG_CONFIG' => $configPath] + $_ENV);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start the PHP dev server on port $port\n");
    exit(2);
}

register_shutdown_function(function () use ($proc, $admin, $dbName, $keep, $log, $port) {
    if ($keep) {
        // Left running on purpose, so a browser can be pointed at a seeded
        // database. The command is exec'd, so the PID is the server's own.
        $status = is_resource($proc) ? proc_get_status($proc) : ['pid' => 0];
        echo "\nkept:\n";
        echo "  database  $dbName\n";
        echo "  server    http://127.0.0.1:$port  (pid {$status['pid']})\n";
        echo "  log       $log\n";
        echo "  frontend  RG_API_TARGET=http://127.0.0.1:$port npm run dev\n";
        echo "  stop      kill {$status['pid']} && mysql -e 'DROP DATABASE $dbName'\n";
        return;
    }
    if (is_resource($proc)) {
        $status = proc_get_status($proc);
        if ($status['running']) proc_terminate($proc, SIGTERM);
        proc_close($proc);
    }
    rgt_assert_disposable($dbName);
    @$admin->query("DROP DATABASE IF EXISTS `$dbName`");
});

// Wait for it to answer rather than sleeping a guessed interval.
$up = false;
for ($i = 0; $i < 100; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $up = true; break; }
    usleep(100_000);
}
if (!$up) {
    fwrite(STDERR, "The dev server never answered on $port. Log:\n" . (string) @file_get_contents($log));
    exit(2);
}

$client = new RgtClient($base);

// ---------------------------------------------------------------------------
// 3. Environment assertions, before anything else runs.

Rgt::group('harness');
Rgt::same('a second migrate.php --go applies nothing further', $appliedTwice, rgt_count_migrations($admin));
Rgt::same('backfill-people.php run twice creates no new person', $peopleAfter, $peopleBefore);

$health = $client->get('/auth.php?action=me');
Rgt::denied('an unauthenticated request for the current user is refused', $health);

// The harness must be pointed at the disposable database, not the developer's.
Rgt::ok('tests run against a test database', str_starts_with($dbName, 'ritagreen_os_test'), $dbName);

// ---------------------------------------------------------------------------
// 4. Cases.

$caseFiles = glob(__DIR__ . '/cases/*.php') ?: [];
sort($caseFiles, SORT_STRING);
foreach ($caseFiles as $file) {
    if ($filters && !array_filter($filters, fn ($f) => str_contains(basename($file), $f))) continue;
    echo "\n" . basename($file, '.php') . "\n";
    // Sign-in is rate limited to 10 attempts per IP in 300 seconds, and the whole
    // suite arrives from 127.0.0.1 in well under 300 seconds. Clearing the window
    // between case files is what makes the eighth account able to sign in at all.
    // The limit itself is asserted in 010_authentication.php.
    rgt_clear_rate_limits($admin);
    (static function (string $file, RgtClient $client, mysqli $db, string $base) {
        require $file;
    })($file, $client, $admin, $base);
}

// ---------------------------------------------------------------------------
// 5. Report.

echo "\n";
if (Rgt::$failures) {
    echo count(Rgt::$failures) . " FAILED, " . Rgt::$passed . " passed\n\n";
    foreach (Rgt::$failures as $i => $f) echo '  ' . ($i + 1) . ") $f\n\n";
    $serverLog = (string) @file_get_contents($log);
    if (trim($serverLog) !== '') {
        echo "server log (last 40 lines):\n";
        echo implode("\n", array_slice(explode("\n", trim($serverLog)), -40)) . "\n";
    }
    exit(1);
}
echo "ok — " . Rgt::$passed . " assertions passed across " . count(Rgt::$groupCounts) . " groups\n";

// ---------------------------------------------------------------------------

/** Run one of the server's CLI tools against the test config, returning output. */
function rgt_run_cli(string $script, array $args, string $configPath): string
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, ['RG_CONFIG' => $configPath] + $_ENV);
    if (!is_resource($proc)) return '';
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out . $err;
}

/** Empty the rate-limit window. See the call site for why this is not cheating. */
function rgt_clear_rate_limits(mysqli $conn): void
{
    $conn->query('DELETE FROM rate_limits');
}

function rgt_count_migrations(mysqli $conn): int
{
    $res = $conn->query('SELECT COUNT(*) FROM schema_migrations');
    return $res ? (int) $res->fetch_row()[0] : 0;
}
