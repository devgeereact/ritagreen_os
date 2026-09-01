<?php
// The Action Centre.
//
//   GET /action-centre.php            everything needing attention, grouped
//   GET /action-centre.php?mine=1     only what is assigned to, or about, me
//
// Answers one question: what needs attention today? The office should not have
// to visit fifteen screens to find out, and an owner should not have to know
// which screen a problem would appear on.
//
// Everything here is DERIVED. There is no "action" table to fall out of step
// with reality -- each item is a live query against the records that already
// exist, so an item cannot linger after the thing it describes is fixed.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
require_staff($user);
if (method() !== 'GET') fail('Method not allowed.', 405);

// Urgency, not category. The office needs to know what to do first, and a list
// sorted by which module a problem belongs to answers a question nobody asked.
const URGENT = 'urgent';   // somebody cannot work, or a duty is overdue
const TODAY  = 'today';    // work waiting on a person
const SOON   = 'soon';     // will become urgent if ignored

$items = [];
$add = static function (string $urgency, string $kind, string $title, string $detail, ?string $link = null, ?int $count = null) use (&$items): void {
    $items[] = [
        'urgency' => $urgency, 'kind' => $kind, 'title' => $title,
        'detail' => $detail, 'link' => $link, 'count' => $count,
    ];
};

$scalar = static function (mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    return $res ? (int) $res->fetch_row()[0] : 0;
};

// ---- Cannot work -----------------------------------------------------------
// The most urgent thing the system knows: somebody the office believes is active
// is not actually deployable.
$blocked = $conn->query(
    'SELECT p.full_name, ps.service, ps.eligibility_reason
     FROM person_services ps
     JOIN people p ON p.id = ps.person_id
     WHERE ps.eligibility_status = "not_eligible" AND ps.active = 1
     ORDER BY p.full_name LIMIT 25'
);
$blockedList = [];
while ($row = $blocked->fetch_assoc()) $blockedList[] = $row;
if ($blockedList) {
    $add(URGENT, 'eligibility',
        count($blockedList) . ' ' . (count($blockedList) === 1 ? 'person cannot' : 'people cannot') . ' be deployed',
        implode('; ', array_map(
            static fn(array $r): string => $r['full_name'] . ' (' . $r['service'] . '): ' . $r['eligibility_reason'],
            array_slice($blockedList, 0, 5)
        )),
        '/app/compliance', count($blockedList));
}

