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
    echo json_encode(['error' => 'config.php is missing on the server. Upload it to the document root.']);
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
        'SELECT u.id, u.name, u.email, u.role, u.division
         FROM tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ?: null;
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

// Write an audit log row for sensitive actions.
function audit(mysqli $conn, ?int $userId, string $action, string $detail = ''): void
{
    $stmt = $conn->prepare('INSERT INTO audit_log (user_id, action, detail) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $detail);
    $stmt->execute();
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

    $transport = $secure === 'ssl' ? "ssl://$host" : $host;
    $fp = @stream_socket_client(
        "$transport:$port",
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
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
