<?php
// Automated database backup — run by cron, daily.
//   CLI:  php /home/<user>/ritagreenservices.com/backup.php
//   HTTP: wget -qO- --header="X-Cron-Key: YOUR_ALERTS_KEY" "https://ritagreenservices.com/backup.php"
//         (the older "?key=YOUR_ALERTS_KEY" form still works, but lands in the access log)
//
// Writes a gzipped mysqldump to a backups/ folder ABOVE the document root (so
// it's never web-accessible) and prunes dumps older than 14 days. Reuses
// ALERTS_KEY to guard HTTP triggering. Requires shell_exec + mysqldump; if the
// host disables them it reports clearly instead of failing silently.
require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    // Throttle the key check itself. hash_equals stops a timing attack, not an
    // attacker who simply tries a lot of keys; without a limit the guard was a
    // single shared secret with unlimited attempts against it.
    rate_limit($conn, 'cron-key', 10, 600);
    // X-Cron-Key header preferred, ?key= still accepted. See rg_cron_key().
    $key = rg_cron_key($_SERVER, $_GET);
    $expected = defined('ALERTS_KEY') ? ALERTS_KEY : '';
    if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, $key)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

rg_cron_started($conn, 'backup');

if (!function_exists('shell_exec')) {
    echo "shell_exec is disabled on this host — use cPanel's scheduled backups instead.\n";
    exit;
}

// Keep dumps outside the web root.
$dir = dirname(__DIR__) . '/backups';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
if (!is_dir($dir)) {
    echo "Could not create backup directory $dir\n";
    exit;
}

$stamp = date('Y-m-d_His');
$file = "$dir/ritagreen_os-$stamp.sql.gz";

$cmd = sprintf(
    'mysqldump --single-transaction --quick --host=%s --user=%s --password=%s %s 2>/dev/null | gzip > %s',
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg(DB_NAME),
    escapeshellarg($file)
);
shell_exec($cmd);

clearstatcache();
if (!file_exists($file) || filesize($file) < 100) {
    echo "Backup failed — mysqldump may be unavailable. Check cPanel.\n";
    @unlink($file);
    exit;
}

// Prune dumps older than 14 days.
$pruned = 0;
foreach (glob("$dir/ritagreen_os-*.sql.gz") as $old) {
    if (filemtime($old) < time() - 14 * 86400) {
        @unlink($old);
        $pruned++;
    }
}

$size = round(filesize($file) / 1024, 1);
rg_cron_succeeded($conn, 'backup', "{$size} KB, pruned $pruned");
echo "Backup OK:$file ({$size} KB). Pruned $pruned old dump(s).\n";
