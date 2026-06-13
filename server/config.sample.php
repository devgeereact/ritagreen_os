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

// ----- Stripe (card payments via hosted Checkout; secrets stay server-side) -----
// Until these are real, payments.php returns 503 and Invoicing keeps using
// manual mark-paid. Get keys at dashboard.stripe.com → Developers → API keys.
// STRIPE_WEBHOOK_SECRET (whsec_...) comes from the webhook you register pointing
// at https://ritagreenservices.com/payments.php?action=webhook (event:
// checkout.session.completed).
define('STRIPE_SECRET_KEY', 'CHANGE_ME');                // sk_live_... or sk_test_...
define('STRIPE_WEBHOOK_SECRET', 'CHANGE_ME');            // whsec_...

// ----- Compliance expiry alerts (expiry-alerts.php, run by cron) -----
// Secret that guards HTTP triggering of the digest. Use a long random string.
// Not needed if you only ever run the script via CLI cron.
define('ALERTS_KEY', 'CHANGE_ME');                       // e.g. 32+ random chars

// ----- OpenWeather (read only by a future weather.php) -----
define('OPENWEATHER_API_KEY', 'CHANGE_ME');
define('OPENWEATHER_UNITS', 'metric');

// ----- SMS (Twilio) for shift offers & reminders -----
// Leave as CHANGE_ME to disable SMS (offers still create in-app + log as skipped).
// Get these at console.twilio.com. TWILIO_FROM is your Twilio number in E.164.
define('TWILIO_SID', 'CHANGE_ME');                       // ACxxxxxxxx...
define('TWILIO_TOKEN', 'CHANGE_ME');                     // auth token — server only
define('TWILIO_FROM', '');                               // e.g. '+447700900123'
// Public base URL used to build shift-offer accept links sent by SMS.
define('PUBLIC_BASE_URL', 'https://ritagreenservices.com');

// ----- Email notifications (public enquiries & careers applications) -----
// When enabled, contact.php emails the office on every submission and sends the
// sender a confirmation, and the in-app inbox can send replies. MAIL_FROM should
// be a real mailbox or alias on your domain so it passes SPF/DKIM and doesn't
// land in spam. Leave MAIL_ENABLED false to skip outgoing mail entirely.
define('MAIL_ENABLED', false);
define('MAIL_FROM', 'hello@ritagreenservices.com');      // a mailbox/alias on the domain
define('MAIL_FROM_NAME', 'RitaGreen Services');
define('MAIL_TO', 'hello@ritagreenservices.com');        // where office alerts go

// ----- Outgoing mail (SMTP) -----
// Leave SMTP_HOST blank to fall back to PHP mail(). On cPanel/Namecheap use the
// mail server hostname (e.g. premiumNNN.web-hosting.com or mail.<domain>) with a
// real mailbox + password. SMTP_SECURE must match the port.
define('SMTP_HOST', '');                                 // e.g. 'mail.ritagreenservices.com' — blank => mail()
define('SMTP_PORT', 465);                                // 465 = SSL, 587 = STARTTLS
define('SMTP_SECURE', 'ssl');                            // 'ssl' | 'tls' | ''  (match the port)
define('SMTP_USER', 'hello@ritagreenservices.com');
define('SMTP_PASS', 'CHANGE_ME');                        // mailbox password — server only, never commit
define('SMTP_VERIFY_PEER', true);                        // verify the SMTP TLS cert (blocks MITM); set false only if the host's cert is broken

// ----- Incoming mail (IMAP) for the in-app inbox -----
// Requires the PHP imap extension. Leave IMAP_HOST blank to disable the inbox.
define('IMAP_HOST', '');                                 // e.g. 'mail.ritagreenservices.com' — blank => disabled
define('IMAP_PORT', 993);                                // 993 = IMAP over SSL
define('IMAP_USER', 'hello@ritagreenservices.com');
define('IMAP_PASS', 'CHANGE_ME');                        // mailbox password — server only, never commit
define('IMAP_VALIDATE_CERT', true);                      // validate the IMAP TLS cert (blocks MITM); set false only if the host's cert is broken

// ----- CORS -----
// In dev the React app runs on localhost:5173 and calls the live API.
// Lock this to the dev origins you actually use.
define('ALLOWED_ORIGINS', [
    'http://localhost:5178',
    'http://127.0.0.1:5178',
    'http://localhost:5180',
    'http://127.0.0.1:5180',
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
