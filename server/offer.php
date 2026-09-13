<?php
// Public shift-offer endpoint — a worker accepts/declines a broadcast open shift
// from an SMS link, no login required. The token in the link is the secret.
// GET  /offer.php?token=XXXX            -> { offer: {...}, shift: {...} }
// POST /offer.php?token=XXXX {action}   -> action = "accept" | "decline"
// An accept runs the same deployment gate and clash check as the office screen;
// a refusal expires the offer, audits shift.blocked and tells the office.
// First worker to accept claims the shift atomically; later accepts get "filled".
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{40}$/', $token)) fail('Invalid offer link.', 404);

// Load offer + shift + worker.
$stmt = $conn->prepare(
    'SELECT o.id AS offer_id, o.status AS offer_status, o.worker_id, w.name AS worker,
            s.id AS shift_id, s.title, s.division, s.site_name, s.starts_at, s.ends_at, s.status AS shift_status,
            c.name AS client
     FROM shift_offers o
     JOIN shifts s ON s.id = o.shift_id
     JOIN workers w ON w.id = o.worker_id
     LEFT JOIN clients c ON c.id = s.client_id
     WHERE o.token = ? LIMIT 1'
);
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) fail('This offer was not found.', 404);

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
    $data = body();
    $act = trim((string) ($data['action'] ?? ''));
    $offerId = (int) $row['offer_id'];
    $shiftId = (int) $row['shift_id'];
    $workerId = (int) $row['worker_id'];

    if ($row['offer_status'] !== 'sent') {
        json_out(['result' => 'closed', 'message' => 'This offer has already been responded to.']);
    }

    if ($act === 'decline') {
        $stmt = $conn->prepare('UPDATE shift_offers SET status = "declined", responded_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $offerId);
        $stmt->execute();
        json_out(['result' => 'declined', 'message' => 'Thanks — we\'ll offer it to someone else.']);
    }

    if ($act === 'accept') {
        // The deployment gate, before the claim.
        //
        // A broadcast goes out to workers who were eligible at the moment it was
        // sent, and the link stays live afterwards. Nothing re-asked on the way
        // in, so a worker whose DBS lapsed between the text and the reply was
        // booked by replying, and one who had picked up an overlapping shift in
        // the meantime was booked over the top of it -- while the office screen
        // refused both with a 409. rg_deployment_check() calls itself "the
        // single gate every assignment path must call"; this path did not.
        $claim = rg_claim_check($conn, $workerId, $shiftId);
        if (!$claim['ok']) {
            $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired", responded_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $offerId);
            $stmt->execute();
            audit($conn, null, 'shift.blocked', "offer accept, worker #$workerId: " . $claim['reason']);
            notify(
                $conn, null, 'An offer could not be accepted',
                $row['worker'] . ' tried to accept "' . $row['title'] . '" — ' . $claim['reason'],
                'shift', '/app/scheduling'
            );
            json_out([
                'result' => 'blocked',
                // Addressed to the worker reading it on their phone, so it says
                // what to do rather than only what is wrong.
                'message' => rtrim($claim['reason'], '.') . '. Please contact the office.',
            ], 409);
        }

        // Atomic claim: only succeeds if the shift is still open.
        $stmt = $conn->prepare('UPDATE shifts SET worker_id = ?, status = "scheduled" WHERE id = ? AND status = "open"');
        $stmt->bind_param('ii', $workerId, $shiftId);
        $stmt->execute();
        if ($conn->affected_rows === 1) {
            $stmt = $conn->prepare('UPDATE shift_offers SET status = "accepted", responded_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $offerId);
            $stmt->execute();
            // Expire the other outstanding offers for this shift.
            $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired" WHERE shift_id = ? AND status = "sent"');
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            // Tell the office.
            notify($conn, null, 'Open shift filled', $row['worker'] . ' accepted "' . $row['title'] . '"', 'shift', '/app/scheduling');
            audit($conn, null, 'shift.accepted', "shift #$shiftId by worker #$workerId");
            json_out(['result' => 'accepted', 'message' => 'You\'re booked in. Thank you!']);
        }
        // Someone else got there first.
        $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired", responded_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $offerId);
        $stmt->execute();
        json_out(['result' => 'filled', 'message' => 'Sorry — this shift was just filled by someone else.']);
    }

    fail('Unknown action.');
}

fail('Method not allowed.', 405);
