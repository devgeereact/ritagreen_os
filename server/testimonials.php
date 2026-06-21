<?php
// Client testimonials.
//
// Public (no auth):
//   GET  /testimonials.php              -> { testimonials: [...] }  published only
//
// Admin (auth: super_admin | admin | manager | editor):
//   GET  /testimonials.php?action=all              -> { testimonials: [...] } incl. drafts
//   POST /testimonials.php?action=create  body{}   -> { ok, id }
//   POST /testimonials.php?action=update&id=5 body{} -> { ok }
//   POST /testimonials.php?action=delete&id=5      -> { ok }
require __DIR__ . '/lib.php';

$action = $_GET['action'] ?? '';
$m = method();

// ---------- Public read ----------
if ($m === 'GET' && $action === '') {
    $res = $conn->query(
        'SELECT author, role, rating, body
         FROM testimonials
         WHERE status = "published" AND deleted_at IS NULL
         ORDER BY sort_order ASC, id DESC
         LIMIT 60'
    );
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['rating'] = (int) $row['rating'];
        $rows[] = $row;
    }
    json_out(['testimonials' => $rows]);
}

// ---------- Admin ----------
$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager', 'editor');

if ($m === 'GET' && $action === 'all') {
    $res = $conn->query(
        'SELECT id, author, role, rating, body, status, sort_order
         FROM testimonials
         WHERE deleted_at IS NULL
         ORDER BY sort_order ASC, id DESC'
    );
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['rating'] = (int) $row['rating'];
        $row['sort_order'] = (int) $row['sort_order'];
        $rows[] = $row;
    }
    json_out(['testimonials' => $rows]);
}

if ($m === 'POST' && ($action === 'create' || $action === 'update')) {
    $d = body();
    $author = trim($d['author'] ?? '');
    if ($author === '') fail('An author name is required.');
    $bodytext = trim($d['body'] ?? '');
    if ($bodytext === '') fail('The testimonial text is required.');
    $role = trim($d['role'] ?? '');
    $rating = (int) ($d['rating'] ?? 5);
    if ($rating < 1) $rating = 1;
    if ($rating > 5) $rating = 5;
    $status = (($d['status'] ?? 'published') === 'draft') ? 'draft' : 'published';
    $sort = (int) ($d['sort_order'] ?? 0);

    if ($action === 'create') {
        $stmt = $conn->prepare(
            'INSERT INTO testimonials (author, role, rating, body, status, sort_order, author_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $authorId = (int) $user['id'];
        $stmt->bind_param('ssissii', $author, $role, $rating, $bodytext, $status, $sort, $authorId);
        if (!$stmt->execute()) fail('Could not save the testimonial.');
        $id = $conn->insert_id;
        audit($conn, (int) $user['id'], 'testimonial.create', "#$id");
        json_out(['ok' => true, 'id' => $id], 201);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A testimonial is required.');
    $stmt = $conn->prepare(
        'UPDATE testimonials
         SET author = ?, role = ?, rating = ?, body = ?, status = ?, sort_order = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ssissii', $author, $role, $rating, $bodytext, $status, $sort, $id);
    if (!$stmt->execute()) fail('Could not update the testimonial.');
    audit($conn, (int) $user['id'], 'testimonial.update', "#$id");
    json_out(['ok' => true]);
}

if ($m === 'POST' && $action === 'delete') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A testimonial is required.');
    $stmt = $conn->prepare('UPDATE testimonials SET deleted_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'testimonial.delete', "#$id");
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
