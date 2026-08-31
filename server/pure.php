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
