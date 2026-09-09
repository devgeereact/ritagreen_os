<?php
// Invoicing & payments (manual reconciliation — no card processor wired yet).
// GET  /invoices.php                     -> { invoices: [...], totals: {...} }
// POST /invoices.php {client_id?, division, net_pence} -> creates with 20% VAT
// POST /invoices.php?action=pay&id=12    -> mark as paid
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// The sales ledger. Reads were unguarded; the writes below already were.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

const PAYMENT_TERMS_DAYS = 30;

if (method() === 'GET') {
    // Pull invoices with any credit notes netted off, plus an effective due date
    // (stored due_date, or invoice date + terms for older rows without one).
    $res = $conn->query(
        'SELECT i.id, i.number, i.client_id, c.name AS client, i.division,
                i.net_pence, i.vat_pence, i.total_pence, i.amount_paid_pence, i.pay_token, i.status, i.created_at,
                COALESCE(i.due_date, DATE_ADD(DATE(i.created_at), INTERVAL ' . PAYMENT_TERMS_DAYS . ' DAY)) AS due_date,
                COALESCE((SELECT SUM(cn.amount_pence) FROM credit_notes cn WHERE cn.invoice_id = i.id), 0) AS credited_pence,
                DATEDIFF(CURDATE(), COALESCE(i.due_date, DATE_ADD(DATE(i.created_at), INTERVAL ' . PAYMENT_TERMS_DAYS . ' DAY))) AS days_overdue
         FROM invoices i LEFT JOIN clients c ON c.id = i.client_id
         ORDER BY i.created_at DESC'
    );
    $invoices = [];
    $outstanding = 0;
    $paid = 0;
    // Aged-debt buckets (on the outstanding balance, net of credits).
    $aged = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0];
    $agedItems = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['net_pence'] = (int) $row['net_pence'];
        $row['vat_pence'] = (int) $row['vat_pence'];
        $row['total_pence'] = (int) $row['total_pence'];
        $row['amount_paid_pence'] = (int) $row['amount_paid_pence'];
        $row['has_pay_link'] = $row['pay_token'] !== null;
        unset($row['pay_token']);
        $row['credited_pence'] = (int) $row['credited_pence'];
        $row['days_overdue'] = (int) $row['days_overdue'];
        $balance = max(0, $row['total_pence'] - $row['credited_pence']);

        if ($row['status'] === 'paid') {
            $paid += $balance;
        } elseif (in_array($row['status'], ['sent', 'overdue'], true)) {
            $outstanding += $balance;
            $d = $row['days_overdue'];
            $bucket = $d <= 0 ? 'current' : ($d <= 30 ? 'd1_30' : ($d <= 60 ? 'd31_60' : ($d <= 90 ? 'd61_90' : 'd90_plus')));
            $aged[$bucket] += $balance;
            if ($balance > 0) {
                $agedItems[] = [
                    'id' => $row['id'], 'number' => $row['number'], 'client' => $row['client'],
                    'balance_pence' => $balance, 'days_overdue' => max(0, $d), 'bucket' => $bucket,
                ];
            }
        }
        $invoices[] = $row;
    }
    // Worst (most overdue) first for the chase list.
    usort($agedItems, fn($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

    json_out([
        'invoices' => $invoices,
        'totals' => ['outstanding_pence' => $outstanding, 'paid_pence' => $paid],
        'aged' => ['buckets' => $aged, 'items' => $agedItems],
    ]);
}

if ($action === 'pay' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An invoice is required.');

    // Three outcomes worth telling apart. It reported success for all of them:
    // a mistyped id, an invoice a credit note has voided, and a real payment.
    $stmt = $conn->prepare('SELECT status FROM invoices WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    if (!$invoice) fail('Invoice not found.', 404);
    if ($invoice['status'] === 'void') fail('That invoice has been voided by a credit note.', 409);
    if ($invoice['status'] === 'paid') json_out(['ok' => true]);   // already settled

    // amount_paid_pence is set here as payments.php and gocardless.php both set
    // it on their own confirmations. Marking an invoice paid by hand left it at
    // zero, so the same invoice read as paid in one column and unpaid in another.
    $stmt = $conn->prepare('UPDATE invoices SET status = "paid", amount_paid_pence = total_pence WHERE id = ?');
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

    // Sequential invoice number: INV-<year>-####. Atomic per-year counter so
    // concurrent creates never collide on the UNIQUE number.
    $year = date('Y');
    $seq = next_seq($conn, "invoice-$year");
    $number = sprintf('INV-%s-%04d', $year, $seq);
    $dueDate = date('Y-m-d', strtotime('+' . PAYMENT_TERMS_DAYS . ' days'));

    // A client_id that does not exist reached the INSERT and came back as a
    // foreign-key 500 with the constraint name in it, plus a logged exception,
    // for what is only a stale id from a page left open.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    $stmt = $conn->prepare(
        'INSERT INTO invoices (number, client_id, division, net_pence, vat_pence, total_pence, status, due_date)
         VALUES (?, ?, ?, ?, ?, ?, "sent", ?)'
    );
    $stmt->bind_param('sisiiis', $number, $clientId, $division, $net, $vat, $total, $dueDate);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'invoice.create', $number);
    json_out([
        'invoice' => [
            'id' => (int) $id, 'number' => $number, 'client_id' => $clientId, 'client' => null,
            'division' => $division, 'net_pence' => $net, 'vat_pence' => $vat,
            'total_pence' => $total, 'status' => 'sent', 'due_date' => $dueDate,
            'credited_pence' => 0, 'days_overdue' => -PAYMENT_TERMS_DAYS,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
