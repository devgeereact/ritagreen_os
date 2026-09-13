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
// Source-level authorisation check. Needs no database and no config.php, so it
// runs in the same suite. See the file for the invariant it protects.
require_once __DIR__ . '/guards.php';

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
group('rg_shift_minutes');

check('a plain day shift', rg_shift_minutes('2026-03-02', '09:00', '17:00'), 480);
check('minutes are not lost', rg_shift_minutes('2026-03-02', '09:15', '17:45'), 510);

// The defect this exists to catch: an end at or before the start was rejected
// as "the end time must be after the start time", so a waking night or a night
// guard -- the shifts this business actually runs -- could not be recorded at
// all, while the shifts table had stored overnight spans all along.
check('crosses midnight', rg_shift_minutes('2026-03-02', '22:00', '06:00'), 480);
check('a twelve-hour waking night', rg_shift_minutes('2026-03-02', '20:00', '08:00'), 720);
check('ends exactly at midnight', rg_shift_minutes('2026-03-02', '20:00', '00:00'), 240);
check('starts at midnight', rg_shift_minutes('2026-03-02', '00:00', '08:00'), 480);

// Equal times are ambiguous -- a zero-length entry or a full 24 hours -- so the
// function refuses to guess and the caller rejects it.
check('equal times are zero', rg_shift_minutes('2026-03-02', '09:00', '09:00'), 0);
check('unparseable is zero', rg_shift_minutes('2026-03-02', 'not-a-time', '17:00'), 0);
check('a UTC night is not shortened by BST', rg_shift_minutes('2026-03-28', '23:00', '07:00'), 480);

// Deliberately UTC, and pinned here so it cannot drift with the host's
// date.timezone: the night the clocks go forward is still counted as eight
// hours. The whole system stores UTC, so this is consistent rather than
// correct-in-wall-clock-terms, and that trade is documented on the function.

// ---------------------------------------------------------------------------
group('pay_valid_date');

check('accepts an ISO date', pay_valid_date('2026-03-01'), true);
check('rejects a UK-format date', pay_valid_date('01/03/2026'), false);
check('rejects empty', pay_valid_date(''), false);
check('rejects a partial date', pay_valid_date('2026-03'), false);
check('rejects trailing text', pay_valid_date("2026-03-01' OR 1=1"), false);

// ---------------------------------------------------------------------------
group('rg_panic_open');

// The board used in_array('panic', $kinds), so once an alert was raised the
// banner stayed up for the rest of the day with nothing that could clear it.
check('no events, nothing open', rg_panic_open([]), false);
check('ordinary shift, nothing open', rg_panic_open(['clock_in', 'note', 'clock_out']), false);
check('a raised alert is open', rg_panic_open(['clock_in', 'panic']), true);
check('acknowledged closes it', rg_panic_open(['clock_in', 'panic', 'panic_ack']), false);

// Order, not counts: a shift can be raised, acknowledged, and raised again, and
// the second one is live.
check('raised again after an acknowledgement', rg_panic_open(['panic', 'panic_ack', 'panic']), true);
check('and acknowledged again', rg_panic_open(['panic', 'panic_ack', 'panic', 'panic_ack']), false);
check('other events do not close it', rg_panic_open(['panic', 'note', 'check_in']), true);
check('an acknowledgement alone opens nothing', rg_panic_open(['panic_ack']), false);

// ---------------------------------------------------------------------------
group('rg_bank_holidays');

// The table held eight rows for 2026 and nothing after, so from 2027 every bank
// holiday would have paid flat rate instead of the configured multiplier. These
// eight are the rows the project already had on file for 2026, so the generator
// is checked against data that was trusted before it existed.
$y2026 = rg_bank_holidays(2026);
check('2026 has eight', count($y2026), 8);
check("2026 New Year's Day", $y2026['2026-01-01'] ?? null, "New Year's Day");
check('2026 Good Friday', $y2026['2026-04-03'] ?? null, 'Good Friday');
check('2026 Easter Monday', $y2026['2026-04-06'] ?? null, 'Easter Monday');
check('2026 Early May', $y2026['2026-05-04'] ?? null, 'Early May bank holiday');
check('2026 Spring', $y2026['2026-05-25'] ?? null, 'Spring bank holiday');
check('2026 Summer', $y2026['2026-08-31'] ?? null, 'Summer bank holiday');
check('2026 Christmas Day', $y2026['2026-12-25'] ?? null, 'Christmas Day');
check('2026 Boxing Day substitute', $y2026['2026-12-28'] ?? null, 'Boxing Day (substitute)');

