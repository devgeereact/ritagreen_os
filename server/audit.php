<?php
// Audit log viewer (admin only).
// GET /audit.php -> { entries: [...] }  (most recent first, capped)
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');
if (method() !== 'GET') fail('Method not allowed.', 405);

$res = $conn->query(
    'SELECT a.id, a.action, a.detail, a.created_at, u.name AS user
     FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 200'
);
$entries = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $entries[] = $row;
}
json_out(['entries' => $entries]);
