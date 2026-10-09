<?php
// Requests: website cover requests and enquiries in one list, under the Rota.
//
// GET  /requests.php[?type=cover|enquiry][&status=open|closed]  -> { requests: [...] }
// POST /requests.php?action=owner&type=cover|enquiry&id=N {user_id|null}  -> set or clear the owner
//
// The two sources keep their own tables and endpoints: bookings.php confirms a
// cover request into draft shifts and sets its status, enquiries.php marks an
// enquiry handled. This file only reads them together and records who owns each.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Both carry a member of the public's name, email and phone.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

const REQUEST_OPEN = ['cover' => ['new', 'quoted'], 'enquiry' => ['new']];

if ($action === 'owner' && method() === 'POST') {
    $type = $_GET['type'] ?? '';
    $id = (int) ($_GET['id'] ?? 0);
    if (!in_array($type, ['cover', 'enquiry'], true) || $id <= 0) fail('A request is required.');
    $table = $type === 'cover' ? 'service_requests' : 'contact_enquiries';

    $stmt = $conn->prepare("SELECT division, owner_id FROM $table WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Request not found.', 404);
    // An enquiry's "other" is nobody's service, which a scoped account does not hold.
    require_service_scope($user, $row['division'] === 'other' ? null : $row['division']);

    $raw = body()['user_id'] ?? null;
    $ownerId = $raw === null || $raw === '' ? null : (int) $raw;
    if ($ownerId !== null) {
        // An owner is somebody in the office who can open the request: a client
        // or worker login is an ordinary users row too, and would be handed a
        // record it cannot see.
        $stmt = $conn->prepare('SELECT id, role, client_id, worker_id FROM users WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc();
        if (!$owner || !in_array($owner['role'], ['super_admin', 'admin', 'manager'], true) || !is_staff($owner)) {
            fail('Choose somebody in the office.', 422);
        }
    }
    $stmt = $conn->prepare("UPDATE $table SET owner_id = ? WHERE id = ?");
    $stmt->bind_param('ii', $ownerId, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'request.owner', "$type #$id", $type === 'cover' ? 'service_request' : 'enquiry', $id,
        ['owner_id' => $row['owner_id']], ['owner_id' => $ownerId]);
    json_out(['ok' => true]);
}

if (method() === 'GET') {
    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';
    $scope = service_scope($user);
    $out = [];

    if ($type === '' || $type === 'cover') {
        $sql = 'SELECT r.id, r.client_id, c.name AS client, r.contact_name, r.email, r.phone, r.division, r.site_name,
                       r.detail, r.preferred_start, r.preferred_end, r.status, r.shift_id, r.owner_id, u.name AS owner,
                       r.created_at
                  FROM service_requests r
                  LEFT JOIN clients c ON c.id = r.client_id
                  LEFT JOIN users u ON u.id = r.owner_id
                 WHERE r.deleted_at IS NULL' . ($scope !== null ? ' AND r.division = ?' : '') . '
                 ORDER BY r.created_at DESC LIMIT 300';
        $stmt = $conn->prepare($sql);
        if ($scope !== null) $stmt->bind_param('s', $scope);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[] = [
                'type' => 'cover', 'id' => (int) $r['id'], 'name' => $r['contact_name'], 'email' => $r['email'],
                'phone' => $r['phone'], 'division' => $r['division'], 'summary' => $r['detail'],
                'status' => $r['status'], 'open' => in_array($r['status'], REQUEST_OPEN['cover'], true),
                'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : null, 'owner' => $r['owner'],
                'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null, 'client' => $r['client'],
                'site_name' => $r['site_name'], 'preferred_start' => $r['preferred_start'],
                'preferred_end' => $r['preferred_end'],
                'shift_id' => $r['shift_id'] !== null ? (int) $r['shift_id'] : null,
                'created_at' => $r['created_at'],
            ];
        }
    }

    if ($type === '' || $type === 'enquiry') {
        $sql = 'SELECT e.id, e.name, e.email, e.phone, e.division, e.message, e.status, e.owner_id, u.name AS owner, e.created_at
                  FROM contact_enquiries e LEFT JOIN users u ON u.id = e.owner_id
                 WHERE e.deleted_at IS NULL' . ($scope !== null ? ' AND e.division = ?' : '') . '
                 ORDER BY e.created_at DESC LIMIT 300';
        $stmt = $conn->prepare($sql);
        if ($scope !== null) $stmt->bind_param('s', $scope);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $e) {
            $out[] = [
                'type' => 'enquiry', 'id' => (int) $e['id'], 'name' => $e['name'], 'email' => $e['email'],
                'phone' => $e['phone'], 'division' => $e['division'], 'summary' => $e['message'],
                'status' => $e['status'], 'open' => in_array($e['status'], REQUEST_OPEN['enquiry'], true),
                'owner_id' => $e['owner_id'] !== null ? (int) $e['owner_id'] : null, 'owner' => $e['owner'],
                'client_id' => null, 'client' => null, 'site_name' => null, 'preferred_start' => null,
                'preferred_end' => null, 'shift_id' => null, 'created_at' => $e['created_at'],
            ];
        }
    }

    if ($status === 'open') $out = array_values(array_filter($out, static fn(array $r): bool => $r['open']));
    if ($status === 'closed') $out = array_values(array_filter($out, static fn(array $r): bool => !$r['open']));
    usort($out, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    json_out(['requests' => $out]);
}

fail('Method not allowed.', 405);
