<?php
// Scheduling & dispatch.
// GET  /scheduling.php?from=YYYY-MM-DD&to=YYYY-MM-DD   -> { shifts: [...] } (incl. open shifts)
// GET  /scheduling.php?action=match&division=&starts_at=&ends_at=&skill_id?  -> { workers: [...] }
// POST /scheduling.php {worker_id, title, client_id?, site_name?, starts_at, ends_at}  -> assign a worker
// POST /scheduling.php?action=open {title, division, client_id?, site_name?, starts_at, ends_at} -> open shift
// POST /scheduling.php?action=broadcast&id=N  -> offer an open shift to eligible workers (SMS + in-app)
//
// Guards: no double-booking; a worker with a lapsed required check (DBS for care,
// SIA for security) or no right to work cannot be scheduled or matched.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

// Latest expiry of a still-relevant cert on the compliance register, so a valid
// SIA/DBS recorded in Compliance counts at scheduling time.
function max_cert_expiry(mysqli $conn, int $workerId, string $like): ?string
{
    $stmt = $conn->prepare(
        'SELECT MAX(expiry) AS e FROM certifications
         WHERE worker_id = ? AND type LIKE ? AND expiry IS NOT NULL'
    );
    $stmt->bind_param('is', $workerId, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && $row['e'] ? $row['e'] : null;
}

// Is a worker compliant to work in their division as of today?
function worker_compliant(mysqli $conn, array $w): bool
{
    if (!$w['right_to_work']) return false;
    $today = date('Y-m-d');
    if ($w['division'] === 'security') {
        $sia = $w['sia_expiry'];
        $certSia = max_cert_expiry($conn, (int) $w['id'], '%SIA%');
        if ($certSia && (!$sia || $certSia > $sia)) $sia = $certSia;
        if (!$sia || $sia < $today) return false;
    }
    if ($w['division'] === 'care') {
        $dbs = $w['dbs_expiry'];
        $certDbs = max_cert_expiry($conn, (int) $w['id'], '%DBS%');
        if ($certDbs && (!$dbs || $certDbs > $dbs)) $dbs = $certDbs;
        if ($dbs && $dbs < $today) return false;
    }
    return true;
}

// Does this worker have an overlapping scheduled shift?
function has_clash(mysqli $conn, int $workerId, string $startSql, string $endSql): bool
{
    $stmt = $conn->prepare(
        'SELECT id FROM shifts
         WHERE worker_id = ? AND status = "scheduled" AND starts_at < ? AND ends_at > ? LIMIT 1'
    );
    $stmt->bind_param('iss', $workerId, $endSql, $startSql);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_assoc();
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

    $eligible = [];
    while ($w = $res->fetch_assoc()) {
        if (!worker_compliant($conn, $w)) continue;
        if (!(int) $w['available']) continue;
        if (has_clash($conn, (int) $w['id'], $startSql, $endSql)) continue;
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

    $res = $conn->query(
        'SELECT id, name, phone, division, right_to_work, dbs_expiry, sia_expiry, ' . $availCol . ' AS available
         FROM workers
         WHERE deleted_at IS NULL AND status != "inactive" AND division = "' . $conn->real_escape_string($shift['division']) . '"'
    );
    $when = date('D j M, H:i', $startTs);
    $count = 0;
    while ($w = $res->fetch_assoc()) {
        if (!(int) $w['available']) continue;
        if (!worker_compliant($conn, $w)) continue;
        if (has_clash($conn, (int) $w['id'], $startSql, $endSql)) continue;

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
         WHERE s.starts_at >= ? AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY)
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

    if (!$w['right_to_work']) fail($w['name'] . ' has no right-to-work on file and cannot be scheduled.', 409);
    if (!worker_compliant($conn, $w)) {
        fail($w['name'] . "'s required check (DBS/SIA) is missing or expired. They cannot be scheduled.", 409);
    }

    $startSql = date('Y-m-d H:i:s', $startTs);
    $endSql = date('Y-m-d H:i:s', $endTs);
    if (has_clash($conn, $workerId, $startSql, $endSql)) {
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
