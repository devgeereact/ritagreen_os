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
    // What the applicant wrote about themselves. The careers form asks for it and
    // it is the most useful thing a recruiter reads, so it is stored rather than
    // dropped on the way in.
    $notes = trim((string) ($data['notes'] ?? ''));
    $consent = !empty($data['consent']);
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('A name and valid email are required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Choose a division.');
    if (!$consent) fail('Please agree to be contacted about your application.');
    // Length caps. There were none, so an oversized field reached MySQL in strict
    // mode and came back as a 500 rather than a message the applicant could act
    // on -- and nothing stopped a script posting megabytes at the endpoint.
    if (mb_strlen($name) > 160) fail('That name is too long.');
    if (mb_strlen($email) > 190) fail('That email is too long.');
    if (mb_strlen($role) > 80) fail('That role is too long.');
    if (mb_strlen($notes) > 5000) fail('Please keep that under 5000 characters.');

    $roleVal = $role !== '' ? $role : null;
    $phoneVal = $phone !== '' ? mb_substr($phone, 0, 40) : null;
    $notesVal = $notes !== '' ? $notes : null;
    // Resolve the person BEFORE the application, so a returning applicant lands
    // on the record they already have rather than becoming a second copy.
    $personId = find_or_create_person($conn, $name, $email, $phoneVal);
    $stmt = $conn->prepare(
        'INSERT INTO applicants (person_id, name, email, phone, role_applied, division, notes, source) VALUES (?, ?, ?, ?, ?, ?, ?, "careers")'
    );
    $stmt->bind_param('issssss', $personId, $name, $email, $phoneVal, $roleVal, $division, $notesVal);
    $stmt->execute();
    // Captured immediately. Two more inserts follow before the email is queued,
    // and $conn->insert_id tracks the LAST one -- so the queued message used to
    // carry the notifications row's id as its related_id, pointing anybody who
    // followed it at the wrong record.
    $applicantId = (int) $conn->insert_id;

    $ip = client_ip();
    $stmt = $conn->prepare('INSERT INTO consent_log (email, kind, granted, source, ip) VALUES (?, "application", 1, "careers", ?)');
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();

    notify($conn, null, 'New job application', "$name — " . ucfirst($division), 'ats', '/app/ats');
    // Queued regardless of whether mail is switched on: the queue is the record
    // of intent, and the drainer records 'skipped' when it cannot send.
    {
        $body = "$name <$email> ($phone)\nRole: $role";
        if ($notesVal !== null) $body .= "\n\nAbout them:\n$notesVal";
        queue_mail($conn, MAIL_TO, "New application — $division", $body,
            $email, null, 'applicant', $applicantId);
    }
    json_out(['ok' => true], 201);
}

// Everything else is staff-only.
$user = require_auth($conn);
// Applicant records are personal data. /app/ats is office plus trainer in
// src/lib/access.ts; the reads below enforced nothing. The public POST above
// stays unauthenticated on purpose.
require_role($user, 'super_admin', 'admin', 'manager', 'trainer');

if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    if (!$app) fail('Applicant not found.', 404);
    // The list below is scoped and this was not, so an id was all it took to read
    // another service's candidate in full: name, email, phone and every note.
    require_service_scope($user, $app['division']);
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
    // A division-scoped account sees only its own division's candidates.
    $scope = service_scope($user);
    $res = $conn->query(
        'SELECT id, name, email, phone, role_applied, division, stage, source, worker_id, created_at
         FROM applicants WHERE deleted_at IS NULL'
        . ($scope !== null ? " AND division = '" . $conn->real_escape_string($scope) . "'" : '')
        . ' ORDER BY created_at DESC LIMIT 500'
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
    $valid = ['applied', 'screened', 'interview', 'vetting', 'offer', 'onboarding', 'onboarded', 'rejected'];
    if ($id <= 0 || !in_array($stage, $valid, true)) fail('Valid applicant and stage required.');
    $prev = $conn->prepare('SELECT stage, division FROM applicants WHERE id = ?');
    $prev->bind_param('i', $id);
    $prev->execute();
    $before = $prev->get_result()->fetch_assoc();
    // No row means no applicant. Without this the stage change reported success
    // and wrote an audit entry against an id that does not exist.
    if (!$before) fail('Applicant not found.', 404);
    require_service_scope($user, $before['division']);
    $was = $before['stage'];

    $stmt = $conn->prepare('UPDATE applicants SET stage = ? WHERE id = ?');
    $stmt->bind_param('si', $stage, $id);
    $stmt->execute();

    // A rejection is the entry somebody will ask about later.
    audit($conn, (int) $user['id'], 'ats.stage', "#$id", 'applicant', $id,
        ['stage' => $was], ['stage' => $stage]);
    json_out(['ok' => true]);
}

