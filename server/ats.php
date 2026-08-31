<?php
// §13 Recruitment & onboarding (ATS).
// POST /ats.php (public)                     -> submit an application (rate-limited)
// GET  /ats.php (staff)                      -> { applicants:[...] }
// GET  /ats.php?id=N                         -> { applicant, tasks:[...] }
// POST /ats.php?action=stage&id=N {stage}
// POST /ats.php?action=add_task&id=N {label}
// POST /ats.php?action=task_done&task_id=N {done}
// POST /ats.php?action=onboard&id=N          -> create a worker from the applicant + seed checklist
// POST /ats.php?action=from_learner {name,email,division} -> academy → ATS handoff
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

const ONBOARDING_CHECKLIST = [
    'Right to work verified',
    'DBS check submitted',
    'References received (2+)',
    'Contract signed',
    'Mandatory training booked',
];

// ---------- Public application ----------
if (method() === 'POST' && $action === '') {
    rate_limit($conn, 'ats', 8, 600);
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $role = trim((string) ($data['role_applied'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    $consent = !empty($data['consent']);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A name and valid email are required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Choose a division.');
    if (!$consent) fail('Please agree to be contacted about your application.');

    $roleVal = $role !== '' ? $role : null;
    $phoneVal = $phone !== '' ? $phone : null;
    // Resolve the person BEFORE the application, so a returning applicant lands
    // on the record they already have rather than becoming a second copy.
    $personId = find_or_create_person($conn, $name, $email, $phoneVal);
    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, phone, role_applied, division, source) VALUES (?, ?, ?, ?, ?, ?, "careers")'
    );
    $stmt->bind_param('isssss', $personId, $name, $email, $phoneVal, $roleVal, $division);
    $stmt->execute();

    $ip = client_ip();
    $stmt = $conn->prepare('INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "application", 1, "careers", ?)');
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();

    notify($conn, null, 'New job application', "$name — " . ucfirst($division), 'ats', '/app/ats');
    if (function_exists('mail_enabled') && mail_enabled()) {
        send_mail(MAIL_TO, "New application — $division", "$name <$email> ($phone)\nRole: $role");
    }
    json_out(['ok' => true], 201);
}

// Everything else is staff-only.
$user = require_auth($conn);

if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    if (!$app) fail('Applicant not found.', 404);
    $app['id'] = (int) $app['id'];
    $app['worker_id'] = $app['worker_id'] !== null ? (int) $app['worker_id'] : null;

    $stmt = $conn->prepare('SELECT id, label, done FROM onboarding_tasks WHERE applicant_id = ? ORDER BY id');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tasks = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['done'] = (int) $row['done'];
        $tasks[] = $row;
    }
    json_out(['applicant' => $app, 'tasks' => $tasks]);
}

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT id, name, email, phone, role_applied, division, stage, source, worker_id, created_at
         FROM applicants ORDER BY created_at DESC LIMIT 500'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        $items[] = $row;
    }
    json_out(['applicants' => $items]);
}

if ($action === 'stage' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $stage = trim((string) (body()['stage'] ?? ''));
    $valid = ['applied', 'screened', 'interview', 'vetting', 'offer', 'onboarded', 'rejected'];
    if ($id <= 0 || !in_array($stage, $valid, true)) fail('Valid applicant and stage required.');
    $stmt = $conn->prepare('UPDATE applicants SET stage = ? WHERE id = ?');
    $stmt->bind_param('si', $stage, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'ats.stage', "#$id -> $stage");
    json_out(['ok' => true]);
}

if ($action === 'add_task' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $label = trim((string) (body()['label'] ?? ''));
    if ($id <= 0 || $label === '') fail('An applicant and task label are required.');
    $stmt = $conn->prepare('INSERT INTO onboarding_tasks (applicant_id, label) VALUES (?, ?)');
    $stmt->bind_param('is', $id, $label);
    $stmt->execute();
    json_out(['ok' => true], 201);
}

