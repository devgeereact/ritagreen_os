<?php
// Shared helpers for every RitaGreenOS endpoint.
// Each endpoint starts with: require __DIR__ . '/lib.php';
// which pulls in config.php ($conn) and sets up CORS + JSON helpers.

// Surface fatals / mysqli exceptions as JSON instead of a blank 500 HTML page.
// Reporting is switched to throw once the connection exists -- see below, after
// config.php is loaded.
mysqli_report(MYSQLI_REPORT_OFF);
set_exception_handler(function (Throwable $e) {
    // The full detail always goes to the server log; what goes back to the caller
    // depends on GK_DEBUG. This previously returned $e->getMessage() to whoever
    // made the request, regardless -- which hands an attacker table names, file
    // paths and query fragments from any input that provokes a fatal.
    //
    // The reference is echoed to the caller and logged alongside the detail, so a
    // user can quote it in a support request and it can be found in error_log
    // without them ever having seen the underlying error.
    $ref = bin2hex(random_bytes(4));
    error_log(sprintf(
        '[%s] %s: %s in %s:%d',
        $ref, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    $debug = defined('GK_DEBUG') && GK_DEBUG;
    echo json_encode($debug
        ? [
            'error' => 'Server error: ' . $e->getMessage(),
            'ref' => $ref,
            'where' => $e->getFile() . ':' . $e->getLine(),
        ]
        : ['error' => 'Server error. Quote reference ' . $ref . ' if you report this.', 'ref' => $ref]);
    exit;
});
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Pure domain logic — no database, no config, no output. Required first so every
// endpoint has it, and so server/tests/run.php can include it on its own.
require_once __DIR__ . '/pure.php';

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

// From here on a failed query THROWS instead of returning false.
//
// It was MYSQLI_REPORT_OFF throughout, and almost no call site checks the return
// value of execute(), so a failed INSERT was silently ignored and the endpoint
// went on to report success. workers.php returned HTTP 200 with a complete
// worker object, id 0, for a row that was never written.
//
// Set after the connection is established so config.php keeps its own readable
// message for a connection failure. Anything thrown from here reaches the
// exception handler above, which logs the detail and returns a reference.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// One clock, in UTC, on both sides of the connection.
//
// Neither was pinned. PHP used the host's date.timezone and the connection used
// the MySQL server's default, so the two disagreed with each other by an hour
// for the seven months of British Summer Time -- measured on a developer
// machine: NOW() returned 21:47:21 while date() in the same request returned
// 20:47:21. Every created_at, updated_at and decided_at was written on one
// clock and every date comparison in PHP made on the other, so "today's shifts"
// in field.php and the retention sweep's cut-off both moved for the hour after
// midnight, and the same code gave different answers on a laptop and on cPanel.
//
// schema.sql has declared `SET time_zone = '+00:00'` since the first import;
// that only applied to the import itself, never to a request. This makes the
// connection honour what the schema already says.
//
// DATETIME columns -- shifts.starts_at, shifts.ends_at, work dates -- store a
// literal wall-clock value and are not converted, so the rota is unaffected.
// TIMESTAMP columns are converted on read, so on a server that was running
// something other than UTC an existing created_at now reads as the UTC instant
// it always was, which during BST is an hour earlier than it used to display.
date_default_timezone_set('UTC');
$conn->query("SET time_zone = '+00:00'");

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
        'SELECT u.id, u.name, u.email, u.role, u.division, u.scope_to_division, u.client_id, u.worker_id
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

// Require an internal staff account; 403 otherwise. Use on anything that shows
// one person's data to another — the office board, the shared mailbox, the
// workforce list.
function require_staff(array $user): void
{
    if (!is_staff($user)) fail('You do not have access to this.', 403);
}

/**
 * May this account see a category of information?
 *
 * Zones exist because a worker profile is not one undifferentiated record.
 * Health information, disciplinary matters and payroll are not ordinary fields,
 * and "can open the profile" must not mean "can see all of it".
 *
 * Cached per request: a profile touches several zones and this would otherwise
 * run the same query five times.
 */
function can_view_zone(mysqli $conn, array $user, string $zone): bool
{
    static $cache = [];
    $role = (string) ($user['role'] ?? '');
    $key = $role . '|' . $zone;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $conn->prepare('SELECT can_view FROM zone_permissions WHERE role_key = ? AND zone = ? LIMIT 1');
    $stmt->bind_param('ss', $role, $zone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    // No row means no permission. A zone nobody has configured must not be
    // readable by default.
    return $cache[$key] = (bool) ($row['can_view'] ?? false);
}

/** As above, for changing rather than reading. */
function can_edit_zone(mysqli $conn, array $user, string $zone): bool
{
    $stmt = $conn->prepare('SELECT can_edit FROM zone_permissions WHERE role_key = ? AND zone = ? LIMIT 1');
    $role = (string) ($user['role'] ?? '');
    $stmt->bind_param('ss', $role, $zone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (bool) ($row['can_edit'] ?? false);
}

/**
 * The service this account is limited to, or null for no limit.
 *
 * users.division has always been recorded but never enforced, so a Care
 * recruiter saw Security candidates -- which is not what "scoped to a division"
 * means. Enforcement is opt-in per account via scope_to_division, so switching
 * it on narrows nobody's access by surprise.
 *
 * super_admin and admin are never scoped: somebody has to see the whole picture.
 */
function service_scope(array $user): ?string
{
    if (in_array($user['role'] ?? '', ['super_admin', 'admin'], true)) return null;
    if (empty($user['scope_to_division'])) return null;
    $division = $user['division'] ?? null;
    return $division !== null && $division !== '' ? (string) $division : null;
}

/**
 * Find the person these details describe, or create them.
 *
 * Every path that creates a candidate, worker or login goes through here, which
 * is what stops the system growing a second copy of somebody. Matching uses the
 * same rules as the backfill (rg_person_match_key in pure.php): email first,
 * then phone, never a name.
 *
 * A person with neither a usable email nor a usable phone gets a NULL match_key
 * and is therefore always created fresh. That can leave a duplicate, which is
 * the safe direction to be wrong in -- merging two people is far harder to undo.
 */
function find_or_create_person(mysqli $conn, string $name, ?string $email = null, ?string $phone = null): int
{
    $match = rg_person_match_key(['email' => $email, 'phone' => $phone]);

    if ($match['key'] !== null) {
        $stmt = $conn->prepare('SELECT id FROM people WHERE match_key = ? LIMIT 1');
        $stmt->bind_param('s', $match['key']);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            // Fill in anything we did not have before, without overwriting what
            // is already recorded.
            $stmt = $conn->prepare(
                'UPDATE people SET email = COALESCE(email, ?), phone = COALESCE(phone, ?) WHERE id = ?'
            );
            $normalised = rg_normalise_email($email);
            $stmt->bind_param('ssi', $normalised, $phone, $row['id']);
            $stmt->execute();
            return (int) $row['id'];
        }
    }

    $normalised = rg_normalise_email($email);
    $stmt = $conn->prepare('INSERT INTO people (full_name, email, phone, match_key) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $name, $normalised, $phone, $match['key']);
    $stmt->execute();
    return (int) $conn->insert_id;
}

/**
 * The next payroll number. Unique, permanent, never reused.
 *
 * Deliberately not derived from the service: a person's service can change, and
 * a payroll number that implies one would then be wrong forever.
 */
function next_payroll_no(mysqli $conn): string
{
    return sprintf('RG-%06d', next_seq($conn, 'payroll'));
}

/**
 * Queue an email instead of sending it now.
 *
 * Nothing in a web request should open an SMTP socket. send_mail() does, so a
 * slow mail server slows the application, and a failure is lost -- nobody finds
 * out that an applicant never got their confirmation.
 *
 * Returns the queue id. Draining is server/send-queued-mail.php, run by cron.
 */
function queue_mail(
    mysqli $conn,
    string $to,
    string $subject,
    string $body,
    ?string $replyTo = null,
    ?string $templateKey = null,
    ?string $relatedType = null,
    ?int $relatedId = null,
    ?int $queuedBy = null,
    ?string $sendAfter = null
): int {
    $stmt = $conn->prepare(
        'INSERT INTO email_queue
           (recipient, subject, body, reply_to, template_key, related_type, related_id, queued_by, send_after)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssssiis', $to, $subject, $body, $replyTo, $templateKey, $relatedType, $relatedId, $queuedBy, $sendAfter);
    $stmt->execute();
    return (int) $conn->insert_id;
}

/**
 * Queue an email built from a stored template.
 *
 * Refuses rather than sending a half-filled message: an email that goes out
 * reading "Hi {{first_name}}," has already reached the recipient by the time
 * anybody notices. Returns the queue id, or throws with what was missing.
 */
function queue_templated_mail(
    mysqli $conn,
    string $templateKey,
    string $to,
    array $values,
    ?string $relatedType = null,
    ?int $relatedId = null,
    ?int $queuedBy = null
): int {
    $stmt = $conn->prepare('SELECT subject, body FROM email_templates WHERE template_key = ? AND active = 1 LIMIT 1');
    $stmt->bind_param('s', $templateKey);
    $stmt->execute();
    $tpl = $stmt->get_result()->fetch_assoc();
    if (!$tpl) fail("No active email template '$templateKey'.", 500);

    $subject = rg_render_template($tpl['subject'], $values);
    $body = rg_render_template($tpl['body'], $values);

    $missing = array_merge(rg_unfilled_placeholders($subject), rg_unfilled_placeholders($body));
    if ($missing) {
        fail("Cannot send '$templateKey': missing " . implode(', ', array_unique($missing)) . '.', 422);
    }

    return queue_mail($conn, $to, $subject, $body, null, $templateKey, $relatedType, $relatedId, $queuedBy);
}

/**
 * Write an audit log row for a sensitive action.
 *
 * The signature is unchanged, so every existing call site keeps working. The
 * optional arguments are what make an entry answerable months later: which
 * record it touched, what the values were before and after, why, and from where.
 *
 * $before and $after are arrays of the fields that changed -- pass only those,
 * not the whole row. An audit entry holding a copy of every field is a second
 * copy of the data, which is its own retention problem.
 */
function audit(
    mysqli $conn,
    ?int $userId,
    string $action,
    string $detail = '',
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $before = null,
    ?array $after = null,
    ?string $reason = null
): void {
    $beforeJson = $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null;
    $afterJson = $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null;
    $ip = client_ip();
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

    $stmt = $conn->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, detail, before_json, after_json, reason, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // user_id i | action s | entity_type s | entity_id i | detail s
    // before s | after s | reason s | ip s | user_agent s
    $stmt->bind_param(
        'ississssss',
        $userId, $action, $entityType, $entityId, $detail, $beforeJson, $afterJson, $reason, $ip, $agent
    );
    $stmt->execute();
}

/**
 * Only the fields that actually changed, as [before, after].
 *
 * Keeps an audit entry to the difference rather than a copy of the record.
 * Values are compared loosely as strings, because a column read back from MySQL
 * is a string and the value written was often an int.
 */
function audit_diff(array $before, array $after, array $fields): array
{
    $b = [];
    $a = [];
    foreach ($fields as $field) {
        $old = $before[$field] ?? null;
        $new = $after[$field] ?? null;
        if ((string) $old !== (string) $new) {
            $b[$field] = $old;
            $a[$field] = $new;
        }
    }
    return [$b, $a];
}

/**
 * The caller's IP address, for rate limiting and the audit trail.
 *
 * This used to read CF-Connecting-IP, then X-Forwarded-For, then REMOTE_ADDR,
 * taking whichever appeared first. Both of those headers are supplied by the
 * caller, so anybody could hand us a different one on every request and land in
 * a fresh rate-limit bucket each time. That defeated every rate_limit() call in
 * the codebase: login, password reset, the contact form, the public application
 * form. Verified against a running server -- fourteen login attempts with a
 * rotating X-Forwarded-For all returned 401, none returned 429.
 *
 * A proxy header is only meaningful if the hop that set it is one we trust. So
 * the socket peer is the default, and a forwarded header is read ONLY when
 * REMOTE_ADDR is a proxy listed in TRUSTED_PROXIES.
 *
 * On this deployment that default is also the correct answer: the origin sits
 * behind Cloudflare with IP restoration enabled, so REMOTE_ADDR already holds
 * the visitor's real address by the time PHP runs. TRUSTED_PROXIES exists for a
 * host where that is not true; leave it undefined and nothing is trusted.
 *
 * Trimmed to fit audit_log.ip / tokens.ip, both VARCHAR(45).
 */
function client_ip(): string
{
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $trusted = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES) ? TRUSTED_PROXIES : [];
    if ($peer !== '' && in_array($peer, $trusted, true)) {
        // Cloudflare sets CF-Connecting-IP to the single client address, so it
        // needs no list-splitting. X-Forwarded-For is a chain appended to by
        // each hop; the left-most entry is the original client.
        $forwarded = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $candidate = trim(explode(',', $forwarded)[0]);
            // Anything that is not an IP address is a forged header, not a hop.
            if (filter_var($candidate, FILTER_VALIDATE_IP)) return substr($candidate, 0, 45);
        }
    }

    if ($peer !== '' && filter_var($peer, FILTER_VALIDATE_IP)) return substr($peer, 0, 45);
    return '0.0.0.0';
}

/**
 * Sliding-window rate limit per (bucket, subject).
 *
 * Records one hit per call and fails with 429 once $max hits land inside
 * $windowSec. Throttles public and unauthenticated endpoints -- login, password
 * reset, the contact form, the public application form -- against brute force
 * and spam.
 *
 * $subject defaults to the caller's IP, which is what every existing call site
 * wants. Pass one explicitly to throttle something other than an address: the
 * login endpoint also limits per account, because a per-IP limit alone does
 * nothing about one password guessed from a thousand hosts. Never pass a raw
 * identifier -- hash it, so the table does not accumulate a list of the email
 * addresses people have tried to sign in as.
 *
 * Requires the `rate_limits` table (see schema.sql).
 */
function rate_limit(mysqli $conn, string $bucket, int $max, int $windowSec, ?string $subject = null): void
{
    $ip = $subject !== null ? substr($subject, 0, 45) : client_ip();
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

/**
 * Does this row exist? Used to tell "nothing changed" from "nothing there".
 *
 * mysqli's affected_rows is 0 both when an UPDATE matched no row AND when it
 * matched one whose value was already what was written, so a bare affected_rows
 * check would 404 an idempotent re-click. Ask only when nothing changed:
 *
 *     $stmt->execute();
 *     if ($stmt->affected_rows === 0 && !row_exists($conn, 'quotes', $id)) {
 *         fail('Quote not found.', 404);
 *     }
 *
 * Without it a status change against a deleted or mistyped id returned
 * {"ok": true} and wrote an audit entry for a record that does not exist.
 *
 * $table is a code literal at every call site and never user input. It cannot be
 * bound, so it must stay that way -- see docs/RULES.md rule 4.
 */
function row_exists(mysqli $conn, string $table, int $id, bool $respectSoftDelete = true): bool
{
    $sql = "SELECT id FROM `$table` WHERE id = ?";
    if ($respectSoftDelete) $sql .= ' AND deleted_at IS NULL';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
}

/**
 * How many rows would go with this one, per table, if it were deleted now.
 *
 * Read from the live foreign keys rather than a hand-kept list, so it cannot
 * drift out of step with the schema. Only ON DELETE CASCADE children are
 * counted: SET NULL keeps the row and loses the link, which is a different
 * thing and not data loss.
 *
 * Call BEFORE the delete. Returns [table => count], omitting empty ones.
 */
/**
 * Is this a local development database?
 *
 * Declared in config.php as RG_ENV, never inferred from DB_HOST. On cPanel the
 * PRODUCTION database is also on localhost, so the host answers "local" for the
 * live system — which meant `seed.php --confirm --reset` would have emptied the
 * client, worker and invoice tables of the real database, and `migrate.php --go`
 * would have applied DDL to it with neither --production nor the typed-name
 * confirmation. Both guards were written believing localhost meant a laptop.
 *
 * A config.php that predates RG_ENV is treated as production. Failing closed is
 * the only safe default for scripts that can wipe tables: the cost of being
 * wrong is a refusal and a one-line edit, not a restore from backup.
 */
function rg_is_local_database(): bool
{
    return defined('RG_ENV') && RG_ENV === 'local';
}

/** What to tell somebody whose config.php has not declared where it points. */
function rg_env_hint(): string
{
    if (defined('RG_ENV')) return '';
    return "config.php does not define RG_ENV, so this is treated as production.\n"
        . "Add one of these to server/config.php:\n"
        . "  define('RG_ENV', 'local');       // a development database on your own machine\n"
        . "  define('RG_ENV', 'production');  // the live database\n";
}

function cascade_counts(mysqli $conn, string $table, int $id): array
{
    $stmt = $conn->prepare(
        'SELECT k.TABLE_NAME, k.COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA = DATABASE()
           AND k.REFERENCED_TABLE_NAME = ?
           AND r.DELETE_RULE = "CASCADE"'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $children = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $children[] = $row;

    $counts = [];
    foreach ($children as $child) {
        // Both names come from information_schema, never from a request, and
        // neither can be bound. See docs/RULES.md rule 4.
        $childTable = (string) $child['TABLE_NAME'];
        $childColumn = (string) $child['COLUMN_NAME'];
        $q = $conn->prepare("SELECT COUNT(*) FROM `$childTable` WHERE `$childColumn` = ?");
        $q->bind_param('i', $id);
        $q->execute();
        $n = (int) $q->get_result()->fetch_row()[0];
        if ($n > 0) $counts[$childTable] = $n;
    }
    return $counts;
}

// ----- Marketing consent -----
//
// There is no marketing_preferences table. consent_log already records
// (email, kind, granted, source, ip), which is the right shape; it simply never
// held a granted = 0 row because nothing ever wrote one. An opt-out is a new
// row, never a deletion: erasing the record that somebody unsubscribed is
// precisely how they get signed up again by the next import.

/**
 * May we send marketing to this address?
 *
 * The latest row wins, and no row means no. Silence is not consent, and an
 * address we have never asked is an address we have not been given permission
 * to email.
 */
function marketing_allowed(mysqli $conn, string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '') return false;
    $stmt = $conn->prepare(
        'SELECT granted FROM consent_log WHERE email = ? AND kind = "marketing" ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row !== null && (int) $row['granted'] === 1;
}

/** Record a marketing choice, in either direction. */
function record_marketing_consent(mysqli $conn, string $email, bool $granted, string $source): void
{
    $email = mb_strtolower(trim($email));
    if ($email === '') return;
    $g = $granted ? 1 : 0;
    $ip = client_ip();
    $stmt = $conn->prepare(
        'INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "marketing", ?, ?, ?)'
    );
    $stmt->bind_param('siss', $email, $g, $source, $ip);
    $stmt->execute();
}

/**
 * The secret behind unsubscribe links.
 *
 * Not derived from anything else. A configuration this depends on should be
 * absent loudly rather than fall back to something that merely looks random.
 */
function app_secret(): string
{
    $s = defined('APP_SECRET') ? (string) APP_SECRET : '';
    return ($s === '' || $s === 'CHANGE_ME') ? '' : $s;
}

/** A one-click unsubscribe URL, or null when APP_SECRET has not been set. */
function unsubscribe_link(string $email): ?string
{
    $secret = app_secret();
    if ($secret === '') return null;
    $base = defined('PUBLIC_BASE_URL') ? rtrim((string) PUBLIC_BASE_URL, '/') : '';
    if ($base === '') return null;
    return $base . '/unsubscribe.php?e=' . rawurlencode(mb_strtolower(trim($email)))
        . '&t=' . rg_unsubscribe_token($email, $secret);
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
