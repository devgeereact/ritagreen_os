<?php
// Test runner for the pure server-side logic.
//
//     php server/tests/run.php
//
// Deliberately dependency-free: no PHPUnit, no Composer, no autoloader. The
// production host cannot install packages, and a test suite that only runs on a
// developer's machine is a test suite that stops being run.
//
// Only pure functions are covered — anything needing a database or config.php is
// out of scope here. That is exactly why pure.php exists: it lets the rules that
// decide who may work, and who may see what, be tested without infrastructure.

declare(strict_types=1);

require_once __DIR__ . '/../pure.php';
require_once __DIR__ . '/../pay-lib.php';

$passed = 0;
$failures = [];
$group = '';

function group(string $name): void
{
    global $group;
    $group = $name;
}

function check(string $what, $actual, $expected): void
{
    global $passed, $failures, $group;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failures[] = sprintf(
        "%s — %s\n      expected: %s\n      actual:   %s",
        $group, $what, var_export($expected, true), var_export($actual, true)
    );
}

// ---------------------------------------------------------------------------
group('rg_days_until');

check('counts forward', rg_days_until('2026-01-11', '2026-01-01'), 10);
check('counts backward', rg_days_until('2025-12-22', '2026-01-01'), -10);
check('today is zero', rg_days_until('2026-01-01', '2026-01-01'), 0);
check('no date is null', rg_days_until(null, '2026-01-01'), null);
check('empty string is null', rg_days_until('', '2026-01-01'), null);
check('unparseable is null', rg_days_until('not-a-date', '2026-01-01'), null);
// A credential expiring "today" has not expired yet. Off by one here would
// stand people down a day early, every time.
check('expiry today is not yet negative', rg_days_until('2026-06-30', '2026-06-30'), 0);

// ---------------------------------------------------------------------------
group('rg_classify');

check('well in date', rg_classify('2026-06-01', '2026-01-01')['status'], 'valid');
check('inside the window', rg_classify('2026-01-20', '2026-01-01')['status'], 'expiring');
check('lapsed', rg_classify('2025-12-31', '2026-01-01')['status'], 'expired');
// 'unknown' must stay distinct from 'expired'. A missing date is a gap in the
// evidence, not a lapsed credential, and the two are chased differently.
check('no date recorded', rg_classify(null, '2026-01-01')['status'], 'unknown');
check('no date carries no day count', rg_classify(null, '2026-01-01')['days'], null);

// Boundaries. These are where an off-by-one becomes a person wrongly stood down,
// or wrongly deployed.
check('30 days out is expiring', rg_classify('2026-01-31', '2026-01-01')['status'], 'expiring');
check('31 days out is still valid', rg_classify('2026-02-01', '2026-01-01')['status'], 'valid');
check('expires today is expiring, not expired', rg_classify('2026-01-01', '2026-01-01')['status'], 'expiring');
check('expired yesterday', rg_classify('2025-12-31', '2026-01-01')['days'], -1);

// ---------------------------------------------------------------------------
group('rg_reminder_tier');

check('far out, nothing to send', rg_reminder_tier(200), null);
check('91 days is still nothing', rg_reminder_tier(91), null);
check('90 days', rg_reminder_tier(90), 'd90');
check('61 days is the 90 band', rg_reminder_tier(61), 'd90');
check('60 days', rg_reminder_tier(60), 'd60');
check('31 days is the 60 band', rg_reminder_tier(31), 'd60');
check('30 days', rg_reminder_tier(30), 'd30');
check('tomorrow is the 30 band', rg_reminder_tier(1), 'd30');
check('today is the 30 band', rg_reminder_tier(0), 'd30');
check('lapsed', rg_reminder_tier(-1), 'expired');
check('long lapsed', rg_reminder_tier(-400), 'expired');
check('no date, no reminder', rg_reminder_tier(null), null);

// ---------------------------------------------------------------------------
group('is_staff');

