<?php
// Scheduling & dispatch.
// GET  /scheduling.php?from=YYYY-MM-DD&to=YYYY-MM-DD   -> { shifts: [...] } (incl. open shifts)
// GET  /scheduling.php?action=match&division=&starts_at=&ends_at=&skill_id?  -> { workers: [...] }
// POST /scheduling.php {worker_id, title, client_id?, site_name?, starts_at, ends_at}  -> assign a worker
// POST /scheduling.php?action=open {title, division, client_id?, site_name?, starts_at, ends_at} -> open shift
// POST /scheduling.php?action=broadcast&id=N  -> offer an open shift to eligible workers (SMS + in-app)
// POST /scheduling.php?action=assign&id=N {worker_id} -> fill an open shift, or move it to someone else
// POST /scheduling.php?action=unassign&id=N  -> return a scheduled shift to open
// POST /scheduling.php?action=cancel&id=N    -> cancel a shift (the rota keeps the record)
//
// Guards: no double-booking; a worker with a lapsed required check (DBS for care,
// SIA for security) or no right to work cannot be scheduled or matched.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
// The rota names workers, clients and sites. Reads were guarded by nothing but
// a login, so a client portal or worker account could pull the whole schedule.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

// The legacy compliance pair -- worker_compliant() and max_cert_expiry() -- used
// to live here: a hard-coded DBS-for-care / SIA-for-security test that matching
// and broadcast consulted while assignment consulted the configured rule set.
// Two gates, two answers for the same worker. Everything now calls
// rg_deployment_check() in compliance-lib.php, so they are gone rather than left
// for the next person to reach for.

// Load a shift for a lifecycle change, or fail with the reason.
function load_shift_for_change(mysqli $conn, int $id): array
{
    if ($id <= 0) fail('A shift is required.');
    $stmt = $conn->prepare(
        'SELECT id, worker_id, division, title, starts_at, ends_at, status
         FROM shifts WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) fail('Shift not found.', 404);
    // A shift that has already been worked is a record, not a plan. Changing who
    // was on it after the fact would contradict the field events and the
    // timesheet already attached to it.
    if ($shift['status'] === 'completed') fail('That shift is already complete.', 409);
    return $shift;
}

// Classify a shift window into the availability bucket it needs.
function shift_bucket(int $startTs): string
{
    $hour = (int) date('G', $startTs);
    $dow = (int) date('N', $startTs);
    if ($hour >= 20 || $hour < 6) return 'nights';
    if ($dow >= 6) return 'weekends';
    return 'days';
}

