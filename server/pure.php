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
