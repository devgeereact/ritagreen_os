<?php
// Academy LMS.
// GET  /academy.php                 -> { programmes: [...] }
// GET  /academy.php?id=5            -> { programme, enrolments }
// POST /academy.php                 -> create programme {title, description?, division?, status?}
// POST /academy.php?action=publish&id=5      {status}
// POST /academy.php?action=enrol             {programme_id, learner_name}
// POST /academy.php?action=complete&enrolment_id=9  -> completes + issues a certificate
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

// Single programme + its learners.
if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare(
        'SELECT id, title, description, division, status, created_at FROM programmes WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $programme = $stmt->get_result()->fetch_assoc();
    if (!$programme) fail('Programme not found.', 404);
    $programme['id'] = (int) $programme['id'];

    $stmt = $conn->prepare(
        'SELECT id, learner_name, status, certificate_public_id, created_at
         FROM enrolments WHERE programme_id = ? ORDER BY learner_name'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $enrolments = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $enrolments[] = $row;
    }
    json_out(['programme' => $programme, 'enrolments' => $enrolments]);
}

// Programme list with learner counts.
if (method() === 'GET') {
    $res = $conn->query(
        'SELECT p.id, p.title, p.division, p.status, p.created_at,
                (SELECT COUNT(*) FROM enrolments e WHERE e.programme_id = p.id) AS enrolments
         FROM programmes p ORDER BY p.created_at DESC'
    );
    $programmes = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['enrolments'] = (int) $row['enrolments'];
        $programmes[] = $row;
    }
    json_out(['programmes' => $programmes]);
}

if ($action === 'enrol' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'trainer');
    $data = body();
    $pid = (int) ($data['programme_id'] ?? 0);
    $learner = trim((string) ($data['learner_name'] ?? ''));
    if ($pid <= 0 || $learner === '') fail('A programme and learner name are required.');

    $stmt = $conn->prepare('SELECT id FROM programmes WHERE id = ?');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Programme not found.', 404);

    $stmt = $conn->prepare('INSERT INTO enrolments (programme_id, learner_name, status) VALUES (?, ?, "enrolled")');
    $stmt->bind_param('is', $pid, $learner);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'enrolment.create', "$learner -> programme #$pid");
    json_out(['ok' => true], 201);
}

if ($action === 'complete' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'trainer');
    $eid = (int) ($_GET['enrolment_id'] ?? 0);
    if ($eid <= 0) fail('An enrolment is required.');

    $stmt = $conn->prepare(
        'SELECT e.id, e.learner_name, e.certificate_public_id, p.title
         FROM enrolments e JOIN programmes p ON p.id = e.programme_id WHERE e.id = ?'
    );
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $enr = $stmt->get_result()->fetch_assoc();
    if (!$enr) fail('Enrolment not found.', 404);
    if ($enr['certificate_public_id']) {
        json_out(['ok' => true, 'public_id' => $enr['certificate_public_id']]);
    }

    // Issue a verifiable certificate. ID format RG-<year>-#####.
    $year = date('Y');
    $seq = (int) ($conn->query('SELECT COUNT(*) FROM certificates')->fetch_row()[0] ?? 0) + 1;
    $publicId = sprintf('RG-%s-%05d', $year, $seq);
    $today = date('Y-m-d');

    $stmt = $conn->prepare(
        'INSERT INTO certificates (public_id, learner_name, course, issued_on, status)
         VALUES (?, ?, ?, ?, "issued")'
    );
    $stmt->bind_param('ssss', $publicId, $enr['learner_name'], $enr['title'], $today);
    $stmt->execute();

    $stmt = $conn->prepare('UPDATE enrolments SET status = "completed", certificate_public_id = ? WHERE id = ?');
    $stmt->bind_param('si', $publicId, $eid);
    $stmt->execute();

    audit($conn, (int) $user['id'], 'certificate.issue', "$publicId for " . $enr['learner_name']);
    json_out(['ok' => true, 'public_id' => $publicId], 201);
}

if ($action === 'publish' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'editor');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['draft', 'published', 'archived'], true)) fail('A programme and valid status are required.');
    $stmt = $conn->prepare('UPDATE programmes SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    json_out(['ok' => true]);
}

// Create programme.
if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'editor');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $division = trim((string) ($data['division'] ?? 'academy'));
    $status = trim((string) ($data['status'] ?? 'draft'));
    if ($title === '') fail('A programme title is required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if (!in_array($status, ['draft', 'published', 'archived'], true)) $status = 'draft';

    $descVal = $description !== '' ? $description : null;
    $stmt = $conn->prepare('INSERT INTO programmes (title, description, division, status) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $title, $descVal, $division, $status);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, (int) $user['id'], 'programme.create', $title);
    json_out([
        'programme' => [
            'id' => (int) $id, 'title' => $title, 'description' => $descVal,
            'division' => $division, 'status' => $status, 'enrolments' => 0,
        ],
    ], 201);
}

fail('Method not allowed.', 405);