// 2027, as published for England and Wales.
$y2027 = rg_bank_holidays(2027);
check('2027 Good Friday', $y2027['2027-03-26'] ?? null, 'Good Friday');
check('2027 Easter Monday', $y2027['2027-03-29'] ?? null, 'Easter Monday');
check('2027 Early May', $y2027['2027-05-03'] ?? null, 'Early May bank holiday');
check('2027 Spring', $y2027['2027-05-31'] ?? null, 'Spring bank holiday');
check('2027 Summer', $y2027['2027-08-30'] ?? null, 'Summer bank holiday');

// Christmas Day on a Sunday is the case that gets the names the wrong way round:
// Boxing Day keeps the Monday and Christmas is the one that moves to Tuesday.
// This is what actually happened in 2022.
$y2022 = rg_bank_holidays(2022);
check('2022 Boxing Day keeps its Monday', $y2022['2022-12-26'] ?? null, 'Boxing Day');
check('2022 Christmas moves to the Tuesday', $y2022['2022-12-27'] ?? null, 'Christmas Day (substitute)');
check('2022 New Year substitutes to the Monday', $y2022['2022-01-03'] ?? null, "New Year's Day (substitute)");

// Christmas on a Saturday pushes both.
$y2027d = rg_bank_holidays(2027);
check('Christmas on a Saturday moves to Monday', $y2027d['2027-12-27'] ?? null, 'Christmas Day (substitute)');
check('and Boxing Day to the Tuesday', $y2027d['2027-12-28'] ?? null, 'Boxing Day (substitute)');

// Eight every year, whatever the weekdays do, and never two on one date.
foreach (range(2020, 2040) as $y) {
    if (count(rg_bank_holidays($y)) !== 8) {
        check("every year has eight ($y)", count(rg_bank_holidays($y)), 8);
    }
}
check('every year from 2020 to 2040 has eight', true, true);

// Easter, against published dates.
check('Easter 2026', rg_easter_sunday(2026), '2026-04-05');
check('Easter 2027', rg_easter_sunday(2027), '2027-03-28');
check('Easter 2024', rg_easter_sunday(2024), '2024-03-31');
check('Easter 2038 is a late one', rg_easter_sunday(2038), '2038-04-25');

// ---------------------------------------------------------------------------
group('pay_entry');

// Every one of these sums used to live inside pay_compute()'s result loop, which
// takes a mysqli and so could not be tested without a database. On a payroll
// system that meant base pay, the overtime split, the premium application and
// both roundings had no test at all.
$rules = ['night' => 1.30, 'weekend' => 1.25, 'bank_holiday' => 2.00, 'overtime' => 1.50];
$seg = static fn(string $k, int $m): array => [['keys' => $k === 'standard' ? [] : [$k], 'minutes' => $m]];

// £12.50/hr, eight hours, nothing special about the day.
$e = pay_entry(1250, $seg('standard', 480), 0.0, $rules);
check('plain day: hours', $e['hours'], 8.0);
check('plain day: base', $e['base_pence'], 10000);
check('plain day: gross equals base', $e['gross_pence'], 10000);
check('plain day: no overtime', $e['overtime_hours'], 0.0);
check('plain day: premium label', $e['premium'], 'standard');

// A night premium lifts gross but never base -- base is what the hours are worth
// before any uplift, and premium_pence is the difference between the two.
$e = pay_entry(1250, $seg('night', 480), 0.0, $rules);
check('night: base is unchanged', $e['base_pence'], 10000);
check('night: gross carries the uplift', $e['gross_pence'], 13000);

// A shift that runs from a weekday night into a Saturday is paid night for the
// part that is night and weekend for the part that is weekend. The old model
// paid night on all eight hours and weekend on none.
$mixed = [['keys' => ['night'], 'minutes' => 240], ['keys' => ['weekend'], 'minutes' => 240]];
$e = pay_entry(1000, $mixed, 0.0, $rules);
check('mixed shift: four hours night at 1.3, four weekend at 1.25', $e['gross_pence'], 5200 + 5000);
check('mixed shift: base ignores premiums', $e['base_pence'], 8000);
check('mixed shift: labelled by the dearest band', $e['premium'], 'night');
check('mixed shift: breakdown shows both', $e['breakdown'], ['night' => 4.0, 'weekend' => 4.0]);

