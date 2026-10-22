<?php
// Careers, the forms directory and company details (migration 039, section S7).
//
// The website reads three things from the office app: published vacancies, the
// Google Form linked to each, and the company's details. Each public read must
// return an explicit list of public fields and nothing a draft or an internal
// note could leak; each write must be refused to anyone outside the Website
// area; a pasted form address must be an approved Google Forms address or
// nothing. Rows this file creates are archived or put back at the end.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin    = $client->login('admin@local.invalid');
$manager  = $client->login('manager@local.invalid');
$scoped   = $client->login('scoped@local.invalid');   // manager, security only
$editor   = $client->login('editor@local.invalid');
$trainer  = $client->login('trainer@local.invalid');
$employee = $client->login('employee@local.invalid');
$worker   = $client->login('amara.login@local.invalid');
$customer = $client->login('priya@local.invalid');
$adminId  = (int) $db->query("SELECT id FROM users WHERE email = 'admin@local.invalid'")->fetch_row()[0];
$editorId = (int) $db->query("SELECT id FROM users WHERE email = 'editor@local.invalid'")->fetch_row()[0];
$workerUserId = (int) $db->query("SELECT id FROM users WHERE email = 'amara.login@local.invalid'")->fetch_row()[0];

$auditCount = static fn (string $action): int => (int) $db->query(
    "SELECT COUNT(*) FROM audit_log WHERE action = '" . $db->real_escape_string($action) . "'"
)->fetch_row()[0];

$formId = '1FAIpQLSdTestOnlyFormId_abcdefghij0123';
$formUrl = "https://docs.google.com/forms/d/e/$formId/viewform";

// ---------------------------------------------------------------------------
Rgt::group('forms directory: an approved Google Forms address or nothing');

$refused = [
    'another host' => "https://example.net/forms/d/e/$formId/viewform",
    'a look-alike host' => "https://docs.google.com.example.net/forms/d/e/$formId/viewform",
    'the google.com redirector' => "https://www.google.com/url?q=$formUrl",
    'javascript:' => 'javascript:alert(document.cookie)',
    'pasted embed HTML' => "<iframe src=\"$formUrl?embedded=true\"></iframe>",
    'plain http' => "http://docs.google.com/forms/d/e/$formId/viewform",
    'the short link' => 'https://forms.gle/AbCdEf123',
];
$before = (int) $db->query('SELECT COUNT(*) FROM website_forms')->fetch_row()[0];
foreach ($refused as $what => $url) {
    Rgt::status("$what is refused with 422",
        $admin->post('/website-forms.php?action=create', ['title' => 'Bad form', 'url' => $url]), 422);
}
Rgt::same('and none of them was stored', (int) $db->query('SELECT COUNT(*) FROM website_forms')->fetch_row()[0], $before);

$integrationId = (int) $db->query("SELECT id FROM integrations WHERE integration_key = 'careers_care'")->fetch_row()[0];
$res = $admin->post('/website-forms.php?action=create', [
    'title' => 'Support worker application', 'purpose' => 'recruitment', 'service' => 'care',
    'url' => "$formUrl?usp=sf_link", 'display' => 'embedded', 'owner_id' => $editorId, 'integration_id' => $integrationId,
]);
Rgt::status('a real form address is accepted', $res, 201);
$websiteFormId = (int) $res->get('id');
$stored = $db->query("SELECT google_form_id, last_reviewed_at FROM website_forms WHERE id = $websiteFormId")->fetch_assoc();
Rgt::same('only the id is stored, not the pasted address', $stored['google_form_id'], $formId);
Rgt::ok('and it counts as reviewed today', $stored['last_reviewed_at'] !== null);
Rgt::same('the creation is audited', $auditCount('website_form.create') >= 1, true);

Rgt::status('an owner who is a worker\'s login is refused',
    $admin->post('/website-forms.php?action=create', ['title' => 'X', 'url' => $formUrl, 'owner_id' => $workerUserId]), 422);

Rgt::group('forms directory: who may manage it');

Rgt::status('an editor lists the directory', $editor->get('/website-forms.php'), 200);
Rgt::denied('a trainer cannot', $trainer->get('/website-forms.php'));
Rgt::denied('an employee cannot', $employee->get('/website-forms.php'));
Rgt::denied("a worker's login cannot", $worker->get('/website-forms.php'));
Rgt::denied('a client portal login cannot', $customer->get('/website-forms.php'));
Rgt::status('nobody signed in is refused', $client->get('/website-forms.php'), 401);
Rgt::denied('a worker\'s login cannot add a form',
    $worker->post('/website-forms.php?action=create', ['title' => 'X', 'url' => $formUrl]));
Rgt::denied('a security-only manager cannot see a care form\'s history',
    $scoped->get("/website-forms.php?action=history&id=$websiteFormId"));
