<?php
// Contracts & recurring services.
// GET  /contracts.php                    -> { contracts: [...], totals: {...} }
// POST /contracts.php {title, division, rate_pence, frequency, start_date, end_date, client_id?, scope?, sla_hours?}
// POST /contracts.php?action=status&id=5 {status}
// POST /contracts.php?action=renew&id=5  {months}  -> extend the term
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Contract terms and annualised values. Reads were unguarded.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

// Annualised value of a contract, in pence, for pipeline totals.
function annualise(int $ratePence, string $freq): int
{
    $perYear = ['weekly' => 52, 'fortnightly' => 26, 'monthly' => 12, 'quarterly' => 4];
    return $ratePence * ($perYear[$freq] ?? 12);
}

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT ct.id, ct.client_id, c.name AS client, ct.division, ct.title, ct.scope,
                ct.rate_pence, ct.frequency, ct.sla_hours, ct.start_date, ct.end_date, ct.status,
                DATEDIFF(ct.end_date, CURDATE()) AS days_to_renewal
         FROM contracts ct LEFT JOIN clients c ON c.id = ct.client_id
         WHERE ct.deleted_at IS NULL ORDER BY ct.end_date'
    );
    $contracts = [];
    $annualActive = 0;
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['rate_pence'] = (int) $row['rate_pence'];
        $row['sla_hours'] = $row['sla_hours'] !== null ? (int) $row['sla_hours'] : null;
        $row['days_to_renewal'] = (int) $row['days_to_renewal'];
        if ($row['status'] === 'active') $annualActive += annualise($row['rate_pence'], $row['frequency']);
        $contracts[] = $row;
    }
    json_out(['contracts' => $contracts, 'totals' => ['annual_active_pence' => $annualActive]]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['active', 'expired', 'cancelled'], true)) fail('A contract and valid status are required.');
    $stmt = $conn->prepare('UPDATE contracts SET status = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'contracts', $id)) fail('Contract not found.', 404);
    audit($conn, (int) $user['id'], 'contract.status', "#$id -> $status");
    json_out(['ok' => true]);
}

if ($action === 'renew' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $months = (int) (body()['months'] ?? 12);
    if ($id <= 0) fail('A contract is required.');
    if ($months < 1 || $months > 60) $months = 12;
    $stmt = $conn->prepare(
        'UPDATE contracts SET end_date = DATE_ADD(GREATEST(end_date, CURDATE()), INTERVAL ? MONTH),
                status = "active" WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ii', $months, $id);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'contracts', $id)) fail('Contract not found.', 404);
    audit($conn, (int) $user['id'], 'contract.renew', "#$id +{$months}m");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $scope = trim((string) ($data['scope'] ?? ''));
    $rate = (int) round((float) ($data['rate_pence'] ?? 0));
    $freq = trim((string) ($data['frequency'] ?? 'monthly'));
    $sla = isset($data['sla_hours']) && $data['sla_hours'] !== '' ? (int) $data['sla_hours'] : null;
    $start = trim((string) ($data['start_date'] ?? ''));
    $end = trim((string) ($data['end_date'] ?? ''));

    if ($title === '') fail('A contract title is required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if (!in_array($freq, ['weekly', 'fortnightly', 'monthly', 'quarterly'], true)) fail('Invalid frequency.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) fail('Start and end must be dates.');
    if ($end < $start) fail('The end date must be after the start date.');
    if ($rate < 0) fail('Rate cannot be negative.');

    // A client_id that does not exist reached the INSERT and came back as a
    // foreign-key 500 with the constraint name in it, plus a logged exception,
    // for what is only a stale id from a page left open.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    $scopeVal = $scope !== '' ? $scope : null;
    $stmt = $conn->prepare(
        'INSERT INTO contracts (client_id, division, title, scope, rate_pence, frequency, sla_hours, start_date, end_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
    );
    $stmt->bind_param('isssisiss', $clientId, $division, $title, $scopeVal, $rate, $freq, $sla, $start, $end);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'contract.create', $title);
    json_out(['contract' => ['id' => (int) $id, 'title' => $title]], 201);
}

fail('Method not allowed.', 405);