// A minute can be both weekend and night. The dearest configured band pays --
// with the defaults that is night at 1.30, ahead of weekend at 1.25 -- and
// changing the multipliers changes which one wins, without touching code.
$both = [['keys' => ['weekend', 'night'], 'minutes' => 480]];
check('weekend and night together pays the dearer', pay_entry(1000, $both, 0.0, $rules)['gross_pence'], 10400);
check('and says which one it was', pay_entry(1000, $both, 0.0, $rules)['premium'], 'night');
$weekendRich = ['night' => 1.10, 'weekend' => 1.60, 'bank_holiday' => 2.00, 'overtime' => 1.50];
check('reconfigured, weekend wins instead', pay_entry(1000, $both, 0.0, $weekendRich)['premium'], 'weekend');

// The weekly threshold splits a single entry. 36 hours already worked, then an
// 8-hour day: 4 hours at normal rate, 4 at overtime.
$e = pay_entry(1000, $seg('standard', 480), 36.0, $rules);
check('split entry: normal part', $e['normal_hours'], 4.0);
check('split entry: overtime part', $e['overtime_hours'], 4.0);
check('split entry: gross', $e['gross_pence'], 4000 + 6000);
check('split entry: base ignores the split', $e['base_pence'], 8000);

// Exactly on the threshold is not yet overtime.
check('40 hours exactly is not overtime', pay_entry(1000, $seg('standard', 480), 32.0, $rules)['overtime_hours'], 0.0);
check('past it is', pay_entry(1000, $seg('standard', 480), 32.5, $rules)['overtime_hours'] > 0, true);

// Wholly beyond the threshold: every hour of the entry is overtime.
$e = pay_entry(1000, $seg('standard', 480), 40.0, $rules);
check('wholly overtime: no normal hours', $e['normal_hours'], 0.0);
check('wholly overtime: gross', $e['gross_pence'], 12000);

// Overtime never pays less than the premium it displaces: a bank-holiday hour
// does not get cheaper by being the forty-first of the week.
$e = pay_entry(1000, $seg('bank_holiday', 480), 40.0, $rules);
check('the higher of overtime and premium wins', $e['gross_pence'], 16000);

// The threshold can fall inside a segment rather than between two.
$e = pay_entry(1000, $seg('night', 480), 38.0, $rules);
check('threshold inside a segment: normal part', $e['normal_hours'], 2.0);
check('threshold inside a segment: overtime part', $e['overtime_hours'], 6.0);
check('threshold inside a segment: gross', $e['gross_pence'], 2600 + 9000);

// Rounding happens once per entry, on pence.
$e = pay_entry(1337, $seg('standard', 90), 0.0, $rules);
check('part hours round to whole pence', $e['gross_pence'], 2006); // 1.5 x 1337 = 2005.5
check('a zero-length entry is worth nothing', pay_entry(1250, [], 0.0, $rules)['gross_pence'], 0);
check('no rate means no pay', pay_entry(0, $seg('night', 480), 0.0, $rules)['gross_pence'], 0);

// ---------------------------------------------------------------------------
group('pay_holiday');

// 12.07% of worked hours, valued at base rate.
$h = pay_holiday(100.0, 1000);
check('accrues 12.07 per cent', round($h['hours'], 4), 12.07);
check('valued at base rate', $h['pence'], 12070);
check('nothing worked accrues nothing', pay_holiday(0.0, 1250)['pence'], 0);

// ---------------------------------------------------------------------------
group('pay week scan');

// Overtime is a weekly threshold, so a pay period cutting through a week has to
// count the days either side of the cut or the threshold resets mid-week.
//
// The defect: a monthly run read only the entries inside from..to while keying
// the 40-hour accumulator by ISO week. A worker doing 8 hours on Monday 31
// August and 40 more across the rest of that week reached 48; the September run
// saw 40 and paid no overtime at all. Widening the scan to whole ISO weeks fixes
// it -- the days outside the period fill the threshold and are never paid.
check('a period starting mid-week scans back to Monday', pay_week_scan_start('2026-09-01'), '2026-08-31');
check('a period starting on a Monday stays put', pay_week_scan_start('2026-08-31'), '2026-08-31');
check('a Sunday start scans back six days', pay_week_scan_start('2026-09-06'), '2026-08-31');
check('a period ending mid-week scans on to Sunday', pay_week_scan_end('2026-09-30'), '2026-10-04');
check('a period ending on a Sunday stays put', pay_week_scan_end('2026-10-04'), '2026-10-04');
check('a Monday end scans on six days', pay_week_scan_end('2026-09-28'), '2026-10-04');