$scopedList = $scoped->get('/website-forms.php');
Rgt::same('and the care form is not in their list',
    in_array($websiteFormId, array_column((array) $scopedList->get('forms', []), 'id'), true), false);

$list = $editor->get('/website-forms.php');
$row = null;
foreach ((array) $list->get('forms', []) as $f) if ($f['id'] === $websiteFormId) $row = $f;
$configured = (bool) $db->query("SELECT secret_hash IS NOT NULL FROM integrations WHERE id = $integrationId")->fetch_row()[0];
Rgt::ok('the list carries the intake health of the linked integration',
    $configured ? in_array($row['intake_status'] ?? null, ['ok', 'silent', 'paused'], true) : ($row['intake_status'] ?? null) === 'not_configured',
    json_encode($row));
Rgt::same('and is not yet due for review', $row['review_due'] ?? null, false);

Rgt::group('forms directory: a changed address keeps its history');

$newId = '1FAIpQLSdSecondTestFormId_zyxwvu98765';
Rgt::status('a bad address on update is refused',
    $admin->post("/website-forms.php?action=update&id=$websiteFormId", ['title' => 'Support worker application', 'url' => 'javascript:x']), 422);
Rgt::same('and the stored id is unchanged',
    $db->query("SELECT google_form_id FROM website_forms WHERE id = $websiteFormId")->fetch_row()[0], $formId);
Rgt::status('a good one is accepted', $admin->post("/website-forms.php?action=update&id=$websiteFormId", [
    'title' => 'Support worker application', 'purpose' => 'recruitment', 'service' => 'care',
    'url' => "https://docs.google.com/forms/d/e/$newId/viewform", 'integration_id' => $integrationId,
]), 200);
$history = $admin->get("/website-forms.php?action=history&id=$websiteFormId");
$change = ((array) $history->get('history', []))[0] ?? [];
Rgt::same('the history holds the previous id', $change['before']['google_form_id'] ?? null, $formId);
Rgt::same('and the new one', $change['after']['google_form_id'] ?? null, $newId);
$formId = $newId;

// ---------------------------------------------------------------------------
Rgt::group('vacancies: who may manage them');

$draft = [
    'title' => 'Support worker, Croydon', 'service' => 'care', 'location' => 'Croydon',
    'hours_text' => 'Days and nights, 36 hours a week', 'pay_text' => '£13.50 an hour',
    'summary' => 'Supporting adults with learning disabilities to live independently.',
    'requirements' => 'Six months of care experience.',
];
Rgt::denied("a worker's login cannot create one", $worker->post('/vacancies.php?action=create', $draft));
Rgt::denied('a client portal login cannot', $customer->post('/vacancies.php?action=create', $draft));
Rgt::denied('a trainer cannot', $trainer->post('/vacancies.php?action=create', $draft));
Rgt::denied('an employee cannot', $employee->post('/vacancies.php?action=create', $draft));
Rgt::status('nobody signed in is refused', $client->post('/vacancies.php?action=create', $draft), 401);
Rgt::denied("a worker's login cannot list drafts", $worker->get('/vacancies.php?action=all'));
Rgt::denied('a security-only manager cannot create a care role', $scoped->post('/vacancies.php?action=create', $draft));

