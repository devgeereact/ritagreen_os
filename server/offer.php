<?php
// Public shift-offer endpoint — a worker accepts/declines a broadcast open shift
// from an SMS link, no login required. The token in the link is the secret.
// GET  /offer.php?token=XXXX            -> { offer: {...}, shift: {...} }
// POST /offer.php?token=XXXX {action}   -> action = "accept" | "decline"
// An accept runs the same deployment gate and clash check as the office screen;
// a refusal expires the offer, audits shift.blocked and tells the office.
// First worker to accept claims the shift atomically; later accepts get "filled".
// The answer itself is rg_offer_respond() in compliance-lib.php, which the worker
// portal (me.php?action=offer_accept / offer_decline) calls as well.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{40}$/', $token)) fail('Invalid offer link.', 404);

// Load offer + shift + worker.
$stmt = $conn->prepare(
    'SELECT o.id AS offer_id, o.status AS offer_status, o.expires_at, o.worker_id, w.name AS worker,
            s.id AS shift_id, s.title, s.division, s.site_name, s.starts_at, s.ends_at, s.status AS shift_status,
            s.is_draft, s.deleted_at AS shift_deleted_at, c.name AS client
     FROM shift_offers o
     JOIN shifts s ON s.id = o.shift_id
     JOIN workers w ON w.id = o.worker_id
     LEFT JOIN clients c ON c.id = s.client_id
     WHERE o.token = ? LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
// A draft or an archived shift is not something a worker can be shown, so an
// offer for one reads as no offer at all. Offers are only made on published
// shifts; this holds even if one somehow outlived that.
if (!$row || (int) $row['is_draft'] === 1 || $row['shift_deleted_at'] !== null) fail('This offer was not found.', 404);

if (method() === 'GET') {
    json_out([
        'offer' => ['status' => $row['offer_status'], 'worker' => $row['worker']],
        'shift' => [
            'title' => $row['title'], 'division' => $row['division'], 'site_name' => $row['site_name'],
            'client' => $row['client'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
            'status' => $row['shift_status'],
        ],
    ]);
}

if (method() === 'POST') {
    rate_limit($conn, 'offer', 20, 600);
    $act = trim((string) (body()['action'] ?? ''));
    $res = rg_offer_respond($conn, $row, $act);
    json_out(['result' => $res['result'], 'message' => $res['message']], $res['code']);
}

fail('Method not allowed.', 405);
