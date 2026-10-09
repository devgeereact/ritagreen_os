<?php
// §13 Recruitment & onboarding (ATS).
// POST /ats.php (public)                     -> submit an application (rate-limited)
// GET  /ats.php (staff)                      -> { applicants:[...] }
// GET  /ats.php?id=N                         -> { applicant, tasks:[...] }
// POST /ats.php?action=stage&id=N {stage, reason?}
// POST /ats.php?action=recruiter&id=N {recruiter_id|null}
// POST /ats.php?action=add_task&id=N {label, owner_id?, due_on?}
// POST /ats.php?action=task_done&task_id=N {done}
// POST /ats.php?action=task_assign&task_id=N {owner_id|null, due_on|null}
// POST /ats.php?action=interview&id=N {scheduled_at, interviewer_id?, location?, notes?}
// POST /ats.php?action=interview_outcome&interview_id=N {outcome, notes?}
// POST /ats.php?action=onboard&id=N          -> create a worker from the applicant + seed checklist
// POST /ats.php?action=from_learner {name,email,division} -> academy → ATS handoff
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';

// The fallback checklist, used only when a service has no active items in
// onboarding_template_items (migration 036). The per-service lists are what
// normally seeds a checklist; this keeps onboarding working if somebody retires
// every item for a service.
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
    // Captured immediately, BEFORE anything else inserts. Three more inserts
    // follow before the email is queued, and $conn->insert_id tracks the LAST
    // one -- so the queued message used to carry the notifications row's id as
    // its related_id, pointing anybody who followed it at the wrong record.
    $applicantId = (int) $conn->insert_id;

    // The first history row, so the candidate's timeline starts where they did.
    // changed_by is NULL: the public form has no user behind it, and naming one
    // would be an invention.
    record_stage($conn, $applicantId, null, 'applied', 'Applied through the careers page', null);

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
    $app['person_id'] = $app['person_id'] !== null ? (int) $app['person_id'] : null;
    $app['worker_id'] = $app['worker_id'] !== null ? (int) $app['worker_id'] : null;
    $app['recruiter_id'] = $app['recruiter_id'] !== null ? (int) $app['recruiter_id'] : null;

    // The recruiter's name, so the screen does not have to fetch the account list
    // to render one label.
    $app['recruiter_name'] = null;
    if ($app['recruiter_id'] !== null) {
        $stmt = $conn->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $app['recruiter_id']);
        $stmt->execute();
        $app['recruiter_name'] = $stmt->get_result()->fetch_assoc()['name'] ?? null;
    }

    $stmt = $conn->prepare(
        'SELECT t.id, t.label, t.done, t.due_on, t.done_at, t.owner_id, u.name AS owner_name
           FROM onboarding_tasks t
           LEFT JOIN users u ON u.id = t.owner_id
          WHERE t.applicant_id = ? ORDER BY t.due_on IS NULL, t.due_on, t.id'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $tasks = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['done'] = (int) $row['done'];
        $row['owner_id'] = $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        $tasks[] = $row;
    }

    $stmt = $conn->prepare(
        'SELECT i.id, i.scheduled_at, i.location, i.outcome, i.notes, i.decided_at,
                i.interviewer_id, u.name AS interviewer_name
           FROM applicant_interviews i
           LEFT JOIN users u ON u.id = i.interviewer_id
          WHERE i.applicant_id = ? AND i.deleted_at IS NULL
          ORDER BY i.scheduled_at DESC'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $interviews = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['interviewer_id'] = $row['interviewer_id'] !== null ? (int) $row['interviewer_id'] : null;
        $interviews[] = $row;
    }

    $stmt = $conn->prepare(
        'SELECT h.id, h.from_stage, h.to_stage, h.reason, h.changed_at, u.name AS changed_by_name
           FROM applicant_stage_history h
           LEFT JOIN users u ON u.id = h.changed_by
          WHERE h.applicant_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 100'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $history = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $history[] = $row;
    }

    json_out([
        'applicant' => $app,
        'tasks' => $tasks,
        'interviews' => $interviews,
        'history' => $history,
        // What this candidate may be moved to next, decided by the same function
        // the write path uses. The screen renders the options it is given rather
        // than keeping its own copy of the rules and drifting from them.
        'next_stages' => next_stages_for((string) $app['stage']),
    ]);
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
    $data = body();
    $stage = trim((string) ($data['stage'] ?? ''));
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($id <= 0 || $stage === '') fail('Valid applicant and stage required.');
    if (mb_strlen($reason) > 500) fail('Please keep the reason under 500 characters.');

    $prev = $conn->prepare('SELECT stage, division FROM applicants WHERE id = ?');
    $prev->bind_param('i', $id);
    $prev->execute();
    $before = $prev->get_result()->fetch_assoc();
    // No row means no applicant. Without this the stage change reported success
    // and wrote an audit entry against an id that does not exist.
    if (!$before) fail('Applicant not found.', 404);
    require_service_scope($user, $before['division']);
    $was = (string) $before['stage'];

    // Every stage was freely selectable, so a candidate could go from 'applied'
    // to 'onboarded' in one request -- no worker record, no checklist, no
    // verification, no approval. rg_stage_transition() in pure.php holds the
    // rules and is tested there; the message it returns is written to be read by
    // the person who tried, so it is passed straight through.
    $decision = rg_stage_transition($was, $stage, $reason !== '');
    if (!$decision['ok']) fail($decision['reason'], 409);

    // The reason belongs on the record for an exit, because "why did this person
    // not join" is the question asked months later. A note explaining a move
    // back a stage is history, not the candidate's outcome, so it stays in the
    // history table only.
    $isExit = in_array($stage, RG_STAGE_EXITS, true);
    if ($isExit) {
        $stmt = $conn->prepare('UPDATE applicants SET stage = ?, outcome_reason = ? WHERE id = ?');
        $stmt->bind_param('ssi', $stage, $reason, $id);
    } else {
        // Coming back into the pipeline clears the outcome: they did not leave.
        $stmt = $conn->prepare('UPDATE applicants SET stage = ?, outcome_reason = NULL WHERE id = ?');
        $stmt->bind_param('si', $stage, $id);
    }
    $stmt->execute();

    record_stage($conn, $id, $was, $stage, $reason !== '' ? $reason : null, (int) $user['id']);

    // A rejection is the entry somebody will ask about later.
    audit($conn, (int) $user['id'], 'ats.stage', "#$id", 'applicant', $id,
        ['stage' => $was], ['stage' => $stage, 'reason' => $reason !== '' ? $reason : null]);
    json_out(['ok' => true, 'stage' => $stage]);
}

