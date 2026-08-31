<?php
// Deletion approval and export logging.
//
//   GET  /governance.php?action=deletions[&status=requested]
//   POST /governance.php?action=request_deletion
//   POST /governance.php?action=decide_deletion&id=N
//   GET  /governance.php?action=exports
//   POST /governance.php?action=log_export
//   GET  /governance.php?action=retention
//   POST /governance.php?action=set_retention
//
// Two rules run through all of it.
//
// Nobody deletes an employment record on their own say so. Ordinary staff
// request; somebody else approves; both halves are recorded with a reason. And
// the approver may not be the requester -- a four-eyes rule that a single
// determined person cannot walk around.
//
// Every export of personal data is logged. Where a spreadsheet goes after it
// leaves is outside this system's control, which is the reason to record that it
// left at all.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_staff($user);
$action = $_GET['action'] ?? '';

// What may be removed through this workflow, and what it is called.
const DELETABLE = [
    'person' => 'people', 'worker' => 'workers', 'applicant' => 'applicants',
    'client' => 'clients', 'file' => 'files', 'user' => 'users',
];

// ---- Deletion requests -----------------------------------------------------
if (method() === 'GET' && $action === 'deletions') {
    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT d.id, d.entity_type, d.entity_id, d.entity_label, d.reason, d.disposition,
                   d.status, d.requested_at, d.decided_at, d.decision_note, d.completed_at,
                   r.name AS requested_by, a.name AS decided_by
            FROM deletion_requests d
            LEFT JOIN users r ON r.id = d.requested_by
            LEFT JOIN users a ON a.id = d.decided_by';
    $params = [];
    if (in_array($status, ['requested','approved','rejected','completed','cancelled'], true)) {
        $sql .= ' WHERE d.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY d.id DESC LIMIT 200';

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param('s', $params[0]);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['entity_id'] = (int) $row['entity_id'];
        $items[] = $row;
    }
    json_out(['requests' => $items]);
}

if (method() === 'POST' && $action === 'request_deletion') {
    $data = body();
    $type = trim((string) ($data['entity_type'] ?? ''));
    $id = (int) ($data['entity_id'] ?? 0);
    $reason = trim((string) ($data['reason'] ?? ''));
    $disposition = trim((string) ($data['disposition'] ?? 'archive'));

    if (!isset(DELETABLE[$type])) fail('That kind of record cannot be removed through this workflow.');
    if ($id <= 0) fail('A record is required.');
    // A reason is required. A deletion request nobody can explain later is not
    // one an approver can sensibly decide on.
    if (strlen($reason) < 10) fail('Give a reason of at least 10 characters.');
    if (!in_array($disposition, ['archive', 'delete'], true)) fail('Disposition must be archive or delete.');

    $table = DELETABLE[$type];
    $labelColumn = in_array($table, ['people'], true) ? 'full_name'
        : (in_array($table, ['files'], true) ? 'original_name' : 'name');
    $stmt = $conn->prepare("SELECT $labelColumn AS label FROM $table WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('That record does not exist.', 404);
    // Captured now: the record may be gone by the time anybody reads this.
    $label = substr((string) $row['label'], 0, 255);

    $stmt = $conn->prepare('SELECT id FROM deletion_requests WHERE entity_type = ? AND entity_id = ? AND status IN ("requested","approved") LIMIT 1');
    $stmt->bind_param('si', $type, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) fail('There is already an open request for that record.', 409);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'INSERT INTO deletion_requests (entity_type, entity_id, entity_label, reason, disposition, requested_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sisssi', $type, $id, $label, $reason, $disposition, $uid);
    $stmt->execute();
    $requestId = (int) $conn->insert_id;

    audit($conn, $uid, 'deletion.request', "$type #$id ($label)", $type, $id, null,
        ['disposition' => $disposition], $reason);
    json_out(['ok' => true, 'id' => $requestId], 201);
}

