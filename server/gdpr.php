<?php
// UK GDPR data-subject tooling (admin only).
// POST /gdpr.php?action=export {email}  -> all personal data held for an email
// POST /gdpr.php?action=erase  {email}  -> anonymise that person's records
// Consent is logged at capture time (see consent_log); this covers access &
// erasure requests. Workforce/user records are not auto-erased (legal basis:
// employment) — those are handled manually with the data flagged here.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');
$action = $_GET['action'] ?? '';
$data = body();
$email = trim((string) ($data['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A valid email is required.');

function rows_for(mysqli $conn, string $sql, string $email): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $out = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}

if ($action === 'export' && method() === 'POST') {
    $bundle = [
        'email' => $email,
        'generated_at' => date('c'),
        'enquiries' => rows_for($conn, 'SELECT id, name, email, phone, division, message, created_at FROM contact_enquiries WHERE email = ?', $email),
        'service_requests' => rows_for($conn, 'SELECT id, contact_name, email, phone, division, detail, created_at FROM service_requests WHERE email = ?', $email),
        'consent' => rows_for($conn, 'SELECT kind, granted, source, created_at FROM consent_log WHERE email = ?', $email),
        'client_records' => rows_for($conn, 'SELECT id, name, contact_name, email, phone FROM clients WHERE email = ? AND deleted_at IS NULL', $email),
        'worker_records' => rows_for($conn, 'SELECT id, name, email, phone, role FROM workers WHERE email = ? AND deleted_at IS NULL', $email),
    ];
    audit($conn, (int) $user['id'], 'gdpr.export', $email);
    json_out($bundle);
}

if ($action === 'erase' && method() === 'POST') {
    $anon = 'erased@redacted.invalid';
    // Anonymise public-form personal data (no ongoing legal basis to keep it).
    $q = $conn->prepare("UPDATE contact_enquiries SET name = '[erased]', email = ?, phone = NULL, message = '[erased]' WHERE email = ?");
    $q->bind_param('ss', $anon, $email);
    $q->execute();
    $n1 = $conn->affected_rows;
    $q = $conn->prepare("UPDATE service_requests SET contact_name = '[erased]', email = ?, phone = NULL, detail = '[erased]' WHERE email = ?");
    $q->bind_param('ss', $anon, $email);
    $q->execute();
    $n2 = $conn->affected_rows;
    $q = $conn->prepare('DELETE FROM consent_log WHERE email = ?');
    $q->bind_param('s', $email);
    $q->execute();
    $n3 = $conn->affected_rows;

    // Flag (not delete) any client/worker match — employment/contract records
    // need a manual decision, but we surface them.
    $remaining = [
        'clients' => rows_for($conn, 'SELECT id, name FROM clients WHERE email = ? AND deleted_at IS NULL', $email),
        'workers' => rows_for($conn, 'SELECT id, name FROM workers WHERE email = ? AND deleted_at IS NULL', $email),
    ];

    audit($conn, (int) $user['id'], 'gdpr.erase', "$email enquiries=$n1 requests=$n2 consent=$n3");
    json_out(['ok' => true, 'erased' => ['enquiries' => $n1, 'service_requests' => $n2, 'consent' => $n3], 'needs_manual_review' => $remaining]);
}

fail('Method not allowed.', 405);
