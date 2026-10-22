<?php
// Authentication, and what an unauthenticated caller can reach.
//
// server/tests/guards.php proves the source of each endpoint calls an
// authorisation function. It cannot prove the function refuses, because it never
// runs one. That is this file's job.

/** @var RgtClient $client */
/** @var mysqli $db */

Rgt::group('authentication');

$res = $client->post('/auth.php?action=login', ['email' => 'admin@local.invalid', 'password' => 'LocalDev123!']);
Rgt::status('a seeded admin can sign in', $res, 200);
Rgt::ok('sign-in returns a token', is_string($res->get('token')) && $res->get('token') !== '');
Rgt::same('sign-in reports the role', $res->get('user.role'), 'admin');
Rgt::ok('sign-in never returns the password hash', !str_contains($res->body, 'password_hash'), $res->body);

$bad = $client->post('/auth.php?action=login', ['email' => 'admin@local.invalid', 'password' => 'wrong-password']);
Rgt::status('a wrong password is refused', $bad, 401);
Rgt::ok('a wrong password does not say which half was wrong',
    !str_contains(strtolower($bad->error()), 'no such') && !str_contains(strtolower($bad->error()), 'not found'),
    $bad->error());

$unknown = $client->post('/auth.php?action=login', ['email' => 'nobody@local.invalid', 'password' => 'LocalDev123!']);
Rgt::status('an unknown address is refused', $unknown, 401);
Rgt::same('an unknown address is refused identically to a wrong password',
    $unknown->error(), $bad->error());

$admin = $client->login('admin@local.invalid');
$me = $admin->get('/auth.php?action=me');
Rgt::status('a token identifies its user', $me, 200);
Rgt::same('the identified user is the one who signed in', $me->get('user.email') ?? $me->get('email'), 'admin@local.invalid');

Rgt::group('authentication — a token is required');

// Every endpoint that holds personal data, called with no token at all. The
// point is not that one of them refuses; it is that none of them answers.
$protected = [
    '/workers.php',
    '/ats.php',
    '/clients.php',
    '/compliance.php',
    '/timesheets.php',
    '/payroll.php',
    '/scheduling.php',
    '/verifications.php',
    '/action-centre.php',
    '/users.php',
    '/audit.php',
    '/reports.php',
    '/files.php',
    '/leave.php',
    '/references.php',
    '/right-to-work.php',
    '/system-health.php',
];
foreach ($protected as $path) {
    Rgt::denied("GET $path without a token", $client->get($path));
}

Rgt::group('authentication — a rubbish token is not a token');

$forged = $client->withToken('not-a-real-token-at-all');
foreach (['/workers.php', '/ats.php', '/payroll.php'] as $path) {
    Rgt::denied("GET $path with a forged token", $forged->get($path));
}

Rgt::group('authentication — logout');

$throwaway = $client->login('manager@local.invalid');
$before = $throwaway->get('/auth.php?action=me');
Rgt::status('the session works before logout', $before, 200);
$out = $throwaway->post('/auth.php?action=logout', []);
Rgt::ok('logout is accepted', $out->status < 400, "HTTP {$out->status} {$out->body}");
$after = $throwaway->get('/auth.php?action=me');
Rgt::denied('the token stops working after logout', $after);
Rgt::denied('and it cannot read a worker afterwards', $throwaway->get('/workers.php'));

Rgt::group('authentication — repeated failures are rate limited');

// The limit is 10 per IP in 300 seconds (auth.php), and a second, wider limit
// per account. Proven here rather than assumed, because a login endpoint with no
// limit is a login endpoint that will be guessed at.
rgt_clear_rate_limits($db);
$hit429 = false;
for ($i = 0; $i < 14; $i++) {
    $r = $client->post('/auth.php?action=login', ['email' => 'admin@local.invalid', 'password' => 'wrong']);
    if ($r->status === 429) { $hit429 = true; break; }
}
Rgt::ok('repeated wrong passwords are eventually refused with 429', $hit429);

// The window is 300 seconds and the suite does not wait it out. Clearing the
// table is what lets the later cases sign in; the assertion above is what keeps
// the limit honest.
rgt_clear_rate_limits($db);
