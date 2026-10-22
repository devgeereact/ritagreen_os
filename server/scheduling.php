<?php
// The rota.
//
// Reads
// GET  /scheduling.php?from=YYYY-MM-DD&to=YYYY-MM-DD[&division=][&site_id=]
//        -> { shifts: [...], rest_gap_min_hours }   each shift carries its state and conflicts
// GET  /scheduling.php?id=N                         -> { shift, history, offers }
// GET  /scheduling.php?action=check&id=N&worker_id=M -> { check }  may this worker take this shift?
// GET  /scheduling.php?action=match&id=N            -> { workers }  eligible workers, with warnings
// GET  /scheduling.php?action=match&division=&starts_at=&ends_at=[&site_id=][&skill_id=]
// GET  /scheduling.php?action=settings              -> { rest_gap_min_hours }
//
// Writes (every new shift is a DRAFT until published; workers never see drafts)
// POST /scheduling.php {title, division, starts_at, ends_at, site_id?, client_id?, site_name?,
//                       worker_id?, acknowledge_warnings?}               -> create a draft
// POST /scheduling.php?action=open {...}                                 -> the same, never with a worker
// POST /scheduling.php?action=update&id=N {title?, starts_at?, ends_at?, site_id?, division?,
//                                          acknowledge_warnings?}       -> edit; a published change tells the worker
// POST /scheduling.php?action=assign&id=N {worker_id, acknowledge_warnings?}
// POST /scheduling.php?action=unassign&id=N
// POST /scheduling.php?action=cancel&id=N {reason}                      -> reason required; the worker is told
// POST /scheduling.php?action=publish {from, to, division?, site_id?} | {ids: [...]}
// POST /scheduling.php?action=copy_week {week_start, division?, site_id?} -> last week's shifts as drafts
// POST /scheduling.php?action=broadcast&id=N                            -> offer a published open shift to all eligible
// POST /scheduling.php?action=offer&id=N {worker_ids: [...], acknowledge_warnings?}
// POST /scheduling.php?action=settings {rest_gap_min_hours}             -> admin only
//
// Who may be put on a shift is decided in one place: rg_assignment_check() in
// compliance-lib.php. Blocking reasons (employment, evidence including the
// site's own requirements, a clash, approved leave) refuse. Warnings (marked
// unavailable, a short rest gap) refuse until the office sends
// acknowledge_warnings, which is audited.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';
require __DIR__ . '/rota-lib.php';

$user = require_auth($conn);
// The rota names workers, clients and sites. Reads were guarded by nothing but
// a login, so a client portal or worker account could pull the whole schedule.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';
$uid = (int) $user['id'];

const ROTA_SERVICES = ['care', 'academy', 'cleaning', 'security'];

// ---------------------------------------------------------------- helpers

/** A wall-clock datetime from the request, as 'Y-m-d H:i:s', or a 400. */
function rota_datetime(string $value, string $label): string
{
    $ts = strtotime(trim($value));
    if ($value === '' || $ts === false) fail("$label must be a valid date and time.");
    return date('Y-m-d H:i:s', $ts);
}

/** Load a shift and hold the account to its service. */
function rota_load(mysqli $conn, array $user, int $id): array
{
    if ($id <= 0) fail('A shift is required.');
    $shift = rg_shift_row($conn, $id);
    if (!$shift) fail('Shift not found.', 404);
    require_service_scope($user, $shift['division']);
    return $shift;
}

/**
 * A shift that can still be changed.
 *
 * A shift that has been worked is a record, not a plan: changing who was on it
 * would contradict the field events and the timesheet attached to it. A
 * cancelled one stays cancelled; cover is a new shift.
 */
function rota_load_live(mysqli $conn, array $user, int $id): array
{
    $shift = rota_load($conn, $user, $id);
    if ($shift['status'] === 'completed') fail('That shift is already complete.', 409);
    if ($shift['status'] === 'cancelled') fail('That shift is cancelled. Add a new one to cover the slot.', 409);
    return $shift;
}

