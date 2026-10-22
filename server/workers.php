<?php
// Workers / staff endpoint.
// GET  /workers.php  -> { workers: [...] }
// POST /workers.php {name, email, phone, role, division, right_to_work} -> { worker }
// POST /workers.php?action=availability|pay&id=N
// POST /workers.php?action=approve&id=N {note?}      unapprove&id=N {reason}
// POST /workers.php?action=suspend&id=N {reason}     reinstate&id=N {reason}
require __DIR__ . '/lib.php';
// The employment gate, the onboarding count and the status refresh. Approving a
// worker is a compliance decision as much as an HR one, and the rules for it
// belong in one file rather than being restated here.
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    // Internal staff only. This row set carries pay rates and DBS/SIA data, and
    // before this check any authenticated account could read it — including a
    // client portal login, which is an external party.
    require_staff($user);
    // Pay is its own zone, not a hard-coded role list. The business can widen or
    // narrow who sees it without a deployment.
    $seesPay = can_view_zone($conn, $user, 'payroll');
    // Right-to-work state, DBS status and an SIA licence number are compliance
    // data about a named person, and the zone matrix already says who may see
    // it. An editor holds 'general' only, so the content role no longer reads
    // the workforce's vetting record as a side effect of opening the page.
    $seesCompliance = can_view_zone($conn, $user, 'compliance');
    // A division-scoped account sees only its own division's workforce.
    $scope = service_scope($user);
    $res = $conn->query(
        'SELECT id, person_id, name, email, phone, role, division, status, right_to_work,
                dbs_status, dbs_expiry, sia_number, sia_expiry,
                pay_rate_pence, pay_type, holiday_hours,
                home_postcode, travel_radius_mi, avail_days, avail_nights, avail_weekends, created_at
         FROM workers WHERE deleted_at IS NULL'
        . ($scope !== null ? " AND division = '" . $conn->real_escape_string($scope) . "'" : '')
        . ' ORDER BY name'
    );
    $workers = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        // The person profile is keyed by person, not worker: one human, one page.
        $row['person_id'] = $row['person_id'] !== null ? (int) $row['person_id'] : null;
        $row['right_to_work'] = (bool) $row['right_to_work'];
        $row['pay_rate_pence'] = (int) $row['pay_rate_pence'];
        $row['holiday_hours'] = (float) $row['holiday_hours'];
        $row['travel_radius_mi'] = (int) $row['travel_radius_mi'];
        $row['avail_days'] = (bool) $row['avail_days'];
        $row['avail_nights'] = (bool) $row['avail_nights'];
        $row['avail_weekends'] = (bool) $row['avail_weekends'];
        if (!$seesPay) unset($row['pay_rate_pence'], $row['pay_type'], $row['holiday_hours']);
        if (!$seesCompliance) {
            unset($row['right_to_work'], $row['dbs_status'], $row['dbs_expiry'], $row['sia_number'], $row['sia_expiry']);
            $row['compliance_redacted'] = true;
        }
        $workers[] = $row;
    }
    json_out(['workers' => $workers]);
}

