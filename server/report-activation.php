<?php
// Who would be blocked from work by the activation gate, and why.
//
//     php server/report-activation.php
//
// Read-only. It writes nothing and changes nothing.
//
// Migration 034 made approval a requirement and rg_employment_gate() enforces it
// alongside suspension, leaver state and outstanding onboarding. That is a change
// in enforcement against a workforce that already exists, and the rule for those
// is: report the affected records first. A policy change that quietly stands
// people down is indistinguishable from an outage, except that nobody is looking
// for the cause.
//
// Run this before and after applying 034. The migration backfills approval for
// everybody already marked active, so the "not approved" list should contain only
// people who genuinely are not.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("report-activation.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

echo "Database: " . DB_NAME . " on " . DB_HOST . (rg_is_local_database() ? " (local)" : " (REMOTE)") . "\n\n";

$res = $conn->query(
    'SELECT id, person_id, name, division, status, left_on, approved_at, approval_note, deleted_at
       FROM workers WHERE deleted_at IS NULL ORDER BY division, name'
);

$blocked = [];
$clear = 0;
$total = 0;

while ($w = $res->fetch_assoc()) {
    $total++;
    $gate = rg_employment_gate($w, [
        'onboarding_outstanding' => rg_onboarding_outstanding($conn, (int) $w['person_id']),
    ]);
    if ($gate['ok']) { $clear++; continue; }
    $blocked[] = $w + ['reason' => $gate['reason']];
}

echo "Workers (not archived): $total\n";
echo "Pass the employment gate: $clear\n";
echo "Blocked: " . count($blocked) . "\n\n";

if (!$blocked) {
    echo "Nobody is blocked by employment state.\n";
    exit(0);
}

// Grouped by reason, because the reasons need different people to act on them:
// an unapproved worker is a decision waiting to be made, an outstanding checklist
// is work waiting to be done, and a suspension is neither.
$byReason = [];
foreach ($blocked as $w) {
    $key = preg_replace('/\d+/', 'N', $w['reason']);
    $byReason[$key][] = $w;
}

foreach ($byReason as $reason => $rows) {
    echo $reason . '  (' . count($rows) . ")\n";
    foreach ($rows as $w) {
        echo sprintf("    #%-5d %-24s %-10s %s\n",
            $w['id'], $w['name'], $w['division'], $w['status']);
    }
    echo "\n";
}

echo "Nothing here has been changed. Approve a worker through the application, or\n";
echo "finish the onboarding the report names, and re-run this to confirm.\n";
