<?php
// Shared helpers for every RitaGreenOS endpoint.
// Each endpoint starts with: require __DIR__ . '/lib.php';
// which pulls in config.php ($conn) and sets up CORS + JSON helpers.

// Surface fatals / mysqli exceptions as JSON instead of a blank 500 HTML page.
mysqli_report(MYSQLI_REPORT_OFF);
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    // Two audiences: a developer who has not set up locally yet, and an operator
    // who has deployed without placing config.php. Say which is which.
    echo json_encode(['error' => PHP_SAPI === 'cli-server'
        ? 'config.php is missing. Locally: cp server/config.local.php.example server/config.php'
        : 'config.php is missing on the server. Upload it to the document root.']);
    exit;
}
require __DIR__ . '/config.php';

foreach (['ALLOWED_ORIGINS', 'OR_API_KEY', 'OR_MODEL'] as $_required) {
    if (!defined($_required)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => "config.php is missing constant $_required. Copy config.sample.php fields into it."]);
        exit;
    }
}

// ----- CORS (must run before any output) -----
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Short-circuit preflight.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function fail(string $message, int $code = 400): void
{
    json_out(['error' => $message], $code);
}

// Parse a JSON request body into an associative array.
function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

// Pull the Bearer token from the Authorization header.
function bearer_token(): ?string
{
    $headers = [];
    if (function_exists('getallheaders')) $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
    return null;
}

// Resolve the current user from the token, or null. Tokens expire after 30 days.
function current_user(mysqli $conn): ?array
{
    $token = bearer_token();
    if (!$token) return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.division, u.client_id, u.worker_id
         FROM tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if (!$res) return null;

    // Keep last_seen_at meaningful. It was written once when the token was issued
    // and never updated, so the device list in Settings showed each session's
    // creation time under a heading that reads as last activity. Throttled to at
    // most one write a minute per token, so a burst of calls costs one update.
    $touch = $conn->prepare(
        'UPDATE tokens SET last_seen_at = NOW()
         WHERE token_hash = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 1 MINUTE)'
    );
    $touch->bind_param('s', $hash);
    $touch->execute();

    return $res;
}

// Require a logged-in user; 401 otherwise. Returns the user row.
function require_auth(mysqli $conn): array
{
    $user = current_user($conn);
    if (!$user) fail('Not authenticated.', 401);
    return $user;
}

// Require one of the given roles; 403 otherwise.
function require_role(array $user, string ...$roles): void
{
    if (!in_array($user['role'], $roles, true)) fail('You do not have access to this.', 403);
}

// Is this an internal staff account, as opposed to a worker or client login?
//
// Role alone cannot answer this. A worker's login is an ordinary account with
// users.worker_id set, and a client portal login has users.client_id set, so both
// can hold a role like 'employee'. Anything gated on "internal staff" must check
// the link, not just the role.
//
// Privileged roles are always staff, so a manager who also holds a worker record
// (a trainer who covers shifts, say) does not lock themselves out of the office.
function is_staff(array $user): bool
{
    if (in_array($user['role'], ['super_admin', 'admin', 'manager'], true)) return true;
    if ($user['role'] === 'client') return false;
    if (!empty($user['client_id']) || !empty($user['worker_id'])) return false;
    return true;
}

// Require an internal staff account; 403 otherwise. Use on anything that shows
// one person's data to another — the office board, the shared mailbox, the
// workforce list.
function require_staff(array $user): void
{
    if (!is_staff($user)) fail('You do not have access to this.', 403);
}

// The worker record this login belongs to, or null for a staff or client account.
function linked_worker_id(array $user): ?int
{
    return empty($user['worker_id']) ? null : (int) $user['worker_id'];
}

// Write an audit log row for sensitive actions.
function audit(mysqli $conn, ?int $userId, string $action, string $detail = ''): void
{
    $stmt = $conn->prepare('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $detail);
    $stmt->execute();
}

// Best-effort client IP. Cloudflare and generic proxies put the real address in
// these headers; fall back to the socket peer. Trimmed to fit VARCHAR(45).
function client_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]); // first hop in XFF list
    return substr($ip, 0, 45);
}

