<?php
// Local development fixtures.
//
//     php server/seed.php --confirm          insert fixtures
//     php server/seed.php --confirm --reset  wipe the fixture tables first
//
// Exists so nobody needs production data to develop against. It refuses to run
// anywhere but a local database, from the CLI, with an explicit --confirm.
//
// The seeded compliance data is deliberately mixed: some credentials valid, some
// inside the 30-day window, some already expired, and one worker with no right
// to work at all. That is what makes the compliance and scheduling paths
// testable without hand-editing rows.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("seed.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
$confirmed = in_array('--confirm', $args, true);
$reset = in_array('--reset', $args, true);

// Guard 1: never anything but a declared local database. This is the whole point
// of the script, and it used to ask DB_HOST — which is 'localhost' on the cPanel
// production host as well. --reset empties clients, workers, invoices and
// twenty more tables, so that guard was one misplaced config.php away from
// wiping the live system. RG_ENV is declared, and an undeclared config is
// treated as production.
if (!rg_is_local_database()) {
    exit("REFUSED: " . DB_NAME . " is not declared as a local database. seed.php only ever runs against one.\n"
        . rg_env_hint());
}

// Guard 2: never silently. Show what is about to be written to, and to where.
if (!$confirmed) {
    echo "About to seed fixtures into:\n";
    echo "  host     " . DB_HOST . "\n";
    echo "  database " . DB_NAME . "\n";
    echo "  user     " . DB_USER . "\n\n";
    echo "Re-run with --confirm to proceed, and --reset to wipe fixture tables first.\n";
    exit(1);
}

// Guard 3: do not quietly double up on an already-populated database.
$existing = (int) $conn->query('SELECT COUNT(*) FROM workers')->fetch_row()[0];
if ($existing > 0 && !$reset) {
    exit("REFUSED: workers already holds $existing rows. Re-run with --reset to replace them.\n");
}

function run(mysqli $conn, string $sql): void
{
    if (!$conn->query($sql)) {
        exit("SQL failed: {$conn->error}\n  $sql\n");
    }
}

if ($reset) {
    echo "Resetting fixture tables…\n";
    run($conn, 'SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'shift_events', 'shift_offers', 'shifts', 'shift_templates',
        'timesheets', 'certifications', 'worker_references', 'worker_skills',
        'onboarding_tasks', 'applicants', 'enrolments', 'sessions', 'programmes',
        'incident_actions', 'incidents', 'credit_notes', 'invoices', 'quotes',
        'contracts', 'jobs', 'rate_cards', 'service_requests', 'sites',
        'clients', 'workers', 'contact_enquiries', 'notifications', 'files',
        // Hang off people rather than workers, so they outlive a reset that
        // only clears the employment tables and leave the spine inconsistent.
        'person_services', 'right_to_work_checks', 'verifications', 'leave_records',
    ] as $table) {
        run($conn, "DELETE FROM $table");
    }
    // Keep the seeded super admin; clear the fixture logins around it.
    run($conn, "DELETE FROM users WHERE email LIKE '%@local.invalid'");
    // And the people nothing points at any more. Without this every --reset
    // left the previous run's identities behind, and `people` grew each time.
    run($conn, 'DELETE p FROM people p
                LEFT JOIN users u      ON u.person_id = p.id
                LEFT JOIN workers w    ON w.person_id = p.id
                LEFT JOIN applicants a ON a.person_id = p.id
                WHERE u.id IS NULL AND w.id IS NULL AND a.id IS NULL');
    run($conn, 'SET FOREIGN_KEY_CHECKS = 1');
}

// Dates relative to today, so the fixtures never go stale.
$d = static fn(int $days): string => date('Y-m-d', strtotime("$days days"));
$dt = static fn(string $expr): string => date('Y-m-d H:i:s', strtotime($expr));

echo "Seeding…\n";

