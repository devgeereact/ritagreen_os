<?php
// §15 Incidents, accidents, safeguarding & complaints.
// GET  /incidents.php                       -> { incidents:[...], summary:{...} }
// GET  /incidents.php?id=N                  -> { incident, actions:[...] }
// POST /incidents.php {division,type,severity,title,detail,...} -> create
// POST /incidents.php?action=status&id=N {status}
// POST /incidents.php?action=add_action&id=N {note}
// POST /incidents.php?action=action_done&action_id=N {done}
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $stmt = $conn->prepare(
        'SELECT i.*, c.name AS client, w.name AS worker, u.name AS reporter
         FROM incidents i
         LEFT JOIN clients c ON c.id = i.client_id
         LEFT JOIN workers w ON w.id = i.worker_id
         LEFT JOIN users u ON u.id = i.reported_by
         WHERE i.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $inc = $stmt->get_result()->fetch_assoc();
    if (!$inc) fail('Incident not found.', 404);
    foreach (['id', 'client_id', 'worker_id', 'riddor', 'safeguarding'] as $k) {
        if ($inc[$k] !== null) $inc[$k] = (int) $inc[$k];
    }

    $stmt = $conn->prepare('SELECT id, note, done, created_at FROM incident_actions WHERE incident_id = ? ORDER BY created_at');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $actions = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['done'] = (int) $row['done'];
        $actions[] = $row;
    }
    json_out(['incident' => $inc, 'actions' => $actions]);
}

if (method() === 'GET') {
    $res = $conn->query(
        'SELECT i.id, i.ref, i.division, i.type, i.severity, i.title, i.status,
                i.riddor, i.safeguarding, i.created_at, c.name AS client, w.name AS worker
         FROM incidents i
         LEFT JOIN clients c ON c.id = i.client_id
         LEFT JOIN workers w ON w.id = i.worker_id
         ORDER BY FIELD(i.severity,"critical","high","medium","low"), i.created_at DESC
         LIMIT 400'
    );
    $items = [];
    $summary = ['open' => 0, 'safeguarding' => 0, 'riddor' => 0, 'critical' => 0];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['riddor'] = (int) $row['riddor'];
        $row['safeguarding'] = (int) $row['safeguarding'];
        if (in_array($row['status'], ['open', 'investigating'], true)) $summary['open']++;
        if ($row['safeguarding']) $summary['safeguarding']++;
        if ($row['riddor']) $summary['riddor']++;
        if ($row['severity'] === 'critical') $summary['critical']++;
        $items[] = $row;
    }
    json_out(['incidents' => $items, 'summary' => $summary]);
}

if ($action === 'status' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $status = trim((string) (body()['status'] ?? ''));
    if ($id <= 0 || !in_array($status, ['open', 'investigating', 'actioned', 'closed'], true)) fail('Valid incident and status required.');
    $stmt = $conn->prepare('UPDATE incidents SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'incident.status', "#$id -> $status");
    json_out(['ok' => true]);
}

if ($action === 'add_action' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $id = (int) ($_GET['id'] ?? 0);
    $note = trim((string) (body()['note'] ?? ''));
    if ($id <= 0 || $note === '') fail('An incident and note are required.');
    $uid = (int) $user['id'];
    $stmt = $conn->prepare('INSERT INTO incident_actions (incident_id, note, created_by) VALUES (?, ?, ?)');
    $stmt->bind_param('isi', $id, $note, $uid);
    $stmt->execute();
    json_out(['ok' => true], 201);
}

if ($action === 'action_done' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $aid = (int) ($_GET['action_id'] ?? 0);
    $done = !empty(body()['done']) ? 1 : 0;
    if ($aid <= 0) fail('An action is required.');
    $stmt = $conn->prepare('UPDATE incident_actions SET done = ? WHERE id = ?');
    $stmt->bind_param('ii', $done, $aid);
    $stmt->execute();
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager', 'trainer', 'employee');
    $data = body();
    $division = trim((string) ($data['division'] ?? 'care'));
    $type = trim((string) ($data['type'] ?? 'incident'));
    $severity = trim((string) ($data['severity'] ?? 'low'));
    $title = trim((string) ($data['title'] ?? ''));
    $detail = trim((string) ($data['detail'] ?? ''));
    $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
    $site = trim((string) ($data['site_name'] ?? ''));
    $workerId = isset($data['worker_id']) && $data['worker_id'] !== '' ? (int) $data['worker_id'] : null;
    $riddor = !empty($data['riddor']) ? 1 : 0;
    $safeguarding = !empty($data['safeguarding']) ? 1 : 0;
    $occurred = trim((string) ($data['occurred_at'] ?? ''));

    if ($title === '' || $detail === '') fail('A title and detail are required.');
    if (!in_array($division, ['care', 'academy', 'cleaning', 'security'], true)) fail('Invalid division.');
    if (!in_array($type, ['accident', 'incident', 'near_miss', 'safeguarding', 'complaint'], true)) fail('Invalid type.');
    if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) fail('Invalid severity.');
    if ($type === 'safeguarding') $safeguarding = 1;

    $year = date('Y');
    $seq = next_seq($conn, "incident-$year");
    $ref = sprintf('INC-%s-%04d', $year, $seq);
    $siteVal = $site !== '' ? $site : null;
    $occVal = $occurred !== '' && strtotime($occurred) ? date('Y-m-d H:i:s', strtotime($occurred)) : null;
    $uid = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO incidents (ref, division, type, severity, title, detail, client_id, site_name, worker_id, riddor, safeguarding, reported_by, occurred_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssssisiiiis', $ref, $division, $type, $severity, $title, $detail, $clientId, $siteVal, $workerId, $riddor, $safeguarding, $uid, $occVal);
    $stmt->execute();
    $id = $conn->insert_id;

    // Escalate the serious stuff to the office bell + email.
    if ($safeguarding || $severity === 'critical' || $riddor) {
        $flag = $safeguarding ? 'SAFEGUARDING' : ($riddor ? 'RIDDOR' : 'CRITICAL');
        notify($conn, null, "$flag incident: $title", "$ref · " . ucfirst($division), 'incident', '/app/incidents');
        // Queued regardless of whether mail is on -- see server/send-queued-mail.php.
        {
            queue_mail($conn, MAIL_TO, "[$flag] Incident $ref", "$title\n\n$detail\n\nLog in to action this immediately.");
        }
    }
    audit($conn, $uid, 'incident.create', "$ref ($type/$severity)");
    json_out(['ok' => true, 'ref' => $ref, 'id' => (int) $id], 201);
}

fail('Method not allowed.', 405);
