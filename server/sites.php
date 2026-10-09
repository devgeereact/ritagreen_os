<?php
// Sites: the places a client's work happens.
//
// GET  /sites.php                        -> { sites }  every site, for the rota's filter and pickers
// GET  /sites.php?client_id=N            -> { sites, requirements }  one client's sites, with what each requires
// POST /sites.php {client_id, name, address?, postcode?, access_notes?, contact_name?, contact_phone?,
//                  requirement_ids?: [], active?}                    -> create
// POST /sites.php?action=update&id=N {...the same, any subset}       -> edit
//
// requirement_ids name compliance requirements (the same catalogue the services'
// rules use). A shift at the site is assessed against the service's rules PLUS
// these, as mandatory, by rg_load_rules() -- one evaluator, not a second one.
//
// A site is closed (active = 0) rather than deleted: shifts point at it, and
// where somebody worked is history.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Site contacts are named people with phone numbers, and the access notes can
// be door codes. Office roles only, for reads as well as writes.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';
$uid = (int) $user['id'];

/** The site's requirements, as {id, req_key, label}. */
function site_requirements(mysqli $conn, array $siteIds): array
{
    $ids = array_values(array_filter(array_map('intval', $siteIds)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT sr.site_id, r.id, r.req_key, r.label
           FROM site_requirements sr JOIN compliance_requirements r ON r.id = sr.requirement_id
          WHERE sr.site_id IN ($marks) ORDER BY r.label"
    );
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $out = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(int) $row['site_id']][] = ['id' => (int) $row['id'], 'req_key' => $row['req_key'], 'label' => $row['label']];
    }
    return $out;
}

/**
 * The editable fields from a request body, validated. Only the keys present are
 * returned, so an edit can send one field.
 */
function site_fields(array $data): array
{
    $limits = ['name' => 190, 'address' => 255, 'postcode' => 12, 'access_notes' => 1000, 'contact_name' => 160, 'contact_phone' => 40];
    $labels = ['name' => 'The site name', 'address' => 'The address', 'postcode' => 'The postcode',
        'access_notes' => 'The access notes', 'contact_name' => 'The contact name', 'contact_phone' => 'The contact phone'];
    $out = [];
    foreach ($limits as $field => $max) {
        if (!array_key_exists($field, $data)) continue;
        $value = trim((string) ($data[$field] ?? ''));
        if (mb_strlen($value) > $max) fail("{$labels[$field]} is too long.");
        if ($field === 'postcode') $value = strtoupper(preg_replace('/\s+/', ' ', $value) ?? '');
        $out[$field] = $value !== '' ? $value : null;
    }
    if (array_key_exists('name', $out) && $out['name'] === null) fail('Give the site a name.');
    if (($out['contact_phone'] ?? null) !== null && !rg_valid_phone($out['contact_phone'])) {
        fail('The contact phone is not a number that can be rung.');
    }
    if (array_key_exists('active', $data)) $out['active'] = !empty($data['active']) ? 1 : 0;
    return $out;
}

/** Validate requirement ids against the active catalogue. */
function site_requirement_ids(mysqli $conn, $raw): array
{
    if (!is_array($raw)) fail('requirement_ids must be a list.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn(int $i): bool => $i > 0)));
    if (!$ids) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT id FROM compliance_requirements WHERE active = 1 AND id IN ($marks)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $found = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    if (count($found) !== count($ids)) fail('One of those requirements does not exist.', 404);
    sort($found);
    return $found;
}

/** Replace a site's requirement set; returns [before, after] as id lists. */
function site_set_requirements(mysqli $conn, int $siteId, array $ids, int $uid): array
{
    $stmt = $conn->prepare('SELECT requirement_id FROM site_requirements WHERE site_id = ? ORDER BY requirement_id');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $before = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'requirement_id'));
    $stmt = $conn->prepare('DELETE FROM site_requirements WHERE site_id = ?');
    $stmt->bind_param('i', $siteId);
    $stmt->execute();
    $ins = $conn->prepare('INSERT INTO site_requirements (site_id, requirement_id, created_by) VALUES (?, ?, ?)');
    foreach ($ids as $rid) {
        $ins->bind_param('iii', $siteId, $rid, $uid);
        $ins->execute();
    }
    return [$before, $ids];
}