// ---- Staff logins, one per role -------------------------------------------
// Local fixture password, shared by every seeded account below. This is a
// throwaway credential for a throwaway database; it is not a secret and must
// never appear in a real config.
$password = password_hash('LocalDev123!', PASSWORD_BCRYPT, ['cost' => 10]);
$staff = [
    ['Olu Admin',      'admin@local.invalid',    'admin',    null],
    ['Mara Manager',   'manager@local.invalid',  'manager',  'care'],
    ['Tam Trainer',    'trainer@local.invalid',  'trainer',  'academy'],
    ['Eddie Editor',   'editor@local.invalid',   'editor',   null],
    ['Ivy Employee',   'employee@local.invalid', 'employee', 'cleaning'],
];
// Every identity is created through find_or_create_person(), the same call the
// application uses. Migration 004 made users.person_id, workers.person_id and
// applicants.person_id NOT NULL, and these inserts still named none of them --
// so seeding a migrated database failed outright with "Field 'person_id'
// doesn't have a default value", which is every developer's database.
$ins = $conn->prepare('INSERT INTO users (name, email, password_hash, role, division, person_id) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($staff as [$name, $email, $role, $division]) {
    $personId = find_or_create_person($conn, $name, $email);
    $ins->bind_param('sssssi', $name, $email, $password, $role, $division, $personId);
    $ins->execute();
}
echo "  users        " . count($staff) . "\n";

// ---- Clients and sites -----------------------------------------------------
$clients = [
    ['Oakleaf Care Home', 'care_home', 'Priya Nair',   'priya@oakleaf.invalid',  '02071234001', 'care,cleaning', 'active'],
    ['Bramley Estates',   'landlord',  'Tom Fielding', 'tom@bramley.invalid',    '02071234002', 'cleaning,security', 'active'],
    ['Northgate Events',  'event',     'Sam Okoro',    'sam@northgate.invalid',  '02071234003', 'security', 'active'],
    ['Havering NHS Trust','nhs',       'Dr K Ahmed',   'k.ahmed@havering.invalid','02071234004','care', 'prospect'],
];
$ins = $conn->prepare('INSERT INTO clients (name, type, contact_name, email, phone, divisions, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
$clientIds = [];
foreach ($clients as $c) {
    $ins->bind_param('sssssss', $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6]);
    $ins->execute();
    $clientIds[$c[0]] = $conn->insert_id;
}
$ins = $conn->prepare('INSERT INTO sites (client_id, name, address) VALUES (?, ?, ?)');
$sites = [
    [$clientIds['Oakleaf Care Home'], 'Oakleaf — Main House', '1 Oakleaf Way, London E1 1AA'],
    [$clientIds['Bramley Estates'],   'Bramley Court',        '12 Bramley Road, London N4 2BB'],
    [$clientIds['Bramley Estates'],   'Bramley Heights',      '40 Bramley Road, London N4 2CC'],
    [$clientIds['Northgate Events'],  'Northgate Arena',      'Arena Approach, London SE10 3DD'],
];
foreach ($sites as $s) {
    $ins->bind_param('iss', $s[0], $s[1], $s[2]);
    $ins->execute();
}
echo "  clients      " . count($clients) . " (" . count($sites) . " sites)\n";

