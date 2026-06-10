<?php
// Clients (CRM) endpoint.
// GET  /clients.php          -> { clients: [...] }
// GET  /clients.php?id=12    -> { client: {...}, sites: [...] }
// POST /clients.php {name, type, contact_name, email, phone} -> { client }
require __DIR__ . '/lib.php';

$user = require_auth($conn);

// Single client + its sites.
if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare(
        'SELECT id, name, type, contact_name, email, phone, divisions, status, created_at
         FROM clients WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) fail('Client not found.', 404);
    $client['id'] = (int) $client['id'];

    $stmt = $conn->prepare('SELECT id, name, address FROM sites WHERE client_id = ? AND deleted_at IS NULL ORDER BY name');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sites = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $sites[] = $row;
    }
    json_out(['client' => $client, 'sites' => $sites]);
}

// List clients (with a site count).
if (method() === 'GET') {
    $res = $conn->query(
        'SELECT c.id, c.name, c.type, c.contact_name, c.email, c.phone, c.divisions, c.status, c.created_at,
                (SELECT COUNT(*) FROM sites s WHERE s.client_id = c.id AND s.deleted_at IS NULL) AS sites_count
         FROM clients c WHERE c.deleted_at IS NULL ORDER BY c.name'
    );
    $clients = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['sites_count'] = (int) $row['sites_count'];
        $clients[] = $row;
    }
    json_out(['clients' => $clients]);
}

// Create a client (office roles only).
if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $type = trim((string) ($data['type'] ?? 'private'));
    $contact = trim((string) ($data['contact_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));

    if ($name === '') fail('A client name is required.');
    $allowedTypes = ['care_home', 'nhs', 'landlord', 'event', 'private'];
    if (!in_array($type, $allowedTypes, true)) fail('Invalid client type.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');

    $stmt = $conn->prepare(
        'INSERT INTO clients (name, type, contact_name, email, phone, status) VALUES (?, ?, ?, ?, ?, "prospect")'
    );
    $stmt->bind_param('sssss', $name, $type, $contact, $email, $phone);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'client.create', $name);

    json_out([
        'client' => [
            'id' => (int) $id,
            'name' => $name,
            'type' => $type,
            'contact_name' => $contact,
            'email' => $email,
            'phone' => $phone,
            'divisions' => '',
            'status' => 'prospect',
        ],
    ], 201);
}

fail('Method not allowed.', 405);
