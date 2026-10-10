<?php
// Storing and streaming uploaded documents.
//
// Not an endpoint. files.php (the office document centre) and me.php (a worker
// uploading their own evidence) both store files, and they must store them the
// same way: the same size limit, the same type check made on the contents rather
// than on what the browser claims, the same random name on disk. Two copies of
// that list would drift, and the copy that drifted would be the one an attacker
// found.
//
// Who may store or read a given file is decided by the caller before it gets
// here. This file only does the mechanics.

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

/**
 * Check and move one uploaded file into the store.
 *
 * $allowed narrows the accepted types further for a caller that wants fewer (a
 * worker's evidence is a photo or a PDF, never a spreadsheet).
 *
 * @param array $file one entry of $_FILES
 * @param array<string,string>|null $allowed mime => extension, a subset of ALLOWED_MIME
 * @return array{stored:string,original:string,mime:string,size:int}
 */
function rg_store_upload(array $file, ?array $allowed = null): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('No file was uploaded, or the upload failed.');
    }
    if ((int) $file['size'] > MAX_BYTES) fail('Files must be 10 MB or smaller.');

    // Detect MIME from contents, not the client-supplied type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $types = $allowed ?? ALLOWED_MIME;
    if (!isset($types[$mime])) fail('That file type is not allowed.');
    $ext = $types[$mime];

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $stored)) {
        fail('Could not save the file.', 500);
    }

    return [
        'stored' => $stored,
        'original' => mb_substr((string) $file['name'], 0, 255),
        'mime' => $mime,
        'size' => (int) $file['size'],
    ];
}

/**
 * Send a stored file to the browser as a download and stop.
 *
 * @param array{stored_name:string,original_name:string,mime:string} $f
 */
function rg_stream_file(array $f): void
{
    $path = UPLOAD_DIR . '/' . basename((string) $f['stored_name']);
    if (!is_file($path)) fail('File missing on disk.', 404);

    // Override the JSON content-type set by lib.php and stream the file.
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . rawurlencode((string) $f['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
