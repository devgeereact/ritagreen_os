<?php
// Feature switches (migration 036): the owner decides which optional areas are
// in use, and a switched-off area is refused by the server, not just hidden.
//
// Every switch this file changes is put back at the end, so later case files
// see the seeded state.

/** @var RgtClient $client */
/** @var mysqli $db */

$admin    = $client->login('admin@local.invalid');
$manager  = $client->login('manager@local.invalid');
$editor   = $client->login('editor@local.invalid');
$worker   = $client->login('amara.login@local.invalid');
$customer = $client->login('priya@local.invalid');
$adminId  = (int) $db->query("SELECT id FROM users WHERE email = 'admin@local.invalid'")->fetch_row()[0];

$stored = static fn (string $name) => $db->query(
    "SELECT enabled, updated_by FROM feature_switches WHERE name = '" . $db->real_escape_string($name) . "'"
)->fetch_assoc();

Rgt::group('feature switches — seeded as the plan says');

Rgt::same('the five switches are seeded',
    (int) $db->query('SELECT COUNT(*) FROM feature_switches')->fetch_row()[0], 5);
Rgt::same('finance starts on', (int) $stored('finance')['enabled'], 1);
Rgt::same('online payments start on', (int) $stored('online_payments')['enabled'], 1);
Rgt::same('SMS and WhatsApp start on', (int) $stored('sms_whatsapp')['enabled'], 1);
Rgt::same('the inbox starts on', (int) $stored('inbox')['enabled'], 1);
Rgt::same('the AI assistant starts off', (int) $stored('ai_assistant')['enabled'], 0);

Rgt::group('feature switches — any staff account can read them');

$res = $manager->get('/features.php');
Rgt::status('a manager reads the switches', $res, 200);
Rgt::same('and sees finance on', $res->get('features.finance'), true);
Rgt::same('and the AI assistant off', $res->get('features.ai_assistant'), false);
Rgt::same('with a row per switch', count((array) $res->get('switches', [])), 5);
Rgt::status('an editor reads them too', $editor->get('/features.php'), 200);

Rgt::group('feature switches — not for worker, client or anonymous logins');

Rgt::denied("a worker's login cannot read them", $worker->get('/features.php'));
Rgt::denied('a client portal login cannot read them', $customer->get('/features.php'));
Rgt::status('nobody signed in is refused', $client->get('/features.php'), 401);

Rgt::group('feature switches — only an owner may change one');

$res = $manager->post('/features.php', ['name' => 'inbox', 'enabled' => false]);
Rgt::denied('a manager cannot switch the inbox off', $res);
Rgt::same('and it stays on', (int) $stored('inbox')['enabled'], 1);
Rgt::denied('nor can an editor', $editor->post('/features.php', ['name' => 'ai_assistant', 'enabled' => true]));
Rgt::same('and the AI assistant stays off', (int) $stored('ai_assistant')['enabled'], 0);
Rgt::denied("nor a worker's login", $worker->post('/features.php', ['name' => 'finance', 'enabled' => false]));

Rgt::group('feature switches — only real switches, only true or false');

Rgt::status('an unknown switch is refused', $admin->post('/features.php', ['name' => 'teleport', 'enabled' => true]), 422);
Rgt::same('and nothing is stored for it',
    (int) $db->query("SELECT COUNT(*) FROM feature_switches WHERE name = 'teleport'")->fetch_row()[0], 0);
Rgt::status('a value that is not a boolean is refused',
    $admin->post('/features.php', ['name' => 'inbox', 'enabled' => 'off']), 422);
Rgt::same('and the inbox is untouched', (int) $stored('inbox')['enabled'], 1);

Rgt::group('feature switches — an owner change is stored and audited');

