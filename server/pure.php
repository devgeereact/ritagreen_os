<?php
// Pure domain logic: no database, no config, no output, no globals.
//
// Everything here is a function of its arguments alone, which is what makes it
// testable — server/tests/run.php includes this file directly, with no database
// and no config.php. lib.php requires it, so every endpoint has it available.
//
// Anything that needs $conn, a constant from config.php, or the network belongs
// in lib.php instead. Keep this file boring.
//
// Clocks are injectable. Every date function takes an optional "today" or "now"
// so a test can pin time instead of hoping the suite does not run at midnight.

// ---------------------------------------------------------------- credentials

/** Days before expiry at which a credential starts reading as "expiring". */
const RG_EXPIRING_WINDOW_DAYS = 30;


/**
 * Whole days from $today until $date. Negative once the date has passed.
 * Null when there is no date to measure.
 */
function rg_days_until(?string $date, ?string $today = null): ?int
{
    if (!$date) return null;
    $from = strtotime($today ?? date('Y-m-d'));
    $to = strtotime($date);
    if ($from === false || $to === false) return null;
    return (int) floor(($to - $from) / 86400);
}

/**
 * Classify an expiry date into a status and the days remaining.
 *
 * 'unknown' is deliberately distinct from 'expired': a credential with no date
 * recorded is a gap in the evidence, not a lapsed credential, and the two need
 * different chasing.
 *
 * @return array{status: string, days: int|null} status ∈ valid|expiring|expired|unknown
 */
function rg_classify(?string $expiry, ?string $today = null): array
{
    $days = rg_days_until($expiry, $today);
    if ($days === null) return ['status' => 'unknown', 'days' => null];
    if ($days < 0) return ['status' => 'expired', 'days' => $days];
    if ($days <= RG_EXPIRING_WINDOW_DAYS) return ['status' => 'expiring', 'days' => $days];
    return ['status' => 'valid', 'days' => $days];
}

/**
 * Which reminder tier a credential falls into, or null when it is far enough
 * out that nothing needs sending yet.
 *
 * Tiers are bands, not exact days: a cron run that misses a day must not skip a
 * tier, so each tier covers everything up to its threshold and the dedupe table
 * stops it firing twice.
 *
 * @return string|null 'expired' | 'd30' | 'd60' | 'd90' | null
 */
function rg_reminder_tier(?int $days): ?string
{
    if ($days === null) return null;
    if ($days < 0) return 'expired';
    if ($days <= 30) return 'd30';
    if ($days <= 60) return 'd60';
    if ($days <= 90) return 'd90';
    return null;
}

// --------------------------------------------------------------------- access

/**
 * Is this an internal staff account, as opposed to a worker or client login?
 *
 * Role alone cannot answer this. A worker's login is an ordinary account with
 * users.worker_id set, and a client portal login has users.client_id set, so
 * either may carry a role such as 'employee'. Anything gated on "internal
 * staff" must check the link, not just the role.
 *
 * Privileged roles are staff unconditionally, so a manager who also holds a
 * worker record does not lock themselves out of the office.
 *
 * Mirrored in TypeScript by isStaff() in src/lib/access.ts.
 */
function is_staff(array $user): bool
{
    if (in_array($user['role'] ?? '', ['super_admin', 'admin', 'manager'], true)) return true;
    if (($user['role'] ?? '') === 'client') return false;
    if (!empty($user['client_id']) || !empty($user['worker_id'])) return false;
    return true;
}

/** The worker record this login belongs to, or null for a staff or client account. */
function linked_worker_id(array $user): ?int
{
    return empty($user['worker_id']) ? null : (int) $user['worker_id'];
}

/**
 * May this account read this stored file?
 *
 * The document vault is one endpoint holding two very different things: company
 * documents (policies, RAMS, marketing collateral) and a named person's records
 * (identity scans, DBS evidence, signed contracts). Being allowed to open the
 * vault is not the same as being allowed to open everything in it, and until
 * this existed the only check was the endpoint-level role list -- so an editor
 * could download an identity document by guessing its id.
 *
 * A file is personal if it is filed against a person, a worker or a client, or
 * if its category says so. Everything else is company material.
 */
function rg_file_readable(array $file, array $user): bool
{
    if (in_array($user['role'] ?? '', ['super_admin', 'admin', 'manager'], true)) return true;

    $personal = in_array((string) ($file['category'] ?? ''), ['id', 'contract', 'certificate'], true)
        || !empty($file['person_id'])
        || !empty($file['worker_id'])
        || !empty($file['client_id']);

    return !$personal;
}

/**
 * Round a location to a precision proportionate to why it was collected.
 *
 * A clock-in needs to show somebody was at the site; it does not need to say
 * where in the building they stood. Three decimal places is roughly 110 m, which
 * answers the operational question and stops the record being a precise movement
 * history of a worker's day. A panic alert is the opposite case -- somebody has
 * to be found -- so it keeps full precision.
 */
function rg_round_coord(?float $value, int $places): ?float
{
    if ($value === null) return null;
    return round($value, $places);
}

/** Decimal places to store for a field event of this kind. */
function rg_coord_places(string $kind): int
{
    return $kind === 'panic' ? 6 : 3;
}

/**
 * The unsubscribe token for an address.
 *
 * An unsubscribe link has to work from an email client with no session, so the
 * address is in the URL and the token proves the link came from us. HMAC over a
 * server-side secret, rather than a stored random token, because it needs no
 * table and no cleanup, and it cannot be enumerated.
 *
 * The address is lower-cased first so that the link in an email addressed to
 * Sam@Example.com still validates against sam@example.com.
 */
function rg_unsubscribe_token(string $email, string $secret): string
{
    return hash_hmac('sha256', 'unsubscribe:' . mb_strtolower(trim($email)), $secret);
}

/**
 * Is a record past its retention period?
 *
 * Pure, so the arithmetic that decides whether somebody's record is disposed of
 * can be tested without a database and read without running anything.
 *
 * A NULL period is not zero. It means the business has not set one yet, and the
 * answer is "no, and do not ask again" — a policy with no period must never be
 * read as "delete immediately", which is the failure mode that would turn an
 * unfinished configuration into data loss.
 */
function rg_retention_due(?string $anchorDate, ?int $retainMonths, string $today): bool
{
    if ($retainMonths === null || $retainMonths <= 0) return false;
    if ($anchorDate === null || $anchorDate === '' || $anchorDate === '0000-00-00') return false;
    $anchor = strtotime($anchorDate);
    if ($anchor === false) return false;
    $due = strtotime("+$retainMonths months", $anchor);
    return $due !== false && $due <= strtotime($today);
}

/**
 * What the sweep should do with a record that is past its period.
 *
 * 'review' raises it for a person to look at. 'archive' hides it but keeps it.
 * 'delete' is a request, not an act: it goes to the approval workflow. Anything
 * unrecognised falls back to 'review', because the safe reading of a value we
 * do not understand is "ask somebody".
 */
function rg_retention_action(string $configured): string
{
    return in_array($configured, ['review', 'archive', 'delete'], true) ? $configured : 'review';
}

/** Constant-time check of an unsubscribe token. */
function rg_unsubscribe_valid(string $email, string $token, string $secret): bool
{
    if ($token === '' || $secret === '') return false;
    return hash_equals(rg_unsubscribe_token($email, $secret), $token);
}

// ---------------------------------------------------------------- field state

/**
 * Derive an on-the-ground status for a shift from its logged events and the clock.
 *
 * Order matters: a clocked-out shift is complete regardless of how late it
 * started, and a clocked-in shift can never read as a no-show.
 *
 * @param string[] $kinds shift_events.kind values recorded against the shift
 */
function rg_field_status(array $kinds, string $startsAt, string $endsAt, ?int $now = null): string
{
    $now ??= time();
    $start = strtotime($startsAt);
    $end = strtotime($endsAt);
    if (in_array('clock_out', $kinds, true)) return 'completed';
    if (in_array('clock_in', $kinds, true)) return $now > $end + 900 ? 'overrunning' : 'on_site';
    if ($now > $start + 1800) return 'no_show';   // 30 minutes past start, never clocked in
    if ($now > $start) return 'late';
    return 'scheduled';
}

/**
 * Worked minutes for a shift recorded as a start date plus two clock times.
 *
 * work_date is the date the shift STARTS. An end EARLIER than the start means
 * the shift crosses midnight -- a waking night, a night guard -- so it belongs
 * to the following day, not to an earlier moment on the same one. Treating that
 * as an error meant a business that runs nights could not record a night, while
 * the shifts table had stored overnight spans all along.
 *
 * Equal times are ambiguous: nothing distinguishes a mistake from a full 24-hour
 * span, so this refuses to guess and returns 0. Unparseable times return 0 too.
 * The caller decides what a zero or an implausible duration means.
 *
 * Computed in UTC, explicitly, because the rest of the system is: schema.sql
 * sets time_zone = '+00:00' and every stored timestamp is UTC. Relying on the
 * host's date.timezone instead would make the same entry worth different money
 * on a laptop and on cPanel. One consequence worth stating plainly: a night
 * spanning a British Summer Time change is NOT adjusted for it, so 23:00-07:00
 * over the spring change counts as 8 hours, not the 7 the worker was on site.
 */
