<?php
// The forms directory: the Google Forms the website may embed or link to
// (2026 plan, file 06, section S7).
//
// Website area only (super_admin | admin | manager | editor, internal staff;
// a service-scoped account sees and changes only its own service's forms).
// There is no public read here: the website reaches a form only through the
// vacancy it is linked to (vacancies.php?slug=...), and only by its id.
//
//   GET  /website-forms.php                         -> { forms, integrations }
//   GET  /website-forms.php?action=history&id=N     -> { history }  previous values
//   POST /website-forms.php?action=create           body {...} -> { ok, id }
//   POST /website-forms.php?action=update&id=N      body {...} -> { ok }
//   POST /website-forms.php?action=reviewed&id=N    mark reviewed today
//
// The address is validated by rg_parse_google_form() and only the id is kept.
// Anything else is refused with 422: another host, a redirector, javascript:,
// pasted HTML. Changing the address keeps the row, so every role page that links
// to it keeps working; the previous id is in the audit log.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager', 'editor');
require_staff($user);
$userId = (int) $user['id'];

$action = $_GET['action'] ?? '';
$m = method();

const WEBSITE_FORM_FIELDS = ['title', 'purpose', 'service', 'google_form_id', 'display', 'active', 'owner_id', 'integration_id'];

function website_form_load(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, title, purpose, service, google_form_id, display, active, owner_id, integration_id, last_reviewed_at
         FROM website_forms WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    foreach (['id', 'owner_id', 'integration_id'] as $k) if ($row[$k] !== null) $row[$k] = (int) $row[$k];
    $row['active'] = (bool) $row['active'];
    return $row;
}

