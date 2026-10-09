<?php
// Bookings / service requests — the client-facing front door to cover.
// POST /bookings.php (public)                 -> create a cover request (rate-limited)
// GET  /bookings.php (staff)                  -> { requests: [...] }
// POST /bookings.php?action=status&id=N {status}   -> quoted/declined
// POST /bookings.php?action=confirm&id=N {site_id?, title?, slots?} -> draft shift(s) at the site
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
    // Length caps, as on contact.php. Without them an oversized field reached
    // MySQL in strict mode and returned a 500 rather than something the person
    // filling the form could act on.
    if (mb_strlen($name) > 160) fail('That name is too long.');
    if (mb_strlen($email) > 190) fail('That email is too long.');
    if (mb_strlen($site) > 160) fail('That site name is too long.');
    if (mb_strlen($detail) > 5000) fail('Please keep the detail under 5000 characters.');
    $phone = mb_substr($phone, 0, 40);

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
    // Queued regardless of whether mail is on -- see server/send-queued-mail.php.
    {
        queue_mail($conn, MAIL_TO, "New cover request — $division", "$name <$email> ($phone)\n\n$detail\n\nPreferred: " . ($startVal ?? 'n/a'), $email);
    }
    json_out(['ok' => true], 201);
}

// Everything else is staff-only.
$user = require_auth($conn);
// Cover requests carry the requesting contact's name, email and phone. The
// public POST above is deliberately unauthenticated; reading the queue is not.
require_role($user, 'super_admin', 'admin', 'manager');

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
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'service_requests', $id)) fail('Request not found.', 404);
    audit($conn, (int) $user['id'], 'booking.status', "#$id -> $status");
    json_out(['ok' => true]);
}

// Create shifts from a request: one or more DRAFT open shifts at the right site,
// which the office then fills and publishes from the rota.
//
// POST /bookings.php?action=confirm&id=N {site_id?, title?, slots?: [{starts_at, ends_at}]}
//
// With no slots, one shift from the request's preferred start and end. Drafts,
// not published shifts: a cover request is a plan until somebody is booked, and
// workers never see drafts (migration 038).
if ($action === 'confirm' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A request is required.');
    $stmt = $conn->prepare('SELECT * FROM service_requests WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) fail('Request not found.', 404);
    require_service_scope($user, $req['division']);
    if ($req['shift_id']) fail('This request already has shifts.', 409);

    $data = body();
    $clientId = $req['client_id'] !== null ? (int) $req['client_id'] : null;
    $siteName = $req['site_name'];
    $siteId = isset($data['site_id']) && $data['site_id'] !== '' && $data['site_id'] !== null ? (int) $data['site_id'] : null;
    if ($siteId !== null) {
        $stmt = $conn->prepare('SELECT id, client_id, name, active FROM sites WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('i', $siteId);
        $stmt->execute();
        $site = $stmt->get_result()->fetch_assoc();
        if (!$site) fail('Site not found.', 404);
        if (!(int) $site['active']) fail('That site is closed.', 409);
        $clientId = (int) $site['client_id'];
        $siteName = $site['name'];
    }

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') $title = 'Cover: ' . $req['contact_name'];
    if (mb_strlen($title) > 190) fail('That title is too long.');

    $slots = isset($data['slots']) && is_array($data['slots']) ? $data['slots'] : [];
    if (!$slots) {
        $start = $req['preferred_start'] ?: date('Y-m-d 09:00:00', strtotime('+1 day'));
        $end = $req['preferred_end'] ?: date('Y-m-d H:i:s', strtotime($start . ' +8 hours'));
        $slots = [['starts_at' => $start, 'ends_at' => $end]];
    }
    if (count($slots) > 31) fail('Create up to 31 shifts from one request.');

    $times = [];
    foreach ($slots as $i => $slot) {
        $s = strtotime((string) ($slot['starts_at'] ?? ''));
        $e = strtotime((string) ($slot['ends_at'] ?? ''));
        $n = $i + 1;
        if (!$s || !$e) fail("Shift $n needs a start and an end.");
        if ($e <= $s) fail("Shift $n must end after it starts.");
        $times[] = [date('Y-m-d H:i:s', $s), date('Y-m-d H:i:s', $e)];
    }

    $uid = (int) $user['id'];
    $division = $req['division'];
    $conn->begin_transaction();
    $insert = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, site_id, division, title, site_name, starts_at, ends_at, status, is_draft)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, "open", 1)'
    );
    $ids = [];
    foreach ($times as [$start, $end]) {
        $insert->bind_param('iisssss', $clientId, $siteId, $division, $title, $siteName, $start, $end);
        $insert->execute();
        $ids[] = (int) $conn->insert_id;
    }
    // service_requests.shift_id holds one id: the first. The rest are found
    // through the audit trail, which names the request on every shift.
    $first = $ids[0];
    $stmt = $conn->prepare('UPDATE service_requests SET status = "confirmed", shift_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $first, $id);
    $stmt->execute();
    $conn->commit();
    foreach ($ids as $sid) {
        audit($conn, $uid, 'shift.create', "From cover request #$id (draft)", 'shift', $sid);
    }

    // Confirm to the client. Queued regardless of whether mail is on: a queued
    // row is a record that we meant to confirm this booking.
    if (filter_var($req['email'], FILTER_VALIDATE_EMAIL)) {
        queue_mail($conn, $req['email'], 'Your RitaGreen cover request is confirmed',
            "Hi {$req['contact_name']},\n\nThanks, we have logged your request and are arranging cover from "
            . date('D j M, H:i', strtotime($times[0][0])) . ".\nWe will confirm who is coming shortly.\n\nRitaGreen Services");
    }
    audit($conn, $uid, 'booking.confirm', "#$id -> " . count($ids) . ' draft shifts');
    json_out(['ok' => true, 'shift_id' => $first, 'shift_ids' => $ids], 201);
}
fail('Method not allowed.', 405);