// ---- Workers, with a deliberate spread of compliance states ----------------
// name, email, phone, role, division, status, rtw, dbs_status, dbs_expiry,
// sia_number, sia_expiry, pay pence
$workers = [
    ['Amara Chen',    'amara@local.invalid',  '07700900101', 'carer',   'care',     'active',     1, 'valid',   $d(300),  null,           null,    1350],
    ['Bola Adeyemi',  'bola@local.invalid',   '07700900102', 'nurse',   'care',     'active',     1, 'valid',   $d(21),   null,           null,    2200],
    ['Cerys Hughes',  'cerys@local.invalid',  '07700900103', 'carer',   'care',     'active',     1, 'expired', $d(-14),  null,           null,    1300],
    ['Dmitri Volkov', 'dmitri@local.invalid', '07700900104', 'guard',   'security', 'active',     1, 'valid',   $d(400),  'SIA-1000-0104', $d(180), 1500],
    ['Esi Mensah',    'esi@local.invalid',    '07700900105', 'guard',   'security', 'active',     1, 'valid',   $d(250),  'SIA-1000-0105', $d(-3),  1500],
    ['Farid Hassan',  'farid@local.invalid',  '07700900106', 'guard',   'security', 'active',     1, 'valid',   $d(500),  'SIA-1000-0106', $d(25),  1550],
    ['Grace Okafor',  'grace@local.invalid',  '07700900107', 'cleaner', 'cleaning', 'active',     1, null,      null,     null,           null,    1250],
    ['Hana Yilmaz',   'hana@local.invalid',   '07700900108', 'cleaner', 'cleaning', 'active',     0, null,      null,     null,           null,    1250],
    ['Ivan Petrov',   'ivan@local.invalid',   '07700900109', 'cleaner', 'cleaning', 'onboarding', 0, null,      null,     null,           null,    1200],
    ['Jade Thompson', 'jade@local.invalid',   '07700900110', 'trainer', 'academy',  'active',     1, 'valid',   $d(600),  null,           null,    2500],
];
$ins = $conn->prepare(
    'INSERT INTO workers (name, email, phone, role, division, status, right_to_work,
                          dbs_status, dbs_expiry, sia_number, sia_expiry, pay_rate_pence, pay_type, person_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "paye", ?)'
);
$workerIds = [];
$workerPersonIds = [];
foreach ($workers as $w) {
    $personId = find_or_create_person($conn, $w[0], $w[1], $w[2]);
    $ins->bind_param('ssssssissssii', $w[0], $w[1], $w[2], $w[3], $w[4], $w[5], $w[6], $w[7], $w[8], $w[9], $w[10], $w[11], $personId);
    $ins->execute();
    $workerIds[$w[0]] = $conn->insert_id;
    $workerPersonIds[$w[0]] = $personId;
}
// A statutory-style entitlement in hours, so the leave balance means something.
// 28 days at 7.5 hours is 210; part-time workers get less, which is the point of
// counting in hours rather than days.
$conn->query('UPDATE workers SET holiday_hours = 210 WHERE holiday_hours = 0');
echo "  workers      " . count($workers) . " (mixed compliance: valid, expiring, expired, no RTW)\n";

// A worker login, so /m can be exercised. Linked to Amara Chen.
// Deliberately the same person_id as the worker record, not a new person: a
// worker and their login are one human, and the fixtures have to demonstrate
// that or they teach the opposite of the rule.
$ins = $conn->prepare('INSERT INTO users (name, email, password_hash, role, division, worker_id, person_id) VALUES (?, ?, ?, "employee", "care", ?, ?)');
$wname = 'Amara Chen'; $wemail = 'amara.login@local.invalid';
$ins->bind_param('sssii', $wname, $wemail, $password, $workerIds['Amara Chen'], $workerPersonIds['Amara Chen']);
$ins->execute();

// A client portal login, so /portal can be exercised.
$ins = $conn->prepare('INSERT INTO users (name, email, password_hash, role, client_id, person_id) VALUES (?, ?, ?, "client", ?, ?)');
$cname = 'Priya Nair'; $cemail = 'priya@local.invalid';
$cpid = find_or_create_person($conn, $cname, $cemail);
$ins->bind_param('sssii', $cname, $cemail, $password, $clientIds['Oakleaf Care Home'], $cpid);
$ins->execute();
echo "  portal login 1 client, 1 worker\n";

// ---- Certifications, again spread across the expiry tiers ------------------
$certs = [
    ['Amara Chen',    'Care Certificate',      'CC-2024-001', $d(200)],
    ['Amara Chen',    'Moving & Handling',     null,          $d(55)],
    ['Bola Adeyemi',  'NMC Registration',      'NMC-77123',   $d(90)],
    ['Cerys Hughes',  'First Aid',             null,          $d(-40)],
    ['Dmitri Volkov', 'First Aid',             null,          $d(120)],
    ['Esi Mensah',    'Conflict Management',   null,          $d(15)],
    ['Grace Okafor',  'COSHH Awareness',       null,          $d(310)],
    ['Hana Yilmaz',   'COSHH Awareness',       null,          $d(-8)],
];
$ins = $conn->prepare('INSERT INTO certifications (worker_id, type, number, expiry) VALUES (?, ?, ?, ?)');
foreach ($certs as $c) {
    $ins->bind_param('isss', $workerIds[$c[0]], $c[1], $c[2], $c[3]);
    $ins->execute();
}
echo "  certifications " . count($certs) . "\n";

