<?php
// The live half of the sitemap: published role pages and published posts.
//
//   GET /sitemap.php   -> application/xml, public, cached for an hour
//
// The static pages stay in dist/sitemap.xml, written at build time from
// src/lib/public-routes.ts (the same table the router reads). That build runs in
// CI, which cannot reach the database, so records that live in the database are
// listed here instead. robots.txt names both files; a crawler reads every
// Sitemap line it is given, so neither has to know about the other.
//
// Only what is public anyway: the slug and the date of a published vacancy or
// post. Drafts, closed roles and archived rows are never listed.
require __DIR__ . '/lib.php';

if (method() !== 'GET') fail('Method not allowed.', 405);

$origin = 'https://ritagreenservices.com';
$urls = [];

$res = $conn->query(
    'SELECT slug, COALESCE(updated_at, published_at) AS changed FROM vacancies
     WHERE status = "published" AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000'
);
while ($row = $res->fetch_assoc()) {
    if (!rg_valid_slug($row['slug'])) continue;
    $urls[] = ['loc' => "$origin/careers/{$row['slug']}", 'lastmod' => $row['changed'], 'priority' => '0.7'];
}

// blog_posts is created by schema.sql (and by blog.php on first use).
$res = $conn->query(
    'SELECT slug, published_at FROM blog_posts
     WHERE status = "published" AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1000'
);
while ($row = $res->fetch_assoc()) {
    $urls[] = ['loc' => "$origin/news/" . rawurlencode($row['slug']), 'lastmod' => $row['published_at'], 'priority' => '0.5'];
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
http_response_code(200);

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $xml .= '  <url><loc>' . htmlspecialchars($u['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    $ts = $u['lastmod'] ? strtotime((string) $u['lastmod']) : false;
    if ($ts) $xml .= '<lastmod>' . gmdate('Y-m-d', $ts) . '</lastmod>';
    $xml .= '<priority>' . $u['priority'] . '</priority></url>' . "\n";
}
$xml .= '</urlset>' . "\n";
print $xml;
