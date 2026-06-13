<?php
// Margin reconciliation — pay vs charge, per division and overall.
// GET /margin.php?from=YYYY-MM-DD&to=YYYY-MM-DD
//   -> { rows: [{division, revenue_pence, cost_pence, margin_pence, margin_pct}], totals: {...} }
//
// Revenue  = invoice NET (ex-VAT) raised in the period, less credit notes,
//            excluding void/draft invoices.
// Cost     = labour gross from approved timesheets in the period (pay-lib.php),
//            so premiums and overtime are reflected in the cost of delivery.
require __DIR__ . '/lib.php';
require __DIR__ . '/pay-lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
if (!pay_valid_date($from) || !pay_valid_date($to)) fail('A valid from and to date are required (YYYY-MM-DD).');
if ($from > $to) fail('The "from" date must be on or before the "to" date.');

$divisions = ['care', 'academy', 'cleaning', 'security'];
$rows = [];
foreach ($divisions as $d) $rows[$d] = ['division' => $d, 'revenue_pence' => 0, 'cost_pence' => 0];

// Revenue: net invoiced in range, less credits, per division.
$stmt = $conn->prepare(
    'SELECT i.division,
            SUM(i.net_pence) AS net,
            COALESCE(SUM((SELECT COALESCE(SUM(cn.amount_pence),0) FROM credit_notes cn WHERE cn.invoice_id = i.id)), 0) AS credited
     FROM invoices i
     WHERE i.status NOT IN ("void","draft") AND DATE(i.created_at) BETWEEN ? AND ?
     GROUP BY i.division'
);
$stmt->bind_param('ss', $from, $to);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    if (!isset($rows[$r['division']])) continue;
    // Credits are gross (incl VAT); approximate the net portion at the 20% rate.
    $creditNet = (int) round(((int) $r['credited']) / 1.20);
    $rows[$r['division']]['revenue_pence'] = max(0, (int) $r['net'] - $creditNet);
}

// Cost: labour gross by division from the shared pay engine.
$pay = pay_compute($conn, $from, $to);
foreach ($pay['workers'] as $w) {
    if (!isset($rows[$w['division']])) continue;
    $rows[$w['division']]['cost_pence'] += (int) $w['gross_pence'];
}

$totals = ['revenue_pence' => 0, 'cost_pence' => 0];
$out = [];
foreach ($divisions as $d) {
    $rev = $rows[$d]['revenue_pence'];
    $cost = $rows[$d]['cost_pence'];
    $margin = $rev - $cost;
    $out[] = [
        'division' => $d,
        'revenue_pence' => $rev,
        'cost_pence' => $cost,
        'margin_pence' => $margin,
        'margin_pct' => $rev > 0 ? round($margin / $rev * 100, 1) : null,
    ];
    $totals['revenue_pence'] += $rev;
    $totals['cost_pence'] += $cost;
}
$totals['margin_pence'] = $totals['revenue_pence'] - $totals['cost_pence'];
$totals['margin_pct'] = $totals['revenue_pence'] > 0
    ? round($totals['margin_pence'] / $totals['revenue_pence'] * 100, 1) : null;

json_out(['period' => ['from' => $from, 'to' => $to], 'rows' => $out, 'totals' => $totals]);
