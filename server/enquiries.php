<?php
// Enquiries inbox — the admin view of the public contact form submissions.
// GET  /enquiries.php                       -> { enquiries: [...] }
// POST /enquiries.php?action=handled&id=5   -> mark handled
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT id, name, email, phone, division, message, status, created_at
         FROM contact_enquiries ORDER BY id DESC LIMIT 200'
    );
    $enquiries = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $enquiries[] = $row;
    }
    json_out(['enquiries' => $enquiries]);
}

if ($action === 'handled' && method() === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An enquiry is required.');
    $stmt = $conn->prepare('UPDATE contact_enquiries SET status = "handled" WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'contact_enquiries', $id, false)) {
        fail('Enquiry not found.', 404);
    }
    audit($conn, (int) $user['id'], 'enquiry.handled', "#$id");
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
