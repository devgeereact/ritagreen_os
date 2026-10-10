<?php
// System health and staff workload.
//
//   GET /system-health.php            is the system itself working?
//   GET /system-health.php?action=workload   who is carrying what
//
// The owner should not learn that recruitment stopped importing three weeks ago
// from a candidate ringing up. Everything here is a live check, not a stored
// status: a health table would go stale exactly when it mattered.
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
// The email queue, form intake and the workload table carry no service, so any
// count here is a count across every service. A manager confined to one service
// is refused rather than shown company-wide figures (S5 audit; the Action
// Centre applies the same rule through rg_action_centre_access()).
if (service_scope($user) !== null) fail('You do not have access to this.', 403);
if (method() !== 'GET') fail('Method not allowed.', 405);

$action = $_GET['action'] ?? '';

// ---- Staff workload --------------------------------------------------------
// Not a performance measure. It exists so work can be moved before somebody
// quietly drowns in it, which is only possible if it is visible.
if ($action === 'workload') {
    $res = $conn->query(
        'SELECT u.id, u.name, u.role, u.division,
                (SELECT COUNT(*) FROM applicants a
                  WHERE a.deleted_at IS NULL AND a.stage IN ("screened","interview","vetting","offer")
                    AND (u.division IS NULL OR a.division = u.division)) AS open_candidates,
                (SELECT COUNT(*) FROM incidents i
                  WHERE i.deleted_at IS NULL AND i.status IN ("open","investigating") AND i.reported_by = u.id) AS open_incidents,
                (SELECT COUNT(*) FROM leave_records l WHERE l.status = "requested"
                    AND (u.role IN ("super_admin","admin","manager"))) AS leave_to_decide,
                (SELECT COUNT(*) FROM deletion_requests d WHERE d.status = "requested"
                    AND u.role IN ("super_admin","admin")) AS approvals_waiting,
                (SELECT COUNT(*) FROM audit_log al
                  WHERE al.user_id = u.id AND al.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) AS actions_7d
         FROM users u
         WHERE u.deleted_at IS NULL AND u.role NOT IN ("client") AND u.worker_id IS NULL
         ORDER BY u.name'
    );

    $staff = [];
    while ($row = $res->fetch_assoc()) {
        $queue = (int) $row['open_candidates'] + (int) $row['open_incidents']
               + (int) $row['leave_to_decide'] + (int) $row['approvals_waiting'];
        $staff[] = [
            'id' => (int) $row['id'], 'name' => $row['name'], 'role' => $row['role'],
            'division' => $row['division'],
            'open_candidates' => (int) $row['open_candidates'],
            'open_incidents' => (int) $row['open_incidents'],
            'leave_to_decide' => (int) $row['leave_to_decide'],
            'approvals_waiting' => (int) $row['approvals_waiting'],
            'queue' => $queue,
            'actions_7d' => (int) $row['actions_7d'],
        ];
    }
    usort($staff, static fn(array $a, array $b): int => $b['queue'] <=> $a['queue']);
    json_out(['staff' => $staff, 'generated_at' => date('Y-m-d H:i')]);
}

// ---- System health ---------------------------------------------------------
$checks = [];
$add = static function (string $key, string $label, string $state, string $detail) use (&$checks): void {
    // ok | warn | fail | off  -- 'off' is a deliberate configuration state, not
    // a problem, and must not read as one on a dashboard.
    $checks[] = ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail];
};

$scalar = static function (mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    return $res ? (int) $res->fetch_row()[0] : 0;
};

