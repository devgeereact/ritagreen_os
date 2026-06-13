<?php
// Company insurance & certificate register.
// GET  /insurance.php                 -> { policies: [...], summary: {...} }
// POST /insurance.php {type, insurer, policy_no, cover_pence, start_date, expiry, notes} -> add
// POST /insurance.php?action=delete&id=N -> soft delete
//
// EL cover must be at least £5m to win most contracts; this register keeps the
// company-level proof in one place and feeds the renewal reminder engine.
require __DIR__ . '/lib.php';

$user = require_auth($conn);

// Classify an expiry date into a RAG status + days remaining (mirrors compliance.php).
function classify_ins(?string $expiry): array
{
    if (!$expiry) return ['status' => 'unknown', 'days' => null];
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) return ['status' => 'expired', 'days' => $days];
    if ($days <= 30) return ['status' => 'expiring', 'days' => $days];
    return ['status' => 'valid', 'days' => $days];
}

$EL_MIN_PENCE = 500000000; // £5,000,000

if (method() === 'GET') {
    $policies = [];
    $res = $conn->query(
        'SELECT id, type, insurer, policy_no, cover_pence, start_date, expiry, notes
         FROM insurance WHERE deleted_at IS NULL ORDER BY expiry IS NULL, expiry ASC'
    );
    $elOk = false;
    while ($r = $res->fetch_assoc()) {
        $c = classify_ins($r['expiry']);
        $policy = [
            'id' => (int) $r['id'], 'type' => $r['type'], 'insurer' => $r['insurer'],
            'policy_no' => $r['policy_no'], 'cover_pence' => (int) $r['cover_pence'],
            'start_date' => $r['start_date'], 'expiry' => $r['expiry'], 'notes' => $r['notes'],
            'status' => $c['status'], 'days' => $c['days'],
        ];
        if ($r['type'] === 'employers_liability' && $c['status'] !== 'expired'
            && (int) $r['cover_pence'] >= $EL_MIN_PENCE) {
            $elOk = true;
        }
        $policies[] = $policy;
    }
    json_out([
        'policies' => $policies,
        'summary' => ['el_minimum_met' => $elOk, 'el_minimum_pence' => $EL_MIN_PENCE],
    ]);
}

if (method() === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A policy id is required.');
    $stmt = $conn->prepare('UPDATE insurance SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'insurance.delete', "policy #$id");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $type = trim((string) ($data['type'] ?? ''));
    $insurer = trim((string) ($data['insurer'] ?? ''));
    $policyNo = trim((string) ($data['policy_no'] ?? ''));
    $cover = (int) ($data['cover_pence'] ?? 0);
    $start = trim((string) ($data['start_date'] ?? ''));
    $expiry = trim((string) ($data['expiry'] ?? ''));
    $notes = trim((string) ($data['notes'] ?? ''));

    $validTypes = ['employers_liability', 'public_liability', 'professional_indemnity', 'other'];
    if (!in_array($type, $validTypes, true)) fail('Choose a valid insurance type.');
    if ($insurer === '') fail('The insurer is required.');
    foreach (['start_date' => $start, 'expiry' => $expiry] as $label => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) fail("$label must be a date (YYYY-MM-DD).");
    }
    if ($cover < 0) fail('Cover cannot be negative.');

    $startVal = $start !== '' ? $start : null;
    $expiryVal = $expiry !== '' ? $expiry : null;
    $policyVal = $policyNo !== '' ? $policyNo : null;
    $notesVal = $notes !== '' ? $notes : null;

    $stmt = $conn->prepare(
        'INSERT INTO insurance (type, insurer, policy_no, cover_pence, start_date, expiry, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssisss', $type, $insurer, $policyVal, $cover, $startVal, $expiryVal, $notesVal);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'insurance.create', "$type — $insurer");
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