if ($action === 'task_done' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $tid = (int) ($_GET['task_id'] ?? 0);
    $done = !empty(body()['done']) ? 1 : 0;
    if ($tid <= 0) fail('A task is required.');
    $stmt = $conn->prepare('UPDATE onboarding_tasks SET done = ? WHERE id = ?');
    $stmt->bind_param('ii', $done, $tid);
    $stmt->execute();
    json_out(['ok' => true]);
}

// Convert an applicant into a worker record + seed the onboarding checklist.
if ($action === 'onboard' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An applicant is required.');
    $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    if (!$app) fail('Applicant not found.', 404);
    if ($app['worker_id']) fail('This applicant is already a worker.', 409);

    // Map division -> a sensible default worker role.
    $roleMap = ['care' => 'carer', 'cleaning' => 'cleaner', 'security' => 'guard', 'academy' => 'trainer'];
    $wrole = $roleMap[$app['division']] ?? 'carer';

    // LINK, do not copy. This previously read name, email and phone off the
    // applicant and wrote them into a new workers row, so one human became two
    // records that drifted apart from that moment on. The employment record now
    // points at the same person as the application, and the identity fields are
    // read back from people rather than duplicated from the applicant.
    $personId = (int) $app['person_id'];
    $stmt = $conn->prepare('SELECT full_name, email, phone FROM people WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $person = $stmt->get_result()->fetch_assoc();
    if (!$person) fail('This application is not linked to a person record.', 409);

    // A payroll number is assigned once, here, and never reused.
    $payrollNo = next_payroll_no($conn);

    $stmt = $conn->prepare(
        'INSERT INTO workers (person_id, payroll_no, name, email, phone, role, division, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, "onboarding")'
    );
    $stmt->bind_param('issssss', $personId, $payrollNo, $person['full_name'], $person['email'], $person['phone'], $wrole, $app['division']);
    $stmt->execute();
    $workerId = $conn->insert_id;

    // The person is now intended for this service.
    $stmt = $conn->prepare('INSERT IGNORE INTO person_services (person_id, service) VALUES (?, ?)');
    $stmt->bind_param('is', $personId, $app['division']);
    $stmt->execute();

    $stmt = $conn->prepare('UPDATE applicants SET stage = "onboarded", worker_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $workerId, $id);
    $stmt->execute();

    // Seed the onboarding checklist if none exists yet.
    $existing = $conn->query('SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = ' . $id)->fetch_row()[0];
    if ((int) $existing === 0) {
        $ins = $conn->prepare('INSERT INTO onboarding_tasks (applicant_id, label) VALUES (?, ?)');
        foreach (ONBOARDING_CHECKLIST as $label) {
            $ins->bind_param('is', $id, $label);
            $ins->execute();
        }
    }
    audit($conn, (int) $user['id'], 'ats.onboard', "applicant #$id -> worker #$workerId (person #$personId, $payrollNo)");
    json_out(['ok' => true, 'worker_id' => (int) $workerId, 'person_id' => $personId, 'payroll_no' => $payrollNo], 201);
}

// Academy → ATS handoff: a strong learner becomes an applicant.
if ($action === 'from_learner' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'trainer');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $email = trim((string) ($data['email'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'care'));
    if ($name === '') fail('A learner name is required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) $division = 'care';
    // The fabricated '<name>@learner.invalid' address is kept only to satisfy the
    // NOT NULL column on applicants. It is NOT an identity: rg_normalise_email()
    // discards that domain, so a learner with no real address gets their own
    // person rather than colliding with every other addressless learner.
    $emailVal = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ($name . '@learner.invalid');
    $personId = find_or_create_person($conn, $name, $email ?: null, null);
    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, division, stage, source) VALUES (?, ?, ?, ?, "screened", "academy")'
    );
    $stmt->bind_param('isss', $personId, $name, $emailVal, $division);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'ats.from_learner', $name);
    json_out(['ok' => true, 'id' => (int) $conn->insert_id], 201);
}

fail('Method not allowed.', 405);
