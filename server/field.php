<?php
// §8 Field operations & service delivery.
// GET  /field.php?action=live              -> today's shifts with on-site status (office board)
// GET  /field.php?shift_id=N               -> { events:[...] } timeline for a shift
// POST /field.php {shift_id, kind, note?, lat?, lng?, signer?} -> log a field event
//   kind: clock_in | clock_out | check_in | panic | note | task | signoff
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

// Is this shift assigned to the worker behind this login? Staff accounts are not
// worker-linked, so this is only ever consulted for worker logins.
function shift_belongs_to(mysqli $conn, array $user, int $shiftId): bool
{
    $workerId = linked_worker_id($user);
    if ($workerId === null) return false;
    $stmt = $conn->prepare('SELECT 1 FROM shifts WHERE id = ? AND worker_id = ? LIMIT 1');
    $stmt->bind_param('ii', $shiftId, $workerId);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

// Derive an on-the-ground status for a shift from its events + clock.
// Implementation in pure.php, where it is tested against a pinned clock.
function field_status(array $kinds, string $startsAt, string $endsAt): string
{
    return rg_field_status($kinds, $startsAt, $endsAt);
}

if ($action === 'live' && method() === 'GET') {
    // The office board: every shift today, with worker and client names on it.
    require_staff($user);
    $today = date('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.division, s.site_name, s.starts_at, s.ends_at, s.status,
                w.name AS worker, c.name AS client
         FROM shifts s
         LEFT JOIN workers w ON w.id = s.worker_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE DATE(s.starts_at) = ? AND s.status IN ("scheduled","open")
         ORDER BY s.starts_at'
    );
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $shifts = [];
    while ($s = $res->fetch_assoc()) {
        $kinds = [];
        $e = $conn->query('SELECT kind FROM shift_events WHERE shift_id = ' . (int) $s['id']);
        while ($ev = $e->fetch_assoc()) $kinds[] = $ev['kind'];
        $shifts[] = [
            'id' => (int) $s['id'], 'title' => $s['title'], 'division' => $s['division'],
            'site_name' => $s['site_name'], 'client' => $s['client'], 'worker' => $s['worker'],
            'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'],
            'field_status' => $s['status'] === 'open' ? 'unfilled' : field_status($kinds, $s['starts_at'], $s['ends_at']),
            'panic' => in_array('panic', $kinds, true),
        ];
    }
    json_out(['shifts' => $shifts]);
}

if (method() === 'GET' && isset($_GET['shift_id'])) {
    $sid = (int) $_GET['shift_id'];
    // Staff see any shift's timeline; a worker sees only their own shifts.
    if (!is_staff($user) && !shift_belongs_to($conn, $user, $sid)) {
        fail('You do not have access to this.', 403);
    }
    $stmt = $conn->prepare(
        'SELECT e.id, e.kind, e.note, e.lat, e.lng, e.signer, e.created_at, w.name AS worker
         FROM shift_events e LEFT JOIN workers w ON w.id = e.worker_id
         WHERE e.shift_id = ? ORDER BY e.created_at'
    );
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $res = $stmt->get_result();
    $events = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $events[] = $row;
    }
    json_out(['events' => $events]);
}

if (method() === 'POST') {
    $data = body();
    $shiftId = (int) ($data['shift_id'] ?? 0);
    $kind = trim((string) ($data['kind'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $signer = trim((string) ($data['signer'] ?? ''));
    $lat = isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null;
    $lng = isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null;
    $valid = ['clock_in', 'clock_out', 'check_in', 'panic', 'note', 'task', 'signoff'];
    if ($shiftId <= 0 || !in_array($kind, $valid, true)) fail('A shift and valid event kind are required.');

    // Resolve the worker on this shift (used to stamp the event).
    $stmt = $conn->prepare('SELECT worker_id, title FROM shifts WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) fail('Shift not found.', 404);

    // A worker may only log events against a shift assigned to them. Without this,
    // any authenticated account could clock in on someone else's shift or raise a
    // panic alert against an arbitrary shift id.
    if (!is_staff($user)) {
        $workerId = linked_worker_id($user);
        if ($workerId === null || $shift['worker_id'] === null || (int) $shift['worker_id'] !== $workerId) {
            fail('You are not assigned to this shift.', 403);
        }
    }

    // Prefer the logged-in user's linked worker, else the shift's worker.
    $workerId = $user['worker_id'] ? (int) $user['worker_id'] : ($shift['worker_id'] !== null ? (int) $shift['worker_id'] : null);

    $noteVal = $note !== '' ? $note : null;
    $signerVal = $signer !== '' ? $signer : null;
    $stmt = $conn->prepare(
        'INSERT INTO shift_events (shift_id, worker_id, kind, note, lat, lng, signer) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissdds', $shiftId, $workerId, $kind, $noteVal, $lat, $lng, $signerVal);
    $stmt->execute();

    // Mark the shift completed when clocked out.
    if ($kind === 'clock_out') {
        $conn->query('UPDATE shifts SET status = "completed" WHERE id = ' . $shiftId . ' AND status = "scheduled"');
    }
    // Panic / lone-worker alert → escalate hard.
    if ($kind === 'panic') {
        $who = $user['name'] ?? 'A worker';
        notify($conn, null, 'PANIC ALERT', "$who on shift \"{$shift['title']}\"" . ($note ? " — $note" : ''), 'panic', '/app/field');
        // Sent synchronously ON PURPOSE. A lone-worker panic alert waiting in a
        // queue for the next cron run is not an alert. This is the one place
        // where blocking the request is the right trade.
        if (function_exists('mail_enabled') && mail_enabled()) {
            send_mail(MAIL_TO, '[PANIC] Lone-worker alert', "$who triggered a panic alert on shift #$shiftId ({$shift['title']}).\n$note");
        }
    }
    audit($conn, (int) $user['id'], 'field.' . $kind, "shift #$shiftId");
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