check('super admin', is_staff(['role' => 'super_admin']), true);
check('admin', is_staff(['role' => 'admin']), true);
check('manager', is_staff(['role' => 'manager']), true);
check('trainer', is_staff(['role' => 'trainer']), true);
check('editor', is_staff(['role' => 'editor']), true);
check('unlinked employee', is_staff(['role' => 'employee']), true);

// The reason this function exists. A worker's login is an ordinary account with
// worker_id set, so role alone would wave it straight through to the office.
check('worker login is not staff', is_staff(['role' => 'employee', 'worker_id' => 7]), false);
check('client role is not staff', is_staff(['role' => 'client']), false);
check('client-linked login is not staff', is_staff(['role' => 'employee', 'client_id' => 3]), false);

// A manager who also holds a worker record must keep office access.
check('manager who is also a worker', is_staff(['role' => 'manager', 'worker_id' => 7]), true);
check('admin who is also a client contact', is_staff(['role' => 'admin', 'client_id' => 3]), true);

// Null and zero links must not read as a link.
check('null worker_id', is_staff(['role' => 'employee', 'worker_id' => null]), true);
check('zero worker_id', is_staff(['role' => 'employee', 'worker_id' => 0]), true);
check('missing role', is_staff([]), true);

// ---------------------------------------------------------------------------
group('linked_worker_id');

check('reads the link', linked_worker_id(['worker_id' => 42]), 42);
check('coerces a string id', linked_worker_id(['worker_id' => '42']), 42);
check('absent is null', linked_worker_id([]), null);
check('null is null', linked_worker_id(['worker_id' => null]), null);
check('zero is null', linked_worker_id(['worker_id' => 0]), null);

// ---------------------------------------------------------------------------
group('rg_field_status');

$start = '2026-03-01 09:00:00';
$end = '2026-03-01 17:00:00';
$at = static fn(string $t): int => (int) strtotime($t);

check('before the shift', rg_field_status([], $start, $end, $at('2026-03-01 08:00:00')), 'scheduled');
check('just started, not clocked in', rg_field_status([], $start, $end, $at('2026-03-01 09:10:00')), 'late');
check('half an hour late exactly is still late', rg_field_status([], $start, $end, $at('2026-03-01 09:30:00')), 'late');
check('past the grace period', rg_field_status([], $start, $end, $at('2026-03-01 09:31:00')), 'no_show');
check('clocked in', rg_field_status(['clock_in'], $start, $end, $at('2026-03-01 10:00:00')), 'on_site');
check('clocked in, past the end', rg_field_status(['clock_in'], $start, $end, $at('2026-03-01 17:20:00')), 'overrunning');
check('within the end grace period', rg_field_status(['clock_in'], $start, $end, $at('2026-03-01 17:10:00')), 'on_site');
check('clocked out', rg_field_status(['clock_in', 'clock_out'], $start, $end, $at('2026-03-01 17:05:00')), 'completed');

// A clocked-out shift is complete however late it ran, and a clocked-in shift
// can never read as a no-show. Order of the checks is what guarantees that.
check('clocked out long after', rg_field_status(['clock_in', 'clock_out'], $start, $end, $at('2026-03-02 04:00:00')), 'completed');
check('clocked in beats no-show', rg_field_status(['clock_in'], $start, $end, $at('2026-03-01 12:00:00')), 'on_site');
check('other events do not mask a no-show', rg_field_status(['note'], $start, $end, $at('2026-03-01 11:00:00')), 'no_show');

// ---------------------------------------------------------------------------
group('pay_valid_date');

check('accepts an ISO date', pay_valid_date('2026-03-01'), true);
check('rejects a UK-format date', pay_valid_date('01/03/2026'), false);
check('rejects empty', pay_valid_date(''), false);
check('rejects a partial date', pay_valid_date('2026-03'), false);
check('rejects trailing text', pay_valid_date("2026-03-01' OR 1=1"), false);

