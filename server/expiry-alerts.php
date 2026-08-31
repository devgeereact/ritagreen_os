<?php
// Compliance renewal reminder engine.
// Scans every tracked credential (DBS + SIA on the worker row, extra
// certifications, and company insurance) and emails renewal reminders at the
// 90 / 60 / 30-day marks and once a credential has expired. Each (credential,
// tier) is sent only once — recorded in compliance_reminders — so the 60-day
// nudge never fires twice. Expired items re-nag at most weekly.
//
// The office always gets a single digest. Workers with an email on file also
// get a personal nudge for their own items at the 30-day and expired stages.
//
// Designed to be run by cron, once a day. Two ways to trigger:
//   CLI (preferred):  php /home/<user>/ritagreenservices.com/expiry-alerts.php
//   HTTP (needs key):  wget -qO- "https://ritagreenservices.com/expiry-alerts.php?key=YOUR_ALERTS_KEY"
//
// HTTP access is refused unless ?key matches ALERTS_KEY in config.php, so the
// digest can't be triggered (or scraped) by the public.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    $expected = defined('ALERTS_KEY') ? ALERTS_KEY : '';
    if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, (string) $key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$today = date('Y-m-d');

// Reassessment runs FIRST, and unconditionally.
//
// It used to sit at the end of this file, after an `exit` taken when no reminder
// was due -- so on a quiet day nothing was recalculated at all, and eligibility
// went stale on exactly the days nobody was watching. Running it first also
// means the reminders below are composed from a fresh assessment.
// ----- Reassess eligibility -------------------------------------------------
//
// Eligibility is calculated, which means it goes stale the moment a credential
// lapses -- not when somebody next opens a page. Recomputing it here, on the
// same daily run that chases expiries, is what makes the Action Centre and the
// dashboards true without anyone having looked.
//
// Only people the business actually intends for a service are assessed. Scoring
// every person against every service would produce a wall of "not eligible for
// Security" for cleaners who were never going to work security.
$assessed = 0;
$blocked = 0;

$res = $conn->query(
    'SELECT DISTINCT w.person_id, w.role, w.division
     FROM workers w
     WHERE w.deleted_at IS NULL AND w.person_id IS NOT NULL AND w.status IN ("active", "onboarding")'
);
while ($row = $res->fetch_assoc()) {
    $personId = (int) $row['person_id'];

    // The person is intended for the service their employment record names, plus
    // anything already recorded against them in person_services.
    $services = [$row['division']];
    $extra = $conn->prepare('SELECT service FROM person_services WHERE person_id = ? AND active = 1');
    $extra->bind_param('i', $personId);
    $extra->execute();
    $eres = $extra->get_result();
    while ($e = $eres->fetch_assoc()) $services[] = $e['service'];

    foreach (array_unique($services) as $service) {
        $verdict = rg_assess_person($conn, $personId, $service, $row['role']);
        $assessed++;
        if (!$verdict['eligible']) $blocked++;
    }
}

echo "\nEligibility reassessed: $assessed person-service pairs, $blocked not eligible.\n";

// ----- Derive worker status -------------------------------------------------
//
// The manual status column is the INTENT; this is what is actually true given
// the evidence. Recomputed here rather than on read, so the office sees the same
// answer everywhere without every page paying for the query.
$statusCounts = [];
$res = $conn->query(
    'SELECT w.id, w.person_id, w.status, w.left_on,
            (SELECT COUNT(*) FROM person_services ps
              WHERE ps.person_id = w.person_id AND ps.active = 1
                AND ps.eligibility_status IN ("eligible","eligible_with_warnings")) AS eligible_services,
            (SELECT COUNT(*) FROM leave_records l
              WHERE l.person_id = w.person_id AND l.status IN ("approved","taken")
                AND CURDATE() BETWEEN l.starts_on AND l.ends_on) AS on_leave_today,
            (SELECT COUNT(*) FROM onboarding_tasks ot
              JOIN applicants a ON a.id = ot.applicant_id
              WHERE a.person_id = w.person_id AND ot.done = 0) AS onboarding_outstanding,
            (SELECT COUNT(*) FROM files f
              WHERE f.worker_id = w.id AND f.category = "contract" AND f.deleted_at IS NULL) AS contracts
     FROM workers w WHERE w.deleted_at IS NULL AND w.person_id IS NOT NULL'
);

