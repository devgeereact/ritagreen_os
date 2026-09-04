<?php
// Files & document centre.
// GET  /files.php                       -> { files: [...] }
// POST /files.php  (multipart: file, category, client_id?, worker_id?) -> upload
// GET  /files.php?action=download&id=5  -> stream the file (auth required)
// POST /files.php?action=delete&id=5    -> soft delete
require __DIR__ . '/lib.php';

// Pull readable text out of an OOXML file (docx/xlsx) by reading one XML entry
// from the zip and stripping tags. Paragraph/cell boundaries become newlines.
function docx_text(string $path, string $entry): string
{
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName($entry);
    $zip->close();
    if ($xml === false) return '';
    $xml = preg_replace('#</w:p>|</a:p>|</text:p>|</si>#', "\n", $xml);
    $xml = str_replace(['</w:tr>', '</row>'], "\n", $xml);
    $txt = strip_tags($xml);
    return html_entity_decode($txt, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Best-effort PDF text extraction: decode Flate streams where present and pull
// the literal strings out of text-show operators. Works on most digitally
// generated PDFs; scanned/image-only PDFs yield nothing.
function pdf_text(string $path): string
{
    $raw = (string) file_get_contents($path);
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
        foreach ($m[1] as $stream) {
            $data = @gzuncompress($stream);
            if ($data === false) $data = $stream; // uncompressed stream
            if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)|\[(?:[^\]\\\\]|\\\\.)*\]/s', $data, $tm)) {
                foreach ($tm[0] as $tok) {
                    $tok = preg_replace('/^\[|\]$/', '', $tok);
                    if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $tok, $sm)) {
                        foreach ($sm[0] as $s) {
                            $s = substr($s, 1, -1);
                            $s = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $s);
                            $out .= $s;
                        }
                        $out .= ' ';
                    }
                }
                $out .= "\n";
            }
        }
    }
    return $out;
}

$user = require_auth($conn);
// The document centre holds sensitive HR/compliance files (DBS scans, contracts,
// IDs). Restrict the whole endpoint — including download/extract/list — to staff
// roles; 'employee'/'client'/'trainer' have no business browsing it. delete and
// upload below keep their own stricter role checks.
require_role($user, 'super_admin', 'admin', 'manager', 'editor');
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
    $stmt = $conn->prepare('SELECT stored_name, original_name, mime, category, person_id, worker_id, client_id FROM files WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) fail('File not found.', 404);
    if (!rg_file_readable($f, $user)) fail('You do not have access to this.', 403);
    $path = UPLOAD_DIR . '/' . $f['stored_name'];
    if (!is_file($path)) fail('File missing on disk.', 404);

    // Override the JSON content-type set by lib.php and stream the file.
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . rawurlencode($f['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Extract plain text from a stored document so the browser can summarise it via
// ai.php. Handles the text-based formats; binary/image types return empty text
// with a reason. Best-effort for PDF (decodes Flate streams where present).
if ($action === 'extract' && method() === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A file is required.');
    $stmt = $conn->prepare('SELECT stored_name, original_name, mime, category, person_id, worker_id, client_id FROM files WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $f = $stmt->get_result()->fetch_assoc();
    if (!$f) fail('File not found.', 404);
    // Extraction feeds ai.php, which sends the text to a third-party model. The
    // same rule as download applies, and more sharply.
    if (!rg_file_readable($f, $user)) fail('You do not have access to this.', 403);
    $path = UPLOAD_DIR . '/' . $f['stored_name'];
    if (!is_file($path)) fail('File missing on disk.', 404);

    $mime = $f['mime'];
    $text = '';
    $note = null;

    if ($mime === 'text/plain' || $mime === 'text/csv') {
        $text = (string) file_get_contents($path);
    } elseif ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        $text = docx_text($path, 'word/document.xml');
    } elseif ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        $text = docx_text($path, 'xl/sharedStrings.xml');
    } elseif ($mime === 'application/pdf') {
        $text = pdf_text($path);
        if (trim($text) === '') $note = 'This PDF appears to be scanned or image-only, so no text could be read.';
    } else {
        $note = 'This file type cannot be read as text (images and legacy .doc are not supported).';
    }

    $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\s*\n\s*/', "\n", $text)) ?? '');
    $truncated = false;
    if (mb_strlen($text) > 12000) {
        $text = mb_substr($text, 0, 12000);
        $truncated = true;
    }

    json_out([
        'name' => $f['original_name'],
        'mime' => $mime,
        'text' => $text,
        'truncated' => $truncated,
        'note' => $note,
    ]);
}

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
