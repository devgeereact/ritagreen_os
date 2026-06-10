<?php
// RitaGreenOS — server configuration SAMPLE.
//
// Copy this file to config.php on the server and fill in the real values.
// config.php is gitignored and must NEVER be committed: it holds the DB
// password and the OpenRouter key. Only api.php/auth.php/etc require it.

// ----- Database (cPanel MySQL) -----
define('DB_HOST', 'localhost');
define('DB_NAME', 'ritagreen_os');   // cPanel may prefix as <cpaneluser>_ritagreen_os
define('DB_USER', 'ritagreen_os');   // usually the same as the DB name
define('DB_PASS', 'CHANGE_ME');      // the DB user's password — server only

// ----- OpenRouter (server-side AI only; key never reaches the browser) -----
define('OR_API_KEY', 'CHANGE_ME');                       // sk-or-...
define('OR_MODEL', 'anthropic/claude-sonnet-4');         // any current openrouter.ai/models id

// ----- CORS -----
// In dev the React app runs on localhost:5173 and calls the live API.
// Lock this to the dev origins you actually use.
define('ALLOWED_ORIGINS', [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'https://ritagreenservices.com',
    'https://www.ritagreenservices.com',
]);

// ----- Connect -----
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');