// ---------------------------------------------------------------------------
group('rg_premium_segments');

// Premiums were decided for a whole entry from its START hour and its work_date.
// That was defensible while a shift could not cross midnight, and stopped being
// so the moment overnight entries were allowed.
$bh = ['2026-12-25' => true];

// 2026-09-10 is a Thursday, 2026-09-11 a Friday, 2026-09-12 a Saturday.
$plain = rg_premium_segments('2026-09-10', '09:00', 480, $bh);
check('a plain weekday day is one segment', count($plain), 1);
check('carrying no premium', $plain[0]['keys'], []);
check('for the whole shift', $plain[0]['minutes'], 480);

// The old model gave this night for all eight hours because it started at five.
$early = rg_premium_segments('2026-09-10', '05:00', 480, $bh);
check('an 05:00 start is night only until six', $early[0]['keys'], ['night']);
check('for one hour', $early[0]['minutes'], 60);
check('then nothing', $early[1]['keys'], []);
check('for the remaining seven', $early[1]['minutes'], 420);

// The old model gave this no night premium at all, because it started at six.
$evening = rg_premium_segments('2026-09-10', '18:00', 480, $bh);
check('an 18:00 start carries nothing until eight', $evening[0]['keys'], []);
check('for two hours', $evening[0]['minutes'], 120);
check('then night', $evening[1]['keys'], ['night']);
check('for six', $evening[1]['minutes'], 360);

// The case the overnight fix created: Friday night into Saturday morning. The
// old model paid night on all eight hours and weekend on none.
$intoSat = rg_premium_segments('2026-09-11', '20:00', 480, $bh);
check('Friday 20:00 starts as night', $intoSat[0]['keys'], ['night']);
check('for four hours', $intoSat[0]['minutes'], 240);
check('then weekend and night together at midnight', $intoSat[1]['keys'], ['weekend', 'night']);
check('for four hours', $intoSat[1]['minutes'], 240);

// A bank holiday outranks both, and only for the part that is one.
$intoBh = rg_premium_segments('2026-12-24', '20:00', 480, $bh);
check('Christmas Eve night is night', $intoBh[0]['keys'], ['night']);
check('until Christmas Day starts', $intoBh[1]['keys'], ['bank_holiday', 'night']);
check('for the rest of the shift', $intoBh[1]['minutes'], 240);

// A whole Saturday is weekend throughout, night window included: weekend
// outranks night, so there is no segment boundary at 20:00.
$sat = rg_premium_segments('2026-09-12', '18:00', 480, $bh);
check('a Saturday evening starts as weekend alone', $sat[0]['keys'], ['weekend']);
check('for two hours', $sat[0]['minutes'], 120);
check('then weekend and night from 20:00', $sat[1]['keys'], ['weekend', 'night']);
check('for six', $sat[1]['minutes'], 360);

check('a zero-length entry has no segments', rg_premium_segments('2026-09-10', '09:00', 0, $bh), []);

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
group('rg_leave_year');

// April start: the common UK choice.
check('mid-year', rg_leave_year('2026-06-15', 4), '2026/27');
check('the day it starts', rg_leave_year('2026-04-01', 4), '2026/27');
check('the day before', rg_leave_year('2026-03-31', 4), '2025/26');
check('January belongs to the previous year', rg_leave_year('2026-01-10', 4), '2025/26');
check('December', rg_leave_year('2026-12-31', 4), '2026/27');
// A January start is one calendar year, not a straddle.
check('calendar leave year', rg_leave_year('2026-06-15', 1), '2026');
check('unparseable is empty', rg_leave_year('nonsense', 4), '');

// ---------------------------------------------------------------------------
group('rg_leave_balance');

$none = rg_leave_balance(224.0, []);
check('nothing taken leaves it all', $none['remaining'], 224.0);
check('entitlement is reported', $none['entitlement'], 224.0);
check('and is known', $none['entitlement_known'], true);