// ---------------------------------------------------------------------------
group('pay_day_premium');

$rules = ['night' => 1.30, 'weekend' => 1.25, 'bank_holiday' => 2.00, 'overtime' => 1.50];
$noHols = [];
$hols = ['2026-12-25' => 'Christmas Day'];

// 2026-03-04 is a Wednesday; 2026-03-07 a Saturday.
check('weekday daytime is standard', pay_day_premium($rules, '2026-03-04', '09:00', $noHols)['key'], 'standard');
check('standard pays flat', pay_day_premium($rules, '2026-03-04', '09:00', $noHols)['mult'], 1.0);
check('weekend', pay_day_premium($rules, '2026-03-07', '09:00', $noHols)['key'], 'weekend');
check('night starts at 20:00', pay_day_premium($rules, '2026-03-04', '20:00', $noHols)['key'], 'night');
check('19:00 is not night', pay_day_premium($rules, '2026-03-04', '19:00', $noHols)['key'], 'standard');
check('early morning is night', pay_day_premium($rules, '2026-03-04', '05:00', $noHols)['key'], 'night');
check('06:00 is not night', pay_day_premium($rules, '2026-03-04', '06:00', $noHols)['key'], 'standard');
check('bank holiday', pay_day_premium($rules, '2026-12-25', '09:00', $hols)['key'], 'bank_holiday');

// Where several premiums apply, the best one wins — the worker is not paid the
// lesser rate because two happened to coincide.
check('bank holiday beats night', pay_day_premium($rules, '2026-12-25', '22:00', $hols)['key'], 'bank_holiday');
check('night beats weekend', pay_day_premium($rules, '2026-03-07', '22:00', $noHols)['key'], 'night');
check('and pays the higher multiplier', pay_day_premium($rules, '2026-03-07', '22:00', $noHols)['mult'], 1.30);

// ---------------------------------------------------------------------------
// The compliance engine. This decides whether a person may legally be sent to
// work, so it gets the most scrutiny in this file.
group('rg_assess_requirement');

$today = '2026-06-01';
$mandatory = ['key' => 'sia', 'label' => 'SIA licence', 'weight' => RG_MANDATORY, 'expires' => true];

check('valid credential', rg_assess_requirement($mandatory, ['expiry' => '2026-12-01'], $today)['state'], 'met');
check('inside the window', rg_assess_requirement($mandatory, ['expiry' => '2026-06-20'], $today)['state'], 'expiring');
check('lapsed', rg_assess_requirement($mandatory, ['expiry' => '2026-05-01'], $today)['state'], 'expired');
check('nothing on file', rg_assess_requirement($mandatory, null, $today)['state'], 'missing');

// A credential that should carry an expiry but does not is incomplete evidence,
// not valid evidence. Treating it as valid is how an undated check passes an audit.
check('present but undated', rg_assess_requirement($mandatory, ['expiry' => null], $today)['state'], 'undated');

// Some requirements genuinely never expire.
$noExpiry = ['key' => 'id', 'label' => 'Identity', 'weight' => RG_MANDATORY, 'expires' => false];
check('non-expiring is met on presence', rg_assess_requirement($noExpiry, [], $today)['state'], 'met');
check('non-expiring still needs a record', rg_assess_requirement($noExpiry, null, $today)['state'], 'missing');

// An unverified document is a claim, not evidence.
$verified = ['key' => 'rtw', 'label' => 'Right to work', 'weight' => RG_MANDATORY, 'expires' => true, 'verify' => true];
check('unverified', rg_assess_requirement($verified, ['expiry' => '2026-12-01'], $today)['state'], 'unverified');
check('verified', rg_assess_requirement($verified, ['expiry' => '2026-12-01', 'verified' => true], $today)['state'], 'met');

