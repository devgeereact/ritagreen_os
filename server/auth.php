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

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        'INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $stmt->bind_param('is', $uid, $hash);
    $stmt->execute();

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
        ],
    ]);
}

if ($action === 'me' && method() === 'GET') {
    $user = require_auth($conn);
    $user['id'] = (int) $user['id'];
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
        if (function_exists('mail_enabled') && mail_enabled()) {
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

fail('Unknown action.', 404);
