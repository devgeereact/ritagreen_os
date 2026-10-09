<?php
// The Action Centre: what needs doing now. The Today page is built on it.
//
//   GET /action-centre.php   -> { tiles, items, counts, total, all_clear, scope, generated_at }
//
// Answers one question: what needs attention today? The office should not have
// to visit fifteen screens to find out, and an owner should not have to know
// which screen a problem would appear on.
//
// Everything here is DERIVED. There is no "action" table to fall out of step
// with reality -- each item is a live query against the records that already
// exist, so an item cannot linger after the thing it describes is fixed.
//
// Every count obeys the same boundaries as the screen it links to (launch plan
// Phase 5: "Do not expose the existing Action Centre until its aggregate queries
// obey those boundaries"). It did not: every count was over the whole company
// for every member of staff, so a manager confined to security was told how many
// care credentials had expired and who in care could not be deployed, and an
// editor with no compliance zone read the same. Two rules now apply throughout:
//
//   - which groups an account sees is rg_action_centre_access() in pure.php,
//     mirroring the guard on the endpoint each group links to;
//   - a scoped account's counts are filtered by service in the query (records
//     with a service column) or by rg_person_in_scope() (records about a person),
//     and a record with no service is out of scope, as everywhere else.
//
// Counts are over the whole filtered dataset. Names are only ever listed for
// person-based items, at most five, as links to the profile.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
require_staff($user);
if (method() !== 'GET') fail('Method not allowed.', 405);

$scope = service_scope($user);
$can = rg_action_centre_access(
    (string) $user['role'],
    $scope,
    [
        'compliance' => can_view_zone($conn, $user, 'compliance'),
        'payroll' => can_view_zone($conn, $user, 'payroll'),
    ],
    ['finance' => feature_enabled($conn, 'finance')]
);

// Urgency, not category. The office needs to know what to do first, and a list
// sorted by which module a problem belongs to answers a question nobody asked.
const URGENT = 'urgent';   // somebody cannot work, or a duty is overdue
const TODAY  = 'today';    // work waiting on a person
const SOON   = 'soon';     // will become urgent if ignored

$items = [];
/**
 * @param list<array{id:int,name:string}> $people
 */
$add = static function (
    string $urgency, string $kind, string $title, string $detail, ?string $link, int $count,
    string $action = 'Open', array $people = []
) use (&$items): void {
    $items[] = [
        'urgency' => $urgency, 'kind' => $kind, 'title' => $title, 'detail' => $detail,
        'link' => $link, 'count' => $count, 'action' => $action, 'people' => $people,
    ];
};

/**
 * A count, with the service filter appended for a scoped account.
 *
 * $serviceColumn names the column that holds the record's service. For an
 * unscoped account nothing is added; for a scoped one `AND col = ?` is, so a
 * record whose service is NULL is excluded, matching rg_in_service_scope().
 */
$count = static function (string $sql, ?string $serviceColumn = null) use ($conn, $scope): int {
    $types = '';
    $args = [];
    if ($scope !== null && $serviceColumn !== null) {
        $sql .= " AND $serviceColumn = ?";
        $types = 's';
        $args[] = $scope;
    }
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    return $row ? (int) $row[0] : 0;
};

/**
 * The distinct people a query returns, narrowed to this account's scope.
 *
 * For records about a person rather than a service (right-to-work checks,
 * verifications, leave): in scope when any service the person holds is the
 * account's, the same rule verifications.php and leave.php apply to their lists.
 *
 * The query returns one row per record (person_id, full_name); the result is
 * the in-scope people, and in $records the number of in-scope records, which is
 * the figure to show when one person can have several.
 *
 * @return list<array{id:int,name:string}>
 */
$people = static function (string $sql, ?int &$records = null) use ($conn, $scope): array {
    $res = $conn->query($sql);
    $rows = [];
    $perPerson = [];
    while ($r = $res->fetch_assoc()) {
        $id = (int) $r['person_id'];
        $rows[$id] = (string) $r['full_name'];
        $perPerson[$id] = ($perPerson[$id] ?? 0) + 1;
    }
    if ($scope !== null && $rows !== []) {
        $services = rg_people_services($conn, array_keys($rows));
        foreach (array_keys($rows) as $id) {
            if (!rg_person_in_scope($scope, $services[$id] ?? [])) unset($rows[$id], $perPerson[$id]);
        }
    }
    $records = array_sum($perPerson);
    $out = [];
    foreach ($rows as $id => $name) $out[] = ['id' => $id, 'name' => $name];
    return $out;
};