// No entitlement recorded is not an entitlement of zero. Without this, somebody
// whose contracted holiday was never entered shows a large negative balance,
// which reads as "overdrawn" when it means "nobody set this up".
$unset = rg_leave_balance(null, [['kind' => 'annual', 'status' => 'approved', 'hours' => 37.5]]);
check('unset entitlement is flagged', $unset['entitlement_known'], false);
check('and the balance is null, not negative', $unset['remaining'], null);
check('but usage is still counted', $unset['used'], 37.5);
$zero = rg_leave_balance(0.0, []);
check('zero is also treated as unset', $zero['entitlement_known'], false);

$records = [
    ['kind' => 'annual', 'status' => 'taken',     'hours' => 24],
    ['kind' => 'annual', 'status' => 'approved',  'hours' => 16],
    ['kind' => 'annual', 'status' => 'requested', 'hours' => 8],
    ['kind' => 'annual', 'status' => 'declined',  'hours' => 40],
    ['kind' => 'annual', 'status' => 'cancelled', 'hours' => 40],
];
$b = rg_leave_balance(224.0, $records);
check('taken counts', $b['taken'], 24.0);
check('approved counts', $b['approved'], 16.0);
check('requested is reported separately', $b['requested'], 8.0);
// A request is not an agreement. Deducting it would mislead both sides.
check('requested is NOT deducted', $b['remaining'], 184.0);
check('used is taken plus approved', $b['used'], 40.0);
check('declined consumes nothing', rg_leave_balance(100.0, [['kind'=>'annual','status'=>'declined','hours'=>50]])['remaining'], 100.0);
check('cancelled consumes nothing', rg_leave_balance(100.0, [['kind'=>'annual','status'=>'cancelled','hours'=>50]])['remaining'], 100.0);

// Sickness is recorded but does not consume holiday.
check('sickness does not consume holiday',
    rg_leave_balance(100.0, [['kind' => 'sickness', 'status' => 'taken', 'hours' => 40]])['remaining'], 100.0);
check('unpaid leave does not consume holiday',
    rg_leave_balance(100.0, [['kind' => 'unpaid', 'status' => 'taken', 'hours' => 40]])['remaining'], 100.0);

// Carry-over adds to the entitlement rather than being deducted from it.
$carried = rg_leave_balance(100.0, [['kind' => 'annual', 'status' => 'approved', 'hours' => 20, 'carried_over' => 1]]);
check('carry-over increases entitlement', $carried['entitlement'], 120.0);
check('and is reported', $carried['carried_in'], 20.0);
check('and is not also deducted', $carried['remaining'], 120.0);

// Paid in lieu still consumes the entitlement -- it was paid instead of taken.
$lieu = rg_leave_balance(100.0, [['kind' => 'annual', 'status' => 'taken', 'hours' => 10, 'in_lieu' => 1]]);
check('payment in lieu consumes entitlement', $lieu['remaining'], 90.0);
check('and is reported separately', $lieu['paid_in_lieu'], 10.0);

// ---------------------------------------------------------------------------
group('rg_leave_overlaps');

check('clear overlap', rg_leave_overlaps('2026-06-01', '2026-06-10', '2026-06-05', '2026-06-15'), true);
check('contained', rg_leave_overlaps('2026-06-01', '2026-06-30', '2026-06-10', '2026-06-12'), true);
check('identical', rg_leave_overlaps('2026-06-01', '2026-06-01', '2026-06-01', '2026-06-01'), true);
// Ends are inclusive: a day of leave is a whole day, so touching ends DO clash.
check('touching ends clash', rg_leave_overlaps('2026-06-01', '2026-06-05', '2026-06-05', '2026-06-09'), true);
check('adjacent days do not', rg_leave_overlaps('2026-06-01', '2026-06-05', '2026-06-06', '2026-06-09'), false);
check('far apart', rg_leave_overlaps('2026-06-01', '2026-06-05', '2026-08-01', '2026-08-05'), false);
check('order does not matter', rg_leave_overlaps('2026-08-01', '2026-08-05', '2026-06-01', '2026-06-05'), false);

// ---------------------------------------------------------------------------
// Form intake. A broken mapping is SILENT -- submissions arrive and map to
// nothing -- so the matching is deliberately forgiving and everything it cannot
// place is reported rather than dropped.
group('rg_map_submission');

