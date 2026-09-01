<?php
// Authentication endpoint.
// POST /auth.php?action=login   {email, password, code?}  -> { token, user } | { twofa_required:true }
// GET  /auth.php?action=me                                 -> { user }
// POST /auth.php?action=logout                             -> { ok:true }
// POST /auth.php?action=forgot  {email}                    -> { ok:true } (no enumeration)
// POST /auth.php?action=reset   {token, password}          -> { ok:true }
// 2FA (authenticated):
// POST /auth.php?action=2fa_setup                          -> { secret, otpauth }
// POST /auth.php?action=2fa_enable  {code}                 -> { ok:true }
// POST /auth.php?action=2fa_disable {code}                 -> { ok:true }
// GET  /auth.php?action=2fa_status                         -> { enabled }
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// Does this user have 2FA enabled?
function twofa_on(mysqli $conn, int $uid): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM user_2fa WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

// Must this user set up 2FA before using the app? (policy + privileged role + off)
function needs_2fa_setup(mysqli $conn, string $role, int $uid): bool
{
    if (!defined('MANDATORY_2FA') || !MANDATORY_2FA) return false;
    if (!in_array($role, ['super_admin', 'admin', 'manager'], true)) return false;
    return !twofa_on($conn, $uid);
}

// Issue a 30-day token for a user, recording device + IP for the session list.
function issue_token(mysqli $conn, int $uid): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = client_ip();
    $stmt = $conn->prepare(
        'INSERT INTO tokens (user_id, token_hash, expires_at, user_agent, ip, last_seen_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, ?, NOW())'
    );
    $stmt->bind_param('isss', $uid, $hash, $ua, $ip);
    $stmt->execute();
    return $token;
}