// ---- Expired and expiring credentials --------------------------------------
$expired = $scalar($conn,
    'SELECT COUNT(*) FROM certifications c JOIN workers w ON w.id = c.worker_id
     WHERE w.deleted_at IS NULL AND c.expiry IS NOT NULL AND c.expiry < CURDATE()');
if ($expired) $add(URGENT, 'compliance', "$expired expired " . ($expired === 1 ? 'credential' : 'credentials'),
    'Already lapsed. Anyone relying on one cannot be scheduled for work that requires it.', '/app/compliance', $expired);

$expiring = $scalar($conn,
    'SELECT COUNT(*) FROM certifications c JOIN workers w ON w.id = c.worker_id
     WHERE w.deleted_at IS NULL AND c.expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)');
if ($expiring) $add(SOON, 'compliance', "$expiring " . ($expiring === 1 ? 'credential expires' : 'credentials expire') . ' within 30 days',
    'Still valid today. Chase now and nobody is stood down later.', '/app/compliance', $expiring);

$siaExpired = $scalar($conn,
    'SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND sia_expiry IS NOT NULL AND sia_expiry < CURDATE()');
if ($siaExpired) $add(URGENT, 'compliance', "$siaExpired SIA " . ($siaExpired === 1 ? 'licence has' : 'licences have') . ' expired',
    'Security work cannot be assigned without a valid licence.', '/app/compliance', $siaExpired);

$dbsExpired = $scalar($conn,
    'SELECT COUNT(*) FROM workers WHERE deleted_at IS NULL AND dbs_expiry IS NOT NULL AND dbs_expiry < CURDATE()');
if ($dbsExpired) $add(URGENT, 'compliance', "$dbsExpired DBS " . ($dbsExpired === 1 ? 'check has' : 'checks have') . ' expired',
    'Care work cannot be assigned without a current check.', '/app/compliance', $dbsExpired);

// ---- Right to work ---------------------------------------------------------
$rtwUnverified = $scalar($conn,
    'SELECT COUNT(*) FROM right_to_work_checks r
     WHERE r.verified_at IS NULL AND r.outcome <> "invalid"
       AND r.id = (SELECT MAX(r2.id) FROM right_to_work_checks r2 WHERE r2.person_id = r.person_id)');
if ($rtwUnverified) $add(TODAY, 'right_to_work', "$rtwUnverified right-to-work " . ($rtwUnverified === 1 ? 'check needs' : 'checks need') . ' verifying',
    'Recorded but not yet signed off by a named person.', '/app/compliance', $rtwUnverified);

$rtwDue = $scalar($conn,
    'SELECT COUNT(*) FROM right_to_work_checks
     WHERE recheck_due IS NOT NULL AND recheck_due <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)');
if ($rtwDue) $add(SOON, 'right_to_work', "$rtwDue right-to-work " . ($rtwDue === 1 ? 'recheck is' : 'rechecks are') . ' due within 60 days',
    'Arrange these before the date, not after it.', '/app/compliance', $rtwDue);

// ---- Recruitment -----------------------------------------------------------
$newApps = $scalar($conn, 'SELECT COUNT(*) FROM applicants WHERE deleted_at IS NULL AND stage = "applied"');
if ($newApps) $add(TODAY, 'recruitment', "$newApps " . ($newApps === 1 ? 'application is' : 'applications are') . ' awaiting review',
    'Nobody has looked at these yet.', '/app/ats', $newApps);

$stalled = $scalar($conn,
    'SELECT COUNT(*) FROM applicants
     WHERE deleted_at IS NULL AND stage IN ("screened","interview","vetting","offer")
       AND updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)');
if ($stalled) $add(TODAY, 'recruitment', "$stalled " . ($stalled === 1 ? 'candidate has' : 'candidates have') . ' not moved in two weeks',
    'Mid-pipeline and going cold. Progress or close them.', '/app/ats', $stalled);

// ---- Unfilled work ---------------------------------------------------------
$openSoon = $scalar($conn,
    'SELECT COUNT(*) FROM shifts
     WHERE deleted_at IS NULL AND status = "open" AND starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)');
if ($openSoon) $add(URGENT, 'scheduling', "$openSoon unfilled " . ($openSoon === 1 ? 'shift starts' : 'shifts start') . ' within 3 days',
    'Nobody is assigned. These are the ones that become a phone call at 6am.', '/app/scheduling', $openSoon);

// ---- Governance and operations --------------------------------------------
$openIncidents = $scalar($conn, 'SELECT COUNT(*) FROM incidents WHERE deleted_at IS NULL AND status IN ("open","investigating")');
if ($openIncidents) $add(TODAY, 'incidents', "$openIncidents open " . ($openIncidents === 1 ? 'incident' : 'incidents'),
    'Including anything flagged RIDDOR or safeguarding.', '/app/incidents', $openIncidents);

$riddor = $scalar($conn, 'SELECT COUNT(*) FROM incidents WHERE deleted_at IS NULL AND riddor = 1 AND status <> "closed"');
if ($riddor) $add(URGENT, 'incidents', "$riddor RIDDOR-flagged " . ($riddor === 1 ? 'incident is' : 'incidents are') . ' still open',
    'These carry a reporting duty.', '/app/incidents', $riddor);

$unapproved = $scalar($conn, 'SELECT COUNT(*) FROM timesheets WHERE deleted_at IS NULL AND status = "pending"');
if ($unapproved) $add(TODAY, 'payroll', "$unapproved " . ($unapproved === 1 ? 'timesheet is' : 'timesheets are') . ' awaiting approval',
    'Unapproved hours do not reach a pay run.', '/app/timesheets', $unapproved);

$overdue = $scalar($conn,
    'SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND status IN ("sent","overdue") AND due_date IS NOT NULL AND due_date < CURDATE()');
if ($overdue) $add(TODAY, 'invoicing', "$overdue overdue " . ($overdue === 1 ? 'invoice' : 'invoices'),
    'Past their due date and unpaid.', '/app/invoicing', $overdue);

$newEnquiries = $scalar($conn, 'SELECT COUNT(*) FROM contact_enquiries WHERE status = "new"');
if ($newEnquiries) $add(TODAY, 'enquiries', "$newEnquiries unanswered " . ($newEnquiries === 1 ? 'enquiry' : 'enquiries'),
    'Somebody asked and has not heard back.', '/app/enquiries', $newEnquiries);

// ---- The system itself -----------------------------------------------------
// If email stopped going out, that is not visible anywhere else, and the first
// symptom is somebody saying they never heard from us.
$failedMail = $scalar($conn, 'SELECT COUNT(*) FROM email_queue WHERE status = "failed"');
if ($failedMail) $add(URGENT, 'system', "$failedMail " . ($failedMail === 1 ? 'email has' : 'emails have') . ' failed to send',
    'Gave up after repeated attempts. Somebody was not told something.', '/app/settings', $failedMail);

$stuckMail = $scalar($conn,
    'SELECT COUNT(*) FROM email_queue WHERE status = "queued" AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)');
if ($stuckMail) $add(URGENT, 'system', "$stuckMail " . ($stuckMail === 1 ? 'email has' : 'emails have') . ' been queued over 2 hours',
    'The queue is not being drained. Check the send-queued-mail cron.', '/app/settings', $stuckMail);

// An integration that has gone quiet looks exactly like a quiet week. This is
// the difference between noticing in a day and noticing when a candidate rings
// up asking why nobody replied.
$silent = $conn->query(
    'SELECT name, TIMESTAMPDIFF(HOUR, last_success_at, NOW()) AS hours
     FROM integrations
     WHERE active = 1 AND secret_hash IS NOT NULL AND expect_within_hours IS NOT NULL
       AND (last_success_at IS NULL OR TIMESTAMPDIFF(HOUR, last_success_at, NOW()) > expect_within_hours)'
);
$silentList = [];
while ($row = $silent->fetch_assoc()) {
    $silentList[] = $row['name'] . ($row['hours'] === null ? ' (nothing ever received)' : " ({$row['hours']}h ago)");
}
if ($silentList) $add(URGENT, 'system',
    count($silentList) . ' ' . (count($silentList) === 1 ? 'integration has' : 'integrations have') . ' gone quiet',
    'Nothing received within the expected window: ' . implode('; ', $silentList),
    '/app/settings', count($silentList));

$failedIntake = $scalar($conn, 'SELECT COUNT(*) FROM integration_submissions WHERE status = "failed"');
if ($failedIntake) $add(TODAY, 'system', "$failedIntake form " . ($failedIntake === 1 ? 'submission' : 'submissions') . ' could not be mapped',
    'Somebody applied and is not in the pipeline. The raw answers are kept, so fixing the mapping recovers them.',
    '/app/settings', $failedIntake);

$unlinked = $scalar($conn, 'SELECT COUNT(*) FROM enrolments WHERE person_id IS NULL');
if ($unlinked) $add(SOON, 'system', "$unlinked training " . ($unlinked === 1 ? 'record is' : 'records are') . ' not linked to a person',
    'Enrolments carry only a learner name, so these are linked by hand.', '/app/academy', $unlinked);

// ---- Order and return ------------------------------------------------------
$rank = [URGENT => 0, TODAY => 1, SOON => 2];
usort($items, static fn(array $a, array $b): int => $rank[$a['urgency']] <=> $rank[$b['urgency']]);

$counts = [URGENT => 0, TODAY => 0, SOON => 0];
foreach ($items as $item) $counts[$item['urgency']]++;

json_out([
    'items' => $items,
    'counts' => $counts,
    'total' => count($items),
    // A quiet day is a real answer, and worth saying out loud.
    'all_clear' => $items === [],
    'generated_at' => date('Y-m-d H:i'),
]);