$update = $conn->prepare('UPDATE workers SET derived_status = ?, derived_status_reason = ?, derived_at = NOW() WHERE id = ?');
while ($row = $res->fetch_assoc()) {
    $derived = rg_derive_worker_status(
        ['status' => $row['status'], 'left_on' => $row['left_on']],
        [
            'eligible_services' => (int) $row['eligible_services'],
            'on_leave_today' => (int) $row['on_leave_today'] > 0,
            'onboarding_outstanding' => (int) $row['onboarding_outstanding'],
            'has_contract' => (int) $row['contracts'] > 0,
        ]
    );
    $id = (int) $row['id'];
    $update->bind_param('ssi', $derived['status'], $derived['reason'], $id);
    $update->execute();
    $statusCounts[$derived['status']] = ($statusCounts[$derived['status']] ?? 0) + 1;
}

echo "Worker status derived: ";
foreach ($statusCounts as $status => $n) echo "$status=$n ";
echo "\n";


// ----- 1. Gather every credential with an expiry date -----
// Each item: key, label, expiry, days, worker (name|null), worker_email, division.
$items = [];

// DBS + SIA live on the worker row.
$res = $conn->query(
    'SELECT id, name, email, division, dbs_expiry, sia_number, sia_expiry,
            DATEDIFF(dbs_expiry, CURDATE()) AS dbs_days,
            DATEDIFF(sia_expiry, CURDATE()) AS sia_days
     FROM workers WHERE deleted_at IS NULL'
);
while ($w = $res->fetch_assoc()) {
    if ($w['dbs_expiry']) {
        $items[] = [
            'key' => 'dbs:' . $w['id'], 'label' => 'DBS check',
            'expiry' => $w['dbs_expiry'], 'days' => (int) $w['dbs_days'],
            'worker' => $w['name'], 'worker_email' => $w['email'], 'division' => $w['division'],
        ];
    }
    if ($w['sia_expiry']) {
        $items[] = [
            'key' => 'sia:' . $w['id'], 'label' => 'SIA licence' . ($w['sia_number'] ? " ({$w['sia_number']})" : ''),
            'expiry' => $w['sia_expiry'], 'days' => (int) $w['sia_days'],
            'worker' => $w['name'], 'worker_email' => $w['email'], 'division' => $w['division'],
        ];
    }
}

// Extra certifications.
$res = $conn->query(
    'SELECT c.id, c.type, c.expiry, w.name AS worker, w.email AS worker_email, w.division,
            DATEDIFF(c.expiry, CURDATE()) AS days
     FROM certifications c
     JOIN workers w ON w.id = c.worker_id
     WHERE w.deleted_at IS NULL AND c.expiry IS NOT NULL'
);
while ($r = $res->fetch_assoc()) {
    $items[] = [
        'key' => 'cert:' . $r['id'], 'label' => $r['type'],
        'expiry' => $r['expiry'], 'days' => (int) $r['days'],
        'worker' => $r['worker'], 'worker_email' => $r['worker_email'], 'division' => $r['division'],
    ];
}

// Company insurance (no worker — surfaced to the office only).
$res = $conn->query(
    'SELECT id, type, insurer, expiry, DATEDIFF(expiry, CURDATE()) AS days
     FROM insurance WHERE deleted_at IS NULL AND expiry IS NOT NULL'
);
while ($r = $res->fetch_assoc()) {
    $typeLabel = ucwords(str_replace('_', ' ', $r['type']));
    $items[] = [
        'key' => 'ins:' . $r['id'], 'label' => "$typeLabel insurance ({$r['insurer']})",
        'expiry' => $r['expiry'], 'days' => (int) $r['days'],
        'worker' => null, 'worker_email' => null, 'division' => 'company',
    ];
}

// ----- 2. Bucket each item into a reminder tier -----
// Which reminder tier a credential falls into. Implementation in pure.php, so
// the banding is defined once and is testable without a database.
function tier_for(int $days): ?string
{
    return rg_reminder_tier($days);
}