// ---------- Smart match: eligible workers for a prospective shift ----------
if ($action === 'match' && method() === 'GET') {
    $division = trim((string) ($_GET['division'] ?? ''));
    $starts = trim((string) ($_GET['starts_at'] ?? ''));
    $ends = trim((string) ($_GET['ends_at'] ?? ''));
    $skillId = isset($_GET['skill_id']) && $_GET['skill_id'] !== '' ? (int) $_GET['skill_id'] : null;
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('A valid division is required.');
    $startTs = strtotime($starts);
    $endTs = strtotime($ends);
    if (!$startTs || !$endTs || $endTs <= $startTs) fail('Valid start and end date-times are required.');
    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    $bucket = shift_bucket($startTs);
    $availCol = 'avail_' . $bucket; // avail_nights | avail_weekends | avail_days

    $sql =
        'SELECT w.id, w.name, w.role, w.division, w.right_to_work, w.dbs_expiry, w.sia_expiry,
                w.home_postcode, w.travel_radius_mi, w.' . $availCol . ' AS available,
                (SELECT COUNT(*) FROM shifts s WHERE s.worker_id = w.id AND s.status = "scheduled"
                   AND s.starts_at >= NOW()) AS upcoming
         FROM workers w
         WHERE w.deleted_at IS NULL AND w.division = ? AND w.status != "inactive"';
    $params = [$division];
    $types = 's';
    if ($skillId) {
        $sql .= ' AND EXISTS (SELECT 1 FROM worker_skills ws WHERE ws.worker_id = w.id AND ws.skill_id = ?)';
        $params[] = $skillId;
        $types .= 'i';
    }
    $sql .= ' ORDER BY upcoming ASC, w.name ASC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    // rg_deployment_check(), not the legacy worker_compliant().
    //
    // The two answer from different rule sources: worker_compliant() is the
    // hard-coded DBS-for-care / SIA-for-security pair, while assignment consults
    // the configured rule set. Verified against a local database: with a nurse's
    // NMC registration expired -- mandatory for care in compliance_rules, and
    // invisible to worker_compliant() -- "Find eligible" listed Bola Adeyemi and
    // assigning him was refused. The office was being offered workers it could
    // not book, and broadcast was texting them an offer they could not accept.
    $eligible = [];
    while ($w = $res->fetch_assoc()) {
        if (!(int) $w['available']) continue;
        if (!rg_deployment_check($conn, (int) $w['id'], $division)['eligible']) continue;
        if (rg_shift_clash($conn, (int) $w['id'], $startSql, $endSql)) continue;
        $eligible[] = [
            'id' => (int) $w['id'], 'name' => $w['name'], 'role' => $w['role'],
            'home_postcode' => $w['home_postcode'], 'travel_radius_mi' => (int) $w['travel_radius_mi'],
            'upcoming_shifts' => (int) $w['upcoming'],
        ];
    }
    json_out(['workers' => $eligible, 'bucket' => $bucket]);
}

// ---------- Create an open shift (no worker yet) ----------
if ($action === 'open' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? ''));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    // migration 014 added fk_shifts_client, so a stale id from a page left open
    // reached the INSERT and came back as a 500 carrying the constraint name and
    // the database name. shift-templates.php and jobs.php both already ask.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);
    $site = trim((string) ($data['site_name'] ?? ''));
    $starts = trim((string) ($data['starts_at'] ?? ''));
    $ends = trim((string) ($data['ends_at'] ?? ''));
    if ($title === '' || !in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Title and a valid division are required.');
    $startTs = strtotime($starts);
    $endTs = strtotime($ends);
    if (!$startTs || !$endTs || $endTs <= $startTs) fail('Valid start and end date-times are required.');

    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    $siteVal = $site !== '' ? $site : null;
    $stmt = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at, status)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, "open")'
    );
    $stmt->bind_param('isssss', $clientId, $division, $title, $siteVal, $startSql, $endSql);
    $stmt->execute();
    $id = $conn->insert_id;
    audit($conn, (int) $user['id'], 'shift.open', "$title (#$id)");
    json_out(['id' => (int) $id], 201);
}