$maps = [
    ['source_field' => 'Full name',     'target_field' => 'name',  'required' => 1],
    ['source_field' => 'Email address', 'target_field' => 'email', 'required' => 1],
    ['source_field' => 'Phone number',  'target_field' => 'phone', 'required' => 0],
    ['source_field' => 'Notes',         'target_field' => 'ignore', 'required' => 0],
];

$r = rg_map_submission(['Full name' => 'Amara Chen', 'Email address' => 'a@example.com'], $maps);
check('maps what it finds', $r['fields']['name'], 'Amara Chen');
check('and the rest', $r['fields']['email'], 'a@example.com');
check('nothing missing', $r['missing'], []);

// Google marks required questions with an asterisk, and people rename questions
// with stray spaces. An exact-string join breaks on both.
$loose = rg_map_submission(['  full NAME *' => 'Amara Chen', 'Email Address' => 'a@example.com'], $maps);
check('tolerates case, spacing and asterisks', $loose['fields']['name'], 'Amara Chen');
check('and on the second field too', $loose['fields']['email'], 'a@example.com');

// A required answer left blank is reported, not silently defaulted.
$incomplete = rg_map_submission(['Full name' => 'Amara Chen'], $maps);
check('reports a missing required field', $incomplete['missing'], ['Email address']);
$blank = rg_map_submission(['Full name' => 'Amara Chen', 'Email address' => '   '], $maps);
check('whitespace counts as missing', $blank['missing'], ['Email address']);

// Optional fields simply do not appear.
check('absent optional field is not reported', $r['missing'], []);
check('and is not invented', isset($r['fields']['phone']), false);

// Checkbox answers arrive as arrays.
$multi = rg_map_submission(['Full name' => ['Amara', 'Chen'], 'Email address' => 'a@example.com'], $maps);
check('array answers are joined', $multi['fields']['name'], 'Amara, Chen');

// A question nobody mapped must SHOW UP, not vanish. A form that gains a
// question should read as something to configure.
$extra = rg_map_submission(
    ['Full name' => 'A', 'Email address' => 'a@example.com', 'What shifts can you work?' => 'Nights'],
    $maps
);
check('unmapped questions are reported', $extra['unmapped'], ['what shifts can you work?']);
// An explicitly ignored question is not reported as unmapped.
$ignored = rg_map_submission(['Full name' => 'A', 'Email address' => 'a@example.com', 'Notes' => 'hello'], $maps);
check('an ignored question is not flagged', $ignored['unmapped'], []);
check('and is not mapped either', isset($ignored['fields']['notes']), false);
// A blank unmapped answer is not worth flagging.
$blankExtra = rg_map_submission(['Full name' => 'A', 'Email address' => 'a@example.com', 'Spare' => ''], $maps);
check('a blank unmapped answer is ignored', $blankExtra['unmapped'], []);

// Value translation, so the form can say "Security Officer" and the CMS store
// "security".
$svcMap = [['source_field' => 'Service', 'target_field' => 'service',
            'value_map' => '{"Security Officer":"security","Care Assistant":"care"}']];
check('translates a value', rg_map_submission(['Service' => 'Security Officer'], $svcMap)['fields']['service'], 'security');
check('translation is case-insensitive', rg_map_submission(['Service' => 'care assistant'], $svcMap)['fields']['service'], 'care');
check('an untranslated value passes through', rg_map_submission(['Service' => 'Cleaner'], $svcMap)['fields']['service'], 'Cleaner');

// ---------------------------------------------------------------------------
group('rg_parse_yes_no');

check('yes', rg_parse_yes_no('Yes'), true);
check('y', rg_parse_yes_no('y'), true);
check('no', rg_parse_yes_no('NO'), false);
check('padded', rg_parse_yes_no('  yes  '), true);
check('empty is unknown', rg_parse_yes_no(''), null);
check('null is unknown', rg_parse_yes_no(null), null);
// Anything ambiguous stays unknown rather than being guessed at. On a
// right-to-work question, guessing is the worst possible behaviour.
check('a hedge is unknown', rg_parse_yes_no('I have a share code'), null);
check('maybe is unknown', rg_parse_yes_no('maybe'), null);

// ---------------------------------------------------------------------------
// Derived worker status. Order is the whole design here, so most of these test
// which rule wins rather than any rule in isolation.
group('rg_derive_worker_status');

$ready = ['eligible_services' => 2, 'on_leave_today' => false, 'onboarding_outstanding' => 0, 'has_contract' => true];

