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
            // Verify the amount actually paid matches the invoice total in GBP
            // before flipping to paid — never trust the session's metadata id
            // alone, or a tampered/underpaid session could clear the invoice.
            $stmt = $conn->prepare('SELECT total_pence FROM invoices WHERE id = ?');
            $stmt->bind_param('i', $invoiceId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $expected = $row ? (int) $row['total_pence'] : -1;
            $amount = (int) ($session['amount_total'] ?? -1);
            $currency = strtolower((string) ($session['currency'] ?? ''));

            if ($expected >= 0 && $amount === $expected && $currency === 'gbp') {
                $stmt = $conn->prepare('UPDATE invoices SET status = "paid", amount_paid_pence = total_pence WHERE id = ? AND status <> "paid"');
                $stmt->bind_param('i', $invoiceId);
                $stmt->execute();
                audit($conn, 0, 'invoice.paid.stripe', "#$invoiceId via Stripe Checkout");
                // Emailed receipt to the client.
                $stmt = $conn->prepare('SELECT i.number, i.total_pence, c.email, c.name FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
                $stmt->bind_param('i', $invoiceId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                // The address check stays; the mail_enabled() gate does not.
                if ($r && $r['email']) {
                    $amt = '£' . number_format(((int) $r['total_pence']) / 100, 2);
                    queue_mail($conn, $r['email'], 'Payment received — ' . $r['number'],
                        "Hi {$r['name']},\n\nThank you — we've received your payment of $amt for invoice {$r['number']}.\nThis email is your receipt.\n\nRitaGreen Services");
                }
            } else {
                // Amount/currency mismatch — log and leave the invoice unpaid.
                audit($conn, 0, 'invoice.paid.mismatch', "#$invoiceId paid=$amount $currency expected=$expected gbp");
            }
        }
    }
    json_out(['ok' => true]);
}

// Build a Stripe Checkout Session for an invoice row; returns the hosted URL.
function stripe_create_session(array $inv, string $base): string
{
    $label = 'Invoice ' . $inv['number'] . ($inv['client'] ? ' — ' . $inv['client'] : '');
    $params = [
        'mode' => 'payment',
        'success_url' => $base . '/app/invoicing?paid=' . $inv['id'],
        'cancel_url' => $base . '/app/invoicing?cancelled=' . $inv['id'],
        'client_reference_id' => (string) $inv['id'],
        'metadata[invoice_id]' => (string) $inv['id'],
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
    if ($status >= 400) fail($json['error']['message'] ?? 'Stripe rejected the request.', 502);
    if (empty($json['url'])) fail('Stripe did not return a checkout URL.', 502);
    return $json['url'];
}

// --- Public pay link: a client pays an invoice from an emailed link, no login.
//     GET /payments.php?action=public_checkout&token=XXXX -> 302 to Stripe ---
if ($action === 'public_checkout' && method() === 'GET') {
    if (!stripe_configured()) fail('Card payments are not set up yet.', 503);
    $token = trim((string) ($_GET['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) fail('Invalid payment link.', 404);
    $stmt = $conn->prepare(
        'SELECT i.id, i.number, i.total_pence, i.status, c.name AS client
         FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.pay_token = ? LIMIT 1'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    if (!$inv) fail('Invoice not found.', 404);
    if ($inv['status'] === 'paid') fail('That invoice is already paid.', 409);
    if ($inv['status'] === 'void') fail('That invoice has been cancelled.', 409);
    if ((int) $inv['total_pence'] <= 0) fail('That invoice has no amount to charge.');

    $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : (defined('OR_REFERER') ? OR_REFERER : 'https://ritagreenservices.com');
    $url = stripe_create_session($inv, $base);
    header('Location: ' . $url, true, 302);
    exit;
}

// --- Everything below is staff-only. ---
$user = require_auth($conn);

// Return the public pay link for an invoice (creates a token if missing).
if ($action === 'paylink' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $invoiceId = (int) ($_GET['id'] ?? 0);
    if ($invoiceId <= 0) fail('An invoice is required.');
    $stmt = $conn->prepare('SELECT pay_token FROM invoices WHERE id = ?');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Invoice not found.', 404);
    $tok = $row['pay_token'];
    if (!$tok) {
        $tok = bin2hex(random_bytes(20));
        $stmt = $conn->prepare('UPDATE invoices SET pay_token = ? WHERE id = ?');
        $stmt->bind_param('si', $tok, $invoiceId);
        $stmt->execute();
    }
    $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'https://ritagreenservices.com';
    json_out(['url' => $base . '/payments.php?action=public_checkout&token=' . $tok]);
}

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
    $url = stripe_create_session($inv, $base);

    audit($conn, (int) $user['id'], 'invoice.checkout', $inv['number']);
    json_out(['url' => $url]);
}

fail('Method not allowed.', 405);
