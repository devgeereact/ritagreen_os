<?php
// Invoicing & payments (manual reconciliation — no card processor wired yet).
// GET  /invoices.php                     -> { invoices: [...], totals: {...} }
// POST /invoices.php {client_id?, division, net_pence} -> creates with 20% VAT
// POST /invoices.php?action=pay&id=12    -> mark as paid
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT i.id, i.number, i.client_id, c.name AS client, i.division,
                i.net_pence, i.vat_pence, i.total_pence, i.status, i.created_at
         FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
         ORDER BY i.created_at DESC'
    );
    $invoices = [];
    $outstanding = 0;
    $paid = 0;
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['net_pence'] = (int) $row['net_pence'];
        $row['vat_pence'] = (int) $row['vat_pence'];
        $row['total_pence'] = (int) $row['total_pence'];
        if (in_array($row['status'], ['sent', 'overdue'], true)) $outstanding += $row['total_pence'];
        if ($row['status'] === 'paid') $paid += $row['total_pence'];
        $invoices[] = $row;
    }
    json_out(['invoices' => $invoices, 'totals' => ['outstanding_pence' => $outstanding, 'paid_pence' => $paid]]);
}

if ($action === 'pay' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An invoice is required.');
    $stmt = $conn->prepare('UPDATE invoices SET status = "paid" WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'invoice.paid', "#$id");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $division = trim((string) ($data['division'] ?? 'care'));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $net = (int) round((float) ($data['net_pence'] ?? 0));

    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if ($net <= 0) fail('Enter a net amount greater than zero.');

    $vat = (int) round($net * 0.20);     // UK standard rate
    $total = $net + $vat;

    // Sequential invoice number: INV-<year>-####
    $year = date('Y');
    $res = $conn->query('SELECT COUNT(*) FROM invoices');
    $seq = (int) ($res->fetch_row()[0] ?? 0) + 1;
    $number = sprintf('INV-%s-%04d', $year, $seq);

    $stmt = $conn->prepare(
        'INSERT INTO invoices (number, client_id, division, net_pence, vat_pence, total_pence, status)
         VALUES (?, ?, ?, ?, ?, ?, "sent")'
    );
    $stmt->bind_param('sisiii', $number, $clientId, $division, $net, $vat, $total);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'invoice.create', $number);
    json_out([
        'invoice' => [
            'id' => (int) $id, 'number' => $number, 'client_id' => $clientId, 'client' => null,
            'division' => $division, 'net_pence' => $net, 'vat_pence' => $vat,
            'total_pence' => $total, 'status' => 'sent',
        ],
    ], 201);
}

fail('Method not allowed.', 405);
