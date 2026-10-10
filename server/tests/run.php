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
group('rg_deduct_break');

// Nothing recorded a break at all, so gross hours were paid door to door: a
// twelve-hour shift with an unpaid half hour was paid twelve hours.
$oneBand = [['keys' => ['night'], 'minutes' => 480]];
check('no break changes nothing', rg_deduct_break($oneBand, 0), $oneBand);
check('a negative break changes nothing', rg_deduct_break($oneBand, -30), $oneBand);
check('a break comes off a single band exactly', rg_deduct_break($oneBand, 30)[0]['minutes'], 450.0);

// Taken proportionally, because nothing records WHEN the break was taken. For a
// shift crossing a band it splits the deduction the way the shift splits.
$two = [['keys' => ['night'], 'minutes' => 240], ['keys' => ['weekend'], 'minutes' => 240]];
$cut = rg_deduct_break($two, 60);
check('proportional across two equal bands, first', $cut[0]['minutes'], 210.0);
check('proportional across two equal bands, second', $cut[1]['minutes'], 210.0);
check('and the total is right', $cut[0]['minutes'] + $cut[1]['minutes'], 420.0);

// Uneven bands keep their proportions: 600 night + 120 standard, less 30.
$uneven = [['keys' => ['night'], 'minutes' => 600], ['keys' => [], 'minutes' => 120]];
$cut = rg_deduct_break($uneven, 30);
check('uneven bands keep their shape, night', $cut[0]['minutes'], 575.0);
check('uneven bands keep their shape, standard', $cut[1]['minutes'], 115.0);

// A break that swallows the shift leaves nothing payable rather than going
// negative; the caller decides whether that is an error.
check('a break equal to the shift pays nothing', rg_deduct_break($oneBand, 480), []);
check('a break longer than the shift pays nothing', rg_deduct_break($oneBand, 600), []);
check('no segments, nothing to deduct from', rg_deduct_break([], 30), []);

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
group('rg_parse_google_form');

$formId = '1FAIpQLSe_example-Form_Id_0123456789abc';
check('the responder address is accepted', rg_parse_google_form("https://docs.google.com/forms/d/e/$formId/viewform")['id'], $formId);
check('with Google\'s sharing parameter', rg_parse_google_form("https://docs.google.com/forms/d/e/$formId/viewform?usp=sf_link")['id'], $formId);
check('surrounding spaces are ignored', rg_parse_google_form("  https://docs.google.com/forms/d/e/$formId/viewform \n")['id'], $formId);
check('and no error is given', rg_parse_google_form("https://docs.google.com/forms/d/e/$formId/viewform")['error'], null);
$refused = [
    'plain http' => "http://docs.google.com/forms/d/e/$formId/viewform",
    'a look-alike host' => "https://docs.google.com.example.net/forms/d/e/$formId/viewform",
    'a userinfo trick' => "https://docs.google.com@example.net/forms/d/e/$formId/viewform",
    'the google.com redirector' => "https://www.google.com/url?q=https://docs.google.com/forms/d/e/$formId/viewform",
    'javascript:' => 'javascript:alert(document.cookie)',
    'a data: URL' => 'data:text/html,<script>alert(1)</script>',
    'pasted embed HTML' => "<iframe src=\"https://docs.google.com/forms/d/e/$formId/viewform?embedded=true\"></iframe>",
    'an extra path after viewform' => "https://docs.google.com/forms/d/e/$formId/viewform/../../evil",
    'an arbitrary query string' => "https://docs.google.com/forms/d/e/$formId/viewform?embedded=true&x=<script>",
    'a fragment' => "https://docs.google.com/forms/d/e/$formId/viewform#x",
    'an id with a quote in it' => 'https://docs.google.com/forms/d/e/abc"onload="x0123456789/viewform',
    'an id too short to be real' => 'https://docs.google.com/forms/d/e/short/viewform',
    'a different Google product' => "https://docs.google.com/document/d/e/$formId/viewform",
    'an upper-case scheme trick' => "HTTPS://docs.google.com/forms/d/e/$formId/viewform",
    'empty' => '',
];
foreach ($refused as $what => $url) {
    check("$what is refused", rg_parse_google_form($url)['id'], null);
}
check('the short link gets advice', str_contains((string) rg_parse_google_form('https://forms.gle/abc123')['error'], 'short link'), true);
check('the editing address gets advice',
    str_contains((string) rg_parse_google_form('https://docs.google.com/forms/d/1abcDEF/edit')['error'], 'editing address'), true);

group('rg_valid_google_form_id');
check('a stored id', rg_valid_google_form_id($formId), true);
check('a slash is not an id', rg_valid_google_form_id("$formId/x"), false);
check('null is not an id', rg_valid_google_form_id(null), false);

// ---------------------------------------------------------------------------
group('rg_slugify / rg_valid_slug');

check('a title becomes an address', rg_slugify('Senior Carer, Night Shifts'), 'senior-carer-night-shifts');
check('runs of punctuation collapse', rg_slugify('  Cleaner -- (Schools) / Lambeth  '), 'cleaner-schools-lambeth');
check('accents fold to plain letters', rg_slugify('Café supervisor'), 'cafe-supervisor');
check('nothing usable falls back', rg_slugify('!!!'), 'role');
check('long titles are cut at 80 without a trailing hyphen', strlen(rg_slugify(str_repeat('ab ', 60))) <= 80, true);
check('and still valid', rg_valid_slug(rg_slugify(str_repeat('ab ', 60))), true);
check('a slug is valid', rg_valid_slug('support-worker-croydon'), true);
check('upper case is not', rg_valid_slug('Support-Worker'), false);
check('a double hyphen is not', rg_valid_slug('support--worker'), false);
check('a leading hyphen is not', rg_valid_slug('-support'), false);
check('a slash is not', rg_valid_slug('careers/x'), false);
check('empty is not', rg_valid_slug(''), false);

group('rg_vacancy_slug_editable / rg_vacancy_transition_ok');
check('a never-published slug may change', rg_vacancy_slug_editable(null), true);
check('a published one may not', rg_vacancy_slug_editable('2026-10-01 09:00:00'), false);
check('draft to published', rg_vacancy_transition_ok('draft', 'published'), true);
check('published to closed', rg_vacancy_transition_ok('published', 'closed'), true);
check('closed can be reopened', rg_vacancy_transition_ok('closed', 'published'), true);
check('published cannot go back to draft', rg_vacancy_transition_ok('published', 'draft'), false);
check('an unknown state is refused', rg_vacancy_transition_ok('draft', 'archived'), false);

group('rg_vacancy_default_checks / rg_vacancy_apply_route');
check('care asks for an enhanced DBS', str_contains(rg_vacancy_default_checks('care'), 'Enhanced DBS'), true);
check('security asks for the SIA licence', str_contains(rg_vacancy_default_checks('security'), 'SIA licence'), true);
check('cleaning asks for COSHH', str_contains(rg_vacancy_default_checks('cleaning'), 'COSHH'), true);
check('every service starts with right to work',
    array_unique(array_map(fn ($s) => explode("\n", rg_vacancy_default_checks($s))[0], ['care', 'cleaning', 'security', 'academy'])),
    ['Right to work in the UK']);
check('no form: the native short form', rg_vacancy_apply_route(false, false), 'native');
check('an active form: that form only', rg_vacancy_apply_route(true, true), 'form');
check('a switched-off form: closed, not the native form', rg_vacancy_apply_route(true, false), 'closed');

group('rg_form_review_due');
check('never reviewed is due', rg_form_review_due(null, '2026-10-09'), true);
check('reviewed last week is not', rg_form_review_due('2026-10-02', '2026-10-09'), false);
check('89 days is not yet', rg_form_review_due('2026-07-12', '2026-10-09'), false);
check('90 days is', rg_form_review_due('2026-07-11', '2026-10-09'), true);

