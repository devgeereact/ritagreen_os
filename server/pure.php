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
 * Records that share a match key are the same person. Records with no key are
 * returned separately for human review rather than guessed at — a wrong merge
 * of two employment histories is far harder to undo than an unmerged duplicate.
 *
 * @param array<int,array> $rows each needing at least: source, id, name, email, phone
 * @return array{groups: array<string,array<int,array>>, unmatchable: array<int,array>}
 */
function rg_group_people(array $rows): array
{
    $groups = [];
    $unmatchable = [];

    foreach ($rows as $row) {
        $match = rg_person_match_key($row);
        if ($match['key'] === null) {
            $unmatchable[] = $row;
            continue;
        }
        $groups[$match['key']][] = $row;
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
