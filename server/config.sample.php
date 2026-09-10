<?php
// RitaGreenOS — server configuration SAMPLE.
//
// Copy this file to config.php on the server and fill in the real values.
// config.php is gitignored and must NEVER be committed: it holds the DB
// password and the OpenRouter key. Only api.php/auth.php/etc require it.

// ----- Which database is this? -----
// Declared, never guessed. seed.php, migrate.php and backfill-people.php decide
// what they are allowed to do from this constant. They used to read DB_HOST,
// and on cPanel the production database is on localhost — so the live system
// looked local, `migrate.php --go` skipped the typed-name confirmation, and
// `seed.php --confirm --reset` would have emptied clients, workers and invoices
// on the real database. A config.php without this constant is treated as
// production, which is the safe way to be wrong.
define('RG_ENV', 'production');

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

// ----- Security policy -----
// When true, privileged roles (super_admin/admin/manager) must enrol in 2FA
// before they can use the app; they're forced into setup at login until they do.
define('MANDATORY_2FA', false);

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

// Signs unsubscribe links, which have to work from an email client with no
// session. Any long random string: `php -r "echo bin2hex(random_bytes(32));"`.
// Left as CHANGE_ME, marketing email is refused rather than sent without a
// working unsubscribe — a marketing list nobody can leave is worse than no
// marketing list. Changing it invalidates every link already sent.
define('APP_SECRET', 'CHANGE_ME');

// WhatsApp via Twilio (uses the same TWILIO_SID/TWILIO_TOKEN above).
// Your Twilio WhatsApp sender in E.164 WITHOUT the "whatsapp:" prefix; leave
// blank to disable WhatsApp (offers/reminders fall back to SMS).
define('TWILIO_WHATSAPP_FROM', '');                      // e.g. '+14155238886' (sandbox) or your approved number

// ----- GoCardless Direct Debit (recurring B2B collection) -----
// Leave GC_ACCESS_TOKEN as CHANGE_ME to disable. Token from manage.gocardless.com
// (or sandbox). Register the webhook at gocardless.php?action=webhook and put its
// secret in GC_WEBHOOK_SECRET.
define('GC_ACCESS_TOKEN', 'CHANGE_ME');                  // live_... or sandbox_...
define('GC_ENVIRONMENT', 'sandbox');                     // 'sandbox' | 'live'
define('GC_WEBHOOK_SECRET', 'CHANGE_ME');

// ----- Google SSO (staff sign-in with Google; matches existing users only) -----
// Create an OAuth client at console.cloud.google.com. Authorised redirect URI must
// equal GOOGLE_REDIRECT_URI exactly. Leave CLIENT_ID CHANGE_ME to hide the button.
define('GOOGLE_CLIENT_ID', 'CHANGE_ME');                 // xxxx.apps.googleusercontent.com
define('GOOGLE_CLIENT_SECRET', 'CHANGE_ME');
define('GOOGLE_REDIRECT_URI', 'https://ritagreenservices.com/auth/google/callback');

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
// In dev the React app runs on localhost:5122 (project block 12 in the
// workspace port scheme, ~/.claude/CLAUDE.md "Dev-server ports") and reaches the live API through
// Vite's *.php proxy, so it arrives same-origin and never hits this list.
// The entries below are the direct-fetch fallback; keep them in step with
// the pinned dev port. Lock this to the dev origins you actually use.
define('ALLOWED_ORIGINS', [
    'http://localhost:5122',
    'http://127.0.0.1:5122',
    'https://ritagreenservices.com',
    'https://www.ritagreenservices.com',
]);

// ----- Trusted proxies -----
// Which socket peers are allowed to tell us the caller's real IP.
//
// client_ip() feeds every rate_limit() call and the audit trail. It used to read
// X-Forwarded-For / CF-Connecting-IP whenever they were present -- both are
// supplied by the caller, so anybody could send a different one per request and
// get a fresh rate-limit bucket every time. That defeated the throttles on login,
// password reset and the public forms; it was reproduced against a running
// server before it was fixed.
//
// A forwarded header is now read ONLY when REMOTE_ADDR is listed here. Leave the
// list empty and only the socket peer is used, which is CORRECT on this host:
// the origin sits behind Cloudflare with IP restoration enabled, so REMOTE_ADDR
// already holds the visitor's real address by the time PHP runs.
//
// Populate this only if you move to a proxy that does NOT rewrite REMOTE_ADDR,
// and then list that proxy's addresses exactly.
define('TRUSTED_PROXIES', []);

// ----- Connect -----
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_errno) {
    // The detail goes to the log, not to the caller. $conn->connect_error names
    // the host, the database and the user, and this fires for any anonymous
    // request the moment MySQL is unreachable -- which is exactly when somebody
    // is most likely to be poking at the site.
    error_log('DB connection failed (' . $conn->connect_errno . '): ' . $conn->connect_error);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'The service is temporarily unavailable. Please try again shortly.']);
    exit;
}
$conn->set_charset('utf8mb4');