group('rg_social_url_ok');
check('an Instagram profile', rg_social_url_ok('instagram', 'https://instagram.com/ritagreenservices'), true);
check('a LinkedIn company page', rg_social_url_ok('linkedin', 'https://www.linkedin.com/company/ritagreen-services/'), true);
check('a WhatsApp click-to-chat link', rg_social_url_ok('whatsapp', 'https://wa.me/442031052218'), true);
check('empty means not shown', rg_social_url_ok('tiktok', ''), true);
check('another network\'s host is refused', rg_social_url_ok('instagram', 'https://facebook.com/x'), false);
check('a look-alike host is refused', rg_social_url_ok('instagram', 'https://instagram.com.example.net/x'), false);
check('http is refused', rg_social_url_ok('facebook', 'http://facebook.com/x'), false);
check('javascript: is refused', rg_social_url_ok('facebook', 'javascript:alert(1)'), false);
check('a bare host with no profile is refused', rg_social_url_ok('instagram', 'https://instagram.com/'), false);
check('X is not a network here', rg_social_url_ok('x', 'https://x.com/ritagreen'), false);

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
group('rg_employment_gate');

$approved = ['status' => 'active', 'approved_at' => '2026-01-01 09:00:00', 'left_on' => null];
$none = ['onboarding_outstanding' => 0];
$today = '2026-09-14';

check('an approved active worker passes', rg_employment_gate($approved, $none, $today)['ok'], true);

// Order matters: a leaver is not a compliance problem and must not read as one.
// Written out rather than built with `+`: the union operator keeps the LEFT
// operand's keys, so $approved's own left_on => null would have won and the
// test would have passed while asserting nothing.
check('a leaver is blocked',
    rg_employment_gate(['status' => 'active', 'approved_at' => 'x', 'left_on' => '2026-06-30'], $none, $today)['ok'], false);
check('and is told why',
    rg_employment_gate(['status' => 'active', 'approved_at' => 'x', 'left_on' => '2026-06-30'], $none, $today)['reason'],
    'This worker left on 2026-06-30.');
// A leaving date in the future has not happened yet.
check('a future leaving date does not block yet',
    rg_employment_gate(['status' => 'active', 'approved_at' => 'x', 'left_on' => '2026-12-31'], $none, $today)['ok'], true);

check('an archived record is blocked',
    rg_employment_gate($approved + ['deleted_at' => '2026-01-02'], $none, $today)['ok'], false);

check('a suspended worker is blocked',
    rg_employment_gate(['status' => 'suspended', 'approved_at' => 'x'], $none, $today)['ok'], false);
check('with suspension given as the reason',
    rg_employment_gate(['status' => 'suspended', 'approved_at' => 'x'], $none, $today)['reason'],
    'This worker is suspended.');
check('an inactive worker is blocked',
    rg_employment_gate(['status' => 'inactive', 'approved_at' => 'x'], $none, $today)['ok'], false);

// Starting onboarding is not finishing it.
check('outstanding onboarding blocks',
    rg_employment_gate($approved, ['onboarding_outstanding' => 3], $today)['ok'], false);
check('and says how many',
    rg_employment_gate($approved, ['onboarding_outstanding' => 3], $today)['reason'],
    '3 onboarding tasks are still outstanding.');
check('one task reads as one task',
    rg_employment_gate($approved, ['onboarding_outstanding' => 1], $today)['reason'],
    '1 onboarding task is still outstanding.');

// Every box ticked is not the same fact as an approval.
check('an unapproved worker is blocked',
    rg_employment_gate(['status' => 'active', 'approved_at' => null], $none, $today)['ok'], false);
check('and is told so plainly',
    rg_employment_gate(['status' => 'active', 'approved_at' => null], $none, $today)['reason'],
    'This worker has not been approved for work.');
check('an empty approval date is not an approval',
    rg_employment_gate(['status' => 'active', 'approved_at' => ''], $none, $today)['ok'], false);

// A suspension outranks an unfinished checklist: both block, and the reason
// given should be the one a person acts on first.
check('suspension is reported before onboarding',
    rg_employment_gate(['status' => 'suspended', 'approved_at' => null], ['onboarding_outstanding' => 2], $today)['reason'],
    'This worker is suspended.');

// ---------------------------------------------------------------------------
group('rg_stage_transition');

$ok = fn (string $f, string $t, bool $r = false) => rg_stage_transition($f, $t, $r)['ok'];
$why = fn (string $f, string $t, bool $r = false) => rg_stage_transition($f, $t, $r)['reason'];

// Forward, one step at a time.
check('applied to screened', $ok('applied', 'screened'), true);
check('screened to interview', $ok('screened', 'interview'), true);
check('interview to vetting', $ok('interview', 'vetting'), true);
check('vetting to offer', $ok('vetting', 'offer'), true);

// Skipping forward is the bypass this exists to stop.
check('applied straight to offer is refused', $ok('applied', 'offer'), false);
check('and says which stage comes next', $why('applied', 'offer'), 'A candidate moves forward one stage at a time. The next stage is screened.');
check('applied straight to vetting is refused', $ok('applied', 'vetting'), false);
check('screened straight to offer is refused', $ok('screened', 'offer'), false);

// Stages an action owns cannot be typed in.
check('nobody sets onboarding by hand', $ok('offer', 'onboarding'), false);
check('and is told what does', $why('offer', 'onboarding'),
    'Starting onboarding, which creates the worker record sets this stage. It cannot be set directly.');
check('nobody sets onboarded by hand', $ok('onboarding', 'onboarded'), false);
check('not even from applied', $ok('applied', 'onboarded'), false);

// Backwards is a correction, not a bypass, and needs a reason.
check('interview back to screened needs a reason', $ok('interview', 'screened'), false);
check('and is allowed with one', $ok('interview', 'screened', true), true);
check('offer back to applied is allowed with a reason', $ok('offer', 'applied', true), true);

// Leaving the pipeline, from anywhere, with a reason.
check('rejected from applied needs a reason', $ok('applied', 'rejected'), false);
check('rejected from applied with a reason', $ok('applied', 'rejected', true), true);
check('rejected from offer with a reason', $ok('offer', 'rejected', true), true);
check('withdrawn is its own exit', $ok('interview', 'withdrawn', true), true);
check('a candidate cannot be rejected twice', $ok('rejected', 'withdrawn', true), false);
check('nor withdrawn after rejection', $ok('rejected', 'rejected', true), false);

// Coming back in.
check('rejected back to applied needs a reason', $ok('rejected', 'applied'), false);
check('rejected back to applied with a reason', $ok('rejected', 'applied', true), true);
check('withdrawn back to interview with a reason', $ok('withdrawn', 'interview', true), true);
check('but not back into a stage an action owns', $ok('withdrawn', 'onboarded', true), false);

// Nonsense.
check('no move to the same stage', $ok('applied', 'applied'), false);
check('an unknown target is refused', $ok('applied', 'hired'), false);
check('an unknown source is refused', $ok('shortlisted', 'interview', true), false);

// ---------------------------------------------------------------------------
group('rg_in_service_scope');

// An unscoped account -- admin, or any account with scope_to_division off.
check('no scope sees a record in any service', rg_in_service_scope(null, 'care'), true);
check('no scope sees a record with no service', rg_in_service_scope(null, null), true);

// A scoped account.
check('own service is in scope', rg_in_service_scope('security', 'security'), true);
check('another service is not', rg_in_service_scope('security', 'care'), false);

// A record with no service is out of scope for a scoped account. Treating
// "unknown" as "yours" is how a scope stops meaning anything.
check('a record with no service is out of scope', rg_in_service_scope('security', null), false);
check('an empty service is out of scope', rg_in_service_scope('security', ''), false);

// Services are compared exactly. A prefix is a different service.
check('a prefix is not a match', rg_in_service_scope('care', 'careers'), false);

// ---------------------------------------------------------------------------
group('endpoint guards');

[$guardChecked, $guardProblems] = guard_check_endpoints();
check('every endpoint was inspected', $guardChecked > 40, true);
foreach ($guardProblems as $problem) {
    check($problem, false, true);
}
check('no endpoint reads before it authorises', $guardProblems, []);
check('no retired endpoint has come back', guard_retired_present(), []);

// ---------------------------------------------------------------------------
group('rg_cron_key');

// The header keeps the key out of the access log; the query string must keep
// working, because the crontabs on the server already use it.
check('reads the X-Cron-Key header', rg_cron_key(['HTTP_X_CRON_KEY' => 'h'], []), 'h');
check('falls back to ?key=', rg_cron_key([], ['key' => 'q']), 'q');
check('the header wins when both are sent', rg_cron_key(['HTTP_X_CRON_KEY' => 'h'], ['key' => 'q']), 'h');
check('an empty header falls back to ?key=', rg_cron_key(['HTTP_X_CRON_KEY' => ''], ['key' => 'q']), 'q');
check('nothing sent is an empty key', rg_cron_key([], []), '');
// ?key[]=x arrives as an array. It must read as no key (a 403), not reach
// hash_equals as an array or raise an "Array to string" warning (a 500).
check('an array ?key is no key', rg_cron_key([], ['key' => ['x']]), '');

