<?php
// Files & document centre.
// GET  /files.php                       -> { files: [...] }
// POST /files.php  (multipart: file, category, client_id?, worker_id?) -> upload
// GET  /files.php?action=download&id=5  -> stream the file (auth required)
// POST /files.php?action=delete&id=5    -> soft delete
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'text/plain' => 'txt',
    'text/csv' => 'csv',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/msword' => 'doc',
];

if ($action === 'download' && method() === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A file is required.');
    $stmt = $conn->prepare('SELECT stored_name, original_name, mime FROM files WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) fail('File not found.', 404);
    $path = UPLOAD_DIR . '/' . $f['stored_name'];
    if (!is_file($path)) fail('File missing on disk.', 404);

    // Override the JSON content-type set by lib.php and stream the file.
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($f['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT f.id, f.original_name, f.mime, f.size_bytes, f.category, f.created_at, u.name AS uploaded_by
         FROM files f LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.deleted_at IS NULL ORDER BY f.created_at DESC'
    );
    $files = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        $files[] = $row;
    }
    json_out(['files' => $files]);
}

if ($action === 'delete' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A file is required.');
    $stmt = $conn->prepare('UPDATE files SET deleted_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'file.delete', "#$id");
    json_out(['ok' => true]);
}

// Upload (multipart/form-data).
if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'editor');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail('No file was uploaded, or the upload failed.');
    }
    $file = $_FILES['file'];
    if ($file['size'] > MAX_BYTES) fail('Files must be 10 MB or smaller.');

    // Detect MIME from contents, not the client-supplied type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset(ALLOWED_MIME[$mime])) fail('That file type is not allowed.');
    $ext = ALLOWED_MIME[$mime];

    $category = trim((string) ($_POST['category'] ?? 'other'));
    if (!in_array($category, ['contract', 'certificate', 'policy', 'rams', 'id', 'other'], true)) $category = 'other';
    $clientId = isset($_POST['client_id']) && $_POST['client_id'] !== '' ? (int) $_POST['client_id'] : null;
    $workerId = isset($_POST['worker_id']) && $_POST['worker_id'] !== '' ? (int) $_POST['worker_id'] : null;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
        fail('Could not save the file.', 500);
    }

    $original = mb_substr((string) $file['name'], 0, 255);
    $size = (int) $file['size'];
    $uid = (int) $user['id'];
    $stmt = $conn->prepare(
        'INSERT INTO files (stored_name, original_name, mime, size_bytes, category, client_id, worker_id, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssisiii', $stored, $original, $mime, $size, $category, $clientId, $workerId, $uid);
    $stmt->execute();
    $id = $conn->insert_id;

    audit($conn, $uid, 'file.upload', $original);
    json_out([
        'file' => [
            'id' => (int) $id, 'original_name' => $original, 'mime' => $mime,
            'size_bytes' => $size, 'category' => $category, 'uploaded_by' => $user['name'],
        ],
    ], 201);
}

fail('Method not allowed.', 405);