function rg_shift_minutes(string $workDate, string $startTime, string $endTime): int
{
    $utc = new DateTimeZone('UTC');
    $start = date_create_immutable("$workDate $startTime", $utc);
    $end = date_create_immutable("$workDate $endTime", $utc);
    if ($start === false || $end === false) return 0;
    if ($end == $start) return 0;
    if ($end < $start) $end = $end->modify('+1 day');
    return (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
}

/**
 * Remove an unpaid break from a shift's premium segments.
 *
 * Nothing recorded a break at all, so gross hours were paid door to door and a
 * twelve-hour shift with an unpaid half hour was paid twelve hours.
 *
 * The break is taken proportionally across the bands rather than from one end,
 * because nothing records WHEN it was taken. For a shift sitting in a single
 * band -- most of them -- proportional and exact are the same answer. For a
 * shift that crosses one, it splits the deduction the way the shift itself
 * splits, which is the fairest thing available without asking the worker to
 * record break times as well.
 *
 * A break at or beyond the whole shift leaves nothing payable rather than going
 * negative; the caller decides whether that is an error.
 *
 * @param list<array{keys:list<string>,minutes:int}> $segments
 * @return list<array{keys:list<string>,minutes:float}>
 */
function rg_deduct_break(array $segments, int $breakMinutes): array
{
    if ($breakMinutes <= 0) return $segments;
    $total = 0;
    foreach ($segments as $seg) $total += $seg['minutes'];
    if ($total <= 0) return [];
    if ($breakMinutes >= $total) return [];

    $factor = ($total - $breakMinutes) / $total;
    $out = [];
    foreach ($segments as $seg) {
        $out[] = ['keys' => $seg['keys'], 'minutes' => $seg['minutes'] * $factor];
    }
    return $out;
}

/**
 * Is there an unacknowledged panic alert on this shift?
 *
 * The board derived its panic flag from "is there a panic event", so once one
 * was raised the banner stayed up all day with nothing that could clear it --
 * and the next alert, on another shift, arrived into a board already shouting.
 *
 * Order matters, not counts: a shift can be raised, acknowledged, and raised
 * again, and the second one is live. Takes the kinds in the order they were
 * recorded and reports whether the last panic-related event was a panic.
 *
 * @param string[] $kinds shift_events.kind values, oldest first
 */
function rg_panic_open(array $kinds): bool
{
    $open = false;
    foreach ($kinds as $k) {
        if ($k === 'panic') $open = true;
        elseif ($k === 'panic_ack') $open = false;
    }
    return $open;
}

// ------------------------------------------------------------- bank holidays

/**
 * Easter Sunday for a year, as YYYY-MM-DD. Anonymous Gregorian algorithm.
 *
 * Not easter_days(): that needs ext-calendar, which cPanel may not have, and a
 * pay run must not depend on which PHP extensions a host happened to compile.
 */
function rg_easter_sunday(int $year): string
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * England and Wales bank holidays for a year, as [date => name].
 *
 * The table had eight rows for 2026 and nothing after, so from 2027 every bank
 * holiday would silently have paid flat rate instead of the configured
 * multiplier — a money defect with a date on it and nobody watching for it.
 * Typing future dates into a money table by hand is how that happens again, so
 * the standard set is computed.
 *
 * Computed, not authoritative. `bank_holidays` remains the source of truth,
 * because one-off holidays do happen — the 2022 Jubilee and the 2023 Coronation
 * were both announced, not derived — and those are inserted alongside these.
 *
 * Substitution: a fixed-date holiday falling at a weekend moves to the next
 * weekday not already taken, which is why Christmas on a Saturday pushes Boxing
 * Day to the Tuesday.
 */
function rg_bank_holidays(int $year): array
{
    $easter = strtotime(rg_easter_sunday($year));
    $days = [
        date('Y-m-d', strtotime('-2 day', $easter)) => 'Good Friday',
        date('Y-m-d', strtotime('+1 day', $easter)) => 'Easter Monday',
        date('Y-m-d', strtotime("first monday of may $year")) => 'Early May bank holiday',
        date('Y-m-d', strtotime("last monday of may $year")) => 'Spring bank holiday',
        date('Y-m-d', strtotime("last monday of august $year")) => 'Summer bank holiday',
    ];

    // Fixed dates, each taking the next free weekday if it lands at a weekend.
    //
    // The pair in December is ordered rather than fixed. Normally Christmas Day
    // is placed first and Boxing Day follows it. But when Christmas Day falls on
    // a Sunday, Boxing Day is already on the Monday and keeps it, and Christmas
    // is the one that moves to the Tuesday -- which is what happened in 2022.
    // Placing Christmas first in that year would put the two names on each
    // other's days. Both days are bank holidays either way, so pay is right
    // regardless; the names are not.
    $december = (int) date('N', strtotime("$year-12-25")) === 7
        ? [["$year-12-26", 'Boxing Day'], ["$year-12-25", 'Christmas Day']]
        : [["$year-12-25", 'Christmas Day'], ["$year-12-26", 'Boxing Day']];

    foreach (array_merge([["$year-01-01", "New Year's Day"]], $december) as [$date, $name]) {
        $ts = strtotime($date);
        $substituted = false;
        while ((int) date('N', $ts) >= 6 || isset($days[date('Y-m-d', $ts)])) {
            $ts = strtotime('+1 day', $ts);
            $substituted = true;
        }
        $days[date('Y-m-d', $ts)] = $name . ($substituted ? ' (substitute)' : '');
    }

    ksort($days);
    return $days;
}

/**
 * Split a worked interval into ordered premium segments, at minute granularity.
 *
 * Premiums used to be decided for a whole entry from its START hour and its
 * work_date alone. That was defensible while a shift could not cross midnight.
 * It stopped being defensible the moment overnight entries were allowed:
 *
 *   Friday 20:00 to Saturday 04:00 — night on all eight hours, weekend on none,
 *   even though six of those hours are Saturday.
 *   05:00 to 13:00 — night on all eight hours, because it started at five, when
 *   only the first hour is inside the night window.
 *   18:00 to 02:00 — no night premium at all, because it started at six.
 *
 * Each minute carries every category in force at that moment, not one: a
 * Saturday at 22:00 is both weekend and night. Which of them pays is not decided
 * here, because that depends on the configured multipliers and this function
 * does not know them — pay_entry() takes the dearest, which is what the previous
 * behaviour did and keeps the choice in configuration rather than in code.
 *
 * Consecutive minutes carrying the same categories are coalesced, and the
 * segments come back in time order so overtime can be taken from the end of the
 * shift.
 *
 * Minute granularity rather than boundary arithmetic: an entry is at most 16
 * hours, so this is under a thousand iterations, and there are no off-by-one
 * boundary cases to get wrong.
 *
 * @param array<string,bool> $bankHols keyed YYYY-MM-DD
 * @return list<array{keys:list<string>,minutes:int}>
 */
function rg_premium_segments(string $workDate, string $startTime, int $minutes, array $bankHols): array
{
    if ($minutes <= 0) return [];
    $start = strtotime("$workDate $startTime");
    if ($start === false) return [];

    $segments = [];
    for ($m = 0; $m < $minutes; $m++) {
        $at = $start + $m * 60;
        $day = date('Y-m-d', $at);
        $hour = (int) date('G', $at);
        $dow = (int) date('N', $at);

        $keys = [];
        if (isset($bankHols[$day])) $keys[] = 'bank_holiday';
        if ($dow >= 6) $keys[] = 'weekend';
        if ($hour >= 20 || $hour < 6) $keys[] = 'night';

        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last]['keys'] === $keys) $segments[$last]['minutes']++;
        else $segments[] = ['keys' => $keys, 'minutes' => 1];
    }
    return $segments;
}

// ---------------------------------------------------------- compliance engine

/**
 * Requirement weights. A requirement's weight decides what its absence means,
 * and the same requirement can carry different weights in different services —
 * first aid may be mandatory for Care and recommended for Cleaning. That is
 * configuration owned by the business, not a constant in code.
 */
const RG_MANDATORY = 'mandatory';
const RG_RECOMMENDED = 'recommended';
const RG_OPTIONAL = 'optional';

/** Overall verdicts. */
const RG_ELIGIBLE = 'eligible';
const RG_ELIGIBLE_WITH_WARNINGS = 'eligible_with_warnings';
const RG_NOT_ELIGIBLE = 'not_eligible';

/**
 * Assess one requirement against the evidence held for a person.
 *
 * A rule is:
 *   ['key' => 'sia_licence', 'label' => 'SIA licence',
 *    'weight' => RG_MANDATORY, 'expires' => true]
 *
 * Evidence for that key, or null when nothing is on file:
 *   ['expiry' => '2026-09-01', 'verified' => true]
 *
 * @return array{key:string,label:string,weight:string,state:string,reason:?string,days:?int}
 *         state ∈ met | expiring | expired | missing | undated | unverified
 */
function rg_assess_requirement(array $rule, ?array $evidence, ?string $today = null): array
{
    $key = $rule['key'];
    $label = $rule['label'] ?? $key;
    $weight = $rule['weight'] ?? RG_MANDATORY;
    $expires = $rule['expires'] ?? true;
    $needsVerifying = $rule['verify'] ?? false;

    $out = static fn(string $state, ?string $reason, ?int $days = null): array => [
        'key' => $key, 'label' => $label, 'weight' => $weight,
        'state' => $state, 'reason' => $reason, 'days' => $days,
    ];

    // Nothing on file at all. Distinct from expired: one is a gap in the
    // evidence, the other is a lapse, and they are chased differently.
    if ($evidence === null) {
        return $out('missing', "$label: no evidence on file");
    }

    // Present, but nobody has checked it. An unverified document is a claim.
    if ($needsVerifying && empty($evidence['verified'])) {
        return $out('unverified', "$label: on file but not yet verified");
    }

    if (!$expires) {
        return $out('met', null);
    }

    $expiry = $evidence['expiry'] ?? null;
    $class = rg_classify($expiry, $today);

    switch ($class['status']) {
        case 'unknown':
            // A credential that should carry an expiry but does not is
            // incomplete evidence, not valid evidence.
            return $out('undated', "$label: no expiry date recorded");
        case 'expired':
            $days = abs((int) $class['days']);
            $ago = $days === 1 ? 'yesterday' : "$days days ago";
            return $out('expired', "$label: expired $ago", $class['days']);
        case 'expiring':
            $days = (int) $class['days'];
            $when = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in $days days");
            return $out('expiring', "$label: expires $when", $days);
        default:
            return $out('met', null, $class['days']);
    }
}

/** Does this state mean the requirement is not satisfied? */
function rg_state_is_unmet(string $state): bool
{
    return in_array($state, ['missing', 'expired', 'undated', 'unverified'], true);
}

/**
 * Decide whether a person may work in a service.
 *
 * This is the answer the whole system exists to give, and it is CALCULATED —
 * never a field somebody sets. A mandatory requirement that is unmet blocks
 * deployment and says why; a mandatory requirement inside its expiry window
 * warns without blocking, because the person may still work today; a
 * recommended requirement never blocks.
 *
 * $rules    list of requirement definitions for this service and role
 * $evidence map of requirement key => evidence array, or absent when nothing
 *           is on file
 *
 * @return array{
 *   status:string, eligible:bool, score:int,
 *   blocking:array<int,array>, warnings:array<int,array>, items:array<int,array>
 * }
 */
function rg_evaluate_eligibility(array $rules, array $evidence, ?string $today = null): array
{
    $items = [];
    $blocking = [];
    $warnings = [];
    $weightPoints = [RG_MANDATORY => 2, RG_RECOMMENDED => 1, RG_OPTIONAL => 0];
    $earned = 0;
    $possible = 0;

    foreach ($rules as $rule) {
        $assessed = rg_assess_requirement($rule, $evidence[$rule['key']] ?? null, $today);
        $items[] = $assessed;

        $points = $weightPoints[$assessed['weight']] ?? 0;
        $possible += $points;

        $unmet = rg_state_is_unmet($assessed['state']);
        if (!$unmet) $earned += $points;

        if ($assessed['weight'] === RG_MANDATORY) {
            if ($unmet) {
                $blocking[] = $assessed;
            } elseif ($assessed['state'] === 'expiring') {
                $warnings[] = $assessed;
            }
        } elseif ($assessed['weight'] === RG_RECOMMENDED && ($unmet || $assessed['state'] === 'expiring')) {
            $warnings[] = $assessed;
        }
    }

    // An empty rule set means nothing has been configured for this service yet.
    // Report 0, not 100: "no requirements defined" must never read as "fully
    // compliant" on a dashboard.
    $score = $possible > 0 ? (int) round($earned / $possible * 100) : 0;

    if ($blocking) {
        $status = RG_NOT_ELIGIBLE;
    } elseif ($warnings) {
        $status = RG_ELIGIBLE_WITH_WARNINGS;
    } else {
        $status = RG_ELIGIBLE;
    }

    return [
        'status' => $status,
        'eligible' => $blocking === [],
        'score' => $score,
        'blocking' => $blocking,
        'warnings' => $warnings,
        'items' => $items,
    ];
}

/**
 * One line explaining a verdict, for a refusal message or an audit entry.
 * Never returns an empty string: a refusal without a reason is not actionable.
 */
function rg_eligibility_reason(array $verdict): string
{
    if ($verdict['blocking']) {
        $reasons = array_column($verdict['blocking'], 'reason');
        return implode('; ', $reasons);
    }
    if ($verdict['warnings']) {
        return implode('; ', array_column($verdict['warnings'], 'reason'));
    }
    return 'All requirements met.';
}

// ------------------------------------------------------------ person matching

/**
 * Email addresses the system generated rather than collected.
 *
 * ats.php fabricated `<name>@learner.invalid` when an Academy learner had no
 * address. Those are not identities and must never be used to match two records
 * together — every learner without an email would otherwise collide on a name.
 */
