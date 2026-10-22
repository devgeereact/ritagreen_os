<?php
// §18 Worker self-service: the staff portal at /m (section S4).
//
// GET  /me.php                               -> { worker, shifts, open_shifts, offers, blockers, training_due, unread }
// POST /me.php?action=accept&shift_id=N      -> pick up an open shift (atomic, through rg_claim_check)
// POST /me.php?action=offer_accept&id=N      -> accept an offer made to me
// POST /me.php?action=offer_decline&id=N     -> decline it
// POST /me.php?action=report {note, shift_id?} -> report a problem to the office
// GET  /me.php?action=records                -> { checks, training }
// POST /me.php?action=upload (multipart: file, requirement_key) -> evidence for a check, awaiting review
// GET  /me.php?action=download&id=N          -> a file I uploaded, or a certificate on my training
// GET  /me.php?action=payslip&from=&to=      -> own gross pay for a period
// GET  /me.php?action=timesheets             -> own timesheets, newest first
// POST /me.php?action=submit_timesheet&id=N {break_minutes?} -> submit a draft, or answer a query
// GET  /me.php?action=availability           -> own unavailable periods still to come
// POST /me.php?action=availability {starts_at, ends_at, note?} -> mark a period unavailable
// POST /me.php?action=availability_remove&id=N
// GET  /me.php?action=profile                -> my contact details and emergency contacts
// POST /me.php?action=profile {phone, address_line1, address_line2, city, postcode}
// POST /me.php?action=emergency {emergency_contact_*} -> rg_save_emergency_contacts()
//
// Leave goes to leave.php, whose worker branches already pin the person to the
// login. Clock-in/out, check-in and panic go through field.php.
//
// Ownership: the worker id comes from the login (users.worker_id) and nowhere
// else. No branch takes a worker or person id from the request; every id the
// request does carry (a shift, an offer, a timesheet, a file) is looked up
// together with this worker's id, so somebody else's reads as not found.
// Workers never see draft shifts: every shift read here filters is_draft = 0.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';
require __DIR__ . '/rota-lib.php';
require __DIR__ . '/people-lib.php';
require __DIR__ . '/portal-lib.php';

$user = require_auth($conn);
$workerId = $user['worker_id'] !== null ? (int) $user['worker_id'] : 0;
if ($workerId <= 0) fail('This account is not linked to a worker.', 403);
$action = $_GET['action'] ?? '';
$uid = (int) $user['id'];

if ($action === 'payslip' && method() === 'GET') {
    require __DIR__ . '/pay-lib.php';
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    if (!pay_valid_date($from) || !pay_valid_date($to)) fail('A valid from and to date are required.');
    $w = rg_portal_worker($conn, $workerId);
    $stmt = $conn->prepare('SELECT pay_type, pay_rate_pence FROM workers WHERE id = ?');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $pay = $stmt->get_result()->fetch_assoc();
    $out = pay_compute($conn, $from, $to, $workerId);
    $agg = $out['workers'][0] ?? [
        'hours' => 0, 'base_pence' => 0, 'premium_pence' => 0, 'gross_pence' => 0,
        'holiday_hours' => 0, 'holiday_pay_pence' => 0,
    ];
    // The same shape as payroll.php?action=payslip, so the portal can print the
    // same GROSS PAY statement the office prints.
    json_out([
        'worker' => [
            'id' => $workerId, 'name' => $w['full_name'] ?? $w['name'], 'division' => $w['division'], 'role' => $w['role'],
            'pay_type' => $pay['pay_type'] ?? 'paye', 'pay_rate_pence' => (int) ($pay['pay_rate_pence'] ?? 0),
        ],
        'period' => ['from' => $from, 'to' => $to],
        'lines' => $out['lines'],
        'totals' => [
            'hours' => $agg['hours'], 'base_pence' => $agg['base_pence'], 'premium_pence' => $agg['premium_pence'],
            'gross_pence' => $agg['gross_pence'], 'holiday_hours' => $agg['holiday_hours'],
            'holiday_pay_pence' => $agg['holiday_pay_pence'],
        ],
    ]);
}