// Sliding-window rate limit per (bucket, IP). Records one hit per call and
// fails with 429 once $max hits land inside $windowSec. Throttles public /
// unauthenticated endpoints (login, contact form) against brute-force and spam.
// Requires the `rate_limits` table (see schema.sql).
function rate_limit(mysqli $conn, string $bucket, int $max, int $windowSec): void
{
    $ip = client_ip();
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM rate_limits
         WHERE bucket = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->bind_param('ssi', $bucket, $ip, $windowSec);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    if ($count >= $max) {
        fail('Too many attempts. Please wait a little and try again.', 429);
    }
    $stmt = $conn->prepare('INSERT INTO rate_limits (bucket, ip) VALUES (?, ?)');
    $stmt->bind_param('ss', $bucket, $ip);
    $stmt->execute();
    // Opportunistic cleanup (~1% of calls) so the table never grows unbounded.
    if (random_int(1, 100) === 1) {
        $conn->query('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    }
}

// Atomically allocate the next value in a named sequence. Replaces the racy
// SELECT COUNT(*)+1 pattern for document numbers (invoices/quotes/certs):
// concurrent callers each get a distinct integer in a single statement.
// Requires the `counters` table (see schema.sql).
function next_seq(mysqli $conn, string $key): int
{
    $stmt = $conn->prepare(
        'INSERT INTO counters (name, val) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE val = LAST_INSERT_ID(val + 1)'
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    return (int) $conn->insert_id;
}

// ----- Email notifications -----
// Optional: only fires when config.php defines MAIL_ENABLED, MAIL_FROM and
// MAIL_TO. Used to alert the office the moment a public enquiry or application
// arrives, and to send the sender a friendly autoresponder. Sending failures
// are deliberately non-fatal — the lead is already saved in the database.

function mail_enabled(): bool
{
    return defined('MAIL_ENABLED') && MAIL_ENABLED
        && defined('MAIL_FROM') && MAIL_FROM !== ''
        && defined('MAIL_TO') && MAIL_TO !== '';
}

// True when SMTP is configured, so send_mail() should use it instead of mail().
function smtp_configured(): bool
{
    return defined('SMTP_HOST') && SMTP_HOST !== ''
        && defined('SMTP_USER') && SMTP_USER !== ''
        && defined('SMTP_PASS') && SMTP_PASS !== '';
}

// Send a plain-text email. Uses SMTP when configured, otherwise PHP mail().
// $to and $replyTo must be pre-validated addresses (e.g. FILTER_VALIDATE_EMAIL).
// $extraHeaders lets callers add e.g. In-Reply-To / References for threaded
// replies. Returns true on successful hand-off to the mail server.
function send_mail(string $to, string $subject, string $body, ?string $replyTo = null, array $extraHeaders = []): bool
{
    if (!defined('MAIL_FROM') || MAIL_FROM === '') return false;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    // Strip CR/LF to block header injection via the subject.
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));

    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'RitaGreen Services';
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    foreach ($extraHeaders as $h) {
        $h = trim(str_replace(["\r", "\n"], '', (string) $h));
        if ($h !== '') $headers[] = $h;
    }

    if (smtp_configured()) {
        return smtp_send($to, $subject, $body, $fromName, $headers);
    }

    // PHP mail() fallback — it injects To/Subject itself, so we only pass the rest.
    $mailHeaders = array_merge(['From: ' . $fromName . ' <' . MAIL_FROM . '>'], $headers);
    return @mail($to, $subject, $body, implode("\r\n", $mailHeaders));
}

// Minimal SMTP client over a socket (AUTH LOGIN). No Composer/PHPMailer needed,
// so it runs on stock cPanel/Namecheap shared hosting. $headers is the list of
// extra header lines (MIME, Reply-To, In-Reply-To, …); From/To/Subject/Date/
// Message-ID are added here. Returns true only if the server accepts the message.
function smtp_send(string $to, string $subject, string $body, string $fromName, array $headers): bool
{
    $host = SMTP_HOST;
    $port = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
    $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : '';
    $from = MAIL_FROM;

    // Verify the server's TLS cert by default (blocks MITM). A host with a
    // broken/self-signed mail cert can opt out via SMTP_VERIFY_PEER=false.
    $verify = !defined('SMTP_VERIFY_PEER') || SMTP_VERIFY_PEER;
    $transport = $secure === 'ssl' ? "ssl://$host" : $host;
    $fp = @stream_socket_client(
        "$transport:$port",
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]])
    );
    if (!$fp) {
        error_log("SMTP connect failed: $errno $errstr");
        return false;
    }
    stream_set_timeout($fp, 20);

    // Read a (possibly multi-line) reply and check its leading status code.
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // Lines like "250-..." continue; "250 ..." (space) is the last line.
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $line, string $expect) use ($fp, $read): bool {
        fwrite($fp, $line . "\r\n");
        $resp = $read();
        return substr($resp, 0, 3) === $expect;
    };

    $ok = true;
    $read(); // server greeting (220)
    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $ok = $ok && $cmd("EHLO $ehloHost", '250');

    if ($secure === 'tls') {
        $ok = $ok && $cmd('STARTTLS', '220');
        if ($ok && !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $ok = $ok && $cmd("EHLO $ehloHost", '250');
    }

    $ok = $ok && $cmd('AUTH LOGIN', '334');
    $ok = $ok && $cmd(base64_encode(SMTP_USER), '334');
    $ok = $ok && $cmd(base64_encode(SMTP_PASS), '235');
    $ok = $ok && $cmd('MAIL FROM:<' . $from . '>', '250');
    $ok = $ok && $cmd('RCPT TO:<' . $to . '>', '250');
    $ok = $ok && $cmd('DATA', '354');

    if ($ok) {
        $msgId = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/^.*@/', '', $from) . '>';
        $lines = array_merge([
            'Date: ' . date('r'),
            'Message-ID: ' . $msgId,
            'From: ' . encode_mime_word($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . encode_mime_word($subject),
        ], $headers, ['', $body]);

        $message = implode("\r\n", $lines);
        // Dot-stuffing: any line that starts with '.' must be doubled.
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($fp, $message . "\r\n.\r\n");
        $resp = $read();
        $ok = substr($resp, 0, 3) === '250';
    }

    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}

// RFC 2047 encode a header value so non-ASCII (names, subjects) survive transit.
function encode_mime_word(string $text): string
{
    if (preg_match('/[\x80-\xff]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

// ----- SMS (Twilio) -----
// Optional: only sends when TWILIO_SID/TWILIO_TOKEN/TWILIO_FROM are set in
// config.php. Used for shift offers and reminders. Every attempt is logged to
// sms_log so the office has an audit trail even when sending is disabled.

function sms_enabled(): bool
{
    return defined('TWILIO_SID') && TWILIO_SID !== '' && TWILIO_SID !== 'CHANGE_ME'
        && defined('TWILIO_TOKEN') && TWILIO_TOKEN !== '' && TWILIO_TOKEN !== 'CHANGE_ME'
        && defined('TWILIO_FROM') && TWILIO_FROM !== '';
}

// Normalise a UK number to E.164 (+44…). Returns null if it doesn't look valid.
function normalise_phone(?string $phone): ?string
{
    if (!$phone) return null;
    $p = preg_replace('/[^\d+]/', '', $phone);
    if ($p === '') return null;
    if (strpos($p, '+') === 0) return $p;
    if (strpos($p, '0') === 0) return '+44' . substr($p, 1); // UK local -> E.164
    return '+' . $p;
}

// Send one SMS via Twilio. Returns true on hand-off. Logs every call.
function send_sms(mysqli $conn, ?string $to, string $body): bool
{
    $num = normalise_phone($to);
    $body = mb_substr($body, 0, 480);

    if (!$num) {
        sms_log($conn, (string) $to, $body, 'failed', 'invalid number');
        return false;
    }
    if (!sms_enabled()) {
        sms_log($conn, $num, $body, 'skipped', 'sms disabled');
        return false;
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query(['From' => TWILIO_FROM, 'To' => $num, 'Body' => $body]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = $code >= 200 && $code < 300;
    sms_log($conn, $num, $body, $ok ? 'sent' : 'failed', $ok ? null : ('HTTP ' . $code));
    return $ok;
}

function sms_log(mysqli $conn, string $to, string $body, string $status, ?string $meta): void
{
    $to = substr($to, 0, 40);
    $stmt = $conn->prepare('INSERT INTO sms_log (recipient, body, status, meta) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $to, $body, $status, $meta);
    $stmt->execute();
}

// ----- WhatsApp (Twilio, same account as SMS) -----
function whatsapp_enabled(): bool
{
    return sms_enabled() && defined('TWILIO_WHATSAPP_FROM') && TWILIO_WHATSAPP_FROM !== '';
}

// Send one WhatsApp message via Twilio. Logged with meta="whatsapp".
function send_whatsapp(mysqli $conn, ?string $to, string $body): bool
{
    $num = normalise_phone($to);
    $body = mb_substr($body, 0, 1500);
    if (!$num) {
        sms_log($conn, (string) $to, $body, 'failed', 'whatsapp: invalid number');
        return false;
    }
    if (!whatsapp_enabled()) {
        sms_log($conn, $num, $body, 'skipped', 'whatsapp disabled');
        return false;
    }
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => 'whatsapp:' . TWILIO_WHATSAPP_FROM,
            'To' => 'whatsapp:' . $num,
            'Body' => $body,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = $code >= 200 && $code < 300;
    sms_log($conn, $num, $body, $ok ? 'sent' : 'failed', $ok ? 'whatsapp' : ('whatsapp: HTTP ' . $code));
    return $ok;
}

// Reach a worker on their preferred channel: WhatsApp if configured, else SMS.
// Returns true if either hand-off succeeded.
function message_worker(mysqli $conn, ?string $to, string $body): bool
{
    if (whatsapp_enabled()) {
        if (send_whatsapp($conn, $to, $body)) return true;
    }
    return send_sms($conn, $to, $body);
}

// ----- TOTP 2FA (RFC 6238, SHA-1, 6 digits, 30s step) -----
// Pure PHP so it runs on stock shared hosting (no Composer/otplib).

// Generate a base32 secret (A-Z2-7) for a new authenticator enrolment.
function totp_secret(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < $length; $i++) $s .= $alphabet[random_int(0, 31)];
    return $s;
}

// Decode a base32 secret to raw bytes.
function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    }
    return $bytes;
}

// The 6-digit TOTP code for a given time counter.
function totp_code(string $secret, int $counter): string
{
    $key = base32_decode($secret);
    $bin = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $part = (ord($hash[$offset]) & 0x7f) << 24
        | (ord($hash[$offset + 1]) & 0xff) << 16
        | (ord($hash[$offset + 2]) & 0xff) << 8
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($part % 1000000), 6, '0', STR_PAD_LEFT);
}

// Verify a submitted code against the current 30s window (±1 step for clock drift).
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $counter = (int) floor(time() / 30);
    for ($w = -1; $w <= 1; $w++) {
        if (hash_equals(totp_code($secret, $counter + $w), $code)) return true;
    }
    return false;
}

// ----- In-app notifications (the bell) -----
// Insert a notification for one user (or null = broadcast to all staff).
function notify(mysqli $conn, ?int $userId, string $title, string $body = '', string $type = 'info', ?string $url = null): void
{
    $title = mb_substr($title, 0, 190);
    $body = mb_substr($body, 0, 500);
    $stmt = $conn->prepare('INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $userId, $type, $title, $body, $url);
    $stmt->execute();
}
