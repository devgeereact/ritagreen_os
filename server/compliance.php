<?php
// Compliance & certification engine.
// GET  /compliance.php            -> { summary: {...}, items: [...] }
// POST /compliance.php {worker_id, type, number, expiry} -> add a certification
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
// Everything in this file is the vetting record: credential status, DBS and
// SIA numbers, expiry dates and deployment verdicts. Internal staff only, and
// staff is not enough on its own -- it takes the compliance zone as well.
// Hoisted above every branch because only the pack action carried it, so any
// authenticated account could list the whole workforce's compliance state.
require_staff($user);
if (!can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);

// Classify an expiry date into a RAG status + days remaining. The implementation
// lives in pure.php so it is shared with expiry-alerts.php and can be tested
// without a database; this alias keeps the call sites below unchanged.
function classify(?string $expiry): array
{
    return rg_classify($expiry);
}

// ----- Audit-ready compliance pack for one worker (CQC / SIA ACS evidence) -----
// GET /compliance.php?action=pack&worker_id=N -> everything an auditor asks for
// in one bundle: identity + right-to-work, every credential with status, the
// reference checks, and the documents on file. The client turns this into a PDF.
if (method() === 'GET' && ($_GET['action'] ?? '') === 'pack') {
    // Staff and the compliance zone are already required at the top of the file.
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');

    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, role, division, status, right_to_work,
                dbs_status, dbs_expiry, dbs_update_service, sia_number, sia_expiry, recheck_due
         FROM workers WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    $credentials = [];
    if ($w['dbs_expiry'] || $w['dbs_status']) {
        $c = classify($w['dbs_expiry']);
        $credentials[] = ['type' => 'DBS check', 'identifier' => $w['dbs_update_service'] ? 'On update service' : null,
            'expiry' => $w['dbs_expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }
    if ($w['sia_number'] || $w['sia_expiry']) {
        $c = classify($w['sia_expiry']);
        $credentials[] = ['type' => 'SIA licence', 'identifier' => $w['sia_number'],
            'expiry' => $w['sia_expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }
    $stmt = $conn->prepare('SELECT type, number, expiry FROM certifications WHERE worker_id = ? ORDER BY expiry IS NULL, expiry ASC');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $cres = $stmt->get_result();
    while ($row = $cres->fetch_assoc()) {
        $c = classify($row['expiry']);
        $credentials[] = ['type' => $row['type'], 'identifier' => $row['number'],
            'expiry' => $row['expiry'], 'status' => $c['status'], 'days' => $c['days']];
    }

    $references = [];
    $stmt = $conn->prepare('SELECT referee_name, referee_org, relationship, status, gap_note FROM worker_references WHERE worker_id = ? ORDER BY created_at ASC');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $rres = $stmt->get_result();
    while ($row = $rres->fetch_assoc()) $references[] = $row;

    $documents = [];
    $stmt = $conn->prepare("SELECT original_name, category, created_at FROM files WHERE worker_id = ? AND deleted_at IS NULL ORDER BY created_at DESC");
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $fres = $stmt->get_result();
    while ($row = $fres->fetch_assoc()) $documents[] = $row;

    audit($conn, (int) $user['id'], 'compliance.pack', "worker #$workerId");
    json_out([
        'worker' => [
            'id' => (int) $w['id'], 'name' => $w['name'], 'email' => $w['email'], 'phone' => $w['phone'],
            'role' => $w['role'], 'division' => $w['division'], 'status' => $w['status'],
            'right_to_work' => (bool) $w['right_to_work'], 'recheck_due' => $w['recheck_due'],
        ],
        'credentials' => $credentials,
        'references' => $references,
        'documents' => $documents,
        'generated_by' => $user['name'],
        'generated_at' => date('Y-m-d H:i'),
    ]);
}

// ----- Eligibility: may this person work in this service? -----
// GET /compliance.php?action=eligibility&worker_id=N[&service=security]
//
// The answer is CALCULATED from the rules configured for the service and the
// evidence on file. It is never read from a stored flag, and nothing may write
// one -- see docs/RULES.md rule 1.
if (method() === 'GET' && ($_GET['action'] ?? '') === 'eligibility') {
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');
    $service = trim((string) ($_GET['service'] ?? ''));
    $valid = ['care', 'academy', 'cleaning', 'security'];
    if ($service !== '' && !in_array($service, $valid, true)) fail('Unknown service.');

    $verdict = rg_deployment_check($conn, $workerId, $service !== '' ? $service : null);
    json_out($verdict);
}

// Every service a person is assessed for, in one call. This is what makes the
// per-service model visible: one human, a different answer per service.
if (method() === 'GET' && ($_GET['action'] ?? '') === 'eligibility_all') {
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');

    $stmt = $conn->prepare('SELECT person_id, name, role FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    $services = [];
    foreach (['care', 'academy', 'cleaning', 'security'] as $service) {
        $v = rg_assess_person($conn, (int) $w['person_id'], $service, $w['role']);
        $services[$service] = [
            'status' => $v['status'],
            'eligible' => $v['eligible'],
            'score' => $v['score'],
            'reason' => $v['reason'],
            'blocking' => array_column($v['blocking'], 'reason'),
        ];
    }
    json_out(['worker' => $w['name'], 'person_id' => (int) $w['person_id'], 'services' => $services]);
}

if (method() === 'GET') {
    $items = [];

    // DBS + SIA held on the worker row.
    $res = $conn->query(
        'SELECT id, name, division, dbs_status, dbs_expiry, sia_number, sia_expiry
         FROM workers WHERE deleted_at IS NULL'
    );
    while ($w = $res->fetch_assoc()) {
        if ($w['dbs_expiry'] || $w['dbs_status']) {
            $c = classify($w['dbs_expiry']);
            $items[] = [
                'worker_id' => (int) $w['id'], 'worker' => $w['name'], 'division' => $w['division'],
                'type' => 'DBS check', 'identifier' => null,
                'expiry' => $w['dbs_expiry'], 'status' => $c['status'], 'days' => $c['days'],
            ];
        }
        if ($w['sia_number'] || $w['sia_expiry']) {
            $c = classify($w['sia_expiry']);
            $items[] = [
                'worker_id' => (int) $w['id'], 'worker' => $w['name'], 'division' => $w['division'],
                'type' => 'SIA licence', 'identifier' => $w['sia_number'],
                'expiry' => $w['sia_expiry'], 'status' => $c['status'], 'days' => $c['days'],
            ];
        }
    }

    // Extra certifications.
    $res = $conn->query(
        'SELECT c.worker_id, w.name AS worker, w.division, c.type, c.number, c.expiry
         FROM certifications c JOIN workers w ON w.id = c.worker_id
         WHERE w.deleted_at IS NULL'
    );
    while ($row = $res->fetch_assoc()) {
        $c = classify($row['expiry']);
        $items[] = [
            'worker_id' => (int) $row['worker_id'], 'worker' => $row['worker'], 'division' => $row['division'],
            'type' => $row['type'], 'identifier' => $row['number'],
            'expiry' => $row['expiry'], 'status' => $c['status'], 'days' => $c['days'],
        ];
    }

    // Sort soonest-to-expire first.
    usort($items, function ($a, $b) {
        return ($a['days'] ?? PHP_INT_MAX) <=> ($b['days'] ?? PHP_INT_MAX);
    });

    $summary = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'unknown' => 0];
    foreach ($items as $it) $summary[$it['status']]++;

    json_out(['summary' => $summary, 'items' => $items]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $workerId = (int) ($data['worker_id'] ?? 0);
    $type = trim((string) ($data['type'] ?? ''));
    $number = trim((string) ($data['number'] ?? ''));
    $expiry = trim((string) ($data['expiry'] ?? ''));

    if ($workerId <= 0 || $type === '') fail('A worker and certification type are required.');
    if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) fail('Expiry must be a date (YYYY-MM-DD).');

    // Worker must exist.
    $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Worker not found.', 404);

    $expiryVal = $expiry !== '' ? $expiry : null;
    $stmt = $conn->prepare('INSERT INTO certifications (worker_id, type, number, expiry) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $workerId, $type, $number, $expiryVal);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'certification.create', "$type for worker #$workerId");
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