// Who is dealing with this candidate. Answered in somebody's head before this.
if ($action === 'recruiter' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An applicant is required.');
    $raw = body()['recruiter_id'] ?? null;
    $recruiterId = ($raw === null || $raw === '') ? null : (int) $raw;

    $stmt = $conn->prepare('SELECT division, recruiter_id FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Applicant not found.', 404);
    require_service_scope($user, $row['division']);

    // A recruiter is an internal staff account. Assigning a client portal login
    // or a worker's login as the owner of a candidate would show them a record
    // they are not allowed to open.
    if ($recruiterId !== null && !is_assignable_staff($conn, $recruiterId)) {
        fail('A recruiter must be an internal staff account.', 422);
    }

    $stmt = $conn->prepare('UPDATE applicants SET recruiter_id = ? WHERE id = ?');
    $stmt->bind_param('ii', $recruiterId, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'ats.recruiter', "#$id", 'applicant', $id,
        ['recruiter_id' => $row['recruiter_id'] !== null ? (int) $row['recruiter_id'] : null],
        ['recruiter_id' => $recruiterId]);
    json_out(['ok' => true, 'recruiter_id' => $recruiterId]);
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
    [$ownerId, $dueOn] = read_task_assignment($conn, body());

    $stmt = $conn->prepare('INSERT INTO onboarding_tasks (applicant_id, label, owner_id, due_on) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isis', $id, $label, $ownerId, $dueOn);
    $stmt->execute();
    json_out(['ok' => true, 'id' => (int) $conn->insert_id], 201);
}

