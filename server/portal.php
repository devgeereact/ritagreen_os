<?php
// §17 Client portal — self-service for a client-role user (users.client_id set).
// GET  /portal.php                  -> { client, requests, invoices, workers, contracts }
// POST /portal.php?action=request   -> raise a cover request
// POST /portal.php?action=issue     -> raise an issue (logged as a complaint incident)
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$clientId = $user['client_id'] !== null ? (int) $user['client_id'] : 0;
if ($clientId <= 0) fail('This account is not linked to a client.', 403);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $stmt = $conn->prepare('SELECT id, name, type, divisions FROM clients WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) fail('Client not found.', 404);
    $client['id'] = (int) $client['id'];

    // Cover requests.
    $stmt = $conn->prepare('SELECT id, division, detail, status, preferred_start, created_at FROM service_requests WHERE client_id = ? ORDER BY created_at DESC LIMIT 50');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $requests = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $row['id'] = (int) $row['id']; $requests[] = $row; }

    // Invoices.
    $stmt = $conn->prepare('SELECT number, division, total_pence, status, due_date, created_at FROM invoices WHERE client_id = ? AND status != "draft" ORDER BY created_at DESC LIMIT 50');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $invoices = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $row['total_pence'] = (int) $row['total_pence']; $invoices[] = $row; }

    // Assigned workers on upcoming shifts — the trust proof: name + credential status.
    $stmt = $conn->prepare(
        'SELECT DISTINCT w.id, w.name, w.role, w.division, w.right_to_work, w.dbs_expiry, w.sia_expiry
         FROM shifts s JOIN workers w ON w.id = s.worker_id
         WHERE s.client_id = ? AND s.starts_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND s.status = "scheduled"
         ORDER BY w.name'
    );
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $workers = [];
    $r = $stmt->get_result();
    $today = date('Y-m-d');
    while ($w = $r->fetch_assoc()) {
        $cleared = $w['right_to_work']
            && (!$w['dbs_expiry'] || $w['dbs_expiry'] >= $today)
            && (!$w['sia_expiry'] || $w['sia_expiry'] >= $today);
        $workers[] = [
            'id' => (int) $w['id'], 'name' => $w['name'], 'role' => $w['role'],
            'division' => $w['division'], 'cleared' => $cleared,
        ];
    }

    // Contracts.
    $stmt = $conn->prepare('SELECT title, division, frequency, sla_hours, start_date, end_date, status FROM contracts WHERE client_id = ? AND deleted_at IS NULL ORDER BY end_date DESC');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $contracts = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $contracts[] = $row;

    json_out(['client' => $client, 'requests' => $requests, 'invoices' => $invoices, 'workers' => $workers, 'contracts' => $contracts]);
}

if ($action === 'request' && method() === 'POST') {
    $data = body();
    $division = trim((string) ($data['division'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));
    $site = trim((string) ($data['site_name'] ?? ''));
    $start = trim((string) ($data['preferred_start'] ?? ''));
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true) || $detail === '') fail('A service and details are required.');

    $startVal = $start !== '' && strtotime($start) ? date('Y-m-d H:i:s', strtotime($start)) : null;
    $siteVal = $site !== '' ? $site : null;
    $name = $user['name']; $email = $user['email'];
    $stmt = $conn->prepare(
        'INSERT INTO service_requests (client_id, contact_name, email, division, site_name, detail, preferred_start)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssss', $clientId, $name, $email, $division, $siteVal, $detail, $startVal);
    $stmt->execute();
    notify($conn, null, 'Portal cover request', "$name ($division)", 'booking', '/app/bookings');
    json_out(['ok' => true], 201);
}

if ($action === 'issue' && method() === 'POST') {
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    if ($title === '' || $detail === '') fail('A subject and detail are required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) $division = 'care';

    $year = date('Y');
    $seq = next_seq($conn, "incident-$year");
    $ref = sprintf('INC-%s-%04d', $year, $seq);
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'INSERT INTO incidents (ref, division, type, severity, title, detail, client_id, reported_by, status)
         VALUES (?, ?, "complaint", "medium", ?, ?, ?, ?, "open")'
    );
    $stmt->bind_param('ssssii', $ref, $division, $title, $detail, $clientId, $uid);
    $stmt->execute();
    notify($conn, null, 'Client raised an issue', "$title ($ref)", 'incident', '/app/incidents');
    json_out(['ok' => true, 'ref' => $ref], 201);
}

fail('Method not allowed.', 405);