if ($action === 'accept' && method() === 'POST') {
    $shiftId = (int) ($_GET['shift_id'] ?? 0);
    if ($shiftId <= 0) fail('A shift is required.');
    // Must be a published open shift in the worker's division. A draft is not
    // there to be claimed, so it reads as not open.
    $stmt = $conn->prepare(
        'SELECT s.division FROM shifts s
          WHERE s.id = ? AND s.status = "open" AND s.is_draft = 0 AND s.deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    $shift = $stmt->get_result()->fetch_assoc();
    if (!$shift) fail('That shift is no longer open.', 409);
    $stmt = $conn->prepare('SELECT division FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w || $w['division'] !== $shift['division']) fail('This shift is not for your division.', 403);

    // The deployment gate, before the claim.
    //
    // Matching divisions was the whole test. A worker with a lapsed DBS could
    // claim a care shift from the app, and one already booked elsewhere could
    // claim a shift overlapping it -- verified against a local database: a
    // worker scheduled 08:00-16:00 accepted an overlapping 09:00-17:00 shift
    // here and ended up on both, while the office screen refused the same
    // assignment with a 409.
    $claim = rg_claim_check($conn, $workerId, $shiftId);
    if (!$claim['ok']) {
        audit($conn, $uid, 'shift.blocked', "app accept, worker #$workerId: " . $claim['reason']);
        fail($claim['reason'], 409);
    }

    // Atomic claim.
    $stmt = $conn->prepare(
        'UPDATE shifts SET worker_id = ?, status = "scheduled"
          WHERE id = ? AND status = "open" AND is_draft = 0 AND deleted_at IS NULL'
    );
    $stmt->bind_param('ii', $workerId, $shiftId);
    $stmt->execute();
    if ($conn->affected_rows !== 1) fail('Sorry, that shift was just taken.', 409);
    $stmt = $conn->prepare('UPDATE shift_offers SET status = "expired" WHERE shift_id = ? AND status = "sent"');
    $stmt->bind_param('i', $shiftId);
    $stmt->execute();
    // A worker choosing a shift has made the choice a warning exists to prompt,
    // so it does not stop them. The office is still told, because a short rest
    // gap is the company's responsibility as well as theirs.
    $warn = $claim['warnings'] ? ' Note: ' . implode(' ', array_column($claim['warnings'], 'reason')) : '';
    notify($conn, null, 'Open shift filled', $user['name'] . ' accepted "' . $claim['shift']['title'] . '" in the app.' . $warn,
        'shift', '/app/scheduling');
    rg_shift_audit($conn, $uid, 'me.accept_shift', $shiftId, $user['name'] . ' claimed it in the app.' . $warn);
    json_out(['ok' => true, 'warnings' => $claim['warnings']]);
}

// ---------- Offers made to me ----------
if (($action === 'offer_accept' || $action === 'offer_decline') && method() === 'POST') {
    $offerId = (int) ($_GET['id'] ?? 0);
    if ($offerId <= 0) fail('An offer is required.');
    // The offer must be addressed to this worker. Somebody else's offer id reads
    // as not found, never as "not yours", so ids cannot be probed.
    $stmt = $conn->prepare(
        'SELECT o.id AS offer_id, o.status AS offer_status, o.expires_at, o.worker_id, w.name AS worker,
                s.id AS shift_id, s.title
           FROM shift_offers o
           JOIN shifts s ON s.id = o.shift_id AND s.is_draft = 0 AND s.deleted_at IS NULL
           JOIN workers w ON w.id = o.worker_id
          WHERE o.id = ? AND o.worker_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $offerId, $workerId);
    $stmt->execute();
    $offer = $stmt->get_result()->fetch_assoc();
    if (!$offer) fail('Offer not found.', 404);
    $res = rg_offer_respond($conn, $offer, $action === 'offer_accept' ? 'accept' : 'decline', $uid);
    if ($res['code'] >= 400) fail($res['message'], $res['code']);
    if ($res['result'] === 'filled') fail($res['message'], 409);
    json_out(['ok' => true, 'result' => $res['result'], 'message' => $res['message'], 'warnings' => $res['warnings']]);
}

// ---------- Report a problem ----------
// The office's incident log is office-only (incidents.php), and rightly: it holds
// safeguarding records about named people. A worker reports; the office decides
// whether it becomes an incident. On a shift it is also a note on that shift's
// trail, where the duty manager is already looking.
if ($action === 'report' && method() === 'POST') {
    $data = body();
    $note = trim((string) ($data['note'] ?? ''));
    if ($note === '') fail('Say what the problem is.');
    // 400 so the whole note fits in the office notice beside the name and shift.
    if (mb_strlen($note) > 400) fail('Please keep it under 400 characters. The office will call you if they need more.');
    rate_limit($conn, 'me.report', 10, 600, (string) $uid);
    $shiftId = (int) ($data['shift_id'] ?? 0);
    $where = '';
    if ($shiftId > 0) {
        $stmt = $conn->prepare(
            'SELECT title FROM shifts WHERE id = ? AND worker_id = ? AND is_draft = 0 AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->bind_param('ii', $shiftId, $workerId);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        if (!$s) fail('That is not one of your shifts.', 404);
        $stmt = $conn->prepare('INSERT INTO shift_events (shift_id, worker_id, kind, note) VALUES (?, ?, "note", ?)');
        $stmt->bind_param('iis', $shiftId, $workerId, $note);
        $stmt->execute();
        $where = ' on "' . $s['title'] . '"';
    }
    notify($conn, null, 'Problem reported', $user['name'] . $where . ': ' . $note, 'incident', '/app/field');
    audit($conn, $uid, 'me.report_problem', $shiftId > 0 ? "shift #$shiftId" : 'no shift', $shiftId > 0 ? 'shift' : null,
        $shiftId > 0 ? $shiftId : null);
    json_out(['ok' => true], 201);
}

// ---------- My documents, checks and training ----------
if ($action === 'records' && method() === 'GET') {
    $w = rg_portal_worker($conn, $workerId);
    json_out([
        'checks' => rg_portal_checks($conn, $w),
        'training' => rg_portal_training($conn, $w['person_id'], $workerId),
    ]);
}

// ---------- Send evidence for a check ----------
// The file is filed against the person (never against the worker row, and never
// as a contract: either would make it count in rg_load_evidence on arrival), and
// the check moves to "received". A reviewer verifies or rejects it in the office
// queue. Nothing a worker uploads can make anything valid by itself.
if ($action === 'upload' && method() === 'POST') {
    require __DIR__ . '/files-lib.php';
    $w = rg_portal_worker($conn, $workerId);
    $personId = $w['person_id'];
    $key = trim((string) ($_POST['requirement_key'] ?? ''));
    $category = rg_portal_upload_category($key);
    if ($category === null) fail('That check is handled by the office. Please contact them.', 422);
    // Only a requirement that applies to this worker's own service.
    $keys = array_column(rg_load_rules($conn, (string) $w['division'], $w['role']), 'key');
    if (!in_array($key, $keys, true)) fail('That check does not apply to you.', 422);
    if (!isset($_FILES['file'])) fail('Choose a file to upload.');
    rate_limit($conn, 'me.upload', 20, 3600, (string) $uid);

    $open = rg_portal_open_checks($conn, $personId)[$key] ?? null;
    if ($open !== null && in_array($open['status'], ['received', 'under_review'], true)) {
        fail('The office is already checking a copy of this. They will tell you if they need another.', 409);
    }

    // Photos and PDFs: what a phone camera and a scanning app produce.
    $up = rg_store_upload($_FILES['file'], [
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    ]);
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'INSERT INTO files (stored_name, original_name, mime, size_bytes, category, person_id, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssisii', $up['stored'], $up['original'], $up['mime'], $up['size'], $category, $personId, $uid);
    $stmt->execute();
    $fileId = (int) $conn->insert_id;

    $label = null;
    foreach (rg_load_rules($conn, (string) $w['division'], $w['role']) as $r) {
        if ($r['key'] === $key) $label = $r['label'] ?? $key;
    }
    if ($open !== null && $open['status'] === 'requested') {
        // The office asked for it; this is the answer.
        $vid = $open['id'];
        $stmt = $conn->prepare(
            'UPDATE verifications SET status = "received", received_at = NOW(), evidence_file_id = ? WHERE id = ? AND status = "requested"'
        );
        $stmt->bind_param('ii', $fileId, $vid);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO verifications (person_id, requirement_key, subject_type, subject_label, status, received_at, evidence_file_id)
             VALUES (?, ?, "document", ?, "received", NOW(), ?)'
        );
        $stmt->bind_param('issi', $personId, $key, $label, $fileId);
        $stmt->execute();
        $vid = (int) $conn->insert_id;
    }
    $conn->commit();

    audit($conn, $uid, 'file.upload', $up['original'], 'file', $fileId, null,
        ['person_id' => $personId, 'category' => $category, 'via' => 'portal']);
    audit($conn, $uid, 'verification.received', "#$vid $key (sent by the worker)", 'verification', $vid,
        $open !== null ? ['status' => $open['status']] : null, ['status' => 'received']);
    notify($conn, null, 'Document to check', $user['name'] . ' sent a new copy of ' . ($label ?? $key) . '.', 'compliance', '/app/compliance');
    json_out(['ok' => true, 'file_id' => $fileId, 'verification_id' => $vid, 'status' => 'received'], 201);
}

if ($action === 'download' && method() === 'GET') {
    require __DIR__ . '/files-lib.php';
    $w = rg_portal_worker($conn, $workerId);
    $f = rg_portal_file_for($conn, (int) ($_GET['id'] ?? 0), $w['person_id'], $uid);
    if (!$f) fail('File not found.', 404);
    rg_stream_file($f);
}

// ---------- Own timesheets ----------
if ($action === 'timesheets' && method() === 'GET') {
    $stmt = $conn->prepare(
        'SELECT t.id, t.shift_id, s.title AS shift_title, COALESCE(st.name, s.site_name) AS site_name,
                t.work_date, t.start_time, t.end_time, t.minutes,
                t.break_minutes, t.status, t.source, t.submitted_at, t.decided_at, t.decision_note
           FROM timesheets t LEFT JOIN shifts s ON s.id = t.shift_id LEFT JOIN sites st ON st.id = s.site_id
          WHERE t.worker_id = ? AND t.deleted_at IS NULL
          ORDER BY t.work_date DESC, t.start_time DESC LIMIT 60'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['shift_id'] = $r['shift_id'] !== null ? (int) $r['shift_id'] : null;
        $r['minutes'] = (int) $r['minutes'];
        $r['break_minutes'] = (int) $r['break_minutes'];
    }
    unset($r);
    json_out(['timesheets' => $rows]);
}

// ---------- Submit a timesheet (the worker confirms the break) ----------
if ($action === 'submit_timesheet' && method() === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A timesheet is required.');
    // Proving ownership: the row must be this worker's, or it does not exist as
    // far as this login is concerned.
    $stmt = $conn->prepare(
        'SELECT id, status, minutes, break_minutes FROM timesheets WHERE id = ? AND worker_id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ii', $id, $workerId);
    $stmt->execute();
    $ts = $stmt->get_result()->fetch_assoc();
    if (!$ts) fail('Timesheet not found.', 404);
    if (!rg_timesheet_can('submit', $ts['status'])) fail('That timesheet has already been submitted.', 409);

    $data = body();
    $break = array_key_exists('break_minutes', $data) ? (int) $data['break_minutes'] : (int) $ts['break_minutes'];
    if ($break < 0) fail('A break cannot be negative.');
    if ($break >= (int) $ts['minutes']) fail('The break cannot be as long as the shift.');

    $stmt = $conn->prepare('UPDATE timesheets SET status = "pending", break_minutes = ?, submitted_at = NOW() WHERE id = ?');
    $stmt->bind_param('ii', $break, $id);
    $stmt->execute();
    audit($conn, $uid, 'timesheet.submit', "#$id", 'timesheet', $id,
        ['status' => $ts['status'], 'break_minutes' => (int) $ts['break_minutes']],
        ['status' => 'pending', 'break_minutes' => $break]);
    json_out(['ok' => true, 'status' => 'pending']);
}

// ---------- Unavailability the worker marks ----------
// A warning the office must acknowledge before booking them (rg_assignment_check),
// never a block: the worker may change their mind.
if ($action === 'availability' || $action === 'availability_remove') {
    $personId = rg_portal_worker($conn, $workerId)['person_id'];

    if ($action === 'availability_remove' && method() === 'POST') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $conn->prepare(
            'UPDATE availability_blocks SET deleted_at = NOW() WHERE id = ? AND person_id = ? AND deleted_at IS NULL'
        );
        $stmt->bind_param('ii', $id, $personId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) fail('Not found.', 404);
        audit($conn, $uid, 'availability.remove', "#$id", 'availability', $id);
        json_out(['ok' => true]);
    }

    if ($action === 'availability' && method() === 'POST') {
        $data = body();
        $starts = strtotime(trim((string) ($data['starts_at'] ?? '')));
        $ends = strtotime(trim((string) ($data['ends_at'] ?? '')));
        if (!$starts || !$ends) fail('Give a start and an end.');
        if ($ends <= $starts) fail('The end must be after the start.');
        if ($ends - $starts > 92 * 86400) fail('Mark up to three months at a time. For longer, request leave.');
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
        $startSql = date('Y-m-d H:i:s', $starts);
        $endSql = date('Y-m-d H:i:s', $ends);
        $noteVal = $note !== '' ? $note : null;
        $stmt = $conn->prepare(
            'INSERT INTO availability_blocks (person_id, starts_at, ends_at, note, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssi', $personId, $startSql, $endSql, $noteVal, $uid);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        audit($conn, $uid, 'availability.add', "$startSql to $endSql", 'availability', $id);
        json_out(['ok' => true, 'id' => $id], 201);
    }

    if (method() === 'GET') {
        $stmt = $conn->prepare(
            'SELECT id, starts_at, ends_at, note FROM availability_blocks
              WHERE person_id = ? AND deleted_at IS NULL AND ends_at >= NOW() ORDER BY starts_at'
        );
        $stmt->bind_param('i', $personId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) $r['id'] = (int) $r['id'];
        unset($r);
        json_out(['unavailable' => $rows]);
    }
    fail('Method not allowed.', 405);
}