/** A site a shift may be booked at, or a refusal. */
function rota_site(mysqli $conn, int $siteId): array
{
    $stmt = $conn->prepare('SELECT id, client_id, name, active FROM sites WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $site = $stmt->get_result()->fetch_assoc();
    if (!$site) fail('Site not found.', 404);
    if (!(int) $site['active']) fail('That site is closed. Reopen it on the client before booking shifts there.', 409);
    return $site;
}

/** A worker row with what the rota needs, or a 404. */
function rota_worker(mysqli $conn, int $workerId): array
{
    if ($workerId <= 0) fail('A worker is required.');
    $stmt = $conn->prepare('SELECT id, name, division, phone FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);
    return $w;
}

/** The check, shaped for the screen: what blocks, what warns, and the evidence detail. */
function rota_check_out(array $check): array
{
    $gate = $check['gate'] ?? [];
    return [
        'ok' => $check['ok'],
        'blocking' => $check['blocking'],
        'warnings' => $check['warnings'],
        'eligibility' => [
            'status' => $gate['status'] ?? null,
            'score' => $gate['score'] ?? null,
            'blocking' => array_map(
                static fn(array $b): array => ['requirement' => $b['label'], 'reason' => $b['reason']],
                $gate['blocking'] ?? []
            ),
        ],
    ];
}

/**
 * Refuse unless this worker may take this shift.
 *
 * A failed check keeps what the office typed (nothing is written) and says
 * exactly what blocks the worker. Warnings refuse too, until the office says it
 * has read them; the acknowledgement is then audited by the caller.
 */
function rota_require_check(mysqli $conn, ?int $uid, array $check, string $name, bool $ack, int $shiftId = 0): void
{
    if (!$check['ok']) {
        $reasons = implode(' ', array_column($check['blocking'], 'reason'));
        if ($shiftId > 0) {
            rg_shift_audit($conn, $uid, 'shift.blocked', $shiftId, "$name: $reasons");
        } else {
            audit($conn, $uid, 'shift.blocked', "$name: $reasons");
        }
        json_out(['error' => "$name cannot work this shift. $reasons", 'check' => rota_check_out($check)], 409);
    }
    if ($check['warnings'] && !$ack) {
        $reasons = implode(' ', array_column($check['warnings'], 'reason'));
        json_out([
            'error' => "Check before booking $name: $reasons",
            'needs_acknowledgement' => true,
            'check' => rota_check_out($check),
        ], 409);
    }
}

/** Record that the office booked somebody despite warnings, and which. */
function rota_audit_acknowledged(mysqli $conn, int $uid, int $shiftId, string $name, array $warnings): void
{
    if (!$warnings) return;
    rg_shift_audit(
        $conn, $uid, 'shift.warnings_acknowledged', $shiftId,
        "$name: " . implode(' ', array_column($warnings, 'reason')),
        null, null, implode(', ', array_column($warnings, 'code'))
    );
}

/** Expire the live offers on a shift: it has been filled, cancelled or moved. */
function rota_expire_offers(mysqli $conn, int $shiftId): void
{
    $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired" WHERE shift_id = ? AND status = "sent"');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
}

/** Classify a shift window into the availability bucket it needs. */
function shift_bucket(int $startTs): string
{
    $hour = (int) date('G', $startTs);
    $dow = (int) date('N', $startTs);
    if ($hour >= 20 || $hour < 6) return 'nights';
    if ($dow >= 6) return 'weekends';
    return 'days';
}

/**
 * Send one offer: a link that works until the shift starts, in the app, by email
 * and by text (text only while the owner has SMS switched on; message_worker()
 * honours the switch). One live offer per worker per shift (migration 027), so
 * offering again refreshes the link rather than sending a second one.
 */
function rota_send_offer(mysqli $conn, array $shift, array $worker, ?int $actorId): void
{
    $shiftId = (int) $shift['id'];
    $workerId = (int) $worker['id'];
    $token = bin2hex(random_bytes(20));
    $stmt = $conn->prepare(
        'INSERT INTO shift_offers (shift_id, worker_id, token, status, expires_at)
         VALUES (?, ?, ?, "sent", ?)
         ON DUPLICATE KEY UPDATE token = VALUES(token), status = "sent",
                                 expires_at = VALUES(expires_at), responded_at = NULL'
    );
    $stmt->bind_param('iiss', $shiftId, $workerId, $token, $shift['starts_at']);
    $stmt->execute();

    $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : '';
    $link = $base . '/offer/' . $token;
    rg_notify_worker($conn, $workerId, 'Shift offered',
        rg_shift_summary($shift) . ". First to accept gets it: $link", $shiftId, $actorId);
    message_worker($conn, $worker['phone'] ?? null,
        "RitaGreen shift: {$shift['title']} on " . rg_shift_when($shift['starts_at']) . ". First to accept gets it: $link");
}

/** Optional integer from a request body or query. */
function rota_opt_int($value): ?int
{
    return isset($value) && $value !== '' && $value !== null ? (int) $value : null;
}

// ---------------------------------------------------------------- settings

if ($action === 'settings') {
    if (method() === 'POST') {
        // The rest gap is a company rule, not a rota preference.
        require_role($user, 'super_admin', 'admin');
        $raw = body()['rest_gap_min_hours'] ?? null;
        if (!is_numeric($raw) || (int) $raw != $raw) fail('Give the minimum rest in whole hours.');
        $hours = (int) $raw;
        [$min, $max] = RG_SETTING_BOUNDS['rest_gap_min_hours'];
        if ($hours < $min || $hours > $max) fail("The minimum rest must be between $min and $max hours.");
        $before = setting_int($conn, 'rest_gap_min_hours');
        $value = (string) $hours;
        $stmt = $conn->prepare(
            'INSERT INTO app_settings (name, value, updated_by, updated_at) VALUES ("rest_gap_min_hours", ?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        );
        $stmt->bind_param('si', $value, $uid);
        $stmt->execute();
        audit($conn, $uid, 'settings.rest_gap', "$before -> $hours hours", 'setting', null,
            ['rest_gap_min_hours' => $before], ['rest_gap_min_hours' => $hours]);
        setting_rows($conn, true);
    }
    json_out(['rest_gap_min_hours' => setting_int($conn, 'rest_gap_min_hours')]);
}

// ---------------------------------------------------------------- one shift

if (method() === 'GET' && isset($_GET['id']) && $action === '') {
    $shift = rota_load($conn, $user, (int) $_GET['id']);
    $id = (int) $shift['id'];
    $stmt = $conn->prepare(
        'SELECT o.worker_id, w.name AS worker, o.status, o.expires_at, o.responded_at
           FROM shift_offers o JOIN workers w ON w.id = o.worker_id
          WHERE o.shift_id = ? ORDER BY o.id DESC'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $offers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $live = count(array_filter($offers, static fn(array $o): bool =>
        $o['status'] === 'sent' && ($o['expires_at'] === null || strtotime($o['expires_at']) > time())));
    $shift['state'] = rg_shift_state($shift, $live);
    json_out(['shift' => $shift, 'history' => rg_shift_history($conn, $id), 'offers' => $offers]);
}

// ---------------------------------------------------------------- check one worker

if ($action === 'check' && method() === 'GET') {
    $shift = rota_load_live($conn, $user, (int) ($_GET['id'] ?? 0));
    $w = rota_worker($conn, (int) ($_GET['worker_id'] ?? 0));
    require_service_scope($user, $w['division']);
    // The shift takes the worker's service when assigned (see assign below), so
    // that is the service the check is for.
    $check = rg_assignment_check($conn, (int) $w['id'], ['division' => $w['division']] + $shift, false);
    json_out(['worker' => $w['name'], 'check' => rota_check_out($check)]);
}

// ---------------------------------------------------------------- match

if ($action === 'match' && method() === 'GET') {
    $shiftId = (int) ($_GET['id'] ?? 0);
    if ($shiftId > 0) {
        $s = rota_load_live($conn, $user, $shiftId);
        $division = $s['division'];
        $startSql = $s['starts_at'];
        $endSql = $s['ends_at'];
        $siteId = $s['site_id'] !== null ? (int) $s['site_id'] : null;
    } else {
        $division = trim((string) ($_GET['division'] ?? ''));
        if (!in_array($division, ROTA_SERVICES, true)) fail('A valid division is required.');
        require_service_scope($user, $division);
        $startSql = rota_datetime((string) ($_GET['starts_at'] ?? ''), 'The start');
        $endSql = rota_datetime((string) ($_GET['ends_at'] ?? ''), 'The end');
        if ($endSql <= $startSql) fail('The shift must end after it starts.');
        $siteId = rota_opt_int($_GET['site_id'] ?? null);
    }
    $skillId = rota_opt_int($_GET['skill_id'] ?? null);
    $bucket = shift_bucket(strtotime($startSql));
    $availCol = 'avail_' . $bucket; // avail_nights | avail_weekends | avail_days

    $sql =
        'SELECT w.id, w.name, w.role, w.home_postcode, w.travel_radius_mi, w.' . $availCol . ' AS available,
                (SELECT COUNT(*) FROM shifts s WHERE s.worker_id = w.id AND s.status = "scheduled"
                   AND s.deleted_at IS NULL AND s.starts_at >= NOW()) AS upcoming
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
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // The same check as assigning, so "Find eligible" never offers somebody the
    // assign button would then refuse. persist false: this is screening a list,
    // not deciding about a person. A worker with only warnings is listed with
    // them, because the office may acknowledge and book; a blocked one is not.
    $shape = ['id' => $shiftId, 'division' => $division, 'site_id' => $siteId, 'starts_at' => $startSql, 'ends_at' => $endSql];
    $eligible = [];
    foreach ($rows as $w) {
        if (!(int) $w['available']) continue;
        $check = rg_assignment_check($conn, (int) $w['id'], $shape, false);
        if (!$check['ok']) continue;
        $eligible[] = [
            'id' => (int) $w['id'], 'name' => $w['name'], 'role' => $w['role'],
            'home_postcode' => $w['home_postcode'], 'travel_radius_mi' => (int) $w['travel_radius_mi'],
            'upcoming_shifts' => (int) $w['upcoming'],
            'warnings' => $check['warnings'],
        ];
    }
    json_out(['workers' => $eligible, 'bucket' => $bucket]);
}

// ---------------------------------------------------------------- create a draft

if (($action === '' || $action === 'open') && method() === 'POST') {
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? ''));
    $siteId = rota_opt_int($data['site_id'] ?? null);
    $clientId = rota_opt_int($data['client_id'] ?? null);
    $siteName = trim((string) ($data['site_name'] ?? ''));
    $workerId = $action === 'open' ? 0 : (int) ($data['worker_id'] ?? 0);
    $ack = !empty($data['acknowledge_warnings']);

    if ($title === '') fail('Give the shift a role or title.');
    if (mb_strlen($title) > 190) fail('That title is too long.');
    $startSql = rota_datetime((string) ($data['starts_at'] ?? ''), 'The start');
    $endSql = rota_datetime((string) ($data['ends_at'] ?? ''), 'The end');
    if ($endSql <= $startSql) fail('The shift must end after it starts.');

    // A site is the record; its client and name come with it. site_name alone is
    // still accepted for the older screens and stays as history.
    if ($siteId !== null) {
        $site = rota_site($conn, $siteId);
        $clientId = (int) $site['client_id'];
        $siteName = $site['name'];
    } elseif ($clientId !== null && !row_exists($conn, 'clients', $clientId)) {
        // migration 014's fk_shifts_client turned a stale id into a 500 that
        // named the constraint and the database.
        fail('Client not found.', 404);
    }

    $worker = null;
    if ($workerId > 0) {
        $worker = rota_worker($conn, $workerId);
        // The shift takes the worker's service, as it always has: a cleaner
        // covering a cleaning shift keeps it cleaning.
        $division = $worker['division'];
    }
    if (!in_array($division, ROTA_SERVICES, true)) fail('Choose a service.');
    require_service_scope($user, $division);

    $check = null;
    if ($worker) {
        $check = rg_assignment_check($conn, $workerId, [
            'id' => 0, 'division' => $division, 'site_id' => $siteId, 'starts_at' => $startSql, 'ends_at' => $endSql,
        ]);
        rota_require_check($conn, $uid, $check, $worker['name'], $ack);
    }

    $status = $worker ? 'scheduled' : 'open';
    $workerVal = $worker ? $workerId : null;
    $siteVal = $siteName !== '' ? $siteName : null;
    $stmt = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, site_id, division, title, site_name, starts_at, ends_at, status, is_draft)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->bind_param('iiissssss', $workerVal, $clientId, $siteId, $division, $title, $siteVal, $startSql, $endSql, $status);
    $stmt->execute();
    $id = (int) $conn->insert_id;

    rg_shift_audit($conn, $uid, 'shift.create', $id,
        "$title, " . rg_shift_when($startSql) . ($worker ? ", for {$worker['name']}" : ', open') . ' (draft)');
    if ($worker && $check) rota_audit_acknowledged($conn, $uid, $id, $worker['name'], $check['warnings']);

    json_out(['id' => $id, 'shift' => rg_shift_row($conn, $id)], 201);
}