// Reasons must be readable by the person who has to act on them.
check('expired reads naturally', rg_assess_requirement($mandatory, ['expiry' => '2026-05-31'], $today)['reason'], 'SIA licence: expired yesterday');
check('expiring today reads naturally', rg_assess_requirement($mandatory, ['expiry' => '2026-06-01'], $today)['reason'], 'SIA licence: expires today');
check('expiring tomorrow reads naturally', rg_assess_requirement($mandatory, ['expiry' => '2026-06-02'], $today)['reason'], 'SIA licence: expires tomorrow');
check('missing reads naturally', rg_assess_requirement($mandatory, null, $today)['reason'], 'SIA licence: no evidence on file');
check('met has no reason', rg_assess_requirement($mandatory, ['expiry' => '2026-12-01'], $today)['reason'], null);

// ---------------------------------------------------------------------------
group('rg_evaluate_eligibility');

$securityRules = [
    ['key' => 'rtw', 'label' => 'Right to work',  'weight' => RG_MANDATORY,   'expires' => true],
    ['key' => 'sia', 'label' => 'SIA licence',    'weight' => RG_MANDATORY,   'expires' => true],
    ['key' => 'id',  'label' => 'Identity',       'weight' => RG_MANDATORY,   'expires' => false],
    ['key' => 'fa',  'label' => 'First aid',      'weight' => RG_RECOMMENDED, 'expires' => true],
    ['key' => 'cm',  'label' => 'Conflict mgmt',  'weight' => RG_OPTIONAL,    'expires' => true],
];
$allGood = [
    'rtw' => ['expiry' => '2027-01-01'],
    'sia' => ['expiry' => '2027-01-01'],
    'id'  => [],
    'fa'  => ['expiry' => '2027-01-01'],
    'cm'  => ['expiry' => '2027-01-01'],
];

$v = rg_evaluate_eligibility($securityRules, $allGood, $today);
check('fully compliant is eligible', $v['eligible'], true);
check('fully compliant status', $v['status'], RG_ELIGIBLE);
check('fully compliant scores 100', $v['score'], 100);
check('fully compliant blocks nothing', count($v['blocking']), 0);

// The case the whole system exists to prevent.
$lapsedSia = $allGood;
$lapsedSia['sia'] = ['expiry' => '2026-05-01'];
$v = rg_evaluate_eligibility($securityRules, $lapsedSia, $today);
check('lapsed SIA blocks deployment', $v['eligible'], false);
check('lapsed SIA status', $v['status'], RG_NOT_ELIGIBLE);
check('lapsed SIA names one blocker', count($v['blocking']), 1);
check('and says which', $v['blocking'][0]['key'], 'sia');
check('and says why', rg_eligibility_reason($v), 'SIA licence: expired 31 days ago');

// A mandatory credential inside its window warns, but the person may still work
// today. Blocking here would stand down someone who is currently valid.
$expiringSia = $allGood;
$expiringSia['sia'] = ['expiry' => '2026-06-15'];
$v = rg_evaluate_eligibility($securityRules, $expiringSia, $today);
check('expiring does not block', $v['eligible'], true);
check('but is flagged', $v['status'], RG_ELIGIBLE_WITH_WARNINGS);
check('with a warning', count($v['warnings']), 1);
check('and still scores full marks', $v['score'], 100);

// A recommended requirement never blocks.
$noFirstAid = $allGood;
unset($noFirstAid['fa']);
$v = rg_evaluate_eligibility($securityRules, $noFirstAid, $today);
check('missing recommended does not block', $v['eligible'], true);
check('missing recommended warns', $v['status'], RG_ELIGIBLE_WITH_WARNINGS);
// mandatory 2+2+2 and recommended 1 = 7 possible; without first aid, 6 earned.
check('and costs score', $v['score'], 86);

