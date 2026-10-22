<?php
// Follow-ups found by earlier sections and closed in S10: where a check's
// evidence came from, "&" in a certificate label, the rest-gap setting, the
// deletion-request screen's data, the inbox badge with no mailbox, and the
// training forms on /training.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$manager = $client->login('manager@local.invalid');
$editor = $client->login('editor@local.invalid');
$amaraApp = $client->login('amara.login@local.invalid');

$one = static fn(mysqli $db, string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$amaraPerson = (int) $one($db, "SELECT person_id FROM workers WHERE name = 'Amara Chen' AND deleted_at IS NULL");
$amaraWorker = (int) $one($db, "SELECT id FROM workers WHERE name = 'Amara Chen' AND deleted_at IS NULL");

/** A key's value, or 'absent' -- `??` would turn a real null into the default. */
$field = static fn(array $a, string $k): mixed => array_key_exists($k, $a) ? $a[$k] : 'absent';

/** The moving and handling item from Amara's care checks. */
$movingItem = static function () use ($admin, $amaraPerson): array {
    $res = $admin->get("/people.php?id=$amaraPerson");
    foreach ($res->get('checks.assessments', []) as $a) {
        foreach ($a['items'] as $item) if ($item['key'] === 'moving_handling') return $item;
    }
    return [];
};

// ---------------------------------------------------------------------------
Rgt::group('S10: a check valid only from a certificate row says so');

$item = $movingItem();
Rgt::ok('the seeded "Moving and handling" certificate now matches its requirement', $item !== [], 'no moving_handling item');
Rgt::same('it counts', $item['state'] ?? null, 'met');
Rgt::same('nobody verified it', $field($item, 'verified_by'), null);
Rgt::same('and the profile says where it came from', $item['evidence_origin'] ?? null, 'certificate');

$db->query("UPDATE certifications SET type = 'Moving & Handling' WHERE worker_id = $amaraWorker AND type = 'Moving and handling'");
Rgt::same('"&" is read as "and"', $movingItem()['evidence_origin'] ?? null, 'certificate');
$db->query("UPDATE certifications SET type = 'Moving handling' WHERE worker_id = $amaraWorker AND type = 'Moving & Handling'");
Rgt::same('but nothing looser matches', $field($movingItem(), 'evidence_origin'), null);
$db->query("UPDATE certifications SET type = 'Moving and handling' WHERE worker_id = $amaraWorker AND type = 'Moving handling'");

// The label is not a precedence: a rejection still outranks the certificate.
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, reviewed_at, rejection_reason)
     VALUES ($amaraPerson, 'moving_handling', 'document', 'rejected', NOW(), 'Certificate is for a different person')"
);
$rejectedId = (int) $db->insert_id;
$item = $movingItem();
Rgt::ok('a rejection is not revived by the certificate row', ($item['state'] ?? null) !== 'met', json_encode($item));
Rgt::same('and no origin is claimed for evidence that does not count', $field($item, 'evidence_origin'), null);
$db->query("DELETE FROM verifications WHERE id = $rejectedId");

// A verification carries its verifier, and its origin says so.
$adminId = (int) $one($db, "SELECT id FROM users WHERE email = 'admin@local.invalid'");
$db->query(
    "INSERT INTO verifications (person_id, requirement_key, subject_type, status, verified_at, verified_by, expires_on)
     VALUES ($amaraPerson, 'moving_handling', 'document', 'verified', NOW(), $adminId, '2030-01-01')"
);
$verifiedId = (int) $db->insert_id;
$item = $movingItem();
Rgt::same('a verified check names its verifier', $item['verified_by'] ?? null, (string) $one($db, "SELECT name FROM users WHERE id = $adminId"));
Rgt::same('and comes from the verification', $item['evidence_origin'] ?? null, 'verification');
$db->query("DELETE FROM verifications WHERE id = $verifiedId");

// ---------------------------------------------------------------------------
Rgt::group('S10: the minimum rest between shifts');

