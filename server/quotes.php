<?php
// Quoting & estimating.
// GET  /quotes.php                       -> { quotes: [...], totals: {...} }
// POST /quotes.php {title, division, net_pence, client_id?}  -> create (20% VAT)
// POST /quotes.php?action=status&id=5 {status}   -> sent | declined
// POST /quotes.php?action=accept&id=5            -> accept + auto-create an invoice
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT q.id, q.number, q.client_id, c.name AS client, q.division, q.title,
                q.net_pence, q.vat_pence, q.total_pence, q.status, q.invoice_id, q.created_at
         FROM quotes q LEFT JOIN clients c ON c.id = q.client_id
         ORDER BY q.created_at DESC'
    );
    $quotes = [];
    $open = 0;
    $won = 0;
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['net_pence'] = (int) $row['net_pence'];
        $row['vat_pence'] = (int) $row['vat_pence'];
        $row['total_pence'] = (int) $row['total_pence'];
        $row['invoice_id'] = $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null;
        if (in_array($row['status'], ['draft', 'sent'], true)) $open += $row['total_pence'];
        if ($row['status'] === 'accepted') $won += $row['total_pence'];
        $quotes[] = $row;
    }
    json_out(['quotes' => $quotes, 'totals' => ['open_pence' => $open, 'won_pence' => $won]]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['draft', 'sent', 'declined'], true)) fail('A quote and valid status are required.');
    $stmt = $conn->prepare('UPDATE quotes SET status = ? WHERE id = ? AND status NOT IN ("accepted")');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'quote.status', "#$id -> $status");
    json_out(['ok' => true]);
}

if ($action === 'accept' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A quote is required.');

    $stmt = $conn->prepare('SELECT * FROM quotes WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $q = $stmt->get_result()->fetch_assoc();
    if (!$q) fail('Quote not found.', 404);
    if ($q['status'] === 'accepted') fail('That quote has already been accepted.', 409);

    // Create an invoice mirroring the quote totals.
    $year = date('Y');
    $seq = (int) ($conn->query('SELECT COUNT(*) FROM invoices')->fetch_row()[0] ?? 0) + 1;
    $invNumber = sprintf('INV-%s-%04d', $year, $seq);
    $clientId = $q['client_id'] !== null ? (int) $q['client_id'] : null;
    $div = $q['division'];
    $net = (int) $q['net_pence'];
    $vat = (int) $q['vat_pence'];
    $total = (int) $q['total_pence'];

    $stmt = $conn->prepare(
        'INSERT INTO invoices (number, client_id, division, net_pence, vat_pence, total_pence, status)
         VALUES (?, ?, ?, ?, ?, ?, "sent")'
    );
    $stmt->bind_param('sisiii', $invNumber, $clientId, $div, $net, $vat, $total);
    $stmt->execute();
    $invoiceId = $conn->insert_id;

    $stmt = $conn->prepare('UPDATE quotes SET status = "accepted", invoice_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $invoiceId, $id);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'quote.accept', $q['number'] . " -> $invNumber");
    json_out(['ok' => true, 'invoice_number' => $invNumber], 201);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $net = (int) round((float) ($data['net_pence'] ?? 0));

    if ($title === '') fail('A quote title is required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if ($net <= 0) fail('Enter a net amount greater than zero.');

    $vat = (int) round($net * 0.20);
    $total = $net + $vat;
    $year = date('Y');
    $seq = (int) ($conn->query('SELECT COUNT(*) FROM quotes')->fetch_row()[0] ?? 0) + 1;
    $number = sprintf('QUO-%s-%04d', $year, $seq);

    $stmt = $conn->prepare(
        'INSERT INTO quotes (number, client_id, division, title, net_pence, vat_pence, total_pence, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "draft")'
    );
    $stmt->bind_param('sissiii', $number, $clientId, $division, $title, $net, $vat, $total);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'quote.create', $number);
    json_out([
        'quote' => [
            'id' => (int) $id, 'number' => $number, 'client_id' => $clientId, 'client' => null,
            'division' => $division, 'title' => $title, 'net_pence' => $net, 'vat_pence' => $vat,
            'total_pence' => $total, 'status' => 'draft', 'invoice_id' => null,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
