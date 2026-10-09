<?php
// Endpoint authorisation guards — a source-level check, not a unit test.
//
// The defect this exists to catch: every endpoint here role-gated its POST
// branches and left the GET branches on require_auth() alone. Reading was
// therefore open to ANY logged-in account, which includes a client portal login
// and a worker's own login. A client could list every other client, every
// applicant, the whole rota and the entire workforce's compliance state.
//
// The invariant: between require_auth() and the first authorisation call, an
// endpoint must not return anything. Guards come before reads, not just before
// writes. Branches that are deliberately public sit ABOVE require_auth() and are
// unaffected.
//
// Endpoints that genuinely serve any authenticated account are listed below with
// the reason. Adding a file to that list is a decision; forgetting a guard is
// not, and now fails here.

declare(strict_types=1);

/** Files that are not HTTP endpoints. */
const GUARD_NOT_AN_ENDPOINT = [
    'lib.php', 'pure.php', 'compliance-lib.php', 'pay-lib.php', 'people-lib.php',
    'config.php', 'config.sample.php',
    'migrate.php', 'seed.php', 'backfill-people.php', 'retention-sweep.php',
    'send-queued-mail.php', 'create-admin.php', 'expiry-alerts.php', 'backup.php',
];

/**
 * Endpoints where the first authenticated read is deliberately open to any
 * logged-in account, with why. Each one scopes its own query to the caller.
 */
const GUARD_ANY_AUTHENTICATED = [
    'auth.php'          => 'the caller\'s own session, devices and 2FA state',
    'me.php'            => 'the worker app; every query is scoped to users.worker_id',
    'portal.php'        => 'the client portal; every query is scoped to users.client_id',
    'notifications.php' => 'the caller\'s own notifications',
];

function guard_check_endpoints(): array
{
    $problems = [];
    $checked = 0;

    foreach (glob(__DIR__ . '/../*.php') as $path) {
        $name = basename($path);
        if (in_array($name, GUARD_NOT_AN_ENDPOINT, true)) continue;

        $src = file_get_contents($path);
        $authAt = strpos($src, 'require_auth(');
        // No login required at all: a public endpoint. Out of scope here.
        if ($authAt === false) continue;
        $checked++;

        // json_out() is the convention; weather.php echoes a cached body directly,
        // so a bare echo counts as a read as well.
        $readAt = guard_first_position($src, ['json_out(', 'echo '], $authAt);
        $guardAt = guard_first_position(
            $src,
            ['require_role(', 'require_staff(', 'can_view_zone(', 'require_super_admin('],
            $authAt
        );

        $unguardedRead = $readAt !== null && ($guardAt === null || $readAt < $guardAt);
        $listed = array_key_exists($name, GUARD_ANY_AUTHENTICATED);

        if ($unguardedRead && !$listed) {
            $problems[] = "$name returns data to any authenticated account before any "
                . 'authorisation check. Add the guard, or list it in GUARD_ANY_AUTHENTICATED with the reason.';
        }
        if (!$unguardedRead && $listed) {
            $problems[] = "$name is listed in GUARD_ANY_AUTHENTICATED but now guards its "
                . 'first read. Remove the entry so the list keeps meaning something.';
        }
    }

    return [$checked, $problems];
}

/** The earliest position of any needle after $from, or null. */
function guard_first_position(string $src, array $needles, int $from): ?int
{
    $best = null;
    foreach ($needles as $needle) {
        $at = strpos($src, $needle, $from + 1);
        if ($at !== false && ($best === null || $at < $best)) $best = $at;
    }
    return $best;
}