Rgt::same('the office can read it', $manager->get('/scheduling.php?action=settings')->get('rest_gap_min_hours'), 11);
Rgt::denied('a manager cannot change it', $manager->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 8]));
Rgt::status('more than a day is refused', $admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 25]), 400);
Rgt::status('a fraction is refused', $admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => '10.5']), 400);
$res = $admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 12]);
Rgt::status('an owner sets it', $res, 200);
Rgt::same('to twelve', $res->get('rest_gap_min_hours'), 12);
Rgt::same('and it is audited', (int) $one($db, "SELECT COUNT(*) FROM audit_log WHERE action = 'settings.rest_gap'") > 0, true);
$admin->post('/scheduling.php?action=settings', ['rest_gap_min_hours' => 11]);

// ---------------------------------------------------------------------------
Rgt::group('S10: deletion requests, four eyes');

// A second owner, so somebody other than the requester can decide.
$hash = $db->real_escape_string((string) $one($db, "SELECT password_hash FROM users WHERE email = 'admin@local.invalid'"));
$db->query("INSERT INTO people (full_name, email, match_key) VALUES ('Second Owner', 'owner2@local.invalid', 'e:owner2@local.invalid')");
$ownerPerson = (int) $db->insert_id;
$db->query("INSERT INTO users (name, email, password_hash, role, person_id)
            VALUES ('Second Owner', 'owner2@local.invalid', '$hash', 'super_admin', $ownerPerson)");
$owner2 = $client->login('owner2@local.invalid');

// A document of its own, so archiving it touches nobody's evidence.
$db->query("INSERT INTO files (original_name, stored_name, mime, size_bytes, category) VALUES ('old-note.txt', '" . bin2hex(random_bytes(16)) . "', 'text/plain', 4, 'other')");
$fileId = (int) $db->insert_id;
$res = $admin->post('/governance.php?action=request_deletion', [
    'entity_type' => 'file', 'entity_id' => $fileId, 'disposition' => 'archive', 'reason' => 'Uploaded to the wrong person by mistake',
]);
Rgt::status('an owner asks for a document to be archived', $res, 201);
$reqId = (int) $res->get('id');

$mine = array_values(array_filter($admin->get('/governance.php?action=deletions&status=requested')->get('requests', []),
    static fn($r) => $r['id'] === $reqId))[0] ?? [];
Rgt::same('the screen knows it is theirs', $mine['is_mine'] ?? null, true);
Rgt::status('and the endpoint refuses their own decision', $admin->post("/governance.php?action=decide_deletion&id=$reqId", ['decision' => 'approved']), 409);

$list = $owner2->get('/governance.php?action=deletions&status=requested');
$theirs = array_values(array_filter($list->get('requests', []), static fn($r) => $r['id'] === $reqId))[0] ?? [];
Rgt::same('another owner sees it as somebody else\'s', $theirs['is_mine'] ?? null, false);
Rgt::same('and may decide', $list->get('can_decide'), true);
Rgt::ok('the requester\'s id is not sent', !array_key_exists('requested_by_id', $theirs));
Rgt::same('an editor may read the list but not decide', $editor->get('/governance.php?action=deletions')->get('can_decide'), false);
Rgt::denied('nor decide through the endpoint', $editor->post("/governance.php?action=decide_deletion&id=$reqId", ['decision' => 'rejected', 'note' => 'x']));
Rgt::denied('a worker login reads nothing', $amaraApp->get('/governance.php?action=deletions'));

$res = $owner2->post("/governance.php?action=decide_deletion&id=$reqId", ['decision' => 'approved', 'note' => 'Agreed']);
Rgt::status('the second owner approves', $res, 200);
Rgt::ok('the document is archived, not deleted',
    $one($db, "SELECT deleted_at FROM files WHERE id = $fileId") !== null);
$db->query("UPDATE files SET deleted_at = NULL WHERE id = $fileId");

$res = $manager->post('/governance.php?action=request_deletion', [
    'entity_type' => 'file', 'entity_id' => $fileId, 'disposition' => 'archive', 'reason' => 'A second request, to be declined',
]);
Rgt::status('a manager may ask', $res, 201);
$today = $admin->get('/action-centre.php');
Rgt::ok('Today links the item to Settings, Data requests',
    str_contains(str_replace('\\/', '/', $today->body), '/app/settings?tab=requests'), substr($today->body, 0, 400));
Rgt::status('it is declined with a note', $admin->post('/governance.php?action=decide_deletion&id=' . (int) $res->get('id'),
    ['decision' => 'rejected', 'note' => 'Archive is not needed']), 200);

// ---------------------------------------------------------------------------
Rgt::group('S10: the inbox badge with no mailbox set up');

$res = $admin->get('/inbox.php?action=unread');
Rgt::status('the badge read answers, not a 503', $res, 200);
Rgt::same('nothing unread', $res->get('unread'), 0);
Rgt::same('and says the mailbox is not configured', $res->get('configured'), false);
Rgt::status('opening the inbox itself still says why it cannot', $admin->get('/inbox.php?action=list'), 503);
Rgt::denied('the badge read is still office only', $editor->get('/inbox.php?action=unread'));

// ---------------------------------------------------------------------------
Rgt::group('S10: training forms on /training');

$db->query("INSERT INTO website_forms (title, purpose, service, google_form_id, display, active) VALUES
    ('First aid course booking', 'training', 'academy', '1FAIpQLSdTrainingFormIdForTests0123456789abcd', 'embedded', 1),
    ('Retired training form',    'training', 'academy', '1FAIpQLSdRetiredTrainingFormIdForTests012345', 'embedded', 0),
    ('Care assistant application', 'recruitment', 'care', '1FAIpQLSdRecruitmentFormIdForTests0123456789', 'embedded', 1),
    ('Broken id',                'training', 'academy', 'javascript:alert(1)', 'link', 1)");
$public = $client->withToken(null);
$res = $public->get('/website-forms.php?action=public&purpose=training');
Rgt::status('anyone can read the training forms', $res, 200);
$titles = array_column($res->get('forms', []), 'title');
Rgt::same('only the active, valid training form', $titles, ['First aid course booking']);
Rgt::same('with its id and display', $res->get('forms.0.form'), ['id' => '1FAIpQLSdTrainingFormIdForTests0123456789abcd', 'display' => 'embedded']);
Rgt::ok('and nothing internal', !array_diff(array_keys($res->get('forms.0', [])), ['title', 'service', 'form']));
Rgt::status('no other purpose is public', $public->get('/website-forms.php?action=public&purpose=recruitment'), 404);
Rgt::denied('the directory itself still needs a login', $public->get('/website-forms.php'));

// ---------------------------------------------------------------------------
Rgt::group('S10: system health reads the recorded restore test');

$restoreCheck = static function () use ($admin): array {
    foreach ($admin->get('/system-health.php')->get('checks', []) as $c) if ($c['key'] === 'restore_test') return $c;
    return [];
};
$db->query("DELETE FROM cron_runs WHERE job = 'restore_test'");
$c = $restoreCheck();
Rgt::same('never recorded is a warning, not fine', $c['state'] ?? null, 'warn');
Rgt::ok('and says how to record one', str_contains((string) ($c['detail'] ?? ''), 'backup-and-restore.md'), json_encode($c));
$db->query("INSERT INTO cron_runs (job, last_started_at, last_success_at, last_detail, runs)
            VALUES ('restore_test', UTC_TIMESTAMP(), UTC_TIMESTAMP(), '88 tables, 1240 rows, 5 documents into x', 1)");
$c = $restoreCheck();
Rgt::same('a test passed today is ok', $c['state'] ?? null, 'ok');
Rgt::ok('with its date', str_contains((string) ($c['detail'] ?? ''), gmdate('Y-m-d')), json_encode($c));
$db->query("UPDATE cron_runs SET last_success_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 200 DAY) WHERE job = 'restore_test'");
$c = $restoreCheck();
Rgt::same('one 200 days old is a warning again', $c['state'] ?? null, 'warn');
Rgt::ok('saying it is overdue', str_contains((string) ($c['detail'] ?? ''), 'more than 90 days'), json_encode($c));
$db->query("DELETE FROM cron_runs WHERE job = 'restore_test'");