$auditBefore = (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'feature.switch'")->fetch_row()[0];
$res = $admin->post('/features.php', ['name' => 'inbox', 'enabled' => false]);
Rgt::status('an admin switches the inbox off', $res, 200);
Rgt::same('the response says so', $res->get('features.inbox'), false);
$row = $stored('inbox');
Rgt::same('the row is off', (int) $row['enabled'], 0);
Rgt::same('and names who switched it', (int) $row['updated_by'], $adminId);
$audit = $db->query(
    "SELECT user_id, detail, before_json, after_json FROM audit_log
      WHERE action = 'feature.switch' ORDER BY id DESC LIMIT 1"
)->fetch_assoc();
Rgt::same('one audit entry was written',
    (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'feature.switch'")->fetch_row()[0], $auditBefore + 1);
Rgt::same('by the admin', (int) $audit['user_id'], $adminId);
Rgt::same('recording the state before', json_decode((string) $audit['before_json'], true)['enabled'] ?? null, true);
Rgt::same('and after', json_decode((string) $audit['after_json'], true)['enabled'] ?? null, false);

$res = $admin->post('/features.php', ['name' => 'inbox', 'enabled' => false]);
Rgt::status('setting it to what it already is succeeds', $res, 200);
Rgt::same('without a second audit entry',
    (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'feature.switch'")->fetch_row()[0], $auditBefore + 1);

Rgt::group('feature switches — a switched-off area is refused by the server');

$res = $admin->get('/inbox.php?action=unread');
Rgt::status('the inbox refuses even an admin', $res, 403);
Rgt::ok('and says it is switched off', str_contains($res->error(), 'switched off'), $res->error());

// Seeded off. Nothing here reaches OpenRouter: the switch is checked first.
$res = $admin->post('/ai.php', ['prompt' => 'Say hello.']);
Rgt::status('the AI assistant refuses while off', $res, 403);
Rgt::ok('and says it is switched off', str_contains($res->error(), 'switched off'), $res->error());

$admin->post('/features.php', ['name' => 'online_payments', 'enabled' => false]);
$invoiceId = (int) ($db->query('SELECT id FROM invoices ORDER BY id LIMIT 1')->fetch_row()[0] ?? 0);
$res = $admin->post('/payments.php?action=checkout', ['invoice_id' => $invoiceId]);
Rgt::status('a card checkout is refused while online payments are off', $res, 403);
Rgt::ok('and says so', str_contains($res->error(), 'switched off'), $res->error());
Rgt::status('so is a new pay link', $admin->post("/payments.php?action=paylink&id=$invoiceId", []), 403);
Rgt::status('so is a public pay link', $client->get('/payments.php?action=public_checkout&token=' . str_repeat('a', 40)), 403);
Rgt::status('so is a Direct Debit set-up', $admin->post('/gocardless.php?action=setup', ['client_id' => 1]), 403);
Rgt::status('so is a Direct Debit collection', $admin->post('/gocardless.php?action=charge', ['invoice_id' => $invoiceId]), 403);
// The webhooks are not behind the switch: a payment already in flight must
// still settle. Here they stop at "not configured", which is the point: they
// got past the switch.
$res = $client->post('/payments.php?action=webhook', []);
Rgt::ok('the Stripe webhook is not refused by the switch', $res->status !== 403, "HTTP {$res->status} {$res->body}");
$res = $client->post('/gocardless.php?action=webhook', []);
Rgt::ok('nor is the GoCardless webhook', $res->status !== 403, "HTTP {$res->status} {$res->body}");

Rgt::group('feature switches — SMS and WhatsApp are skipped, and logged, while off');

// send_sms() is reached through a CLI probe that loads lib.php against the same
// test database, rather than through a broadcast, which needs an eligible,
// available worker and would test the gate's neighbours as much as the gate.
$probe = sys_get_temp_dir() . '/rgt_sms_probe_' . getmypid() . '.php';
file_put_contents($probe, '<?php require ' . var_export(dirname(__DIR__, 3) . '/lib.php', true) . ';'
    . ' send_sms($conn, "07700900123", "feature switch probe sms");'
    . ' send_whatsapp($conn, "07700900123", "feature switch probe whatsapp");');
$runProbe = static fn () => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
$lastMeta = static fn (string $body) => $db->query(
    "SELECT status, meta FROM sms_log WHERE body = '" . $db->real_escape_string($body) . "' ORDER BY id DESC LIMIT 1"
)->fetch_assoc();

$admin->post('/features.php', ['name' => 'sms_whatsapp', 'enabled' => false]);
$out = $runProbe();
$sms = $lastMeta('feature switch probe sms');
Rgt::ok('the SMS attempt was logged', $sms !== null, $out);
Rgt::same('as skipped', $sms['status'] ?? null, 'skipped');
Rgt::same('because the switch is off', $sms['meta'] ?? null, 'sms switched off in settings');
$wa = $lastMeta('feature switch probe whatsapp');
Rgt::same('the WhatsApp attempt likewise', $wa['meta'] ?? null, 'whatsapp switched off in settings');

$admin->post('/features.php', ['name' => 'sms_whatsapp', 'enabled' => true]);
$runProbe();
// Switched on, the next thing to stop it is the missing Twilio configuration,
// which the test config leaves empty on purpose: nothing is ever sent.
Rgt::same('switched back on, the switch no longer stops it',
    $lastMeta('feature switch probe sms')['meta'] ?? null, 'sms disabled');
@unlink($probe);

Rgt::group('feature switches — switching back on restores the area');

$admin->post('/features.php', ['name' => 'inbox', 'enabled' => true]);
$res = $admin->get('/inbox.php?action=unread');
// The test config has no IMAP server, so the next answer is "not configured"
// (or no imap extension): either way, past the switch.
Rgt::ok('the inbox is no longer refused by the switch', $res->status !== 403, "HTTP {$res->status} {$res->body}");

// Back to the seeded state for the case files after this one.
$admin->post('/features.php', ['name' => 'online_payments', 'enabled' => true]);
Rgt::same('every switch is back as seeded',
    $admin->get('/features.php')->get('features'),
    ['finance' => true, 'online_payments' => true, 'sms_whatsapp' => true, 'inbox' => true, 'ai_assistant' => false]);
