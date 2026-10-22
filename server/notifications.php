<?php
// In-app notifications (the bell).
// GET  /notifications.php               -> { notifications: [...], unread }
// GET  /notifications.php?action=unread -> { unread }
// POST /notifications.php?action=read {id}   -> mark one read
// POST /notifications.php?action=read_all     -> mark all my notifications read
// A user sees their own notifications plus, for staff accounts only, broadcasts
// (user_id IS NULL). Broadcasts name applicants, cover-request contacts and
// workers raising panic alerts, so a client or worker login gets only its own.
//
// A broadcast is one row read by many people, and the row has a single read_at,
// so per-user read state lives in notification_reads (migration 025). Before it
// existed every broadcast was forced to read = true and the unread count ignored
// them entirely -- which meant a panic alert, which is a broadcast, arrived
// already marked read and never lit the bell.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
$uid = (int) $user['id'];
$seesBroadcasts = is_staff($user);
$action = $_GET['action'] ?? '';

function unread_count(mysqli $conn, int $uid, bool $seesBroadcasts): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $own = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
    if (!$seesBroadcasts) return $own;

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM notifications n
          WHERE n.user_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM notification_reads r
                             WHERE r.notification_id = n.id AND r.user_id = ?)'
    );
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    return $own + (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
}

if ($action === 'unread' && method() === 'GET') {
    json_out(['unread' => unread_count($conn, $uid, $seesBroadcasts)]);
}

if (method() === 'GET') {
    $stmt = $conn->prepare(
        $seesBroadcasts
            ? 'SELECT n.id, n.type, n.title, n.body, n.url, n.read_at, n.created_at,
                      (n.user_id IS NULL) AS broadcast, r.read_at AS broadcast_read_at
               FROM notifications n
               LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
               WHERE n.user_id = ? OR n.user_id IS NULL
               ORDER BY n.id DESC LIMIT 50'
            : 'SELECT id, type, title, body, url, read_at, created_at,
                      0 AS broadcast, NULL AS broadcast_read_at
               FROM notifications
               WHERE user_id = ?
               ORDER BY id DESC LIMIT 50'
    );
    if ($seesBroadcasts) $stmt->bind_param('ii', $uid, $uid);
    else $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'],
            'body' => $r['body'], 'url' => $r['url'],
            // A broadcast is read when THIS user has a receipt for it, not
            // simply because it is a broadcast.
            'read' => (int) $r['broadcast'] === 1
                ? $r['broadcast_read_at'] !== null
                : $r['read_at'] !== null,
            'broadcast' => (int) $r['broadcast'] === 1,
            'created_at' => $r['created_at'],
        ];
    }
    json_out(['notifications' => $items, 'unread' => unread_count($conn, $uid, $seesBroadcasts)]);
}

if ($action === 'read' && method() === 'POST') {
    $data = body();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) fail('A notification id is required.');
    $stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();

    // Nothing was updated and it may be a broadcast, which is read by receipt.
    // Only staff see broadcasts at all, so only staff can leave one.
    if ($stmt->affected_rows === 0 && $seesBroadcasts) {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO notification_reads (notification_id, user_id)
             SELECT id, ? FROM notifications WHERE id = ? AND user_id IS NULL'
        );
        $stmt->bind_param('ii', $uid, $id);
        $stmt->execute();
    }
    json_out(['ok' => true, 'unread' => unread_count($conn, $uid, $seesBroadcasts)]);
}

if ($action === 'read_all' && method() === 'POST') {
    $stmt = $conn->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $uid);
    $stmt->execute();

    // And every broadcast this account can see, or "mark all read" leaves the
    // bell lit and the button looks broken.
    if ($seesBroadcasts) {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO notification_reads (notification_id, user_id)
             SELECT id, ? FROM notifications WHERE user_id IS NULL'
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
    }
    json_out(['ok' => true, 'unread' => 0]);
}

fail('Method not allowed.', 405);
