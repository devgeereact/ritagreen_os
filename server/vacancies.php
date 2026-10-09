<?php
// Vacancies: the roles on /careers and /careers/:slug (2026 plan, files 05 and
// 06, section S7).
//
// Public (no auth), published roles only, an explicit list of fields:
//   GET  /vacancies.php                  -> { vacancies: [...] }
//   GET  /vacancies.php?slug=x           -> { vacancy: {...} }
//        A closed role answers 410 with its title, so the page can say it has
//        closed; a draft or unknown slug answers 404 and says nothing about it.
//
// Website area (super_admin | admin | manager | editor, internal staff only;
// a service-scoped account sees and changes only its own service's roles):
//   GET  /vacancies.php?action=all              -> { vacancies, default_checks }
//   GET  /vacancies.php?action=get&id=N         -> { vacancy }
//   POST /vacancies.php?action=create           body {...} -> { ok, id, slug }
//   POST /vacancies.php?action=update&id=N      body {...} -> { ok, slug }
//   POST /vacancies.php?action=status&id=N      body {status} -> { ok, status }
//   POST /vacancies.php?action=delete&id=N      archive (soft delete)
//
// Drafts are never public. The slug is fixed once a role has been published,
// because its address is then in search results and social posts.
require __DIR__ . '/lib.php';

const VACANCY_SERVICES = ['care', 'academy', 'cleaning', 'security'];

$action = $_GET['action'] ?? '';
$m = method();

// ---------- Public reads ----------
if ($m === 'GET' && $action === '') {
    // Published changes must reach the site within five minutes (file 06).
    header('Cache-Control: public, max-age=300');

    $slug = trim((string) ($_GET['slug'] ?? ''));
    if ($slug !== '') {
        if (!rg_valid_slug($slug)) fail('Role not found.', 404);
        $stmt = $conn->prepare(
            'SELECT v.slug, v.title, v.service, v.location, v.hours_text, v.pay_text, v.summary,
                    v.requirements, v.checks, v.status, v.published_at,
                    f.google_form_id, f.display AS form_display, f.active AS form_active,
                    (f.id IS NOT NULL AND f.deleted_at IS NULL) AS has_form
             FROM vacancies v
             LEFT JOIN website_forms f ON f.id = v.form_id
             WHERE v.slug = ? AND v.deleted_at IS NULL AND v.status IN ("published", "closed")
             LIMIT 1'
        );
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) fail('Role not found.', 404);
        if ($row['status'] === 'closed') {
            json_out(['error' => 'This role has closed.', 'closed' => true, 'title' => $row['title']], 410);
        }

        $route = rg_vacancy_apply_route((bool) $row['has_form'], (bool) $row['form_active']);
        // The id is checked again on the way out: the website builds an iframe
        // from it, so nothing but a valid id may ever leave here.
        $form = ($route === 'form' && rg_valid_google_form_id($row['google_form_id']))
            ? ['id' => $row['google_form_id'], 'display' => $row['form_display']]
            : null;
        if ($route === 'form' && $form === null) $route = 'closed';

        json_out(['vacancy' => [
            'slug' => $row['slug'],
            'title' => $row['title'],
            'service' => $row['service'],
            'location' => $row['location'],
            'hours_text' => $row['hours_text'],
            'pay_text' => $row['pay_text'],
            'summary' => $row['summary'],
            'requirements' => $row['requirements'],
            'checks' => $row['checks'],
            'published_at' => $row['published_at'],
            'apply' => $route,
            'form' => $form,
        ]]);
    }

    $res = $conn->query(
        'SELECT slug, title, service, location, hours_text, pay_text, summary, published_at
         FROM vacancies
         WHERE status = "published" AND deleted_at IS NULL
         ORDER BY published_at DESC, id DESC
         LIMIT 200'
    );
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    json_out(['vacancies' => $items]);
}

// ---------- Website area (authenticated) ----------
$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager', 'editor');
require_staff($user);
$userId = (int) $user['id'];

/** One vacancy row as the office sees it, or null. */
function vacancy_load(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT v.id, v.slug, v.title, v.service, v.location, v.hours_text, v.pay_text, v.summary,
                v.requirements, v.checks, v.status, v.form_id, v.owner_id, v.published_at, v.closed_at,
                v.created_at, v.updated_at, f.title AS form_title, f.active AS form_active,
                u.name AS owner_name
         FROM vacancies v
         LEFT JOIN website_forms f ON f.id = v.form_id AND f.deleted_at IS NULL
         LEFT JOIN users u ON u.id = v.owner_id
         WHERE v.id = ? AND v.deleted_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? vacancy_shape($row) : null;
}