// Database. If this file is running at all the connection worked, so report
// something more useful than "connected".
$tables = $scalar($conn, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
$migrations = $scalar($conn, 'SELECT COUNT(*) FROM schema_migrations');
$add('database', 'Database', 'ok', "$tables tables, $migrations migrations applied");

// Migrations pending on disk but not recorded. A deploy that shipped the files
// and never ran them looks completely normal until something fails oddly.
$applied = [];
$res = $conn->query('SELECT filename FROM schema_migrations');
while ($row = $res->fetch_assoc()) $applied[$row['filename']] = true;
$onDisk = array_map('basename', glob(__DIR__ . '/migrations/*.sql') ?: []);
$pending = array_values(array_filter($onDisk, static fn(string $f): bool => !isset($applied[$f])));
$add('migrations', 'Schema migrations',
    $pending ? 'warn' : 'ok',
    $pending ? count($pending) . ' pending: ' . implode(', ', $pending) : 'Up to date');

// Mail.
if (!mail_enabled()) {
    $add('mail', 'Outgoing mail', 'off', 'Disabled in config.php (MAIL_ENABLED false)');
} else {
    $failed = $scalar($conn, 'SELECT COUNT(*) FROM email_queue WHERE status = "failed"');
    $stuck = $scalar($conn, 'SELECT COUNT(*) FROM email_queue WHERE status = "queued" AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)');
    $sent = $scalar($conn, 'SELECT COUNT(*) FROM email_log WHERE outcome = "sent" AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $add('mail', 'Outgoing mail',
        $failed || $stuck ? 'fail' : 'ok',
        $stuck ? "$stuck queued over 2 hours — is the send-queued-mail cron running?"
               : ($failed ? "$failed failed to send" : "$sent sent in the last 7 days"));
}

// The queue drainer, inferred from the log rather than assumed.
$lastDrain = $conn->query('SELECT MAX(created_at) FROM email_log')->fetch_row()[0] ?? null;
$queued = $scalar($conn, 'SELECT COUNT(*) FROM email_queue WHERE status = "queued"');
$add('mail_cron', 'Mail queue drainer',
    $queued > 0 && $lastDrain === null ? 'fail' : ($lastDrain === null ? 'off' : 'ok'),
    $lastDrain === null ? ($queued > 0 ? "$queued waiting and the drainer has never run" : 'Never run; nothing waiting')
                        : "Last processed $lastDrain, $queued waiting");

// Integrations.
$res = $conn->query('SELECT COUNT(*) total, SUM(secret_hash IS NOT NULL AND active = 1) live FROM integrations');
$row = $res->fetch_assoc();
$silent = $scalar($conn,
    'SELECT COUNT(*) FROM integrations WHERE active = 1 AND secret_hash IS NOT NULL AND expect_within_hours IS NOT NULL
       AND (last_success_at IS NULL OR TIMESTAMPDIFF(HOUR, last_success_at, NOW()) > expect_within_hours)');
$add('integrations', 'Form intake',
    (int) $row['live'] === 0 ? 'off' : ($silent ? 'fail' : 'ok'),
    (int) $row['live'] === 0 ? 'No integration has a secret configured yet'
        : ($silent ? "$silent of {$row['live']} have gone quiet" : "{$row['live']} live, all reporting"));

// Compliance freshness. A verdict nobody has recalculated is a verdict that may
// already be wrong.
$assessed = $scalar($conn, 'SELECT COUNT(*) FROM person_services WHERE assessed_at IS NOT NULL');
$stale = $scalar($conn, 'SELECT COUNT(*) FROM person_services WHERE assessed_at IS NULL OR assessed_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)');
$add('compliance', 'Eligibility freshness',
    $assessed === 0 ? 'warn' : ($stale ? 'warn' : 'ok'),
    $assessed === 0 ? 'Nothing has been assessed. Run the expiry-alerts cron.'
        : ($stale ? "$stale of " . ($assessed + $stale) . " not reassessed in 48 hours" : "$assessed current"));

// Document storage.
$uploads = __DIR__ . '/uploads';
$add('storage', 'Document storage',
    is_dir($uploads) && is_writable($uploads) ? 'ok' : 'fail',
    is_dir($uploads) ? (is_writable($uploads) ? 'Writable' : 'NOT writable — uploads will fail')
                     : 'The uploads directory does not exist');

// Backups. server/backup.php writes above the document root.
$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    $add('backups', 'Database backups', 'warn', 'No backup directory found. Is the backup cron configured?');
} else {
    $files = glob($backupDir . '/*.gz') ?: [];
    $newest = 0;
    foreach ($files as $f) $newest = max($newest, (int) filemtime($f));
    $ageHours = $newest ? (int) round((time() - $newest) / 3600) : null;
    $add('backups', 'Database backups',
        $ageHours === null ? 'fail' : ($ageHours > 48 ? 'fail' : 'ok'),
        $ageHours === null ? 'No backups found' : count($files) . " backups, newest {$ageHours}h old");
}

// A backup nobody has restored is a hope, not a backup. There is no way to infer
// this, so it is reported as unknown rather than assumed to be fine.
$add('restore_test', 'Backup restore test', 'warn',
    'Not automated. A backup that has never been restored is an assumption — see docs/RULES.md rule 15.');

// ---- Scheduled jobs (migration 043) ---------------------------------------
// When each job last reported. A job with no row has never reported since the
// heartbeat was added; that is "unknown", said plainly, never "ok".
const HEALTH_JOBS = [
    'backup' => ['label' => 'Database backup', 'every_hours' => 24],
    'expiry_alerts' => ['label' => 'Expiry alerts and eligibility', 'every_hours' => 24],
    'retention_sweep' => ['label' => 'Retention sweep', 'every_hours' => 24 * 7],
    'send_queued_mail' => ['label' => 'Send queued mail', 'every_hours' => 1],
];
$runs = [];
$heartbeatReady = true;
try {
    $res = $conn->query('SELECT job, last_started_at, last_finished_at, last_success_at, last_detail, runs FROM cron_runs');
    while ($row = $res->fetch_assoc()) $runs[$row['job']] = $row;
} catch (Throwable $e) {
    // Migration 043 not applied yet: every job is unknown, and the reason is given.
    $heartbeatReady = false;
}
$jobs = [];
foreach (HEALTH_JOBS as $key => $job) {
    $r = $runs[$key] ?? null;
    $state = rg_cron_state($r['last_success_at'] ?? null, $job['every_hours']);
    $jobs[] = [
        'key' => $key, 'label' => $job['label'], 'every_hours' => $job['every_hours'],
        'state' => $state,
        'last_started_at' => $r['last_started_at'] ?? null,
        'last_success_at' => $r['last_success_at'] ?? null,
        'last_detail' => $r['last_detail'] ?? null,
        'runs' => isset($r['runs']) ? (int) $r['runs'] : 0,
        'note' => !$heartbeatReady ? 'Run migration 043 to start recording runs.'
            : ($r === null ? 'No run recorded since run times started being kept.'
            : ($r['last_success_at'] === null ? 'Started but never finished successfully.' : null)),
    ];
}
$unknownJobs = count(array_filter($jobs, static fn($j) => $j['state'] === 'unknown'));
$lateJobs = count(array_filter($jobs, static fn($j) => $j['state'] === 'late'));
$add('cron', 'Scheduled jobs',
    $lateJobs ? 'fail' : ($unknownJobs ? 'warn' : 'ok'),
    $lateJobs ? "$lateJobs late" . ($unknownJobs ? ", $unknownJobs unknown" : '')
        : ($unknownJobs ? "$unknownJobs of " . count($jobs) . ' have never reported' : 'All ran on time'));

// ---- Email queue -------------------------------------------------------------
// Counts by status and the most recent failures. The subject and the error are
// shown, never the recipient: Settings is for fixing the system, not for
// reading who was written to.
$queue = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS n FROM email_queue GROUP BY status');
while ($row = $res->fetch_assoc()) $queue[$row['status']] = (int) $row['n'];
$failures = [];
$res = $conn->query(
    'SELECT id, subject, template_key, attempts, last_error, created_at
       FROM email_queue WHERE status = "failed" ORDER BY id DESC LIMIT 10'
);
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int) $row['id'];
    $row['attempts'] = (int) $row['attempts'];
    $failures[] = $row;
}

