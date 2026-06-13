<?php
// In-app notifications (the bell).
// GET  /notifications.php               -> { notifications: [...], unread }
// GET  /notifications.php?action=unread -> { unread }
// POST /notifications.php?action=read {id}   -> mark one read
// POST /notifications.php?action=read_all     -> mark all my notifications read
// A user sees their own notifications plus broadcasts (user_id IS NULL). Unread
// count tracks only the user's own targeted notifications.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$uid = (int) $user['id'];
$action = $_GET['action'] ?? '';

function unread_count(mysqli $conn, int $uid): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    return (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
}

if ($action === 'unread' && method() === 'GET') {
    json_out(['unread' => unread_count($conn, $uid)]);
}

if (method() === 'GET') {
    $stmt = $conn->prepare(
        'SELECT id, type, title, body, url, read_at, created_at, (user_id IS NULL) AS broadcast
         FROM notifications
         WHERE user_id = ? OR user_id IS NULL
         ORDER BY id DESC LIMIT 50'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'],
            'body' => $r['body'], 'url' => $r['url'],
            'read' => $r['read_at'] !== null || (int) $r['broadcast'] === 1,
            'broadcast' => (int) $r['broadcast'] === 1,
            'created_at' => $r['created_at'],
        ];
    }
    json_out(['notifications' => $items, 'unread' => unread_count($conn, $uid)]);
}

if ($action === 'read' && method() === 'POST') {
    $data = body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) fail('A notification id is required.');
    $stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    json_out(['ok' => true, 'unread' => unread_count($conn, $uid)]);
}

if ($action === 'read_all' && method() === 'POST') {
    $stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    json_out(['ok' => true, 'unread' => 0]);
}

fail('Method not allowed.', 405);
