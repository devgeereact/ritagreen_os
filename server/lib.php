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