// ---------------------------------------------------------------- edit

if ($action === 'update' && method() === 'POST') {
    $shift = rota_load_live($conn, $user, (int) ($_GET['id'] ?? 0));
    $id = (int) $shift['id'];
    $data = body();
    $ack = !empty($data['acknowledge_warnings']);

    $after = $shift;
    if (array_key_exists('title', $data)) {
        $after['title'] = trim((string) $data['title']);
        if ($after['title'] === '') fail('Give the shift a role or title.');
        if (mb_strlen($after['title']) > 190) fail('That title is too long.');
    }
    if (array_key_exists('starts_at', $data)) $after['starts_at'] = rota_datetime((string) $data['starts_at'], 'The start');
    if (array_key_exists('ends_at', $data)) $after['ends_at'] = rota_datetime((string) $data['ends_at'], 'The end');
    if ($after['ends_at'] <= $after['starts_at']) fail('The shift must end after it starts.');
    if (array_key_exists('site_id', $data)) {
        $newSite = rota_opt_int($data['site_id']);
        if ($newSite === null) {
            $after['site_id'] = null;
        } else {
            $site = rota_site($conn, $newSite);
            $after['site_id'] = (int) $site['id'];
            $after['client_id'] = (int) $site['client_id'];
            $after['site_name'] = $site['name'];
        }
    }
    if (array_key_exists('division', $data) && $data['division'] !== $shift['division']) {
        // An assigned shift's service is its worker's. Change the worker instead.
        if ($shift['worker_id'] !== null) fail('Take the worker off the shift before changing its service.', 409);
        if (!in_array($data['division'], ROTA_SERVICES, true)) fail('Choose a service.');
        $after['division'] = $data['division'];
        require_service_scope($user, $after['division']);
    }

    $fields = ['title', 'starts_at', 'ends_at', 'site_id', 'client_id', 'site_name', 'division'];
    [$b, $a] = audit_diff($shift, $after, $fields);
    if (!$a) json_out(['ok' => true, 'changed' => false, 'shift' => $shift]);

    // Moving a booked shift is booking the worker again: same check, same rules.
    $check = null;
    if ($shift['worker_id'] !== null) {
        $check = rg_assignment_check($conn, (int) $shift['worker_id'], $after);
        rota_require_check($conn, $uid, $check, (string) $shift['worker'], $ack, $id);
    }

    $siteVal = $after['site_name'] !== null && $after['site_name'] !== '' ? $after['site_name'] : null;
    $clientVal = $after['client_id'] !== null ? (int) $after['client_id'] : null;
    $siteIdVal = $after['site_id'] !== null ? (int) $after['site_id'] : null;
    $stmt = $conn->prepare(
        'UPDATE shifts SET title = ?, starts_at = ?, ends_at = ?, site_id = ?, client_id = ?, site_name = ?, division = ?
          WHERE id = ?'
    );
    $stmt->bind_param('sssiissi', $after['title'], $after['starts_at'], $after['ends_at'], $siteIdVal, $clientVal,
        $siteVal, $after['division'], $id);
    $stmt->execute();

    $lines = rg_shift_change_lines($shift, $after);
    rg_shift_audit($conn, $uid, 'shift.edit', $id, $lines ? implode(' ', $lines) : 'Details changed.', $b, $a);
    if ($check && $shift['worker_id'] !== null) rota_audit_acknowledged($conn, $uid, $id, (string) $shift['worker'], $check['warnings']);

    // Every change after publishing tells the affected worker what changed.
    $notified = false;
    if ((int) $shift['is_draft'] === 0 && $shift['worker_id'] !== null && $lines) {
        rg_notify_worker($conn, (int) $shift['worker_id'], 'Your shift has changed',
            $shift['title'] . ' on ' . rg_shift_when($shift['starts_at']) . " has changed.\n" . implode("\n", $lines), $id, $uid);
        $notified = true;
    }
    // Live offers describe the old shift. Cancel them rather than let a worker
    // accept something that no longer exists in that form.
    if ((int) $shift['is_draft'] === 0 && $shift['worker_id'] === null) rota_expire_offers($conn, $id);

    json_out(['ok' => true, 'changed' => true, 'notified' => $notified, 'shift' => rg_shift_row($conn, $id)]);
}

