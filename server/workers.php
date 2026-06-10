<?php
// Workers / staff endpoint.
// GET  /workers.php  -> { workers: [...] }
// POST /workers.php {name, email, phone, role, division, right_to_work} -> { worker }
require __DIR__ . '/lib.php';

$user = require_auth($conn);

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT id, name, email, phone, role, division, status, right_to_work,
                dbs_status, dbs_expiry, sia_number, sia_expiry, created_at
         FROM workers WHERE deleted_at IS NULL ORDER BY name'
    );
    $workers = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['right_to_work'] = (bool) $row['right_to_work'];
        $workers[] = $row;
    }
    json_out(['workers' => $workers]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $role = trim((string) ($data['role'] ?? 'carer'));
    $division = trim((string) ($data['division'] ?? 'care'));
    $rtw = !empty($data['right_to_work']) ? 1 : 0;

    if ($name === '') fail('The worker name is required.');
    $allowedRoles = ['carer', 'nurse', 'cleaner', 'guard', 'trainer'];
    $allowedDiv = ['care', 'academy', 'cleaning', 'security'];
    if (!in_array($role, $allowedRoles, true)) fail('Invalid role.');
    if (!in_array($division, $allowedDiv, true)) fail('Invalid division.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');

    $stmt = $conn->prepare(
        'INSERT INTO workers (name, email, phone, role, division, right_to_work, status)
         VALUES (?, ?, ?, ?, ?, ?, "onboarding")'
    );
    $stmt->bind_param('sssssi', $name, $email, $phone, $role, $division, $rtw);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'worker.create', $name);

    json_out([
        'worker' => [
            'id' => (int) $id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'division' => $division,
            'status' => 'onboarding',
            'right_to_work' => (bool) $rtw,
            'dbs_status' => null,
            'dbs_expiry' => null,
            'sia_number' => null,
            'sia_expiry' => null,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