if (method() === 'POST' && $action === 'decide_deletion') {
    require_role($user, 'super_admin', 'admin');
    $id = (int) ($_GET['id'] ?? 0);
    $data = body();
    $decision = trim((string) ($data['decision'] ?? ''));
    $note = trim((string) ($data['note'] ?? ''));
    if ($id <= 0) fail('A request is required.');
    if (!in_array($decision, ['approved', 'rejected'], true)) fail('Decision must be approved or rejected.');

    $stmt = $conn->prepare('SELECT * FROM deletion_requests WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) fail('Request not found.', 404);
    if ($req['status'] !== 'requested') fail('That request has already been decided.', 409);

    // Four eyes. The point of an approval step is that somebody else looked, so
    // a single determined person cannot walk their own request through.
    $uid = (int) $user['id'];
    if ((int) $req['requested_by'] === $uid) {
        fail('You cannot approve your own deletion request. Somebody else must decide it.', 409);
    }

    $noteVal = $note !== '' ? $note : null;
    if ($decision === 'rejected') {
        $stmt = $conn->prepare('UPDATE deletion_requests SET status = "rejected", decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?');
        $stmt->bind_param('isi', $uid, $noteVal, $id);
        $stmt->execute();
        audit($conn, $uid, 'deletion.reject', "request #$id", $req['entity_type'], (int) $req['entity_id'], null, null, $noteVal);
        json_out(['ok' => true, 'status' => 'rejected']);
    }

    // Approved. Carry it out.
    $table = DELETABLE[$req['entity_type']] ?? null;
    if ($table === null) fail('That kind of record can no longer be removed here.', 409);

    $entityId = (int) $req['entity_id'];
    if ($req['disposition'] === 'archive') {
        // Soft delete where the table supports it, which is everywhere it matters.
        $hasDeletedAt = $conn->query("SHOW COLUMNS FROM $table LIKE 'deleted_at'")->num_rows > 0;
        if (!$hasDeletedAt) fail("Records in $table cannot be archived; they have no deleted_at.", 409);
        $stmt = $conn->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = ?");
    } else {
        $stmt = $conn->prepare("DELETE FROM $table WHERE id = ?");
    }
    $stmt->bind_param('i', $entityId);
    $stmt->execute();

    $stmt = $conn->prepare('UPDATE deletion_requests SET status = "completed", decided_by = ?, decided_at = NOW(), decision_note = ?, completed_at = NOW() WHERE id = ?');
    $stmt->bind_param('isi', $uid, $noteVal, $id);
    $stmt->execute();

    audit($conn, $uid, 'deletion.' . $req['disposition'], "{$req['entity_type']} #$entityId ({$req['entity_label']})",
        $req['entity_type'], $entityId, ['existed' => true], ['disposition' => $req['disposition']], $req['reason']);
    json_out(['ok' => true, 'status' => 'completed', 'disposition' => $req['disposition']]);
}

// ---- Export log ------------------------------------------------------------
if (method() === 'GET' && $action === 'exports') {
    require_role($user, 'super_admin', 'admin');
    $res = $conn->query(
        'SELECT e.id, e.export_type, e.format, e.record_count, e.scope, e.purpose,
                e.contains_personal_data, e.ip, e.created_at, u.name AS user
         FROM export_log e LEFT JOIN users u ON u.id = e.user_id
         ORDER BY e.id DESC LIMIT 200'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['contains_personal_data'] = (bool) $row['contains_personal_data'];
        $items[] = $row;
    }
    json_out(['exports' => $items]);
}

// Called by the client as it produces a download. Exports are built in the
// browser from data already fetched, so this is the only point at which the
// server can know one happened.
if (method() === 'POST' && $action === 'log_export') {
    $data = body();
    $type = substr(trim((string) ($data['export_type'] ?? '')), 0, 64);
    $format = trim((string) ($data['format'] ?? 'csv'));
    if ($type === '') fail('An export type is required.');
    if (!in_array($format, ['csv', 'pdf', 'xlsx', 'json'], true)) fail('Unknown format.');

    $count = isset($data['record_count']) && $data['record_count'] !== '' ? (int) $data['record_count'] : null;
    $scope = substr(trim((string) ($data['scope'] ?? '')), 0, 255) ?: null;
    $purpose = substr(trim((string) ($data['purpose'] ?? '')), 0, 500) ?: null;
    $personal = array_key_exists('contains_personal_data', $data) ? (!empty($data['contains_personal_data']) ? 1 : 0) : 1;
    $ip = client_ip();
    $uid = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO export_log (user_id, export_type, format, record_count, scope, purpose, contains_personal_data, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // user_id i | export_type s | format s | record_count i | scope s | purpose s | personal i | ip s
    $stmt->bind_param('ississis', $uid, $type, $format, $count, $scope, $purpose, $personal, $ip);
    $stmt->execute();
    json_out(['ok' => true], 201);
}

// ---- Retention policies ----------------------------------------------------
if (method() === 'GET' && $action === 'retention') {
    $res = $conn->query(
        'SELECT r.id, r.record_type, r.label, r.retain_months, r.basis, r.action_at_end,
                r.active, r.updated_at, u.name AS updated_by
         FROM retention_policies r LEFT JOIN users u ON u.id = r.updated_by
         ORDER BY r.label'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['retain_months'] = $row['retain_months'] !== null ? (int) $row['retain_months'] : null;
        $row['active'] = (bool) $row['active'];
        // NULL is not zero. A policy with no period is unset, and a dashboard
        // must be able to say so rather than implying immediate deletion.
        $row['is_set'] = $row['retain_months'] !== null;
        $items[] = $row;
    }
    json_out(['policies' => $items,
              'unset' => count(array_filter($items, static fn(array $p): bool => !$p['is_set']))]);
}

if (method() === 'POST' && $action === 'set_retention') {
    require_role($user, 'super_admin', 'admin');
    $data = body();
    $type = trim((string) ($data['record_type'] ?? ''));
    if ($type === '') fail('A record type is required.');

    $months = isset($data['retain_months']) && $data['retain_months'] !== '' ? (int) $data['retain_months'] : null;
    if ($months !== null && ($months < 0 || $months > 1200)) fail('A retention period must be between 0 and 1200 months.');
    $basis = substr(trim((string) ($data['basis'] ?? '')), 0, 500) ?: null;
    $atEnd = trim((string) ($data['action_at_end'] ?? 'review'));
    if (!in_array($atEnd, ['review', 'archive', 'delete'], true)) fail('Unknown end-of-period action.');

    $stmt = $conn->prepare('SELECT retain_months, action_at_end FROM retention_policies WHERE record_type = ?');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $was = $stmt->get_result()->fetch_assoc();
    if (!$was) fail('Unknown record type.', 404);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE retention_policies SET retain_months = ?, basis = ?, action_at_end = ?, updated_by = ? WHERE record_type = ?');
    $stmt->bind_param('issis', $months, $basis, $atEnd, $uid, $type);
    $stmt->execute();

    [$before, $after] = audit_diff($was, ['retain_months' => $months, 'action_at_end' => $atEnd],
        ['retain_months', 'action_at_end']);
    audit($conn, $uid, 'retention.set', $type, 'retention_policy', null, $before, $after, $basis);
    json_out(['ok' => true]);
}

// ---- Sensitive zones -------------------------------------------------------
// The matrix of which role may see which category of information. Configuration,
// so the business can widen or narrow access without a deployment.
if (method() === 'GET' && $action === 'zones') {
    require_role($user, 'super_admin', 'admin');
    $res = $conn->query(
        'SELECT z.role_key, z.zone, z.can_view, z.can_edit, z.updated_at, u.name AS updated_by
         FROM zone_permissions z LEFT JOIN users u ON u.id = z.updated_by
         ORDER BY FIELD(z.role_key,"super_admin","admin","manager","trainer","editor","employee","client"),
                  FIELD(z.zone,"general","employment","compliance","health","payroll","disciplinary","legal")'
    );
    $matrix = [];
    while ($row = $res->fetch_assoc()) {
        $matrix[$row['role_key']][$row['zone']] = [
            'can_view' => (bool) $row['can_view'],
            'can_edit' => (bool) $row['can_edit'],
        ];
    }
    json_out(['zones' => ['general','employment','compliance','health','payroll','disciplinary','legal'],
              'matrix' => $matrix]);
}

if (method() === 'POST' && $action === 'set_zone') {
    require_role($user, 'super_admin');
    $data = body();
    $role = trim((string) ($data['role_key'] ?? ''));
    $zone = trim((string) ($data['zone'] ?? ''));
    $view = !empty($data['can_view']) ? 1 : 0;
    $edit = !empty($data['can_edit']) ? 1 : 0;

    $zones = ['general','employment','compliance','health','payroll','disciplinary','legal'];
    if (!in_array($zone, $zones, true)) fail('Unknown zone.');
    // Editing without viewing is not a coherent permission.
    if ($edit && !$view) fail('A role that cannot view a zone cannot edit it.');

    $stmt = $conn->prepare('SELECT can_view, can_edit FROM zone_permissions WHERE role_key = ? AND zone = ?');
    $stmt->bind_param('ss', $role, $zone);
    $stmt->execute();
    $was = $stmt->get_result()->fetch_assoc();
    if (!$was) fail('Unknown role.', 404);

    // super_admin must keep every zone, or the account that fixes a mistake here
    // can lock itself out of the screen that fixes it.
    if ($role === 'super_admin' && !$view) fail('A super admin cannot be denied a zone.', 409);

    $uid = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE zone_permissions SET can_view = ?, can_edit = ?, updated_by = ? WHERE role_key = ? AND zone = ?');
    $stmt->bind_param('iiiss', $view, $edit, $uid, $role, $zone);
    $stmt->execute();

    [$before, $after] = audit_diff($was, ['can_view' => $view, 'can_edit' => $edit], ['can_view', 'can_edit']);
    audit($conn, $uid, 'zone.set', "$role / $zone", 'zone_permission', null, $before, $after);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