const RG_SYNTHETIC_EMAIL_DOMAINS = ['learner.invalid', 'example.invalid', 'local.invalid'];

/** Lowercased, trimmed email, or null when it is absent or system-generated. */
function rg_normalise_email(?string $email): ?string
{
    if (!$email) return null;
    $e = strtolower(trim($email));
    if ($e === '' || !str_contains($e, '@')) return null;
    $domain = substr($e, strrpos($e, '@') + 1);
    if (in_array($domain, RG_SYNTHETIC_EMAIL_DOMAINS, true)) return null;
    return $e;
}

/**
 * A phone number reduced to a comparable form, for matching only.
 *
 * Deliberately not E.164: normalise_phone() in lib.php produces a number to
 * dial, this produces a key to compare. '07700 900123', '+447700900123' and
 * '00447700900123' are one person and must collapse to one key.
 *
 * Returns null for anything too short to identify somebody.
 */
function rg_phone_key(?string $phone): ?string
{
    if (!$phone) return null;
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') return null;
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);       // 0044…
    if (str_starts_with($digits, '44')) $digits = '0' . substr($digits, 2); // 44…  -> 0…
    // A UK mobile or landline is 11 digits with the leading zero. Anything
    // shorter than 7 is an extension or a fragment, not an identity.
    return strlen($digits) >= 7 ? $digits : null;
}

/**
 * The key used to decide that two records describe the same human.
 *
 * Email first, because it is the strongest signal we hold; phone second. A
 * record with neither cannot be matched automatically and must be reviewed by a
 * person — never merged on a name, which would collapse every John Smith into
 * one worker.
 *
 * @return array{key: ?string, basis: string} basis ∈ email | phone | none
 */
function rg_person_match_key(array $row): array
{
    $email = rg_normalise_email($row['email'] ?? null);
    if ($email !== null) return ['key' => 'e:' . $email, 'basis' => 'email'];

    $phone = rg_phone_key($row['phone'] ?? null);
    if ($phone !== null) return ['key' => 'p:' . $phone, 'basis' => 'phone'];

    return ['key' => null, 'basis' => 'none'];
}

/**
 * Group source records into candidate people.
 *
 * Two things join records together, and both must be honoured:
 *
 *  1. An EXPLICIT link the database already records — users.worker_id and
 *     applicants.worker_id. That is a fact somebody entered, not a guess, and it
 *     outranks any heuristic. Supply it as `link_key` on the row.
 *  2. A shared match key — email, else phone. See rg_person_match_key().
 *
 * These are unioned rather than applied in sequence, because a record can be
 * joined to one group by its link and to another by its email, and those two
 * groups are then the same person. A first version of this matched only on
 * email and phone, and split a worker from their own login.
 *
 * Records with neither a link nor a key are returned separately, to become one
 * person each rather than be guessed at — a wrong merge of two employment
 * histories is far harder to undo than an unmerged duplicate.
 *
 * @param array<int,array> $rows each needing: source, id, name, email, phone, and
 *                               optionally link_key
 * @return array{groups: array<string,array<int,array>>, unmatchable: array<int,array>}
 */
function rg_group_people(array $rows): array
{
    // Union-find over the keys each row carries.
    $parent = [];
    $find = static function (string $x) use (&$parent, &$find): string {
        while (($parent[$x] ?? $x) !== $x) {
            $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
            $x = $parent[$x];
        }
        return $x;
    };
    $union = static function (string $a, string $b) use (&$parent, $find): void {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra !== $rb) $parent[$ra] = $rb;
    };

    $keyed = [];
    $unmatchable = [];

    foreach ($rows as $row) {
        $keys = [];
        if (!empty($row['link_key'])) $keys[] = (string) $row['link_key'];
        $match = rg_person_match_key($row);
        if ($match['key'] !== null) $keys[] = $match['key'];

        if (!$keys) {
            $unmatchable[] = $row;
            continue;
        }

        foreach ($keys as $k) $parent[$k] = $parent[$k] ?? $k;
        for ($i = 1, $n = count($keys); $i < $n; $i++) $union($keys[0], $keys[$i]);

        $keyed[] = ['row' => $row, 'key' => $keys[0]];
    }

    $groups = [];
    foreach ($keyed as $entry) {
        $groups[$find($entry['key'])][] = $entry['row'];
    }

    return ['groups' => $groups, 'unmatchable' => $unmatchable];
}

/**
 * Do the records in a group agree on who this is?
 *
 * Sharing a phone number is not proof: a couple, or a household, may share a
 * landline. When the names in a group disagree, the group is flagged for review
 * instead of being merged.
 */
function rg_group_is_ambiguous(array $group): bool
{
    if (count($group) < 2) return false;
    $names = [];
    foreach ($group as $row) {
        $n = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($row['name'] ?? ''))));
        if ($n !== '') $names[$n] = true;
    }
    return count($names) > 1;
}

// ------------------------------------------------------------------- email

/**
 * Substitute {{placeholders}} in a template.
 *
 * An unknown placeholder is left in place rather than silently emptied. An email
 * that goes out reading "Hi ," looks like a mistake to the recipient; one
 * reading "Hi {{first_name}}," looks like a mistake to whoever sent it, which is
 * the person who can fix it.
 *
 * @param array<string,string|int|null> $values
 */
function rg_render_template(string $text, array $values): string
{
    return preg_replace_callback(
        '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
        static function (array $m) use ($values): string {
            $key = strtolower($m[1]);
            foreach ($values as $k => $v) {
                if (strtolower((string) $k) === $key && $v !== null && $v !== '') {
                    return (string) $v;
                }
            }
            return $m[0];
        },
        $text
    ) ?? $text;
}

/** Which placeholders a rendered string still has unfilled. */
function rg_unfilled_placeholders(string $text): array
{
    preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);
    return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
}

/**
 * A usable first name from a full name, for addressing somebody.
 * Falls back to the whole string rather than to nothing.
 */
function rg_first_name(?string $fullName): string
{
    $name = trim((string) $fullName);
    if ($name === '') return 'there';
    $parts = preg_split('/\s+/', $name);
    return $parts[0] !== '' ? $parts[0] : $name;
}

// -------------------------------------------------------------- leave & absence

/**
 * Which leave year a date falls in, as "2026/27".
 *
 * $startMonth is the month the leave year begins (4 = April, the common UK
 * choice; 1 = January). A date before that month belongs to the year that
 * started the previous January.
 */
function rg_leave_year(string $date, int $startMonth = 4): string
{
    $ts = strtotime($date);
    if ($ts === false) return '';
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $startYear = $month >= $startMonth ? $year : $year - 1;
    // A leave year starting in January is one calendar year, not a straddle.
    if ($startMonth === 1) return (string) $startYear;
    return $startYear . '/' . substr((string) ($startYear + 1), 2);
}

/**
 * A holiday balance from an entitlement and the records against it.
 *
 * Counted in HOURS. Shift work is not made of whole days, and a balance in days
 * is wrong for anyone on variable hours.
 *
 * Only ANNUAL leave counts against the entitlement -- sickness and unpaid leave
 * are recorded but do not consume holiday. Requested leave is shown separately
 * from taken: it is not yet approved, but somebody planning cover needs to see
 * it coming.
 *
 * @param array<int,array{kind:string,status:string,hours:float|string,carried_over?:int|bool,in_lieu?:int|bool}> $records
 * @return array{entitlement:float,carried_in:float,taken:float,approved:float,requested:float,paid_in_lieu:float,remaining:float}
 */
function rg_leave_balance(?float $entitlementHours, array $records): array
{
    // No entitlement recorded is NOT the same as an entitlement of zero. Without
    // this, somebody whose contracted holiday was never entered shows a large
    // negative balance, which reads as "overdrawn" when it means "nobody has set
    // this up". Same distinction as unknown versus expired in compliance.
    $entitlementKnown = $entitlementHours !== null && $entitlementHours > 0;
    $entitlementHours = (float) ($entitlementHours ?? 0);
    $carried = 0.0;
    $taken = 0.0;
    $approved = 0.0;
    $requested = 0.0;
    $inLieu = 0.0;

    foreach ($records as $r) {
        if (($r['kind'] ?? '') !== 'annual') continue;
        $hours = (float) ($r['hours'] ?? 0);
        $status = $r['status'] ?? '';

        if (!empty($r['carried_over'])) {
            $carried += $hours;
            continue;
        }
        if (!empty($r['in_lieu'])) {
            // Paid instead of taken: it still consumes the entitlement.
            $inLieu += $hours;
            continue;
        }
        if ($status === 'taken') $taken += $hours;
        elseif ($status === 'approved') $approved += $hours;
        elseif ($status === 'requested') $requested += $hours;
        // declined and cancelled consume nothing.
    }

    $entitlement = $entitlementHours + $carried;
    // Requested leave is NOT deducted: it has not been agreed, and showing a
    // balance already reduced by an unapproved request misleads both sides.
    $remaining = $entitlement - $taken - $approved - $inLieu;

    return [
        'entitlement_known' => $entitlementKnown,
        'entitlement' => round($entitlement, 2),
        'carried_in' => round($carried, 2),
        'taken' => round($taken, 2),
        'approved' => round($approved, 2),
        'requested' => round($requested, 2),
        'paid_in_lieu' => round($inLieu, 2),
        // Meaningless until an entitlement is recorded, so it is null rather
        // than a misleading negative number.
        'remaining' => $entitlementKnown ? round($remaining, 2) : null,
        'used' => round($taken + $approved + $inLieu, 2),
    ];
}

/**
 * Do two leave periods overlap? Inclusive of both end dates, because a day of
 * leave is a whole day.
 */
function rg_leave_overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
{
    return strtotime($aStart) <= strtotime($bEnd) && strtotime($bStart) <= strtotime($aEnd);
}

// -------------------------------------------------------------- form intake

/**
 * Map a form submission onto CMS fields using a configured mapping.
 *
 * Matching is case-insensitive and ignores surrounding whitespace and a trailing
 * asterisk, because Google marks required questions with one and people rename
 * questions with stray spaces. An exact-string join would break on any of that,
 * and a broken mapping is silent: submissions arrive and map to nothing.
 *
 * @param array<string,mixed> $payload question title => answer
 * @param array<int,array{source_field:string,target_field:string,required?:int|bool,value_map?:?string}> $maps
 * @return array{fields:array<string,string>,missing:array<int,string>,unmapped:array<int,string>}
 */
function rg_map_submission(array $payload, array $maps): array
{
    $normalise = static fn(string $s): string => strtolower(trim(rtrim(trim($s), '*')));

    $byQuestion = [];
    foreach ($payload as $question => $answer) {
        $byQuestion[$normalise((string) $question)] = $answer;
    }

    $fields = [];
    $missing = [];
    $used = [];

    foreach ($maps as $map) {
        $target = $map['target_field'] ?? 'ignore';
        $key = $normalise($map['source_field']);
        // Marked used even when ignored: a question somebody deliberately chose
        // not to map is configured, and reporting it as unmapped would train
        // people to ignore the list that exists to catch real gaps.
        $used[$key] = true;
        if ($target === 'ignore') continue;
        $raw = $byQuestion[$key] ?? null;

        // Google sends checkbox answers as arrays.
        if (is_array($raw)) $raw = implode(', ', array_map('strval', $raw));
        $value = $raw === null ? '' : trim((string) $raw);

        if ($value === '') {
            if (!empty($map['required'])) $missing[] = $map['source_field'];
            continue;
        }

        // Optional value translation, e.g. "Security Officer" -> "security".
        if (!empty($map['value_map'])) {
            $translations = json_decode((string) $map['value_map'], true);
            if (is_array($translations)) {
                foreach ($translations as $from => $to) {
                    if ($normalise((string) $from) === $normalise($value)) {
                        $value = (string) $to;
                        break;
                    }
                }
            }
        }

        $fields[$target] = $value;
    }

    // Questions nobody mapped. Reported rather than dropped: a form that gains a
    // question should show up as something to configure, not vanish.
    $unmapped = [];
    foreach ($byQuestion as $question => $answer) {
        if (!isset($used[$question]) && trim((string) (is_array($answer) ? implode(',', $answer) : $answer)) !== '') {
            $unmapped[] = $question;
        }
    }

    return ['fields' => $fields, 'missing' => $missing, 'unmapped' => $unmapped];
}

