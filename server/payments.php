<?php
// Card payments via Stripe Checkout (hosted) — no card data ever touches this
// server. Staff create a Checkout Session for an unpaid invoice; the customer
// pays on Stripe's page; Stripe's webhook tells us to mark the invoice paid.
//
// POST /payments.php?action=checkout  {invoice_id}      -> { url }   (staff, auth)
// POST /payments.php?action=webhook                     -> { ok }    (Stripe, no auth)
//
// Config (config.php): STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET. Until those are
// set this endpoint returns 503 and the rest of the app keeps using manual
// mark-paid, so deploying this file changes nothing until you add the keys.
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

function stripe_configured(): bool
{
    return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '' && STRIPE_SECRET_KEY !== 'CHANGE_ME';
}

// --- Stripe webhook: Stripe POSTs here on payment events. No auth (Stripe is
// the caller); instead we verify the signature against STRIPE_WEBHOOK_SECRET. ---
if ($action === 'webhook' && method() === 'POST') {
    if (!stripe_configured() || !defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '' || STRIPE_WEBHOOK_SECRET === 'CHANGE_ME') {
        fail('Payments are not configured.', 503);
    }
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // Parse "t=...,v1=..." and verify HMAC-SHA256 of "t.payload".
    $t = null; $v1 = null;
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $t = $kv[1];
        if ($kv[0] === 'v1') $v1 = $kv[1];
    }
    if ($t === null || $v1 === null) fail('Bad signature.', 400);
    $expected = hash_hmac('sha256', $t . '.' . $payload, STRIPE_WEBHOOK_SECRET);
    if (!hash_equals($expected, $v1)) fail('Signature verification failed.', 400);
    // Reject events older than 5 minutes (replay protection).
    if (abs(time() - (int) $t) > 300) fail('Stale event.', 400);

    $event = json_decode($payload, true);
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = $event['data']['object'] ?? [];
        $invoiceId = (int) ($session['metadata']['invoice_id'] ?? $session['client_reference_id'] ?? 0);
        $paidOk = ($session['payment_status'] ?? '') === 'paid';
        if ($invoiceId > 0 && $paidOk) {
            $stmt = $conn->prepare('UPDATE invoices SET status = "paid" WHERE id = ? AND status <> "paid"');
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            audit($conn, 0, 'invoice.paid.stripe', "#$invoiceId via Stripe Checkout");
        }
    }
    json_out(['ok' => true]);
}

// --- Everything below is staff-only. ---
$user = require_auth($conn);

if ($action === 'checkout' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    if (!stripe_configured()) fail('Card payments are not set up yet. Add STRIPE_SECRET_KEY to config.php.', 503);

    $data = body();
    $invoiceId = (int) ($data['invoice_id'] ?? 0);
    if ($invoiceId <= 0) fail('An invoice is required.');

    $stmt = $conn->prepare(
        'SELECT i.id, i.number, i.total_pence, i.status, c.name AS client
         FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    if (!$inv) fail('Invoice not found.', 404);
    if ($inv['status'] === 'paid') fail('That invoice is already paid.', 409);
    if ((int) $inv['total_pence'] <= 0) fail('That invoice has no amount to charge.');

    $base = defined('OR_REFERER') ? OR_REFERER : 'https://ritagreenservices.com';
    $label = 'Invoice ' . $inv['number'] . ($inv['client'] ? ' — ' . $inv['client'] : '');

    // Create a Checkout Session (form-encoded, raw HTTP — no SDK on shared host).
    $params = [
        'mode' => 'payment',
        'success_url' => $base . '/app/invoicing?paid=' . $invoiceId,
        'cancel_url' => $base . '/app/invoicing?cancelled=' . $invoiceId,
        'client_reference_id' => (string) $invoiceId,
        'metadata[invoice_id]' => (string) $invoiceId,
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'gbp',
        'line_items[0][price_data][unit_amount]' => (string) (int) $inv['total_pence'],
        'line_items[0][price_data][product_data][name]' => $label,
    ];

    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) fail('Could not reach Stripe: ' . $curlErr, 502);
    $json = json_decode($response, true);
    if ($status >= 400) {
        $msg = $json['error']['message'] ?? 'Stripe rejected the request.';
        fail($msg, 502);
    }
    if (empty($json['url'])) fail('Stripe did not return a checkout URL.', 502);

    audit($conn, (int) $user['id'], 'invoice.checkout', $inv['number']);
    json_out(['url' => $json['url']]);
}

fail('Method not allowed.', 405);
