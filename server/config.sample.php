<?php
// RitaGreenOS — server configuration SAMPLE.
//
// Copy this file to config.php on the server and fill in the real values.
// config.php is gitignored and must NEVER be committed: it holds the DB
// password and the OpenRouter key. Only api.php/auth.php/etc require it.

// ----- Error display -----
// Flip GK_DEBUG to true only while debugging. In production errors are logged
// (check cPanel error_log) but never shown, so nothing leaks to callers.
define('GK_DEBUG', false);
ini_set('display_errors', GK_DEBUG ? '1' : '0');
ini_set('display_startup_errors', GK_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ----- Database (cPanel MySQL) -----
define('DB_HOST', 'localhost');
define('DB_NAME', 'ritagreen_os');   // cPanel may prefix as <cpaneluser>_ritagreen_os
define('DB_USER', 'ritagreen_os');   // usually the same as the DB name
define('DB_PASS', 'CHANGE_ME');      // the DB user's password — server only

// ----- OpenRouter (server-side AI only; key never reaches the browser) -----
define('OR_API_KEY', 'CHANGE_ME');                       // sk-or-v1-...
define('OR_MODEL', 'openai/gpt-oss-120b:free');          // any current openrouter.ai/models id

// Reference only — ai.php uses OR_MODEL above; these are not required.
define('OR_MODELS', ['openai/gpt-oss-120b:free', 'anthropic/claude-sonnet-4.6']);
define('OR_REFERER', 'https://ritagreenservices.com');
define('OR_TITLE', 'RitaGreenOS');

// ----- OpenWeather (read only by a future weather.php) -----
define('OPENWEATHER_API_KEY', 'CHANGE_ME');
define('OPENWEATHER_UNITS', 'metric');

// ----- CORS -----
// In dev the React app runs on localhost:5173 and calls the live API.
// Lock this to the dev origins you actually use.
define('ALLOWED_ORIGINS', [
    'http://localhost:5178',
    'http://127.0.0.1:5178',
    'https://ritagreenservices.com',
    'https://www.ritagreenservices.com',
]);

// ----- Connect -----
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');
