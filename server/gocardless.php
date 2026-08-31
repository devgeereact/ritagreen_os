<?php
// GoCardless Direct Debit — recurring B2B collection.
// POST /gocardless.php?action=setup   {client_id}         -> { url } (hosted mandate setup)
// POST /gocardless.php?action=complete {mandate}          -> finalise after the client returns
// GET  /gocardless.php?action=status&client_id=N          -> { mandate }
// POST /gocardless.php?action=charge  {invoice_id}        -> collect an invoice by DD
// POST /gocardless.php?action=webhook                     -> GoCardless events (signature-verified)
//
// Uses the Billing Requests flow. No SDK — raw HTTPS, fine for shared hosting.
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

function gc_configured(): bool
{
    return defined('GC_ACCESS_TOKEN') && GC_ACCESS_TOKEN !== '' && GC_ACCESS_TOKEN !== 'CHANGE_ME';
}

function gc_base(): string
{
    return (defined('GC_ENVIRONMENT') && GC_ENVIRONMENT === 'live')
        ? 'https://api.gocardless.com' : 'https://api-sandbox.gocardless.com';
}

// One GoCardless API call. Returns [httpStatus, decodedJson].
function gc_request(string $method, string $path, ?array $body = null): array
{
    $headers = [
        'Authorization: Bearer ' . GC_ACCESS_TOKEN,
        'GoCardless-Version: 2015-07-06',
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($method === 'POST') $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    $ch = curl_init(gc_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode((string) $resp, true) ?: []];
}

// ----- Webhook (no auth; HMAC-SHA256 of the raw body, hex, in Webhook-Signature) -----
if ($action === 'webhook' && method() === 'POST') {
    $secret = defined('GC_WEBHOOK_SECRET') ? GC_WEBHOOK_SECRET : '';
    if ($secret === '' || $secret === 'CHANGE_ME') fail('Webhooks not configured.', 503);
    $payload = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';
    if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) fail('Bad signature.', 498);

    $events = json_decode($payload, true)['events'] ?? [];
    foreach ($events as $ev) {
        $resource = $ev['resource_type'] ?? '';
        $act = $ev['action'] ?? '';
        if ($resource === 'mandates') {
            $mid = $ev['links']['mandate'] ?? '';
            if ($mid && in_array($act, ['active', 'created', 'cancelled', 'failed', 'expired'], true)) {
                $status = in_array($act, ['active', 'created'], true) ? 'active' : ($act === 'cancelled' ? 'cancelled' : 'failed');
                $stmt = $conn->prepare('UPDATE mandates SET status = ? WHERE gc_mandate_id = ?');
                $stmt->bind_param('ss', $status, $mid);
                $stmt->execute();
            }
        }
        if ($resource === 'payments') {
            $pid = $ev['links']['payment'] ?? '';
            if ($pid) {
                // Read the current state BEFORE updating it. GoCardless may redeliver a
                // webhook, and without this an already-settled payment would be credited
                // to the invoice a second time.
                $sel = $conn->prepare('SELECT invoice_id, amount_pence, status FROM dd_payments WHERE gc_payment_id = ? LIMIT 1');
                $sel->bind_param('s', $pid);
                $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                $alreadySettled = $row && in_array($row['status'], ['confirmed', 'paid_out'], true);

                $stmt = $conn->prepare('UPDATE dd_payments SET status = ? WHERE gc_payment_id = ?');
                $stmt->bind_param('ss', $act, $pid);
                $stmt->execute();

                // On first settlement, credit what was actually collected and mark the
                // invoice paid only once the credited total covers it. This previously
                // set amount_paid_pence = total_pence on any confirmed collection, so a
                // part-collection reported as paid in full.
                if (in_array($act, ['confirmed', 'paid_out'], true) && !$alreadySettled && $row && $row['invoice_id']) {
                    $iid = (int) $row['invoice_id'];
                    $amount = (int) $row['amount_pence'];

                    $credit = $conn->prepare(
                        'UPDATE invoices SET amount_paid_pence = LEAST(amount_paid_pence + ?, total_pence) WHERE id = ?'
                    );
                    $credit->bind_param('ii', $amount, $iid);
                    $credit->execute();

                    $settle = $conn->prepare(
                        'UPDATE invoices SET status = "paid"
                         WHERE id = ? AND status <> "paid" AND amount_paid_pence >= total_pence'
                    );
                    $settle->bind_param('i', $iid);
                    $settle->execute();

                    if ($settle->affected_rows > 0) {
                        audit($conn, 0, 'invoice.paid.gocardless', "#$iid via DD");
                    } else {
                        audit($conn, 0, 'invoice.part_paid.gocardless', "#$iid credited {$amount}p via DD");
                    }
                }
            }
        }
    }
    json_out(['ok' => true]);
}

// ----- Staff-only below -----
$user = require_auth($conn);
if (!gc_configured()) fail('Direct Debit is not set up yet. Add GC_ACCESS_TOKEN to config.php.', 503);

if ($action === 'status' && method() === 'GET') {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId <= 0) fail('A client is required.');
    $stmt = $conn->prepare('SELECT id, status, gc_mandate_id, created_at FROM mandates WHERE client_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    json_out(['mandate' => $m ?: null]);
}