// An optional requirement neither blocks nor warns nor scores.
$noOptional = $allGood;
unset($noOptional['cm']);
$v = rg_evaluate_eligibility($securityRules, $noOptional, $today);
check('missing optional is silent', $v['status'], RG_ELIGIBLE);
check('and does not move the score', $v['score'], 100);

// Every blocker is reported, not just the first. Fixing one at a time, with a
// fresh refusal each attempt, is how an office loses a day.
$nothingOnFile = rg_evaluate_eligibility($securityRules, [], $today);
check('reports every blocker', count($nothingOnFile['blocking']), 3);
check('scores zero', $nothingOnFile['score'], 0);
check('is not eligible', $nothingOnFile['eligible'], false);

// The same person, different services: this is why eligibility is per service.
$cleaningRules = [
    ['key' => 'rtw', 'label' => 'Right to work', 'weight' => RG_MANDATORY, 'expires' => true],
    ['key' => 'id',  'label' => 'Identity',      'weight' => RG_MANDATORY, 'expires' => false],
];
$v = rg_evaluate_eligibility($cleaningRules, $lapsedSia, $today);
check('lapsed SIA is irrelevant to cleaning', $v['eligible'], true);

// No rules configured must not read as fully compliant.
$empty = rg_evaluate_eligibility([], [], $today);
check('no rules is not 100%', $empty['score'], 0);
check('no rules blocks nothing', $empty['eligible'], true);

// A refusal always carries a reason.
check('reason is never empty when blocked', rg_eligibility_reason($nothingOnFile) !== '', true);
check('all-clear says so', rg_eligibility_reason(rg_evaluate_eligibility($securityRules, $allGood, $today)), 'All requirements met.');

// Undated mandatory evidence blocks.
$undated = $allGood;
$undated['rtw'] = ['expiry' => null];
$v = rg_evaluate_eligibility($securityRules, $undated, $today);
check('undated mandatory blocks', $v['eligible'], false);
check('and explains itself', $v['blocking'][0]['reason'], 'Right to work: no expiry date recorded');

// ---------------------------------------------------------------------------
group('rg_state_is_unmet');

foreach (['missing', 'expired', 'undated', 'unverified'] as $state) {
    check("$state is unmet", rg_state_is_unmet($state), true);
}
// 'expiring' is still valid today. Treating it as unmet would stand people down early.
check('expiring is not unmet', rg_state_is_unmet('expiring'), false);
check('met is not unmet', rg_state_is_unmet('met'), false);

// ---------------------------------------------------------------------------
// Person matching. A wrong merge joins two people's employment histories, which
// is far harder to undo than an unmerged duplicate — so these lean towards
// review over guessing.
group('rg_normalise_email');

check('lowercases and trims', rg_normalise_email('  John.Smith@Example.COM '), 'john.smith@example.com');
check('absent is null', rg_normalise_email(null), null);
check('empty is null', rg_normalise_email(''), null);
check('not an address is null', rg_normalise_email('john smith'), null);

// ats.php fabricated these for learners with no address. Matching on them would
// collide every such learner.
check('synthetic learner address is null', rg_normalise_email('John Smith@learner.invalid'), null);
check('seed address is null', rg_normalise_email('amara@local.invalid'), null);
check('a real address survives', rg_normalise_email('amara@gmail.com'), 'amara@gmail.com');

// ---------------------------------------------------------------------------
group('rg_phone_key');

// One person, written four ways.
$expected = '07700900123';
check('UK local', rg_phone_key('07700900123'), $expected);
check('spaced', rg_phone_key('07700 900 123'), $expected);
check('E.164', rg_phone_key('+447700900123'), $expected);
check('international prefix', rg_phone_key('00447700900123'), $expected);
check('punctuated', rg_phone_key('(07700) 900-123'), $expected);

check('absent is null', rg_phone_key(null), null);
check('empty is null', rg_phone_key(''), null);
check('no digits is null', rg_phone_key('n/a'), null);
// Too short to identify anybody — an extension, not a person.
check('extension is null', rg_phone_key('4021'), null);
check('six digits is null', rg_phone_key('123456'), null);
check('seven digits is enough', rg_phone_key('1234567'), '1234567');