// ---------------------------------------------------------------------------
group('feature switches');

// The defaults keep what production did before the switches existed.
check('finance defaults on', rg_feature_on('finance', []), true);
check('online payments default on', rg_feature_on('online_payments', []), true);
check('SMS and WhatsApp default on', rg_feature_on('sms_whatsapp', []), true);
check('the inbox defaults on', rg_feature_on('inbox', []), true);
// The AI assistant switch was retired with ai.php in S9. Its name is now unknown,
// so even the row migration 036 left behind cannot turn anything on.
check('the retired AI assistant switch is off', rg_feature_on('ai_assistant', []), false);
check('and a stored 1 for it changes nothing', rg_feature_on('ai_assistant', ['ai_assistant' => 1]), false);
// A stored row wins over the default, either way, whatever type MySQL returned.
check('a stored 0 turns a default-on switch off', rg_feature_on('inbox', ['inbox' => '0']), false);
check('a stored 1 keeps a switch on', rg_feature_on('inbox', ['inbox' => 1]), true);
check('a stored row for another switch does not leak', rg_feature_on('inbox', ['finance' => 0]), true);
// A name nobody defined is a typo in code, and the safe answer is no.
check('an unknown switch is off', rg_feature_on('teleport', []), false);
check('even when a row exists for it', rg_feature_on('teleport', ['teleport' => 1]), false);
check('the map lists every switch, in order',
    array_keys(rg_feature_map([])), ['finance', 'online_payments', 'sms_whatsapp', 'inbox']);
check('the map applies stored values', rg_feature_map(['finance' => 0])['finance'], false);
check('the map ignores rows for unknown names', array_key_exists('teleport', rg_feature_map(['teleport' => 1])), false);

group('website cleaning quote options');

// The public quote form posts these keys; quotes.php refuses anything else. The
// frontend copy of the list (src/components/public/forms/options.ts) must match.
check('site types are the plan\'s cleaning buyers plus other',
    array_keys(rg_cleaning_site_types()), ['residential', 'void', 'school', 'construction', 'office', 'care', 'other']);
check('frequencies run from one-off to not sure',
    array_keys(rg_cleaning_frequencies()), ['one_off', 'daily', 'several_weekly', 'weekly', 'fortnightly', 'monthly', 'not_sure']);
check('every site type has a label the office can read',
    count(array_filter(rg_cleaning_site_types(), static fn ($l) => trim($l) === '')), 0);
check('every frequency has a label', count(array_filter(rg_cleaning_frequencies(), static fn ($l) => trim($l) === '')), 0);

// ---------------------------------------------------------------------------
group('rg_person_in_scope');

// A person holds services through several records. In scope when any of them is
// the account's; never when the person holds none.
check('an unscoped account sees anyone', rg_person_in_scope(null, []), true);
check('a scoped account sees a person in its service', rg_person_in_scope('security', ['care', 'security']), true);
check('but not a person only in another', rg_person_in_scope('security', ['care']), false);
check('and not a person with no service at all', rg_person_in_scope('security', []), false);
check('a NULL service is not a match', rg_person_in_scope('security', [null, '']), false);

// ---------------------------------------------------------------------------
group('rg_service_statuses');

$today = '2026-10-09';
$s = rg_service_statuses([], [['id' => 1, 'division' => 'care', 'stage' => 'interview']], $today);
check('an application alone reads as applicant', $s['care']['status'], 'applicant');
check('with its stage', $s['care']['detail'], 'Application at interview');

$s = rg_service_statuses(
    [['id' => 7, 'division' => 'care', 'status' => 'onboarding', 'approved_at' => null, 'payroll_no' => 'RG-000007']],
    [['id' => 1, 'division' => 'care', 'stage' => 'onboarding']],
    $today
);
check('a worker record outranks the application for the same service', $s['care']['status'], 'onboarding');
check('and keeps the application it came from', $s['care']['applicant_id'], 1);
check('with the payroll number', $s['care']['payroll_no'], 'RG-000007');

$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'active', 'approved_at' => null]], [], $today);
// Active with nobody's approval cannot be put on a shift, so it must not read as active.
check('active but unapproved reads as onboarding', $s['care']['status'], 'onboarding');
$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'active', 'approved_at' => '2026-01-01']], [], $today);
check('active and approved reads as active', $s['care']['status'], 'active');
$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'suspended', 'approved_at' => '2026-01-01']], [], $today);
check('a suspension is shown as one', $s['care']['status'], 'suspended');
$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'suspended', 'left_on' => '2026-09-30']], [], $today);
check('having left outranks a suspension', $s['care']['status'], 'left');
$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'active', 'approved_at' => 'x', 'left_on' => '2026-12-31']], [], $today);
check('a leaving date in the future has not happened yet', $s['care']['status'], 'active');

$s = rg_service_statuses(
    [['id' => 7, 'division' => 'care', 'status' => 'active', 'approved_at' => 'x']],
    [['id' => 2, 'division' => 'security', 'stage' => 'rejected']],
    $today
);
check('one person, a different answer per service', [$s['care']['status'], $s['security']['status']], ['active', 'applicant']);
check('an exit is described as one', $s['security']['detail'], 'Application rejected');
$s = rg_service_statuses([], [
    ['id' => 3, 'division' => 'care', 'stage' => 'applied'],
    ['id' => 9, 'division' => 'care', 'stage' => 'offer'],
], $today);
check('the newest application for a service wins', $s['care']['applicant_id'], 9);
$s = rg_service_statuses([['id' => 7, 'division' => 'care', 'status' => 'active', 'deleted_at' => '2026-01-01']], [], $today);
check('an archived worker record is not a status', $s, []);

// ---------------------------------------------------------------------------
group('rg_requirement_chip');

check('met is Valid', rg_requirement_chip('met'), ['chip' => 'valid', 'label' => 'Valid']);
check('expiring is Due soon', rg_requirement_chip('expiring')['chip'], 'due_soon');
check('expired is Expired', rg_requirement_chip('expired')['chip'], 'expired');
check('missing is Missing', rg_requirement_chip('missing')['chip'], 'missing');
check('on file but unchecked is Awaiting review', rg_requirement_chip('unverified')['chip'], 'awaiting_review');
check('on file with no expiry is Awaiting review', rg_requirement_chip('undated')['chip'], 'awaiting_review');
// Evidence that has arrived is the next thing to deal with, not the gap.
check('missing with evidence received is Awaiting review', rg_requirement_chip('missing', 'received')['chip'], 'awaiting_review');
check('expired with a renewal under review is Awaiting review', rg_requirement_chip('expired', 'under_review')['chip'], 'awaiting_review');
check('a request alone is still Missing', rg_requirement_chip('missing', 'requested')['chip'], 'missing');
// A renewal in the queue does not make a valid credential stop being valid.
check('met with a renewal received stays Valid', rg_requirement_chip('met', 'received')['chip'], 'valid');

// ---------------------------------------------------------------------------
group('rg_approval_blockers');

$pending = ['status' => 'onboarding', 'approved_at' => null, 'left_on' => null];
check('nothing outstanding and a reviewed contract: approvable', rg_approval_blockers($pending, 0, true, $today), []);
check('already approved says so',
    array_column(rg_approval_blockers(['status' => 'active', 'approved_at' => '2026-01-01'], 0, true, $today), 'code'), ['approved']);
$b = rg_approval_blockers($pending, 2, false, $today);
check('every blocker is listed, in order', array_column($b, 'code'), ['employment', 'contract']);
check('naming the outstanding onboarding', str_contains($b[0]['reason'], '2 onboarding tasks'), true);
check('and the contract', str_contains($b[1]['reason'], 'reviewed'), true);
check('a suspended worker cannot be approved',
    array_column(rg_approval_blockers(['status' => 'suspended', 'approved_at' => null], 0, true, $today), 'code'), ['employment']);
check('nor a leaver',
    rg_approval_blockers(['status' => 'onboarding', 'approved_at' => null, 'left_on' => '2026-01-01'], 0, true, $today)[0]['code'],
    'employment');