/** Refuse a second live site with the same name under one client. */
function site_name_free(mysqli $conn, int $clientId, string $name, int $exceptId = 0): void
{
    $stmt = $conn->prepare(
        'SELECT id FROM sites WHERE client_id = ? AND name = ? AND id <> ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('isi', $clientId, $name, $exceptId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row()) fail('This client already has a site with that name.', 409);
}

// ---------------------------------------------------------------- reads

if (method() === 'GET') {
    $clientId = (int) ($_GET['client_id'] ?? 0);
    if ($clientId > 0) {
        if (!row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);
        $stmt = $conn->prepare(
            'SELECT id, client_id, name, address, postcode, access_notes, contact_name, contact_phone, active
               FROM sites WHERE client_id = ? AND deleted_at IS NULL ORDER BY active DESC, name'
        );
        $stmt->bind_param('i', $clientId);
    } else {
        $stmt = $conn->prepare(
            'SELECT s.id, s.client_id, c.name AS client, s.name, s.address, s.postcode, s.access_notes,
                    s.contact_name, s.contact_phone, s.active
               FROM sites s JOIN clients c ON c.id = s.client_id
              WHERE s.deleted_at IS NULL AND c.deleted_at IS NULL
              ORDER BY c.name, s.name'
        );
    }
    $stmt->execute();
    $sites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $reqs = site_requirements($conn, array_column($sites, 'id'));
    foreach ($sites as &$s) {
        $s['id'] = (int) $s['id'];
        $s['client_id'] = (int) $s['client_id'];
        $s['active'] = (bool) $s['active'];
        $s['requirements'] = $reqs[$s['id']] ?? [];
    }
    unset($s);

    $catalogue = $conn->query(
        'SELECT id, req_key, label FROM compliance_requirements WHERE active = 1 ORDER BY label'
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($catalogue as &$r) $r['id'] = (int) $r['id'];
    unset($r);
    json_out(['sites' => $sites, 'requirements' => $catalogue]);
}

// ---------------------------------------------------------------- create

if ($action === '' && method() === 'POST') {
    $data = body();
    $clientId = (int) ($data['client_id'] ?? 0);
    if ($clientId <= 0) fail('A client is required.');
    if (!row_exists($conn, 'clients', $clientId)) fail('Client not found.', 404);
    $f = site_fields($data);
    if (!isset($f['name'])) fail('Give the site a name.');
    site_name_free($conn, $clientId, $f['name']);
    $reqIds = site_requirement_ids($conn, $data['requirement_ids'] ?? []);
    $active = $f['active'] ?? 1;

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'INSERT INTO sites (client_id, name, address, postcode, access_notes, contact_name, contact_phone, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $address = $f['address'] ?? null;
    $postcode = $f['postcode'] ?? null;
    $notes = $f['access_notes'] ?? null;
    $contact = $f['contact_name'] ?? null;
    $phone = $f['contact_phone'] ?? null;
    $stmt->bind_param('issssssi', $clientId, $f['name'], $address, $postcode, $notes, $contact, $phone, $active);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    site_set_requirements($conn, $id, $reqIds, $uid);
    $conn->commit();

    // Access notes can hold a door code, so the audit names the fields rather
    // than copying them.
    audit($conn, $uid, 'site.create', $f['name'] . " (client #$clientId)", 'site', $id, null,
        ['requirement_ids' => $reqIds]);
    json_out(['id' => $id], 201);
}

// ---------------------------------------------------------------- edit

if ($action === 'update' && method() === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A site is required.');
    $stmt = $conn->prepare(
        'SELECT id, client_id, name, address, postcode, access_notes, contact_name, contact_phone, active
           FROM sites WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $site = $stmt->get_result()->fetch_assoc();
    if (!$site) fail('Site not found.', 404);

    $data = body();
    $f = site_fields($data);
    if (isset($f['name']) && $f['name'] !== $site['name']) site_name_free($conn, (int) $site['client_id'], $f['name'], $id);
    $after = array_merge($site, $f);

    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'UPDATE sites SET name = ?, address = ?, postcode = ?, access_notes = ?, contact_name = ?, contact_phone = ?, active = ?
          WHERE id = ?'
    );
    $active = (int) $after['active'];
    $stmt->bind_param('ssssssii', $after['name'], $after['address'], $after['postcode'], $after['access_notes'],
        $after['contact_name'], $after['contact_phone'], $active, $id);
    $stmt->execute();
    $reqChange = null;
    if (array_key_exists('requirement_ids', $data)) {
        $reqChange = site_set_requirements($conn, $id, site_requirement_ids($conn, $data['requirement_ids']), $uid);
    }
    $conn->commit();

    [$b, $a] = audit_diff($site, $after, ['name', 'address', 'postcode', 'contact_name', 'active']);
    $changed = array_keys(audit_diff($site, $after, ['access_notes', 'contact_phone'])[1]);
    if ($reqChange && $reqChange[0] !== $reqChange[1]) {
        $b['requirement_ids'] = $reqChange[0];
        $a['requirement_ids'] = $reqChange[1];
    }
    audit($conn, $uid, 'site.update', $after['name'] . ($changed ? ' (also changed: ' . implode(', ', $changed) . ')' : ''),
        'site', $id, $b ?: null, $a ?: null);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