// Give an existing task an owner and a date, or take them away. A checklist
// nobody owns and nothing is due on cannot be chased, which is the only thing a
// checklist is for.
if ($action === 'task_assign' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $tid = (int) ($_GET['task_id'] ?? 0);
    if ($tid <= 0) fail('A task is required.');

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

    [$ownerId, $dueOn] = read_task_assignment($conn, body());

    $stmt = $conn->prepare('UPDATE onboarding_tasks SET owner_id = ?, due_on = ? WHERE id = ?');
    $stmt->bind_param('isi', $ownerId, $dueOn, $tid);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'ats.task_assign', "task #$tid", 'onboarding_task', $tid,
        null, ['owner_id' => $ownerId, 'due_on' => $dueOn]);
    json_out(['ok' => true]);
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

    // Who ticked it and when. "Right to work verified" with nobody's name against
    // it is not evidence that anybody verified anything, and un-ticking clears
    // both rather than leaving a stale completion behind.
    $uid = (int) $user['id'];
    if ($done === 1) {
        $stmt = $conn->prepare('UPDATE onboarding_tasks SET done = 1, done_at = NOW(), done_by = ? WHERE id = ?');
        $stmt->bind_param('ii', $uid, $tid);
    } else {
        $stmt = $conn->prepare('UPDATE onboarding_tasks SET done = 0, done_at = NULL, done_by = NULL WHERE id = ?');
        $stmt->bind_param('i', $tid);
    }
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

        // A candidate who has left the pipeline is not onboarded from where they
        // left it. Bring them back in first, which records why -- otherwise the
        // history shows somebody rejected and then, with no explanation between,
        // employed.
        if (in_array((string) $app['stage'], RG_STAGE_EXITS, true)) {
            $conn->rollback();
            fail('This candidate has left the pipeline. Bring them back in before starting onboarding.', 409);
        }

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

        record_stage($conn, $id, (string) $app['stage'], 'onboarding',
            "Onboarding started — worker #$workerId created", (int) $user['id']);

        // Seed the onboarding checklist if none exists yet, from the list kept for
        // this service (migration 036). Tasks already added by hand are left as
        // they are: an existing checklist is somebody's work in progress.
        $existing = $conn->query('SELECT COUNT(*) FROM onboarding_tasks WHERE applicant_id = ' . $id)->fetch_row()[0];
        if ((int) $existing === 0) {
            $ins = $conn->prepare('INSERT INTO onboarding_tasks (applicant_id, label) VALUES (?, ?)');
            foreach (onboarding_checklist_for($conn, (string) $app['division']) as $label) {
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

// ---- Interviews ------------------------------------------------------------
//
// The date, the interviewer and what was decided lived in a calendar and an
// inbox. An interview that nobody recorded the outcome of is indistinguishable
// from one that never happened.

if ($action === 'interview' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An applicant is required.');
    $data = body();

    $stmt = $conn->prepare('SELECT division FROM applicants WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Applicant not found.', 404);
    require_service_scope($user, $row['division']);

    // Wall-clock, like shifts.starts_at. An interview is at 10:00 in the
    // office's day; it is not an instant to be converted on the way in.
    $when = trim((string) ($data['scheduled_at'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $when)) {
        fail('An interview date and time are required (YYYY-MM-DD HH:MM).');
    }
    $when = str_replace('T', ' ', $when);
    if (strlen($when) === 16) $when .= ':00';

    $rawInterviewer = $data['interviewer_id'] ?? null;
    $interviewerId = ($rawInterviewer === null || $rawInterviewer === '') ? null : (int) $rawInterviewer;
    if ($interviewerId !== null && !is_assignable_staff($conn, $interviewerId)) {
        fail('An interviewer must be an internal staff account.', 422);
    }

    $location = trim((string) ($data['location'] ?? ''));
    if (mb_strlen($location) > 160) fail('That location is too long.');
    $notes = trim((string) ($data['notes'] ?? ''));
    if (mb_strlen($notes) > 5000) fail('Please keep the notes under 5000 characters.');

    $locationVal = $location !== '' ? $location : null;
    $notesVal = $notes !== '' ? $notes : null;
    $uid = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO applicant_interviews (applicant_id, scheduled_at, interviewer_id, location, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isissi', $id, $when, $interviewerId, $locationVal, $notesVal, $uid);
    $stmt->execute();
    $interviewId = (int) $conn->insert_id;

    audit($conn, $uid, 'ats.interview', "applicant #$id at $when", 'applicant', $id,
        null, ['interview_id' => $interviewId, 'scheduled_at' => $when, 'interviewer_id' => $interviewerId]);
    json_out(['ok' => true, 'id' => $interviewId], 201);
}

if ($action === 'interview_outcome' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $iid = (int) ($_GET['interview_id'] ?? 0);
    if ($iid <= 0) fail('An interview is required.');
    $data = body();

    $outcome = trim((string) ($data['outcome'] ?? ''));
    if (!in_array($outcome, ['scheduled', 'passed', 'failed', 'no_show', 'cancelled'], true)) {
        fail('That is not an interview outcome.');
    }
    $notes = trim((string) ($data['notes'] ?? ''));
    if (mb_strlen($notes) > 5000) fail('Please keep the notes under 5000 characters.');

    $stmt = $conn->prepare(
        'SELECT i.id, i.applicant_id, i.outcome, i.notes, a.division
           FROM applicant_interviews i
           JOIN applicants a ON a.id = i.applicant_id
          WHERE i.id = ? AND i.deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $iid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Interview not found.', 404);
    require_service_scope($user, $row['division']);

    $uid = (int) $user['id'];
    // Notes given now are added; notes left blank do not erase what is there.
    $notesVal = $notes !== '' ? $notes : ($row['notes'] ?? null);
    // 'scheduled' is the absence of a decision, so it clears the decision fields
    // rather than recording somebody as having decided nothing.
    if ($outcome === 'scheduled') {
        $stmt = $conn->prepare(
            'UPDATE applicant_interviews SET outcome = ?, notes = ?, decided_at = NULL, decided_by = NULL WHERE id = ?'
        );
        $stmt->bind_param('ssi', $outcome, $notesVal, $iid);
    } else {
        $stmt = $conn->prepare(
            'UPDATE applicant_interviews SET outcome = ?, notes = ?, decided_at = NOW(), decided_by = ? WHERE id = ?'
        );
        $stmt->bind_param('ssii', $outcome, $notesVal, $uid, $iid);
    }
    $stmt->execute();

    // Deliberately NOT moving the candidate's stage. An interview outcome is
    // evidence; deciding what it means for the pipeline is a person's judgement,
    // and a passed interview is not the only thing that has to be true before
    // vetting starts.
    audit($conn, $uid, 'ats.interview_outcome', "interview #$iid", 'applicant', (int) $row['applicant_id'],
        ['outcome' => $row['outcome']], ['outcome' => $outcome]);
    json_out(['ok' => true, 'outcome' => $outcome]);
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
    $newId = (int) $conn->insert_id;
    record_stage($conn, $newId, null, 'screened', 'Referred from the Academy', (int) $user['id']);
    audit($conn, (int) $user['id'], 'ats.from_learner', $name);
    json_out(['ok' => true, 'id' => $newId], 201);
}

fail('Method not allowed.', 405);

// ---------------------------------------------------------------------------

/**
 * The stages this candidate may be moved to, and what each needs.
 *
 * Derived from rg_stage_transition() rather than listed again here: one set of
 * rules, used by the screen that offers the choice and by the branch that
 * applies it.
 *
 * @return list<array{stage: string, needs_reason: bool}>
 */
function next_stages_for(string $current): array
{
    $out = [];
    foreach (array_merge(RG_STAGES, RG_STAGE_EXITS) as $candidate) {
        // Ask twice: without a reason, then with one. A stage that is only
        // allowed the second time is a stage that requires one.
        if (rg_stage_transition($current, $candidate, false)['ok']) {
            $out[] = ['stage' => $candidate, 'needs_reason' => false];
        } elseif (rg_stage_transition($current, $candidate, true)['ok']) {
            $out[] = ['stage' => $candidate, 'needs_reason' => true];
        }
    }
    return $out;
}

/**
 * Record a stage change.
 *
 * audit_log already records it, and it is the wrong place to read this from: it
 * is a security record spanning every table, and a recruiter looking at one
 * candidate should not be handed a tool that pages through everybody's.
 */
function record_stage(mysqli $conn, int $applicantId, ?string $from, string $to, ?string $reason, ?int $userId): void
{
    $stmt = $conn->prepare(
        'INSERT INTO applicant_stage_history (applicant_id, from_stage, to_stage, reason, changed_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssi', $applicantId, $from, $to, $reason, $userId);
    $stmt->execute();
}

/**
 * May this user be given a candidate, a task or an interview to own?
 *
 * Internal staff only. A client portal login and a worker's login are ordinary
 * user rows, so without this an owner could be set to somebody who is then shown
 * -- or emailed about -- a record they are not allowed to open.
 */
function is_assignable_staff(mysqli $conn, int $userId): bool
{
    $stmt = $conn->prepare(
        'SELECT role, client_id, worker_id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row !== null && is_staff($row);
}

/**
 * The owner and due date from a request body, validated.
 *
 * Shared by add_task and task_assign so the two cannot drift into accepting
 * different things. Returns [?int $ownerId, ?string $dueOn].
 */
function read_task_assignment(mysqli $conn, array $data): array
{
    $rawOwner = $data['owner_id'] ?? null;
    $ownerId = ($rawOwner === null || $rawOwner === '') ? null : (int) $rawOwner;
    if ($ownerId !== null && !is_assignable_staff($conn, $ownerId)) {
        fail('A task owner must be an internal staff account.', 422);
    }

    $rawDue = trim((string) ($data['due_on'] ?? ''));
    $dueOn = $rawDue !== '' ? $rawDue : null;
    if ($dueOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn)) {
        fail('A due date must be YYYY-MM-DD.');
    }

    return [$ownerId, $dueOn];
}

/**
 * The checklist a new worker in this service starts with.
 *
 * The office keeps one list per service in onboarding_template_items, so a
 * cleaner is not asked for a DBS check and a security officer is asked for an
 * SIA licence. Falls back to the old fixed list only when a service has none.
 *
 * @return list<string>
 */
function onboarding_checklist_for(mysqli $conn, string $service): array
{
    $stmt = $conn->prepare(
        'SELECT label FROM onboarding_template_items WHERE service = ? AND active = 1 ORDER BY sort_order, id'
    );
    $stmt->bind_param('s', $service);
    $stmt->execute();
    $res = $stmt->get_result();
    $labels = [];
    while ($row = $res->fetch_assoc()) $labels[] = (string) $row['label'];
    return $labels !== [] ? $labels : ONBOARDING_CHECKLIST;
}