check('cleared and contracted is active', rg_derive_worker_status(['status' => 'active'], $ready)['status'], 'active');
check('and says how many services', rg_derive_worker_status(['status' => 'active'], $ready)['reason'], 'Cleared for 2 services');
check('singular reads correctly', rg_derive_worker_status(['status' => 'active'], ['eligible_services' => 1, 'has_contract' => true])['reason'], 'Cleared for 1 service');

// Outstanding onboarding beats everything else that is fine.
$mid = $ready; $mid['onboarding_outstanding'] = 3;
check('onboarding tasks hold it back', rg_derive_worker_status(['status' => 'active'], $mid)['status'], 'onboarding');
check('and are counted', rg_derive_worker_status(['status' => 'active'], $mid)['reason'], '3 onboarding tasks outstanding');

$noContract = $ready; $noContract['has_contract'] = false;
check('no contract is pre-employment', rg_derive_worker_status(['status' => 'active'], $noContract)['status'], 'pre_employment');

$notEligible = $ready; $notEligible['eligible_services'] = 0;
check('eligible for nothing is not active', rg_derive_worker_status(['status' => 'active'], $notEligible)['status'], 'pre_employment');
check('and says why', rg_derive_worker_status(['status' => 'active'], $notEligible)['reason'], 'Not eligible for any service');

// Leave outranks compliance: somebody on holiday has not become non-compliant.
$onLeave = $notEligible; $onLeave['on_leave_today'] = true;
check('leave outranks ineligibility', rg_derive_worker_status(['status' => 'active'], $onLeave)['status'], 'on_leave');

// A suspension is a decision, not a derivation.
check('suspension stands', rg_derive_worker_status(['status' => 'suspended'], $ready)['status'], 'suspended');
check('inactive stands', rg_derive_worker_status(['status' => 'inactive'], $ready)['status'], 'inactive');

// Having left outranks everything. A leaver is not a compliance problem.
check('a leaver is a leaver', rg_derive_worker_status(['status' => 'active', 'left_on' => '2026-01-01'], $notEligible, '2026-06-01')['status'], 'left');
check('even if suspended', rg_derive_worker_status(['status' => 'suspended', 'left_on' => '2026-01-01'], $ready, '2026-06-01')['status'], 'left');
check('leaving today counts', rg_derive_worker_status(['status' => 'active', 'left_on' => '2026-06-01'], $ready, '2026-06-01')['status'], 'left');
// A future leaving date has not happened yet.
check('a future leaving date does not', rg_derive_worker_status(['status' => 'active', 'left_on' => '2026-12-01'], $ready, '2026-06-01')['status'], 'active');
check('an empty leaving date is ignored', rg_derive_worker_status(['status' => 'active', 'left_on' => ''], $ready, '2026-06-01')['status'], 'active');

group('rg_file_readable');

// The document vault holds company material and named people's records in the
// same place. Opening the vault is not permission to open everything in it.
$office = ['role' => 'manager'];
$editor = ['role' => 'editor'];
$policy = ['category' => 'policy', 'person_id' => null, 'worker_id' => null, 'client_id' => null];
$idScan = ['category' => 'id', 'person_id' => null, 'worker_id' => null, 'client_id' => null];

check('an office role reads anything', rg_file_readable($idScan, $office), true);
check('an editor reads company material', rg_file_readable($policy, $editor), true);
check('an editor does not read an identity document', rg_file_readable($idScan, $editor), false);
check('nor a contract', rg_file_readable(['category' => 'contract'], $editor), false);
check('nor a certificate', rg_file_readable(['category' => 'certificate'], $editor), false);

// Category is not the only signal -- a file filed against a person is personal
// whatever it was labelled on upload.
check('filed against a person', rg_file_readable(['category' => 'other', 'person_id' => 4], $editor), false);
check('filed against a worker', rg_file_readable(['category' => 'other', 'worker_id' => 9], $editor), false);
check('filed against a client', rg_file_readable(['category' => 'other', 'client_id' => 2], $editor), false);
check('unfiled other is company material', rg_file_readable(['category' => 'other'], $editor), true);
check('a missing category is not personal on its own', rg_file_readable([], $editor), true);
check('a role we do not know reads nothing personal', rg_file_readable($idScan, ['role' => 'client']), false);

group('rg_coord_places / rg_round_coord');

