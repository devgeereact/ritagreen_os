<?php
// Compliance expiry digest — emails the office about certifications that are
// expired or expiring within 30 days, so nothing lapses unnoticed (and a worker
// isn't blocked at scheduling time by surprise).
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
$res = $conn->query(
    'SELECT w.name AS worker, w.division, c.type, c.expiry,
            DATEDIFF(c.expiry, CURDATE()) AS days
     FROM certifications c
     JOIN workers w ON w.id = c.worker_id
     WHERE w.deleted_at IS NULL AND c.expiry IS NOT NULL
       AND c.expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY c.expiry ASC'
);

$expired = [];
$soon = [];
while ($r = $res->fetch_assoc()) {
    $days = (int) $r['days'];
    $when = $days < 0 ? abs($days) . ' days ago' : ($days === 0 ? 'today' : "in $days days");
    $line = sprintf('%s (%s) — %s — expires %s (%s)', $r['worker'], $r['division'], $r['type'], $r['expiry'], $when);
    if ($days < 0) $expired[] = $line;
    else $soon[] = $line;
}

if (!$expired && !$soon) {
    echo "Compliance digest $today: nothing expired or expiring within 30 days.\n";
    exit;
}

$body = "RitaGreen compliance digest — $today\n\n";
if ($expired) {
    $body .= 'EXPIRED (' . count($expired) . "):\n - " . implode("\n - ", $expired) . "\n\n";
}
if ($soon) {
    $body .= 'EXPIRING within 30 days (' . count($soon) . "):\n - " . implode("\n - ", $soon) . "\n\n";
}
$body .= "A worker with an expired or missing required check (DBS for care, SIA for security) "
    . "cannot be scheduled. Renew the items above to keep cover available.\n";

$subject = sprintf('Compliance digest — %d expired, %d expiring', count($expired), count($soon));

if (function_exists('mail_enabled') && mail_enabled()) {
    send_mail(MAIL_TO, $subject, $body);
    echo "Sent: $subject -> " . MAIL_TO . "\n";
} else {
    // Mail disabled — print so cron output/logs still show the digest.
    echo "[mail disabled] $subject\n\n$body";
}
