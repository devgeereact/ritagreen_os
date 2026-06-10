<?php
// Compliance & certification engine.
// GET  /compliance.php            -> { summary: {...}, items: [...] }
// POST /compliance.php {worker_id, type, number, expiry} -> add a certification
require __DIR__ . '/lib.php';

$user = require_auth($conn);

// Classify an expiry date into a RAG status + days remaining.
function classify(?string $expiry): array
{
    if (!$expiry) return ['status' => 'unknown', 'days' => null];
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['status' => 'expired', 'days' => $days];
    if ($days <= 30) return ['status' => 'expiring', 'days' => $days];
    return ['status' => 'valid', 'days' => $days];
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
