<?php
// Spreadsheet import (2026 plan, file 06, "Spreadsheet import", section S8).
//
//   GET  /sheet-import.php?action=mappings          saved mappings, forms, the field list
//   GET  /sheet-import.php?action=history           import history
//   GET  /sheet-import.php?id=N                     one import: mapping and the preview
//   GET  /sheet-import.php?action=failures&id=N     failed rows as CSV
//   POST /sheet-import.php?action=upload            {file_name, csv} -> {id}
//   POST /sheet-import.php?action=map&id=N          {columns, purpose, default_service, save?}
//   POST /sheet-import.php?action=apply&id=N        {rows:[{id, choice?}]}
//   POST /sheet-import.php?action=close&id=N        clear the held cells
//
// Upload, map, preview, match, approve, apply, report. Nothing is written to a
// person, an application or a worker until apply, and apply recomputes every
// row's outcome inside its own transaction rather than trusting the preview the
// browser saw: the preview can be minutes old, and the browser is not trusted
// to say what a row means. The browser sends row ids and, for an uncertain
// match, which of the offered people it is (or "new"). Never values.
//
// What an import may write: a new application, through find_or_create_person()
// and rg_create_applicant() exactly as the form feed does; and for somebody
// already on staff, their phone, email, address, first emergency contact and
// availability (rg_save_contact_details()). Never compliance, verification, pay
// or employment status. Nobody is made ready to work by an import.
//
// The uploaded file is parsed and discarded. Its cells are held per row until
// apply, then only for failed rows, and not at all once the import is closed.
require __DIR__ . '/lib.php';
require __DIR__ . '/people-lib.php';

$user = require_auth($conn);
// Recruitment and the owner: the office roles. Not trainer, although trainers
// see Recruitment: an import changes staff contact details as well as adding
// candidates. Not a worker's or a client's login, whatever their role.
require_role($user, 'super_admin', 'admin', 'manager');
require_staff($user);

$action = $_GET['action'] ?? '';
$scope = service_scope($user);
$uid = (int) $user['id'];

const SI_SERVICES = ['care', 'academy', 'cleaning', 'security'];

// ---- Reads ---------------------------------------------------------------------

if (method() === 'GET' && $action === 'mappings') {
    $res = $conn->query(
        'SELECT m.id, m.name, m.website_form_id, m.purpose, m.default_service, m.columns_json, m.updated_at,
                f.title AS form_title
           FROM sheet_import_mappings m LEFT JOIN website_forms f ON f.id = m.website_form_id
          WHERE m.deleted_at IS NULL ORDER BY m.name'
    );
    $mappings = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['website_form_id'] = $row['website_form_id'] !== null ? (int) $row['website_form_id'] : null;
        $row['columns'] = json_decode((string) $row['columns_json'], true) ?: [];
        unset($row['columns_json']);
        $mappings[] = $row;
    }
    $res = $conn->query('SELECT id, title, service FROM website_forms WHERE deleted_at IS NULL ORDER BY title');
    $forms = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $forms[] = $row;
    }
    $fields = [];
    foreach (RG_IMPORT_FIELDS as $key => $def) $fields[] = ['key' => $key, 'label' => $def['label']];
    json_out(['mappings' => $mappings, 'forms' => $forms, 'fields' => $fields]);
}

if (method() === 'GET' && $action === 'history') {
    // A scoped account sees the imports it ran. Rows from a file can belong to
    // any service, and the history names files and counts.
    $sql = 'SELECT i.id, i.file_name, i.status, i.purpose, i.row_count, i.created_count, i.updated_count,
                   i.skipped_count, i.failed_count, i.created_at, i.applied_at, i.closed_at, u.name AS created_by_name
              FROM sheet_imports i LEFT JOIN users u ON u.id = i.created_by';
    if ($scope !== null) $sql .= ' WHERE i.created_by = ?';
    $sql .= ' ORDER BY i.id DESC LIMIT 100';
    $stmt = $conn->prepare($sql);
    if ($scope !== null) $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        foreach (['id', 'row_count', 'created_count', 'updated_count', 'skipped_count', 'failed_count'] as $k) $row[$k] = (int) $row[$k];
        $items[] = $row;
    }
    json_out(['imports' => $items]);
}

if (method() === 'GET' && $action === 'failures') {
    $import = si_load($conn, $user, (int) ($_GET['id'] ?? 0));
    $headers = json_decode((string) $import['headers_json'], true) ?: [];
    $stmt = $conn->prepare(
        'SELECT row_no, reason, cells_json FROM sheet_import_rows WHERE import_id = ? AND outcome = "failed" ORDER BY row_no'
    );
    $importId = (int) $import['id'];
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = fopen('php://temp', 'w+');
    fputcsv($out, array_merge(['Row', 'Reason'], $headers), ',', '"', '');
    while ($row = $res->fetch_assoc()) {
        $cells = json_decode((string) $row['cells_json'], true) ?: [];
        $line = [(string) $row['row_no'], (string) $row['reason']];
        foreach ($headers as $h) $line[] = si_csv_safe((string) ($cells[$h] ?? ''));
        fputcsv($out, $line, ',', '"', '');
    }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);

    audit($conn, $uid, 'import.failures_download', $import['file_name'], 'sheet_import', $importId);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo((string) $import['file_name'], PATHINFO_FILENAME)) ?: 'import';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-failures.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

