<?php
// The person profile.
//
//   GET  /people.php?id=N                    everything the profile shows, in one read
//   POST /people.php?action=emergency&id=N   save emergency contacts
//   POST /people.php?action=training&id=N    add a training record
//
// One page per person, keyed by people.id, because a person exists once and may
// be an applicant in one service and a worker in another (docs/Plans 2026/02,
// "The person profile"). Identity is read from people, never copied.
//
// This file reads and does three small writes. Every other action on the page --
// verify, reject, record a right-to-work check, move a contract on, approve,
// suspend, decide leave -- goes to the endpoint that already owns it, so there is
// still one place each rule lives.
//
// Access, in layers:
//   1. Internal staff in the roles that may open People (src/lib/access.ts).
//   2. The person must be in the account's service scope (require_person_scope).
//   3. Records from the person's OTHER services are dropped one by one.
//   4. Each section is behind its zone. A section the account may not see comes
//      back as {"restricted": true} rather than as data, so the screen can say so.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';
require __DIR__ . '/people-lib.php';

$user = require_auth($conn);
// Above every branch, reads included. A worker's login and a client portal login
// are not staff; an editor is staff but has no business in the workforce record,
// which matches /app/people in src/lib/access.ts.
require_staff($user);
require_role($user, 'super_admin', 'admin', 'manager', 'trainer', 'employee');

$action = $_GET['action'] ?? '';
$isOffice = in_array($user['role'], ['super_admin', 'admin', 'manager'], true);
$uid = (int) $user['id'];