// ---------------------------------------------------------------- assign, unassign, cancel

if (in_array($action, ['assign', 'unassign', 'cancel'], true) && method() === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    $shift = rota_load($conn, $user, $id);
    $published = (int) $shift['is_draft'] === 0;
    $data = body();

    if ($action === 'cancel') {
        if ($shift['status'] === 'cancelled') json_out(['ok' => true, 'status' => 'cancelled']);
        if ($shift['status'] === 'completed') fail('That shift is already complete.', 409);
        $reason = trim((string) ($data['reason'] ?? ''));
        // The reason is what the worker is told. A cancellation nobody can
        // explain is a phone call to the office.
        if ($reason === '') fail('Give a reason for cancelling the shift.');
        if (mb_strlen($reason) > 255) fail('The reason must be 255 characters or fewer.');
        $stmt = $conn->prepare(
            'UPDATE shifts SET status = "cancelled", cancel_reason = ?, cancelled_at = NOW(), cancelled_by = ? WHERE id = ?'
        );
        $stmt->bind_param('sii', $reason, $uid, $id);
        $stmt->execute();
        rota_expire_offers($conn, $id);
        rg_shift_audit($conn, $uid, 'shift.cancel', $id, $shift['title'] . ': ' . $reason, null, null, $reason);
        $notified = false;
        if ($published && $shift['worker_id'] !== null) {
            rg_notify_worker($conn, (int) $shift['worker_id'], 'Shift cancelled',
                rg_shift_summary($shift) . " has been cancelled.\nReason: $reason", $id, $uid);
            $notified = true;
        }
        json_out(['ok' => true, 'status' => 'cancelled', 'notified' => $notified]);
    }

    if ($shift['status'] === 'completed') fail('That shift is already complete.', 409);
    if ($shift['status'] === 'cancelled') fail('That shift is cancelled. Add a new one to cover the slot.', 409);

    if ($action === 'unassign') {
        if ($shift['worker_id'] === null) fail('That shift has nobody on it.', 409);
        // Back to open rather than cancelled: the work still needs covering.
        $stmt = $conn->prepare('UPDATE shifts SET worker_id = NULL, status = "open" WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        rg_shift_audit($conn, $uid, 'shift.unassign', $id, "{$shift['worker']} taken off");
        if ($published) {
            rg_notify_worker($conn, (int) $shift['worker_id'], 'Shift removed',
                'You are no longer booked for ' . rg_shift_summary($shift) . '.', $id, $uid);
        }
        json_out(['ok' => true, 'status' => 'open']);
    }

    // assign: fill an open shift, or move a booked one to somebody else.
    $w = rota_worker($conn, (int) ($data['worker_id'] ?? 0));
    $workerId = (int) $w['id'];
    require_service_scope($user, $w['division']);
    if ($shift['worker_id'] !== null && (int) $shift['worker_id'] === $workerId) {
        json_out(['ok' => true, 'status' => 'scheduled', 'worker' => $w['name'], 'division' => $shift['division']]);
    }

    // The shift takes the worker's service: a cleaner covering a cleaning shift
    // keeps it cleaning, and an open shift raised under the wrong one is
    // corrected by the assignment rather than left to disagree with its worker.
    $check = rg_assignment_check($conn, $workerId, ['division' => $w['division']] + $shift);
    rota_require_check($conn, $uid, $check, $w['name'], !empty($data['acknowledge_warnings']), $id);

    $division = $w['division'];
    $stmt = $conn->prepare('UPDATE shifts SET worker_id = ?, division = ?, status = "scheduled" WHERE id = ?');
    $stmt->bind_param('isi', $workerId, $division, $id);
    $stmt->execute();
    rota_expire_offers($conn, $id);

    $was = $shift['worker_id'] !== null ? " (was {$shift['worker']})" : '';
    rg_shift_audit($conn, $uid, 'shift.assign', $id, $w['name'] . $was,
        ['worker_id' => $shift['worker_id']], ['worker_id' => $workerId]);
    rota_audit_acknowledged($conn, $uid, $id, $w['name'], $check['warnings']);

    if ($published) {
        rg_notify_worker($conn, $workerId, 'New shift', 'You are booked for ' . rg_shift_summary($shift) . '.', $id, $uid);
        if ($shift['worker_id'] !== null) {
            rg_notify_worker($conn, (int) $shift['worker_id'], 'Shift removed',
                'You are no longer booked for ' . rg_shift_summary($shift) . '.', $id, $uid);
        }
    }
    json_out(['ok' => true, 'status' => 'scheduled', 'worker' => $w['name'], 'division' => $division,
        'warnings_acknowledged' => $check['warnings']]);
}

// ---------------------------------------------------------------- publish

if ($action === 'publish' && method() === 'POST') {
    $data = body();
    $where = ['s.is_draft = 1', 's.deleted_at IS NULL', 's.status IN ("open","scheduled")'];
    $types = '';
    $args = [];
    if (isset($data['ids']) && is_array($data['ids']) && $data['ids']) {
        $ids = array_values(array_filter(array_map('intval', $data['ids']), static fn(int $i): bool => $i > 0));
        if (!$ids) fail('Choose the shifts to publish.');
        $where[] = 's.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $types .= str_repeat('i', count($ids));
        array_push($args, ...$ids);
    } else {
        $from = trim((string) ($data['from'] ?? ''));
        $to = trim((string) ($data['to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            fail('Give the week to publish as from and to dates (YYYY-MM-DD).');
        }
        $where[] = 's.starts_at >= ? AND s.starts_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $types .= 'ss';
        array_push($args, $from, $to);
    }
    $division = trim((string) ($data['division'] ?? ''));
    if ($division !== '') {
        if (!in_array($division, ROTA_SERVICES, true)) fail('Unknown service.');
        require_service_scope($user, $division);
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $division;
    }
    if (($scope = service_scope($user)) !== null) {
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $scope;
    }
    $siteFilter = rota_opt_int($data['site_id'] ?? null);
    if ($siteFilter !== null) {
        $where[] = 's.site_id = ?';
        $types .= 'i';
        $args[] = $siteFilter;
    }

    $stmt = $conn->prepare('SELECT s.id FROM shifts s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.starts_at');
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));

    $conn->begin_transaction();
    $publish = $conn->prepare(
        'UPDATE shifts SET is_draft = 0, published_at = NOW(), published_by = ? WHERE id = ? AND is_draft = 1'
    );
    $published = [];
    foreach ($ids as $sid) {
        $publish->bind_param('ii', $uid, $sid);
        $publish->execute();
        if ($publish->affected_rows === 1) $published[] = $sid;
    }
    $conn->commit();

    // One notice per worker, listing their new shifts.
    $byWorker = [];
    foreach ($published as $sid) {
        $row = rg_shift_row($conn, $sid);
        rg_shift_audit($conn, $uid, 'shift.publish', $sid, 'Published');
        if ($row && $row['worker_id'] !== null) $byWorker[(int) $row['worker_id']][] = $row;
    }
    foreach ($byWorker as $workerId => $rows) {
        $lines = array_map(static fn(array $r): string => '- ' . rg_shift_summary($r), $rows);
        $n = count($rows);
        rg_notify_worker($conn, $workerId, $n === 1 ? 'New shift' : "$n new shifts",
            "You are booked for:\n" . implode("\n", $lines), $n === 1 ? (int) $rows[0]['id'] : null, $uid);
    }
    audit($conn, $uid, 'rota.publish', count($published) . ' shifts, ' . count($byWorker) . ' workers told');
    json_out(['published' => count($published), 'workers_notified' => count($byWorker)]);
}

// ---------------------------------------------------------------- copy last week

if ($action === 'copy_week' && method() === 'POST') {
    $data = body();
    $weekStart = trim((string) ($data['week_start'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) fail('Give the week to fill as week_start (YYYY-MM-DD).');
    $weekStart = rg_week_start($weekStart);
    $sourceFrom = rg_add_days("$weekStart 00:00:00", -7);
    $sourceTo = "$weekStart 00:00:00";

    $where = ['s.deleted_at IS NULL', 's.status <> "cancelled"', 's.starts_at >= ?', 's.starts_at < ?'];
    $types = 'ss';
    $args = [$sourceFrom, $sourceTo];
    $division = trim((string) ($data['division'] ?? ''));
    if ($division !== '') {
        if (!in_array($division, ROTA_SERVICES, true)) fail('Unknown service.');
        require_service_scope($user, $division);
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $division;
    }
    if (($scope = service_scope($user)) !== null) {
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $scope;
    }
    $siteFilter = rota_opt_int($data['site_id'] ?? null);
    if ($siteFilter !== null) {
        $where[] = 's.site_id = ?';
        $types .= 'i';
        $args[] = $siteFilter;
    }
    $stmt = $conn->prepare(
        'SELECT s.id, s.worker_id, w.name AS worker, s.client_id, s.site_id, s.division, s.title, s.site_name,
                s.starts_at, s.ends_at, s.template_id
           FROM shifts s LEFT JOIN workers w ON w.id = s.worker_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY s.starts_at'
    );
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $source = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // The same shift at the same place and time is a duplicate, whoever is on it.
    $dupe = $conn->prepare(
        'SELECT id FROM shifts
          WHERE deleted_at IS NULL AND status <> "cancelled" AND division = ? AND title = ?
            AND starts_at = ? AND ends_at = ? AND COALESCE(site_id, 0) = ? AND COALESCE(site_name, "") = ?
          LIMIT 1'
    );
    $insert = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, site_id, template_id, division, title, site_name, starts_at, ends_at, status, is_draft)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $created = 0;
    $skipped = 0;
    $unassigned = [];
    foreach ($source as $s) {
        $starts = rg_add_days($s['starts_at'], 7);
        $ends = rg_add_days($s['ends_at'], 7);
        $siteKey = (int) ($s['site_id'] ?? 0);
        $siteNameKey = (string) ($s['site_name'] ?? '');
        $dupe->bind_param('ssssis', $s['division'], $s['title'], $starts, $ends, $siteKey, $siteNameKey);
        $dupe->execute();
        if ($dupe->get_result()->fetch_row()) {
            $skipped++;
            continue;
        }

        // The worker comes too, but only if they could be booked without anybody
        // having to acknowledge anything. Otherwise the copy is open and the
        // office is told why: a copy must not quietly book somebody on leave.
        $workerId = $s['worker_id'] !== null ? (int) $s['worker_id'] : null;
        if ($workerId !== null) {
            $check = rg_assignment_check($conn, $workerId, [
                'id' => 0, 'division' => $s['division'], 'site_id' => $s['site_id'], 'starts_at' => $starts, 'ends_at' => $ends,
            ], false);
            if (!$check['ok'] || $check['warnings']) {
                $why = $check['blocking'] ? $check['blocking'][0]['reason'] : $check['warnings'][0]['reason'];
                $unassigned[] = ['title' => $s['title'], 'starts_at' => $starts, 'worker' => $s['worker'], 'reason' => $why];
                $workerId = null;
            }
        }
        $status = $workerId !== null ? 'scheduled' : 'open';
        $clientId = $s['client_id'] !== null ? (int) $s['client_id'] : null;
        $siteId = $s['site_id'] !== null ? (int) $s['site_id'] : null;
        $templateId = $s['template_id'] !== null ? (int) $s['template_id'] : null;
        $siteName = $s['site_name'];
        $insert->bind_param('iiiissssss', $workerId, $clientId, $siteId, $templateId, $s['division'], $s['title'],
            $siteName, $starts, $ends, $status);
        $insert->execute();
        rg_shift_audit($conn, $uid, 'shift.create', (int) $conn->insert_id,
            "Copied from shift #{$s['id']} (draft)" . ($s['worker_id'] !== null && $workerId === null ? ', without its worker' : ''));
        $created++;
    }
    audit($conn, $uid, 'rota.copy_week', "week of $weekStart: $created created, $skipped already there");
    json_out(['created' => $created, 'skipped' => $skipped, 'unassigned' => $unassigned]);
}

// ---------------------------------------------------------------- broadcast and offers

if (($action === 'broadcast' || $action === 'offer') && method() === 'POST') {
    $shift = rota_load_live($conn, $user, (int) ($_GET['id'] ?? 0));
    $id = (int) $shift['id'];
    // Workers never see drafts, and an offer is a worker seeing a shift.
    if ((int) $shift['is_draft'] === 1) fail('Publish the shift before offering it.', 409);
    if ($shift['status'] !== 'open') fail('Only open shifts can be offered.', 409);

    if ($action === 'broadcast') {
        // Every broadcast can send a chargeable message to every eligible worker
        // in the service. Nothing stopped the same shift being sent round again
        // and again.
        rate_limit($conn, 'broadcast', 12, 600);
        $availCol = 'avail_' . shift_bucket(strtotime($shift['starts_at']));
        $stmt = $conn->prepare(
            'SELECT id, name, phone, ' . $availCol . ' AS available FROM workers
              WHERE deleted_at IS NULL AND status != "inactive" AND division = ?'
        );
        $stmt->bind_param('s', $shift['division']);
        $stmt->execute();
        $count = 0;
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $w) {
            if (!(int) $w['available']) continue;
            // A broadcast cannot acknowledge a warning on anybody's behalf, so a
            // worker with one is left for the office to offer by name.
            $check = rg_assignment_check($conn, (int) $w['id'], $shift, false);
            if (!$check['ok'] || $check['warnings']) continue;
            rota_send_offer($conn, $shift, $w, $uid);
            $count++;
        }
        rg_shift_audit($conn, $uid, 'shift.broadcast', $id, "Offered to $count eligible workers");
        json_out(['offered' => $count]);
    }

    // Offer to named workers.
    $data = body();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['worker_ids'] ?? [])), static fn(int $i): bool => $i > 0)));
    if (!$ids) fail('Choose who to offer the shift to.');
    if (count($ids) > 50) fail('Offer to 50 workers or fewer at a time.');
    rate_limit($conn, 'broadcast', 12, 600);
    $ack = !empty($data['acknowledge_warnings']);

    $ready = [];
    $refused = [];
    $warned = [];
    foreach ($ids as $workerId) {
        $w = rota_worker($conn, $workerId);
        require_service_scope($user, $w['division']);
        if ($w['division'] !== $shift['division']) {
            $refused[] = ['worker' => $w['name'], 'reason' => 'Works in a different service.'];
            continue;
        }
        $check = rg_assignment_check($conn, $workerId, $shift, false);
        if (!$check['ok']) {
            $refused[] = ['worker' => $w['name'], 'reason' => $check['reason']];
            continue;
        }
        if ($check['warnings']) $warned[] = ['worker' => $w['name'], 'warnings' => $check['warnings']];
        $ready[] = $w;
    }
    if ($warned && !$ack) {
        $text = implode(' ', array_map(static fn(array $x): string =>
            $x['worker'] . ': ' . implode(' ', array_column($x['warnings'], 'reason')), $warned));
        json_out(['error' => "Check before offering: $text", 'needs_acknowledgement' => true,
            'warned' => $warned, 'refused' => $refused], 409);
    }
    foreach ($ready as $w) rota_send_offer($conn, $shift, $w, $uid);
    foreach ($warned as $x) {
        rg_shift_audit($conn, $uid, 'shift.warnings_acknowledged', $id,
            $x['worker'] . ': ' . implode(' ', array_column($x['warnings'], 'reason')), null, null, 'offer');
    }
    rg_shift_audit($conn, $uid, 'shift.offer', $id,
        'Offered to ' . (implode(', ', array_column($ready, 'name')) ?: 'nobody'));
    json_out(['offered' => count($ready), 'refused' => $refused]);
}