// ---- Form intake -----------------------------------------------------------
$forms = [];
$res = $conn->query(
    'SELECT i.id, i.name, i.active, (i.secret_hash IS NOT NULL) AS live, i.expect_within_hours,
            i.last_success_at, i.last_failure_at, i.last_failure_reason,
            (SELECT COUNT(*) FROM integration_submissions s WHERE s.integration_id = i.id AND s.status = "failed") AS failed
       FROM integrations i ORDER BY i.name'
);
while ($row = $res->fetch_assoc()) {
    $live = (bool) $row['live'] && (bool) $row['active'];
    $quiet = $live && $row['expect_within_hours'] !== null
        && ($row['last_success_at'] === null || strtotime($row['last_success_at'] . ' UTC') < time() - (int) $row['expect_within_hours'] * 3600);
    $forms[] = [
        'id' => (int) $row['id'], 'name' => $row['name'],
        'state' => !$live ? 'off' : ((int) $row['failed'] > 0 || $quiet ? 'fail' : 'ok'),
        'live' => $live,
        'last_success_at' => $row['last_success_at'],
        'last_failure_at' => $row['last_failure_at'],
        'last_failure_reason' => $row['last_failure_reason'],
        'failed' => (int) $row['failed'],
        'quiet' => $quiet,
    ];
}

$states = array_column($checks, 'state');
$overall = in_array('fail', $states, true) ? 'fail' : (in_array('warn', $states, true) ? 'warn' : 'ok');

json_out([
    'overall' => $overall,
    'checks' => $checks,
    'counts' => array_count_values($states),
    'jobs' => $jobs,
    'mail_queue' => ['counts' => $queue, 'enabled' => mail_enabled(), 'recent_failures' => $failures],
    'forms' => $forms,
    'generated_at' => date('Y-m-d H:i'),
]);
