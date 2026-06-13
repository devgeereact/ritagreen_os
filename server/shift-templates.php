<?php
// Recurring shift templates → generate open shifts forward.
// GET  /shift-templates.php                       -> { templates: [...] }
// POST /shift-templates.php {title, division, client_id?, site_name?, weekday, start_time, end_time} -> create
// POST /shift-templates.php?action=toggle&id=N    -> activate/deactivate
// POST /shift-templates.php?action=generate&weeks=4 -> create open shifts from active templates
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT t.id, t.title, t.division, t.client_id, c.name AS client, t.site_name,
                t.weekday, t.start_time, t.end_time, t.active
         FROM shift_templates t LEFT JOIN clients c ON c.id = t.client_id
         ORDER BY t.weekday, t.start_time'
    );
    $templates = [];
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int) $r['id'];
        $r['client_id'] = $r['client_id'] !== null ? (int) $r['client_id'] : null;
        $r['weekday'] = (int) $r['weekday'];
        $r['active'] = (bool) $r['active'];
        $templates[] = $r;
    }
    json_out(['templates' => $templates]);
}

if ($action === 'toggle' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A template is required.');
    $conn->query('UPDATE shift_templates SET active = 1 - active WHERE id = ' . $id);
    audit($conn, (int) $user['id'], 'template.toggle', "#$id");
    json_out(['ok' => true]);
}

if ($action === 'generate' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $weeks = max(1, min(12, (int) ($_GET['weeks'] ?? 4)));

    $res = $conn->query(
        'SELECT id, title, division, client_id, site_name, weekday, start_time, end_time
         FROM shift_templates WHERE active = 1'
    );
    $templates = [];
    while ($r = $res->fetch_assoc()) $templates[] = $r;

    $created = 0;
    $insert = $conn->prepare(
        'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at, status)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, "open")'
    );
    // Guard against re-generating the same open shift (same template signature + datetime).
    $dupe = $conn->prepare(
        'SELECT id FROM shifts WHERE division = ? AND title = ? AND starts_at = ? AND status = "open" LIMIT 1'
    );

    for ($wk = 0; $wk < $weeks; $wk++) {
        foreach ($templates as $t) {
            // Next occurrence of this ISO weekday in week offset $wk.
            $base = strtotime("+$wk week");
            $todayDow = (int) date('N', $base);
            $delta = ((int) $t['weekday'] - $todayDow + 7) % 7;
            $day = date('Y-m-d', strtotime("+$delta day", $base));
            $startsAt = "$day {$t['start_time']}";
            $endsAt = "$day {$t['end_time']}";
            if (strtotime($endsAt) <= strtotime($startsAt)) {
                $endsAt = date('Y-m-d H:i:s', strtotime($endsAt . ' +1 day')); // overnight
            }

            // Locals (bind_param needs variables, not array elements).
            $clientId = $t['client_id'] !== null ? (int) $t['client_id'] : null;
            $division = $t['division']; $title = $t['title']; $siteName = $t['site_name'];

            $dupe->bind_param('sss', $division, $title, $startsAt);
            $dupe->execute();
            if ($dupe->get_result()->fetch_assoc()) continue;

            $insert->bind_param('isssss', $clientId, $division, $title, $siteName, $startsAt, $endsAt);
            $insert->execute();
            $created++;
        }
    }
    audit($conn, (int) $user['id'], 'template.generate', "$created open shifts");
    json_out(['created' => $created]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $title = trim((string) ($data['title'] ?? ''));
    $division = trim((string) ($data['division'] ?? ''));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $site = trim((string) ($data['site_name'] ?? ''));
    $weekday = (int) ($data['weekday'] ?? 0);
    $start = trim((string) ($data['start_time'] ?? ''));
    $end = trim((string) ($data['end_time'] ?? ''));

    if ($title === '' || !in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Title and a valid division are required.');
    if ($weekday < 1 || $weekday > 7) fail('Choose a weekday.');
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) fail('Times must be HH:MM.');

    $siteVal = $site !== '' ? $site : null;
    $stmt = $conn->prepare(
        'INSERT INTO shift_templates (title, division, client_id, site_name, weekday, start_time, end_time)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssiiss', $title, $division, $clientId, $siteVal, $weekday, $start, $end);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'template.create', $title);
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