if ($action === 'login' && method() === 'POST') {
    rate_limit($conn, 'login', 10, 300);

    $data = body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $code = trim((string) ($data['code'] ?? ''));
    if ($email === '' || $password === '') fail('Email and password are required.');

    $stmt = $conn->prepare(
        'SELECT id, name, email, role, division, password_hash
         FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail('Those details do not match. Check your email and password.', 401);
    }
    $uid = (int) $user['id'];

    // Second factor, if the user has it enabled.
    $stmt = $conn->prepare('SELECT secret FROM user_2fa WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $twofa = $stmt->get_result()->fetch_assoc();
    if ($twofa) {
        if ($code === '') json_out(['twofa_required' => true]);
        if (!totp_verify($twofa['secret'], $code)) fail('That authentication code is not valid.', 401);
    }

    $token = issue_token($conn, $uid);

    audit($conn, $uid, 'login', $email);

    // Carry client_id/worker_id so the SPA can route to the portal / worker app.
    $stmt = $conn->prepare('SELECT client_id, worker_id FROM users WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc() ?: ['client_id' => null, 'worker_id' => null];

    json_out([
        'token' => $token,
        'user' => [
            'id' => $uid, 'name' => $user['name'], 'email' => $user['email'],
            'role' => $user['role'], 'division' => $user['division'],
            'client_id' => $link['client_id'] !== null ? (int) $link['client_id'] : null,
            'worker_id' => $link['worker_id'] !== null ? (int) $link['worker_id'] : null,
            'must_setup_2fa' => needs_2fa_setup($conn, $user['role'], $uid),
        ],
    ]);
}

if ($action === 'me' && method() === 'GET') {
    $user = require_auth($conn);
    $user['id'] = (int) $user['id'];
    $user['must_setup_2fa'] = needs_2fa_setup($conn, $user['role'], (int) $user['id']);
    json_out(['user' => $user]);
}

if ($action === 'logout' && method() === 'POST') {
    $token = bearer_token();
    if ($token) {
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare('DELETE FROM tokens WHERE token_hash = ?');
        $stmt->bind_param('s', $hash);
        $stmt->execute();
    }
    json_out(['ok' => true]);
}

// ----- Forgot / reset password -----
if ($action === 'forgot' && method() === 'POST') {
    rate_limit($conn, 'forgot', 5, 600);
    $data = body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');

    $stmt = $conn->prepare('SELECT id, name FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $uid = (int) $user['id'];
        $stmt = $conn->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $stmt->bind_param('is', $uid, $hash);
        $stmt->execute();

        $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : '';
        $link = $base . '/reset/' . $token;
        // Queued regardless of whether mail is on -- see server/send-queued-mail.php.
    {
            // Synchronous on purpose: somebody is sitting on the reset screen
            // waiting for this. Queue latency here reads as a broken feature.
            send_mail($email, 'Reset your RitaGreen password',
                "Hi {$user['name']},\n\nReset your password using this link (valid for 1 hour):\n$link\n\n"
                . "If you didn't request this, ignore this email.\n\nRitaGreen Services");
        }
        audit($conn, $uid, 'password.forgot', $email);
    }
    // Always succeed — never reveal whether an email exists.
    json_out(['ok' => true]);
}

if ($action === 'reset' && method() === 'POST') {
    rate_limit($conn, 'reset', 10, 600);
    $data = body();
    $token = trim((string) ($data['token'] ?? ''));
    $password = (string) ($data['password'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) fail('Invalid reset link.', 400);
    if (strlen($password) < 8) fail('Choose a password of at least 8 characters.');

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        'SELECT id, user_id FROM password_resets
         WHERE token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('This reset link is invalid or has expired.', 400);

    $uid = (int) $row['user_id'];
    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $newHash, $uid);
    $stmt->execute();

    // Burn the token and revoke all sessions for safety.
    $rid = (int) $row['id'];
    $conn->query('UPDATE password_resets SET used = 1 WHERE id = ' . $rid);
    $stmt = $conn->prepare('DELETE FROM tokens WHERE user_id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();

    audit($conn, $uid, 'password.reset', '');
    json_out(['ok' => true]);
}

// ----- 2FA management (authenticated) -----
if ($action === '2fa_status' && method() === 'GET') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $stmt = $conn->prepare('SELECT enabled FROM user_2fa WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    json_out(['enabled' => $row ? (bool) $row['enabled'] : false]);
}

if ($action === '2fa_setup' && method() === 'POST') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $secret = totp_secret();
    // Upsert a not-yet-enabled secret.
    $stmt = $conn->prepare(
        'INSERT INTO user_2fa (user_id, secret, enabled) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0'
    );
    $stmt->bind_param('is', $uid, $secret);
    $stmt->execute();
    $issuer = 'RitaGreen';
    $label = rawurlencode($issuer . ':' . $user['email']);
    $otpauth = "otpauth://totp/$label?secret=$secret&issuer=$issuer&digits=6&period=30";
    json_out(['secret' => $secret, 'otpauth' => $otpauth]);
}

if ($action === '2fa_enable' && method() === 'POST') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $code = trim((string) (body()['code'] ?? ''));
    $stmt = $conn->prepare('SELECT secret FROM user_2fa WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Start 2FA setup first.', 400);
    if (!totp_verify($row['secret'], $code)) fail('That code is not valid. Try again.', 400);
    $conn->query('UPDATE user_2fa SET enabled = 1 WHERE user_id = ' . $uid);
    audit($conn, $uid, '2fa.enable', '');
    json_out(['ok' => true]);
}

if ($action === '2fa_disable' && method() === 'POST') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $code = trim((string) (body()['code'] ?? ''));
    $stmt = $conn->prepare('SELECT secret FROM user_2fa WHERE user_id = ? AND enabled = 1 LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) json_out(['ok' => true]); // already off
    if (!totp_verify($row['secret'], $code)) fail('Enter a valid code to turn 2FA off.', 400);
    $conn->query('DELETE FROM user_2fa WHERE user_id = ' . $uid);
    audit($conn, $uid, '2fa.disable', '');
    json_out(['ok' => true]);
}

// ----- Active sessions / devices -----
if ($action === 'sessions' && method() === 'GET') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $current = bearer_token();
    $currentHash = $current ? hash('sha256', $current) : '';
    $stmt = $conn->prepare(
        'SELECT id, token_hash, user_agent, ip, created_at, last_seen_at, expires_at
         FROM tokens WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $sessions = [];
    while ($r = $res->fetch_assoc()) {
        $sessions[] = [
            'id' => (int) $r['id'],
            'user_agent' => $r['user_agent'],
            'ip' => $r['ip'],
            'created_at' => $r['created_at'],
            'last_seen_at' => $r['last_seen_at'],
            'current' => hash_equals($currentHash, $r['token_hash']),
        ];
    }
    json_out(['sessions' => $sessions]);
}

if ($action === 'revoke_session' && method() === 'POST') {
    $user = require_auth($conn);
    $uid = (int) $user['id'];
    $id = (int) (body()['id'] ?? 0);
    if ($id <= 0) fail('A session is required.');
    $stmt = $conn->prepare('DELETE FROM tokens WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    audit($conn, $uid, 'session.revoke', "#$id");
    json_out(['ok' => true]);
}

// Lock screen: re-verify the current user's password (no new token issued).
if ($action === 'unlock' && method() === 'POST') {
    $user = require_auth($conn);
    rate_limit($conn, 'unlock', 10, 300);
    $password = (string) (body()['password'] ?? '');
    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL');
    $uid = (int) $user['id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !password_verify($password, $row['password_hash'])) fail('Incorrect password.', 401);
    json_out(['ok' => true]);
}

// ----- Worker self-registration (creates an ATS applicant) -----
if ($action === 'register' && method() === 'POST') {
    rate_limit($conn, 'register', 6, 600);
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $phone = trim((string) ($data['phone'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    $role = trim((string) ($data['role_applied'] ?? ''));
    $consent = !empty($data['consent']);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A name and valid email are required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Choose a division.');
    if (!$consent) fail('Please agree to be contacted.');

    $roleVal = $role !== '' ? $role : null;
    $phoneVal = $phone !== '' ? $phone : null;
    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, phone, role_applied, division, source) VALUES (?, ?, ?, ?, ?, ?, "self-registration")'
    );
    // Self-registration creates an application, not a login -- but it still
    // resolves to a person, so someone who later applies through the site does
    // not become a second identity.
    $personId = find_or_create_person($conn, $name, $email, $phoneVal);
    $stmt->bind_param('isssss', $personId, $name, $email, $phoneVal, $roleVal, $division);
    $stmt->execute();
    $ip = client_ip();
    $stmt = $conn->prepare('INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "registration", 1, "self-registration", ?)');
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    notify($conn, null, 'New worker registration', "$name — " . ucfirst($division), 'ats', '/app/ats');
    if (function_exists('mail_enabled') && mail_enabled()) {
        queue_mail($conn, MAIL_TO, "Worker registration — $division", "$name <$email> ($phone)\nRole: $role", $email);
    }
    json_out(['ok' => true], 201);
}

// ----- Google SSO (staff sign-in; matches existing users only) -----
function google_configured(): bool
{
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_ID !== 'CHANGE_ME'
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '' && GOOGLE_CLIENT_SECRET !== 'CHANGE_ME';
}

if ($action === 'google_config' && method() === 'GET') {
    json_out(['enabled' => google_configured()]);
}

if ($action === 'google_start' && method() === 'GET') {
    if (!google_configured()) fail('Google sign-in is not configured.', 503);
    $params = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'access_type' => 'online',
        'prompt' => 'select_account',
    ]);
    json_out(['url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params]);
}

if ($action === 'google_callback' && method() === 'POST') {
    if (!google_configured()) fail('Google sign-in is not configured.', 503);
    rate_limit($conn, 'google', 20, 300);
    $code = trim((string) (body()['code'] ?? ''));
    if ($code === '') fail('Missing authorization code.', 400);

    // Exchange the code for tokens.
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $tokRes = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $accessToken = $tokRes['access_token'] ?? '';
    if ($accessToken === '') fail('Google sign-in failed (token exchange).', 401);

    // Fetch the verified email.
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 15,
    ]);
    $info = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $email = strtolower(trim((string) ($info['email'] ?? '')));
    $verified = !empty($info['email_verified']);
    if ($email === '' || !$verified) fail('Could not verify your Google email.', 401);

    // Match an existing, active staff user — never auto-create (staff access only).
    $stmt = $conn->prepare('SELECT id, name, email, role, division, client_id, worker_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) fail('No RitaGreen account for that Google email. Ask an administrator to add you.', 403);
    $uid = (int) $user['id'];

    $token = issue_token($conn, $uid);
    audit($conn, $uid, 'login.google', $email);
    json_out([
        'token' => $token,
        'user' => [
            'id' => $uid, 'name' => $user['name'], 'email' => $user['email'],
            'role' => $user['role'], 'division' => $user['division'],
            'client_id' => $user['client_id'] !== null ? (int) $user['client_id'] : null,
            'worker_id' => $user['worker_id'] !== null ? (int) $user['worker_id'] : null,
            'must_setup_2fa' => needs_2fa_setup($conn, $user['role'], $uid),
        ],
    ]);
}

fail('Unknown action.', 404);
