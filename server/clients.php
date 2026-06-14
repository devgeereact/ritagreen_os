<?php
// Clients (CRM) endpoint.
// GET  /clients.php          -> { clients: [...] }
// GET  /clients.php?id=12    -> { client: {...}, sites: [...] }
// POST /clients.php {name, type, contact_name, email, phone} -> { client }
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

// Single client + its sites + rate cards + a financial summary.
if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare(
        'SELECT c.id, c.name, c.type, c.contact_name, c.email, c.phone, c.divisions, c.status,
                c.stage, c.account_manager_id, c.sla_response_hours, c.created_at,
                u.name AS account_manager
         FROM clients c LEFT JOIN users u ON u.id = c.account_manager_id
         WHERE c.id = ? AND c.deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) fail('Client not found.', 404);
    $client['id'] = (int) $client['id'];
    $client['account_manager_id'] = $client['account_manager_id'] !== null ? (int) $client['account_manager_id'] : null;
    $client['sla_response_hours'] = $client['sla_response_hours'] !== null ? (int) $client['sla_response_hours'] : null;

    $stmt = $conn->prepare('SELECT id, name, address FROM sites WHERE client_id = ? AND deleted_at IS NULL ORDER BY name');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sites = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $sites[] = $row;
    }

    // Rate cards.
    $stmt = $conn->prepare('SELECT id, division, role, charge_pence FROM rate_cards WHERE client_id = ? ORDER BY division, role');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rateCards = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['charge_pence'] = (int) $row['charge_pence'];
        $rateCards[] = $row;
    }

    // Financial summary: quoted (open), invoiced (net, non-void), paid, outstanding.
    $fin = ['quoted_pence' => 0, 'invoiced_pence' => 0, 'paid_pence' => 0, 'outstanding_pence' => 0];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_pence),0) FROM quotes WHERE client_id = ? AND status IN ('draft','sent')");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fin['quoted_pence'] = (int) $stmt->get_result()->fetch_row()[0];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(net_pence),0) FROM invoices WHERE client_id = ? AND status != 'void'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fin['invoiced_pence'] = (int) $stmt->get_result()->fetch_row()[0];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE client_id = ? AND status = 'paid'");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fin['paid_pence'] = (int) $stmt->get_result()->fetch_row()[0];
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE client_id = ? AND status IN ('sent','overdue')");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $fin['outstanding_pence'] = (int) $stmt->get_result()->fetch_row()[0];

    json_out(['client' => $client, 'sites' => $sites, 'rate_cards' => $rateCards, 'financials' => $fin]);
}

// List clients (with a site count + pipeline stage + account manager).
if (method() === 'GET') {
    $res = $conn->query(
        'SELECT c.id, c.name, c.type, c.contact_name, c.email, c.phone, c.divisions, c.status,
                c.stage, c.account_manager_id, u.name AS account_manager, c.created_at,
                (SELECT COUNT(*) FROM sites s WHERE s.client_id = c.id AND s.deleted_at IS NULL) AS sites_count
         FROM clients c LEFT JOIN users u ON u.id = c.account_manager_id
         WHERE c.deleted_at IS NULL ORDER BY c.name'
    );
    $clients = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['sites_count'] = (int) $row['sites_count'];
        $row['account_manager_id'] = $row['account_manager_id'] !== null ? (int) $row['account_manager_id'] : null;
        $clients[] = $row;
    }
    json_out(['clients' => $clients]);
}

// Update pipeline stage / account manager / SLA / status.
if ($action === 'update' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A client is required.');
    $data = body();
    $stage = trim((string) ($data['stage'] ?? ''));
    $status = trim((string) ($data['status'] ?? ''));
    $amId = isset($data['account_manager_id']) && $data['account_manager_id'] !== '' ? (int) $data['account_manager_id'] : null;
    $sla = isset($data['sla_response_hours']) && $data['sla_response_hours'] !== '' ? (int) $data['sla_response_hours'] : null;
    if ($stage !== '' && !in_array($stage, ['lead', 'qualified', 'proposal', 'won', 'lost'], true)) fail('Invalid stage.');
    if ($status !== '' && !in_array($status, ['active', 'prospect', 'paused'], true)) fail('Invalid status.');

    $stmt = $conn->prepare(
        'UPDATE clients SET
            stage = COALESCE(NULLIF(?, ""), stage),
            status = COALESCE(NULLIF(?, ""), status),
            account_manager_id = ?,
            sla_response_hours = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ssiii', $stage, $status, $amId, $sla, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'client.update', "#$id stage=$stage");
    json_out(['ok' => true]);
}

// Add a rate card. POST /clients.php?action=rate_card&id=N {division, role, charge_pence}
if ($action === 'rate_card' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $division = trim((string) ($data['division'] ?? ''));
    $role = trim((string) ($data['role'] ?? 'standard'));
    $charge = (int) round((float) ($data['charge_pence'] ?? 0));
    if ($id <= 0 || !in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Client and division are required.');
    if ($charge <= 0) fail('Enter a charge greater than zero.');
    if ($role === '') $role = 'standard';
    $stmt = $conn->prepare('INSERT INTO rate_cards (client_id, division, role, charge_pence) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('issi', $id, $division, $role, $charge);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'rate_card.create', "client #$id $division/$role");
    json_out(['ok' => true], 201);
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
