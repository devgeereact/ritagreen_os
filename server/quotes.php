<?php
// Quoting & estimating.
// GET  /quotes.php                       -> { quotes: [...], totals: {...} }
// POST /quotes.php {title, division, net_pence, client_id?}  -> create (20% VAT)
// POST /quotes.php?action=status&id=5 {status}   -> sent | declined
// POST /quotes.php?action=accept&id=5            -> accept + auto-create an invoice
// POST /quotes.php?action=request (public)        -> a cleaning quote request from the website
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// ---------- Public: a cleaning quote request from the website ----------
//
// The website's quote form used to post to contact.php, so a request for a
// priced cleaning contract arrived in the general enquiry inbox as free text.
//
// It does not become a row in `quotes`. A quote is a priced document with a
// number, a net figure and VAT, and the office writes it; this is somebody asking
// for one, with no price and no client record yet. It is filed where the office
// already triages incoming work for a price: the service_requests queue (the
// Bookings screen), as a Cleaning request whose detail opens "Cleaning quote
// request". That queue's status runs new -> quoted, which is exactly this
// journey, and the Bookings screen is not hidden by the finance switch.
//
// Same guards as the other public forms: rate-limited by IP, every field capped
// at its column, explicit consent to be contacted, marketing a separate unticked
// box recorded only as a yes.
if (method() === 'POST' && $action === 'request') {
    rate_limit($conn, 'quote', 5, 600);
    $d = body();
    // Single-line fields lose CR/LF: they are interpolated into a mail subject.
    $line = static fn (string $k): string => trim(str_replace(["\r", "\n"], ' ', (string) ($d[$k] ?? '')));
    $name = $line('contact_name');
    $email = trim((string) ($d['email'] ?? ''));
    $phone = $line('phone');
    $org = $line('organisation');
    $area = $line('area');
    $siteType = $line('site_type');
    $size = $line('size');
    $frequency = $line('frequency');
    $startDate = $line('start_date');
    $notes = trim((string) ($d['notes'] ?? ''));
    $consent = !empty($d['consent']);
    $marketing = !empty($d['marketing_consent']);

    $siteTypes = rg_cleaning_site_types();
    $frequencies = rg_cleaning_frequencies();

    if ($name === '') fail('Please add your name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');
    if (!array_key_exists($siteType, $siteTypes)) fail('Choose the type of site.');
    if (!array_key_exists($frequency, $frequencies)) fail('Choose how often you need cleaning.');
    if (!$consent) fail('Please agree to be contacted about your request.');
    if (mb_strlen($name) > 160) fail('That name is too long.');
    if (mb_strlen($email) > 190) fail('That email is too long.');
    if (mb_strlen($phone) > 40) fail('That phone number is too long.');
    if (mb_strlen($org) > 160) fail('That organisation name is too long.');
    if (mb_strlen($area) > 120) fail('That area is too long.');
    if (mb_strlen($size) > 200) fail('Please describe the size more briefly.');
    if (mb_strlen($notes) > 3000) fail('Please keep the notes under 3000 characters.');
    $startVal = null;
    if ($startDate !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        if (!$dt || $dt->format('Y-m-d') !== $startDate) fail('That start date is not a date.');
        $startVal = $startDate;
    }

    $detail = "Cleaning quote request\n"
        . 'Organisation: ' . ($org !== '' ? $org : 'not given') . "\n"
        . 'Site type: ' . $siteTypes[$siteType] . "\n"
        . 'Area: ' . ($area !== '' ? $area : 'not given') . "\n"
        . 'Size: ' . ($size !== '' ? $size : 'not given') . "\n"
        . 'Frequency: ' . $frequencies[$frequency] . "\n"
        . 'Start: ' . ($startVal ?? 'not given')
        . ($notes !== '' ? "\n\nNotes:\n$notes" : '');

    // Link to an existing client by email if we know them, as bookings.php does.
    $clientId = null;
    $stmt = $conn->prepare('SELECT id FROM clients WHERE email = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $clientId = (int) $row['id'];

    $site = $org !== '' ? $org : ($area !== '' ? $area : null);
    $phoneVal = $phone !== '' ? $phone : null;
    $stmt = $conn->prepare(
        'INSERT INTO service_requests (client_id, contact_name, email, phone, division, site_name, detail)
         VALUES (?, ?, ?, ?, "cleaning", ?, ?)'
    );
    $stmt->bind_param('isssss', $clientId, $name, $email, $phoneVal, $site, $detail);
    $stmt->execute();

    $ip = client_ip();
    $stmt = $conn->prepare('INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "quote", 1, "cleaning-quote", ?)');
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    if ($marketing) record_marketing_consent($conn, $email, true, 'quote');

    notify($conn, null, 'New cleaning quote request', "$name: " . $siteTypes[$siteType] . ', ' . $frequencies[$frequency], 'booking', '/app/bookings');
    // Queued regardless of whether mail is on -- see server/send-queued-mail.php.
    queue_mail($conn, MAIL_TO, "New cleaning quote request: $name", "$name <$email>" . ($phoneVal ? " ($phoneVal)" : '') . "\n\n$detail", $email);
    json_out(['ok' => true], 201);
}

$user = require_auth($conn);
// The quote pipeline, including client names and values. Reads were unguarded.
require_role($user, 'super_admin', 'admin', 'manager');

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
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'quotes', $id, false)) fail('Quote not found.', 404);
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

    // Create an invoice mirroring the quote totals. Atomic per-year counter.
    $year = date('Y');
    $seq = next_seq($conn, "invoice-$year");
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

    // Check the parents before drawing a number: a stale id reached the INSERT
    // and came back as a foreign-key 500 with the constraint name in it, having
    // already burnt a sequence value on a record that was never written.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    $vat = (int) round($net * 0.20);
    $total = $net + $vat;
    $year = date('Y');
    $seq = next_seq($conn, "quote-$year");
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