$plural = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$first = static fn(array $list): array => array_slice($list, 0, 5);
/** One person: open their profile at the right tab. Several: the list screen. */
$linkFor = static fn(array $list, string $tab, string $fallback): string =>
    count($list) === 1 ? '/app/people/' . $list[0]['id'] . '?tab=' . $tab : $fallback;

$tiles = [
    'unfilled_shifts' => null,
    'checks_to_review' => null,
    'expiring_30' => null,
    'approvals_waiting' => null,
];

// ---- Compliance -------------------------------------------------------------
if ($can['compliance']) {
    // The most urgent thing the system knows: somebody the office believes is
    // active is not actually deployable.
    $stmt = $conn->prepare(
        'SELECT DISTINCT p.id, p.full_name
           FROM person_services ps JOIN people p ON p.id = ps.person_id
          WHERE ps.eligibility_status = "not_eligible" AND ps.active = 1 AND p.deleted_at IS NULL'
        . ($scope !== null ? ' AND ps.service = ?' : '') . ' ORDER BY p.full_name'
    );
    if ($scope !== null) $stmt->bind_param('s', $scope);
    $stmt->execute();
    $res = $stmt->get_result();
    $blocked = [];
    while ($r = $res->fetch_assoc()) $blocked[] = ['id' => (int) $r['id'], 'name' => $r['full_name']];
    if ($blocked) $add(URGENT, 'eligibility',
        $plural(count($blocked), 'person cannot', 'people cannot') . ' be deployed',
        'A required check is missing, expired or rejected. The profile says which.',
        $linkFor($blocked, 'checks', '/app/reports?report=workforce'), count($blocked), 'See why', $first($blocked));

    $expiredCerts = $count(
        'SELECT COUNT(*) FROM certifications c JOIN workers w ON w.id = c.worker_id
          WHERE w.deleted_at IS NULL AND c.expiry IS NOT NULL AND c.expiry < CURDATE()', 'w.division');
    if ($expiredCerts) $add(URGENT, 'compliance', $plural($expiredCerts, 'expired credential', 'expired credentials'),
        'Already lapsed. Anyone relying on one cannot be scheduled for work that requires it.',
        '/app/compliance?tab=credentials', $expiredCerts, 'Review');

    $siaExpired = $count('SELECT COUNT(*) FROM workers w WHERE w.deleted_at IS NULL AND w.sia_expiry IS NOT NULL AND w.sia_expiry < CURDATE()', 'w.division');
    if ($siaExpired) $add(URGENT, 'compliance', $plural($siaExpired, 'SIA licence has', 'SIA licences have') . ' expired',
        'Security work cannot be assigned without a valid licence.', '/app/compliance?tab=credentials', $siaExpired, 'Review');

    $dbsExpired = $count('SELECT COUNT(*) FROM workers w WHERE w.deleted_at IS NULL AND w.dbs_expiry IS NOT NULL AND w.dbs_expiry < CURDATE()', 'w.division');
    if ($dbsExpired) $add(URGENT, 'compliance', $plural($dbsExpired, 'DBS check has', 'DBS checks have') . ' expired',
        'Care work cannot be assigned without a current check.', '/app/compliance?tab=credentials', $dbsExpired, 'Review');

    // Evidence received and waiting for a reviewer. 'requested' is waiting on the
    // person, not on the office, so it is not "to verify".
    $toVerifyCount = 0;
    $toVerify = $people(
        'SELECT v.person_id, p.full_name FROM verifications v JOIN people p ON p.id = v.person_id
          WHERE v.status IN ("received","under_review") AND p.deleted_at IS NULL ORDER BY p.full_name', $toVerifyCount);
    if ($toVerifyCount) $add(TODAY, 'verification', $plural($toVerifyCount, 'document', 'documents') . ' to verify',
        'Evidence has arrived and is waiting for a named person to check it.',
        '/app/compliance?tab=queue', $toVerifyCount, 'Verify', $first($toVerify));

    $rtw = $people(
        'SELECT r.person_id, p.full_name FROM right_to_work_checks r JOIN people p ON p.id = r.person_id
          WHERE r.verified_at IS NULL AND r.outcome <> "invalid" AND p.deleted_at IS NULL
            AND r.id = (SELECT MAX(r2.id) FROM right_to_work_checks r2 WHERE r2.person_id = r.person_id)
          ORDER BY p.full_name');
    if ($rtw) $add(TODAY, 'right_to_work', $plural(count($rtw), 'right-to-work check needs', 'right-to-work checks need') . ' verifying',
        'Recorded but not yet signed off by a named person.',
        $linkFor($rtw, 'checks', '/app/compliance?tab=rtw'), count($rtw), 'Verify', $first($rtw));

    $tiles['checks_to_review'] = ['count' => $toVerifyCount + count($rtw), 'link' => '/app/compliance?tab=queue'];

    $rtwDue = $people(
        'SELECT r.person_id, p.full_name FROM right_to_work_checks r JOIN people p ON p.id = r.person_id
          WHERE r.recheck_due IS NOT NULL AND r.recheck_due <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) AND p.deleted_at IS NULL
            AND r.id = (SELECT MAX(r2.id) FROM right_to_work_checks r2 WHERE r2.person_id = r.person_id)
          ORDER BY r.recheck_due');
    if ($rtwDue) $add(SOON, 'right_to_work', $plural(count($rtwDue), 'right-to-work recheck is', 'right-to-work rechecks are') . ' due within 60 days',
        'Arrange these before the date, not after it.', $linkFor($rtwDue, 'checks', '/app/compliance?tab=rtw'), count($rtwDue), 'Plan', $first($rtwDue));

    $expiringCerts = $count(
        'SELECT COUNT(*) FROM certifications c JOIN workers w ON w.id = c.worker_id
          WHERE w.deleted_at IS NULL AND c.expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'w.division');
    $expiringDbs = $count(
        'SELECT COUNT(*) FROM workers w WHERE w.deleted_at IS NULL
            AND w.dbs_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'w.division');
    $expiringSia = $count(
        'SELECT COUNT(*) FROM workers w WHERE w.deleted_at IS NULL
            AND w.sia_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'w.division');
    $expiring = $expiringCerts + $expiringDbs + $expiringSia;
    if ($expiring) $add(SOON, 'compliance', $plural($expiring, 'credential expires', 'credentials expire') . ' within 30 days',
        'Still valid today. Chase now and nobody is stood down later.', '/app/reports?report=expiring', $expiring, 'Chase');
    $tiles['expiring_30'] = ['count' => $expiring, 'link' => '/app/reports?report=expiring'];
}

// ---- Recruitment -------------------------------------------------------------
if ($can['recruitment']) {
    $newApps = $count('SELECT COUNT(*) FROM applicants a WHERE a.deleted_at IS NULL AND a.stage = "applied"', 'a.division');
    if ($newApps) $add(TODAY, 'recruitment', $plural($newApps, 'new applicant', 'new applicants') . ' to review',
        'Nobody has looked at these yet.', '/app/ats', $newApps, 'Review');

    $stalled = $count(
        'SELECT COUNT(*) FROM applicants a
          WHERE a.deleted_at IS NULL AND a.stage IN ("screened","interview","vetting","offer")
            AND a.updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)', 'a.division');
    if ($stalled) $add(TODAY, 'recruitment', $plural($stalled, 'candidate has', 'candidates have') . ' not moved in two weeks',
        'Mid-pipeline and going cold. Progress or close them.', '/app/ats', $stalled, 'Open');
}

// ---- Rota, requests and incidents ---------------------------------------------
if ($can['rota']) {
    $openSoon = $count(
        'SELECT COUNT(*) FROM shifts s
          WHERE s.deleted_at IS NULL AND s.status = "open" AND s.starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)', 's.division');
    if ($openSoon) $add(URGENT, 'scheduling', $plural($openSoon, 'unfilled shift starts', 'unfilled shifts start') . ' within 3 days',
        'Nobody is assigned. These are the ones that become a phone call at 6am.', '/app/scheduling', $openSoon, 'Fill');

    $unfilledWeek = $count(
        'SELECT COUNT(*) FROM shifts s
          WHERE s.deleted_at IS NULL AND s.status = "open" AND s.starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)', 's.division');
    $tiles['unfilled_shifts'] = ['count' => $unfilledWeek, 'link' => '/app/scheduling'];

    $riddor = $count('SELECT COUNT(*) FROM incidents i WHERE i.deleted_at IS NULL AND i.riddor = 1 AND i.status <> "closed"', 'i.division');
    if ($riddor) $add(URGENT, 'incidents', $plural($riddor, 'RIDDOR-flagged incident is', 'RIDDOR-flagged incidents are') . ' still open',
        'These carry a reporting duty.', '/app/incidents', $riddor, 'Open');

    $openIncidents = $count('SELECT COUNT(*) FROM incidents i WHERE i.deleted_at IS NULL AND i.status IN ("open","investigating")', 'i.division');
    if ($openIncidents) $add(TODAY, 'incidents', $plural($openIncidents, 'open incident', 'open incidents'),
        'Including anything flagged RIDDOR or safeguarding.', '/app/incidents', $openIncidents, 'Open');

    $leave = $people(
        'SELECT DISTINCT l.person_id, p.full_name FROM leave_records l JOIN people p ON p.id = l.person_id
          WHERE l.status = "requested" AND p.deleted_at IS NULL ORDER BY p.full_name');
    if ($leave) $add(TODAY, 'leave', $plural(count($leave), 'person is', 'people are') . ' waiting on a leave decision',
        'Approve or decline from the Employment tab of their profile.',
        $linkFor($leave, 'employment', '/app/people/' . $leave[0]['id'] . '?tab=employment'), count($leave), 'Decide', $first($leave));

    $newEnquiries = $count('SELECT COUNT(*) FROM contact_enquiries e WHERE e.status = "new"', 'e.division');
    if ($newEnquiries) $add(TODAY, 'enquiries', $plural($newEnquiries, 'new enquiry', 'new enquiries'),
        'Somebody asked and has not heard back.', '/app/enquiries', $newEnquiries, 'Reply');

    $newRequests = $count('SELECT COUNT(*) FROM service_requests r WHERE r.status = "new"', 'r.division');
    if ($newRequests) $add(TODAY, 'requests', $plural($newRequests, 'new request', 'new requests') . ' for work',
        'A client asked for cover and is waiting for an answer.', '/app/requests', $newRequests, 'Answer');
}

// ---- Hours ---------------------------------------------------------------------
if ($can['payroll']) {
    $pending = $count(
        'SELECT COUNT(*) FROM timesheets t JOIN workers w ON w.id = t.worker_id
          WHERE t.deleted_at IS NULL AND t.status = "pending"', 'w.division');
    if ($pending) $add(TODAY, 'timesheets', $plural($pending, 'timesheet is', 'timesheets are') . ' waiting for approval',
        'Submitted hours do not reach a pay run until they are approved.', '/app/timesheets', $pending, 'Approve');

    $queried = $count(
        'SELECT COUNT(*) FROM timesheets t JOIN workers w ON w.id = t.worker_id
          WHERE t.deleted_at IS NULL AND t.status = "queried"', 'w.division');
    if ($queried) $add(SOON, 'timesheets', $plural($queried, 'queried timesheet is', 'queried timesheets are') . ' waiting on the worker',
        'Sent back with a question. Chase if it is holding up pay.', '/app/timesheets', $queried, 'Open');
}

// ---- Approvals -------------------------------------------------------------------
if ($can['approvals']) {
    // Ready to approve: onboarding finished and a reviewed contract on file, so
    // the only thing missing is the named person. Workers still blocked are not
    // "waiting for approval", they are waiting for something else, and the
    // profile's next action says what.
    $stmt = $conn->prepare(
        'SELECT w.*, p.full_name FROM workers w JOIN people p ON p.id = w.person_id
          WHERE w.deleted_at IS NULL AND w.approved_at IS NULL AND w.status NOT IN ("left","archived")'
        . ($scope !== null ? ' AND w.division = ?' : '') . ' ORDER BY p.full_name'
    );
    if ($scope !== null) $stmt->bind_param('s', $scope);
    $stmt->execute();
    $res = $stmt->get_result();
    $ready = [];
    while ($w = $res->fetch_assoc()) {
        if (rg_approval_readiness($conn, $w) === []) $ready[] = ['id' => (int) $w['person_id'], 'name' => $w['full_name']];
    }
    if ($ready) $add(TODAY, 'approval', $plural(count($ready), 'worker is', 'workers are') . ' ready for approval',
        'Onboarding is finished and the contract is reviewed. They cannot work until somebody approves them.',
        $linkFor($ready, 'employment', '/app/people/' . $ready[0]['id'] . '?tab=employment'), count($ready), 'Approve', $first($ready));
    $tiles['approvals_waiting'] = ['count' => count($ready), 'link' => $ready
        ? $linkFor($ready, 'employment', '/app/people/' . $ready[0]['id'] . '?tab=employment') : '/app/workers'];
}

if ($can['governance']) {
    $deletions = $count('SELECT COUNT(*) FROM deletion_requests d WHERE d.status = "requested"');
    if ($deletions) $add(TODAY, 'governance', $plural($deletions, 'deletion request needs', 'deletion requests need') . ' a decision',
        'Somebody asked for a record to be removed. A second person decides.', '/app/settings?tab=governance', $deletions, 'Decide');
}

// ---- Money (only while the finance area is switched on) -----------------------
if ($can['finance']) {
    $overdue = $count(
        'SELECT COUNT(*) FROM invoices i
          WHERE i.deleted_at IS NULL AND i.status IN ("sent","overdue") AND i.due_date IS NOT NULL AND i.due_date < CURDATE()', 'i.division');
    if ($overdue) $add(TODAY, 'invoicing', $plural($overdue, 'overdue invoice', 'overdue invoices'),
        'Past their due date and unpaid.', '/app/invoicing', $overdue, 'Chase');
}

// ---- The system itself ---------------------------------------------------------
// If email stopped going out, that is not visible anywhere else, and the first
// symptom is somebody saying they never heard from us.
if ($can['system']) {
    $failedMail = $count('SELECT COUNT(*) FROM email_queue WHERE status = "failed"');
    if ($failedMail) $add(URGENT, 'system', $plural($failedMail, 'email has', 'emails have') . ' failed to send',
        'Gave up after repeated attempts. Somebody was not told something.', '/app/settings?tab=health', $failedMail, 'Check');

    $stuckMail = $count('SELECT COUNT(*) FROM email_queue WHERE status = "queued" AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)');
    if ($stuckMail) $add(URGENT, 'system', $plural($stuckMail, 'email has', 'emails have') . ' been queued over 2 hours',
        'The queue is not being drained. Check the send-queued-mail cron.', '/app/settings?tab=health', $stuckMail, 'Check');

    // An integration that has gone quiet looks exactly like a quiet week.
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
        $plural(count($silentList), 'form has', 'forms have') . ' gone quiet',
        'Nothing received within the expected window: ' . implode('; ', $silentList),
        '/app/settings?tab=health', count($silentList), 'Check');

    $failedIntake = $count('SELECT COUNT(*) FROM integration_submissions WHERE status = "failed"');
    if ($failedIntake) $add(TODAY, 'system', $plural($failedIntake, 'form submission', 'form submissions') . ' could not be read',
        'Somebody applied and is not in the pipeline. The answers are kept, so fixing the mapping recovers them.',
        '/app/settings?tab=health', $failedIntake, 'Fix');
}

if ($can['training']) {
    $unlinked = $count('SELECT COUNT(*) FROM enrolments WHERE person_id IS NULL');
    if ($unlinked) $add(SOON, 'training', $plural($unlinked, 'training record is', 'training records are') . ' not linked to a person',
        'Enrolments carry only a learner name, so these are linked by hand.', '/app/academy', $unlinked, 'Link');
}

// ---- Order and return ------------------------------------------------------
$rank = [URGENT => 0, TODAY => 1, SOON => 2];
usort($items, static fn(array $a, array $b): int => $rank[$a['urgency']] <=> $rank[$b['urgency']]);

$counts = [URGENT => 0, TODAY => 0, SOON => 0];
foreach ($items as $item) $counts[$item['urgency']]++;

json_out([
    // A tile is null when this account may not see what it counts, so the page
    // leaves it out rather than showing a misleading zero.
    'tiles' => $tiles,
    'items' => $items,
    'counts' => $counts,
    'total' => count($items),
    // A quiet day is a real answer, and worth saying out loud.
    'all_clear' => $items === [],
    'scope' => $scope,
    'generated_at' => date('Y-m-d H:i'),
]);