/** The person row, or a 404. */
function people_load(mysqli $conn, int $id): array
{
    if ($id <= 0) fail('A person is required.');
    $stmt = $conn->prepare(
        'SELECT id, full_name, preferred_name, email, phone, address_line1, address_line2, city, postcode,
                emergency_contact_name, emergency_contact_phone, emergency_contact_relationship,
                emergency_contact2_name, emergency_contact2_phone, emergency_contact2_relationship,
                created_at
           FROM people WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $person = $stmt->get_result()->fetch_assoc();
    if (!$person) fail('Person not found.', 404);
    return $person;
}

/** Run a query with an IN (...) list of ints, returning every row. */
function people_rows(mysqli $conn, string $sql, array $ids, string $prefixTypes = '', array $prefix = []): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(str_replace('(:ids)', "($marks)", $sql));
    $params = array_merge($prefix, $ids);
    $stmt->bind_param($prefixTypes . str_repeat('i', count($ids)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

/** A nullable int column as an int or null. */
function people_int($v): ?int
{
    return $v === null ? null : (int) $v;
}

// ---------------------------------------------------------------------------
// Writes

if (method() === 'POST' && $action === 'emergency') {
    // Visible to authorised office staff and the person only (docs/Plans 2026/02).
    // The employment zone is where that authority is configured.
    require_role($user, 'super_admin', 'admin', 'manager');
    if (!can_edit_zone($conn, $user, 'employment')) fail('You do not have access to this.', 403);
    $id = (int) ($_GET['id'] ?? 0);
    people_load($conn, $id);
    require_person_scope($conn, $user, $id);
    $values = rg_save_emergency_contacts($conn, $id, body(), $uid);
    json_out(['ok' => true, 'emergency' => $values]);
}

if (method() === 'POST' && $action === 'training') {
    require_role($user, 'super_admin', 'admin', 'manager');
    if (!can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);
    $id = (int) ($_GET['id'] ?? 0);
    people_load($conn, $id);
    require_person_scope($conn, $user, $id);
    $recordId = rg_add_training_record($conn, $id, body(), $uid);
    json_out(['ok' => true, 'id' => $recordId], 201);
}

if (method() !== 'GET') fail('Method not allowed.', 405);

// ---------------------------------------------------------------------------
// The profile

$personId = (int) ($_GET['id'] ?? 0);
$person = people_load($conn, $personId);
require_person_scope($conn, $user, $personId);

$scope = service_scope($user);
$zones = [
    'compliance' => can_view_zone($conn, $user, 'compliance'),
    'payroll' => can_view_zone($conn, $user, 'payroll'),
    'health' => can_view_zone($conn, $user, 'health'),
    'employment' => can_view_zone($conn, $user, 'employment'),
];
$restricted = ['restricted' => true];
$today = date('Y-m-d');

// ---- Worker records, in scope ------------------------------------------------
$stmt = $conn->prepare(
    'SELECT w.id, w.person_id, w.payroll_no, w.role, w.division, w.status, w.derived_status,
            w.derived_status_reason, w.left_on, w.approved_at, w.approval_note, w.suspended_at,
            w.suspension_reason, w.holiday_hours, w.created_at, w.deleted_at,
            ua.name AS approved_by_name, us.name AS suspended_by_name
       FROM workers w
       LEFT JOIN users ua ON ua.id = w.approved_by
       LEFT JOIN users us ON us.id = w.suspended_by
      WHERE w.person_id = ? AND w.deleted_at IS NULL ORDER BY w.id'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
$workers = [];
while ($w = $res->fetch_assoc()) {
    if (!rg_in_service_scope($scope, $w['division'])) continue;
    $blockers = array_values(array_filter(
        rg_approval_readiness($conn, $w),
        static fn(array $b): bool => $b['code'] !== 'approved'
    ));
    $workers[] = [
        'id' => (int) $w['id'],
        'payroll_no' => $w['payroll_no'],
        'role' => $w['role'],
        'division' => $w['division'],
        'status' => $w['status'],
        'derived_status' => $w['derived_status'],
        'derived_status_reason' => $w['derived_status_reason'],
        'left_on' => $w['left_on'],
        'approved_at' => $w['approved_at'],
        'approved_by_name' => $w['approved_by_name'],
        'approval_note' => $w['approval_note'],
        'suspended_at' => $w['suspended_at'],
        'suspended_by_name' => $w['suspended_by_name'],
        'suspension_reason' => $w['suspension_reason'],
        'created_at' => $w['created_at'],
        'approval' => [
            'approved' => $w['approved_at'] !== null,
            // Empty means the server will accept an approval now.
            'blockers' => $blockers,
        ],
        '_holiday_hours' => (float) $w['holiday_hours'],
        '_left_on' => $w['left_on'],
        '_approved_at' => $w['approved_at'],
        '_deleted_at' => $w['deleted_at'],
    ];
}
$workerIds = array_column($workers, 'id');

// ---- Applications, in scope ---------------------------------------------------
$stmt = $conn->prepare(
    'SELECT a.id, a.division, a.stage, a.role_applied, a.source, a.created_at, a.outcome_reason,
            a.worker_id, u.name AS recruiter_name
       FROM applicants a LEFT JOIN users u ON u.id = a.recruiter_id
      WHERE a.person_id = ? AND a.deleted_at IS NULL ORDER BY a.id'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
$applications = [];
while ($a = $res->fetch_assoc()) {
    if (!rg_in_service_scope($scope, $a['division'])) continue;
    $a['id'] = (int) $a['id'];
    $a['worker_id'] = people_int($a['worker_id']);
    $a['tasks'] = [];
    $applications[$a['id']] = $a;
}
$taskRows = people_rows($conn,
    'SELECT t.id, t.applicant_id, t.label, t.done, t.due_on, t.done_at, u.name AS owner_name
       FROM onboarding_tasks t LEFT JOIN users u ON u.id = t.owner_id
      WHERE t.applicant_id IN (:ids) ORDER BY t.due_on IS NULL, t.due_on, t.id',
    array_keys($applications));
$outstanding = 0;
$taskIds = [];
foreach ($taskRows as $t) {
    $t['id'] = (int) $t['id'];
    $t['done'] = (int) $t['done'];
    $taskIds[] = $t['id'];
    if ($t['done'] === 0) $outstanding++;
    $applications[(int) $t['applicant_id']]['tasks'][] = $t;
}
$applications = array_values($applications);

// ---- Per-service status --------------------------------------------------------
$statusInput = array_map(static fn(array $w): array => [
    'id' => $w['id'], 'division' => $w['division'], 'status' => $w['status'],
    'left_on' => $w['_left_on'], 'approved_at' => $w['_approved_at'], 'payroll_no' => $w['payroll_no'],
], $workers);
$services = rg_service_statuses($statusInput, $applications, $today);

// ---- Evidence ------------------------------------------------------------------
// Loaded once whether or not the compliance tab is visible, because the next
// action is worked out from it. Only what the zone allows is returned.
$evidence = rg_load_evidence($conn, $personId);

$stmt = $conn->prepare(
    'SELECT v.id, v.requirement_key, v.subject_type, v.subject_label, v.status,
            v.requested_at, v.received_at, v.reviewed_at, v.verified_at, v.expires_on,
            v.method, v.outcome_note, v.rejection_reason, v.evidence_file_id,
            f.original_name AS evidence_file_name, vb.name AS verified_by,
            COALESCE(r.label, v.requirement_key) AS requirement
       FROM verifications v
       LEFT JOIN users vb ON vb.id = v.verified_by
       LEFT JOIN files f ON f.id = v.evidence_file_id AND f.deleted_at IS NULL
       LEFT JOIN compliance_requirements r ON r.req_key = v.requirement_key
      WHERE v.person_id = ? ORDER BY v.id DESC'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
$verifications = [];
$latestByKey = [];
$openByKey = [];
while ($v = $res->fetch_assoc()) {
    $v['id'] = (int) $v['id'];
    $v['evidence_file_id'] = people_int($v['evidence_file_id']);
    $v['next'] = RG_VERIFY_TRANSITIONS[$v['status']] ?? [];
    $verifications[] = $v;
    $key = $v['requirement_key'];
    $latestByKey[$key] ??= $v;
    if (!isset($openByKey[$key]) && in_array($v['status'], ['requested', 'received', 'under_review'], true)) {
        $openByKey[$key] = $v;
    }
}

$stmt = $conn->prepare(
    'SELECT r.id, r.method, r.method_note, r.reference, r.checked_on, r.outcome,
            r.follow_up_required, r.recheck_due, r.evidence_file_id, r.notes,
            r.created_at, r.verified_at, u.name AS verified_by, f.original_name AS evidence_file_name
       FROM right_to_work_checks r
       LEFT JOIN users u ON u.id = r.verified_by
       LEFT JOIN files f ON f.id = r.evidence_file_id AND f.deleted_at IS NULL
      WHERE r.person_id = ? ORDER BY r.checked_on DESC, r.id DESC'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
$rtwChecks = [];
while ($r = $res->fetch_assoc()) {
    $r['id'] = (int) $r['id'];
    $r['follow_up_required'] = (bool) $r['follow_up_required'];
    $r['evidence_file_id'] = people_int($r['evidence_file_id']);
    $r['verified'] = $r['verified_at'] !== null;
    $rtwChecks[] = $r;
}
$rtwCurrent = $rtwChecks[0] ?? null;

// Each service this person is connected to, assessed now and not written down.
// Viewing a profile is not a decision about the person (CLAUDE.md, "Screening a
// list is not deciding about a person").
$assessments = [];
foreach ($services as $service => $svc) {
    $role = null;
    foreach ($workers as $w) {
        if ($w['division'] === $service) $role = $w['role'];
    }
    $verdict = rg_evaluate_eligibility(rg_load_rules($conn, $service, $role), $evidence, $today);
    $items = [];
    foreach ($verdict['items'] as $item) {
        $key = $item['key'];
        $open = $openByKey[$key] ?? null;
        $chip = rg_requirement_chip($item['state'], $open['status'] ?? null);
        $latest = $latestByKey[$key] ?? null;
        $isRtw = $key === 'right_to_work';
        $items[] = $item + [
            'chip' => $chip['chip'],
            'chip_label' => $chip['label'],
            'expiry' => $evidence[$key]['expiry'] ?? null,
            // Where the counted evidence came from (rg_load_evidence()), so a check
            // that is valid only because of a certificate row nobody has verified
            // is labelled as such rather than reading as "Verifier: nobody yet".
            'evidence_origin' => $evidence[$key]['origin'] ?? null,
            'verification_id' => $latest['id'] ?? null,
            'verification_status' => $latest['status'] ?? null,
            'open_verification_id' => $open['id'] ?? null,
            'open_status' => $open['status'] ?? null,
            'next' => $latest !== null ? $latest['next'] : [],
            'rejection_reason' => ($latest['status'] ?? null) === 'rejected' ? $latest['rejection_reason'] : null,
            'verified_by' => $isRtw ? ($rtwCurrent['verified_by'] ?? null) : ($latest['verified_by'] ?? null),
            'checked_on' => $isRtw ? ($rtwCurrent['checked_on'] ?? null) : ($latest['verified_at'] ?? $latest['reviewed_at'] ?? null),
            'evidence_file_id' => $isRtw ? ($rtwCurrent['evidence_file_id'] ?? null) : ($latest['evidence_file_id'] ?? null),
            'evidence_file_name' => $isRtw ? ($rtwCurrent['evidence_file_name'] ?? null) : ($latest['evidence_file_name'] ?? null),
        ];
    }
    $assessments[] = [
        'service' => $service,
        'status' => $verdict['status'],
        'eligible' => $verdict['eligible'],
        'score' => $verdict['score'],
        'reason' => rg_eligibility_reason($verdict),
        'items' => $items,
    ];
    $services[$service]['eligibility'] = $verdict['status'];
}

// ---- Documents filed against the person or an in-scope worker record ---------
$stmt = $conn->prepare(
    'SELECT f.id, f.original_name, f.mime, f.category, f.created_at, f.expires_on,
            f.person_id, f.worker_id, f.client_id, w.division, u.name AS uploaded_by
       FROM files f
       LEFT JOIN workers w ON w.id = f.worker_id
       LEFT JOIN users u ON u.id = f.uploaded_by
      WHERE f.deleted_at IS NULL AND (f.person_id = ? OR w.person_id = ?)
      ORDER BY f.created_at DESC, f.id DESC'
);
$stmt->bind_param('ii', $personId, $personId);
$stmt->execute();
$res = $stmt->get_result();
$files = [];
while ($f = $res->fetch_assoc()) {
    if ($f['worker_id'] !== null && !rg_in_service_scope($scope, $f['division'])) continue;
    // Being able to open the profile is not being able to open every document.
    if (!rg_file_readable($f, $user)) continue;
    $files[] = [
        'id' => (int) $f['id'],
        'original_name' => $f['original_name'],
        'mime' => $f['mime'],
        'category' => $f['category'],
        'created_at' => $f['created_at'],
        'expires_on' => $f['expires_on'],
        'uploaded_by' => $f['uploaded_by'],
    ];
}

// ---- References: third-party contact details, office only (references.php) ---
$references = [];
if ($isOffice) {
    foreach (people_rows($conn,
        'SELECT id, worker_id, referee_name, referee_org, relationship, email, phone, status, gap_note, created_at
           FROM worker_references WHERE worker_id IN (:ids) ORDER BY created_at, id', $workerIds) as $r) {
        $r['id'] = (int) $r['id'];
        $r['worker_id'] = (int) $r['worker_id'];
        $references[] = $r;
    }
}

// ---- Training -------------------------------------------------------------------
$certifications = [];
foreach (people_rows($conn,
    'SELECT c.id, c.worker_id, c.type, c.number, c.expiry, c.status, c.created_at
       FROM certifications c WHERE c.worker_id IN (:ids) ORDER BY c.expiry IS NULL, c.expiry', $workerIds) as $c) {
    $class = rg_classify($c['expiry'], $today);
    $c['id'] = (int) $c['id'];
    $c['worker_id'] = (int) $c['worker_id'];
    $c['rag'] = $class['status'];
    $c['days'] = $class['days'];
    $certifications[] = $c;
}
$stmt = $conn->prepare(
    'SELECT e.id, e.status, e.completed_on, e.expires_on, e.certificate_public_id, e.created_at,
            e.evidence_file_id, f.original_name AS evidence_file_name,
            COALESCE(pr.title, e.external_course) AS course, tp.name AS provider
       FROM enrolments e
       LEFT JOIN programmes pr ON pr.id = e.programme_id
       LEFT JOIN training_providers tp ON tp.id = e.provider_id
       LEFT JOIN files f ON f.id = e.evidence_file_id AND f.deleted_at IS NULL
      WHERE e.person_id = ? ORDER BY e.completed_on IS NULL, e.completed_on DESC, e.id DESC'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
$training = [];
$enrolmentIds = [];
while ($e = $res->fetch_assoc()) {
    $class = rg_classify($e['expires_on'], $today);
    $e['id'] = (int) $e['id'];
    $e['evidence_file_id'] = people_int($e['evidence_file_id']);
    $e['rag'] = $e['expires_on'] !== null ? $class['status'] : null;
    $e['days'] = $class['days'];
    $enrolmentIds[] = $e['id'];
    $training[] = $e;
}
$providers = [];
$res = $conn->query('SELECT id, name FROM training_providers WHERE active = 1 ORDER BY name');
while ($p = $res->fetch_assoc()) $providers[] = ['id' => (int) $p['id'], 'name' => $p['name']];

// ---- Shifts: scheduling data, not hours worked ---------------------------------
// Durations here are what was booked. Hours actually worked are timesheets, which
// are the payroll zone and are not read by this page.
// shifts.starts_at and ends_at are wall-clock DATETIME (CLAUDE.md, "One clock"),
// compared here with the server's date() on the same clock.
$shiftSelect = 'SELECT s.id, s.worker_id, s.division, s.title, s.site_name, s.starts_at, s.ends_at, s.status, s.is_draft,
                       c.name AS client_name
                  FROM shifts s LEFT JOIN clients c ON c.id = s.client_id
                 WHERE s.deleted_at IS NULL';
$now = date('Y-m-d H:i:s');
$shape = static function (array $s): array {
    $s['id'] = (int) $s['id'];
    $s['worker_id'] = (int) $s['worker_id'];
    $s['minutes'] = max(0, (int) round((strtotime($s['ends_at']) - strtotime($s['starts_at'])) / 60));
    return $s;
};
$upcoming = array_map($shape, people_rows($conn,
    $shiftSelect . ' AND s.ends_at >= ? AND s.worker_id IN (:ids) ORDER BY s.starts_at LIMIT 30',
    $workerIds, 's', [$now]));
$past = array_map($shape, people_rows($conn,
    $shiftSelect . ' AND s.ends_at < ? AND s.worker_id IN (:ids) ORDER BY s.starts_at DESC LIMIT 30',
    $workerIds, 's', [$now]));
$nextShift = null;
foreach ($upcoming as $s) {
    if ($s['status'] === 'scheduled') { $nextShift = $s; break; }
}

// ---- Employment: contracts, pay history, leave -----------------------------------
$contracts = [];
$stmt = $conn->prepare(
    'SELECT c.id, c.reference, c.version, c.status, c.engagement_type, c.job_title, c.start_date,
            c.pay_rate_pence, c.pay_frequency, c.hours_pattern, c.supersedes_id,
            c.document_file_id, df.original_name AS document_file_name,
            c.signed_file_id, sf.original_name AS signed_file_name,
            c.sent_at, c.accepted_at, c.signed_at, c.signed_name,
            c.reviewed_at, ru.name AS reviewed_by_name, c.review_note, c.created_at,
            w.division
       FROM employment_contracts c
       LEFT JOIN workers w ON w.id = c.worker_id
       LEFT JOIN files df ON df.id = c.document_file_id AND df.deleted_at IS NULL
       LEFT JOIN files sf ON sf.id = c.signed_file_id AND sf.deleted_at IS NULL
       LEFT JOIN users ru ON ru.id = c.reviewed_by
      WHERE c.person_id = ? ORDER BY c.version DESC, c.id DESC'
);
$stmt->bind_param('i', $personId);
$stmt->execute();
$res = $stmt->get_result();
while ($c = $res->fetch_assoc()) {
    // The same rule employment-contracts.php applies to every action on a
    // contract, so the screen never offers one the endpoint will refuse.
    if (!rg_in_service_scope($scope, $c['division'])) continue;
    $c['id'] = (int) $c['id'];
    $c['version'] = (int) $c['version'];
    $c['supersedes_id'] = people_int($c['supersedes_id']);
    $c['document_file_id'] = people_int($c['document_file_id']);
    $c['signed_file_id'] = people_int($c['signed_file_id']);
    $c['pay_rate_pence'] = $zones['payroll'] ? people_int($c['pay_rate_pence']) : null;
    $c['next'] = RG_CONTRACT_TRANSITIONS[$c['status']] ?? [];
    unset($c['division']);
    $contracts[] = $c;
}
$contractIds = array_column($contracts, 'id');

$payRates = [];
foreach (people_rows($conn,
    'SELECT r.id, r.worker_id, r.rate_pence, r.pay_type, r.effective_from, r.note, u.name AS set_by_name
       FROM worker_pay_rates r LEFT JOIN users u ON u.id = r.set_by
      WHERE r.worker_id IN (:ids) ORDER BY r.effective_from DESC, r.id DESC', $workerIds) as $r) {
    $r['id'] = (int) $r['id'];
    $r['worker_id'] = (int) $r['worker_id'];
    $r['rate_pence'] = (int) $r['rate_pence'];
    $payRates[] = $r;
}

// Leave, as leave.php reads it: the current leave year, reasons only to the
// health zone. April is leave.php's LEAVE_YEAR_START_MONTH.
$leaveYear = rg_leave_year($today, 4);
$stmt = $conn->prepare(
    'SELECT l.id, l.kind, l.status, l.starts_on, l.ends_on, l.hours, l.leave_year, l.reason,
            l.return_to_work_on, l.return_to_work_note, l.created_at, l.decided_at, d.name AS decided_by
       FROM leave_records l LEFT JOIN users d ON d.id = l.decided_by
      WHERE l.person_id = ? AND (l.leave_year = ? OR l.leave_year IS NULL OR l.status = "requested")
      ORDER BY l.starts_on DESC'
);
$stmt->bind_param('is', $personId, $leaveYear);
$stmt->execute();
$res = $stmt->get_result();
$leave = [];
$leaveIds = [];
while ($l = $res->fetch_assoc()) {
    $l['id'] = (int) $l['id'];
    $l['hours'] = (float) $l['hours'];
    $leaveIds[] = $l['id'];
    if (!$zones['health']) {
        $l['reason'] = null;
        $l['return_to_work_note'] = null;
        $l['health_redacted'] = true;
    }
    $leave[] = $l;
}
$entitlement = $workers ? $workers[0]['_holiday_hours'] : 0.0;
$balance = rg_leave_balance($entitlement, array_values(array_filter($leave, static fn($l) => $l['leave_year'] === $leaveYear)));

// ---- Activity: the audit trail for this person and everything linked ---------------
$activity = $restricted;
if ($isOffice) {
    $entities = [
        'person' => [$personId],
        'worker' => $workerIds,
        'applicant' => array_column($applications, 'id'),
        'onboarding_task' => $taskIds,
        'employment_contract' => $contractIds,
        'verification' => array_column($verifications, 'id'),
        'right_to_work_check' => array_column($rtwChecks, 'id'),
        'leave' => $leaveIds,
        'enrolment' => $enrolmentIds,
        'file' => array_column($files, 'id'),
    ];
    $clauses = [];
    $types = '';
    $params = [];
    foreach ($entities as $type => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) continue;
        $clauses[] = '(a.entity_type = ? AND a.entity_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
        $types .= 's' . str_repeat('i', count($ids));
        $params = array_merge($params, [$type], $ids);
    }
    $stmt = $conn->prepare(
        'SELECT a.id, a.action, a.entity_type, a.entity_id, a.detail, a.reason, a.created_at, u.name AS user
           FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
          WHERE ' . implode(' OR ', $clauses) . '
          ORDER BY a.id DESC LIMIT 200'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $activity = [];
    while ($a = $res->fetch_assoc()) {
        // A pay change is the payroll zone's business, even in a history list.
        if (!$zones['payroll'] && $a['action'] === 'worker.pay') continue;
        $a['id'] = (int) $a['id'];
        $a['entity_id'] = people_int($a['entity_id']);
        $activity[] = $a;
    }
}

// ---- The one next action ---------------------------------------------------------
$awaiting = [];
foreach (array_reverse($verifications) as $v) {   // oldest first
    if (in_array($v['status'], ['received', 'under_review'], true)) $awaiting[] = $v['requirement'];
}
$latestContract = null;
foreach ($contracts as $c) {
    if ($c['status'] !== 'superseded') { $latestContract = $c; break; }
}
$contractState = 'none';
if ($latestContract !== null) {
    $contractState = match ($latestContract['status']) {
        'draft' => 'draft',
        'sent', 'viewed' => 'sent',
        'accepted' => 'accepted',
        'signed' => $latestContract['reviewed_at'] !== null ? 'reviewed' : 'signed_unreviewed',
        default => 'none',
    };
}
$blocking = [];
$expiring = [];
foreach ($assessments as $a) {
    foreach ($a['items'] as $item) {
        if ($item['weight'] === RG_MANDATORY && in_array($item['chip'], ['missing', 'expired'], true)
            && $item['key'] !== 'contract' && !in_array($item['label'], $blocking, true)) {
            $blocking[] = $item['label'];
        }
        if ($item['chip'] === 'due_soon' && !in_array($item['label'], $expiring, true)) $expiring[] = $item['label'];
    }
}
$approvableWorker = null;
$allApproved = $workers !== [];
foreach ($workers as $w) {
    if (!$w['approval']['approved']) $allApproved = false;
    if (!$w['approval']['approved'] && $w['approval']['blockers'] === []) $approvableWorker ??= $w['id'];
}
$latestApplication = $applications ? $applications[count($applications) - 1] : null;
$nextAction = rg_person_next_action([
    'suspended' => in_array('suspended', array_column($workers, 'status'), true),
    'awaiting_review' => $awaiting,
    'rtw_needs_verify' => $rtwCurrent !== null && !$rtwCurrent['verified'] && $rtwCurrent['outcome'] !== 'invalid',
    'contract' => $contractState,
    'onboarding_outstanding' => $outstanding,
    'blocking' => $blocking,
    'approvable' => $approvableWorker !== null,
    'approved' => $allApproved,
    'expiring' => $expiring,
    'has_worker' => $workers !== [],
    'applicant_stage' => $latestApplication['stage'] ?? null,
]);
if ($nextAction !== null) {
    $nextAction['target_id'] = match ($nextAction['key']) {
        'approve' => $approvableWorker,
        'review_contract', 'send_contract', 'record_signature' => $latestContract['id'] ?? null,
        'start_onboarding', 'review_application' => $latestApplication['id'] ?? null,
        default => null,
    };
}

// The internal underscore fields were only for the calculations above.
foreach ($workers as &$w) {
    unset($w['_holiday_hours'], $w['_left_on'], $w['_approved_at'], $w['_deleted_at']);
}
unset($w);

$canManage = $isOffice;
json_out([
    'person' => [
        'id' => (int) $person['id'],
        'full_name' => $person['full_name'],
        'preferred_name' => $person['preferred_name'],
        'email' => $person['email'],
        'phone' => $person['phone'],
        'address_line1' => $person['address_line1'],
        'address_line2' => $person['address_line2'],
        'city' => $person['city'],
        'postcode' => $person['postcode'],
        'created_at' => $person['created_at'],
        // From the newest contract that has been agreed, where there is one.
        'start_date' => $latestContract !== null && in_array($latestContract['status'], ['accepted', 'signed'], true)
            ? $latestContract['start_date'] : null,
    ],
    'services' => array_values($services),
    'payroll_no' => $workers[0]['payroll_no'] ?? null,
    'next_action' => $nextAction,
    'next_shift' => $nextShift,
    'zones' => $zones,
    'can' => [
        'manage' => $canManage,
        'approve' => $canManage,
        'edit_emergency' => $canManage && can_edit_zone($conn, $user, 'employment'),
    ],
    'workers' => $workers,
    'applications' => $applications,
    'checks' => $zones['compliance'] ? [
        'assessments' => $assessments,
        'verifications' => $verifications,
        'right_to_work' => ['current' => $rtwCurrent, 'checks' => $rtwChecks],
        'references' => $isOffice ? $references : null,
        'files' => $files,
    ] : $restricted,
    'training' => $zones['compliance'] ? [
        'certifications' => $certifications,
        'records' => $training,
        'providers' => $providers,
    ] : $restricted,
    'shifts' => ['upcoming' => $upcoming, 'past' => $past],
    'employment' => [
        'contracts' => $zones['compliance'] ? $contracts : $restricted,
        'pay_rates' => $zones['payroll'] ? $payRates : $restricted,
        'leave' => ['leave_year' => $leaveYear, 'balance' => $balance, 'records' => $leave],
    ],
    'emergency' => $zones['employment'] ? array_intersect_key($person, array_flip(RG_EMERGENCY_FIELDS)) : $restricted,
    'activity' => $activity,
]);