// One row again with a person's answer to an uncertain match, so the screen can
// show what choosing that person would do (old and new values) before apply.
if (method() === 'GET' && $action === 'row') {
    $import = si_load($conn, $user, (int) ($_GET['id'] ?? 0));
    if ($import['status'] !== 'open') fail('This import has already been applied.', 409);
    $rowId = (int) ($_GET['row'] ?? 0);
    $importId = (int) $import['id'];
    $stmt = $conn->prepare('SELECT * FROM sheet_import_rows WHERE id = ? AND import_id = ? LIMIT 1');
    $stmt->bind_param('ii', $rowId, $importId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) fail('Row not found.', 404);
    $raw = (string) ($_GET['choice'] ?? '');
    $choice = $raw === 'new' ? 'new' : ((int) $raw > 0 ? (int) $raw : null);
    $plan = si_plan($conn, $user, $import, $row, $choice, []);
    unset($plan['fields'], $plan['person_facts'], $plan['create_new']);
    json_out(['row' => $plan]);
}

if (method() === 'GET' && isset($_GET['id'])) {
    $import = si_load($conn, $user, (int) $_GET['id']);
    json_out(si_view($conn, $user, $import));
}

if (method() === 'GET') fail('Unknown action.', 404);
if (method() !== 'POST') fail('Method not allowed.', 405);

// ---- Upload --------------------------------------------------------------------

if ($action === 'upload') {
    rate_limit($conn, 'sheet_import', 30, 600, (string) $uid);
    $data = body();
    $fileName = trim(basename(str_replace('\\', '/', (string) ($data['file_name'] ?? ''))));
    $csv = (string) ($data['csv'] ?? '');
    if ($fileName === '') fail('Name the file.');
    if (mb_strlen($fileName) > 190) $fileName = mb_substr($fileName, 0, 190);
    if (preg_match('/\.(xlsx|xls|ods|numbers)$/i', $fileName)) {
        fail('Spreadsheet files cannot be read directly. In Google Sheets choose File, Download, Comma-separated values (.csv), then upload that.', 422);
    }

    $parsed = rg_csv_parse($csv);
    if (!$parsed['ok']) fail((string) $parsed['error'], 422);
    $headers = $parsed['headers'];

    // Suggest a mapping: the saved one whose headings this file has most of, as
    // long as it has at least two of them; otherwise a guess per heading.
    $normalised = array_map('rg_import_normalise_header', $headers);
    $best = null;
    $bestScore = 1;
    $res = $conn->query('SELECT id, purpose, default_service, columns_json FROM sheet_import_mappings WHERE deleted_at IS NULL');
    while ($m = $res->fetch_assoc()) {
        $cols = json_decode((string) $m['columns_json'], true) ?: [];
        $score = count(array_intersect(array_keys($cols), $normalised));
        if ($score > $bestScore) { $best = $m; $bestScore = $score; }
    }
    $columns = [];
    $taken = [];
    foreach ($normalised as $h) {
        // The saved mapping for headings it knows, a guess for any it does not
        // (a question added to the form since the mapping was saved).
        $saved = $best !== null ? (json_decode((string) $best['columns_json'], true) ?: []) : [];
        $field = $saved[$h] ?? rg_import_guess_field($h);
        // One column per field: a second column that looks like the same field
        // is left for the person mapping to decide.
        if ($field !== 'ignore' && isset($taken[$field])) $field = 'ignore';
        $taken[$field] = true;
        $columns[$h] = $field;
    }
    $mappingId = $best !== null ? (int) $best['id'] : null;
    $purpose = $best['purpose'] ?? 'applications';
    $defaultService = $best['default_service'] ?? null;

    $sha = hash('sha256', $csv);
    $headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE);
    $columnsJson = json_encode($columns, JSON_UNESCAPED_UNICODE);
    $rowCount = count($parsed['rows']);

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'INSERT INTO sheet_imports (file_name, file_sha256, mapping_id, columns_json, headers_json, purpose, default_service, row_count, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssissssii', $fileName, $sha, $mappingId, $columnsJson, $headersJson, $purpose, $defaultService, $rowCount, $uid);
    $stmt->execute();
    $importId = (int) $conn->insert_id;

    $stmt = $conn->prepare('INSERT INTO sheet_import_rows (import_id, row_no, cells_json, fingerprint) VALUES (?, ?, ?, ?)');
    foreach ($parsed['rows'] as $r) {
        $cellsJson = json_encode($r['cells'], JSON_UNESCAPED_UNICODE);
        $fp = rg_import_fingerprint($r['cells']);
        $rowNo = (int) $r['row'];
        $stmt->bind_param('iiss', $importId, $rowNo, $cellsJson, $fp);
        $stmt->execute();
    }
    audit($conn, $uid, 'import.upload', $fileName, 'sheet_import', $importId, null,
        ['rows' => $rowCount, 'mapping_id' => $mappingId]);
    $conn->commit();

    json_out(['ok' => true, 'id' => $importId, 'rows' => $rowCount, 'mapping_id' => $mappingId], 201);
}

