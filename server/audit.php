<?php
// Audit log viewer (admin only).
//
//   GET /audit.php                                  most recent, capped
//   GET /audit.php?entity_type=worker&entity_id=12   everything that happened to one record
//   GET /audit.php?action=rtw.verify                 everything of one kind
//   GET /audit.php?user_id=3                         everything one person did
//
// The entity filter is the point of the log. "What happened to this worker?" was
// unanswerable while entries carried only an action and a free-text string.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');
if (method() !== 'GET') fail('Method not allowed.', 405);

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));

$where = [];
$params = [];
$types = '';

if (!empty($_GET['entity_type'])) {
    $where[] = 'a.entity_type = ?';
    $params[] = substr((string) $_GET['entity_type'], 0, 40);
    $types .= 's';
    if (isset($_GET['entity_id']) && $_GET['entity_id'] !== '') {
        $where[] = 'a.entity_id = ?';
        $params[] = (int) $_GET['entity_id'];
        $types .= 'i';
    }
}
if (!empty($_GET['action'])) {
    $where[] = 'a.action = ?';
    $params[] = substr((string) $_GET['action'], 0, 64);
    $types .= 's';
}
if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
    $where[] = 'a.user_id = ?';
    $params[] = (int) $_GET['user_id'];
    $types .= 'i';
}

$sql = 'SELECT a.id, a.action, a.entity_type, a.entity_id, a.detail,
               a.before_json, a.after_json, a.reason, a.ip, a.created_at,
               u.name AS user
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY a.id DESC LIMIT ' . $limit;

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$entries = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['entity_id'] = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;
    // Decode here so the client does not have to parse a string out of JSON.
    $row['before'] = $row['before_json'] !== null ? json_decode($row['before_json'], true) : null;
    $row['after'] = $row['after_json'] !== null ? json_decode($row['after_json'], true) : null;
    unset($row['before_json'], $row['after_json']);
    $entries[] = $row;
}

// Reading the audit log is itself a sensitive action, and one worth being able
// to see. Recorded only for a filtered query, so the routine full listing does
// not fill the log with entries about reading the log.
if ($where) {
    audit($conn, (int) $user['id'], 'audit.search', http_build_query(array_intersect_key(
        $_GET, array_flip(['entity_type', 'entity_id', 'action', 'user_id'])
    )));
}

json_out(['entries' => $entries, 'count' => count($entries), 'limit' => $limit]);
