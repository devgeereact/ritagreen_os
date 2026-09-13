<?php
// Retention sweep.
//
//     php server/retention-sweep.php              report only (the default)
//     php server/retention-sweep.php --go         act on what it reports
//     wget -qO- "https://…/retention-sweep.php?key=ALERTS_KEY"   report only
//
// Nothing in this system has ever disposed of anything on a schedule. The
// retention_policies table has existed since migration 010 with eight record
// types and no consumer at all, so a policy could be configured and would do
// nothing. This is the consumer.
//
// Three things about it are deliberate.
//
// It reports by default. Acting takes --go, from the command line, in the same
// shape as migrate.php and backfill-people.php. A cron that deletes on its
// first run because somebody typed a number into Settings is not a feature.
//
// It acts only where a period is set. All eight seeded policies have
// retain_months NULL, and NULL means "the business has not decided", not zero.
// On the day this ships it therefore finds nothing and changes nothing, which
// is the correct behaviour for a mechanism whose values are still with the
// company's advisers.
//
// It never hard-deletes. 'review' raises it for a person. 'archive' sets
// deleted_at, which every list query already filters on. 'delete' opens a
// request in the four-eyes workflow in governance.php and stops there — the
// approval is a person's, and a sweep that could remove an employment record
// unattended would be a worse problem than the one it solves.
require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
$args = $argv ?? [];
$go = $isCli && in_array('--go', $args, true);