function vacancy_shape(array $row): array
{
    foreach (['id', 'form_id', 'owner_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    if (array_key_exists('form_active', $row)) $row['form_active'] = $row['form_active'] === null ? null : (bool) $row['form_active'];
    $row['slug_editable'] = rg_vacancy_slug_editable($row['published_at'] ?? null);
    return $row;
}

/**
 * The editable fields from a request body, validated. Refuses with 422.
 *
 * @return array<string, mixed>
 */
function vacancy_read_input(mysqli $conn, array $user, array $d, ?array $existing): array
{
    $text = static function (string $key, int $max, bool $required, string $label) use ($d): ?string {
        $v = trim((string) ($d[$key] ?? ''));
        if ($v === '') {
            if ($required) fail("$label is required.", 422);
            return null;
        }
        if (mb_strlen($v) > $max) fail("$label is too long (at most $max characters).", 422);
        return $v;
    };

    $title = $text('title', 160, true, 'A title');
    $service = (string) ($d['service'] ?? ($existing['service'] ?? ''));
    if (!in_array($service, VACANCY_SERVICES, true)) fail('Choose the service this role is in.', 422);
    // The service from the body is checked as well as the stored one, so a scoped
    // account cannot move a role into, or create one in, a service it cannot see.
    require_service_scope($user, $service);

    $summary = $text('summary', 4000, true, 'A summary');
    $requirements = $text('requirements', 4000, false, 'Requirements');
    $checks = $text('checks', 2000, false, 'Checks');
    if ($checks === null) $checks = rg_vacancy_default_checks($service);

    $formId = isset($d['form_id']) && $d['form_id'] !== '' && $d['form_id'] !== null ? (int) $d['form_id'] : null;
    if ($formId !== null) {
        $stmt = $conn->prepare('SELECT id FROM website_forms WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->bind_param('i', $formId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) fail('That form is not in the forms directory.', 422);
    }
    $ownerId = isset($d['owner_id']) && $d['owner_id'] !== '' && $d['owner_id'] !== null ? (int) $d['owner_id'] : null;
    if ($ownerId !== null && !is_assignable_staff($conn, $ownerId)) {
        fail('The owner must be a member of staff.', 422);
    }

    // The slug: from the body while it may still change, otherwise kept.
    $slug = $existing['slug'] ?? null;
    if ($existing === null || $existing['slug_editable']) {
        $asked = trim((string) ($d['slug'] ?? ''));
        if ($asked !== '') {
            if (!rg_valid_slug($asked)) fail('The address may use lower-case letters, numbers and single hyphens only.', 422);
            $slug = $asked;
        } elseif ($existing === null) {
            $slug = rg_slugify($title);
        }
    } elseif (isset($d['slug']) && trim((string) $d['slug']) !== '' && $d['slug'] !== $existing['slug']) {
        fail('This role has been published, so its address is fixed.', 422);
    }

    return [
        'slug' => $slug,
        'title' => $title,
        'service' => $service,
        'location' => $text('location', 160, false, 'Location') ?? '',
        'hours_text' => $text('hours_text', 160, false, 'Hours') ?? '',
        'pay_text' => $text('pay_text', 160, false, 'Pay'),
        'summary' => $summary,
        'requirements' => $requirements,
        'checks' => $checks,
        'form_id' => $formId,
        'owner_id' => $ownerId,
    ];
}

/** A slug nobody else holds: the one asked for, or it with -2, -3 ... */
function vacancy_unique_slug(mysqli $conn, string $slug, ?int $exceptId): string
{
    $candidate = $slug;
    for ($n = 2; $n < 100; $n++) {
        $stmt = $conn->prepare('SELECT id FROM vacancies WHERE slug = ? LIMIT 1');
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        if (!$row || ($exceptId !== null && (int) $row[0] === $exceptId)) return $candidate;
        $suffix = '-' . $n;
        $candidate = rtrim(substr($slug, 0, 80 - strlen($suffix)), '-') . $suffix;
    }
    fail('Could not find a free address for this role. Choose one.', 422);
}

const VACANCY_AUDIT_FIELDS = ['slug', 'title', 'service', 'location', 'hours_text', 'pay_text', 'summary',
    'requirements', 'checks', 'form_id', 'owner_id'];

if ($m === 'GET' && $action === 'all') {
    $scope = service_scope($user);
    $sql = 'SELECT v.id, v.slug, v.title, v.service, v.location, v.hours_text, v.pay_text, v.status,
                   v.form_id, v.owner_id, v.published_at, v.closed_at, v.updated_at,
                   f.title AS form_title, f.active AS form_active, u.name AS owner_name
            FROM vacancies v
            LEFT JOIN website_forms f ON f.id = v.form_id AND f.deleted_at IS NULL
            LEFT JOIN users u ON u.id = v.owner_id
            WHERE v.deleted_at IS NULL';
    if ($scope !== null) $sql .= ' AND v.service = ?';
    $sql .= ' ORDER BY FIELD(v.status, "published", "draft", "closed"), v.updated_at DESC, v.id DESC';
    $stmt = $conn->prepare($sql);
    if ($scope !== null) $stmt->bind_param('s', $scope);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = vacancy_shape($row);
    $defaults = [];
    foreach (VACANCY_SERVICES as $s) $defaults[$s] = rg_vacancy_default_checks($s);
    json_out(['vacancies' => $items, 'default_checks' => $defaults]);
}

if ($m === 'GET' && $action === 'get') {
    $v = vacancy_load($conn, (int) ($_GET['id'] ?? 0));
    if (!$v) fail('Vacancy not found.', 404);
    require_service_scope($user, $v['service']);
    json_out(['vacancy' => $v]);
}

if ($m === 'POST' && $action === 'create') {
    $in = vacancy_read_input($conn, $user, body(), null);
    $in['slug'] = vacancy_unique_slug($conn, $in['slug'], null);
    $stmt = $conn->prepare(
        'INSERT INTO vacancies (slug, title, service, location, hours_text, pay_text, summary, requirements,
                                checks, form_id, owner_id, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssssssiii',
        $in['slug'], $in['title'], $in['service'], $in['location'], $in['hours_text'], $in['pay_text'],
        $in['summary'], $in['requirements'], $in['checks'], $in['form_id'], $in['owner_id'], $userId
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    audit($conn, $userId, 'vacancy.create', "#$id {$in['slug']}", 'vacancy', $id, null, $in + ['status' => 'draft']);
    json_out(['ok' => true, 'id' => $id, 'slug' => $in['slug']], 201);
}

if ($m === 'POST' && in_array($action, ['update', 'status', 'delete'], true)) {
    $id = (int) ($_GET['id'] ?? 0);
    $before = $id > 0 ? vacancy_load($conn, $id) : null;
    if (!$before) fail('Vacancy not found.', 404);
    require_service_scope($user, $before['service']);

    if ($action === 'update') {
        $in = vacancy_read_input($conn, $user, body(), $before);
        if ($in['slug'] !== $before['slug']) {
            $taken = vacancy_unique_slug($conn, $in['slug'], $id);
            if ($taken !== $in['slug']) fail('Another role already uses that address.', 422);
        }
        $stmt = $conn->prepare(
            'UPDATE vacancies SET slug = ?, title = ?, service = ?, location = ?, hours_text = ?, pay_text = ?,
                    summary = ?, requirements = ?, checks = ?, form_id = ?, owner_id = ?
             WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->bind_param(
            'sssssssssiii',
            $in['slug'], $in['title'], $in['service'], $in['location'], $in['hours_text'], $in['pay_text'],
            $in['summary'], $in['requirements'], $in['checks'], $in['form_id'], $in['owner_id'], $id
        );
        $stmt->execute();
        [$b, $a] = audit_diff($before, $in, VACANCY_AUDIT_FIELDS);
        if ($a) audit($conn, $userId, 'vacancy.update', "#$id {$in['slug']}", 'vacancy', $id, $b, $a);
        json_out(['ok' => true, 'slug' => $in['slug']]);
    }

    if ($action === 'status') {
        $to = (string) (body()['status'] ?? '');
        if (!in_array($to, ['draft', 'published', 'closed'], true)) fail('Unknown status.', 422);
        if (!rg_vacancy_transition_ok($before['status'], $to)) {
            fail("A {$before['status']} role cannot be moved to $to.", 422);
        }
        if ($to === 'published' && trim((string) $before['summary']) === '') fail('Add a summary before publishing.', 422);
        // published_at is the first publication and is kept on reopening, so the
        // slug stays fixed and the posting date stays honest.
        $stmt = $conn->prepare(
            'UPDATE vacancies
             SET status = ?,
                 published_at = IF(? = "published", COALESCE(published_at, NOW()), published_at),
                 closed_at = IF(? = "closed", NOW(), NULL)
             WHERE id = ?'
        );
        $stmt->bind_param('sssi', $to, $to, $to, $id);
        $stmt->execute();
        audit($conn, $userId, 'vacancy.status', "#$id {$before['slug']} {$before['status']} -> $to", 'vacancy', $id,
            ['status' => $before['status']], ['status' => $to]);
        json_out(['ok' => true, 'status' => $to]);
    }

    // Archive. The row is kept: it is the record of what was advertised.
    $stmt = $conn->prepare('UPDATE vacancies SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    audit($conn, $userId, 'vacancy.archive', "#$id {$before['slug']}", 'vacancy', $id, ['status' => $before['status']], null);
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
