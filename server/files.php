<?php
// Files & document centre.
// GET  /files.php                       -> { files: [...] }  filtered per file
// POST /files.php  (multipart: file, category, client_id?, worker_id?, person_id?) -> upload
// GET  /files.php?action=download&id=5  -> stream the file
// POST /files.php?action=delete&id=5    -> soft delete
//
// The whole file is restricted to staff roles, and each individual file is then
// checked with rg_file_readable(): the vault holds company material and named
// people's records in the same place, and being allowed to open it is not
// permission to open everything in it.
require __DIR__ . '/lib.php';
require __DIR__ . '/files-lib.php';

$user = require_auth($conn);
// The document centre holds sensitive HR/compliance files (DBS scans, contracts,
// IDs). Restrict the whole endpoint, including download and list, to staff
// roles; 'employee'/'client'/'trainer' have no business browsing it. delete and
// upload below keep their own stricter role checks.
require_role($user, 'super_admin', 'admin', 'manager', 'editor');
$action = $_GET['action'] ?? '';

// UPLOAD_DIR, MAX_BYTES and ALLOWED_MIME live in files-lib.php, shared with the
// worker portal's own uploads (me.php).

if ($action === 'download' && method() === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A file is required.');
    $stmt = $conn->prepare('SELECT stored_name, original_name, mime, category, person_id, worker_id, client_id FROM files WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) fail('File not found.', 404);
    if (!rg_file_readable($f, $user)) fail('You do not have access to this.', 403);
    rg_stream_file($f);
}

// action=extract (plain text for AI summarising) was retired with ai.php in S9.
// An unknown action falls through to the list below, as before.

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT f.id, f.original_name, f.mime, f.size_bytes, f.category, f.created_at,
                f.person_id, f.worker_id, f.client_id, u.name AS uploaded_by
         FROM files f LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.deleted_at IS NULL ORDER BY f.created_at DESC'
    );
    $files = [];
    while ($row = $res->fetch_assoc()) {
        // Filenames leak too -- "Ade Okoro DBS certificate.pdf" is disclosure on
        // its own, so a file that cannot be opened is not listed either.
        if (!rg_file_readable($row, $user)) continue;
        $row['id'] = (int) $row['id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        unset($row['person_id'], $row['worker_id'], $row['client_id']);
        $files[] = $row;
    }
    json_out(['files' => $files]);
}

if ($action === 'delete' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A file is required.');
    // Already-deleted is not an error, but no such file is.
    $stmt = $conn->prepare('UPDATE files SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows === 0 && !row_exists($conn, 'files', $id, false)) fail('File not found.', 404);
    audit($conn, (int) $user['id'], 'file.delete', "#$id");
    json_out(['ok' => true]);
}

// Upload (multipart/form-data).
if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'editor');
    if (!isset($_FILES['file'])) fail('No file was uploaded, or the upload failed.');
    $category = trim((string) ($_POST['category'] ?? 'other'));
    if (!in_array($category, ['contract', 'certificate', 'policy', 'rams', 'id', 'other'], true)) $category = 'other';
    $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int) $_POST['client_id'] : null;
    $workerId = isset($_POST['worker_id']) && $_POST['worker_id'] !== '' ? (int) $_POST['worker_id'] : null;
    $personId = isset($_POST['person_id']) && $_POST['person_id'] !== '' ? (int) $_POST['person_id'] : null;

    // Evidence about a named person is filed against that person, so the profile,
    // a verification and a contract can all find it -- including for an applicant
    // who has no worker record yet. A worker's file is filed against their person
    // too. Either way the caller has to be allowed to see that person.
    if ($workerId !== null) {
        $stmt = $conn->prepare('SELECT person_id, division FROM workers WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $w = $stmt->get_result()->fetch_assoc();
        if (!$w) fail('Worker not found.', 404);
        require_service_scope($user, $w['division']);
        if ($personId !== null && $w['person_id'] !== null && (int) $w['person_id'] !== $personId) {
            fail('That worker record belongs to a different person.', 422);
        }
        $personId ??= $w['person_id'] !== null ? (int) $w['person_id'] : null;
    }
    if ($personId !== null) {
        if (!row_exists($conn, 'people', $personId)) fail('Person not found.', 404);
        require_person_scope($conn, $user, $personId);
    }

    $up = rg_store_upload($_FILES['file']);
    $stored = $up['stored'];
    $original = $up['original'];
    $mime = $up['mime'];
    $size = $up['size'];
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'INSERT INTO files (stored_name, original_name, mime, size_bytes, category, client_id, worker_id, person_id, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssisiiii', $stored, $original, $mime, $size, $category, $clientId, $workerId, $personId, $uid);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, $uid, 'file.upload', $original, 'file', (int) $id,
        null, ['person_id' => $personId, 'worker_id' => $workerId, 'category' => $category]);
    json_out([
        'file' => [
            'id' => (int) $id, 'original_name' => $original, 'mime' => $mime,
            'size_bytes' => $size, 'category' => $category, 'uploaded_by' => $user['name'],
        ],
    ], 201);
}

fail('Method not allowed.', 405);