// ---------------------------------------------------------------- the week

if (method() === 'GET') {
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
    $to = $_GET['to'] ?? date('Y-m-d', strtotime($from . ' +6 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        fail('from and to must be dates (YYYY-MM-DD).');
    }
    $where = ['s.deleted_at IS NULL', 's.starts_at >= ?', 's.starts_at < DATE_ADD(?, INTERVAL 1 DAY)'];
    $types = 'ss';
    $args = [$from, $to];
    $division = trim((string) ($_GET['division'] ?? ''));
    if ($division !== '') {
        if (!in_array($division, ROTA_SERVICES, true)) fail('Unknown service.');
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $division;
    }
    // A manager confined to one service sees that service's rota.
    if (($scope = service_scope($user)) !== null) {
        $where[] = 's.division = ?';
        $types .= 's';
        $args[] = $scope;
    }
    $siteFilter = rota_opt_int($_GET['site_id'] ?? null);
    if ($siteFilter !== null) {
        $where[] = 's.site_id = ?';
        $types .= 'i';
        $args[] = $siteFilter;
    }

    $stmt = $conn->prepare(
        'SELECT s.id, s.worker_id, w.name AS worker, s.division, s.title, s.site_id,
                COALESCE(t.name, s.site_name) AS site_name, s.client_id, c.name AS client,
                s.starts_at, s.ends_at, s.status, s.is_draft, s.cancel_reason,
                (SELECT COUNT(*) FROM shift_offers o WHERE o.shift_id = s.id AND o.status = "sent"
                   AND (o.expires_at IS NULL OR o.expires_at > NOW())) AS live_offers
         FROM shifts s
         LEFT JOIN workers w ON w.id = s.worker_id
         LEFT JOIN clients c ON c.id = s.client_id
         LEFT JOIN sites t ON t.id = s.site_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY s.starts_at, s.id'
    );
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();

    $now = date('Y-m-d H:i:s');
    $shifts = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['site_id'] = $row['site_id'] !== null ? (int) $row['site_id'] : null;
        $row['is_draft'] = (bool) $row['is_draft'];
        $row['live_offers'] = (int) $row['live_offers'];
        $row['state'] = rg_shift_state($row, $row['live_offers']);

        // Conflicts shown on the shift, with the reason, rather than in a
        // separate report: who is on it may no longer be allowed to be -- a
        // credential lapsed, leave was approved afterwards, a second booking
        // squeezed the rest. Only for shifts still to be worked.
        $row['conflicts'] = [];
        if ($row['worker_id'] !== null && $row['state'] !== 'cancelled' && $row['state'] !== 'completed' && $row['ends_at'] > $now) {
            $check = rg_assignment_check($conn, $row['worker_id'], $row, false);
            foreach ($check['blocking'] as $b) $row['conflicts'][] = ['severity' => 'block'] + $b;
            foreach ($check['warnings'] as $wn) $row['conflicts'][] = ['severity' => 'warning'] + $wn;
        }
        $shifts[] = $row;
    }
    json_out(['shifts' => $shifts, 'rest_gap_min_hours' => setting_int($conn, 'rest_gap_min_hours')]);
}

fail('Method not allowed.', 405);
