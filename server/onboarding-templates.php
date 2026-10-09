<?php
// Onboarding checklists, one per service.
//
//   GET  /onboarding-templates.php                       every item, grouped by service
//   POST /onboarding-templates.php {service, label}      add an item
//   POST /onboarding-templates.php?action=update&id=N {label?, sort_order?, active?}
//
// Starting onboarding (ats.php?action=onboard) seeds the new worker's checklist
// from the active items for their service. Editing a list changes what the NEXT
// person is asked for; it does not reach into a checklist already seeded, which
// is somebody's work in progress.
//
// Items are retired rather than deleted (active = 0), so a checklist seeded from
// an item last year can still be read against the list it came from.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// The office decides what a new worker is asked for. Owner and managers only,
// which is the same set that may start onboarding.
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';
$scope = service_scope($user);

const OT_SERVICES = ['care', 'cleaning', 'security', 'academy'];

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT id, service, label, sort_order, active, updated_at
           FROM onboarding_template_items ORDER BY service, active DESC, sort_order, id'
    );
    $services = [];
    foreach (OT_SERVICES as $service) {
        if (rg_in_service_scope($scope, $service)) $services[$service] = [];
    }
    while ($row = $res->fetch_assoc()) {
        if (!isset($services[$row['service']])) continue;
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['active'] = (bool) $row['active'];
        $services[$row['service']][] = $row;
    }
    json_out(['services' => $services]);
}

if (method() !== 'POST') fail('Method not allowed.', 405);
$uid = (int) $user['id'];

if ($action === '') {
    $data = body();
    $service = trim((string) ($data['service'] ?? ''));
    $label = trim((string) ($data['label'] ?? ''));
    if (!in_array($service, OT_SERVICES, true)) fail('Choose a service.');
    // A manager confined to security edits the security list and no other.
    require_service_scope($user, $service);
    if ($label === '') fail('Say what the task is.');
    if (mb_strlen($label) > 160) fail('Please keep the task under 160 characters.');

    $stmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM onboarding_template_items WHERE service = ?');
    $stmt->bind_param('s', $service);
    $stmt->execute();
    $sort = (int) $stmt->get_result()->fetch_row()[0];

    $stmt = $conn->prepare(
        'INSERT INTO onboarding_template_items (service, label, sort_order, created_by) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('ssii', $service, $label, $sort, $uid);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // uniq_template_item: the same task twice in one list is a mistake.
        if ($e->getCode() === 1062) fail('That task is already on the list for this service.', 409);
        throw $e;
    }
    $id = (int) $conn->insert_id;

    audit($conn, $uid, 'onboarding_template.add', "$service: $label", 'onboarding_template_item', $id,
        null, ['service' => $service, 'label' => $label]);
    json_out(['ok' => true, 'id' => $id], 201);
}

if ($action === 'update') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('An item is required.');
    $stmt = $conn->prepare('SELECT id, service, label, sort_order, active FROM onboarding_template_items WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    if (!$item) fail('Item not found.', 404);
    require_service_scope($user, $item['service']);

    $data = body();
    $label = array_key_exists('label', $data) ? trim((string) $data['label']) : (string) $item['label'];
    $sort = array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : (int) $item['sort_order'];
    $active = array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : (int) $item['active'];
    if ($label === '') fail('Say what the task is.');
    if (mb_strlen($label) > 160) fail('Please keep the task under 160 characters.');

    $stmt = $conn->prepare('UPDATE onboarding_template_items SET label = ?, sort_order = ?, active = ? WHERE id = ?');
    $stmt->bind_param('siii', $label, $sort, $active, $id);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) fail('That task is already on the list for this service.', 409);
        throw $e;
    }

    [$before, $after] = audit_diff(
        $item,
        ['label' => $label, 'sort_order' => $sort, 'active' => $active],
        ['label', 'sort_order', 'active']
    );
    if ($after) {
        audit($conn, $uid, 'onboarding_template.update', $item['service'] . ': ' . $label,
            'onboarding_template_item', $id, $before, $after);
    }
    json_out(['ok' => true]);
}

fail('Unknown action.', 404);
