<?php
// Workers / staff endpoint.
// GET  /workers.php  -> { workers: [...] }
// POST /workers.php {name, email, phone, role, division, right_to_work} -> { worker }
require __DIR__ . '/lib.php';

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
        'SELECT id, name, email, phone, role, division, status, right_to_work,
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

// Set a worker's pay rate + type. POST /workers.php?action=pay&id=N {pay_rate_pence, pay_type}
if ($action === 'pay' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $rate = (int) ($data['pay_rate_pence'] ?? 0);
    $type = trim((string) ($data['pay_type'] ?? 'paye'));
    if ($id <= 0) fail('A worker is required.');
    if ($rate < 0) fail('Pay rate cannot be negative.');
    if (!in_array($type, ['paye', 'umbrella'], true)) fail('Invalid pay type.');
    // Read the current values first: an audit entry that cannot say what the pay
    // rate was before is not much of an audit entry.
    $prev = $conn->prepare('SELECT pay_rate_pence, pay_type FROM workers WHERE id = ? AND deleted_at IS NULL');
    $prev->bind_param('i', $id);
    $prev->execute();
    $was = $prev->get_result()->fetch_assoc();
    // No row means no worker. Without this a pay change against a mistyped id
    // reported success and wrote an audit entry with an empty before-state.
    if (!$was) fail('Worker not found.', 404);

    $stmt = $conn->prepare('UPDATE workers SET pay_rate_pence = ?, pay_type = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('isi', $rate, $type, $id);
    $stmt->execute();

    [$before, $after] = audit_diff($was, ['pay_rate_pence' => $rate, 'pay_type' => $type], ['pay_rate_pence', 'pay_type']);
    audit($conn, (int) $user['id'], 'worker.pay', "worker #$id", 'worker', $id, $before, $after);
    json_out(['ok' => true]);
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
