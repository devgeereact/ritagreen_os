<?php
// User & role management (admin only).
// GET  /users.php                          -> { users: [...] }
// POST /users.php {name, email, role, division?, password} -> create
// POST /users.php?action=deactivate&id=5   -> soft-delete a user
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

const ROLES = ['super_admin', 'admin', 'manager', 'trainer', 'editor', 'employee', 'client'];
const DIVS = ['care', 'academy', 'cleaning', 'security'];

if (method() === 'GET') {
    require_role($user, 'super_admin', 'admin');
    $res = $conn->query(
        'SELECT u.id, u.name, u.email, u.role, u.division, u.client_id, u.worker_id, u.created_at,
                (u.deleted_at IS NULL) AS active, c.name AS client, w.name AS worker
         FROM users u
         LEFT JOIN clients c ON c.id = u.client_id
         LEFT JOIN workers w ON w.id = u.worker_id
         ORDER BY u.name'
    );
    $users = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['active'] = (bool) $row['active'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $users[] = $row;
    }
    json_out(['users' => $users]);
}

if ($action === 'deactivate' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A user is required.');
    if ($id === (int) $user['id']) fail('You cannot deactivate your own account.', 409);

    $stmt = $conn->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    // Already deactivated is not an error; no such account is.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'users', $id, false)) fail('User not found.', 404);
    // Revoke their tokens so access stops immediately.
    $del = $conn->prepare('DELETE FROM tokens WHERE user_id = ?');
    $del->bind_param('i', $id);
    $del->execute();

    audit($conn, (int) $user['id'], 'user.deactivate', "#$id", 'user', $id,
        ['active' => true], ['active' => false]);
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $role = trim((string) ($data['role'] ?? 'employee'));
    $division = trim((string) ($data['division'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($name === '' || $email === '') fail('Name and email are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');
    if (!in_array($role, ROLES, true)) fail('Invalid role.');
    if (strlen($password) < 8) fail('The password must be at least 8 characters.');

    // Only a super_admin can mint another super_admin.
    if ($role === 'super_admin' && $user['role'] !== 'super_admin') {
        fail('Only a super admin can create another super admin.', 403);
    }

    $divisionVal = in_array($division, DIVS, true) ? $division : null;

    // Optional account links: a client login (portal) or a worker login (app).
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $workerId = isset($data['worker_id']) && $data['worker_id'] !== '' ? (int) $data['worker_id'] : null;
    if ($clientId !== null) {
        $stmt = $conn->prepare('SELECT id FROM clients WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) fail('That client was not found.', 404);
    }
    if ($workerId !== null) {
        $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) fail('That worker was not found.', 404);
        // One login per worker.
        $stmt = $conn->prepare('SELECT id FROM users WHERE worker_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) fail('That worker already has a login.', 409);
    }

    // Email must be unique.
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) fail('A user with that email already exists.', 409);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    // A login belongs to a person. Where it is linked to a worker it must be the
    // SAME person as that worker, or the login and the employment record describe
    // two different humans -- the defect this milestone exists to remove.
    if ($workerId) {
        $lookup = $conn->prepare('SELECT person_id FROM workers WHERE id = ? LIMIT 1');
        $lookup->bind_param('i', $workerId);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        if (!$found) fail('That worker does not exist.', 404);
        $personId = (int) $found['person_id'];
    } else {
        $personId = find_or_create_person($conn, $name, $email, null);
    }

    $stmt = $conn->prepare('INSERT INTO users (person_id, name, email, password_hash, role, division, client_id, worker_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssssii', $personId, $name, $email, $hash, $role, $divisionVal, $clientId, $workerId);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'user.create', "$email ($role)");
    json_out([
        'user' => [
            'id' => (int) $id, 'name' => $name, 'email' => $email,
            'role' => $role, 'division' => $divisionVal, 'active' => true,
            'client_id' => $clientId, 'worker_id' => $workerId,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