// ---- Careers, forms directory and company details (section S7) -------------

/**
 * Read an address pasted into the forms directory as a Google Form id.
 *
 * Only the public responder address is accepted:
 *
 *     https://docs.google.com/forms/d/e/<id>/viewform
 *
 * optionally followed by Google's own `?usp=...` sharing parameter. The website
 * builds the iframe from the id alone, so anything else (another host, a
 * look-alike such as docs.google.com.example.net, a google.com/url redirector,
 * a userinfo trick, javascript:, pasted HTML) is refused outright rather than
 * cleaned up. The two near-misses people actually paste, the editor link and the
 * forms.gle short link, get a message that says what to paste instead.
 *
 * @return array{id: ?string, error: ?string}
 */
function rg_parse_google_form(?string $url): array
{
    $url = trim((string) $url);
    if ($url === '') return ['id' => null, 'error' => 'Paste the form\'s address.'];
    if (preg_match('#^https://docs\.google\.com/forms/d/e/([A-Za-z0-9_-]{20,200})/viewform(\?usp=[A-Za-z0-9_]{1,40})?$#', $url, $m)) {
        return ['id' => $m[1], 'error' => null];
    }
    if (preg_match('#^https://forms\.gle/#i', $url)) {
        return ['id' => null, 'error' => 'That is a short link. In Google Forms choose Send, then the link tab, untick "Shorten URL" and paste the full address.'];
    }
    if (preg_match('#^https://docs\.google\.com/forms/d/(?!e/)[^/]+/(edit|viewform)#', $url)) {
        return ['id' => null, 'error' => 'That is the editing address. Paste the address respondents use, from Send, then the link tab.'];
    }
    return ['id' => null, 'error' => 'Only a Google Forms address of the form https://docs.google.com/forms/d/e/.../viewform is accepted.'];
}

/** The id alone, as stored in website_forms.google_form_id: a Google Forms responder id. */
function rg_valid_google_form_id(?string $id): bool
{
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{20,200}$/', $id) === 1;
}

/**
 * A role page address from a title: lower case letters, digits and single
 * hyphens, at most 80 characters, never empty.
 */
function rg_slugify(?string $s, int $max = 80): string
{
    $s = strtolower(trim((string) $s));
    // Fold the accented letters a job title might carry into their plain form,
    // so "Café supervisor" becomes cafe-supervisor rather than caf-supervisor.
    if (function_exists('iconv') && preg_match('/[^\x00-\x7F]/', $s)) {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($folded) && $folded !== '') $s = strtolower($folded);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), '-');
    return $s !== '' ? $s : 'role';
}

