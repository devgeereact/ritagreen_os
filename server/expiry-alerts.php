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
