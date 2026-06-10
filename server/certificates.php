<?php
// Certificates endpoint.
// GET /certificates.php?action=verify&id=RG-2026-00123 -> { certificate }   (public)
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

if ($action === 'verify' && method() === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') fail('A certificate ID is required.');

    $stmt = $conn->prepare(
        'SELECT public_id, learner_name, course, issued_on, status
         FROM certificates WHERE public_id = ? AND status = "issued" LIMIT 1'
    );
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('No certificate found with that ID.', 404);

    json_out([
        'certificate' => [
            'id' => $row['public_id'],
            'learner_name' => $row['learner_name'],
            'course' => $row['course'],
            'issued_on' => $row['issued_on'],
            'status' => $row['status'],
        ],
    ]);
}

fail('Unknown action.', 404);
