<?php
// Bookings / service requests — the client-facing front door to cover.
// POST /bookings.php (public)                 -> create a cover request (rate-limited)
// GET  /bookings.php (staff)                  -> { requests: [...] }
// POST /bookings.php?action=status&id=N {status}   -> quoted/declined
// POST /bookings.php?action=confirm&id=N      -> turn a request into an open shift
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// ---------- Public: submit a cover request ----------
if (method() === 'POST' && $action === '') {
    rate_limit($conn, 'booking', 8, 600);
    $data = body();
    $name = trim((string) ($data['contact_name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $division = trim((string) ($data['division'] ?? ''));
    $site = trim((string) ($data['site_name'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));
    $start = trim((string) ($data['preferred_start'] ?? ''));
    $end = trim((string) ($data['preferred_end'] ?? ''));
    $consent = !empty($data['consent']);

    if ($name === '' || $detail === '') fail('Your name and what you need are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Choose a service.');
    if (!$consent) fail('Please agree to be contacted about your request.');

    // Link to an existing client by email if we know them.
    $clientId = null;
    $stmt = $conn->prepare('SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $clientId = (int) $row['id'];

    $startVal = $start !== '' && strtotime($start) ? date('Y-m-d H:i:s', strtotime($start)) : null;
    $endVal = $end !== '' && strtotime($end) ? date('Y-m-d H:i:s', strtotime($end)) : null;
    $siteVal = $site !== '' ? $site : null;
    $phoneVal = $phone !== '' ? $phone : null;

    $stmt = $conn->prepare(
        'INSERT INTO service_requests (client_id, contact_name, email, phone, division, site_name, detail, preferred_start, preferred_end)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssssss', $clientId, $name, $email, $phoneVal, $division, $siteVal, $detail, $startVal, $endVal);
    $stmt->execute();

    // GDPR consent record + office alert.
    $ip = client_ip();
    $stmt = $conn->prepare('INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "booking", 1, "cover-request", ?)');
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();

    notify($conn, null, 'New cover request', "$name ($division): " . mb_substr($detail, 0, 120), 'booking', '/app/bookings');
    if (function_exists('mail_enabled') && mail_enabled()) {
        send_mail(MAIL_TO, "New cover request — $division", "$name <$email> ($phone)\n\n$detail\n\nPreferred: " . ($startVal ?? 'n/a'));
    }
    json_out(['ok' => true], 201);
}

// Everything else is staff-only.
$user = require_auth($conn);

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT r.id, r.client_id, c.name AS client, r.contact_name, r.email, r.phone, r.division,
                r.site_name, r.detail, r.preferred_start, r.preferred_end, r.status, r.shift_id, r.created_at
         FROM service_requests r LEFT JOIN clients c ON c.id = r.client_id
         ORDER BY r.created_at DESC LIMIT 300'
    );
    $requests = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['shift_id'] = $row['shift_id'] !== null ? (int) $row['shift_id'] : null;
        $requests[] = $row;
    }
    json_out(['requests' => $requests]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $status = trim((string) ($data['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['new', 'quoted', 'declined'], true)) fail('A request and valid status are required.');
    $stmt = $conn->prepare('UPDATE service_requests SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'booking.status', "#$id -> $status");
    json_out(['ok' => true]);
}

// Confirm a request: create an open shift the office can then broadcast/assign.
if ($action === 'confirm' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A request is required.');
    $stmt = $conn->prepare('SELECT * FROM service_requests WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) fail('Request not found.', 404);
    if ($req['shift_id']) fail('This request already has a shift.', 409);

    $start = $req['preferred_start'] ?: date('Y-m-d H:i:s', strtotime('+1 day 09:00'));
    $end = $req['preferred_end'] ?: date('Y-m-d H:i:s', strtotime($start . ' +8 hours'));
    $title = 'Cover — ' . ucfirst($req['division']) . ' (' . $req['contact_name'] . ')';

    $stmt = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at, status)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, "open")'
    );
    $clientId = $req['client_id'] !== null ? (int) $req['client_id'] : null;
    $division = $req['division']; $site = $req['site_name'];
    $stmt->bind_param('isssss', $clientId, $division, $title, $site, $start, $end);
    $stmt->execute();
    $shiftId = $conn->insert_id;

    $stmt = $conn->prepare('UPDATE service_requests SET status = "confirmed", shift_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $shiftId, $id);
    $stmt->execute();

    // Confirm to the client.
    if (function_exists('mail_enabled') && mail_enabled() && filter_var($req['email'], FILTER_VALIDATE_EMAIL)) {
        send_mail($req['email'], 'Your RitaGreen cover request is confirmed',
            "Hi {$req['contact_name']},\n\nThanks — we've logged your request and are arranging cover for "
            . date('D j M, H:i', strtotime($start)) . ".\nWe'll confirm the assigned worker shortly.\n\nRitaGreen Services");
    }
    audit($conn, (int) $user['id'], 'booking.confirm', "#$id -> shift #$shiftId");
    json_out(['ok' => true, 'shift_id' => (int) $shiftId]);
}

fail('Method not allowed.', 405);