// ---- Everything below works on one import ---------------------------------------------

$import = si_load($conn, $user, (int) ($_GET['id'] ?? 0));
$importId = (int) $import['id'];

if ($action === 'map') {
    if ($import['status'] !== 'open') fail('This import has already been applied.', 409);
    $data = body();
    $headers = json_decode((string) $import['headers_json'], true) ?: [];
    $known = array_flip(array_map('rg_import_normalise_header', $headers));

    // Only headings in this file, only fields on the list. A column mapped to
    // anything else (a pay rate, a status) is stored as ignore: the list in
    // RG_IMPORT_FIELDS is the whole of what an import can write.
    $columns = [];
    $ignored = [];
    foreach ((array) ($data['columns'] ?? []) as $heading => $field) {
        $h = rg_import_normalise_header((string) $heading);
        if (!isset($known[$h])) continue;
        $field = (string) $field;
        if ($field !== 'ignore' && !isset(RG_IMPORT_FIELDS[$field])) { $ignored[] = $field; $field = 'ignore'; }
        $columns[$h] = $field;
    }
    $mappedFields = array_values($columns);
    if (!array_intersect(['name', 'first_name', 'last_name'], $mappedFields)) fail('Map a column to the name.', 422);
    if (!array_intersect(['email', 'phone'], $mappedFields)) fail('Map a column to the email or the phone number, so people can be matched.', 422);
    $dupes = array_diff_assoc(array_filter($mappedFields, static fn($f) => $f !== 'ignore'), array_unique(array_filter($mappedFields, static fn($f) => $f !== 'ignore')));
    if ($dupes) fail('Each field can come from one column only: ' . implode(', ', array_unique(array_map(static fn($f) => RG_IMPORT_FIELDS[$f]['label'], $dupes))) . '.', 422);

    $purpose = ($data['purpose'] ?? 'applications') === 'staff_updates' ? 'staff_updates' : 'applications';
    $defaultService = $data['default_service'] ?? null;
    $defaultService = in_array($defaultService, SI_SERVICES, true) ? $defaultService : null;
    // A scoped account can only default to its own service.
    if ($defaultService !== null && !rg_in_service_scope($scope, $defaultService)) fail('You do not have access to that service.', 403);

    $columnsJson = json_encode($columns, JSON_UNESCAPED_UNICODE);
    $mappingId = $import['mapping_id'] !== null ? (int) $import['mapping_id'] : null;

    $save = $data['save'] ?? null;
    if (is_array($save)) {
        $name = trim((string) ($save['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) fail('Give the mapping a name of up to 160 characters.');
        $formId = isset($save['website_form_id']) && (int) $save['website_form_id'] > 0 ? (int) $save['website_form_id'] : null;
        if ($formId !== null && !row_exists($conn, 'website_forms', $formId)) fail('That form is not in the forms directory.', 422);
        $existingId = isset($save['mapping_id']) && (int) $save['mapping_id'] > 0 ? (int) $save['mapping_id'] : null;

        if ($existingId !== null) {
            if (!row_exists($conn, 'sheet_import_mappings', $existingId)) fail('That saved mapping no longer exists.', 404);
            $stmt = $conn->prepare(
                'UPDATE sheet_import_mappings SET name = ?, website_form_id = ?, purpose = ?, default_service = ?, columns_json = ?, updated_by = ? WHERE id = ?'
            );
            $stmt->bind_param('sisssii', $name, $formId, $purpose, $defaultService, $columnsJson, $uid, $existingId);
            $mappingId = $existingId;
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO sheet_import_mappings (name, website_form_id, purpose, default_service, columns_json, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sisssii', $name, $formId, $purpose, $defaultService, $columnsJson, $uid, $uid);
        }
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) fail('A saved mapping already has that name.', 409);
            throw $e;
        }
        if ($existingId === null) $mappingId = (int) $conn->insert_id;
        audit($conn, $uid, $existingId === null ? 'import.mapping_create' : 'import.mapping_update', $name,
            'sheet_import_mapping', $mappingId, null, ['website_form_id' => $formId, 'purpose' => $purpose, 'columns' => $columns]);
    }

    $stmt = $conn->prepare('UPDATE sheet_imports SET columns_json = ?, purpose = ?, default_service = ?, mapping_id = ? WHERE id = ?');
    $stmt->bind_param('sssii', $columnsJson, $purpose, $defaultService, $mappingId, $importId);
    $stmt->execute();
    audit($conn, $uid, 'import.map', $import['file_name'], 'sheet_import', $importId, null,
        ['purpose' => $purpose, 'default_service' => $defaultService, 'mapping_id' => $mappingId, 'refused_fields' => $ignored]);

    $import = si_load($conn, $user, $importId);
    json_out(si_view($conn, $user, $import));
}

if ($action === 'close') {
    $stmt = $conn->prepare('UPDATE sheet_import_rows SET cells_json = NULL WHERE import_id = ?');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $stmt = $conn->prepare('UPDATE sheet_imports SET status = "closed", closed_at = COALESCE(closed_at, NOW()) WHERE id = ?');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    audit($conn, $uid, 'import.close', $import['file_name'], 'sheet_import', $importId);
    json_out(['ok' => true]);
}

if ($action === 'apply') {
    // Claim the import first, in its own short transaction, so two applies of
    // the same import (a double click, two tabs) cannot both run: the second
    // finds it already applied.
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT status FROM sheet_imports WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $status = $stmt->get_result()->fetch_assoc()['status'] ?? null;
    if ($status !== 'open') {
        $conn->rollback();
        fail('This import has already been applied.', 409);
    }
    $stmt = $conn->prepare('UPDATE sheet_imports SET status = "applied", applied_at = NOW(), applied_by = ? WHERE id = ?');
    $stmt->bind_param('ii', $uid, $importId);
    $stmt->execute();
    $conn->commit();

    $selected = [];
    foreach ((array) (body()['rows'] ?? []) as $r) {
        if (!is_array($r) || (int) ($r['id'] ?? 0) <= 0) continue;
        $choice = $r['choice'] ?? null;
        $selected[(int) $r['id']] = $choice === 'new' ? 'new' : ((int) $choice > 0 ? (int) $choice : null);
    }

    $stmt = $conn->prepare('SELECT id FROM sheet_import_rows WHERE import_id = ? AND outcome = "pending" ORDER BY row_no');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $rowIds = array_map(static fn($r) => (int) $r['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));

    foreach ($rowIds as $rowId) {
        si_apply_row($conn, $user, $import, $rowId, array_key_exists($rowId, $selected), $selected[$rowId] ?? null);
    }

    $counts = si_count($conn, $importId);
    $stmt = $conn->prepare(
        'UPDATE sheet_imports SET created_count = ?, updated_count = ?, skipped_count = ?, failed_count = ? WHERE id = ?'
    );
    $stmt->bind_param('iiiii', $counts['created'], $counts['updated'], $counts['skipped'], $counts['failed'], $importId);
    $stmt->execute();
    // The cells are no longer needed, except a failed row's, which the failures
    // download gives back so the row can be fixed and imported again.
    $stmt = $conn->prepare('UPDATE sheet_import_rows SET cells_json = NULL WHERE import_id = ? AND outcome <> "failed"');
    $stmt->bind_param('i', $importId);
    $stmt->execute();

    audit($conn, $uid, 'import.apply', $import['file_name'], 'sheet_import', $importId, null, $counts);
    json_out(['ok' => true, 'counts' => $counts] + si_view($conn, $user, si_load($conn, $user, $importId)));
}

fail('Unknown action.', 404);

// ---- Helpers -------------------------------------------------------------------

/** Load an import the caller may open. A scoped account may open only its own. */
function si_load(mysqli $conn, array $user, int $id): array
{
    if ($id <= 0) fail('An import is required.');
    $stmt = $conn->prepare('SELECT * FROM sheet_imports WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $import = $stmt->get_result()->fetch_assoc();
    if (!$import) fail('Import not found.', 404);
    if (service_scope($user) !== null && (int) $import['created_by'] !== (int) $user['id']) fail('You do not have access to this.', 403);
    return $import;
}

/** A cell that a spreadsheet would read as a formula, made inert. */
function si_csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** @return array{created:int,updated:int,skipped:int,failed:int,pending:int} */
function si_count(mysqli $conn, int $importId): array
{
    $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0];
    $stmt = $conn->prepare('SELECT outcome, COUNT(*) AS n FROM sheet_import_rows WHERE import_id = ? GROUP BY outcome');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $counts[$r['outcome']] = (int) $r['n'];
    return $counts;
}

/**
 * The import as the screen needs it: the mapping, a few sample values per
 * column, and either the preview (open) or what happened (applied or closed).
 */
function si_view(mysqli $conn, array $user, array $import): array
{
    $importId = (int) $import['id'];
    $headers = json_decode((string) $import['headers_json'], true) ?: [];
    $columns = json_decode((string) $import['columns_json'], true) ?: [];

    $stmt = $conn->prepare(
        'SELECT id, row_no, cells_json, fingerprint, outcome, reason, person_id, applicant_id, changed_fields
           FROM sheet_import_rows WHERE import_id = ? ORDER BY row_no'
    );
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $samples = [];
    foreach ($headers as $h) $samples[$h] = [];
    foreach (array_slice($rows, 0, 3) as $r) {
        $cells = json_decode((string) $r['cells_json'], true) ?: [];
        foreach ($headers as $h) if (($cells[$h] ?? '') !== '') $samples[$h][] = mb_substr((string) $cells[$h], 0, 60);
    }

    $out = [];
    $tally = [];
    $seen = [];
    foreach ($rows as $r) {
        if ($import['status'] === 'open' && $r['outcome'] === 'pending') {
            $plan = si_plan($conn, $user, $import, $r, null, $seen);
            $seen[$r['fingerprint']] ??= (int) $r['row_no'];
            unset($plan['fields'], $plan['person_facts'], $plan['create_new']);
        } else {
            $plan = [
                'id' => (int) $r['id'], 'row' => (int) $r['row_no'], 'outcome' => $r['outcome'], 'reason' => $r['reason'],
                'person_id' => $r['person_id'] !== null ? (int) $r['person_id'] : null,
                'applicant_id' => $r['applicant_id'] !== null ? (int) $r['applicant_id'] : null,
                'changed_fields' => $r['changed_fields'] !== null ? explode(',', (string) $r['changed_fields']) : [],
            ];
        }
        $tally[$plan['outcome']] = ($tally[$plan['outcome']] ?? 0) + 1;
        $out[] = $plan;
    }

    return [
        'import' => [
            'id' => $importId,
            'file_name' => $import['file_name'],
            'status' => $import['status'],
            'purpose' => $import['purpose'],
            'default_service' => $import['default_service'],
            'mapping_id' => $import['mapping_id'] !== null ? (int) $import['mapping_id'] : null,
            'row_count' => (int) $import['row_count'],
            'created_at' => $import['created_at'],
            'applied_at' => $import['applied_at'],
            'closed_at' => $import['closed_at'],
            'counts' => si_count($conn, $importId),
        ],
        'headers' => $headers,
        'columns' => (object) $columns,
        'samples' => (object) $samples,
        'tally' => (object) $tally,
        'rows' => $out,
    ];
}

/**
 * Work out what one row would do now. Reads only.
 *
 * $choice is a person's answer to an uncertain match: a person id from the
 * candidates offered, or 'new'. Anything else is ignored, so an id the preview
 * never offered cannot attach a row to an arbitrary person.
 *
 * @param array<string,int> $seen fingerprint => first row number, for repeats within the file
 */
function si_plan(mysqli $conn, array $user, array $import, array $row, int|string|null $choice, array $seen): array
{
    $scope = service_scope($user);
    $cells = json_decode((string) $row['cells_json'], true) ?: [];
    $columns = json_decode((string) $import['columns_json'], true) ?: [];
    $mapped = rg_import_map_row($cells, $columns, $import['default_service']);
    $f = $mapped['fields'];

    $plan = [
        'id' => (int) $row['id'],
        'row' => (int) $row['row_no'],
        'name' => $f['name'],
        'email' => $f['email'],
        'phone' => $f['phone'],
        'role' => $f['role'],
        'service' => $f['service'],
        'response_at' => $f['response_timestamp'],
        'match' => null,
        'person' => null,
        'candidates' => [],
        'changes' => [],
        'fields' => $f,
        'person_facts' => null,
        'create_new' => false,
    ];

    // ---- Duplicates that need no person: the same row again.
    $duplicate = null;
    if (isset($seen[$row['fingerprint']])) {
        $duplicate = 'The same as row ' . $seen[$row['fingerprint']] . ' in this file.';
    }
    if ($duplicate === null) {
        $stmt = $conn->prepare(
            'SELECT r.row_no, i.file_name FROM sheet_import_rows r JOIN sheet_imports i ON i.id = r.import_id
              WHERE r.applied_fingerprint = ? LIMIT 1'
        );
        $stmt->bind_param('s', $row['fingerprint']);
        $stmt->execute();
        if ($hit = $stmt->get_result()->fetch_assoc()) {
            $duplicate = 'Already imported from ' . $hit['file_name'] . ', row ' . $hit['row_no'] . '.';
        }
    }
    if ($duplicate === null && $f['response_id'] !== null) {
        // The form feed records Google's response id; a Sheet does not show it,
        // but a sheet with a response id column (or one exported by a script)
        // is recognised here.
        $integrationId = si_form_integration($conn, $import);
        $sql = 'SELECT id FROM integration_submissions WHERE external_id = ?' . ($integrationId !== null ? ' AND integration_id = ?' : '') . ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if ($integrationId !== null) $stmt->bind_param('si', $f['response_id'], $integrationId);
        else $stmt->bind_param('s', $f['response_id']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $duplicate = 'Already received through the form feed.';
        if ($duplicate === null) {
            $stmt = $conn->prepare(
                'SELECT r.row_no, i.file_name FROM sheet_import_rows r JOIN sheet_imports i ON i.id = r.import_id
                  WHERE r.response_id = ? AND r.outcome IN ("created","updated") LIMIT 1'
            );
            $stmt->bind_param('s', $f['response_id']);
            $stmt->execute();
            if ($hit = $stmt->get_result()->fetch_assoc()) {
                $duplicate = 'Already imported from ' . $hit['file_name'] . ', row ' . $hit['row_no'] . '.';
            }
        }
    }

    // ---- Who is this?
    $people = si_candidates($conn, $f);
    $match = $mapped['problems'] ? ['decision' => 'none', 'person_id' => null, 'basis' => null, 'reason' => null, 'candidates' => []]
        : rg_import_match_decision($f, $people);

    // Candidates outside the account's service are never shown. When nobody in
    // scope is left to choose from, the row cannot be decided by this account.
    $byId = [];
    foreach ($people as $p) $byId[(int) $p['id']] = $p;
    $services = rg_people_services($conn, array_keys($byId));
    $inScope = static fn(int $id): bool => rg_person_in_scope($scope, $services[$id] ?? []);

    if ($match['decision'] === 'uncertain') {
        $offered = array_values(array_filter($match['candidates'], $inScope));
        if ($choice === 'new') {
            $match = ['decision' => 'none', 'person_id' => null, 'basis' => 'chosen_new', 'reason' => null, 'candidates' => $match['candidates']];
            $plan['create_new'] = true;
        } elseif (is_int($choice) && in_array($choice, $offered, true)) {
            $match = ['decision' => 'match', 'person_id' => $choice, 'basis' => 'chosen', 'reason' => null, 'candidates' => $match['candidates']];
        } elseif ($offered === []) {
            $match['decision'] = 'match';
            $match['person_id'] = (int) $match['candidates'][0];
        }
        foreach ($offered as $cid) {
            $p = $byId[$cid];
            $plan['candidates'][] = [
                'id' => $cid, 'name' => $p['full_name'], 'email' => $p['email'], 'phone' => $p['phone'],
                'services' => $services[$cid] ?? [], 'staff' => si_workers($conn, $cid) !== [],
            ];
        }
    }
    $plan['match'] = ['decision' => $match['decision'], 'basis' => $match['basis'], 'reason' => $match['reason']];

    $personFacts = null;
    $changes = [];
    if ($match['decision'] === 'match' && $match['person_id'] !== null) {
        $pid = (int) $match['person_id'];
        $p = $byId[$pid] ?? si_person($conn, $pid);
        $workers = si_workers($conn, $pid);
        $scopedWorkers = array_values(array_filter($workers, static fn($w) => rg_in_service_scope($scope, $w['division'])));
        $personServices = $services[$pid] ?? (rg_people_services($conn, [$pid])[$pid] ?? []);
        $personFacts = [
            'id' => $pid,
            'has_worker' => $workers !== [],
            'in_scope' => rg_person_in_scope($scope, $personServices),
            'worker_ids' => array_map(static fn($w) => (int) $w['id'], $scopedWorkers),
        ];
        if ($personFacts['in_scope']) {
            $plan['person'] = ['id' => $pid, 'name' => $p['full_name'] ?? null, 'staff' => $workers !== []];
            if ($workers !== []) {
                $current = $p;
                if ($scopedWorkers) {
                    $current += ['avail_days' => $scopedWorkers[0]['avail_days'], 'avail_nights' => $scopedWorkers[0]['avail_nights'],
                                 'avail_weekends' => $scopedWorkers[0]['avail_weekends']];
                }
                $changes = rg_import_contact_changes($current, $f);
            } elseif ($duplicate === null && $import['purpose'] === 'applications') {
                $duplicate = rg_import_same_application($f['role'], $f['response_timestamp'], si_applications($conn, $pid));
            }
        }
    }
    $plan['changes'] = $changes;
    $plan['person_facts'] = $personFacts;

    $decision = rg_import_plan_row([
        'problems' => $mapped['problems'],
        'purpose' => $import['purpose'],
        'duplicate' => $duplicate,
        'match' => $match,
        'person' => $personFacts,
        'service' => $f['service'],
        'scope' => $scope,
        'changes' => $changes,
        'has_email' => $f['email'] !== null,
    ]);
    $plan['outcome'] = $decision['outcome'];
    $plan['reason'] = $decision['reason'];
    return $plan;
}

/** The integration behind the form the import's mapping belongs to, if any. */
function si_form_integration(mysqli $conn, array $import): ?int
{
    if ($import['mapping_id'] === null) return null;
    $stmt = $conn->prepare(
        'SELECT f.integration_id FROM sheet_import_mappings m JOIN website_forms f ON f.id = m.website_form_id WHERE m.id = ? LIMIT 1'
    );
    $mid = (int) $import['mapping_id'];
    $stmt->bind_param('i', $mid);
    $stmt->execute();
    $id = $stmt->get_result()->fetch_assoc()['integration_id'] ?? null;
    return $id !== null ? (int) $id : null;
}

/** Everybody who might be this row, by email, phone, or name. */
function si_candidates(mysqli $conn, array $f): array
{
    $email = rg_normalise_email($f['email']) ?? '';
    $emailKey = $email !== '' ? 'e:' . $email : '';
    $phoneKey = rg_phone_key($f['phone']) ?? '';
    $phoneTail = $phoneKey !== '' ? substr($phoneKey, -9) : '';
    $phoneMatch = $phoneKey !== '' ? 'p:' . $phoneKey : '';
    $name = trim((string) $f['name']);
    $stmt = $conn->prepare(
        'SELECT id, full_name, email, phone, date_of_birth, address_line1, postcode, ' . implode(', ', RG_EMERGENCY_FIELDS) . '
           FROM people
          WHERE deleted_at IS NULL AND (
                (? <> "" AND (match_key = ? OR email = ?))
             OR (? <> "" AND (match_key = ? OR RIGHT(REGEXP_REPLACE(COALESCE(phone, ""), "[^0-9]", ""), 9) = ?))
             OR (? <> "" AND full_name = ?))
          ORDER BY id LIMIT 25'
    );
    $stmt->bind_param('ssssssss', $email, $emailKey, $email, $phoneKey, $phoneMatch, $phoneTail, $name, $name);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function si_person(mysqli $conn, int $id): array
{
    $stmt = $conn->prepare(
        'SELECT id, full_name, email, phone, date_of_birth, address_line1, postcode, ' . implode(', ', RG_EMERGENCY_FIELDS) . '
           FROM people WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: [];
}

/** @return list<array{id:int,division:string,avail_days:int,avail_nights:int,avail_weekends:int}> */
function si_workers(mysqli $conn, int $personId): array
{
    $stmt = $conn->prepare(
        'SELECT id, division, avail_days, avail_nights, avail_weekends FROM workers WHERE person_id = ? AND deleted_at IS NULL ORDER BY id'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * A person's applications, each with every date it is known to have arrived on:
 * when the row was created, when the form feed received it, and the response
 * timestamp of any import row that created it.
 */
function si_applications(mysqli $conn, int $personId): array
{
    $stmt = $conn->prepare(
        'SELECT a.id, a.role_applied, a.stage, a.source, DATE(a.created_at) AS created_on,
                (SELECT GROUP_CONCAT(DATE(s.received_at)) FROM integration_submissions s WHERE s.applicant_id = a.id) AS fed_on,
                (SELECT GROUP_CONCAT(DATE(r.response_at)) FROM sheet_import_rows r WHERE r.applicant_id = a.id AND r.response_at IS NOT NULL) AS imported_on
           FROM applicants a WHERE a.person_id = ? AND a.deleted_at IS NULL'
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
        $dates = array_filter(array_merge([$a['created_on']], explode(',', (string) $a['fed_on']), explode(',', (string) $a['imported_on'])));
        $out[] = ['role' => $a['role_applied'], 'dates' => array_values(array_unique($dates)), 'stage' => $a['stage'], 'source' => $a['source']];
    }
    return $out;
}

/**
 * Apply one row, in its own transaction, with the row locked.
 *
 * The outcome is worked out again here, inside the transaction, from the row's
 * cells and the database as it is now. Rows not ticked are recorded as skipped
 * (or failed, with the reason, when they could never have been applied), so the
 * report accounts for every row in the file.
 */
function si_apply_row(mysqli $conn, array $user, array $import, int $rowId, bool $ticked, int|string|null $choice): void
{
    $uid = (int) $user['id'];
    $file = (string) $import['file_name'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM sheet_import_rows WHERE id = ? AND import_id = ? FOR UPDATE');
        $importId = (int) $import['id'];
        $stmt->bind_param('ii', $rowId, $importId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || $row['outcome'] !== 'pending') { $conn->rollback(); return; }

        // Repeats within this file: an earlier row with the same fingerprint that
        // has already been applied is caught by applied_fingerprint below.
        $plan = si_plan($conn, $user, $import, $row, $choice, []);
        $f = $plan['fields'];
        $where = "$file, row {$row['row_no']}";

        $outcome = 'skipped';
        $reason = $plan['reason'];
        $personId = null;
        $applicantId = null;
        $changed = null;

        if (!$ticked) {
            if ($plan['outcome'] === 'problem') $outcome = 'failed';
            else $reason = 'Not selected.';
        } elseif ($plan['outcome'] === 'problem') {
            $outcome = 'failed';
        } elseif ($plan['outcome'] === 'uncertain') {
            $reason = 'Needs a decision about who this is.';
        } elseif ($plan['outcome'] === 'new') {
            $facts = $plan['person_facts'];
            if ($facts !== null) {
                $personId = (int) $facts['id'];
                // Fill in what the record lacks; never overwrite what it holds.
                $stmt = $conn->prepare('UPDATE people SET email = COALESCE(email, ?), phone = COALESCE(phone, ?) WHERE id = ?');
                $stmt->bind_param('ssi', $f['email'], $f['phone'], $personId);
                $stmt->execute();
            } else {
                $key = rg_person_match_key(['email' => $f['email'], 'phone' => $f['phone']])['key'];
                $taken = false;
                if ($plan['create_new'] && $key !== null) {
                    $stmt = $conn->prepare('SELECT id FROM people WHERE match_key = ? LIMIT 1');
                    $stmt->bind_param('s', $key);
                    $stmt->execute();
                    $taken = (bool) $stmt->get_result()->fetch_assoc();
                }
                if ($taken) {
                    // A person decided this is somebody else who shares an email or
                    // phone with a person on record. The match key is UNIQUE, so the
                    // new person is created without one and is reconciled by hand,
                    // like every other unkeyed person (migration 001).
                    $stmt = $conn->prepare('INSERT INTO people (full_name, email, phone, match_key) VALUES (?, ?, ?, NULL)');
                    $stmt->bind_param('sss', $f['name'], $f['email'], $f['phone']);
                    $stmt->execute();
                    $personId = (int) $conn->insert_id;
                } else {
                    $personId = find_or_create_person($conn, (string) $f['name'], $f['email'], $f['phone']);
                }
            }
            $stmt = $conn->prepare(
                'UPDATE people SET address_line1 = COALESCE(address_line1, ?), postcode = COALESCE(postcode, ?),
                        emergency_contact_name = COALESCE(emergency_contact_name, ?),
                        emergency_contact_phone = COALESCE(emergency_contact_phone, ?),
                        emergency_contact_relationship = COALESCE(emergency_contact_relationship, ?)
                  WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $f['address'], $f['postcode'], $f['emergency_name'], $f['emergency_phone'], $f['emergency_relationship'], $personId);
            $stmt->execute();

            $notes = ["Imported from spreadsheet \"$file\", row {$row['row_no']}."];
            $labels = ['response_timestamp' => 'Response received', 'postcode' => 'Postcode', 'address' => 'Address',
                'right_to_work' => 'Right to work (self-declared)', 'experience' => 'Experience', 'availability' => 'Availability',
                'how_heard' => 'How they heard about us'];
            foreach ($labels as $k => $label) if (!empty($f[$k])) $notes[] = "$label: {$f[$k]}";
            $applicantId = rg_create_applicant($conn, $personId, [
                'name' => (string) $f['name'], 'email' => (string) $f['email'], 'phone' => $f['phone'], 'role' => $f['role'],
                'service' => (string) $f['service'], 'source' => 'spreadsheet_import', 'notes' => implode("\n", $notes),
            ], "Imported from a spreadsheet ($where)", $uid);
            $outcome = 'created';
            $reason = null;
        } elseif ($plan['outcome'] === 'update') {
            $facts = $plan['person_facts'];
            $personId = (int) $facts['id'];
            $error = rg_save_contact_details($conn, $personId, $plan['changes'], $facts['worker_ids'], $uid, "spreadsheet import: $where");
            if ($error !== null) {
                $conn->rollback();
                $conn->begin_transaction();
                $outcome = 'failed';
                $reason = $error;
                $personId = null;
            } else {
                $outcome = 'updated';
                $reason = null;
                $changed = implode(',', array_column($plan['changes'], 'field'));
            }
        }

        $applied = in_array($outcome, ['created', 'updated'], true) ? $row['fingerprint'] : null;
        $keepCells = $outcome === 'failed' ? $row['cells_json'] : null;
        $responseId = $f['response_id'];
        $responseAt = $f['response_timestamp'];
        $reasonVal = $reason !== null ? mb_substr($reason, 0, 500) : null;
        $stmt = $conn->prepare(
            'UPDATE sheet_import_rows SET outcome = ?, reason = ?, person_id = ?, applicant_id = ?, changed_fields = ?,
                    applied_fingerprint = ?, response_id = ?, response_at = ?, cells_json = ?, applied_at = NOW()
              WHERE id = ?'
        );
        $stmt->bind_param('ssiisssssi', $outcome, $reasonVal, $personId, $applicantId, $changed, $applied, $responseId, $responseAt, $keepCells, $rowId);
        $stmt->execute();

        if ($outcome === 'created') {
            audit($conn, $uid, 'import.row_created', $where, 'applicant', $applicantId, null,
                ['import_id' => $importId, 'file' => $file, 'row' => (int) $row['row_no'], 'person_id' => $personId, 'service' => $f['service']]);
        } elseif ($outcome === 'updated') {
            audit($conn, $uid, 'import.row_updated', $where, 'person', $personId, null,
                ['import_id' => $importId, 'file' => $file, 'row' => (int) $row['row_no'], 'changed' => explode(',', (string) $changed)]);
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // 1062 on uniq_sir_applied: the same row was applied by another import,
        // or by an earlier row of this one, after this preview was worked out.
        $dup = $e->getCode() === 1062;
        $outcome = $dup ? 'skipped' : 'failed';
        $reason = $dup ? 'Already imported.' : 'Could not be applied. Nothing was changed for this row.';
        $stmt = $conn->prepare('UPDATE sheet_import_rows SET outcome = ?, reason = ?, applied_at = NOW() WHERE id = ? AND outcome = "pending"');
        $stmt->bind_param('ssi', $outcome, $reason, $rowId);
        $stmt->execute();
        if (!$dup) error_log('sheet-import row ' . $rowId . ': ' . $e->getMessage());
    }
}
