<?php
// Unified Command Centre summary.
// GET /dashboard.php?division=all|care|academy|cleaning|security -> DashboardSummary
require __DIR__ . '/lib.php';

require_auth($conn);
if (method() !== 'GET') fail('Method not allowed.', 405);

$division = $_GET['division'] ?? 'all';
$allowed = ['all', 'care', 'academy', 'cleaning', 'security'];
if (!in_array($division, $allowed, true)) $division = 'all';

// Single scalar from a query, with the division bound rather than interpolated.
//
// $division is already checked against an allowlist above, so the old
// real_escape_string version was not injectable -- but the guarantee depended on
// remembering that check, and the next person to add a filter here would have
// had a pattern to copy that does not carry it. See docs/RULES.md rule 10.
function scalar(mysqli $conn, string $sql, ?string $division = null): int
{
    if ($division === null) {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        return $row ? (int) $row[0] : 0;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $division);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row ? (int) $row[0] : 0;
}

// The filter clause, and the value to bind into it. null means "no filter".
$divFilter = $division === 'all' ? '' : ' AND division = ?';
$divValue = $division === 'all' ? null : $division;

$active_programmes = scalar($conn, 'SELECT COUNT(*) FROM programmes WHERE status = "published"' .
    $divFilter, $divValue);
$enrolments = scalar($conn, 'SELECT COUNT(*) FROM enrolments');
$upcoming_sessions = scalar($conn, 'SELECT COUNT(*) FROM sessions WHERE starts_at > NOW()');
$certificates_issued = scalar($conn, 'SELECT COUNT(*) FROM certificates WHERE status = "issued"');
// Open shifts, not trainer-less training sessions. This counted FROM sessions
// (the Academy table) rather than FROM shifts, so the tile had always shown the
// wrong number. shifts.status = 'open' is the field that means unfilled.
$open_shifts = scalar($conn, 'SELECT COUNT(*) FROM shifts WHERE deleted_at IS NULL AND status = "open" AND starts_at > NOW()' .
    $divFilter, $divValue);

$outstanding = scalar($conn, 'SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE deleted_at IS NULL AND status IN ("sent","overdue")' .
    $divFilter, $divValue);

// Revenue by division (paid invoices).
$revenue = [];
$divisions = $division === 'all' ? ['care', 'academy', 'cleaning', 'security'] : [$division];
foreach ($divisions as $d) {
    $pence = scalar($conn, 'SELECT COALESCE(SUM(total_pence),0) FROM invoices WHERE deleted_at IS NULL AND status = "paid" AND division = ?', $d);
    $revenue[] = ['division' => $d, 'pence' => $pence];
}

// Compliance alerts: DBS or SIA expiring within 30 days.
$alerts = [];
$sql = 'SELECT name, dbs_expiry, sia_expiry FROM workers
        WHERE deleted_at IS NULL' . $divFilter . '
        AND ((dbs_expiry IS NOT NULL AND dbs_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
          OR (sia_expiry IS NOT NULL AND sia_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)))
        ORDER BY LEAST(COALESCE(dbs_expiry, "9999-12-31"), COALESCE(sia_expiry, "9999-12-31")) LIMIT 10';
if ($divValue === null) {
    $res = $conn->query($sql);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $divValue);
    $stmt->execute();
    $res = $stmt->get_result();
}
if ($res) {
    while ($row = $res->fetch_assoc()) {
        foreach ([['DBS check', $row['dbs_expiry']], ['SIA licence', $row['sia_expiry']]] as [$label, $exp]) {
            if (!$exp) continue;
            $days = (int) floor((strtotime($exp) - time()) / 86400);
            if ($days >= 0 && $days <= 30) {
                $alerts[] = ['worker' => $row['name'], 'item' => "$label expires", 'days' => $days];
            }
        }
    }
}

json_out([
    'active_programmes' => $active_programmes,
    'enrolments' => $enrolments,
    'upcoming_sessions' => $upcoming_sessions,
    'certificates_issued' => $certificates_issued,
    'outstanding_invoices_pence' => $outstanding,
    'open_shifts' => $open_shifts,
    'revenue_by_division' => $revenue,
    'compliance_alerts' => $alerts,
]);
