<?php
// Builds the disposable database the HTTP tests run against.
//
// Not a library for the endpoints: nothing under server/ requires this. It is
// run by server/tests/db/run.php before the dev server is started, and its whole
// job is to make "fresh install" a thing that happens on every run rather than a
// thing a developer did once in March.
//
// It applies server/schema.sql and then every migration, in that order, because
// that is the documented path for a new database and the only way a test run
// proves the path still works.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("bootstrap.php runs from the command line only.\n");
}

/** Refuse to touch anything that is not obviously a throwaway test database. */
function rgt_assert_disposable(string $name): void
{
    // The harness DROPs this database. The name is the only thing standing
    // between that and somebody's working data, so it is checked rather than
    // trusted: ritagreen_os_dev, ritagreen_os and every production name fail.
    if (!preg_match('/^ritagreen_os_test[a-z0-9_]*$/', $name)) {
        fwrite(STDERR, "REFUSED: '$name' is not a test database name.\n"
            . "The harness drops and recreates it, so it only ever runs against a\n"
            . "database called ritagreen_os_test, optionally suffixed.\n");
        exit(2);
    }
}

/**
 * Connect without selecting a database, so the database itself can be created.
 * Returns [$conn, $name].
 */
function rgt_admin_connect(): array
{
    $host = getenv('RG_TEST_DB_HOST') ?: '127.0.0.1';
    $user = getenv('RG_TEST_DB_USER') ?: 'rg_test';
    $pass = getenv('RG_TEST_DB_PASS') !== false ? (string) getenv('RG_TEST_DB_PASS') : 'rg_test_local_only';
    $name = getenv('RG_TEST_DB_NAME') ?: 'ritagreen_os_test';

    rgt_assert_disposable($name);

    // OFF only for the connection attempt, so a failure to reach MySQL prints the
    // guidance below rather than a stack trace. Switched to throwing as soon as
    // the connection exists -- see rgt_recreate().
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $user, $pass);
    if ($conn->connect_errno) {
        fwrite(STDERR, "Cannot reach MySQL at $host as $user: {$conn->connect_error}\n\n"
            . "The harness needs a local MySQL or MariaDB and a user that can create a\n"
            . "database. See docs/TESTING.md for the one-off grant.\n");
        exit(2);
    }
    $conn->set_charset('utf8mb4');

    // A failed query now THROWS on this connection.
    //
    // It was MYSQLI_REPORT_OFF, and the cases use $db to arrange the rows a test
    // is about. A mistyped column name therefore inserted nothing, silently, and
    // the assertion that followed measured a database that had never been set up
    // -- which is a test that reports on nothing while looking like it passed.
    // Found exactly that way: an INSERT naming a `note` column the table does not
    // have (it is `rejection_reason`) vanished without a word.
    //
    // rgt_apply_sql_file() and the teardown DROP handle their own errors, so they
    // set this back where they need to.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return [$conn, $name];
}

/** Drop and recreate the test database. Every run starts from nothing. */
function rgt_recreate(mysqli $conn, string $name): void
{
    rgt_assert_disposable($name);
    $conn->query("DROP DATABASE IF EXISTS `$name`");
    if (!$conn->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        fwrite(STDERR, "Could not create `$name`: {$conn->error}\n");
        exit(2);
    }
    $conn->select_db($name);
}

/**
 * Apply a .sql file statement by statement.
 *
 * multi_query() would be shorter and would also swallow the first error into a
 * result set nobody reads, which is how a half-applied schema looks like a
 * successful one. Splitting means every statement is checked.
 */
function rgt_apply_sql_file(mysqli $conn, string $path): int
{
    // This reports its own failures, with the statement that caused them.
    mysqli_report(MYSQLI_REPORT_OFF);
    $sql = (string) file_get_contents($path);
    $count = 0;
    foreach (rgt_split_statements($sql) as $stmt) {
        if (!$conn->query($stmt)) {
            fwrite(STDERR, "FAILED in " . basename($path) . ": {$conn->error}\n  "
                . substr(preg_replace('/\s+/', ' ', $stmt), 0, 200) . "\n");
            exit(2);
        }
        $count++;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $count;
}

/**
 * Split a SQL script on semicolons that end a statement.
 *
 * Aware of quotes, backticks and -- / # / block comments, because a semicolon
 * inside a string literal or a comment is not a statement boundary and the
 * schema contains both.
 */
function rgt_split_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = '';       // '', "'", '"' or '`'
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($c === "\n") { $lineComment = false; $buf .= $c; }
            continue;
        }
        if ($blockComment) {
            if ($c === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`') { $buf .= $next; $i++; continue; }
            if ($c === $quote) $quote = '';
            continue;
        }
        if ($c === '-' && $next === '-') { $lineComment = true; $i++; continue; }
        if ($c === '#') { $lineComment = true; continue; }
        if ($c === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buf .= $c; continue; }
        if ($c === ';') {
            if (trim($buf) !== '') $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}