// Attendance is stored coarsely; a panic alert is not.
check('clock in is coarse', rg_coord_places('clock_in'), 3);
check('clock out is coarse', rg_coord_places('clock_out'), 3);
check('a note is coarse', rg_coord_places('note'), 3);
check('panic keeps precision', rg_coord_places('panic'), 6);

check('rounds to about 110 m', rg_round_coord(51.5074456, 3), 51.507);
check('and the other axis', rg_round_coord(-0.1277653, 3), -0.128);
check('panic precision is untouched', rg_round_coord(51.5074456, 6), 51.507446);
check('null stays null', rg_round_coord(null, 3), null);
check('zero survives rounding', rg_round_coord(0.0, 3), 0.0);

group('rg_unsubscribe_token');

$secret = 'a-long-server-side-secret';
$t = rg_unsubscribe_token('sam@example.com', $secret);

check('is a sha256 hex digest', strlen($t), 64);
check('is stable for the same address', rg_unsubscribe_token('sam@example.com', $secret), $t);
check('case in the address does not matter', rg_unsubscribe_token('Sam@Example.com', $secret), $t);
check('surrounding whitespace does not either', rg_unsubscribe_token('  sam@example.com ', $secret), $t);
check('a different address is a different token', rg_unsubscribe_token('other@example.com', $secret) === $t, false);
check('a different secret is a different token', rg_unsubscribe_token('sam@example.com', 'other') === $t, false);

check('a good token validates', rg_unsubscribe_valid('sam@example.com', $t, $secret), true);
check('as does the address in any case', rg_unsubscribe_valid('SAM@example.com', $t, $secret), true);
check('a token for someone else does not', rg_unsubscribe_valid('other@example.com', $t, $secret), false);
check('nor a token under another secret', rg_unsubscribe_valid('sam@example.com', $t, 'other'), false);
// Both of these would otherwise be an unsubscribe link that works for everyone.
check('an empty token never validates', rg_unsubscribe_valid('sam@example.com', '', $secret), false);
check('nor does an unset secret', rg_unsubscribe_valid('sam@example.com', $t, ''), false);

group('rg_retention_due / rg_retention_action');

// The important case: no period set means nothing happens. All eight seeded
// policies are NULL, so this is what the sweep does on the day it ships.
check('a null period is never due', rg_retention_due('2010-01-01', null, '2026-09-04'), false);
check('nor is a zero period', rg_retention_due('2010-01-01', 0, '2026-09-04'), false);
check('nor a negative one', rg_retention_due('2010-01-01', -6, '2026-09-04'), false);

// Nor does a record with nothing to count from.
check('a null anchor is never due', rg_retention_due(null, 12, '2026-09-04'), false);
check('an empty anchor is never due', rg_retention_due('', 12, '2026-09-04'), false);
check('a zero date is never due', rg_retention_due('0000-00-00', 12, '2026-09-04'), false);
check('an unparseable anchor is never due', rg_retention_due('not a date', 12, '2026-09-04'), false);

check('past the period', rg_retention_due('2024-01-01', 12, '2026-09-04'), true);
check('inside the period', rg_retention_due('2026-06-01', 12, '2026-09-04'), false);
check('exactly on the day counts', rg_retention_due('2025-09-04', 12, '2025-09-04'), false);
check('the day it falls due', rg_retention_due('2024-09-04', 12, '2025-09-04'), true);
check('the day before it does not', rg_retention_due('2024-09-05', 12, '2025-09-04'), false);
// Month arithmetic across a year boundary, and out of a long month.
check('crosses a year end', rg_retention_due('2025-11-30', 6, '2026-05-31'), true);
check('72 months is six years', rg_retention_due('2020-01-01', 72, '2026-09-04'), true);
check('and not five', rg_retention_due('2021-10-01', 72, '2026-09-04'), false);

check('review passes through', rg_retention_action('review'), 'review');
check('archive passes through', rg_retention_action('archive'), 'archive');
check('delete passes through', rg_retention_action('delete'), 'delete');
check('anything else asks a person', rg_retention_action('destroy'), 'review');
check('and so does an empty value', rg_retention_action(''), 'review');

// ---------------------------------------------------------------------------
group('endpoint guards');

[$guardChecked, $guardProblems] = guard_check_endpoints();
check('every endpoint was inspected', $guardChecked > 40, true);
foreach ($guardProblems as $problem) {
    check($problem, false, true);
}
check('no endpoint reads before it authorises', $guardProblems, []);

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
