<?php
// Skills catalogue + per-worker skill assignment (feeds smart matching).
// GET  /skills.php                       -> { skills: [...] }
// GET  /skills.php?worker_id=N           -> { skills: [...all], assigned: [skill_id,...] }
// POST /skills.php?action=set&worker_id=N { skill_ids: [..] } -> replace a worker's skills
// POST /skills.php { name, category }    -> add a skill to the catalogue
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$action = $_GET['action'] ?? '';

if (method() === 'GET') {
    $skills = [];
    $res = $conn->query('SELECT id, name, category FROM skills ORDER BY category, name');
    while ($r = $res->fetch_assoc()) {
        $r['id'] = (int) $r['id'];
        $skills[] = $r;
    }
    $workerId = isset($_GET['worker_id']) ? (int) $_GET['worker_id'] : 0;
    if ($workerId > 0) {
        $assigned = [];
        $stmt = $conn->prepare('SELECT skill_id FROM worker_skills WHERE worker_id = ?');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) $assigned[] = (int) $row['skill_id'];
        json_out(['skills' => $skills, 'assigned' => $assigned]);
    }
    json_out(['skills' => $skills]);
}

if ($action === 'set' && method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $workerId = (int) ($_GET['worker_id'] ?? 0);
    $data = body();
    $ids = is_array($data['skill_ids'] ?? null) ? array_map('intval', $data['skill_ids']) : [];
    if ($workerId <= 0) fail('A worker is required.');

    $stmt = $conn->prepare('SELECT id FROM workers WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) fail('Worker not found.', 404);

    // Replace the set.
    $stmt = $conn->prepare('DELETE FROM worker_skills WHERE worker_id = ?');
    $stmt->bind_param('i', $workerId);
    $stmt->execute();
    if ($ids) {
        $stmt = $conn->prepare('INSERT IGNORE INTO worker_skills (worker_id, skill_id) VALUES (?, ?)');
        foreach (array_unique($ids) as $sid) {
            if ($sid <= 0) continue;
            $stmt->bind_param('ii', $workerId, $sid);
            $stmt->execute();
        }
    }
    audit($conn, (int) $user['id'], 'worker.skills', "worker #$workerId -> " . count($ids) . ' skills');
    json_out(['ok' => true]);
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin', 'manager');
    $data = body();
    $name = trim((string) ($data['name'] ?? ''));
    $category = trim((string) ($data['category'] ?? 'general'));
    if ($name === '') fail('A skill name is required.');
    if (!in_array($category, ['care', 'academy', 'cleaning', 'security', 'general'], true)) $category = 'general';
    $stmt = $conn->prepare('INSERT IGNORE INTO skills (name, category) VALUES (?, ?)');
    $stmt->bind_param('ss', $name, $category);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'skill.create', $name);
    json_out(['ok' => true], 201);
}

fail('Method not allowed.', 405);
