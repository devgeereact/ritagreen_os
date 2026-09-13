<?php
// Jobs / work orders.
// GET  /jobs.php                       -> { jobs: [...] }
// POST /jobs.php {title, division, type, priority, client_id?, scheduled_for?, notes?}
// POST /jobs.php?action=status&id=12 {status} -> advance the pipeline
// POST /jobs.php?action=update&id=12 {title?, division?, type?, priority?, client_id?, scheduled_for?, notes?}
// POST /jobs.php?action=archive&id=12 -> soft delete
require __DIR__ . '/lib.php';

/** The vocabularies the jobs table accepts, in one place. */
const JOB_DIVISIONS = ['care', 'academy', 'cleaning', 'security'];
const JOB_TYPES = ['care_visit', 'clean', 'guard_shift', 'course', 'other'];
const JOB_PRIORITIES = ['emergency', 'high', 'standard'];
const JOB_STATUSES = ['booked', 'assigned', 'in_progress', 'done', 'cancelled'];

/** Rows returned per read. */
const JOB_PAGE = 500;

/**
 * Validate and normalise a job payload. Shared by create and update so the two
 * cannot drift -- create validated four fields and update did not exist, so
 * there was no way to correct a typo in a job title at all.
 */
function job_fields(array $data, mysqli $conn): array
{
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') fail('A job title is required.');
    if (mb_strlen($title) > 190) fail('That title is too long. Keep it under 190 characters.');

    $division = trim((string) ($data['division'] ?? 'care'));
    $type = trim((string) ($data['type'] ?? 'other'));
    $priority = trim((string) ($data['priority'] ?? 'standard'));
    if (!in_array($division, JOB_DIVISIONS, true)) fail('Invalid division.');
    if (!in_array($type, JOB_TYPES, true)) fail('Invalid type.');
    if (!in_array($priority, JOB_PRIORITIES, true)) fail('Invalid priority.');

    $notes = trim((string) ($data['notes'] ?? ''));
    if (mb_strlen($notes) > 5000) fail('Those notes are too long.');

    $clientId = isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null
        ? (int) $data['client_id']
        : null;
    // A client_id that does not exist reached the INSERT and came back as a
    // foreign-key 500 with the constraint name in it, plus a logged exception,
    // for what is only a stale id from a page left open.
    if ($clientId !== null && !row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);

    // strtotime() returns false on anything it cannot read, which date() then
    // treated as the epoch: a typo in the date field silently scheduled the job
    // for 1970-01-01 00:00:00, with no error and no way to notice.
    $scheduled = trim((string) ($data['scheduled_for'] ?? ''));
    $scheduledVal = null;
    if ($scheduled !== '') {
        $ts = strtotime($scheduled);
        if ($ts === false) fail('That scheduled date is not a date we can read.');
        $scheduledVal = date('Y-m-d H:i:s', $ts);
    }

    return [
        'title' => $title, 'division' => $division, 'type' => $type, 'priority' => $priority,
        'client_id' => $clientId, 'scheduled_for' => $scheduledVal,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

/** One job, in the shape the list returns. */
function job_row(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT j.id, j.client_id, c.name AS client, j.division, j.type, j.title,
                j.priority, j.status, j.scheduled_for, j.notes, j.created_at
         FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
         WHERE j.id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
    return $row;
}

$user = require_auth($conn);
// Jobs name clients and sites. Internal staff only -- the reads were open to
// any logged-in account, which includes client portal and worker logins.
require_staff($user);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    // client_id filters server-side. ClientDetail and Insights both fetched the
    // whole list and filtered in the browser, which quietly computed against a
    // truncated set once the list passed the page size.
    $where = ['j.deleted_at IS NULL'];
    $types = '';
    $args = [];
    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId > 0) {
        $where[] = 'j.client_id = ?';
        $types .= 'i';
        $args[] = $clientId;
    }
    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, JOB_STATUSES, true)) fail('Unknown status filter.');
        $where[] = 'j.status = ?';
        $types .= 's';
        $args[] = $status;
    }

    // notes are collected when a job is raised and were never returned, so
    // whatever the office wrote about the visit was invisible from the moment
    // the dialog closed.
    $stmt = $conn->prepare(
        'SELECT j.id, j.client_id, c.name AS client, j.division, j.type, j.title,
                j.priority, j.status, j.scheduled_for, j.notes, j.created_at
         FROM jobs j LEFT JOIN clients c ON c.id = j.client_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY FIELD(j.priority,"emergency","high","standard"), j.created_at DESC
         LIMIT ' . JOB_PAGE
    );
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();
    $jobs = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $jobs[] = $row;
    }
    json_out(['jobs' => $jobs, 'truncated' => count($jobs) === JOB_PAGE]);
}

if ($action === 'update' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A job is required.');
    $was = job_row($conn, $id);
    if (!$was) fail('Job not found.', 404);

    $f = job_fields(body(), $conn);
    $stmt = $conn->prepare(
        'UPDATE jobs SET title = ?, division = ?, type = ?, priority = ?, client_id = ?,
                         scheduled_for = ?, notes = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param(
        'ssssissi',
        $f['title'], $f['division'], $f['type'], $f['priority'],
        $f['client_id'], $f['scheduled_for'], $f['notes'], $id
    );
    $stmt->execute();

    [$before, $after] = audit_diff($was, $f, ['title', 'division', 'type', 'priority', 'client_id', 'scheduled_for', 'notes']);
    audit($conn, (int) $user['id'], 'job.update', "#$id", 'job', $id, $before, $after);
    json_out(['job' => job_row($conn, $id)]);
}

if ($action === 'archive' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A job is required.');
    // Archived, never hard-deleted: a work order is a record of what was asked
    // for. deleted_at has been on this table since migration 013 and every query
    // already respected it -- nothing ever set it, so there was no way to take a
    // job off the board short of leaving it cancelled forever.
    $stmt = $conn->prepare('UPDATE jobs SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) fail('Job not found.', 404);
    audit($conn, (int) $user['id'], 'job.archive', "#$id", 'job', $id);
    json_out(['ok' => true]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'employee');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    if ($id <= 0 || !in_array($status, JOB_STATUSES, true)) fail('A job and a valid status are required.');

    $stmt = $conn->prepare('UPDATE jobs SET status = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        // affected_rows is 0 if the value is unchanged; confirm the row exists.
        $check = $conn->prepare('SELECT id FROM jobs WHERE id = ? AND deleted_at IS NULL');
        $check->bind_param('i', $id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) fail('Job not found.', 404);
    }
    audit($conn, (int) $user['id'], 'job.status', "#$id -> $status");
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $f = job_fields(body(), $conn);

    $stmt = $conn->prepare(
        'INSERT INTO jobs (client_id, division, type, title, priority, scheduled_for, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    // client_id, division, type, title, priority, scheduled_for, notes
    $stmt->bind_param(
        'issssss',
        $f['client_id'], $f['division'], $f['type'], $f['title'],
        $f['priority'], $f['scheduled_for'], $f['notes']
    );
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'job.create', $f['title'], 'job', (int) $id);
    // Read back rather than reassembling: the hand-built body said
    // 'client' => null even when a client was given, and omitted created_at,
    // which src/lib/types.ts declares required.
    json_out(['job' => job_row($conn, (int) $id)], 201);
}

fail('Method not allowed.', 405);
