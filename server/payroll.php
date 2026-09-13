<?php
// Payroll — turns approved timesheets into a pay run and individual payslips.
// GET /payroll.php?from=YYYY-MM-DD&to=YYYY-MM-DD            -> { rows: [...], totals: {...}, rules: {...} }
// GET /payroll.php?action=payslip&worker_id=N&from=&to=     -> { worker, lines: [...], totals: {...} }
//
// Pay model lives in pay-lib.php (shared with margin.php):
//  - Base = approved hours x worker pay_rate_pence.
//  - Day premium = highest applicable uplift per entry: bank holiday > weekend > night.
//  - Overtime = hours beyond 40 in an ISO week, at max(overtime, day premium).
//  - Holiday accrual = 12.07% of worked hours, valued at base rate.
// Only 'approved' timesheets are included.
require __DIR__ . '/lib.php';
require __DIR__ . '/pay-lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
// A pay run is the payroll zone by definition. workers.php already hides a
// single worker's pay_rate_pence behind this zone, so the endpoint that returns
// every worker's gross must ask the same question -- otherwise the narrower
// screen is the only one enforcing it.
if (!can_view_zone($conn, $user, 'payroll')) fail('You do not have access to this.', 403);

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if (!pay_valid_date($from) || !pay_valid_date($to)) fail('A valid from and to date are required (YYYY-MM-DD).');
if ($from > $to) fail('The "from" date must be on or before the "to" date.');

$action = $_GET['action'] ?? '';

if ($action === 'payslip') {
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    if ($workerId <= 0) fail('A worker is required.');

    $stmt = $conn->prepare('SELECT id, name, division, role, pay_type, pay_rate_pence FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    $w = $stmt->get_result()->fetch_assoc();
    if (!$w) fail('Worker not found.', 404);

    $out = pay_compute($conn, $from, $to, $workerId);
    $agg = $out['workers'][0] ?? [
        'hours' => 0, 'base_pence' => 0, 'premium_pence' => 0, 'gross_pence' => 0,
        'holiday_hours' => 0, 'holiday_pay_pence' => 0,
    ];

    audit($conn, (int) $user['id'], 'payroll.payslip', "worker #$workerId $from..$to");
    json_out([
        'worker' => [
            'id' => (int) $w['id'], 'name' => $w['name'], 'division' => $w['division'],
            'role' => $w['role'], 'pay_type' => $w['pay_type'], 'pay_rate_pence' => (int) $w['pay_rate_pence'],
        ],
        'period' => ['from' => $from, 'to' => $to],
        'lines' => $out['lines'],
        'totals' => [
            'hours' => $agg['hours'], 'base_pence' => $agg['base_pence'],
            'premium_pence' => $agg['premium_pence'], 'gross_pence' => $agg['gross_pence'],
            'holiday_hours' => $agg['holiday_hours'], 'holiday_pay_pence' => $agg['holiday_pay_pence'],
        ],
    ]);
}

// Full pay run.
$out = pay_compute($conn, $from, $to);
$totals = ['hours' => 0.0, 'base_pence' => 0, 'premium_pence' => 0, 'gross_pence' => 0, 'holiday_pay_pence' => 0];
foreach ($out['workers'] as $w) {
    $totals['hours'] += $w['hours'];
    $totals['base_pence'] += $w['base_pence'];
    $totals['premium_pence'] += $w['premium_pence'];
    $totals['gross_pence'] += $w['gross_pence'];
    $totals['holiday_pay_pence'] += $w['holiday_pay_pence'];
}
$totals['hours'] = round($totals['hours'], 2);

audit($conn, (int) $user['id'], 'payroll.run', "$from..$to");
json_out([
    'period' => ['from' => $from, 'to' => $to],
    'rows' => $out['workers'],
    'totals' => $totals,
    'rules' => $out['rules'],
    'floor_pence' => $out['floor_pence'],
]);