if ($action === 'add_task' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $label = trim((string) (body()['label'] ?? ''));
    if ($id <= 0 || $label === '') fail('An applicant and task label are required.');
    // Without this the INSERT trips the foreign key and the caller gets a 500
    // and a logged exception for what is only a mistyped id.
    $owner = $conn->prepare('SELECT division FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $owner->bind_param('i', $id);
    $owner->execute();
    $ownerRow = $owner->get_result()->fetch_assoc();
    if (!$ownerRow) fail('Applicant not found.', 404);
    require_service_scope($user, $ownerRow['division']);
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
    // This branch takes a TASK id, so the applicant's service has to be reached
    // through the join. Scoping only the branches that happen to take an
    // applicant id leaves the scope bypassable by using the other id.
    $owner = $conn->prepare(
        'SELECT a.division FROM onboarding_tasks t
           JOIN applicants a ON a.id = t.applicant_id
          WHERE t.id = ? LIMIT 1'
    );
    $owner->bind_param('i', $tid);
    $owner->execute();
    $ownerRow = $owner->get_result()->fetch_assoc();
    if (!$ownerRow) fail('Task not found.', 404);
    require_service_scope($user, $ownerRow['division']);

    $stmt = $conn->prepare('UPDATE onboarding_tasks SET done = ? WHERE id = ?');
    $stmt->bind_param('ii', $done, $tid);
    $stmt->execute();
    // affected_rows is 0 when the value was already this, so only ask when nothing changed.
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'onboarding_tasks', $tid, false)) {
        fail('Task not found.', 404);
    }
    json_out(['ok' => true]);
}

// Convert an applicant into a worker record + seed the onboarding checklist.
if ($action === 'onboard' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An applicant is required.');

    // One transaction, and the applicant row is locked inside it.
    //
    // This is five writes -- allocate a payroll number, insert the worker, link
    // the person to the service, mark the application, seed the checklist -- and
    // it had no transaction and no lock. Two things followed.
    //
    // A failure part way through left the halves that had succeeded: a worker
    // with a payroll number and no application pointing at them, or an
    // application marked onboarding with no checklist to work through.
    //
    // And the "already a worker" check was a plain SELECT, so two requests could
    // both read worker_id as NULL and both go on to insert. Reproduced with four
    // simultaneous requests in server/tests/db/cases/040_worker_conversion.php:
    // one applicant became FOUR workers, each with its own payroll number, and
    // the checklist was seeded three times over. FOR UPDATE holds the row until
    // this transaction ends, so the second request reads the first request's
    // work and refuses.
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        if (!$app) { $conn->rollback(); fail('Applicant not found.', 404); }
        require_service_scope_tx($conn, $user, $app['division']);
        if ($app['worker_id']) { $conn->rollback(); fail('This applicant is already a worker.', 409); }

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
        if (!$person) { $conn->rollback(); fail('This application is not linked to a person record.', 409); }

        // A payroll number is assigned once, here, and never reused. next_seq()
        // is atomic, and workers.payroll_no is UNIQUE (migration 001), so a
        // duplicate is refused by the database as well as avoided by the counter.
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

        // 'onboarding', not 'onboarded' (migration 031). What has just happened is
        // that a pending worker and a checklist of unfinished tasks exist. Saying
        // "onboarded" here left the pipeline claiming a candidate was through it
        // while none of the work had been done, and left no stage to mean cleared
        // and working. Reaching 'onboarded' is the activation step's job.
        $stmt = $conn->prepare('UPDATE applicants SET stage = "onboarding", worker_id = ? WHERE id = ?');
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
        $conn->commit();
    } catch (Throwable $e) {
        // Anything thrown from inside leaves nothing behind. Without this, a
        // failure after the worker insert left a worker holding a payroll number
        // that no application pointed at.
        $conn->rollback();
        throw $e;
    }

    // Audited after the commit, so the log records what is actually in the
    // database rather than what was attempted.
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
    // The division arrives in the body, so a scoped account could otherwise
    // create a candidate in a service it cannot then see or act on.
    require_service_scope($user, $division);
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