/** The editable fields from a request body, validated. Refuses with 422. */
function website_form_read_input(mysqli $conn, array $user, array $d, ?array $existing): array
{
    $title = trim((string) ($d['title'] ?? ''));
    if ($title === '') fail('A title is required.', 422);
    if (mb_strlen($title) > 160) fail('The title is too long (at most 160 characters).', 422);

    $purpose = (string) ($d['purpose'] ?? 'recruitment');
    if (!in_array($purpose, ['recruitment', 'training', 'other'], true)) fail('Choose what the form is for.', 422);

    $service = $d['service'] ?? null;
    $service = ($service === '' || $service === null) ? null : (string) $service;
    if ($service !== null && !in_array($service, ['care', 'academy', 'cleaning', 'security'], true)) {
        fail('Unknown service.', 422);
    }
    require_service_scope($user, $service);

    // A new address is required on create; on update it is optional, and the
    // stored id stands when none is given.
    $url = trim((string) ($d['url'] ?? ''));
    if ($url === '' && $existing !== null) {
        $formId = $existing['google_form_id'];
    } else {
        $parsed = rg_parse_google_form($url);
        if ($parsed['id'] === null) fail((string) $parsed['error'], 422);
        $formId = $parsed['id'];
    }

    $display = (string) ($d['display'] ?? 'embedded');
    if (!in_array($display, ['embedded', 'link'], true)) fail('Choose embedded or link only.', 422);

    $active = array_key_exists('active', $d) ? (bool) $d['active'] : ($existing['active'] ?? true);

    $ownerId = isset($d['owner_id']) && $d['owner_id'] !== '' && $d['owner_id'] !== null ? (int) $d['owner_id'] : null;
    if ($ownerId !== null && !is_assignable_staff($conn, $ownerId)) fail('The owner must be a member of staff.', 422);

    $integrationId = isset($d['integration_id']) && $d['integration_id'] !== '' && $d['integration_id'] !== null
        ? (int) $d['integration_id'] : null;
    if ($integrationId !== null) {
        $stmt = $conn->prepare('SELECT id FROM integrations WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $integrationId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) fail('Unknown intake integration.', 422);
    }

    return [
        'title' => $title, 'purpose' => $purpose, 'service' => $service, 'google_form_id' => $formId,
        'display' => $display, 'active' => $active, 'owner_id' => $ownerId, 'integration_id' => $integrationId,
    ];
}

if ($m === 'GET' && $action === '') {
    $scope = service_scope($user);
    $sql = 'SELECT f.id, f.title, f.purpose, f.service, f.google_form_id, f.display, f.active, f.owner_id,
                   f.integration_id, f.last_reviewed_at, f.updated_at, u.name AS owner_name,
                   i.name AS integration_name, i.integration_key, i.active AS integration_active,
                   i.secret_hash IS NOT NULL AS integration_configured, i.last_success_at, i.last_failure_at,
                   i.last_failure_reason, i.expect_within_hours,
                   TIMESTAMPDIFF(HOUR, i.last_success_at, NOW()) AS hours_since_success,
                   (SELECT GROUP_CONCAT(v.title ORDER BY v.title SEPARATOR "\n") FROM vacancies v
                     WHERE v.form_id = f.id AND v.deleted_at IS NULL) AS vacancy_titles
            FROM website_forms f
            LEFT JOIN users u ON u.id = f.owner_id
            LEFT JOIN integrations i ON i.id = f.integration_id
            WHERE f.deleted_at IS NULL';
    if ($scope !== null) $sql .= ' AND f.service = ?';
    $sql .= ' ORDER BY f.active DESC, f.title';
    $stmt = $conn->prepare($sql);
    if ($scope !== null) $stmt->bind_param('s', $scope);
    $stmt->execute();
    $res = $stmt->get_result();
    $today = gmdate('Y-m-d');
    $forms = [];
    while ($row = $res->fetch_assoc()) {
        foreach (['id', 'owner_id', 'integration_id'] as $k) if ($row[$k] !== null) $row[$k] = (int) $row[$k];
        $row['active'] = (bool) $row['active'];
        $row['url'] = 'https://docs.google.com/forms/d/e/' . $row['google_form_id'] . '/viewform';
        $row['review_due'] = rg_form_review_due($row['last_reviewed_at'], $today);
        $row['vacancies'] = $row['vacancy_titles'] !== null ? explode("\n", $row['vacancy_titles']) : [];
        unset($row['vacancy_titles']);
        // The same reading of intake health as forms-intake.php?action=health.
        if ($row['integration_id'] === null) {
            $row['intake_status'] = 'not_linked';
        } else {
            $hours = $row['hours_since_success'];
            $silent = $row['integration_active'] && $row['expect_within_hours'] !== null
                && ($hours === null || (int) $hours > (int) $row['expect_within_hours']);
            $row['intake_status'] = !$row['integration_configured'] ? 'not_configured'
                : (!$row['integration_active'] ? 'paused' : ($silent ? 'silent' : 'ok'));
        }
        unset($row['integration_active'], $row['integration_configured'], $row['hours_since_success'], $row['expect_within_hours']);
        $forms[] = $row;
    }

    $integrations = [];
    $res = $conn->query('SELECT id, name, integration_key FROM integrations ORDER BY name');
    while ($row = $res->fetch_assoc()) { $row['id'] = (int) $row['id']; $integrations[] = $row; }

    json_out(['forms' => $forms, 'integrations' => $integrations]);
}

if ($m === 'GET' && $action === 'history') {
    $id = (int) ($_GET['id'] ?? 0);
    $form = website_form_load($conn, $id);
    if (!$form) fail('Form not found.', 404);
    require_service_scope($user, $form['service']);
    $stmt = $conn->prepare(
        'SELECT a.action, a.before_json, a.after_json, a.created_at, u.name AS user_name
         FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.entity_type = "website_form" AND a.entity_id = ?
         ORDER BY a.id DESC LIMIT 50'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $history = [];
    while ($row = $res->fetch_assoc()) {
        $row['before'] = $row['before_json'] !== null ? json_decode($row['before_json'], true) : null;
        $row['after'] = $row['after_json'] !== null ? json_decode($row['after_json'], true) : null;
        unset($row['before_json'], $row['after_json']);
        $history[] = $row;
    }
    json_out(['history' => $history]);
}

if ($m === 'POST' && $action === 'create') {
    $in = website_form_read_input($conn, $user, body(), null);
    $active = (int) $in['active'];
    $stmt = $conn->prepare(
        'INSERT INTO website_forms (title, purpose, service, google_form_id, display, active, owner_id, integration_id,
                                    last_reviewed_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_DATE(), ?)'
    );
    $stmt->bind_param('sssssiiii', $in['title'], $in['purpose'], $in['service'], $in['google_form_id'], $in['display'],
        $active, $in['owner_id'], $in['integration_id'], $userId);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    audit($conn, $userId, 'website_form.create', "#$id {$in['title']}", 'website_form', $id, null, $in);
    json_out(['ok' => true, 'id' => $id], 201);
}

if ($m === 'POST' && ($action === 'update' || $action === 'reviewed')) {
    $id = (int) ($_GET['id'] ?? 0);
    $before = $id > 0 ? website_form_load($conn, $id) : null;
    if (!$before) fail('Form not found.', 404);
    require_service_scope($user, $before['service']);

    if ($action === 'reviewed') {
        $stmt = $conn->prepare('UPDATE website_forms SET last_reviewed_at = UTC_DATE() WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        audit($conn, $userId, 'website_form.reviewed', "#$id", 'website_form', $id,
            ['last_reviewed_at' => $before['last_reviewed_at']], ['last_reviewed_at' => gmdate('Y-m-d')]);
        json_out(['ok' => true]);
    }

    $in = website_form_read_input($conn, $user, body(), $before);
    $active = (int) $in['active'];
    $stmt = $conn->prepare(
        'UPDATE website_forms SET title = ?, purpose = ?, service = ?, google_form_id = ?, display = ?, active = ?,
                owner_id = ?, integration_id = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('sssssiiii', $in['title'], $in['purpose'], $in['service'], $in['google_form_id'], $in['display'],
        $active, $in['owner_id'], $in['integration_id'], $id);
    $stmt->execute();
    // The previous values, field by field: this is the form's history.
    [$b, $a] = audit_diff(
        array_merge($before, ['active' => (int) $before['active']]),
        array_merge($in, ['active' => $active]),
        WEBSITE_FORM_FIELDS
    );
    if ($a) audit($conn, $userId, 'website_form.update', "#$id {$in['title']}", 'website_form', $id, $b, $a);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
