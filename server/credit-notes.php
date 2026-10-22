<?php
// Credit notes / refunds against an invoice.
// GET  /credit-notes.php?invoice_id=N   -> { credit_notes: [...] }
// POST /credit-notes.php {invoice_id, amount_pence, reason} -> create
// When credits reach the invoice total, the invoice is marked void.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Credits against an invoice: a financial record. Reads were unguarded, so any
// authenticated account could enumerate credit notes by invoice id.
require_role($user, 'super_admin', 'admin', 'manager');

if (method() === 'GET') {
    $invoiceId = (int) ($_GET['invoice_id'] ?? 0);
    if ($invoiceId <= 0) fail('An invoice is required.');
    $stmt = $conn->prepare(
        'SELECT cn.id, cn.number, cn.invoice_id, cn.amount_pence, cn.reason, cn.created_at, u.name AS created_by
         FROM credit_notes cn LEFT JOIN users u ON u.id = cn.created_by
         WHERE cn.invoice_id = ? ORDER BY cn.created_at DESC'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $res = $stmt->get_result();
    $notes = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int) $r['id'];
        $r['invoice_id'] = (int) $r['invoice_id'];
        $r['amount_pence'] = (int) $r['amount_pence'];
        $notes[] = $r;
    }
    json_out(['credit_notes' => $notes]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $invoiceId = (int) ($data['invoice_id'] ?? 0);
    $amount = (int) round((float) ($data['amount_pence'] ?? 0));
    $reason = trim((string) ($data['reason'] ?? ''));

    if ($invoiceId <= 0) fail('An invoice is required.');
    if ($amount <= 0) fail('Enter a credit amount greater than zero.');

    // Invoice must exist; cap the credit at the remaining balance.
    $stmt = $conn->prepare(
        'SELECT total_pence,
                COALESCE((SELECT SUM(amount_pence) FROM credit_notes WHERE invoice_id = ?), 0) AS credited
         FROM invoices WHERE id = ?'
    );
    $stmt->bind_param('ii', $invoiceId, $invoiceId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    if (!$inv) fail('Invoice not found.', 404);
    $remaining = (int) $inv['total_pence'] - (int) $inv['credited'];
    if ($amount > $remaining) fail('That exceeds the remaining balance (' . $remaining . ' pence).');

    $year = date('Y');
    $seq = next_seq($conn, "credit-$year");
    $number = sprintf('CN-%s-%04d', $year, $seq);
    $reasonVal = $reason !== '' ? $reason : null;
    $uid = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO credit_notes (number, invoice_id, amount_pence, reason, created_by) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('siisi', $number, $invoiceId, $amount, $reasonVal, $uid);
    $stmt->execute();

    // Fully credited -> void the invoice so it leaves the outstanding ledger.
    if ($amount >= $remaining) {
        $conn->query('UPDATE invoices SET status = "void" WHERE id = ' . $invoiceId);
    }

    audit($conn, $uid, 'credit_note.create', "$number for invoice #$invoiceId");
    json_out(['ok' => true, 'number' => $number], 201);
}

fail('Method not allowed.', 405);
