<?php
// Files & document centre.
// GET  /files.php                       -> { files: [...] }  filtered per file
// POST /files.php  (multipart: file, category, client_id?, worker_id?, person_id?) -> upload
// GET  /files.php?action=download&id=5  -> stream the file
// GET  /files.php?action=extract&id=5   -> { text } for the browser to summarise
// POST /files.php?action=delete&id=5    -> soft delete
//
// The whole file is restricted to staff roles, and each individual file is then
// checked with rg_file_readable(): the vault holds company material and named
// people's records in the same place, and being allowed to open it is not
// permission to open everything in it.
require __DIR__ . '/lib.php';
require __DIR__ . '/files-lib.php';

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
