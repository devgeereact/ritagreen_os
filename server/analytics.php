<?php
// Analytics & business intelligence.
// GET /analytics.php -> aggregates across the group for the BI dashboard.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
if (method() !== 'GET') fail('Method not allowed.', 405);

$divisions = ['care', 'academy', 'cleaning', 'security'];

function scalar(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    return (int) ($res->fetch_row()[0] ?? 0);
}

// Revenue by division (paid invoices, pence).
$revenue_by_division = [];
foreach ($divisions as $d) {
    $revenue_by_division[] = [
        'division' => $d,
        'pence' => scalar($conn, 'SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE status = "paid" AND division = "' . $conn->real_escape_string($d) . '"'),
    ];
}

// Monthly revenue trend — last 6 months of paid invoices.
$monthly_revenue = [];
$res = $conn->query(
    'SELECT DATE_FORMAT(updated_at, "%Y-%m") AS month, COALESCE(SUM(total_pence),0) AS pence
     FROM invoices WHERE status = "paid" AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY month ORDER BY month'
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $monthly_revenue[] = ['month' => $row['month'], 'pence' => (int) $row['pence']];
    }
}

// Jobs by status.
$jobs_by_status = [];
$res = $conn->query('SELECT status, COUNT(*) AS n FROM jobs WHERE deleted_at IS NULL GROUP BY status');
if ($res) {
    while ($row = $res->fetch_assoc()) $jobs_by_status[$row['status']] = (int) $row['n'];
}

// Quotes: counts by status + win rate.
$quote_counts = ['draft' => 0, 'sent' => 0, 'accepted' => 0, 'declined' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS n FROM quotes GROUP BY status');
if ($res) {
    while ($row = $res->fetch_assoc()) $quote_counts[$row['status']] = (int) $row['n'];
}
$decided = $quote_counts['accepted'] + $quote_counts['declined'];
$win_rate = $decided > 0 ? round(($quote_counts['accepted'] / $decided) * 100) : 0;

// Workforce by division.
$workforce_by_division = [];
foreach ($divisions as $d) {
    $workforce_by_division[] = [
        'division' => $d,
        'count' => scalar($conn, 'SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND division = "' . $conn->real_escape_string($d) . '"'),
    ];
}

// Compliance RAG (DBS + SIA on workers).
$rag = ['valid' => 0, 'expiring' => 0, 'expired' => 0];
$res = $conn->query('SELECT dbs_expiry, sia_expiry FROM workers WHERE deleted_at IS NULL');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        foreach ([$row['dbs_expiry'], $row['sia_expiry']] as $exp) {
            if (!$exp) continue;
            $days = (int) floor((strtotime($exp) - strtotime(date('Y-m-d'))) / 86400);
            if ($days < 0) $rag['expired']++;
            elseif ($days <= 30) $rag['expiring']++;
            else $rag['valid']++;
        }
    }
}

json_out([
    'revenue_by_division' => $revenue_by_division,
    'monthly_revenue' => $monthly_revenue,
    'jobs_by_status' => $jobs_by_status,
    'quotes' => ['counts' => $quote_counts, 'win_rate' => $win_rate],
    'workforce_by_division' => $workforce_by_division,
    'compliance_rag' => $rag,
    'totals' => [
        'clients' => scalar($conn, 'SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL'),
        'workers' => scalar($conn, 'SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL'),
        'paid_revenue_pence' => scalar($conn, 'SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE status = "paid"'),
        'outstanding_pence' => scalar($conn, 'SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE status IN ("sent","overdue")'),
    ],
]);