// Has this (credential, tier) already been reminded? Future tiers (d90/d60/d30)
// fire once ever. 'expired' re-nags at most weekly so it stays on the radar.
function already_sent(mysqli $conn, string $key, string $tier): bool
{
    if ($tier === 'expired') {
        $stmt = $conn->prepare(
            'SELECT 1 FROM compliance_reminders
             WHERE credential_key = ? AND tier = ? AND sent_at > (NOW() - INTERVAL 7 DAY) LIMIT 1'
        );
    } else {
        $stmt = $conn->prepare(
            'SELECT 1 FROM compliance_reminders WHERE credential_key = ? AND tier = ? LIMIT 1'
        );
    }
    $stmt->bind_param('ss', $key, $tier);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

function mark_sent(mysqli $conn, string $key, string $tier): void
{
    $stmt = $conn->prepare('INSERT INTO compliance_reminders (credential_key, tier) VALUES (?, ?)');
    $stmt->bind_param('ss', $key, $tier);
    $stmt->execute();
}

$due = [];                 // items needing a reminder now, grouped by tier
$perWorker = [];           // worker_email => list of their own due lines
foreach ($items as $it) {
    $tier = tier_for($it['days']);
    if ($tier === null) continue;
    if (already_sent($conn, $it['key'], $tier)) continue;

    $it['tier'] = $tier;
    $due[] = $it;
    mark_sent($conn, $it['key'], $tier);

    // Personal nudge: only the urgent stages, and only if we can reach them.
    if (($tier === 'd30' || $tier === 'expired') && $it['worker_email']
        && filter_var($it['worker_email'], FILTER_VALIDATE_EMAIL)) {
        $perWorker[$it['worker_email']][] = $it;
    }
}

if (!$due) {
    echo "Compliance reminders $today: nothing newly due at the 90/60/30/expired marks.\n";
    exit;
}

// ----- 3. Compose & send the office digest -----
function describe(array $it): string
{
    $d = $it['days'];
    $when = $d < 0 ? abs($d) . ' days ago' : ($d === 0 ? 'today' : "in $d days");
    $who = $it['worker'] ? "{$it['worker']} ({$it['division']})" : 'Company';
    return sprintf('%s — %s — expires %s (%s)', $who, $it['label'], $it['expiry'], $when);
}

$order = ['expired' => 0, 'd30' => 1, 'd60' => 2, 'd90' => 3];
usort($due, fn($a, $b) => [$order[$a['tier']], $a['days']] <=> [$order[$b['tier']], $b['days']]);

$groups = ['expired' => [], 'd30' => [], 'd60' => [], 'd90' => []];
foreach ($due as $it) $groups[$it['tier']][] = describe($it);

$titles = [
    'expired' => 'EXPIRED — block from rostering now',
    'd30' => 'Expiring within 30 days',
    'd60' => 'Expiring within 60 days',
    'd90' => 'Expiring within 90 days',
];

$body = "RitaGreen compliance reminders — $today\n\n";
foreach ($titles as $tier => $heading) {
    if (!$groups[$tier]) continue;
    $body .= $heading . ' (' . count($groups[$tier]) . "):\n - " . implode("\n - ", $groups[$tier]) . "\n\n";
}
$body .= "A worker with an expired or missing required check (DBS for care, SIA for security) "
    . "cannot be scheduled. Renew the items above to keep cover available.\n";

$expiredCount = count($groups['expired']);
$soonCount = count($groups['d30']) + count($groups['d60']) + count($groups['d90']);
$subject = sprintf('Compliance reminders — %d expired, %d upcoming', $expiredCount, $soonCount);

$sent = [];
if (function_exists('mail_enabled') && mail_enabled()) {
    send_mail(MAIL_TO, $subject, $body);
    $sent[] = MAIL_TO;

    // Per-worker personal nudges.
    foreach ($perWorker as $email => $list) {
        $name = $list[0]['worker'];
        $lines = array_map(fn($it) => '- ' . $it['label'] . ' — expires ' . $it['expiry']
            . ($it['days'] < 0 ? ' (EXPIRED)' : " (in {$it['days']} days)"), $list);
        $wb = "Hi $name,\n\nA quick reminder that the following need renewing so you can keep working:\n\n"
            . implode("\n", $lines)
            . "\n\nPlease get these updated as soon as you can. Speak to your coordinator if you need help.\n\nRitaGreen Services";
        send_mail($email, 'Action needed: your compliance is expiring', $wb);
        $sent[] = $email;
    }
    echo "Sent: $subject -> " . implode(', ', $sent) . "\n";
} else {
    // Mail disabled — print so cron output/logs still show the digest.
    echo "[mail disabled] $subject\n\n$body";
}