$res = $editor->post('/vacancies.php?action=create', $draft + ['form_id' => $websiteFormId, 'owner_id' => $editorId]);
Rgt::status('an editor creates one', $res, 201);
$vacancyId = (int) $res->get('id');
$slug = (string) $res->get('slug');
Rgt::same('its address comes from the title', $slug, 'support-worker-croydon');
$v = $db->query("SELECT status, checks FROM vacancies WHERE id = $vacancyId")->fetch_assoc();
Rgt::same('it starts as a draft', $v['status'], 'draft');
Rgt::ok('with the care checks prefilled', str_contains((string) $v['checks'], 'Enhanced DBS'));
Rgt::same('the creation is audited',
    (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'vacancy.create' AND entity_id = $vacancyId")->fetch_row()[0], 1);

$res = $editor->post('/vacancies.php?action=create', $draft);
Rgt::same('a second role with the same title gets its own address', $res->get('slug'), 'support-worker-croydon-2');
$secondId = (int) $res->get('id');

Rgt::denied('a security-only manager cannot read the care role', $scoped->get("/vacancies.php?action=get&id=$vacancyId"));
Rgt::denied('nor publish it', $scoped->post("/vacancies.php?action=status&id=$vacancyId", ['status' => 'published']));
Rgt::status('nor create one claiming to be security and then move it to care', (function () use ($scoped, $draft, $db) {
    $r = $scoped->post('/vacancies.php?action=create', ['service' => 'security'] + $draft);
    $id = (int) $r->get('id');
    $moved = $scoped->post("/vacancies.php?action=update&id=$id", ['service' => 'care'] + $draft);
    $db->query("UPDATE vacancies SET deleted_at = NOW() WHERE id = $id");
    return $moved;
})(), 403);

// ---------------------------------------------------------------------------
Rgt::group('vacancies: drafts are never public');

Rgt::same('the public list does not include the draft',
    in_array($slug, array_column((array) $client->get('/vacancies.php')->get('vacancies', []), 'slug'), true), false);
Rgt::status('and the draft\'s page is not found', $client->get("/vacancies.php?slug=$slug"), 404);
$res = $client->get("/vacancies.php?slug=$slug");
Rgt::ok('without naming it', !str_contains($res->body, 'Croydon'));

Rgt::group('vacancies: publish, read publicly, close');

Rgt::status('a draft cannot jump to an unknown state',
    $editor->post("/vacancies.php?action=status&id=$vacancyId", ['status' => 'archived']), 422);
Rgt::status('the editor publishes it', $editor->post("/vacancies.php?action=status&id=$vacancyId", ['status' => 'published']), 200);
$list = $client->get('/vacancies.php');
Rgt::status('the public list answers', $list, 200);
Rgt::ok('with a cache header of five minutes', str_contains((string) ($list->headers['cache-control'] ?? ''), 'max-age=300'));
$pub = null;
foreach ((array) $list->get('vacancies', []) as $row) if ($row['slug'] === $slug) $pub = $row;
Rgt::ok('and now lists the role', $pub !== null);
Rgt::same('with only the public fields', array_keys($pub ?? []),
    ['slug', 'title', 'service', 'location', 'hours_text', 'pay_text', 'summary', 'published_at']);

$page = $client->get("/vacancies.php?slug=$slug");
Rgt::status('the role page answers', $page, 200);
$fields = array_keys((array) $page->get('vacancy', []));
foreach (['id', 'owner_id', 'owner_name', 'form_id', 'created_by', 'status', 'deleted_at', 'integration_id'] as $internal) {
    Rgt::same("and does not carry $internal", in_array($internal, $fields, true), false);
}
Rgt::same('it applies through the linked form', $page->get('vacancy.apply'), 'form');
Rgt::same('identified by the form id only', $page->get('vacancy.form'), ['id' => $formId, 'display' => 'embedded']);

Rgt::status('a published role cannot go back to draft',
    $editor->post("/vacancies.php?action=status&id=$vacancyId", ['status' => 'draft']), 422);
Rgt::status('its address is fixed once published',
    $editor->post("/vacancies.php?action=update&id=$vacancyId", ['slug' => 'something-else'] + $draft), 422);

// A switched-off form closes applications for the role rather than falling back
// to a second intake path.
$db->query("UPDATE website_forms SET active = 0 WHERE id = $websiteFormId");
Rgt::same('a switched-off form closes applications', $client->get("/vacancies.php?slug=$slug")->get('vacancy.apply'), 'closed');
Rgt::same('and sends no form id', $client->get("/vacancies.php?slug=$slug")->get('vacancy.form'), null);
$db->query("UPDATE website_forms SET active = 1 WHERE id = $websiteFormId");

// A role with no form takes the native short form.
$editor->post("/vacancies.php?action=status&id=$secondId", ['status' => 'published']);
Rgt::same('a role with no form uses the native form',
    $client->get('/vacancies.php?slug=support-worker-croydon-2')->get('vacancy.apply'), 'native');

Rgt::status('the editor closes the role', $editor->post("/vacancies.php?action=status&id=$vacancyId", ['status' => 'closed']), 200);
Rgt::same('it leaves the public list',
    in_array($slug, array_column((array) $client->get('/vacancies.php')->get('vacancies', []), 'slug'), true), false);
$gone = $client->get("/vacancies.php?slug=$slug");
Rgt::status('and its page says it has closed', $gone, 410);
Rgt::same('naming the role so the page can say which', $gone->get('title'), 'Support worker, Croydon');
Rgt::same('every move was audited',
    (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'vacancy.status' AND entity_id = $vacancyId")->fetch_row()[0], 2);

$sitemap = $client->get('/sitemap.php');
Rgt::status('the live sitemap answers', $sitemap, 200);
Rgt::ok('listing the open role', str_contains($sitemap->body, '/careers/support-worker-croydon-2'));
Rgt::ok('and not the closed one', !str_contains($sitemap->body, "/careers/$slug<"));

// ---------------------------------------------------------------------------
Rgt::group('company details: public fields only');

$res = $client->get('/company.php');
Rgt::status('anyone may read them', $res, 200);
Rgt::same('seeded from the code', $res->get('company.phone'), '0203 105 2218');
Rgt::same('with exactly the public fields', array_keys((array) $res->get('company', [])), [
    'trading_name', 'legal_name', 'company_no', 'phone', 'urgent_phone', 'email', 'office_hours', 'area',
    'registered_address', 'instagram_url', 'facebook_url', 'linkedin_url', 'tiktok_url', 'whatsapp_url',
]);
Rgt::ok('and a five-minute cache', str_contains((string) ($res->headers['cache-control'] ?? ''), 'max-age=300'));

Rgt::group('company details: only an owner may change them');

Rgt::denied('a manager cannot', $manager->post('/company.php?action=update', ['phone' => '020 7946 0000']));
Rgt::denied('an editor cannot', $editor->post('/company.php?action=update', ['phone' => '020 7946 0000']));
Rgt::denied("a worker's login cannot", $worker->post('/company.php?action=update', ['phone' => '020 7946 0000']));
Rgt::status('nobody signed in is refused', $client->post('/company.php?action=update', ['phone' => '020 7946 0000']), 401);
Rgt::same('and the phone is unchanged', $client->get('/company.php')->get('company.phone'), '0203 105 2218');

Rgt::status('a phone nobody could ring is refused', $admin->post('/company.php?action=update', ['phone' => 'call us']), 422);
Rgt::status('a social link on the wrong network is refused',
    $admin->post('/company.php?action=update', ['instagram_url' => 'https://evil.example.net/ritagreen']), 422);
Rgt::status('a javascript: social link is refused',
    $admin->post('/company.php?action=update', ['tiktok_url' => 'javascript:alert(1)']), 422);

$auditBefore = $auditCount('company.update');
$res = $admin->post('/company.php?action=update', [
    'phone' => '020 7946 0018', 'tiktok_url' => 'https://www.tiktok.com/@ritagreenservices',
]);
Rgt::status('an admin changes the phone and adds TikTok', $res, 200);
Rgt::same('the public read shows it', $client->get('/company.php')->get('company.phone'), '020 7946 0018');
Rgt::same('fields left out are kept', $client->get('/company.php')->get('company.email'), 'hello@ritagreenservices.com');
$audit = $db->query("SELECT user_id, before_json, after_json FROM audit_log WHERE action = 'company.update' ORDER BY id DESC LIMIT 1")->fetch_assoc();
Rgt::same('one audit entry', $auditCount('company.update'), $auditBefore + 1);
Rgt::same('by the admin', (int) $audit['user_id'], $adminId);
Rgt::same('holding the old phone', json_decode($audit['before_json'], true)['phone'] ?? null, '0203 105 2218');
Rgt::same('and only what changed', array_keys(json_decode($audit['after_json'], true)), ['phone', 'tiktok_url']);

// ---------------------------------------------------------------------------
Rgt::group('form intake: an owner sets the secret, once');

$intId = (int) $db->query("SELECT id FROM integrations WHERE integration_key = 'careers_security'")->fetch_row()[0];
Rgt::denied('a manager cannot set a secret', $manager->post("/forms-intake.php?action=set_secret&id=$intId", []));
Rgt::denied('nor an editor', $editor->post("/forms-intake.php?action=set_secret&id=$intId", []));
$res = $admin->post("/forms-intake.php?action=set_secret&id=$intId", []);
Rgt::status('an admin can', $res, 200);
$secret = (string) $res->get('secret');
Rgt::ok('and is shown a long random secret', strlen($secret) >= 40);
Rgt::same('only its hash is stored',
    $db->query("SELECT secret_hash FROM integrations WHERE id = $intId")->fetch_row()[0], hash('sha256', $secret));
$auditRow = $db->query("SELECT detail, before_json, after_json FROM audit_log WHERE action = 'intake.secret_set' ORDER BY id DESC LIMIT 1")->fetch_assoc();
Rgt::ok('the change is audited without the secret', !str_contains(implode('|', $auditRow), $secret));
$db->query('DELETE FROM rate_limits');
$ok = $client->withHeader('X-RitaGreen-Secret', $secret)->post('/forms-intake.php?key=careers_security', [
    'response_id' => 's07-secret-check',
    'answers' => ['Full name' => 'Secret Check', 'Email address' => 'secret.check@example.test'],
]);
Rgt::status('and the Apps Script can post with it', $ok, 201);

// ---------------------------------------------------------------------------
// Put back what later case files might read.
$db->query("UPDATE company_settings SET phone = '0203 105 2218', tiktok_url = '' WHERE id = 1");
$db->query("UPDATE integrations SET secret_hash = NULL WHERE id = $intId");
$db->query("UPDATE vacancies SET deleted_at = NOW() WHERE id IN ($vacancyId, $secondId)");
