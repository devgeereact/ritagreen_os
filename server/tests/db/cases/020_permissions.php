<?php
// Who can reach what, exercised rather than read.
//
// This is the baseline the launch plan's access work is measured against. It
// records what the server ACTUALLY answers today for every seeded account,
// endpoint by endpoint, so that tightening a permission later is a visible
// change to this file rather than a silent one.
//
// The accounts come from server/seed.php:
//   admin     — no division, sees everything
//   manager   — division 'care'
//   trainer   — division 'academy', staff by is_staff() but not by role
//   editor    — no division, staff by is_staff()
//   employee  — division 'cleaning', staff by is_staff(): no worker_id
//   worker    — amara.login, an employee login WITH worker_id, so not staff
//   client    — priya, a client portal login

/** @var RgtClient $client */
/** @var mysqli $db */

$as = [
    'admin'    => $client->login('admin@local.invalid'),
    'manager'  => $client->login('manager@local.invalid'),
    'trainer'  => $client->login('trainer@local.invalid'),
    'editor'   => $client->login('editor@local.invalid'),
    'employee' => $client->login('employee@local.invalid'),
    'worker'   => $client->login('amara.login@local.invalid'),
    'clientco' => $client->login('priya@local.invalid'),
];

Rgt::group('permissions — the workforce is not public to every login');

// A worker login and a client login are the two accounts that are not staff.
// Neither may read the workforce, the candidate pipeline or the rota. This is
// the defect CLAUDE.md records as "a guard goes above the read": twenty
// endpoints gated their POST branches and left every GET open.
$offLimitsToOutsiders = [
    '/workers.php'      => 'the workforce list',
    '/ats.php'          => 'the candidate pipeline',
    '/clients.php'      => 'the client list',
    '/compliance.php'   => 'the compliance board',
    '/scheduling.php'   => 'the rota',
    '/audit.php'        => 'the audit log',
    '/users.php'        => 'the account list',
    '/verifications.php'=> 'verification records',
    '/references.php'   => 'reference records',
    '/right-to-work.php'=> 'right-to-work checks',
    '/action-centre.php'=> 'the action centre',
    '/system-health.php'=> 'system health',
    '/reports.php'      => 'reports',
];
foreach (['worker', 'clientco'] as $who) {
    foreach ($offLimitsToOutsiders as $path => $what) {
        Rgt::denied("$who cannot read $what", $as[$who]->get($path));
    }
}

Rgt::group('permissions — payroll is a zone, not a role');

// CLAUDE.md: hours are the payroll zone. require_staff() alone was not a
// narrowing, because an editor, a trainer and an employee are all staff.
foreach (['trainer', 'editor', 'employee'] as $who) {
    Rgt::denied("$who cannot read timesheets", $as[$who]->get('/timesheets.php'));
    Rgt::denied("$who cannot read payroll", $as[$who]->get('/payroll.php'));
}
Rgt::status('admin can read timesheets', $as['admin']->get('/timesheets.php'), 200);
$from = date('Y-m-d', strtotime('-30 days'));
$to = date('Y-m-d');
Rgt::status('admin can read payroll', $as['admin']->get("/payroll.php?from=$from&to=$to"), 200);

Rgt::group('permissions — staff can reach the workforce');

foreach (['admin', 'manager'] as $who) {
    Rgt::status("$who can read the workforce", $as[$who]->get('/workers.php'), 200);
    Rgt::status("$who can read the candidate pipeline", $as[$who]->get('/ats.php'), 200);
}

Rgt::group('permissions — a worker reads their own record and no other');

$me = $as['worker']->get('/me.php');
Rgt::status('a worker can read their own worker record', $me, 200);

// Find a worker the seeded login is NOT linked to, and try to read it directly.
$row = $db->query(
    'SELECT w.id FROM workers w
      WHERE w.id <> (SELECT worker_id FROM users WHERE email = "amara.login@local.invalid")
      ORDER BY w.id LIMIT 1'
)->fetch_row();
$otherWorkerId = (int) $row[0];

$sub = $as['worker']->get('/workers.php?id=' . $otherWorkerId);
Rgt::denied("a worker cannot read worker $otherWorkerId by id", $sub);

Rgt::group('permissions — a client reads their own record and no other');

$row = $db->query(
    'SELECT c.id FROM clients c
      WHERE c.id <> (SELECT client_id FROM users WHERE email = "priya@local.invalid")
      ORDER BY c.id LIMIT 1'
)->fetch_row();
if ($row) {
    $otherClientId = (int) $row[0];
    Rgt::denied("a client cannot read client $otherClientId by id",
        $as['clientco']->get('/clients.php?id=' . $otherClientId));
}
$portal = $as['clientco']->get('/portal.php');
Rgt::status('a client can reach their own portal', $portal, 200);

Rgt::group('permissions — writing is refused before reading is');

// A refusal that happens after the write is not a refusal. Each of these must
// be rejected, and the row count must be unchanged afterwards.
$before = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
$attempt = $as['worker']->post('/workers.php', [
    'name' => 'Unauthorised Insert', 'email' => 'nope@local.invalid',
    'role' => 'Carer', 'division' => 'care',
]);
Rgt::denied('a worker cannot create a worker', $attempt);
$after = (int) $db->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
Rgt::same('and no worker row was written', $after, $before);

$before = (int) $db->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
$attempt = $as['clientco']->post('/users.php', [
    'name' => 'Unauthorised Account', 'email' => 'nope2@local.invalid',
    'password' => 'LocalDev123!', 'role' => 'admin',
]);
Rgt::denied('a client cannot create an account', $attempt);
$after = (int) $db->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
Rgt::same('and no user row was written', $after, $before);
