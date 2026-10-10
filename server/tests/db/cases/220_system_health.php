<?php
// System health in Settings (section S5): scheduled jobs report when they last
// ran, "unknown" until they do; the email queue and form intake are shown
// without recipients; a scoped account is refused company-wide counts.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');
$editor = $client->login('editor@local.invalid');

$jobState = static function (RgtResponse $res, string $key): ?array {
    foreach ($res->get('jobs', []) as $j) if ($j['key'] === $key) return $j;
    return null;
};

Rgt::group('system health: who may read it');
Rgt::denied('an editor cannot', $editor->get('/system-health.php'));
Rgt::denied('a scoped manager cannot (every figure here spans all services)', $scoped->get('/system-health.php'));

Rgt::group('system health: scheduled jobs say "unknown" until they report');

$db->query('DELETE FROM cron_runs');
$h = $admin->get('/system-health.php');
Rgt::status('an admin reads it', $h, 200);
Rgt::same('four jobs are listed', array_column($h->get('jobs', []), 'key'),
    ['backup', 'expiry_alerts', 'retention_sweep', 'send_queued_mail']);
Rgt::same('none has reported, and none is passed off as fine',
    array_values(array_unique(array_column($h->get('jobs', []), 'state'))), ['unknown']);

$out = rgt_run_cli(__DIR__ . '/../../../expiry-alerts.php', ['--reassess-only'], __DIR__ . '/../config.php');
Rgt::ok('the expiry sweep runs from the command line', str_contains($out, 'Eligibility reassessed'), $out);
$out = rgt_run_cli(__DIR__ . '/../../../retention-sweep.php', [], __DIR__ . '/../config.php');
Rgt::ok('so does the retention sweep (report only)', str_contains($out, 'Dry run'), $out);

$h = $admin->get('/system-health.php');
$exp = $jobState($h, 'expiry_alerts');
Rgt::same('after a run the expiry sweep reads as on time', $exp['state'] ?? null, 'ok');
Rgt::ok('with when it last succeeded and what it did', ($exp['last_success_at'] ?? null) !== null && str_contains((string) $exp['last_detail'], 'reassessed'));
Rgt::same('the retention sweep says it only reported', str_starts_with((string) ($jobState($h, 'retention_sweep')['last_detail'] ?? ''), 'report only'), true);
Rgt::same('the backup, never run here, is still unknown', $jobState($h, 'backup')['state'] ?? null, 'unknown');

$db->query("UPDATE cron_runs SET last_success_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY) WHERE job = 'expiry_alerts'");
$h = $admin->get('/system-health.php');
Rgt::same('a daily job silent for three days is late', $jobState($h, 'expiry_alerts')['state'] ?? null, 'late');

Rgt::group('system health: email queue and form intake');

$db->query("INSERT INTO email_queue (recipient, subject, body, status, attempts, last_error)
            VALUES ('private.person@local.invalid', 'S5 probe failure', 'x', 'failed', 5, 'SMTP said no')");
$h = $admin->get('/system-health.php');
Rgt::ok('failed mail is counted', (int) $h->get('mail_queue.counts.failed') >= 1);
$subjects = array_column($h->get('mail_queue.recent_failures', []), 'subject');
Rgt::ok('and listed with its subject', in_array('S5 probe failure', $subjects, true));
Rgt::ok('but never with its recipient', !str_contains($h->body, 'private.person@local.invalid'));
Rgt::ok('form intake lists each integration', count($h->get('forms', [])) > 0);
$db->query("DELETE FROM email_queue WHERE subject = 'S5 probe failure'");
