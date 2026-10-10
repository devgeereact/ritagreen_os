<?php
// Configuration for the database-backed test harness.
//
// This file is loaded INSTEAD of server/config.php, through the RG_CONFIG
// environment variable that server/lib.php reads. That indirection exists for
// one reason: these tests create, empty and drop a database, and they must never
// be able to do that to the database a developer is working in -- still less to
// production. They get their own.
//
// Everything here is either a local-only credential or an integration switched
// off. There is no secret in this file and there must never be one: it is
// tracked, and server/tests/db/run.php refuses to run against a config that does
// not declare RG_ENV as 'local'.
//
// Override the connection with RG_TEST_DB_HOST / _USER / _PASS / _NAME when your
// MySQL is set up differently. See docs/TESTING.md.

// ----- Which database is this? -----
// 'local' is what makes migrate.php skip its production confirmation and what
// lib.php checks before honouring RG_CONFIG at all. Never change it here.
define('RG_ENV', 'local');

define('GK_DEBUG', true);
ini_set('display_errors', '0');   // keep JSON responses parseable
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ----- Database -----
// The name carries the _test prefix the harness's grant is scoped to, and
// bootstrap.php refuses any name without it before it issues a DROP.
define('DB_HOST', getenv('RG_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('RG_TEST_DB_USER') ?: 'rg_test');
define('DB_PASS', getenv('RG_TEST_DB_PASS') !== false ? (string) getenv('RG_TEST_DB_PASS') : 'rg_test_local_only');
define('DB_NAME', getenv('RG_TEST_DB_NAME') ?: 'ritagreen_os_test');

// ----- Every outbound integration: OFF -----
// A test run that can email a real applicant is a test run that eventually will.
define('MAIL_ENABLED', false);
define('MAIL_FROM', 'tests@example.invalid');
define('MAIL_FROM_NAME', 'RitaGreen (tests)');
define('MAIL_TO', 'tests@example.invalid');
define('SMTP_HOST', '');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_VERIFY_PEER', true);

define('IMAP_HOST', '');
define('IMAP_PORT', 993);
define('IMAP_USER', '');
define('IMAP_PASS', '');
define('IMAP_VALIDATE_CERT', true);

define('STRIPE_SECRET_KEY', 'CHANGE_ME');
define('STRIPE_WEBHOOK_SECRET', 'CHANGE_ME');
define('GC_ACCESS_TOKEN', 'CHANGE_ME');
define('GC_ENVIRONMENT', 'sandbox');
define('GC_WEBHOOK_SECRET', 'CHANGE_ME');

define('TWILIO_SID', 'CHANGE_ME');
define('TWILIO_TOKEN', 'CHANGE_ME');
define('TWILIO_FROM', '');
define('TWILIO_WHATSAPP_FROM', '');

define('GOOGLE_CLIENT_ID', 'CHANGE_ME');
define('GOOGLE_CLIENT_SECRET', 'CHANGE_ME');
define('GOOGLE_REDIRECT_URI', 'http://127.0.0.1/auth/google/callback');

// ----- Policy -----
// 2FA off so a test can sign in with a password and assert on what follows
// rather than on an enrolment flow. The 2FA path has its own cases.
define('MANDATORY_2FA', false);
define('ALERTS_KEY', 'test-only-not-a-secret');
define('APP_SECRET', 'test-only-not-a-secret-unsubscribe-signing-key');
define('PUBLIC_BASE_URL', 'http://127.0.0.1:8123');
define('TRUSTED_PROXIES', []);
define('ALLOWED_ORIGINS', ['http://127.0.0.1:8123']);

// ----- Connect -----
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Test database connection failed: ' . $conn->connect_error
        . ' — run `npm run test:db` which creates ' . DB_NAME . ', or see docs/TESTING.md']);
    exit;
}
$conn->set_charset('utf8mb4');