// ---------------------------------------------------------------------------
group('rg_person_next_action');

$key = static fn(array $f): ?string => rg_person_next_action($f)['key'] ?? null;
$worker = ['has_worker' => true];
check('nothing known, nothing to do', rg_person_next_action([]), null);
check('a suspension comes first', $key($worker + ['suspended' => true, 'awaiting_review' => ['DBS check']]), 'review_suspension');
check('evidence waiting on a reviewer before anything else',
    rg_person_next_action($worker + ['awaiting_review' => ['DBS check'], 'onboarding_outstanding' => 3]),
    ['key' => 'verify', 'label' => 'Verify DBS check', 'tab' => 'checks']);
check('then an unverified right-to-work check', $key($worker + ['rtw_needs_verify' => true, 'contract' => 'signed_unreviewed']), 'verify_rtw');
check('then a signed contract nobody has read', $key($worker + ['contract' => 'signed_unreviewed', 'onboarding_outstanding' => 1]), 'review_contract');
check('then the onboarding checklist',
    rg_person_next_action($worker + ['onboarding_outstanding' => 1])['label'], 'Finish onboarding (1 task)');
check('then missing mandatory evidence',
    rg_person_next_action($worker + ['blocking' => ['Right to work'], 'contract' => 'none'])['label'], 'Request right to work');
check('initials keep their capitals', rg_person_next_action($worker + ['awaiting_review' => ['DBS check']])['label'], 'Verify DBS check');
check('then the contract, from a draft', $key($worker + ['contract' => 'none']), 'draft_contract');
check('a draft is sent', $key($worker + ['contract' => 'draft']), 'send_contract');
check('a sent contract waits for the signed copy', $key($worker + ['contract' => 'sent']), 'record_signature');
check('then approval, when nothing blocks it', $key($worker + ['contract' => 'reviewed', 'approvable' => true]), 'approve');
check('an approved worker is not offered approval',
    $key($worker + ['contract' => 'reviewed', 'approvable' => false, 'approved' => true]), null);
check('a credential running out is renewed', $key($worker + ['approved' => true, 'contract' => 'reviewed', 'expiring' => ['SIA licence']]), 'renew');
check('an applicant at offer is ready for onboarding', $key(['applicant_stage' => 'offer']), 'start_onboarding');
check('an applicant earlier on needs reviewing', $key(['applicant_stage' => 'screened']), 'review_application');
check('a rejected applicant needs nothing', $key(['applicant_stage' => 'rejected']), null);

// ---------------------------------------------------------------------------
group('rg_valid_phone');

check('a UK mobile', rg_valid_phone('07700 900123'), true);
check('international form', rg_valid_phone('+44 7700 900123'), true);
check('with brackets and hyphens', rg_valid_phone('(020) 7946-0018'), true);
check('letters are not a number', rg_valid_phone('call me'), false);
check('too short to ring', rg_valid_phone('12345'), false);
check('longer than any real number', rg_valid_phone('+44 7700 900123 4567'), false);
check('a plus in the middle is not a number', rg_valid_phone('07700+900123'), false);
check('empty is not a number', rg_valid_phone(''), false);

// ---------------------------------------------------------------------------
group('rg_validate_emergency_contacts');

$ok = rg_validate_emergency_contacts([
    'emergency_contact_name' => ' Jo Bloggs ', 'emergency_contact_phone' => '07700 900456',
    'emergency_contact_relationship' => 'Sister',
]);
check('one complete contact is accepted', $ok['ok'], true);
check('trimmed', $ok['values']['emergency_contact_name'], 'Jo Bloggs');
check('and the second left empty', $ok['values']['emergency_contact2_name'], null);
check('everything empty clears both', rg_validate_emergency_contacts([])['ok'], true);
$bad = rg_validate_emergency_contacts(['emergency_contact_name' => 'Jo Bloggs']);
check('a name with no number is refused', $bad['ok'], false);
check('saying which is missing', $bad['errors'], ['Give a phone number for the first contact.']);
$bad = rg_validate_emergency_contacts(['emergency_contact2_phone' => '07700 900456']);
check('a number with no name is refused', $bad['errors'], ['Give a name for the second contact.']);
$bad = rg_validate_emergency_contacts(['emergency_contact_name' => 'Jo', 'emergency_contact_phone' => 'not a phone']);
check('a number nobody could ring is refused',
    $bad['errors'], ["The first contact's phone number is not a number that can be rung."]);

// ---------------------------------------------------------------------------
group('lifecycle maps shared with the endpoints');

// The profile reads these to say what may happen next, so they must keep the
// rules the endpoints enforce: no jumping from requested or draft to the end.
check('a requested check cannot be verified directly', in_array('verified', RG_VERIFY_TRANSITIONS['requested'], true), false);
check('a received check can be', in_array('verified', RG_VERIFY_TRANSITIONS['received'], true), true);
check('a draft contract cannot be signed', in_array('signed', RG_CONTRACT_TRANSITIONS['draft'], true), false);
check('a signed contract can only be superseded', RG_CONTRACT_TRANSITIONS['signed'], ['superseded']);

// ---------------------------------------------------------------------------
group('rota: settings');

check('the rest gap defaults to 11 hours', rg_setting_int('rest_gap_min_hours', []), 11);
check('a stored value is used', rg_setting_int('rest_gap_min_hours', ['rest_gap_min_hours' => '10']), 10);
check('a value that is not a number falls back', rg_setting_int('rest_gap_min_hours', ['rest_gap_min_hours' => 'eleven']), 11);
check('a value out of bounds falls back', rg_setting_int('rest_gap_min_hours', ['rest_gap_min_hours' => '72']), 11);
$threw = false;
try { rg_setting_int('no_such_setting', []); } catch (InvalidArgumentException $e) { $threw = true; }
check('an unknown setting is a programming error', $threw, true);

// ---------------------------------------------------------------------------
group('rota: real time across the clock changes (Europe/London)');

check('an ordinary night is ten hours', rg_wall_minutes_between('2026-06-13 22:00:00', '2026-06-14 08:00:00'), 600);
check('across 29 March 2026 (clocks forward) it is nine',
    rg_wall_minutes_between('2026-03-28 22:00:00', '2026-03-29 08:00:00'), 540);
check('across 25 October 2026 (clocks back) it is eleven',
    rg_wall_minutes_between('2026-10-24 22:00:00', '2026-10-25 08:00:00'), 660);
check('negative when the order is reversed', rg_wall_minutes_between('2026-06-14 08:00:00', '2026-06-13 22:00:00'), -600);
check('minutes label', [rg_minutes_label(540), rg_minutes_label(630), rg_minutes_label(45)], ['9h', '10h 30m', '45m']);

// ---------------------------------------------------------------------------
group('rota: rest gap');

$night = ['starts_at' => '2026-06-13 20:00:00', 'ends_at' => '2026-06-14 08:00:00'];
$early = ['id' => 7, 'starts_at' => '2026-06-14 16:00:00', 'ends_at' => '2026-06-14 22:00:00'];
$w = rg_rest_gap_warnings($night, [$early], 11);
check('eight hours after a night shift is a warning', count($w), 1);
check('naming the gap and the other shift', $w[0]['reason'] ?? '',
    'Only 8h rest before the shift starting Sun 14 Jun 16:00 (the minimum is 11h).');
check('and which shift it is', $w[0]['shift_id'] ?? null, 7);
check('eleven hours exactly is enough',
    rg_rest_gap_warnings($night, [['starts_at' => '2026-06-14 19:00:00', 'ends_at' => '2026-06-14 23:00:00']], 11), []);
check('measured from the earlier shift too',
    count(rg_rest_gap_warnings(['starts_at' => '2026-06-15 06:00:00', 'ends_at' => '2026-06-15 14:00:00'],
        [['starts_at' => '2026-06-14 18:00:00', 'ends_at' => '2026-06-14 23:00:00']], 11)), 1);
check('across midnight: 23:00 to 06:00 next day is seven hours',
    rg_rest_gap_warnings(['starts_at' => '2026-06-15 06:00:00', 'ends_at' => '2026-06-15 14:00:00'],
        [['starts_at' => '2026-06-14 15:00:00', 'ends_at' => '2026-06-14 23:00:00']], 11)[0]['gap_minutes'] ?? null, 420);
