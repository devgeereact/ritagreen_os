<?php
// Clients (CRM) endpoint.
// GET  /clients.php          -> { clients: [...] }
// GET  /clients.php?id=12    -> { client: {...}, sites: [...] }
// POST /clients.php {name, type, contact_name, email, phone} -> { client }
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// The whole CRM: every client, their contacts, and per-client financials.
// Only the GET branches lacked this -- the writes below already carry it -- so
// any authenticated account, a client portal login included, could list every
// other client and read their invoiced and outstanding totals.
require_role($user, 'super_admin', 'admin', 'manager');
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
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'clients', $id)) fail('Client not found.', 404);
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
    // Without this the INSERT trips the foreign key and the caller gets a 500
    // and a logged exception for what is only a mistyped id.
    if (!row_exists($conn, 'clients', $id)) fail('Client not found.', 404);
    $stmt = $conn->prepare('INSERT INTO rate_cards (client_id, division, role, charge_pence) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('issi', $id, $division, $role, $charge);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'rate_card.create', "client #$id $division/$role");
    json_out(['ok' => true], 201);
}

// Create a client (office roles only).
// Which services this client buys.
// POST /clients.php?action=services&id=N {services: ["care","cleaning"]}
//
// Writes the junction table AND keeps the legacy clients.divisions CSV in step.
// The CSV cannot be joined, indexed or constrained -- "care" matches "healthcare"
// under LIKE -- but endpoints still read it, so the two are kept identical until
// nothing does. Two sources of one fact drift; keeping them in one place is the
// only version of this that is safe.
if ($action === 'services' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A client is required.');

    $valid = ['care', 'academy', 'cleaning', 'security'];
    $requested = body()['services'] ?? [];
    if (!is_array($requested)) fail('services must be a list.');
    $services = array_values(array_unique(array_filter(
        array_map(static fn($s): string => strtolower(trim((string) $s)), $requested),
        static fn(string $s): bool => in_array($s, ['care', 'academy', 'cleaning', 'security'], true)
    )));

    $stmt = $conn->prepare('SELECT divisions FROM clients WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) fail('Client not found.', 404);

    $stmt = $conn->prepare('DELETE FROM client_services WHERE client_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $ins = $conn->prepare('INSERT INTO client_services (client_id, service) VALUES (?, ?)');
    foreach ($services as $service) {
        $ins->bind_param('is', $id, $service);
        $ins->execute();
    }

    $csv = implode(',', $services);
    $stmt = $conn->prepare('UPDATE clients SET divisions = ? WHERE id = ?');
    $stmt->bind_param('si', $csv, $id);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'client.services', "#$id", 'client', $id,
        ['divisions' => $client['divisions']], ['divisions' => $csv]);
    json_out(['ok' => true, 'services' => $services]);
}

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

    audit($conn, (int) $user['id'], 'client.create', $name, 'client', (int) $id);

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