if (!$isCli) {
    rate_limit($conn, 'cron-key', 10, 600);
    $expected = defined('ALERTS_KEY') ? ALERTS_KEY : '';
    if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, (string) ($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$today = date('Y-m-d');

/** "1 month" / "18 months" — a reason line that reads like English. */
function months_phrase(int $n): string
{
    return $n . ' month' . ($n === 1 ? '' : 's');
}

/**
 * Which table each policy governs, what to count from, and how to name a row.
 *
 * The anchor is the date the clock runs from, and it is not always created_at:
 * an employment record is kept for a period after the person leaves, not after
 * the record was made. A policy with no entry here is reported as unmapped
 * rather than quietly skipped — an unmapped policy looks like it is working
 * from the Settings screen, which is the worst of both.
 */
const SWEEPABLE = [
    'contact_enquiries' => [
        'table' => 'contact_enquiries', 'anchor' => 'created_at', 'label' => 'name',
        'entity' => null,
    ],
    'service_requests' => [
        'table' => 'service_requests', 'anchor' => 'created_at', 'label' => 'contact_name',
        'entity' => null,
    ],
    'form_submissions' => [
        'table' => 'integration_submissions', 'anchor' => 'received_at', 'label' => 'external_id',
        'entity' => null,
    ],
    'data_requests' => [
        'table' => 'data_requests', 'anchor' => 'completed_at', 'label' => 'ref',
        'entity' => null,
    ],
    'candidate_unsuccessful' => [
        'table' => 'applicants', 'anchor' => 'updated_at', 'label' => 'name',
        'where' => "stage = 'rejected'", 'entity' => 'applicant',
    ],
    'attendance_events' => [
        // Clock-in and clock-out times, check calls, location and signature
        // names. Anchored on when the event happened; there is nothing later to
        // anchor on, and an attendance record is only useful for as long as a
        // pay query or an incident might reach back to it.
        'table' => 'shift_events', 'anchor' => 'created_at', 'label' => 'kind',
        'entity' => null,
    ],
    'worker_employment' => [
        'table' => 'workers', 'anchor' => 'left_on', 'label' => 'name',
        'where' => 'left_on IS NOT NULL', 'entity' => 'worker',
    ],
];

$policies = [];
$res = $conn->query('SELECT record_type, label, retain_months, action_at_end, active FROM retention_policies');
while ($row = $res->fetch_assoc()) $policies[] = $row;

$lines = [];
$totals = ['due' => 0, 'archived' => 0, 'requested' => 0, 'review' => 0];
$unset = [];
$unmapped = [];

foreach ($policies as $p) {
    $type = (string) $p['record_type'];
    $months = $p['retain_months'] === null ? null : (int) $p['retain_months'];
    $action = rg_retention_action((string) $p['action_at_end']);

    if (!(int) $p['active']) continue;
    if ($months === null) { $unset[] = $type; continue; }
    if (!isset(SWEEPABLE[$type])) { $unmapped[] = $type; continue; }

    $spec = SWEEPABLE[$type];
    $table = $spec['table'];
    $anchor = $spec['anchor'];
    $labelCol = $spec['label'];

    $hasDeletedAt = (bool) $conn->query("SHOW COLUMNS FROM `$table` LIKE 'deleted_at'")->num_rows;

    $where = ["`$anchor` IS NOT NULL"];
    if (!empty($spec['where'])) $where[] = $spec['where'];
    if ($hasDeletedAt) $where[] = 'deleted_at IS NULL';
    $sql = "SELECT id, `$anchor` AS anchor_date, `$labelCol` AS label FROM `$table` WHERE "
        . implode(' AND ', $where) . ' ORDER BY id';

    $rows = [];
    $r = $conn->query($sql);
    while ($row = $r->fetch_assoc()) {
        // The comparison is in pure.php and tested there, rather than expressed
        // as a DATE_SUB in the query where it cannot be.
        if (rg_retention_due(substr((string) $row['anchor_date'], 0, 10), $months, $today)) $rows[] = $row;
    }

    $count = count($rows);
    $totals['due'] += $count;
    $lines[] = sprintf('%-24s %-8s %11s  %4d due', $type, $action, months_phrase($months), $count);
    if ($count === 0) continue;

    if ($action === 'review') {
        $totals['review'] += $count;
        if ($go) {
            notify(
                $conn, null,
                "Retention review: $count " . $p['label'],
                "$count record(s) have passed their retention period and need a decision.",
                'warning', '/app/settings'
            );
        }
        continue;
    }

    if ($action === 'archive') {
        if (!$hasDeletedAt) {
            $lines[] = "  ! $table has no deleted_at column — cannot archive, reporting only";
            continue;
        }
        if ($go) {
            $stmt = $conn->prepare("UPDATE `$table` SET deleted_at = NOW() WHERE id = ?");
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $stmt->bind_param('i', $id);
                $stmt->execute();
                audit($conn, null, 'retention.archive', "$table #$id", $type, $id, null, null,
                    'Past the configured retention period of ' . months_phrase($months) . '.');
            }
        }
        $totals['archived'] += $count;
        continue;
    }

    // action === 'delete'. This raises a request. It does not delete.
    if ($spec['entity'] === null) {
        $lines[] = "  ! $type is set to delete but has no entity type in the approval workflow — reporting only";
        continue;
    }
    if ($go) {
        $check = $conn->prepare('SELECT id FROM deletion_requests WHERE entity_type = ? AND entity_id = ? AND status IN ("requested","approved") LIMIT 1');
        $insert = $conn->prepare(
            'INSERT INTO deletion_requests (entity_type, entity_id, entity_label, reason, disposition, requested_by)
             VALUES (?, ?, ?, ?, "delete", NULL)'
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $entity = (string) $spec['entity'];
            $check->bind_param('si', $entity, $id);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) continue;  // already open
            $label = substr((string) $row['label'], 0, 255);
            $reason = 'Retention sweep: past the configured period of ' . months_phrase($months) . " for $type.";
            $insert->bind_param('siss', $entity, $id, $label, $reason);
            $insert->execute();
            audit($conn, null, 'retention.request_deletion', "$entity #$id ($label)", $entity, $id, null, null, $reason);
            $totals['requested']++;
        }
    } else {
        $totals['requested'] += $count;
    }
}

echo "Retention sweep — $today\n";
echo "Database: " . DB_NAME . ' on ' . DB_HOST . "\n\n";

if ($lines) {
    echo "  record type              action    period       due\n";
    echo "  " . str_repeat('-', 56) . "\n";
    foreach ($lines as $l) echo '  ' . $l . "\n";
    echo "\n";
} else {
    echo "  Nothing to sweep: no active policy has a period set.\n\n";
}

if ($unset) {
    echo 'No period set (' . count($unset) . "), so nothing was considered:\n";
    foreach ($unset as $t) echo "  - $t\n";
    echo "  These are the business's to set, with advice. Until then they are kept.\n\n";
}
if ($unmapped) {
    echo 'Configured but not yet swept (' . count($unmapped) . "):\n";
    foreach ($unmapped as $t) echo "  - $t\n";
    echo "  A period is set but this script does not know which rows it governs.\n";
    echo "  Add it to SWEEPABLE, or the policy is decorative.\n\n";
}

printf(
    "%s: %d past their period — %d for review, %d archived, %d deletion request(s).\n",
    $go ? 'Applied' : 'Dry run',
    $totals['due'], $totals['review'], $totals['archived'], $totals['requested']
);
if (!$go) echo "\nNothing was changed. Re-run from the command line with --go to act.\n";
