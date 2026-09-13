<?php
// §8 Field operations & service delivery.
// GET  /field.php?action=live              -> today's shifts with on-site status (office board)
// GET  /field.php?shift_id=N               -> { events:[...], location_withheld } timeline for a shift
//   lat/lng are returned to the worker whose trail it is, and to roles holding the
//   'employment' zone. Everyone else gets the timeline with the coordinates nulled.
// POST /field.php {shift_id, kind, note?, lat?, lng?, signer?} -> log a field event
//   kind: clock_in | clock_out | check_in | panic | panic_ack | note | task | signoff
//   panic_ack is office-only: acknowledging an alert is the office responding to
//   it, not the worker standing it down. A shift cannot be clocked in twice, or
//   clocked out without being clocked in.
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
    // "completed" belongs on today's board.
    //
    // Clocking out sets shifts.status = 'completed' further down this file, and
    // this filter listed only scheduled and open -- so a shift finished at 14:00
    // disappeared from the day at 14:00, and STATUS_META.completed in FieldOps
    // was unreachable. A duty manager needs to see what has already been done
    // today, not only what has not. Cancelled stays off it.
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.division, s.site_name, s.starts_at, s.ends_at, s.status,
                w.name AS worker, c.name AS client
         FROM shifts s
         LEFT JOIN workers w ON w.id = s.worker_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE DATE(s.starts_at) = ? AND s.deleted_at IS NULL
           AND s.status IN ("scheduled","open","completed")
         ORDER BY s.starts_at'
    );
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($s = $res->fetch_assoc()) $rows[] = $s;

    // One query for every shift's events, not one per shift. The loop ran a
    // separate SELECT for each row, with the id interpolated rather than bound.
    $kindsByShift = [];
    if ($rows) {
        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        // Ordered, because rg_panic_open() reads the sequence: a shift can be
        // raised, acknowledged and raised again.
        $ev = $conn->prepare("SELECT shift_id, kind FROM shift_events WHERE shift_id IN ($marks) ORDER BY id");
        $ev->bind_param(str_repeat('i', count($ids)), ...$ids);
        $ev->execute();
        $evRes = $ev->get_result();
        while ($row = $evRes->fetch_assoc()) {
            $kindsByShift[(int) $row['shift_id']][] = $row['kind'];
        }
    }

    $shifts = [];
    foreach ($rows as $s) {
        $kinds = $kindsByShift[(int) $s['id']] ?? [];
        $shifts[] = [
            'id' => (int) $s['id'], 'title' => $s['title'], 'division' => $s['division'],
            'site_name' => $s['site_name'], 'client' => $s['client'], 'worker' => $s['worker'],
            'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'],
            'field_status' => $s['status'] === 'open'
                ? 'unfilled'
                : ($s['status'] === 'completed' ? 'completed' : field_status($kinds, $s['starts_at'], $s['ends_at'])),
            'panic' => rg_panic_open($kinds),
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

    // Where somebody physically was is not ordinary operational detail.
    //
    // docs/PRIVACY.md records shift_events location as personal data about the
    // worker, and nothing here asked anything about it: is_staff() is true for
    // editor, trainer and employee, and access.ts grants /app/field to all of
    // STAFF, so a content editor could open the board, click any shift and read
    // a named colleague's coordinates to six decimal places. The timeline is
    // still useful to them -- who clocked in, when, what was noted -- so the
    // coordinates are withheld rather than the whole trail, the same way
    // workers.php withholds a pay rate from a profile it still returns.
    //
    // The 'employment' zone, not 'compliance': migration 015 grants compliance to
    // trainer and employee as well, which would leave the gate excluding only the
    // content role, and where a person physically was is employment-record
    // detail rather than a vetting credential. Employment is the office alone.
    //
    // A worker always sees their own trail in full: it is their own location.
    $ownTrail = !is_staff($user);
    $seesLocation = $ownTrail || can_view_zone($conn, $user, 'employment');
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
        if (!$seesLocation) {
            $row['lat'] = null;
            $row['lng'] = null;
        }
        $events[] = $row;
    }
    json_out(['events' => $events, 'location_withheld' => !$seesLocation]);
}

if (method() === 'POST') {
    $data = body();
    $shiftId = (int) ($data['shift_id'] ?? 0);
    $kind = trim((string) ($data['kind'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    $signer = trim((string) ($data['signer'] ?? ''));
    // Stored at a precision proportionate to the purpose -- see rg_coord_places.
    $lat = isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null;
    $lng = isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null;
    $valid = ['clock_in', 'clock_out', 'check_in', 'panic', 'panic_ack', 'note', 'task', 'signoff'];
    if ($shiftId <= 0 || !in_array($kind, $valid, true)) fail('A shift and valid event kind are required.');

    // Acknowledging an alert is the office responding to it, not the worker
    // standing it down, so it is the one kind a worker cannot post.
    if ($kind === 'panic_ack') require_role($user, 'super_admin', 'admin', 'manager');

    // A routine attendance record is stored to ~110 m: enough to show somebody
    // was at the site, not enough to be a movement history. A panic alert keeps
    // full precision, because the point of it is that somebody has to be found.
    $places = rg_coord_places($kind);
    $lat = rg_round_coord($lat, $places);
    $lng = rg_round_coord($lng, $places);

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

    // A clock sequence that cannot have happened is a data-entry problem, not a
    // record. Nothing stopped two clock_in rows on one shift, or a clock_out
    // with no clock_in, and both corrupt the derived field status -- and, once
    // clock events feed timesheets, the hours.
    if ($kind === 'clock_in' || $kind === 'clock_out' || $kind === 'panic_ack') {
        $stmt = $conn->prepare(
            'SELECT kind FROM shift_events WHERE shift_id = ? AND kind IN ("clock_in","clock_out","panic","panic_ack") ORDER BY id'
        );
        $stmt->bind_param('i', $shiftId);
        $stmt->execute();
        $prior = [];
        $priorRes = $stmt->get_result();
        while ($r = $priorRes->fetch_assoc()) $prior[] = $r['kind'];

        if ($kind === 'clock_in' && in_array('clock_in', $prior, true)) {
            fail('That shift has already been clocked in.', 409);
        }
        if ($kind === 'clock_out') {
            if (!in_array('clock_in', $prior, true)) fail('That shift has not been clocked in.', 409);
            if (in_array('clock_out', $prior, true)) fail('That shift has already been clocked out.', 409);
        }
        if ($kind === 'panic_ack' && !rg_panic_open($prior)) {
            fail('There is no open panic alert on that shift.', 409);
        }
    }

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