// ---- Evidence, so the compliance engine has something to decide on ---------
// Deliberately uneven. The point of these fixtures is that the SAME person gets
// a different answer per service, and that a single lapsed credential blocks
// exactly one service and no others.
//
// Dmitri  full evidence, SIA valid       -> eligible for security
// Esi     full evidence, SIA lapsed      -> blocked for security, fine for cleaning
// Grace   full evidence, cleaning        -> eligible for cleaning
// Hana    no right to work               -> blocked everywhere
$ins = $conn->prepare('INSERT INTO worker_references (worker_id, referee_name, referee_org, relationship, email, status) VALUES (?, ?, ?, "Line manager", ?, "verified")');
foreach (['Amara Chen', 'Bola Adeyemi', 'Dmitri Volkov', 'Esi Mensah', 'Grace Okafor', 'Hana Yilmaz'] as $who) {
    $refName = 'Referee for ' . $who;
    $org = 'Previous Employer Ltd';
    $refEmail = 'referee@example.invalid';
    $ins->bind_param('isss', $workerIds[$who], $refName, $org, $refEmail);
    $ins->execute();
}

// File metadata only: these rows exist so the compliance engine has documents to
// find. No file is written to disk, so downloading one will 404 -- they are
// fixtures for the evidence model, not for the document viewer.
$ins = $conn->prepare('INSERT INTO files (stored_name, original_name, mime, size_bytes, category, worker_id) VALUES (?, ?, "application/pdf", 1024, ?, ?)');
foreach (['Amara Chen', 'Bola Adeyemi', 'Dmitri Volkov', 'Esi Mensah', 'Grace Okafor', 'Hana Yilmaz'] as $who) {
    foreach (['contract' => 'Contract of employment.pdf', 'id' => 'Identity document.pdf'] as $category => $original) {
        $stored = bin2hex(random_bytes(8)) . '.pdf';
        $ins->bind_param('sssi', $stored, $original, $category, $workerIds[$who]);
        $ins->execute();
    }
}
echo "  evidence     6 reference sets, 12 documents\n";

// Identity is evidenced by a document, so record it as a certification too --
// the requirement catalogue treats 'Identity' as a non-expiring, verified item.
$ins = $conn->prepare('INSERT INTO certifications (worker_id, type, expiry, status) VALUES (?, "Identity", NULL, "valid")');
foreach (['Amara Chen', 'Bola Adeyemi', 'Dmitri Volkov', 'Esi Mensah', 'Grace Okafor', 'Hana Yilmaz'] as $who) {
    $ins->bind_param('i', $workerIds[$who]);
    $ins->execute();
}

// ---- Applicants across the pipeline ----------------------------------------
$applicants = [
    ['Kofi Baptiste', 'kofi@local.invalid',   '07700900201', 'Care assistant',  'care',     'applied'],
    ['Lena Novak',    'lena@local.invalid',   '07700900202', 'Security officer','security', 'screened'],
    ['Miguel Santos', 'miguel@local.invalid', '07700900203', 'Cleaner',         'cleaning', 'interview'],
    ['Nadia Rahman',  'nadia@local.invalid',  '07700900204', 'Care assistant',  'care',     'vetting'],
    ['Omar Diallo',   'omar@local.invalid',   '07700900205', 'Security officer','security', 'offer'],
];
$ins = $conn->prepare('INSERT INTO applicants (name, email, phone, role_applied, division, stage, source, person_id) VALUES (?, ?, ?, ?, ?, ?, "seed", ?)');
foreach ($applicants as $a) {
    $personId = find_or_create_person($conn, $a[0], $a[1], $a[2]);
    $ins->bind_param('ssssssi', $a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $personId);
    $ins->execute();
}
echo "  applicants   " . count($applicants) . "\n";

// ---- Shifts: some assigned, some open, across today and the coming week ----
$ins = $conn->prepare(
    'INSERT INTO shifts (worker_id, client_id, division, title, site_name, starts_at, ends_at, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$shifts = [
    [$workerIds['Amara Chen'],   $clientIds['Oakleaf Care Home'], 'care',     'Day carer',        'Oakleaf — Main House', $dt('today 08:00'),    $dt('today 16:00'),    'scheduled'],
    [$workerIds['Dmitri Volkov'],$clientIds['Bramley Estates'],   'security', 'Night guard',      'Bramley Court',        $dt('today 20:00'),    $dt('tomorrow 06:00'), 'scheduled'],
    [$workerIds['Grace Okafor'], $clientIds['Bramley Estates'],   'cleaning', 'Communal clean',   'Bramley Heights',      $dt('today 06:00'),    $dt('today 10:00'),    'scheduled'],
    [null,                        $clientIds['Northgate Events'],  'security', 'Event steward',    'Northgate Arena',      $dt('+2 days 18:00'),  $dt('+2 days 23:59'),  'open'],
    [null,                        $clientIds['Oakleaf Care Home'], 'care',     'Waking night',     'Oakleaf — Main House', $dt('+3 days 20:00'),  $dt('+4 days 08:00'),  'open'],
    [$workerIds['Bola Adeyemi'], $clientIds['Oakleaf Care Home'], 'care',     'Nurse cover',      'Oakleaf — Main House', $dt('+1 day 08:00'),   $dt('+1 day 20:00'),   'scheduled'],
];
foreach ($shifts as $s) {
    $ins->bind_param('iissssss', $s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7]);
    $ins->execute();
}
echo "  shifts       " . count($shifts) . " (2 open, for the matching path)\n";