check('an overlap is not a rest question (the clash check refuses it)',
    rg_rest_gap_warnings($night, [['starts_at' => '2026-06-14 07:00:00', 'ends_at' => '2026-06-14 12:00:00']], 11), []);
// Wall-clock gap of 10h 30m either side of each change.
$springBefore = ['starts_at' => '2026-03-28 14:00:00', 'ends_at' => '2026-03-28 21:30:00'];
$springAfter = ['starts_at' => '2026-03-29 08:00:00', 'ends_at' => '2026-03-29 16:00:00'];
check('29 March: 21:30 to 08:00 reads 10h 30m and is 9h 30m',
    rg_rest_gap_warnings($springAfter, [$springBefore], 11)[0]['gap_minutes'] ?? null, 570);
$autumnBefore = ['starts_at' => '2026-10-24 14:00:00', 'ends_at' => '2026-10-24 21:30:00'];
$autumnAfter = ['starts_at' => '2026-10-25 08:00:00', 'ends_at' => '2026-10-25 16:00:00'];
check('25 October: the same times are 11h 30m, so no warning',
    rg_rest_gap_warnings($autumnAfter, [$autumnBefore], 11), []);
check('a minimum of zero switches the check off', rg_rest_gap_warnings($night, [$early], 0), []);

// ---------------------------------------------------------------------------
group('rota: leave and unavailability');

check('a day shift touches one date', rg_shift_dates('2026-06-13 08:00:00', '2026-06-13 16:00:00'), ['2026-06-13']);
check('a night shift touches two', rg_shift_dates('2026-06-13 20:00:00', '2026-06-14 08:00:00'), ['2026-06-13', '2026-06-14']);
check('ending at midnight touches one', rg_shift_dates('2026-06-13 16:00:00', '2026-06-14 00:00:00'), ['2026-06-13']);

$leave = [
    ['starts_on' => '2026-06-14', 'ends_on' => '2026-06-20', 'status' => 'approved', 'kind' => 'annual'],
    ['starts_on' => '2026-07-01', 'ends_on' => '2026-07-03', 'status' => 'requested', 'kind' => 'annual'],
    ['starts_on' => '2026-08-01', 'ends_on' => '2026-08-01', 'status' => 'declined', 'kind' => 'annual'],
];
check('approved leave on the day blocks', rg_leave_conflict($leave, '2026-06-15 08:00:00', '2026-06-15 16:00:00')['kind'] ?? null, 'annual');
check('a night shift running into the first day of leave blocks',
    rg_leave_conflict($leave, '2026-06-13 20:00:00', '2026-06-14 08:00:00') !== null, true);
check('the evening before leave, ending at midnight, does not',
    rg_leave_conflict($leave, '2026-06-13 16:00:00', '2026-06-14 00:00:00'), null);
