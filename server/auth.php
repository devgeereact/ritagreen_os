<?php
// Authentication endpoint.
// POST /auth.php?action=login  {email, password} -> { token, user }
// GET  /auth.php?action=me                        -> { user }
// POST /auth.php?action=logout                     -> { ok: true }
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

if ($action === 'login' && method() === 'POST') {
    // Throttle brute-force: max 10 attempts per IP per 5 minutes.
    rate_limit($conn, 'login', 10, 300);

    $data = body();
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
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

    // Issue a token: store only the SHA-256 hash, return the raw token once.
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $stmt->bind_param('is', $uid, $hash);
    $stmt->execute();

    audit($conn, $uid, 'login', $email);

    json_out([
        'token' => $token,
        'user' => [
            'id' => $uid,
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'division' => $user['division'],
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

fail('Unknown action.', 404);
