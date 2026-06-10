<?php
// Smoke-test endpoint. Proves React -> PHP -> MySQL end to end.
// GET  /api.php        -> { items: [...] }
// POST /api.php {name} -> { item: {...} }
require __DIR__ . '/lib.php';

if (method() === 'GET') {
    $res = $conn->query('SELECT id, name, created_at FROM items ORDER BY id DESC LIMIT 100');
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $items[] = $row;
    }
    json_out(['items' => $items]);
}

if (method() === 'POST') {
    $name = trim((string) (body()['name'] ?? ''));
    if ($name === '') fail('A name is required.');
    if (mb_strlen($name) > 255) fail('Name is too long.');

    $stmt = $conn->prepare('INSERT INTO items (name) VALUES (?)');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $id = $conn->insert_id;

    json_out([
        'item' => ['id' => (int) $id, 'name' => $name],
    ], 201);
}

fail('Method not allowed.', 405);
