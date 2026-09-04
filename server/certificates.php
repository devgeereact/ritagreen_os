<?php
// Certificates endpoint.
// GET /certificates.php?action=verify&id=RG-2026-00123 -> { certificate }   (public)
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// Both actions below are public and both return a learner's NAME against an ID
// of the form RG-2026-00123 -- which counts. Unthrottled, that is a script away
// from a list of everybody the academy has ever certified, which is exactly the
// bulk disclosure UK GDPR asks us not to make trivially available.
//
// The limit is set for the real use: an employer checking the certificates in
// front of them, not enumerating a range. A wrong ID costs a lookup either way,
// so the limit covers hits and misses alike.
rate_limit($conn, 'verify', 30, 600);

// Open Badges 3.0 assertion (public JSON-LD) for an issued certificate.
if ($action === 'badge' && method() === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') fail('A certificate ID is required.');
    $stmt = $conn->prepare('SELECT public_id, learner_name, course, issued_on, status FROM certificates WHERE public_id = ? AND status = "issued" LIMIT 1');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('No certificate found with that ID.', 404);

    $base = defined('PUBLIC_BASE_URL') ? PUBLIC_BASE_URL : 'https://ritagreenservices.com';
    json_out([
        '@context' => ['https://www.w3.org/ns/credentials/v2', 'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json'],
        'id' => $base . '/verify/' . rawurlencode($row['public_id']),
        'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
        'issuer' => ['id' => $base, 'type' => ['Profile'], 'name' => 'RitaGreen Academy'],
        'validFrom' => $row['issued_on'] . 'T00:00:00Z',
        'name' => $row['course'],
        'credentialSubject' => [
            'type' => ['AchievementSubject'],
            'name' => $row['learner_name'],
            'achievement' => [
                'id' => $base . '/verify/' . rawurlencode($row['public_id']),
                'type' => ['Achievement'],
                'name' => $row['course'],
                'description' => 'Awarded by RitaGreen Academy.',
                'criteria' => ['narrative' => 'Completed the ' . $row['course'] . ' programme.'],
            ],
        ],
    ]);
}

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