// Set a worker's availability + travel preferences.
// POST /workers.php?action=availability&id=N {avail_days, avail_nights, avail_weekends, travel_radius_mi, home_postcode}
if ($action === 'availability' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    if ($id <= 0) fail('A worker is required.');
    // The list above is scoped and this was not, so a manager confined to one
    // service could change another service's worker by asking for them by id.
    require_service_scope($user, worker_division($conn, $id));
    $days = !empty($data['avail_days']) ? 1 : 0;
    $nights = !empty($data['avail_nights']) ? 1 : 0;
    $weekends = !empty($data['avail_weekends']) ? 1 : 0;
    $radius = max(0, (int) ($data['travel_radius_mi'] ?? 10));
    $postcode = trim((string) ($data['home_postcode'] ?? ''));
    $postcodeVal = $postcode !== '' ? substr($postcode, 0, 12) : null;
    $stmt = $conn->prepare(
        'UPDATE workers SET avail_days = ?, avail_nights = ?, avail_weekends = ?, travel_radius_mi = ?, home_postcode = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('iiiisi', $days, $nights, $weekends, $radius, $postcodeVal, $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'workers', $id)) fail('Worker not found.', 404);
    audit($conn, (int) $user['id'], 'worker.availability', "worker #$id");
    json_out(['ok' => true]);
}

// Set a worker's pay rate + type.
// POST /workers.php?action=pay&id=N {pay_rate_pence, pay_type, effective_from?, note?}
if ($action === 'pay' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $rate = (int) ($data['pay_rate_pence'] ?? 0);
    $type = trim((string) ($data['pay_type'] ?? 'paye'));
    // A rise starts on a date. Defaults to today; a backdated correction says so.
    $effective = trim((string) ($data['effective_from'] ?? '')) ?: date('Y-m-d');
    $note = trim((string) ($data['note'] ?? ''));
    if ($id <= 0) fail('A worker is required.');
    if ($rate < 0) fail('Pay rate cannot be negative.');
    if (!in_array($type, ['paye', 'umbrella'], true)) fail('Invalid pay type.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) fail('The effective date must be YYYY-MM-DD.');
    if (mb_strlen($note) > 255) fail('That note is too long.');
    // Read the current values first: an audit entry that cannot say what the pay
    // rate was before is not much of an audit entry.
    $prev = $conn->prepare('SELECT pay_rate_pence, pay_type, division FROM workers WHERE id = ? AND deleted_at IS NULL');
    $prev->bind_param('i', $id);
    $prev->execute();
    $was = $prev->get_result()->fetch_assoc();
    // No row means no worker. Without this a pay change against a mistyped id
    // reported success and wrote an audit entry with an empty before-state.
    if (!$was) fail('Worker not found.', 404);
    require_service_scope($user, $was['division']);
    unset($was['division']);   // not part of the pay before-state

    // The history row is what payroll reads for work done on or after that date
    // (migration 022); workers.pay_rate_pence stays as the current rate for the
    // profile screen and for costing a new shift. Both move together, so they
    // cannot drift apart.
    //
    // REPLACE rather than INSERT: a second change on the same day is a
    // correction of the first, not a second rate nobody can order.
    $noteVal = $note !== '' ? $note : null;
    $uid = (int) $user['id'];
    $hist = $conn->prepare(
        'REPLACE INTO worker_pay_rates (worker_id, rate_pence, pay_type, effective_from, set_by, note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $hist->bind_param('iissis', $id, $rate, $type, $effective, $uid, $noteVal);
    $hist->execute();

    // The denormalised current rate only moves when the change starts today or
    // earlier. A rise dated next month must not change what today costs.
    if ($effective <= date('Y-m-d')) {
        $stmt = $conn->prepare('UPDATE workers SET pay_rate_pence = ?, pay_type = ? WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('isi', $rate, $type, $id);
        $stmt->execute();
    }

    [$before, $after] = audit_diff($was, ['pay_rate_pence' => $rate, 'pay_type' => $type], ['pay_rate_pence', 'pay_type']);
    audit($conn, $uid, 'worker.pay', "worker #$id from $effective", 'worker', $id, $before, $after);
    json_out(['ok' => true, 'effective_from' => $effective]);
}

// ----- Approve a worker for work, or withdraw that approval -----
// POST /workers.php?action=approve&id=N   {note?}
// POST /workers.php?action=unapprove&id=N {reason}
//
// The last gate before somebody can be assigned. Every box being ticked is not
// the same fact as an approval: rg_deployment_check() weighed compliance evidence
// and nothing else, so a worker still half way through onboarding, or one nobody
// had ever agreed to employ, could be put on a shift as long as their DBS was in
// date.
if ($action === 'approve' && method() === 'POST') {
    // Deliberately narrower than the rest of this file. Approving somebody for
    // work is the decision the company is answerable for, and a manager holds it.
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A worker is required.');
    $note = trim((string) (body()['note'] ?? ''));
    if (mb_strlen($note) > 255) fail('Please keep the note under 255 characters.');

    $stmt = $conn->prepare(
        'SELECT id, person_id, name, division, status, left_on, approved_at, deleted_at
           FROM workers WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);
    require_service_scope($user, $w['division']);

    // Approval does not override the things it sits on top of. Approving somebody
    // whose onboarding is unfinished would make the approval mean nothing, and
    // approving a leaver or a suspended worker is not a decision anybody means to
    // make. Then a signed contract that somebody with the authority has read.
    //
    // rg_approval_blockers() in pure.php holds the rule, so the person profile can
    // show the same reasons before anybody presses the button.
    $blockers = rg_approval_readiness($conn, $w);
    if ($blockers) {
        $first = $blockers[0];
        fail(($first['code'] === 'employment' ? 'This worker cannot be approved yet. ' : '') . $first['reason'], 409);
    }

    $uid = (int) $user['id'];
    $noteVal = $note !== '' ? $note : null;
    $stmt = $conn->prepare(
        'UPDATE workers SET approved_at = NOW(), approved_by = ?, approval_note = ?, status = "active"
          WHERE id = ? AND approved_at IS NULL'
    );
    $stmt->bind_param('isi', $uid, $noteVal, $id);
    $stmt->execute();
    // Another request got there first. Reporting success would attribute the
    // approval to whoever asked second.
    if ($stmt->affected_rows === 0) fail('This worker is already approved.', 409);

    // The application that produced this worker reaches 'onboarded' here and
    // nowhere else. rg_stage_transition() refuses it by hand ("approving
    // activation sets this stage") and nothing set it, so a candidate approved
    // for work still read as half way through onboarding on the recruitment board.
    $stmt = $conn->prepare(
        'SELECT id FROM applicants WHERE worker_id = ? AND stage = "onboarding" AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $apps = [];
    while ($row = $res->fetch_assoc()) $apps[] = (int) $row['id'];
    foreach ($apps as $appId) {
        $stmt = $conn->prepare('UPDATE applicants SET stage = "onboarded" WHERE id = ?');
        $stmt->bind_param('i', $appId);
        $stmt->execute();
        $reasonText = 'Approved for work';
        $stmt = $conn->prepare(
            'INSERT INTO applicant_stage_history (applicant_id, from_stage, to_stage, reason, changed_by)
             VALUES (?, "onboarding", "onboarded", ?, ?)'
        );
        $stmt->bind_param('isi', $appId, $reasonText, $uid);
        $stmt->execute();
    }

    // Recalculated now, not at the next cron run. A worker who has just been
    // approved and whose dashboard still says "pre-employment" until tomorrow
    // morning is a worker the office will ring up about.
    rg_refresh_worker_status($conn, $id);

    audit($conn, $uid, 'worker.approve', $w['name'], 'worker', $id,
        ['approved_at' => null], ['approved_at' => 'now', 'approved_by' => $uid]);
    json_out(['ok' => true, 'approved' => true]);
}

if ($action === 'unapprove' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A worker is required.');
    $reason = trim((string) (body()['reason'] ?? ''));
    // Withdrawing approval stops somebody working. It is not a thing to do
    // without saying why.
    if ($reason === '') fail('A reason is required to withdraw approval.');
    if (mb_strlen($reason) > 255) fail('Please keep the reason under 255 characters.');

    $stmt = $conn->prepare('SELECT id, name, division, approved_at FROM workers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);
    require_service_scope($user, $w['division']);
    if ($w['approved_at'] === null) fail('This worker is not approved.', 409);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE workers SET approved_at = NULL, approved_by = NULL, approval_note = ? WHERE id = ?'
    );
    $stmt->bind_param('si', $reason, $id);
    $stmt->execute();

    rg_refresh_worker_status($conn, $id);

    audit($conn, $uid, 'worker.unapprove', $w['name'], 'worker', $id,
        ['approved_at' => $w['approved_at']], ['approved_at' => null, 'reason' => $reason]);
    json_out(['ok' => true, 'approved' => false]);
}

// ----- Suspend a worker, or lift the suspension -----
// POST /workers.php?action=suspend&id=N   {reason}
// POST /workers.php?action=reinstate&id=N {reason}
//
// Migration 035 let the status column hold 'suspended' and nothing could write
// it, so standing somebody down while a concern was looked into meant marking
// them inactive -- which says something else. Suspension does not withdraw the
// approval: it is a pause, and rg_employment_gate() refuses a suspended worker on
// its own. Lifting it returns them to where they were.
if (($action === 'suspend' || $action === 'reinstate') && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A worker is required.');
    $reason = trim((string) (body()['reason'] ?? ''));
    if ($reason === '') {
        fail($action === 'suspend' ? 'A reason is required to suspend a worker.' : 'A reason is required to lift a suspension.');
    }
    if (mb_strlen($reason) > 255) fail('Please keep the reason under 255 characters.');

    $stmt = $conn->prepare(
        'SELECT id, name, division, status, approved_at FROM workers WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);
    require_service_scope($user, $w['division']);

    $uid = (int) $user['id'];
    if ($action === 'suspend') {
        if ($w['status'] === 'suspended') fail('This worker is already suspended.', 409);
        $stmt = $conn->prepare(
            'UPDATE workers SET status = "suspended", suspended_at = NOW(), suspended_by = ?, suspension_reason = ?
              WHERE id = ? AND status <> "suspended"'
        );
        $stmt->bind_param('isi', $uid, $reason, $id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) fail('This worker is already suspended.', 409);
        $to = 'suspended';
    } else {
        if ($w['status'] !== 'suspended') fail('This worker is not suspended.', 409);
        // Back to active only if somebody had approved them; otherwise they were
        // still onboarding when the suspension started, and still are.
        $to = $w['approved_at'] !== null ? 'active' : 'onboarding';
        $stmt = $conn->prepare(
            'UPDATE workers SET status = ?, suspended_at = NULL, suspended_by = NULL, suspension_reason = NULL
              WHERE id = ? AND status = "suspended"'
        );
        $stmt->bind_param('si', $to, $id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) fail('This worker is not suspended.', 409);
    }

    rg_refresh_worker_status($conn, $id);
    audit($conn, $uid, 'worker.' . $action, $w['name'], 'worker', $id,
        ['status' => $w['status']], ['status' => $to], $reason);
    json_out(['ok' => true, 'status' => $to]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $role = trim((string) ($data['role'] ?? 'carer'));
    $division = trim((string) ($data['division'] ?? 'care'));
    $rtw = !empty($data['right_to_work']) ? 1 : 0;
    $payRate = (int) ($data['pay_rate_pence'] ?? 0);
    $payType = trim((string) ($data['pay_type'] ?? 'paye'));

    if ($name === '') fail('The worker name is required.');
    $allowedRoles = ['carer', 'nurse', 'cleaner', 'guard', 'trainer'];
    $allowedDiv = ['care', 'academy', 'cleaning', 'security'];
    if (!in_array($role, $allowedRoles, true)) fail('Invalid role.');
    if (!in_array($division, $allowedDiv, true)) fail('Invalid division.');
    // The division arrives in the body, so without this a scoped account could
    // create a worker in a service it cannot then see, manage or be responsible
    // for -- and would have allocated them a payroll number doing it.
    require_service_scope($user, $division);
    if ($payRate < 0) fail('Pay rate cannot be negative.');
    if (!in_array($payType, ['paye', 'umbrella'], true)) fail('Invalid pay type.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');

    // One person, one record: a worker created here for somebody already known
    // attaches to their existing person rather than starting a second identity.
    $personId = find_or_create_person($conn, $name, $email ?: null, $phone ?: null);
    $payrollNo = next_payroll_no($conn);
    $stmt = $conn->prepare(
        'INSERT INTO workers (person_id, payroll_no, name, email, phone, role, division, right_to_work, pay_rate_pence, pay_type, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "onboarding")'
    );
    $stmt->bind_param('issssssiis', $personId, $payrollNo, $name, $email, $phone, $role, $division, $rtw, $payRate, $payType);
    $stmt->execute();
    $id = $conn->insert_id;

    // Open the rate history at the start, so payroll has a rate in force for the
    // worker's first shift rather than falling back to the denormalised column.
    $uid = (int) $user['id'];
    $today = date('Y-m-d');
    $openingNote = 'Rate on joining';
    $hist = $conn->prepare(
        'INSERT INTO worker_pay_rates (worker_id, rate_pence, pay_type, effective_from, set_by, note)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $hist->bind_param('iissis', $id, $payRate, $payType, $today, $uid, $openingNote);
    $hist->execute();

    audit($conn, (int) $user['id'], 'worker.create', $name, 'worker', (int) $id,
        null, ['person_id' => $personId, 'payroll_no' => $payrollNo, 'role' => $role, 'division' => $division]);

    json_out([
        'worker' => [
            'id' => (int) $id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'division' => $division,
            'status' => 'onboarding',
            'right_to_work' => (bool) $rtw,
            'dbs_status' => null,
            'dbs_expiry' => null,
            'sia_number' => null,
            'sia_expiry' => null,
            'pay_rate_pence' => $payRate,
            'pay_type' => $payType,
            'holiday_hours' => 0,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