// ---------- My details ----------
if ($action === 'profile' && method() === 'GET') {
    $w = rg_portal_worker($conn, $workerId);
    $stmt = $conn->prepare(
        'SELECT full_name, preferred_name, email, phone, address_line1, address_line2, city, postcode, '
        . implode(', ', RG_EMERGENCY_FIELDS) . ' FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $w['person_id']);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    if (!$p) fail('Person not found.', 404);
    json_out([
        'person' => $p,
        'worker' => ['role' => $w['role'], 'division' => $w['division'], 'payroll_no' => $w['payroll_no'], 'status' => $w['status']],
        'login_email' => $user['email'],
    ]);
}

// Written to people, which is the record of who somebody is. The denormalised
// workers.phone is brought into step for this person's worker rows only because
// the rota's text messages still read it (scheduling.php); without that a worker
// who changed their number here would stop getting shift texts. It is a copy of
// people.phone, never a second source.
if ($action === 'profile' && method() === 'POST') {
    $w = rg_portal_worker($conn, $workerId);
    $personId = $w['person_id'];
    $check = rg_validate_contact_details(body());
    if (!$check['ok']) fail(implode(' ', $check['errors']), 422);
    $values = $check['values'];

    $stmt = $conn->prepare(
        'SELECT email, match_key, ' . implode(', ', RG_SELF_CONTACT_FIELDS) . ' FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    if (!$before) fail('Person not found.', 404);

    // A person matched by phone number (no usable email) is re-keyed to the new
    // number, or the next application from it would make a second person. If the
    // number already identifies somebody else, that is for the office to sort out.
    $matchKey = $before['match_key'];
    if ($matchKey !== null && str_starts_with((string) $matchKey, 'p:')) {
        $next = rg_person_match_key(['email' => $before['email'], 'phone' => $values['phone']])['key'];
        if ($next !== null && $next !== $matchKey) {
            $stmt = $conn->prepare('SELECT id FROM people WHERE match_key = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $next, $personId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_row()) {
                fail('That number is already on another record. Please ask the office to change it for you.', 409);
            }
            $matchKey = $next;
        }
    }

    $set = implode(', ', array_map(static fn($f) => "$f = ?", RG_SELF_CONTACT_FIELDS));
    $stmt = $conn->prepare("UPDATE people SET $set, match_key = ? WHERE id = ?");
    $params = array_values($values);
    $params[] = $matchKey;
    $params[] = $personId;
    $stmt->bind_param(str_repeat('s', count(RG_SELF_CONTACT_FIELDS) + 1) . 'i', ...$params);
    $stmt->execute();
    $stmt = $conn->prepare('UPDATE workers SET phone = ? WHERE person_id = ? AND deleted_at IS NULL');
    $stmt->bind_param('si', $values['phone'], $personId);
    $stmt->execute();

    // The names of the fields that changed, not their values: an address in the
    // audit log is a second copy to find, correct and delete.
    [, $after] = audit_diff($before, $values, RG_SELF_CONTACT_FIELDS);
    if ($after) {
        audit($conn, $uid, 'person.contact_details', "person #$personId", 'person', $personId,
            null, ['changed' => array_keys($after), 'via' => 'portal']);
    }
    json_out(['ok' => true, 'values' => $values]);
}

if ($action === 'emergency' && method() === 'POST') {
    $personId = rg_portal_worker($conn, $workerId)['person_id'];
    $values = rg_save_emergency_contacts($conn, $personId, body(), $uid, 'portal');
    json_out(['ok' => true, 'values' => $values]);
}

if ($action === '' && method() === 'GET') {
    $w = rg_portal_worker($conn, $workerId);
    $worker = [
        'id' => $workerId,
        'name' => $w['preferred_name'] ?: ($w['full_name'] ?? $w['name']),
        'role' => $w['role'],
        'division' => $w['division'],
        'status' => $w['status'],
    ];

    // My shifts, published, from yesterday on.
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.division, COALESCE(t.name, s.site_name) AS site_name, s.starts_at, s.ends_at,
                s.status, s.cancel_reason, c.name AS client,
                t.address AS site_address, t.postcode AS site_postcode, t.access_notes AS site_notes,
                t.contact_name AS site_contact, t.contact_phone AS site_contact_phone,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_in") AS clocked_in,
                EXISTS(SELECT 1 FROM shift_events e WHERE e.shift_id = s.id AND e.deleted_at IS NULL AND e.kind = "clock_out") AS clocked_out
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN sites t ON t.id = s.site_id
         WHERE s.worker_id = ? AND s.is_draft = 0 AND s.deleted_at IS NULL
           AND s.ends_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         ORDER BY s.starts_at LIMIT 50'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $shifts = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['clocked_in'] = (int) $row['clocked_in'];
        $row['clocked_out'] = (int) $row['clocked_out'];
        // field.php refuses a clock-in before this; the button says when it opens.
        $row['clock_in_opens_at'] = rg_clock_in_opens_at($row['starts_at']);
        $row['can_clock_in'] = $row['status'] === 'scheduled' && !$row['clocked_in'] && rg_clock_in_open($row['starts_at']);
        $shifts[] = $row;
    }

    // Open shifts in my service, each with the answer the claim would get. The
    // check is the assignment check itself, screened without being recorded
    // (CLAUDE.md, "Screening a list is not deciding about a person"); the claim
    // runs it again for real.
    $stmt = $conn->prepare(
        'SELECT s.id, s.title, s.division, s.site_id, COALESCE(t.name, s.site_name) AS site_name, s.starts_at, s.ends_at, c.name AS client
         FROM shifts s LEFT JOIN clients c ON c.id = s.client_id LEFT JOIN sites t ON t.id = s.site_id
         WHERE s.status = "open" AND s.is_draft = 0 AND s.deleted_at IS NULL AND s.division = ? AND s.starts_at >= NOW()
         ORDER BY s.starts_at LIMIT 30'
    );
    $div = $w['division'];
    $stmt->bind_param('s', $div);
    $stmt->execute();
    $open = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $check = rg_assignment_check($conn, $workerId, $row, false);
        $row['eligible'] = $check['ok'];
        $row['reason'] = $check['ok'] ? null : $check['reason'];
        unset($row['site_id']);
        $open[] = $row;
    }

    // What stops me being booked at all, said in words I can act on.
    $gate = rg_deployment_check($conn, $workerId, null, false);
    $openChecks = array_map(static fn(array $o): string => $o['status'], rg_portal_open_checks($conn, $w['person_id']));
    $blockers = rg_portal_blockers($gate, $openChecks);

    // Training and checks running out in the next 30 days, or already out.
    $due = [];
    foreach (rg_portal_training($conn, $w['person_id'], $workerId) as $t) {
        if (in_array($t['rag'], ['expired', 'expiring'], true)) {
            $due[] = ['label' => $t['course'], 'expires_on' => $t['expires_on'], 'days' => $t['days']];
        }
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $unread = (int) $stmt->get_result()->fetch_row()[0];

    json_out([
        'worker' => $worker,
        'shifts' => $shifts,
        'open_shifts' => $open,
        'offers' => rg_portal_offers($conn, $workerId),
        'eligible' => (bool) $gate['eligible'],
        'blockers' => $blockers,
        'training_due' => $due,
        'unread' => $unread,
    ]);
}

fail('Method not allowed.', 405);
