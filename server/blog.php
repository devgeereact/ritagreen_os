<?php
// Blog / resources posts.
//
// Public (no auth):
//   GET  /blog.php                 -> { posts: [...] }   published only
//   GET  /blog.php?slug=my-post    -> { post: {...} }    single published post
//
// Admin (auth: super_admin | admin | manager | editor):
//   GET  /blog.php?action=all                 -> { posts: [...] }  incl. drafts
//   POST /blog.php?action=create   body{...}  -> { ok, id }
//   POST /blog.php?action=update&id=5 body{}  -> { ok }
//   POST /blog.php?action=delete&id=5         -> { ok }
require __DIR__ . '/lib.php';

function blog_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : 'post';
}

$action = $_GET['action'] ?? '';
$m = method();

// ---------- Public reads ----------
if ($m === 'GET' && $action === '') {
    $slug = trim($_GET['slug'] ?? '');
    if ($slug !== '') {
        $stmt = $conn->prepare(
            'SELECT slug, title, excerpt, category, image, body, read_mins, published_at
             FROM blog_posts
             WHERE slug = ? AND status = "published" AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        if (!$post) fail('Post not found.', 404);
        $post['read_mins'] = (int) $post['read_mins'];
        json_out(['post' => $post]);
    }
    $res = $conn->query(
        'SELECT slug, title, excerpt, category, image, body, read_mins, published_at
         FROM blog_posts
         WHERE status = "published" AND deleted_at IS NULL
         ORDER BY published_at DESC, id DESC
         LIMIT 100'
    );
    $posts = [];
    while ($row = $res->fetch_assoc()) {
        $row['read_mins'] = (int) $row['read_mins'];
        $posts[] = $row;
    }
    json_out(['posts' => $posts]);
}

// ---------- Admin (authenticated) ----------
$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager', 'editor');

if ($m === 'GET' && $action === 'all') {
    $res = $conn->query(
        'SELECT id, slug, title, excerpt, category, image, body, read_mins, status, published_at
         FROM blog_posts
         WHERE deleted_at IS NULL
         ORDER BY COALESCE(published_at, created_at) DESC, id DESC'
    );
    $posts = [];
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['read_mins'] = (int) $row['read_mins'];
        $posts[] = $row;
    }
    json_out(['posts' => $posts]);
}

if ($m === 'POST' && ($action === 'create' || $action === 'update')) {
    $d = body();
    $title = trim($d['title'] ?? '');
    if ($title === '') fail('A title is required.');
    $slug = blog_slugify($d['slug'] ?? '') ?: blog_slugify($title);
    if (($d['slug'] ?? '') === '') $slug = blog_slugify($title);
    $excerpt = trim($d['excerpt'] ?? '');
    $category = trim($d['category'] ?? 'Company');
    $image = trim($d['image'] ?? '');
    $bodytext = (string) ($d['body'] ?? '');
    $status = (($d['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
    $pub = trim($d['published_at'] ?? '');
    if ($pub === '') $pub = date('Y-m-d');
    $read = (int) ($d['read_mins'] ?? 0);
    if ($read <= 0) {
        $words = str_word_count(strip_tags($bodytext));
        $read = max(1, (int) ceil($words / 200));
    }

    if ($action === 'create') {
        $stmt = $conn->prepare(
            'INSERT INTO blog_posts (slug, title, excerpt, category, image, body, read_mins, status, published_at, author_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $authorId = (int) $user['id'];
        $stmt->bind_param('ssssssissi', $slug, $title, $excerpt, $category, $image, $bodytext, $read, $status, $pub, $authorId);
        if (!$stmt->execute()) {
            if ($conn->errno === 1062) fail('A post with this URL slug already exists.');
            fail('Could not save the post.');
        }
        $id = $conn->insert_id;
        audit($conn, (int) $user['id'], 'blog.create', "#$id $slug");
        json_out(['ok' => true, 'id' => $id], 201);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A post is required.');
    $stmt = $conn->prepare(
        'UPDATE blog_posts
         SET slug = ?, title = ?, excerpt = ?, category = ?, image = ?, body = ?, read_mins = ?, status = ?, published_at = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->bind_param('ssssssissi', $slug, $title, $excerpt, $category, $image, $bodytext, $read, $status, $pub, $id);
    if (!$stmt->execute()) {
        if ($conn->errno === 1062) fail('A post with this URL slug already exists.');
        fail('Could not update the post.');
    }
    audit($conn, (int) $user['id'], 'blog.update', "#$id $slug");
    json_out(['ok' => true]);
}

if ($m === 'POST' && $action === 'delete') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('A post is required.');
    $stmt = $conn->prepare('UPDATE blog_posts SET deleted_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, (int) $user['id'], 'blog.delete', "#$id");
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