// ---------------------------------------------------------------------------
group('rg_person_match_key');

check('prefers email', rg_person_match_key(['email' => 'a@b.com', 'phone' => '07700900123'])['basis'], 'email');
check('falls back to phone', rg_person_match_key(['email' => null, 'phone' => '07700900123'])['basis'], 'phone');
check('synthetic email falls through to phone', rg_person_match_key(['email' => 'x@learner.invalid', 'phone' => '07700900123'])['basis'], 'phone');
check('neither is unmatchable', rg_person_match_key(['name' => 'John Smith'])['basis'], 'none');
check('unmatchable has no key', rg_person_match_key(['name' => 'John Smith'])['key'], null);

// Never on name. Every John Smith would become one worker.
check('a name alone gives no key', rg_person_match_key(['name' => 'John Smith', 'email' => '', 'phone' => ''])['key'], null);

// ---------------------------------------------------------------------------
group('rg_group_people');

$rows = [
    ['source' => 'applicant', 'id' => 1, 'name' => 'Amara Chen',  'email' => 'Amara@Example.com', 'phone' => '07700900101'],
    ['source' => 'worker',    'id' => 9, 'name' => 'Amara Chen',  'email' => 'amara@example.com', 'phone' => '+447700900101'],
    ['source' => 'worker',    'id' => 4, 'name' => 'Bola Adeyemi','email' => 'bola@example.com',  'phone' => '07700900102'],
    ['source' => 'learner',   'id' => 3, 'name' => 'Cerys Hughes','email' => 'c@learner.invalid', 'phone' => null],
];
$grouped = rg_group_people($rows);
check('the same person collapses', count($grouped['groups']), 2);
check('and keeps both records', count($grouped['groups']['e:amara@example.com']), 2);
check('a distinct person stays separate', count($grouped['groups']['e:bola@example.com']), 1);
// The synthetic address must not become a group of its own keyed on a fake identity.
check('unmatchable is held back', count($grouped['unmatchable']), 1);
check('and identified', $grouped['unmatchable'][0]['name'], 'Cerys Hughes');

// Matching across email and phone must not silently merge different people.
$viaPhone = rg_group_people([
    ['source' => 'applicant', 'id' => 1, 'name' => 'Dee Okafor', 'email' => null, 'phone' => '07700900200'],
    ['source' => 'worker',    'id' => 2, 'name' => 'Dee Okafor', 'email' => null, 'phone' => '+44 7700 900200'],
]);
check('phone matching collapses one person', count($viaPhone['groups']), 1);

// Explicit links outrank heuristics, and combine with them.
// users.worker_id is a fact somebody entered; matching must not ignore it.
$linked = rg_group_people([
    ['source' => 'workers', 'id' => 9, 'name' => 'Amara Chen', 'email' => 'a@learner.invalid', 'phone' => null, 'link_key' => 'w:9'],
    ['source' => 'users',   'id' => 3, 'name' => 'Amara Chen', 'email' => 'a@learner.invalid', 'phone' => null, 'link_key' => 'w:9'],
]);
check('an explicit link joins records with no usable key', count($linked['groups']), 1);
check('and keeps both', count(reset($linked['groups'])), 2);

// A record joined to one group by its link and to another by its email means
// those two groups are one person. Applying the rules in sequence rather than
// unioning them would leave the worker split from their own login.
$transitive = rg_group_people([
    ['source' => 'workers',    'id' => 9, 'name' => 'Amara Chen', 'email' => null,            'phone' => '07700900101', 'link_key' => 'w:9'],
    ['source' => 'users',      'id' => 3, 'name' => 'Amara Chen', 'email' => 'a@example.com', 'phone' => null,          'link_key' => 'w:9'],
    ['source' => 'applicants', 'id' => 5, 'name' => 'Amara Chen', 'email' => 'a@example.com', 'phone' => null],
]);
check('link and email union into one person', count($transitive['groups']), 1);
check('carrying all three records', count(reset($transitive['groups'])), 3);