// ---------- Broadcast an open shift to eligible workers ----------
if ($action === 'broadcast' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    // Every broadcast sends a chargeable message to every eligible worker in the
    // division. Nothing stopped the same shift being broadcast over and over.
    rate_limit($conn, 'broadcast', 12, 600);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A shift is required.');

    $stmt = $conn->prepare('SELECT id, division, title, site_name, starts_at, ends_at, status FROM shifts WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) fail('Shift not found.', 404);
    if ($shift['status'] !== 'open') fail('Only open shifts can be broadcast.', 409);

    $startTs = strtotime($shift['starts_at']);
    $startSql = $shift['starts_at'];
    $endSql = $shift['ends_at'];
    $bucket = shift_bucket($startTs);
    $availCol = 'avail_' . $bucket;

    // The division is bound rather than escaped into the string: not injectable
    // as it stood, but rule 4 says prepared statements always, and the column
    // name beside it is the only thing here that cannot be.
    $stmt = $conn->prepare(
        'SELECT id, name, phone, division, ' . $availCol . ' AS available
         FROM workers
         WHERE deleted_at IS NULL AND status != "inactive" AND division = ?'
    );
    $stmt->bind_param('s', $shift['division']);
    $stmt->execute();
    $res = $stmt->get_result();
    $when = date('D j M, H:i', $startTs);
    $count = 0;
    while ($w = $res->fetch_assoc()) {
        if (!(int) $w['available']) continue;
        // The same gate as match and assign -- see the note on the match branch.
        if (!rg_deployment_check($conn, (int) $w['id'], $shift['division'])['eligible']) continue;
        if (rg_shift_clash($conn, (int) $w['id'], $startSql, $endSql)) continue;

        $token = bin2hex(random_bytes(20));
        $stmt = $conn->prepare('INSERT INTO shift_offers (shift_id, worker_id, token) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $id, $w['id'], $token);
        $stmt->execute();

        $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : '';
        $link = $base . '/offer/' . $token;
        message_worker($conn, $w['phone'], "RitaGreen shift: {$shift['title']} on $when. First to accept gets it: $link");
        $count++;
    }
    audit($conn, (int) $user['id'], 'shift.broadcast', "shift #$id to $count workers");
    json_out(['offered' => $count]);
}

// ---------- Shift lifecycle: assign, unassign, cancel ----------
//
// A shift could be created and broadcast and then never changed again. If the
// worker fell ill, the client moved the booking or the shift was raised against
// the wrong person, the rota had no answer: the row stayed, wrong, and the only
// way out was the database. Creating is not the same as scheduling.
if (in_array($action, ['assign', 'unassign', 'cancel'], true) && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $shift = load_shift_for_change($conn, $id);
    $uid = (int) $user['id'];

    if ($action === 'cancel') {
        if ($shift['status'] === 'cancelled') json_out(['ok' => true, 'status' => 'cancelled']);
        $stmt = $conn->prepare('UPDATE shifts SET status = "cancelled" WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($conn, $uid, 'shift.cancel', $shift['title'] . " (#$id)");
        json_out(['ok' => true, 'status' => 'cancelled']);
    }

    if ($action === 'unassign') {
        if ($shift['worker_id'] === null) fail('That shift has nobody on it.', 409);
        // Back to open rather than cancelled: the work still needs covering, and
        // an open shift is the thing broadcast can offer round.
        $stmt = $conn->prepare('UPDATE shifts SET worker_id = NULL, status = "open" WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($conn, $uid, 'shift.unassign', "worker #{$shift['worker_id']} off shift #$id");
        json_out(['ok' => true, 'status' => 'open']);
    }

    // assign — fill an open shift, or move a scheduled one to somebody else.
    $workerId = (int) (body()['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');

    $stmt = $conn->prepare(
        'SELECT id, name, division FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    // The same gate as creating a shift, for the same reason: who ends up on the
    // rota is what matters, not which screen put them there.
    $gate = rg_deployment_check($conn, $workerId, $w['division']);
    if (!$gate['eligible']) {
        audit($conn, $uid, 'shift.blocked', "worker #$workerId: " . $gate['reason']);
        json_out([
            'error' => $w['name'] . ' cannot be deployed. ' . $gate['reason'],
            'eligibility' => [
                'status' => $gate['status'],
                'score' => $gate['score'],
                'blocking' => array_map(
                    static fn(array $b): array => ['requirement' => $b['label'], 'reason' => $b['reason']],
                    $gate['blocking']
                ),
            ],
        ], 409);
    }
    if (rg_shift_clash($conn, $workerId, $shift['starts_at'], $shift['ends_at'], $id)) {
        fail($w['name'] . ' is already booked for an overlapping shift.', 409);
    }

    // The shift takes the worker's division: a cleaner covering a cleaning shift
    // keeps it cleaning, and an open shift raised under the wrong one is
    // corrected by the assignment rather than left to disagree with its worker.
    $division = $w['division'];
    $stmt = $conn->prepare(
        'UPDATE shifts SET worker_id = ?, division = ?, status = "scheduled" WHERE id = ?'
    );
    $stmt->bind_param('isi', $workerId, $division, $id);
    $stmt->execute();

    $was = $shift['worker_id'] !== null ? "from worker #{$shift['worker_id']} " : '';
    audit($conn, $uid, 'shift.assign', "shift #$id {$was}to worker #$workerId");
    json_out(['ok' => true, 'status' => 'scheduled', 'worker' => $w['name'], 'division' => $division]);
}

// ---------- Calendar read ----------
if (method() === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-t');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fail('from and to must be dates (YYYY-MM-DD).');
    }
    $stmt = $conn->prepare(
        'SELECT s.id, s.worker_id, w.name AS worker, s.division, s.title, s.site_name,
                s.client_id, c.name AS client, s.starts_at, s.ends_at, s.status
         FROM shifts s
         LEFT JOIN workers w ON w.id = s.worker_id
         LEFT JOIN clients c ON c.id = s.client_id
         WHERE s.deleted_at IS NULL
           AND s.starts_at >= ? AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY s.starts_at'
    );
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $shifts = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $shifts[] = $row;
    }
    json_out(['shifts' => $shifts]);
}

// ---------- Assign a worker (compliance + clash guarded) ----------
if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $workerId = (int) ($data['worker_id'] ?? 0);
    $title = trim((string) ($data['title'] ?? ''));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    // migration 014 added fk_shifts_client, so a stale id from a page left open
    // reached the INSERT and came back as a 500 carrying the constraint name and
    // the database name. shift-templates.php and jobs.php both already ask.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);
    $site = trim((string) ($data['site_name'] ?? ''));
    $starts = trim((string) ($data['starts_at'] ?? ''));
    $ends = trim((string) ($data['ends_at'] ?? ''));

    if ($workerId <= 0 || $title === '' || $starts === '' || $ends === '') {
        fail('Worker, title, start and end are required.');
    }
    $startTs = strtotime($starts);
    $endTs = strtotime($ends);
    if (!$startTs || !$endTs) fail('Start and end must be valid date-times.');
    if ($endTs <= $startTs) fail('The shift must end after it starts.');

    $stmt = $conn->prepare(
        'SELECT id, name, division, right_to_work, dbs_expiry, sia_expiry
         FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    // ---- The deployment gate ----
    //
    // One check, driven by the rules configured for the service, rather than the
    // hard-coded DBS-for-care / SIA-for-security pair this used to carry. The
    // refusal names every blocking requirement, so the office fixes them in one
    // pass instead of discovering them one 409 at a time.
    //
    // A warning does not block: a credential inside its expiry window is still
    // valid today, and standing someone down early is its own failure.
    $gate = rg_deployment_check($conn, $workerId, $w['division']);
    if (!$gate['eligible']) {
        audit($conn, (int) $user['id'], 'shift.blocked', "worker #$workerId: " . $gate['reason']);
        json_out([
            'error' => $w['name'] . ' cannot be deployed. ' . $gate['reason'],
            'eligibility' => [
                'status' => $gate['status'],
                'score' => $gate['score'],
                'blocking' => array_map(
                    static fn(array $b): array => ['requirement' => $b['label'], 'reason' => $b['reason']],
                    $gate['blocking']
                ),
            ],
        ], 409);
    }

    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    if (rg_shift_clash($conn, $workerId, $startSql, $endSql)) {
        fail($w['name'] . ' is already booked for an overlapping shift.', 409);
    }

    $division = $w['division'];
    $siteVal = $site !== '' ? $site : null;
    $stmt = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssss', $workerId, $clientId, $division, $title, $siteVal, $startSql, $endSql);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'shift.create', "$title for worker #$workerId");
    json_out([
        'shift' => [
            'id' => (int) $id, 'worker_id' => $workerId, 'worker' => $w['name'],
            'division' => $division, 'title' => $title, 'site_name' => $siteVal,
            'client_id' => $clientId, 'starts_at' => $startSql, 'ends_at' => $endSql,
            'status' => 'scheduled',
        ],
    ], 201);
}

fail('Method not allowed.', 405);