/** Is this a slug rg_slugify() could have produced? */
function rg_valid_slug(?string $slug): bool
{
    return is_string($slug) && strlen($slug) <= 80 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

/**
 * May a vacancy's slug still change?
 *
 * Only while it has never been published. Once a role page has been live its
 * address is in search results and social posts, and changing it would break
 * every one of them.
 */
function rg_vacancy_slug_editable(?string $publishedAt): bool
{
    return $publishedAt === null || $publishedAt === '';
}

/** Vacancy states and the moves between them. A published role can be closed and reopened, never sent back to draft. */
const RG_VACANCY_TRANSITIONS = [
    'draft' => ['published', 'closed'],
    'published' => ['closed'],
    'closed' => ['published'],
];

function rg_vacancy_transition_ok(string $from, string $to): bool
{
    if ($from === $to) return true;
    return in_array($to, RG_VACANCY_TRANSITIONS[$from] ?? [], true);
}

/**
 * The checks a role in this service will need, as shown on the role page.
 *
 * From the 2026 plan, file 01 ("Checks the system must hold"). A prefill, not a
 * rule: the office edits it per role, and the compliance engine, not this text,
 * decides who may work.
 */
function rg_vacancy_default_checks(string $service): string
{
    $checks = [
        'care' => [
            'Right to work in the UK',
            'Enhanced DBS check (adult and/or child workforce, as the role requires)',
            'Two references',
            'Care Certificate or equivalent',
            'Mandatory training',
            'NMC registration (PIN) for nursing roles',
        ],
        'cleaning' => [
            'Right to work in the UK',
            'Two references',
            'COSHH awareness',
            'DBS check where the site requires it (schools, care settings)',
        ],
        'security' => [
            'Right to work in the UK',
            'SIA licence of the correct type, checked against the public register',
            'Two references',
            'First aid where the site requires it',
        ],
        'academy' => [
            'Right to work in the UK',
            'Trainer qualifications for the courses you will deliver',
            'Two references',
        ],
    ];
    return implode("\n", $checks[$service] ?? $checks['care']);
}

/**
 * How a published role takes applications. One intake path per role (file 06):
 *
 *   form    a linked, active Google Form
 *   closed  a linked form that has been switched off: applications are closed
 *   native  no linked form: the short form that posts to ats.php
 */
function rg_vacancy_apply_route(bool $hasForm, bool $formActive): string
{
    if (!$hasForm) return 'native';
    return $formActive ? 'form' : 'closed';
}

/** Is a form due for review? Never reviewed counts as due. */
function rg_form_review_due(?string $lastReviewed, string $today, int $days = 90): bool
{
    if ($lastReviewed === null || $lastReviewed === '') return true;
    $age = rg_days_until($today, substr($lastReviewed, 0, 10));
    return $age === null || $age >= $days;
}

/** The social networks the company record holds, and the hosts each may point at. */
const RG_SOCIAL_HOSTS = [
    'instagram' => ['instagram.com', 'www.instagram.com'],
    'facebook' => ['facebook.com', 'www.facebook.com', 'm.facebook.com'],
    'linkedin' => ['linkedin.com', 'www.linkedin.com', 'uk.linkedin.com'],
    'tiktok' => ['tiktok.com', 'www.tiktok.com'],
    'whatsapp' => ['wa.me', 'api.whatsapp.com'],
];

/**
 * Is this a link the website may show for that network?
 *
 * https only, the network's own host exactly, a path, and nothing that could
 * carry a script or a login. Empty is allowed and means "do not show it".
 */
function rg_social_url_ok(string $network, ?string $url): bool
{
    $url = trim((string) $url);
    if ($url === '') return true;
    $hosts = RG_SOCIAL_HOSTS[$network] ?? null;
    if ($hosts === null || strlen($url) > 300) return false;
    if (!preg_match('#^https://([a-z0-9.-]+)(/[A-Za-z0-9._~%/+@-]*)(\?[A-Za-z0-9._~%=&+-]*)?$#', $url, $m)) return false;
    if (!in_array(strtolower($m[1]), $hosts, true)) return false;
    return trim($m[2], '/') !== '';
}

/**
 * Read a yes/no answer as written by a human.
 * Returns null for anything that is not clearly one or the other, rather than
 * guessing -- "I have a share code" is not a yes.
 */
function rg_parse_yes_no(?string $value): ?bool
{
    $v = strtolower(trim((string) $value));
    if ($v === '') return null;
    if (in_array($v, ['yes', 'y', 'true', '1', 'yes - uk citizen', 'yes, i do'], true)) return true;
    if (in_array($v, ['no', 'n', 'false', '0', 'not yet'], true)) return false;
    return null;
}

// ------------------------------------------------------------- worker status

/**
 * Derive a worker's real status from the evidence, rather than reading a field
 * somebody had to remember to change.
 *
 * The manual `status` column stays as the INTENT -- what the business means to
 * do with this person. This is what is actually true. Keeping them apart
 * matters: "we intend to employ them and they are not yet cleared" is a real and
 * common state, and collapsing it into one field loses exactly the information
 * the office needs.
 *
 * Order is the whole design. A person who has left is not "not compliant"; a
 * suspended person is not "on leave"; and someone with no eligible service is
 * not active however many boxes are ticked elsewhere.
 *
 * @param array{status?:string,left_on?:?string} $worker
 * @param array{eligible_services?:int,on_leave_today?:bool,onboarding_outstanding?:int,has_contract?:bool} $facts
 * @return array{status:string,reason:string}
 */
function rg_derive_worker_status(array $worker, array $facts, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $manual = $worker['status'] ?? 'onboarding';

    // Having left outranks everything. A leaver is not a compliance problem.
    $leftOn = $worker['left_on'] ?? null;
    if ($leftOn !== null && $leftOn !== '' && strtotime($leftOn) <= strtotime($today)) {
        return ['status' => 'left', 'reason' => 'Left on ' . $leftOn];
    }

    // A deliberate suspension is a decision, not a derivation, so it stands.
    if ($manual === 'suspended') {
        return ['status' => 'suspended', 'reason' => 'Suspended'];
    }
    if ($manual === 'inactive') {
        return ['status' => 'inactive', 'reason' => 'Marked inactive'];
    }

    // Approved leave today, before compliance: somebody on holiday has not
    // become non-compliant, they are simply away.
    if (!empty($facts['on_leave_today'])) {
        return ['status' => 'on_leave', 'reason' => 'On approved leave today'];
    }

    $outstanding = (int) ($facts['onboarding_outstanding'] ?? 0);
    $eligible = (int) ($facts['eligible_services'] ?? 0);
    $hasContract = !empty($facts['has_contract']);

    if ($outstanding > 0) {
        return ['status' => 'onboarding',
                'reason' => $outstanding . ' onboarding ' . ($outstanding === 1 ? 'task' : 'tasks') . ' outstanding'];
    }
    if (!$hasContract) {
        return ['status' => 'pre_employment', 'reason' => 'No signed contract on file'];
    }
    if ($eligible === 0) {
        return ['status' => 'pre_employment', 'reason' => 'Not eligible for any service'];
    }

    return ['status' => 'active',
            'reason' => 'Cleared for ' . $eligible . ' ' . ($eligible === 1 ? 'service' : 'services')];
}

/**
 * Is a record in this account's service scope?
 *
 * $scope is what service_scope() returned: null for an account that sees every
 * service, otherwise the one service it is confined to. $service is the record's
 * own service, which is a division column on applicants, workers and shifts.
 *
 * A record with NO service is out of scope for a scoped account. That is the
 * safe direction: a NULL division cannot be shown to belong to security, and
 * treating "unknown" as "yours" is how a scope becomes decorative.
 *
 * Pure, so the rule is testable without a database -- and it needs to be, because
 * the scope was applied to the ATS list query and to nothing else. A manager
 * confined to security could read another service's candidate by id, reject them,
 * add onboarding tasks and convert them into a worker with a payroll number.
 */
function rg_in_service_scope(?string $scope, ?string $service): bool
{
    if ($scope === null) return true;
    return $service !== null && $service !== '' && $service === $scope;
}

// ---------------------------------------------------------------------------
// Recruitment stages.

/**
 * The pipeline, in order. A fixed list, deliberately: the plan is a company
 * management system, not a workflow designer, and a configurable stage model is
 * the first step towards one.
 *
 * 'onboarding' and 'onboarded' are in the order but are NOT settable through the
 * stage endpoint -- see rg_stage_transition(). The exits are separate because
 * they are reachable from anywhere rather than from one predecessor.
 */
const RG_STAGES = ['applied', 'screened', 'interview', 'vetting', 'offer', 'onboarding', 'onboarded'];
const RG_STAGE_EXITS = ['rejected', 'withdrawn'];

/**
 * Stages that only an action can set, with the action that sets them.
 *
 * The defect: every stage was freely selectable from a dropdown, so a candidate
 * could be moved straight from 'applied' to 'onboarded' without a worker record,
 * a checklist, a verification or an approval existing. Setting a status field is
 * not the same as doing the work it describes, and a stage model where it is
 * describes nothing.
 */
const RG_STAGE_BY_ACTION = [
    'onboarding' => 'starting onboarding, which creates the worker record',
    'onboarded'  => 'approving activation, once onboarding is complete',
];

/**
 * May this stage change be made by hand?
 *
 * Returns ['ok' => bool, 'reason' => string]. The reason is written to be shown
 * to the person who tried, so it says what to do instead.
 *
 * The rules:
 *  - Forward, one step at a time. Skipping forward is how required work gets
 *    bypassed, and it is the only direction where that matters.
 *  - Backward, to any earlier stage. A correction is not a bypass, and
 *    recruitment genuinely does go backwards -- a candidate is re-interviewed,
 *    or an offer is withdrawn and they return to vetting.
 *  - Out to rejected or withdrawn from anywhere, with a reason. They are
 *    different facts: one is the company's decision, the other is the
 *    candidate's, and recording both as 'rejected' loses that.
 *  - Back in from rejected or withdrawn, to any active stage, with a reason.
 *  - Never into a stage an action owns.
 *
 * $hasReason is passed rather than the reason itself so this stays a decision
 * about stages and does not start validating strings.
 */
function rg_stage_transition(string $from, string $to, bool $hasReason = false): array
{
    if ($from === $to) {
        return ['ok' => false, 'reason' => 'That is already the stage.'];
    }

    $known = array_merge(RG_STAGES, RG_STAGE_EXITS);
    if (!in_array($to, $known, true)) {
        return ['ok' => false, 'reason' => 'That is not a stage.'];
    }
    if (!in_array($from, $known, true)) {
        // An unrecognised current stage is a data problem, not a decision this
        // function should make on the caller's behalf.
        return ['ok' => false, 'reason' => 'This candidate is at a stage that is no longer recognised.'];
    }

    if (isset(RG_STAGE_BY_ACTION[$to])) {
        return ['ok' => false, 'reason' => ucfirst(RG_STAGE_BY_ACTION[$to]) . ' sets this stage. It cannot be set directly.'];
    }

    $leaving = in_array($from, RG_STAGE_EXITS, true);

    // Out of the pipeline.
    if (in_array($to, RG_STAGE_EXITS, true)) {
        if ($leaving) {
            return ['ok' => false, 'reason' => 'This candidate has already left the pipeline.'];
        }
        if (!$hasReason) {
            return ['ok' => false, 'reason' => 'A reason is required when a candidate leaves the pipeline.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    // Back in from an exit.
    if ($leaving) {
        if (!$hasReason) {
            return ['ok' => false, 'reason' => 'A reason is required when a candidate is brought back into the pipeline.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    $fromIndex = array_search($from, RG_STAGES, true);
    $toIndex = array_search($to, RG_STAGES, true);

    if ($toIndex < $fromIndex) {
        if (!$hasReason) {
            return ['ok' => false, 'reason' => 'A reason is required when a candidate is moved back a stage.'];
        }
        return ['ok' => true, 'reason' => ''];
    }

    if ($toIndex > $fromIndex + 1) {
        $next = RG_STAGES[$fromIndex + 1];
        return ['ok' => false, 'reason' => "A candidate moves forward one stage at a time. The next stage is $next."];
    }

    return ['ok' => true, 'reason' => ''];
}

/**
 * May this worker be put on a shift at all, before their credentials are looked at?
 *
 * rg_deployment_check() assessed compliance evidence and nothing else, so a
 * worker who was suspended, had left, was still half way through onboarding, or
 * had never been approved for work passed the gate as long as their DBS and
 * licence were in date. Credentials describe a person; employment state
 * describes whether the company has agreed they work here.
 *
 * Returns ['ok' => bool, 'reason' => string]. The reason is shown to whoever
 * tried to make the booking, so it says which of the several possible things is
 * wrong rather than "not eligible".
 *
 * Order matters. A leaver is not a compliance problem and must not be reported as
 * one; a suspension is a decision and outranks everything below it.
 */
function rg_employment_gate(array $worker, array $facts, ?string $today = null): array
{
    $today ??= date('Y-m-d');

    if (!empty($worker['deleted_at'])) {
        return ['ok' => false, 'reason' => 'This worker record has been archived.'];
    }

    $leftOn = $worker['left_on'] ?? null;
    if ($leftOn !== null && $leftOn !== '' && strtotime((string) $leftOn) <= strtotime($today)) {
        return ['ok' => false, 'reason' => 'This worker left on ' . $leftOn . '.'];
    }

    $status = (string) ($worker['status'] ?? 'onboarding');
    if ($status === 'suspended') {
        return ['ok' => false, 'reason' => 'This worker is suspended.'];
    }
    if ($status === 'inactive') {
        return ['ok' => false, 'reason' => 'This worker is marked inactive.'];
    }

    // Onboarding outstanding. Starting onboarding is not finishing it, and a
    // checklist with work left on it is the plainest statement of that.
    $outstanding = (int) ($facts['onboarding_outstanding'] ?? 0);
    if ($outstanding > 0) {
        return ['ok' => false, 'reason' => $outstanding . ' onboarding '
            . ($outstanding === 1 ? 'task is' : 'tasks are') . ' still outstanding.'];
    }

    // Somebody with the authority has to have said yes. Every box being ticked is
    // not the same fact as an approval, and only the second one is an approval.
    if (empty($worker['approved_at'])) {
        return ['ok' => false, 'reason' => 'This worker has not been approved for work.'];
    }

    return ['ok' => true, 'reason' => ''];
}

// ---------------------------------------------------------------- cron triggers

/**
 * The key a cron caller presented over HTTP, for comparison with ALERTS_KEY.
 *
 * The X-Cron-Key header is preferred: a query string is written to the server
 * access log and a request header is not. `?key=` is still read, because the
 * crontabs already on the server use it, and removing it would silently stop the
 * reminders, the mail queue and the backups.
 *
 * When the header is present it wins outright, even if a query key is also
 * sent. A value that is not a string (`?key[]=x`) reads as no key, so it is
 * refused with a 403 rather than raising a warning.
 *
 * @param array $server $_SERVER, where the header arrives as HTTP_X_CRON_KEY
 * @param array $query  $_GET
 */
function rg_cron_key(array $server, array $query): string
{
    $header = $server['HTTP_X_CRON_KEY'] ?? '';
    if (is_string($header) && $header !== '') return $header;
    $key = $query['key'] ?? '';
    return is_string($key) ? $key : '';
}

/**
 * The feature switches the owner can set in Settings, with the value each one
 * takes when nothing has been stored for it (migration 036 seeds the same).
 *
 * The list is fixed in code on purpose. A switch is a promise that some code
 * reads it, so a name the code does not know is refused rather than stored.
 *
 * The defaults preserve what production did before the switches existed, except
 * the AI assistant, which the 2026 plan retires. They also answer for a server
 * whose PHP has been uploaded ahead of migration 036, so that upload does not
 * quietly switch payments, SMS or the inbox off.
 *
 * @return array<string, bool>
 */
function rg_feature_defaults(): array
{
    return [
        'finance' => true,
        'online_payments' => true,
        'sms_whatsapp' => true,
        'inbox' => true,
        'ai_assistant' => false,
    ];
}

/**
 * Is a switch on, given the rows stored for it?
 *
 * @param string $name   a switch name
 * @param array  $stored name => enabled, as read from feature_switches; a name
 *                       with no row falls back to its default
 *
 * An unknown name is off. It can only come from a typo in code, and the safe
 * answer to "is this feature allowed?" for a feature nobody defined is no.
 */
function rg_feature_on(string $name, array $stored): bool
{
    $defaults = rg_feature_defaults();
    if (!array_key_exists($name, $defaults)) return false;
    if (array_key_exists($name, $stored)) return (bool) (int) $stored[$name];
    return $defaults[$name];
}

/**
 * Every switch with its current value, in the defaults' order.
 *
 * @return array<string, bool>
 */
function rg_feature_map(array $stored): array
{
    $out = [];
    foreach (array_keys(rg_feature_defaults()) as $name) {
        $out[$name] = rg_feature_on($name, $stored);
    }
    return $out;
}


// ---------------------------------------------------------------- person profile

/**
 * The verification lifecycle, as the only transitions that make sense.
 *
 * Here rather than in verifications.php so the person profile can say what may
 * happen next to a check without keeping a second copy of the rules. A
 * verification that jumped straight from requested to verified would record an
 * assurance nobody gave.
 */
const RG_VERIFY_TRANSITIONS = [
    'requested'    => ['received', 'rejected'],
    'received'     => ['under_review', 'verified', 'rejected'],
    'under_review' => ['verified', 'rejected'],
    'verified'     => ['expired'],
    'rejected'     => ['requested'],
    'expired'      => ['requested'],
];

/**
 * The employment contract lifecycle, in the order the work actually happens.
 * Shared with employment-contracts.php for the same reason as above. A contract
 * that jumped from draft to signed would record an agreement nobody made.
 */
const RG_CONTRACT_TRANSITIONS = [
    'draft'    => ['sent', 'declined'],
    'sent'     => ['viewed', 'accepted', 'signed', 'declined', 'expired'],
    'viewed'   => ['accepted', 'signed', 'declined', 'expired'],
    'accepted' => ['signed', 'declined'],
    'signed'   => ['superseded'],
    'declined' => ['superseded'],
    'expired'  => ['superseded'],
];

/**
 * Is a person in this account's service scope?
 *
 * A person has no service of their own. They hold one through a worker record,
 * an application or a person_services row, and they can hold several: one human
 * can be a care worker and a security applicant at once. $services is that set.
 *
 * In scope when ANY of them is the account's service. A security manager opening
 * somebody who works in care and has applied for security is looking at their own
 * candidate; what they may see of the care side is filtered record by record
 * after this. A person with no service at all is out of scope for a scoped
 * account, for the same reason rg_in_service_scope() refuses a NULL division:
 * "unknown" is not "yours".
 *
 * @param list<?string> $services
 */
function rg_person_in_scope(?string $scope, array $services): bool
{
    if ($scope === null) return true;
    foreach ($services as $service) {
        if (rg_in_service_scope($scope, $service)) return true;
    }
    return false;
}

/**
 * The status a person has in each service they are connected to.
 *
 * One of applicant, onboarding, active, suspended, inactive, left. An
 * employment record outranks an application for the same service: once somebody
 * is a worker in care, their care application is history.
 *
 * "Active" needs an approval as well as an active status (migration 034). A
 * worker the office marked active but nobody approved reads as onboarding,
 * because rg_employment_gate() will refuse to put them on a shift and the profile
 * must not say otherwise.
 *
 * @param list<array<string,mixed>> $workers    id, division, status, left_on, approved_at, payroll_no, deleted_at
 * @param list<array<string,mixed>> $applicants id, division, stage, deleted_at
 * @return array<string, array{service:string,status:string,detail:string,worker_id:?int,applicant_id:?int,payroll_no:?string,stage:?string}>
 */
function rg_service_statuses(array $workers, array $applicants, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $out = [];

    // Oldest first, so the newest application per service is the one kept, and a
    // worker row then replaces it.
    usort($applicants, static fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    foreach ($applicants as $a) {
        if (!empty($a['deleted_at'])) continue;
        $stage = (string) $a['stage'];
        $out[$a['division']] = [
            'service' => (string) $a['division'],
            'status' => 'applicant',
            'detail' => in_array($stage, RG_STAGE_EXITS, true)
                ? 'Application ' . $stage
                : 'Application at ' . $stage,
            'worker_id' => null,
            'applicant_id' => (int) $a['id'],
            'payroll_no' => null,
            'stage' => $stage,
        ];
    }

    usort($workers, static fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    foreach ($workers as $w) {
        if (!empty($w['deleted_at'])) continue;
        $leftOn = $w['left_on'] ?? null;
        $status = (string) ($w['status'] ?? 'onboarding');

        if ($leftOn !== null && $leftOn !== '' && strtotime((string) $leftOn) <= strtotime($today)) {
            [$state, $detail] = ['left', 'Left on ' . $leftOn];
        } elseif ($status === 'suspended') {
            [$state, $detail] = ['suspended', 'Suspended'];
        } elseif ($status === 'inactive') {
            [$state, $detail] = ['inactive', 'Marked inactive'];
        } elseif ($status === 'active' && !empty($w['approved_at'])) {
            [$state, $detail] = ['active', 'Approved for work'];
        } else {
            [$state, $detail] = ['onboarding', 'Not yet approved for work'];
        }

        $previous = $out[$w['division']] ?? null;
        $out[$w['division']] = [
            'service' => (string) $w['division'],
            'status' => $state,
            'detail' => $detail,
            'worker_id' => (int) $w['id'],
            'applicant_id' => $previous['applicant_id'] ?? null,
            'payroll_no' => $w['payroll_no'] ?? null,
            'stage' => $previous['stage'] ?? null,
        ];
    }

    return $out;
}

/**
 * The status chip for one requirement on the profile's checks tab.
 *
 * Five chips, from the plan (docs/Plans 2026/04): valid, due_soon, expired,
 * awaiting_review, missing. They are a presentation of rg_assess_requirement()'s
 * state, never a stored value, and never something the screen can set.
 *
 * $openStatus is the newest verification still in progress for the requirement,
 * if any. Evidence that has arrived and is waiting for somebody outranks
 * "expired" and "missing", because the next thing to do is review it, not chase
 * it. It does not outrank a requirement that is already met: a renewal sitting in
 * the queue does not make a valid credential stop being valid.
 *
 * @return array{chip:string,label:string}
 */
function rg_requirement_chip(string $state, ?string $openStatus = null): array
{
    $labels = [
        'valid' => 'Valid',
        'due_soon' => 'Due soon',
        'expired' => 'Expired',
        'awaiting_review' => 'Awaiting review',
        'missing' => 'Missing',
    ];
    $pick = static fn(string $chip): array => ['chip' => $chip, 'label' => $labels[$chip]];

    if ($state === 'met') return $pick('valid');
    if ($state === 'expiring') return $pick('due_soon');
    if (in_array($openStatus, ['received', 'under_review'], true)) return $pick('awaiting_review');
    if ($state === 'expired') return $pick('expired');
    // On file but unchecked, or on file with no expiry recorded: either way a
    // reviewer has to look at it before it counts.
    if ($state === 'unverified' || $state === 'undated') return $pick('awaiting_review');
    return $pick('missing');
}

/**
 * What stops a worker being approved for work, in the order to fix it.
 *
 * The same test workers.php?action=approve applies, kept here so the profile can
 * show the reasons before anybody presses the button, and so the button and the
 * endpoint cannot disagree. Empty means approval may be granted.
 *
 * The employment gate is asked with the approval itself assumed, because that is
 * the one thing being granted. A suspended worker, a leaver or one with
 * onboarding outstanding cannot be approved; nor can one without a signed
 * contract that an authorised person has reviewed.
 *
 * @return list<array{code:string,reason:string}> code ∈ approved | employment | contract
 */
function rg_approval_blockers(array $worker, int $onboardingOutstanding, bool $hasReviewedContract, ?string $today = null): array
{
    if (!empty($worker['approved_at'])) {
        return [['code' => 'approved', 'reason' => 'This worker is already approved.']];
    }

    $blockers = [];
    // array_merge, not `+`: the union keeps the left operand's approved_at => null.
    $gate = rg_employment_gate(
        array_merge($worker, ['approved_at' => 'granting']),
        ['onboarding_outstanding' => $onboardingOutstanding],
        $today
    );
    if (!$gate['ok']) {
        $blockers[] = ['code' => 'employment', 'reason' => $gate['reason']];
    }
    if (!$hasReviewedContract) {
        $blockers[] = ['code' => 'contract', 'reason' => 'This worker has no signed employment contract that has been reviewed.'];
    }
    return $blockers;
}

/**
 * The single most important thing to do next for a person.
 *
 * One action, not a list: the profile header shows it as the main button, and a
 * header offering six things offers none. The order follows the launch plan's
 * journey -- evidence, then onboarding, then the contract, then approval -- with
 * two exceptions. A suspension is a decision under review and comes first. Work
 * that is waiting on a reviewer (evidence received, a contract signed) comes
 * before work that is waiting on the person, because only the first can be done
 * from this screen today.
 *
 * Facts, all optional:
 *   suspended               bool
 *   awaiting_review         list of requirement labels with evidence received
 *   rtw_needs_verify        bool, a right-to-work check recorded and not signed off
 *   contract                none | draft | sent | accepted | signed_unreviewed | reviewed
 *   onboarding_outstanding  int
 *   blocking                list of mandatory requirement labels not met
 *   approvable              bool, a worker with nothing in rg_approval_blockers()
 *   approved                bool, every worker record already approved
 *   expiring                list of requirement labels inside the expiry window
 *   has_worker              bool
 *   applicant_stage         ?string, the newest application's stage when there is no worker
 *
 * @return array{key:string,label:string,tab:string}|null
 */
function rg_person_next_action(array $f): ?array
{
    $act = static fn(string $key, string $label, string $tab): array =>
        ['key' => $key, 'label' => $label, 'tab' => $tab];
    // "Right to work" mid-sentence is "right to work"; "DBS check" and "SIA
    // licence" keep their capitals, because they are initials.
    $inSentence = static fn(string $label): string =>
        preg_match('/^[A-Z][a-z]/', $label) ? lcfirst($label) : $label;
    $hasWorker = !empty($f['has_worker']);

    if (!empty($f['suspended'])) {
        return $act('review_suspension', 'Review suspension', 'overview');
    }
    if (!empty($f['awaiting_review'])) {
        return $act('verify', 'Verify ' . $inSentence($f['awaiting_review'][0]), 'checks');
    }
    if (!empty($f['rtw_needs_verify'])) {
        return $act('verify_rtw', 'Verify right to work', 'checks');
    }
    $contract = (string) ($f['contract'] ?? 'none');
    if ($hasWorker && $contract === 'signed_unreviewed') {
        return $act('review_contract', 'Review contract', 'employment');
    }
    $outstanding = (int) ($f['onboarding_outstanding'] ?? 0);
    if ($hasWorker && $outstanding > 0) {
        return $act('finish_onboarding',
            'Finish onboarding (' . $outstanding . ' ' . ($outstanding === 1 ? 'task' : 'tasks') . ')', 'overview');
    }
    if ($hasWorker && !empty($f['blocking'])) {
        return $act('request_evidence', 'Request ' . $inSentence($f['blocking'][0]), 'checks');
    }
    if ($hasWorker && empty($f['approved'])) {
        if ($contract === 'none') return $act('draft_contract', 'Draft contract', 'employment');
        if ($contract === 'draft') return $act('send_contract', 'Send contract', 'employment');
        if ($contract === 'sent' || $contract === 'accepted') {
            return $act('record_signature', 'Record signed contract', 'employment');
        }
        if (!empty($f['approvable'])) return $act('approve', 'Approve activation', 'overview');
    }
    if (!empty($f['expiring'])) {
        return $act('renew', 'Renew ' . $inSentence($f['expiring'][0]), 'checks');
    }
    if (!$hasWorker) {
        $stage = $f['applicant_stage'] ?? null;
        if ($stage === 'offer') return $act('start_onboarding', 'Start onboarding', 'overview');
        if ($stage !== null && !in_array($stage, RG_STAGE_EXITS, true)) {
            return $act('review_application', 'Review application', 'overview');
        }
    }
    return null;
}

/**
 * Is this a telephone number somebody could ring?
 *
 * Digits, spaces, a leading plus, brackets, dots and hyphens; between 7 and 15
 * digits, 15 being the longest an international number can be. Not a UK-only
 * test, because an emergency contact can live anywhere.
 */
function rg_valid_phone(?string $phone): bool
{
    $phone = trim((string) $phone);
    if ($phone === '') return false;
    if (!preg_match('/^\+?[0-9 ().\-]+$/', $phone)) return false;
    $digits = strlen(preg_replace('/\D+/', '', $phone) ?? '');
    return $digits >= 7 && $digits <= 15;
}

/** The emergency contact columns on people, in the order the form shows them. */
const RG_EMERGENCY_FIELDS = [
    'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
    'emergency_contact2_name', 'emergency_contact2_phone', 'emergency_contact2_relationship',
];

/**
 * Validate an emergency contact form.
 *
 * Shared by the office profile and, from S4, the worker's own portal, so both
 * accept exactly the same thing. Empty fields clear a contact. A contact needs a
 * name and a number together: a name with no number cannot be rung, and a number
 * with no name cannot be asked for.
 *
 * @return array{ok:bool,errors:list<string>,values:array<string,?string>}
 */
function rg_validate_emergency_contacts(array $data): array
{
    $values = [];
    foreach (RG_EMERGENCY_FIELDS as $field) {
        $v = trim((string) ($data[$field] ?? ''));
        $values[$field] = $v !== '' ? $v : null;
    }

    $errors = [];
    foreach (['emergency_contact' => 'first', 'emergency_contact2' => 'second'] as $prefix => $which) {
        $name = $values[$prefix . '_name'];
        $phone = $values[$prefix . '_phone'];
        $relationship = $values[$prefix . '_relationship'];

        if ($name !== null && mb_strlen($name) > 160) $errors[] = "The $which contact's name is too long.";
        if ($relationship !== null && mb_strlen($relationship) > 80) {
            $errors[] = "The $which contact's relationship is too long.";
        }
        if ($phone !== null && !rg_valid_phone($phone)) {
            $errors[] = "The $which contact's phone number is not a number that can be rung.";
        }
        if ($name !== null && $phone === null) $errors[] = "Give a phone number for the $which contact.";
        if ($name === null && ($phone !== null || $relationship !== null)) {
            $errors[] = "Give a name for the $which contact.";
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'values' => $values];
}

/**
 * The kinds of site the website's cleaning quote form offers, key => label.
 *
 * The keys are what the form posts (src/components/public/forms/options.ts holds
 * the same list); the label is what the office reads in the request. The list is
 * the cleaning buyers in the 2026 plan (file 01) plus "other", so nobody is
 * forced into a category that does not fit.
 *
 * @return array<string, string>
 */
function rg_cleaning_site_types(): array
{
    return [
        'residential' => 'Residential block or communal areas',
        'void' => 'Void property or end of tenancy',
        'school' => 'School',
        'construction' => 'Construction site (builders clean)',
        'office' => 'Office or commercial premises',
        'care' => 'Care setting',
        'other' => 'Something else',
    ];
}

/**
 * How often the cleaning is wanted, key => label. Same pairing rule as above.
 *
 * @return array<string, string>
 */
function rg_cleaning_frequencies(): array
{
    return [
        'one_off' => 'One-off clean',
        'daily' => 'Daily',
        'several_weekly' => 'Several times a week',
        'weekly' => 'Weekly',
        'fortnightly' => 'Fortnightly',
        'monthly' => 'Monthly',
        'not_sure' => 'Not sure yet',
    ];
}



// ---------------------------------------------------------------- the rota

/**
 * The rota's clock. Shift times are wall-clock DATETIMEs (CLAUDE.md, "One
 * clock"): what the office typed and what the worker reads on the rota. Elapsed
 * time between two of them is only meaningful in the zone they were typed in,
 * because twice a year a night in Britain is 9 or 11 hours long, not 10.
 */
const RG_ROTA_TZ = 'Europe/London';

/**
 * Settings the owner may change, with the value each takes when nothing is
 * stored (migration 038 seeds the same). Fixed in code for the same reason as
 * rg_feature_defaults(): a setting is a promise that some code reads it.
 *
 * @return array<string,int>
 */
function rg_setting_defaults(): array
{
    return [
        // Minimum rest between two shifts for the same worker, in hours.
        'rest_gap_min_hours' => 11,
    ];
}

/** Sensible bounds per setting; a stored value outside them falls back to the default. */
const RG_SETTING_BOUNDS = [
    'rest_gap_min_hours' => [0, 24],
];

/**
 * One integer setting, from the stored rows (name => value).
 *
 * A missing row, a value that is not a whole number or one outside its bounds
 * all give the default: a typo in a settings row must not switch a safety check
 * off. An unknown name throws, because it can only be a typo in code.
 */
function rg_setting_int(string $name, array $stored): int
{
    $defaults = rg_setting_defaults();
    if (!array_key_exists($name, $defaults)) {
        throw new InvalidArgumentException("Unknown setting '$name'.");
    }
    $raw = $stored[$name] ?? null;
    if ($raw === null || !preg_match('/^\d+$/', trim((string) $raw))) return $defaults[$name];
    $value = (int) $raw;
    [$min, $max] = RG_SETTING_BOUNDS[$name] ?? [PHP_INT_MIN, PHP_INT_MAX];
    if ($value < $min || $value > $max) return $defaults[$name];
    return $value;
}

/**
 * Real minutes from one wall-clock datetime to another, in Europe/London.
 *
 * 22:00 on Saturday 28 March 2026 to 08:00 on Sunday 29 March reads as ten
 * hours and is nine, because the clocks go forward at 01:00. The same times
 * across Sunday 25 October are eleven. Naive arithmetic gets both wrong by an
 * hour, which is exactly the size of the margin a rest-gap check is about.
 *
 * Negative when $to is before $from.
 */
function rg_wall_minutes_between(string $from, string $to, string $tz = RG_ROTA_TZ): int
{
    $zone = new DateTimeZone($tz);
    $a = new DateTimeImmutable($from, $zone);
    $b = new DateTimeImmutable($to, $zone);
    return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
}

/** "9h", "10h 30m", "45m". */
function rg_minutes_label(int $minutes): string
{
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) return $sign . $m . 'm';
    return $sign . $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '');
}

/** "Sat 28 Mar 22:00" for a wall-clock datetime. */
function rg_shift_when(string $datetime): string
{
    $d = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    return $d->format('D j M H:i');
}

/**
 * The calendar dates a shift touches, start date first.
 *
 * A shift ending exactly at midnight does not touch the next day: 16:00 to
 * 00:00 is an evening shift, not two days' work.
 *
 * @return list<string>
 */
function rg_shift_dates(string $startsAt, string $endsAt): array
{
    $utc = new DateTimeZone('UTC');
    $start = new DateTimeImmutable(substr($startsAt, 0, 10), $utc);
    $endInstant = new DateTimeImmutable($endsAt, $utc);
    $last = new DateTimeImmutable($endInstant->modify('-1 minute')->format('Y-m-d'), $utc);
    if ($last < $start) $last = $start;
    $out = [];
    for ($d = $start; $d <= $last; $d = $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $out;
}

/**
 * Leave that rules a worker out of this shift, or null.
 *
 * Only leave that has been agreed counts: 'approved', or 'taken' once it has
 * happened. A request still waiting is the office's to decide, and a declined
 * one is not leave. Leave is recorded in whole dates, so any day the shift
 * touches that falls inside it is a clash -- a night shift starting the evening
 * before somebody's holiday runs into their first morning off.
 *
 * @param list<array{starts_on:string,ends_on:string,status:string,kind?:string}> $leave
 */
function rg_leave_conflict(array $leave, string $startsAt, string $endsAt): ?array
{
    $dates = rg_shift_dates($startsAt, $endsAt);
    $first = $dates[0];
    $last = $dates[count($dates) - 1];
    foreach ($leave as $row) {
        if (!in_array($row['status'] ?? '', ['approved', 'taken'], true)) continue;
        if (rg_leave_overlaps($row['starts_on'], $row['ends_on'], $first, $last)) return $row;
    }
    return null;
}

/**
 * A period the worker has marked as unavailable that overlaps this shift, or null.
 *
 * Both are wall-clock periods. Touching ends do not overlap: unavailable until
 * 08:00 and a shift from 08:00 is fine.
 *
 * @param list<array{starts_at:string,ends_at:string}> $blocks
 */
function rg_unavailability_conflict(array $blocks, string $startsAt, string $endsAt): ?array
{
    foreach ($blocks as $b) {
        if ($b['starts_at'] < $endsAt && $b['ends_at'] > $startsAt) return $b;
    }
    return null;
}

/**
 * Rest gaps shorter than the minimum between this shift and the worker's others.
 *
 * Measured in real time in Europe/London (rg_wall_minutes_between), so a gap
 * across the spring clock change is an hour shorter than it reads and one across
 * the autumn change an hour longer. Overlapping shifts are not a rest question:
 * rg_shift_clash() refuses those outright.
 *
 * @param array{starts_at:string,ends_at:string} $shift
 * @param list<array{id?:int,starts_at:string,ends_at:string}> $others
 * @return list<array{shift_id:?int,gap_minutes:int,reason:string}>
 */
function rg_rest_gap_warnings(array $shift, array $others, int $minHours): array
{
    if ($minHours <= 0) return [];
    $min = $minHours * 60;
    $out = [];
    foreach ($others as $o) {
        if ($o['ends_at'] <= $shift['starts_at']) {
            $gap = rg_wall_minutes_between($o['ends_at'], $shift['starts_at']);
            $where = 'after the shift ending ' . rg_shift_when($o['ends_at']);
        } elseif ($shift['ends_at'] <= $o['starts_at']) {
            $gap = rg_wall_minutes_between($shift['ends_at'], $o['starts_at']);
            $where = 'before the shift starting ' . rg_shift_when($o['starts_at']);
        } else {
            continue; // an overlap is a clash, refused elsewhere
        }
        if ($gap >= $min) continue;
        $out[] = [
            'shift_id' => isset($o['id']) ? (int) $o['id'] : null,
            'gap_minutes' => $gap,
            'reason' => 'Only ' . rg_minutes_label($gap) . ' rest ' . $where
                . ' (the minimum is ' . $minHours . 'h).',
        ];
    }
    return $out;
}

/**
 * Where a shift is in its life, as one word for the rota (plan file 03).
 *
 * Derived from the columns rather than stored, so it cannot disagree with them:
 * draft, open, offered, assigned, completed, cancelled.
 */
function rg_shift_state(array $shift, int $liveOffers = 0): string
{
    $status = $shift['status'] ?? '';
    if ($status === 'cancelled') return 'cancelled';
    if ($status === 'completed') return 'completed';
    if ((int) ($shift['is_draft'] ?? 0) === 1) return 'draft';
    if ($status === 'open') return $liveOffers > 0 ? 'offered' : 'open';
    return 'assigned';
}

/**
 * What changed about a shift, in words a worker can act on.
 *
 * "Every change after publishing tells the affected worker what changed"
 * (plan file 03). Only the fields a worker cares about are compared.
 *
 * @return list<string>
 */
function rg_shift_change_lines(array $before, array $after): array
{
    $lines = [];
    if (($before['starts_at'] ?? null) !== ($after['starts_at'] ?? null)
        || ($before['ends_at'] ?? null) !== ($after['ends_at'] ?? null)) {
        $lines[] = 'Time: ' . rg_shift_when($before['starts_at']) . ' to ' . substr($before['ends_at'], 11, 5)
            . ' is now ' . rg_shift_when($after['starts_at']) . ' to ' . substr($after['ends_at'], 11, 5) . '.';
    }
    $site = static fn(array $s): string => trim((string) ($s['site_name'] ?? '')) ?: 'no site';
    if ($site($before) !== $site($after)) {
        $lines[] = 'Site: ' . $site($before) . ' is now ' . $site($after) . '.';
    }
    if (trim((string) ($before['title'] ?? '')) !== trim((string) ($after['title'] ?? ''))) {
        $lines[] = 'Role: ' . $before['title'] . ' is now ' . $after['title'] . '.';
    }
    return $lines;
}

/** A wall-clock datetime moved by whole days, without any zone getting involved. */
function rg_add_days(string $datetime, int $days): string
{
    $d = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
    return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d H:i:s');
}

/** The Monday of the ISO week a date falls in. */
function rg_week_start(string $date): string
{
    $d = new DateTimeImmutable(substr($date, 0, 10), new DateTimeZone('UTC'));
    return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
}

/**
 * Turn a clock-in and clock-out into a timesheet's date and times.
 *
 * The clock events are TIMESTAMPs, read back in UTC. A timesheet is wall-clock,
 * like the rota, so they are converted to Europe/London first: a 07:00 clock-in
 * in July is 06:00 UTC, and a timesheet saying 06:00 would be an hour wrong.
 * Seconds are dropped. work_date is the date the shift started, as everywhere.
 *
 * @return array{work_date:string,start_time:string,end_time:string}
 */
function rg_clock_to_timesheet(string $clockInUtc, string $clockOutUtc, string $tz = RG_ROTA_TZ): array
{
    $utc = new DateTimeZone('UTC');
    $zone = new DateTimeZone($tz);
    $in = (new DateTimeImmutable($clockInUtc, $utc))->setTimezone($zone);
    $out = (new DateTimeImmutable($clockOutUtc, $utc))->setTimezone($zone);
    return [
        'work_date' => $in->format('Y-m-d'),
        'start_time' => $in->format('H:i'),
        'end_time' => $out->format('H:i'),
    ];
}

/**
 * Which timesheet statuses each action may start from.
 *
 * approve and reject keep the permissive rule they always had (any status), so
 * an office decision can still be changed before a pay run. The new actions are
 * narrower: a worker submits a draft or answers a query; the office queries
 * something not yet decided; a correction never touches an approved entry,
 * because approved hours may already have been paid. Reject it first.
 */
const RG_TIMESHEET_FROM = [
    'submit'  => ['draft', 'queried'],
    'query'   => ['draft', 'pending'],
    'correct' => ['draft', 'pending', 'queried', 'rejected'],
];

function rg_timesheet_can(string $action, string $status): bool
{
    if ($action === 'approve' || $action === 'reject') return true;
    return in_array($status, RG_TIMESHEET_FROM[$action] ?? [], true);
}

// ---------------------------------------------------------------- staff portal

/** How long before a shift starts a worker may clock in. Plan file 03. */
const RG_CLOCK_IN_LEAD_MINUTES = 15;

/**
 * When clocking in opens for a shift, as a wall-clock datetime.
 *
 * Shift times are wall-clock in Europe/London (CLAUDE.md, "One clock"), so the
 * lead time is taken off in that zone: fifteen minutes before 01:30 on the night
 * the clocks go back is still fifteen real minutes.
 */
function rg_clock_in_opens_at(string $startsAt, int $leadMinutes = RG_CLOCK_IN_LEAD_MINUTES): string
{
    $start = new DateTimeImmutable($startsAt, new DateTimeZone(RG_ROTA_TZ));
    return $start->modify("-$leadMinutes minutes")->format('Y-m-d H:i:s');
}

/**
 * May a worker clock in on this shift at $now (a Unix time)?
 *
 * Only the opening is tested. A late clock-in is still a clock-in: refusing it
 * would lose the record of when somebody actually arrived.
 */
function rg_clock_in_open(string $startsAt, ?int $now = null, int $leadMinutes = RG_CLOCK_IN_LEAD_MINUTES): bool
{
    $now ??= time();
    $start = new DateTimeImmutable($startsAt, new DateTimeZone(RG_ROTA_TZ));
    return $now >= $start->getTimestamp() - $leadMinutes * 60;
}

/**
 * Requirements a worker may send evidence for from the portal, and how the file
 * is filed.
 *
 * Three are left to the office on purpose:
 *   right_to_work  has its own check record, methods and share codes
 *                  (right-to-work.php); a photo in a verification queue is not
 *                  a right-to-work check, and verifying one there would bypass
 *                  the rules that endpoint holds.
 *   references     come from referees, not from the worker.
 *   contract       a file filed as a contract counts as a signed contract in
 *                  rg_load_evidence(), so a worker must never be able to file one.
 *
 * Returns the files.category to use, or null when the portal does not take it.
 */
function rg_portal_upload_category(string $requirementKey): ?string
{
    if (in_array($requirementKey, ['right_to_work', 'references', 'contract'], true)) return null;
    if ($requirementKey === 'identity') return 'id';
    if (!preg_match('/^[a-z0-9_]{2,64}$/', $requirementKey)) return null;
    return 'certificate';
}

/**
 * What stops a worker being booked, in words they can act on.
 *
 * Built from the verdict rg_deployment_check() returns, so the portal says
 * exactly what the deployment gate concluded and nothing it did not. $open is
 * requirement_key => verification status for checks the office has in hand, so
 * "the office is checking it" can replace "send a new copy" where that is true.
 *
 * @param array $gate the verdict from rg_deployment_check()
 * @param array<string,string> $open
 * @return list<array{key:?string,title:string,todo:string}>
 */
function rg_portal_blockers(array $gate, array $open = []): array
{
    if (!empty($gate['eligible'])) return [];

    if (($gate['blocked_by'] ?? null) === 'employment') {
        $reason = (string) ($gate['reason'] ?? '');
        $todo = 'Please contact the office.';
        if (str_contains($reason, 'onboarding')) {
            $todo = 'The office will tell you what is left to do. Please contact them if you are unsure.';
        } elseif (str_contains($reason, 'not been approved')) {
            $todo = 'The office approves you once your checks are complete. Nothing is needed from you unless they ask.';
        }
        // "This worker is suspended." reads oddly to the worker themselves.
        $title = str_replace(['This worker left on', 'This worker'], ['Your employment ended on', 'Your account'], $reason);
        return [['key' => null, 'title' => $title, 'todo' => $todo]];
    }

    $out = [];
    foreach ($gate['blocking'] ?? [] as $item) {
        $key = (string) ($item['key'] ?? '');
        $label = (string) ($item['label'] ?? $key);
        $state = (string) ($item['state'] ?? 'missing');
        $title = match ($state) {
            'expired' => "$label has expired",
            'undated' => "$label has no expiry date recorded",
            'unverified' => "$label has not been confirmed",
            default => "$label is missing",
        };
        $openStatus = $open[$key] ?? null;
        // Only "the office is checking it" when a copy really is with them.
        // Unverified on its own means a record nobody has confirmed, and the
        // worker can usually move it on by sending a current copy.
        if (in_array($openStatus, ['received', 'under_review'], true)) {
            $todo = 'The office is checking it. Nothing more is needed from you for now.';
        } elseif (rg_portal_upload_category($key) !== null) {
            $todo = 'Upload a current copy in Records. The office checks it before it counts.';
        } else {
            $todo = 'Please contact the office about this.';
        }
        $out[] = ['key' => $key, 'title' => $title, 'todo' => $todo];
    }
    if ($out === [] && !empty($gate['reason'])) {
        $out[] = ['key' => null, 'title' => (string) $gate['reason'], 'todo' => 'Please contact the office.'];
    }
    return $out;
}

/** The contact fields a worker may change themselves, on the people record. */
const RG_SELF_CONTACT_FIELDS = ['phone', 'address_line1', 'address_line2', 'city', 'postcode'];

/**
 * Validate the contact details a worker edits in the portal.
 *
 * Email is not here: it is how the person signs in and how they are matched to
 * a returning application, so the office changes it. Empty values clear a field,
 * except the phone, which the office needs to reach somebody on shift.
 *
 * @return array{ok:bool,errors:list<string>,values:array<string,?string>}
 */
function rg_validate_contact_details(array $data): array
{
    $values = [];
    foreach (RG_SELF_CONTACT_FIELDS as $field) {
        $v = trim((string) ($data[$field] ?? ''));
        $values[$field] = $v !== '' ? $v : null;
    }
    $errors = [];
    if ($values['phone'] === null) {
        $errors[] = 'Give a phone number the office can reach you on.';
    } elseif (!rg_valid_phone($values['phone'])) {
        $errors[] = 'That phone number is not a number that can be rung.';
    }
    foreach (['address_line1' => 190, 'address_line2' => 190, 'city' => 80] as $field => $max) {
        if ($values[$field] !== null && mb_strlen($values[$field]) > $max) $errors[] = 'Please shorten the address.';
    }
    if ($values['postcode'] !== null) {
        $pc = strtoupper(preg_replace('/\s+/', ' ', $values['postcode']) ?? '');
        if (!preg_match('/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/', $pc)) {
            $errors[] = 'That postcode does not look right.';
        } else {
            // One space before the last three characters, as Royal Mail writes it.
            $compact = str_replace(' ', '', $pc);
            $values['postcode'] = substr($compact, 0, -3) . ' ' . substr($compact, -3);
        }
    }
    return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'values' => $values];
}

// ---------------------------------------------------------- owner visibility

/**
 * Which parts of the Action Centre (and the Today page built on it) an account
 * may see.
 *
 * The Action Centre counted everything for everybody: a manager confined to
 * security was told how many care credentials had expired, and an editor, who
 * holds no compliance zone, was told who could not be deployed and why. Each
 * group below mirrors the guard on the endpoint that owns the records, so the
 * summary never says more than the screen it links to would.
 *
 *   compliance   the compliance zone (verifications.php, right-to-work.php)
 *   recruitment  ats.php: office and trainer
 *   rota         scheduling.php, incidents.php, enquiries.php, bookings.php,
 *                leave decisions: office
 *   payroll      timesheets.php: office and the payroll zone
 *   finance      invoices: office, and only while the finance switch is on
 *   approvals    activation approval (workers.php?action=approve): office
 *   governance   deletion decisions (governance.php): owner
 *   system       the email queue and form intake carry no service, so a count of
 *                them is a count across every service: office accounts with no
 *                service scope only
 *   training     unlinked enrolments carry no service either: unscoped staff who
 *                may open the Academy
 *
 * @param array<string, bool> $zones    zone => can_view
 * @param array<string, bool> $features switch => enabled
 * @return array<string, bool>
 */
function rg_action_centre_access(string $role, ?string $scope, array $zones, array $features): array
{
    $office = in_array($role, ['super_admin', 'admin', 'manager'], true);
    $owner = in_array($role, ['super_admin', 'admin'], true);
    return [
        'compliance'  => !empty($zones['compliance']),
        'recruitment' => $office || $role === 'trainer',
        'rota'        => $office,
        'payroll'     => $office && !empty($zones['payroll']),
        'finance'     => $office && !empty($features['finance']),
        'approvals'   => $office,
        'governance'  => $owner,
        'system'      => $office && $scope === null,
        'training'    => $scope === null && ($office || in_array($role, ['trainer', 'editor'], true)),
    ];
}

/**
 * The DBS level a free-text label names, or null when it names none.
 *
 * No column records the level of a DBS check. The nearest thing is the label on
 * the verification, which is whatever somebody typed (and, for records carried
 * over in migration 017, the old status word). A client pack must not claim a
 * level nobody recorded, so anything unrecognised is null and printed as "not
 * recorded".
 */
function rg_dbs_level(?string $label): ?string
{
    $l = strtolower(trim((string) $label));
    if ($l === '') return null;
    if (str_contains($l, 'enhanced')) {
        return (str_contains($l, 'barred') || str_contains($l, 'barring')) ? 'Enhanced with barred list' : 'Enhanced';
    }
    if (str_contains($l, 'standard')) return 'Standard';
    if (str_contains($l, 'basic')) return 'Basic';
    return null;
}

/**
 * One CSV cell, quoted, with spreadsheet formula injection neutralised.
 *
 * A name typed as "=HYPERLINK(...)" on a public application form would otherwise
 * run as a formula when the office opens the export. A leading =, +, -, @, tab
 * or carriage return is prefixed with an apostrophe, which spreadsheets show as
 * text. Numbers are left alone so a negative count stays a number.
 */
function rg_csv_cell($value): string
{
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_int($value) || is_float($value)) return (string) $value;
    $s = (string) $value;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
}

/**
 * A CSV document: a header row of labels, then each row's values in the order
 * of $columns. Only the listed columns are written, whatever else a row holds,
 * which is what keeps an export to its authorised fields.
 *
 * @param array<string, string> $columns key => header label
 * @param list<array<string, mixed>> $rows
 */
function rg_csv(array $columns, array $rows): string
{
    $out = implode(',', array_map('rg_csv_cell', array_values($columns))) . "\r\n";
    foreach ($rows as $row) {
        $cells = [];
        foreach (array_keys($columns) as $key) $cells[] = rg_csv_cell($row[$key] ?? null);
        $out .= implode(',', $cells) . "\r\n";
    }
    return $out;
}

/**
 * Which reporting band an expiry falls in: '30', '60' or '90' days from today,
 * 'expired' when already past, or null when further out or undated.
 */
function rg_expiry_band(?int $days): ?string
{
    if ($days === null) return null;
    if ($days < 0) return 'expired';
    if ($days <= 30) return '30';
    if ($days <= 60) return '60';
    if ($days <= 90) return '90';
    return null;
}

/**
 * Is a scheduled job running on time?
 *
 *   unknown  no run has ever been recorded. Said plainly rather than read as
 *            fine: a job that has never reported is exactly the one nobody set up
 *   late     the last run is older than the expected interval plus a grace of
 *            half again (a daily job at 02:00 is not late at 03:00)
 *   ok       otherwise
 */
function rg_cron_state(?string $lastRunAt, int $expectEveryHours, ?int $now = null): string
{
    if ($lastRunAt === null || $lastRunAt === '') return 'unknown';
    $t = strtotime($lastRunAt . ' UTC');
    if ($t === false) return 'unknown';
    $now ??= time();
    return ($now - $t) > (int) round($expectEveryHours * 1.5 * 3600) ? 'late' : 'ok';
}