if ($action === 'setup' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $clientId = (int) (body()['client_id'] ?? 0);
    if ($clientId <= 0) fail('A client is required.');
    $stmt = $conn->prepare('SELECT name, email FROM clients WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    if (!$client) fail('Client not found.', 404);

    // 1. Billing request asking for a GBP mandate.
    [$s1, $br] = gc_request('POST', '/billing_requests', [
        'billing_requests' => ['mandate_request' => ['currency' => 'GBP']],
    ]);
    if ($s1 >= 400 || empty($br['billing_requests']['id'])) {
        fail($br['error']['message'] ?? 'GoCardless rejected the setup.', 502);
    }
    $brId = $br['billing_requests']['id'];

    // Local pending mandate (reference = billing request id for later completion).
    $stmt = $conn->prepare('INSERT INTO mandates (client_id, status, reference) VALUES (?, "pending", ?)');
    $stmt->bind_param('is', $clientId, $brId);
    $stmt->execute();
    $localId = $conn->insert_id;

    // 2. Hosted flow the client completes.
    $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'https://ritagreenservices.com';
    [$s2, $flow] = gc_request('POST', '/billing_request_flows', [
        'billing_request_flows' => [
            'redirect_uri' => $base . '/app/clients/' . $clientId . '?dd=done&m=' . $localId,
            'exit_uri' => $base . '/app/clients/' . $clientId . '?dd=cancelled',
            'links' => ['billing_request' => $brId],
        ],
    ]);
    if ($s2 >= 400 || empty($flow['billing_request_flows']['authorisation_url'])) {
        fail($flow['error']['message'] ?? 'GoCardless could not start the flow.', 502);
    }
    audit($conn, (int) $user['id'], 'dd.setup', "client #$clientId");
    json_out(['url' => $flow['billing_request_flows']['authorisation_url']]);
}

if ($action === 'complete' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $localId = (int) (body()['mandate'] ?? 0);
    if ($localId <= 0) fail('A mandate is required.');
    $stmt = $conn->prepare('SELECT reference FROM mandates WHERE id = ?');
    $stmt->bind_param('i', $localId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || !$row['reference']) fail('Mandate not found.', 404);

    // Fetch the billing request → its created mandate.
    [$s, $br] = gc_request('GET', '/billing_requests/' . $row['reference']);
    $mandateId = $br['billing_requests']['links']['mandate_request_mandate'] ?? '';
    if ($s >= 400 || $mandateId === '') json_out(['status' => 'pending']); // not finished yet
    [$s2, $m] = gc_request('GET', '/mandates/' . $mandateId);
    $customerId = $m['mandates']['links']['customer'] ?? null;
    $mStatus = $m['mandates']['status'] ?? 'pending';
    $statusVal = in_array($mStatus, ['active', 'submitted', 'pending_submission'], true) ? 'active' : 'pending';
    $stmt = $conn->prepare('UPDATE mandates SET gc_mandate_id = ?, gc_customer_id = ?, status = ? WHERE id = ?');
    $stmt->bind_param('sssi', $mandateId, $customerId, $statusVal, $localId);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'dd.complete', "mandate $mandateId");
    json_out(['status' => $statusVal]);
}

if ($action === 'charge' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $invoiceId = (int) (body()['invoice_id'] ?? 0);
    if ($invoiceId <= 0) fail('An invoice is required.');
    $stmt = $conn->prepare('SELECT id, number, client_id, total_pence, status FROM invoices WHERE id = ?');
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    if (!$inv) fail('Invoice not found.', 404);
    if ($inv['status'] === 'paid') fail('That invoice is already paid.', 409);
    if (!$inv['client_id']) fail('That invoice has no client to charge.', 409);

    $stmt = $conn->prepare('SELECT id, gc_mandate_id FROM mandates WHERE client_id = ? AND status = "active" AND gc_mandate_id IS NOT NULL ORDER BY id DESC LIMIT 1');
    $cid = (int) $inv['client_id'];
    $stmt->bind_param('i', $cid);
    $stmt->execute();
    $mandate = $stmt->get_result()->fetch_assoc();
    if (!$mandate) fail('That client has no active Direct Debit mandate.', 409);

    [$s, $pay] = gc_request('POST', '/payments', [
        'payments' => [
            'amount' => (int) $inv['total_pence'],
            'currency' => 'GBP',
            'metadata' => ['invoice_id' => (string) $invoiceId, 'number' => $inv['number']],
            'links' => ['mandate' => $mandate['gc_mandate_id']],
        ],
    ]);
    if ($s >= 400 || empty($pay['payments']['id'])) {
        fail($pay['error']['message'] ?? 'GoCardless rejected the payment.', 502);
    }
    $gcPid = $pay['payments']['id'];
    $gcStatus = $pay['payments']['status'] ?? 'pending_submission';
    $amt = (int) $inv['total_pence'];
    $mid = (int) $mandate['id'];
    $stmt = $conn->prepare('INSERT INTO dd_payments (invoice_id, mandate_id, gc_payment_id, amount_pence, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('iisis', $invoiceId, $mid, $gcPid, $amt, $gcStatus);
    $stmt->execute();
    // Mark the invoice as collecting (sent → stays until webhook confirms paid).
    audit($conn, (int) $user['id'], 'dd.charge', "{$inv['number']} via DD");
    json_out(['ok' => true, 'status' => $gcStatus]);
}

fail('Method not allowed.', 405);