check('a request still waiting does not block', rg_leave_conflict($leave, '2026-07-02 08:00:00', '2026-07-02 16:00:00'), null);
check('declined leave does not block', rg_leave_conflict($leave, '2026-08-01 08:00:00', '2026-08-01 16:00:00'), null);
check('taken leave blocks too',
    rg_leave_conflict([['starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'taken']],
        '2026-05-01 09:00:00', '2026-05-01 10:00:00') !== null, true);

$blocks = [['starts_at' => '2026-06-15 00:00:00', 'ends_at' => '2026-06-15 12:00:00']];
check('marked unavailable over the shift', rg_unavailability_conflict($blocks, '2026-06-15 08:00:00', '2026-06-15 16:00:00') !== null, true);
check('touching ends are fine', rg_unavailability_conflict($blocks, '2026-06-15 12:00:00', '2026-06-15 20:00:00'), null);

// ---------------------------------------------------------------------------
group('rota: lifecycle words and change notices');

check('draft', rg_shift_state(['status' => 'open', 'is_draft' => 1]), 'draft');
check('a draft with a worker is still a draft', rg_shift_state(['status' => 'scheduled', 'is_draft' => 1]), 'draft');
check('published open', rg_shift_state(['status' => 'open', 'is_draft' => 0]), 'open');
check('offered', rg_shift_state(['status' => 'open', 'is_draft' => 0], 2), 'offered');
check('assigned', rg_shift_state(['status' => 'scheduled', 'is_draft' => 0]), 'assigned');
check('cancelled wins over draft', rg_shift_state(['status' => 'cancelled', 'is_draft' => 1]), 'cancelled');
check('completed', rg_shift_state(['status' => 'completed', 'is_draft' => 0]), 'completed');

$before = ['title' => 'Day carer', 'site_name' => 'Oakleaf', 'starts_at' => '2026-06-15 08:00:00', 'ends_at' => '2026-06-15 16:00:00'];
check('nothing changed, nothing to say', rg_shift_change_lines($before, $before), []);
check('a time change says both times',
    rg_shift_change_lines($before, ['starts_at' => '2026-06-15 09:00:00', 'ends_at' => '2026-06-15 17:00:00'] + $before),
    ['Time: Mon 15 Jun 08:00 to 16:00 is now Mon 15 Jun 09:00 to 17:00.']);
check('a site change', rg_shift_change_lines($before, ['site_name' => 'Bramley Court'] + $before),
    ['Site: Oakleaf is now Bramley Court.']);

check('copying a week moves by seven days on the wall clock',
    rg_add_days('2026-10-19 22:00:00', 7), '2026-10-26 22:00:00');
check('across the clock change too', rg_add_days('2026-03-22 08:00:00', 7), '2026-03-29 08:00:00');
check('week start of a Sunday', rg_week_start('2026-10-11'), '2026-10-05');
check('week start of a Monday', rg_week_start('2026-10-05'), '2026-10-05');

// ---------------------------------------------------------------------------
group('rota: timesheets from the clock');

check('summer clock events are converted to BST',
    rg_clock_to_timesheet('2026-07-01 06:02:41', '2026-07-01 14:31:05'),
    ['work_date' => '2026-07-01', 'start_time' => '07:02', 'end_time' => '15:31']);
check('winter clock events are GMT',
    rg_clock_to_timesheet('2026-12-01 07:00:00', '2026-12-01 15:00:00'),
    ['work_date' => '2026-12-01', 'start_time' => '07:00', 'end_time' => '15:00']);
check('a night shift keeps the start date',
    rg_clock_to_timesheet('2026-12-01 20:00:00', '2026-12-02 08:00:00')['work_date'], '2026-12-01');
check('a worker submits a draft', rg_timesheet_can('submit', 'draft'), true);
check('or answers a query', rg_timesheet_can('submit', 'queried'), true);
check('but cannot resubmit an approved entry', rg_timesheet_can('submit', 'approved'), false);
check('the office queries what is waiting', rg_timesheet_can('query', 'pending'), true);
check('not what it has approved', rg_timesheet_can('query', 'approved'), false);
check('a correction never touches approved hours', rg_timesheet_can('correct', 'approved'), false);
check('approve stays permissive', rg_timesheet_can('approve', 'rejected'), true);

// ---------------------------------------------------------------------------
group('staff portal: clocking in, uploads, blockers, contact details');

// 08:00 BST on 1 July 2026 is 07:00 UTC.
$start = '2026-07-01 08:00:00';
check('clock-in opens fifteen minutes before, in London time', rg_clock_in_opens_at($start), '2026-07-01 07:45:00');
check('closed at 07:44 London', rg_clock_in_open($start, strtotime('2026-07-01 06:44:00 UTC')), false);
check('open at 07:45 London', rg_clock_in_open($start, strtotime('2026-07-01 06:45:00 UTC')), true);
check('still open after the start: a late arrival is recorded', rg_clock_in_open($start, strtotime('2026-07-01 09:00:00 UTC')), true);
check('winter is GMT', rg_clock_in_open('2026-12-01 08:00:00', strtotime('2026-12-01 07:45:00 UTC')), true);
check('and one minute earlier is closed', rg_clock_in_open('2026-12-01 08:00:00', strtotime('2026-12-01 07:44:00 UTC')), false);

check('a DBS certificate is filed as a certificate', rg_portal_upload_category('dbs'), 'certificate');
check('identity is filed as id', rg_portal_upload_category('identity'), 'id');
check('right to work stays with its own check', rg_portal_upload_category('right_to_work'), null);
check('references come from referees', rg_portal_upload_category('references'), null);
check('a worker can never file a contract', rg_portal_upload_category('contract'), null);
check('a key that is not a key is refused', rg_portal_upload_category('../etc'), null);

check('nothing blocks an eligible worker', rg_portal_blockers(['eligible' => true]), []);
$b = rg_portal_blockers(['eligible' => false, 'blocked_by' => 'employment', 'reason' => 'This worker is suspended.']);
check('employment blocks are said to the worker', $b[0]['title'], 'Your account is suspended.');
check('with somebody to contact', $b[0]['todo'], 'Please contact the office.');
$gate = ['eligible' => false, 'blocked_by' => 'evidence', 'reason' => 'x', 'blocking' => [
    ['key' => 'dbs', 'label' => 'Enhanced DBS', 'state' => 'expired'],
    ['key' => 'sia_licence', 'label' => 'SIA licence', 'state' => 'missing'],
    ['key' => 'references', 'label' => 'References', 'state' => 'missing'],
]];
$b = rg_portal_blockers($gate, ['sia_licence' => 'received']);
check('an expired check says so', $b[0]['title'], 'Enhanced DBS has expired');
check('and asks for a new copy', str_contains($b[0]['todo'], 'Upload a current copy'), true);
check('one already sent says the office is checking it', str_contains($b[1]['todo'], 'office is checking'), true);
check('one the worker cannot send says to contact the office', $b[2]['todo'], 'Please contact the office about this.');
$b = rg_portal_blockers(['eligible' => false, 'blocked_by' => 'evidence', 'blocking' => [
    ['key' => 'dbs', 'label' => 'DBS check', 'state' => 'unverified']]]);
check('unconfirmed with nothing sent asks for a copy, not patience', str_contains($b[0]['todo'], 'Upload a current copy'), true);

$c = rg_validate_contact_details(['phone' => '07700 900123', 'postcode' => 'se13 6aa', 'city' => 'London']);
check('a good contact form passes', $c['ok'], true);
check('the postcode is written as Royal Mail writes it', $c['values']['postcode'], 'SE13 6AA');
check('a blank line clears a field', $c['values']['address_line2'], null);
check('the phone cannot be cleared', rg_validate_contact_details(['phone' => ''])['ok'], false);
check('a number nobody can ring is refused', rg_validate_contact_details(['phone' => '123'])['ok'], false);
check('a postcode that is not one is refused', rg_validate_contact_details(['phone' => '07700 900123', 'postcode' => 'nowhere'])['ok'], false);
check('email is not a field a worker changes here', array_key_exists('email', rg_validate_contact_details(['phone' => '07700 900123', 'email' => 'x@y.z'])['values']), false);

// ---------------------------------------------------------------------------
group('spreadsheet import: CSV parsing');

$csv = "\xEF\xBB\xBFTimestamp,Full name*,Email address,Tell us about your experience\r\n"
     . "09/10/2026 14:03:22,Ada Lovell,ADA@Example.test,\"Five years, nights\r\nand days\"\r\n"
     . "\r\n"
     . "10/10/2026 09:00:00,\"Tom \"\"TJ\"\" Price\",tj@example.test,\r\n";
$p = rg_csv_parse($csv);
check('a Google Forms export parses', $p['ok'], true);
check('the byte-order mark is not part of the first heading', $p['headers'][0], 'Timestamp');
check('headings are kept as written', $p['headers'][1], 'Full name*');
check('a quoted cell keeps its comma and line break', $p['rows'][0]['cells']['Tell us about your experience'], "Five years, nights\r\nand days");
check('doubled quotes are one quote', $p['rows'][1]['cells']['Full name*'], 'Tom "TJ" Price');
check('two data rows; the blank line is skipped', count($p['rows']), 2);
check('the row number is the spreadsheet row', $p['rows'][0]['row'], 2);
check('a multi-line cell is still one row; the blank row is counted', $p['rows'][1]['row'], 4);

$semi = rg_csv_parse("Name;Email\nAda;ada@example.test\n");
check('a semicolon file is read by its delimiter', $semi['rows'][0]['cells']['Email'], 'ada@example.test');
$tab = rg_csv_parse("Name\tEmail\nAda\tada@example.test\n");
check('so is a tab file', $tab['rows'][0]['cells']['Email'], 'ada@example.test');

$latin = rg_csv_parse("Name,Town\nZo\xEB,Caf\xE9\n");
check('Windows-1252 text from Excel is read as such', $latin['rows'][0]['cells']['Name'], 'Zoë');

check('an empty file is refused', rg_csv_parse('')['ok'], false);
check('a header row with nothing under it is refused', rg_csv_parse("Name,Email\n")['error'],
    'The file has a header row but no responses under it.');
check('an unclosed quote is refused', rg_csv_parse("Name\n\"Ada\n")['ok'], false);
check('an XLSX file uploaded by mistake is refused', rg_csv_parse("PK\x03\x04\0\0binary")['ok'], false);
check('too many rows is refused', rg_csv_parse("Name\n" . str_repeat("x\n", 6), 5)['ok'], false);
$dupes = rg_csv_parse("Email,Email,\nx,y,z\n");
check('repeated headings are numbered rather than overwritten', $dupes['headers'], ['Email', 'Email (2)', 'Column 3']);

check('heading normalisation drops the asterisk and spacing',
    rg_import_normalise_header("  Full   Name * "), 'full name');
check('and a trailing colon', rg_import_normalise_header('Email:'), 'email');

// ---------------------------------------------------------------------------
group('spreadsheet import: field guesses');

check('Timestamp', rg_import_guess_field('Timestamp'), 'response_timestamp');
check('email', rg_import_guess_field('Email address*'), 'email');
check('emergency phone is not taken for phone', rg_import_guess_field('Emergency contact phone number'), 'emergency_phone');
check('emergency name', rg_import_guess_field('Emergency contact name'), 'emergency_name');
check('next of kin relationship', rg_import_guess_field('Next of kin relationship'), 'emergency_relationship');
check('mobile', rg_import_guess_field('Mobile number'), 'phone');
check('"tell us" is not a telephone', rg_import_guess_field('Tell us about your experience'), 'experience');
check('role', rg_import_guess_field('Which role are you applying for?'), 'role');
check('right to work', rg_import_guess_field('Do you have the right to work in the UK?'), 'right_to_work');
check('how heard', rg_import_guess_field('How did you hear about us?'), 'how_heard');
check('an unknown heading is ignored', rg_import_guess_field('Favourite colour'), 'ignore');

// ---------------------------------------------------------------------------
group('spreadsheet import: email and phone normalisation');

check('email is lower-cased and trimmed', rg_import_normalise_email('  Ada@Example.TEST ')['value'], 'ada@example.test');
check('mailto: is removed', rg_import_normalise_email('mailto:ada@example.test')['value'], 'ada@example.test');
check('a blank email is no email, not an error', rg_import_normalise_email(''), ['value' => null, 'error' => null]);
check('a broken email is an error', rg_import_normalise_email('ada at example')['error'], 'The email address is not valid.');

check('+44 becomes a UK number', rg_import_normalise_phone('+44 7700 900123')['value'], '07700900123');
check('0044 too', rg_import_normalise_phone('0044 7700 900123')['value'], '07700900123');
check('a sheet that dropped the leading zero gets it back', rg_import_normalise_phone('7700900123')['value'], '07700900123');
check('spaces and brackets are removed', rg_import_normalise_phone('(0113) 496 0000')['value'], '01134960000');
check('another country keeps its plus', rg_import_normalise_phone('+353 85 123 4567')['value'], '+353851234567');
check('letters are refused', rg_import_normalise_phone('call me')['error'] !== null, true);
check('a fragment is refused', rg_import_normalise_phone('123')['error'] !== null, true);
check('a blank phone is no phone', rg_import_normalise_phone(' ')['value'], null);

// ---------------------------------------------------------------------------
group('spreadsheet import: dates, availability, services, names');

check('dates are day first', rg_import_parse_date('03/04/1990'), '1990-04-03');
check('ISO dates are read', rg_import_parse_date('1990-04-03'), '1990-04-03');
check('an impossible date is refused', rg_import_parse_date('31/02/1990'), null);
check('a Google timestamp', rg_import_parse_timestamp('09/10/2026 14:03:22'), '2026-10-09 14:03:22');
check('a timestamp without seconds', rg_import_parse_timestamp('09/10/2026 9:05'), '2026-10-09 09:05:00');
check('availability: nights and weekends',
    rg_import_parse_availability('Nights, Weekends'), ['days' => false, 'nights' => true, 'weekends' => true]);
check('availability: flexible means everything',
    rg_import_parse_availability('Fully flexible'), ['days' => true, 'nights' => true, 'weekends' => true]);
check('availability: nothing recognisable changes nothing', rg_import_parse_availability('ask me'), null);
check('Healthcare is care', rg_import_service('Healthcare'), 'care');
check('Training is academy', rg_import_service('Training'), 'academy');
check('an unknown service is null', rg_import_service('Catering'), null);
check('names agree ignoring case and punctuation', rg_import_names_agree('ada  LOVELL', 'Ada Lovell.'), true);
check('names agree with a middle initial', rg_import_names_agree('Amara N. Okafor', 'Amara Okafor'), true);
check('different people do not agree', rg_import_names_agree('Ada Lovell', 'Grace Lovell'), false);

// ---------------------------------------------------------------------------
group('spreadsheet import: mapping a row');

$mapping = [
    'full name' => 'name', 'email address' => 'email', 'phone' => 'phone', 'service' => 'service',
    'role' => 'role', 'emergency name' => 'emergency_name', 'emergency phone' => 'emergency_phone',
    'pay rate' => 'pay_rate_pence', 'status' => 'status',
];
$m = rg_import_map_row([
    'Full name*' => ' Ada  Lovell ', 'Email address' => 'ADA@example.test', 'Phone' => '7700900123',
    'Service' => 'Healthcare', 'Role' => 'Support worker', 'Pay rate' => '9999', 'Status' => 'active',
], $mapping);
check('a good row has no problems', $m['problems'], []);
check('the name is tidied', $m['fields']['name'], 'Ada Lovell');
check('the email is normalised', $m['fields']['email'], 'ada@example.test');
check('the phone is normalised', $m['fields']['phone'], '07700900123');
check('the service is translated', $m['fields']['service'], 'care');
check('a column mapped to pay is ignored', array_key_exists('pay_rate_pence', $m['fields']), false);
check('a column mapped to status is ignored', array_key_exists('status', $m['fields']), false);

$noName = rg_import_map_row(['Email address' => 'x@example.test'], $mapping);
check('a row with no name is a problem', in_array('There is no name.', $noName['problems'], true), true);
$halfContact = rg_import_map_row(['Full name' => 'Ada', 'Emergency name' => 'Bob'], $mapping);
check('an emergency contact with no number is a problem',
    in_array('The emergency contact has a name but no phone number.', $halfContact['problems'], true), true);
$split = rg_import_map_row(['First' => 'Ada', 'Last' => 'Lovell'], ['first' => 'first_name', 'last' => 'last_name']);
check('first and last name are joined', $split['fields']['name'], 'Ada Lovell');
$default = rg_import_map_row(['Full name' => 'Ada'], $mapping, 'security');
check('the default service applies when the row has none', $default['fields']['service'], 'security');
$long = rg_import_map_row(['Full name' => str_repeat('a', 161)], $mapping);
check('a name over 160 characters is a problem', $long['problems'], ['Full name is longer than 160 characters.']);
$badService = rg_import_map_row(['Full name' => 'Ada', 'Service' => 'Catering'], $mapping);
check('an unknown service is a problem', count($badService['problems']), 1);

check('the same cells give the same fingerprint',
    rg_import_fingerprint(['Name' => 'Ada', 'Email' => 'a@x.test']), rg_import_fingerprint(['email ' => 'a@x.test', 'NAME' => ' Ada']));
check('a new blank column does not change it',
    rg_import_fingerprint(['Name' => 'Ada']), rg_import_fingerprint(['Name' => 'Ada', 'Extra' => '']));
check('different content does', rg_import_fingerprint(['Name' => 'Ada']) === rg_import_fingerprint(['Name' => 'Adam']), false);

// ---------------------------------------------------------------------------
group('spreadsheet import: match decision');

$ada = ['id' => 1, 'full_name' => 'Ada Lovell', 'email' => 'ada@example.test', 'phone' => '07700 900123', 'date_of_birth' => '1990-04-03'];
$tom = ['id' => 2, 'full_name' => 'Tom Price', 'email' => 'tom@example.test', 'phone' => '07700 900456', 'date_of_birth' => null];
$row = static fn(array $r): array => $r + ['name' => null, 'email' => null, 'phone' => null, 'date_of_birth' => null];

$d = rg_import_match_decision($row(['name' => 'Ada Lovell', 'email' => 'ADA@example.test']), [$ada, $tom]);
check('email match', [$d['decision'], $d['person_id'], $d['basis']], ['match', 1, 'email']);

$d = rg_import_match_decision($row(['name' => 'Grace Hopper', 'email' => 'ada@example.test']), [$ada]);
check('an email match with another name is uncertain', [$d['decision'], $d['person_id'], $d['candidates']], ['uncertain', null, [1]]);

$d = rg_import_match_decision($row(['name' => 'Ada Lovell', 'email' => 'ada@example.test', 'phone' => '07700900456']), [$ada, $tom]);
check('email and phone on two people is uncertain', [$d['decision'], $d['candidates']], ['uncertain', [1, 2]]);

$d = rg_import_match_decision($row(['name' => 'Tom Price', 'phone' => '+447700900456']), [$ada, $tom]);
check('phone match when there is no email', [$d['decision'], $d['person_id'], $d['basis']], ['match', 2, 'phone']);

$d = rg_import_match_decision($row(['name' => 'Sam Price', 'phone' => '07700900456']), [$tom]);
check('a shared phone with another name is uncertain', $d['decision'], 'uncertain');

$d = rg_import_match_decision($row(['name' => 'Tom Price', 'email' => 'new@example.test', 'phone' => '07700900456']), [$tom]);
check('a phone match with a different email on record is uncertain', $d['decision'], 'uncertain');

$d = rg_import_match_decision($row(['name' => 'ada lovell', 'date_of_birth' => '1990-04-03']), [$ada]);
check('name and date of birth match', [$d['decision'], $d['person_id'], $d['basis']], ['match', 1, 'name_dob']);

$d = rg_import_match_decision($row(['name' => 'Ada Lovell', 'email' => 'other@example.test', 'date_of_birth' => '1990-04-03']), [$ada]);
check('name and date of birth with a different email is uncertain', $d['decision'], 'uncertain');

$d = rg_import_match_decision($row(['name' => 'Ada Lovell', 'email' => 'brand.new@example.test']), [$ada]);
check('the same name alone is a possible match, never a merge', [$d['decision'], $d['basis'], $d['candidates']], ['uncertain', 'name', [1]]);

$d = rg_import_match_decision($row(['name' => 'Nobody Known', 'email' => 'n@example.test']), [$ada, $tom]);
check('no match', [$d['decision'], $d['person_id']], ['none', null]);

// ---------------------------------------------------------------------------
group('spreadsheet import: duplicates and changes');

$apps = [['role' => 'Support Worker', 'dates' => ['2026-10-09'], 'stage' => 'applied', 'source' => 'google_form']];
check('same person, role and day from the feed is a duplicate',
    rg_import_same_application('support worker', '2026-10-09 14:03:22', $apps),
    'This application was already received through the form feed on 2026-10-09.');
check('a different day is not', rg_import_same_application('Support worker', '2026-10-10 08:00:00', $apps), null);
check('a different role is not', rg_import_same_application('Cleaner', '2026-10-09 08:00:00', $apps), null);
check('no timestamp and an open application for the role is a duplicate',
    rg_import_same_application('Support worker', null, $apps) !== null, true);
check('no timestamp and a rejected application is not',
    rg_import_same_application('Support worker', null, [['role' => 'Support worker', 'dates' => [], 'stage' => 'rejected', 'source' => 'careers']]), null);

$current = ['email' => 'ada@example.test', 'phone' => '07700 900123', 'address_line1' => null, 'postcode' => 'LS1 1AA',
    'emergency_contact_name' => null, 'emergency_contact_phone' => null, 'emergency_contact_relationship' => null,
    'avail_days' => 1, 'avail_nights' => 0, 'avail_weekends' => 0];
$changes = rg_import_contact_changes($current, [
    'email' => 'ada@example.test', 'phone' => '07700900999', 'address' => '1 New Street', 'postcode' => 'ls11aa',
    'emergency_name' => 'Bob Lovell', 'emergency_phone' => '07700900777', 'emergency_relationship' => null,
    'availability' => 'Days and nights',
]);
check('only changed allowed fields are proposed', array_column($changes, 'field'),
    ['phone', 'address_line1', 'emergency_contact_name', 'emergency_contact_phone', 'availability']);
check('the old value is shown beside the new', [$changes[0]['old'], $changes[0]['new']], ['07700 900123', '07700900999']);
check('availability is described in words', [$changes[4]['old'], $changes[4]['new']], ['Days', 'Days, nights']);
check('a blank cell never clears anything', rg_import_contact_changes($current, ['phone' => null, 'email' => '']), []);
check('availability is not proposed for somebody who is not a worker',
    rg_import_contact_changes(['email' => null], ['availability' => 'nights']), []);

// ---------------------------------------------------------------------------
group('spreadsheet import: what a row becomes');

$base = ['problems' => [], 'purpose' => 'applications', 'duplicate' => null, 'match' => ['decision' => 'none'],
    'person' => null, 'service' => 'care', 'scope' => null, 'changes' => [], 'has_email' => true];
check('a clean new row is a new application', rg_import_plan_row($base)['outcome'], 'new');
check('problems come first', rg_import_plan_row(['problems' => ['There is no name.'], 'duplicate' => 'x'] + $base),
    ['outcome' => 'problem', 'reason' => 'There is no name.']);
check('a duplicate is a duplicate', rg_import_plan_row(['duplicate' => 'Already imported.'] + $base)['outcome'], 'duplicate');
check('an uncertain match waits for a person',
    rg_import_plan_row(['match' => ['decision' => 'uncertain', 'reason' => 'r']] + $base), ['outcome' => 'uncertain', 'reason' => 'r']);
check('a worker with changes is an update',
    rg_import_plan_row(['person' => ['has_worker' => true, 'in_scope' => true], 'changes' => [['field' => 'phone']]] + $base)['outcome'], 'update');
check('a worker with nothing new is no change',
    rg_import_plan_row(['person' => ['has_worker' => true, 'in_scope' => true]] + $base)['outcome'], 'no_change');
check('a person outside the service is a problem',
    rg_import_plan_row(['person' => ['has_worker' => true, 'in_scope' => false], 'changes' => [['field' => 'phone']]] + $base)['reason'],
    'This matches a person outside your service.');
check('staff updates: nobody matched is a problem',
    rg_import_plan_row(['purpose' => 'staff_updates'] + $base)['reason'], 'No member of staff matches this row.');
check('staff updates: an applicant is not staff',
    rg_import_plan_row(['purpose' => 'staff_updates', 'person' => ['has_worker' => false, 'in_scope' => true]] + $base)['outcome'], 'problem');
check('a known applicant applying again is a new application',
    rg_import_plan_row(['person' => ['has_worker' => false, 'in_scope' => true]] + $base)['outcome'], 'new');
check('a new application needs an email', rg_import_plan_row(['has_email' => false] + $base)['outcome'], 'problem');
check('and a service', rg_import_plan_row(['service' => null] + $base)['outcome'], 'problem');
check('a scoped account cannot create in another service',
    rg_import_plan_row(['scope' => 'security'] + $base)['reason'], 'The service is outside your service.');
check('but can in its own', rg_import_plan_row(['scope' => 'care'] + $base)['outcome'], 'new');

// ---------------------------------------------------------------------------
group('owner visibility: who sees which part of Today');

$all = ['compliance' => true, 'payroll' => true];
$fin = ['finance' => true];
check('an admin sees every group',
    array_values(array_unique(rg_action_centre_access('admin', null, $all, $fin))), [true]);
$scopedAccess = rg_action_centre_access('manager', 'security', $all, $fin);
check('a scoped manager sees the rota', $scopedAccess['rota'], true);
check('but not system counts, which carry no service', $scopedAccess['system'], false);
check('nor unlinked training records', $scopedAccess['training'], false);
check('nor deletion decisions', $scopedAccess['governance'], false);
$editorAccess = rg_action_centre_access('editor', null, ['compliance' => false, 'payroll' => false], $fin);
check('an editor sees no compliance items', $editorAccess['compliance'], false);
check('nor recruitment', $editorAccess['recruitment'], false);
check('nor hours', $editorAccess['payroll'], false);
check('an editor may see unlinked training records', $editorAccess['training'], true);
check('finance needs the switch on', rg_action_centre_access('admin', null, $all, ['finance' => false])['finance'], false);
check('payroll needs the zone', rg_action_centre_access('manager', null, ['payroll' => false], $fin)['payroll'], false);
check('a trainer sees recruitment', rg_action_centre_access('trainer', null, [], [])['recruitment'], true);

group('owner visibility: DBS level from a label');
check('enhanced', rg_dbs_level('Enhanced DBS'), 'Enhanced');
check('enhanced with barred list', rg_dbs_level('enhanced + barred list'), 'Enhanced with barred list');
check('standard', rg_dbs_level('Standard'), 'Standard');
check('basic', rg_dbs_level('basic disclosure'), 'Basic');
check('a status word is not a level', rg_dbs_level('valid'), null);
check('nothing is not a level', rg_dbs_level(null), null);

group('owner visibility: CSV');
check('text is quoted', rg_csv_cell('Ama'), '"Ama"');
check('quotes are doubled', rg_csv_cell('a "b"'), '"a ""b"""');
check('a formula is neutralised', rg_csv_cell('=HYPERLINK("x")'), '"\'=HYPERLINK(""x"")"');
check('so is a leading at sign', rg_csv_cell('@SUM(A1)'), '"\'@SUM(A1)"');
check('a negative number stays a number', rg_csv_cell(-3), '-3');
check('null is empty', rg_csv_cell(null), '');
check('only the listed columns are written',
    rg_csv(['name' => 'Name', 'n' => 'Count'], [['name' => 'A', 'n' => 2, 'secret' => 'x']]),
    "\"Name\",\"Count\"\r\n\"A\",2\r\n");

group('owner visibility: weeks and scheduled jobs');
check('a Thursday belongs to its Monday', rg_week_start('2026-10-08'), '2026-10-05');
check('a Monday is its own week', rg_week_start('2026-10-05 09:00:00'), '2026-10-05');
check('a Sunday belongs to the Monday before', rg_week_start('2026-10-11'), '2026-10-05');
check('expiry bands', array_map('rg_expiry_band', [-1, 0, 30, 31, 60, 61, 90, 91, null]),
    ['expired', '30', '30', '60', '60', '90', '90', null, null]);
$cronNow = (int) strtotime('2026-10-09 12:00:00 UTC');
check('never recorded is unknown', rg_cron_state(null, 24, $cronNow), 'unknown');
check('a daily job that ran this morning is fine', rg_cron_state('2026-10-09 02:00:00', 24, $cronNow), 'ok');
check('a daily job silent for two days is late', rg_cron_state('2026-10-07 02:00:00', 24, $cronNow), 'late');

group('credential labels');
check('ampersand reads as and', rg_label_key('Moving & Handling'), rg_label_key('Moving and handling'));
check('case and spacing do not matter', rg_label_key('  Moving   AND handling '), 'moving and handling');
check('an ampersand with no spaces', rg_label_key('Health&Safety'), 'health and safety');
check('a different credential stays different', rg_label_key('Manual handling') === rg_label_key('Moving and handling'), false);
check('null is empty', rg_label_key(null), '');

group('restore rehearsal');
check('identical counts pass', rg_restore_differences(['a' => 1, 'b' => 0], ['b' => 0, 'a' => 1]), []);
check('a short table is named', rg_restore_differences(['people' => 10], ['people' => 9]), ['people: 10 rows, restored copy has 9']);
check('a missing table is named', rg_restore_differences(['files' => 2], []), ['files: missing from the restored copy']);
check('an extra table is named', rg_restore_differences([], ['stray' => 1]), ['stray: only in the restored copy']);
check('a restore test 30 days ago is current', rg_cron_state('2026-09-09 12:00:00', RG_RESTORE_TEST_EVERY_HOURS, $cronNow), 'ok');
check('one 200 days ago is overdue', rg_cron_state('2026-03-23 12:00:00', RG_RESTORE_TEST_EVERY_HOURS, $cronNow), 'late');

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