// ---- Academy ---------------------------------------------------------------
$ins = $conn->prepare('INSERT INTO programmes (title, description, division, status, cpd_hours) VALUES (?, ?, "academy", "published", ?)');
$programmes = [
    ['Care Certificate (16 standards)', 'The full Care Certificate for new starters.', 40],
    ['Moving & Handling',               'Practical moving and handling for care settings.', 6],
    ['Conflict Management',             'De-escalation for licensed security staff.', 8],
];
$programmeIds = [];
foreach ($programmes as $p) {
    $ins->bind_param('ssi', $p[0], $p[1], $p[2]);
    $ins->execute();
    $programmeIds[$p[0]] = $conn->insert_id;
}
$ins = $conn->prepare('INSERT INTO enrolments (programme_id, learner_name, status) VALUES (?, ?, ?)');
foreach ([
    [$programmeIds['Care Certificate (16 standards)'], 'Kofi Baptiste', 'in_progress'],
    [$programmeIds['Care Certificate (16 standards)'], 'Nadia Rahman',  'completed'],
    [$programmeIds['Conflict Management'],             'Omar Diallo',   'enrolled'],
] as $e) {
    $ins->bind_param('iss', $e[0], $e[1], $e[2]);
    $ins->execute();
}
echo "  programmes   " . count($programmes) . " (3 enrolments)\n";

// ---- Enquiries and an incident, so those boards are not empty --------------
$ins = $conn->prepare('INSERT INTO contact_enquiries (name, email, phone, division, message) VALUES (?, ?, ?, ?, ?)');
foreach ([
    ['Rachel Adeniyi', 'rachel@local.invalid', '07700900301', 'care',     'Do you cover weekend respite in E17?'],
    ['Paul Whitmore',  'paul@local.invalid',   '07700900302', 'security', 'Need two door supervisors for a 200-capacity venue.'],
] as $e) {
    $ins->bind_param('sssss', $e[0], $e[1], $e[2], $e[3], $e[4]);
    $ins->execute();
}

// Through next_seq(), not hard-coded: the fixture used to write INC-<year>-0001
// directly and leave the counter at zero, so the first incident anybody filed on
// a freshly seeded database drew 0001 again and died on the unique index with a
// 500. See docs/RULES.md — next_seq() for any sequence, no exceptions.
$year = date('Y');
$ref = sprintf('INC-%s-%04d', $year, next_seq($conn, "incident-$year"));
$ins = $conn->prepare(
    'INSERT INTO incidents (ref, division, type, severity, title, detail, client_id, site_name, worker_id, status, occurred_at)
     VALUES (?, "cleaning", "near_miss", "medium", ?, ?, ?, ?, ?, "open", ?)'
);
$ititle = 'Wet floor sign missing in communal stairwell';
$idetail = 'Operative reported a mopped stairwell with no signage. No injury.';
$isite = 'Bramley Heights';
$iwhen = $dt('-2 days 09:30');
$ins->bind_param('sssisis', $ref, $ititle, $idetail, $clientIds['Bramley Estates'], $isite, $workerIds['Grace Okafor'], $iwhen);
$ins->execute();
echo "  enquiries    2 (1 incident)\n";

echo "\nDone. Sign in at http://localhost:5122/login\n";
echo "  admin@local.invalid / LocalDev123!    office\n";
echo "  amara.login@local.invalid             worker app at /m\n";
echo "  priya@local.invalid                   client portal at /portal\n";
echo "  (every seeded account uses the same fixture password)\n";