// Distinct people must stay distinct even when links are present.
$distinct = rg_group_people([
    ['source' => 'workers', 'id' => 1, 'name' => 'A', 'email' => 'a@example.com', 'phone' => null, 'link_key' => 'w:1'],
    ['source' => 'workers', 'id' => 2, 'name' => 'B', 'email' => 'b@example.com', 'phone' => null, 'link_key' => 'w:2'],
]);
check('separate links stay separate', count($distinct['groups']), 2);

// ---------------------------------------------------------------------------
group('rg_group_is_ambiguous');

check('a single record is never ambiguous', rg_group_is_ambiguous([['name' => 'Amara Chen']]), false);
check('the same name agrees', rg_group_is_ambiguous([['name' => 'Amara Chen'], ['name' => 'amara  chen']]), false);

// A shared landline is not proof of one person. Two names on one number is
// exactly the case that must reach a human rather than be merged.
check('different names is ambiguous', rg_group_is_ambiguous([['name' => 'Amara Chen'], ['name' => 'Bola Adeyemi']]), true);
check('a blank name does not create ambiguity', rg_group_is_ambiguous([['name' => 'Amara Chen'], ['name' => '']]), false);

// ---------------------------------------------------------------------------
group('rg_render_template');

check('substitutes', rg_render_template('Hi {{name}}', ['name' => 'Amara']), 'Hi Amara');
check('substitutes repeatedly', rg_render_template('{{a}} and {{a}}', ['a' => 'x']), 'x and x');
check('tolerates spacing', rg_render_template('Hi {{ name }}', ['name' => 'Amara']), 'Hi Amara');
check('is case-insensitive', rg_render_template('Hi {{Name}}', ['name' => 'Amara']), 'Hi Amara');
check('accepts numbers', rg_render_template('No {{n}}', ['n' => 42]), 'No 42');

// An unknown placeholder is LEFT IN. "Hi ," looks like a mistake to the
// recipient; "Hi {{first_name}}," looks like a mistake to whoever sent it, and
// they are the person who can fix it.
check('unknown placeholder survives', rg_render_template('Hi {{missing}}', []), 'Hi {{missing}}');
check('empty value survives', rg_render_template('Hi {{n}}', ['n' => '']), 'Hi {{n}}');
check('null value survives', rg_render_template('Hi {{n}}', ['n' => null]), 'Hi {{n}}');
check('no placeholders is untouched', rg_render_template('Plain text', ['a' => 'b']), 'Plain text');

check('reports what is unfilled', rg_unfilled_placeholders('Hi {{a}}, see {{b}}'), ['a', 'b']);
check('reports nothing when filled', rg_unfilled_placeholders(rg_render_template('Hi {{a}}', ['a' => 'x'])), []);
check('deduplicates', rg_unfilled_placeholders('{{a}} {{a}}'), ['a']);

// ---------------------------------------------------------------------------
group('rg_first_name');

check('takes the first word', rg_first_name('Amara Chen'), 'Amara');
check('handles a single name', rg_first_name('Amara'), 'Amara');
check('collapses extra spacing', rg_first_name('  Amara   Chen '), 'Amara');
// Never returns an empty greeting.
check('falls back for empty', rg_first_name(''), 'there');
check('falls back for null', rg_first_name(null), 'there');

// ---------------------------------------------------------------------------
$total = $passed + count($failures);
echo "\n";
if ($failures) {
    echo "FAILED — " . count($failures) . " of $total\n\n";
    foreach ($failures as $f) echo "  ✗ $f\n\n";
    exit(1);
}
echo "ok — $total assertions passed\n";
exit(0);
