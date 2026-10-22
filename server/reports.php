<?php
// Reports: one small set of reliable reports, plus the older regulator packs.
//
//   GET /reports.php?action=list                              -> { reports: [{key, title, description}] }
//   GET /reports.php?action=run&report=KEY[&service=]          -> { report, columns, rows, totals, ... }
//   GET /reports.php?action=export&report=KEY[&service=][&purpose=]  -> text/csv, recorded in export_log
//
//   Reports (launch plan Phase 5, plan file 02 "Reports"):
//     pipeline   recruitment pipeline by stage and service
//     workforce  active and blocked workers by service, with the blocking reason  (compliance zone)
//     expiring   evidence expiring within 30, 60 and 90 days                    (compliance zone)
//     unfilled   unfilled shifts by week and service
//     hours      approved hours by week and service                             (payroll zone)
//     workload   staff workload: open candidates, open and overdue tasks
//
// Three rules, all from the launch plan's Phase 5:
//
//   Totals count the whole filtered dataset. Nothing here is paged, and every
//   total is summed from the rows returned, so a total cannot describe more or
//   less than the table under it.
//
//   A scoped account sees its own service only. The service filter can narrow
//   that further and never widen it.
//
//   An export carries the columns the report declares and nothing else
//   (rg_csv() writes only the declared keys), is built on the server, and is
//   recorded in export_log in the same request. It used to be built in the
//   browser from whatever the endpoint returned, and logging it was left to the
//   page, which did not.
//
// Older actions kept for existing callers: client_kpi (Clients page), cqc_pack,
// sia_acs, coshh, skills_for_care.
require __DIR__ . '/lib.php';
require __DIR__ . '/compliance-lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

const RPT_SERVICES = ['care', 'academy', 'cleaning', 'security'];

/**
 * The reports, with what each needs to be seen.
 *
 * zone: a zone the account must hold. personal: whether the rows name people,
 * which decides how the export is recorded.
 */
const RPT_REPORTS = [
    'pipeline' => ['title' => 'Recruitment pipeline', 'zone' => null, 'personal' => false,
        'description' => 'Candidates at each stage, by service. Open stages only, plus the last 90 days of outcomes.'],
    'workforce' => ['title' => 'Active and blocked workers', 'zone' => 'compliance', 'personal' => true,
        'description' => 'Every current worker by service, whether they can be deployed today and, if not, why.'],
    'expiring' => ['title' => 'Evidence expiring', 'zone' => 'compliance', 'personal' => true,
        'description' => 'DBS, SIA, right-to-work rechecks and certificates that lapse within 90 days, or already have.'],
    'unfilled' => ['title' => 'Unfilled shifts', 'zone' => null, 'personal' => false,
        'description' => 'Published and draft shifts with nobody on them, by week and service, this week and the next seven.'],
    'hours' => ['title' => 'Approved hours', 'zone' => 'payroll', 'personal' => false,
        'description' => 'Hours approved for pay, net of breaks, by week and service, for the last eight weeks.'],
    'workload' => ['title' => 'Staff workload', 'zone' => null, 'personal' => true,
        'description' => 'Who is carrying what: candidates they recruit for, onboarding tasks they own and how many are overdue.'],
];

$scope = service_scope($user);

/** May this account see this report? */
function rpt_allowed(mysqli $conn, array $user, string $key): bool
{
    $zone = RPT_REPORTS[$key]['zone'] ?? null;
    return isset(RPT_REPORTS[$key]) && ($zone === null || can_view_zone($conn, $user, $zone));
}

/** @return list<array<string,mixed>> */
function rpt_rows(mysqli $conn, string $sql, string $types = '', array $args = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$args);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

/**
 * Build one report.
 *
 * $service is the effective filter: the account's scope, or the requested
 * service, or null for every service.
 *
 * @return array{columns: array<string,string>, rows: list<array<string,mixed>>, totals: array<string,int|float>}
 */
function rpt_build(mysqli $conn, string $key, ?string $service): array
{
    $svcSql = static fn(string $col): string => $service !== null ? " AND $col = ?" : '';
    $svcArg = $service !== null ? [$service] : [];
    $svcType = $service !== null ? 's' : '';
    $today = date('Y-m-d');

    switch ($key) {
        case 'pipeline': {
            $rows = rpt_rows($conn,
                'SELECT a.division AS service, a.stage, COUNT(*) AS candidates
                   FROM applicants a
                  WHERE a.deleted_at IS NULL
                    AND (a.stage NOT IN ("rejected","withdrawn","onboarded") OR a.updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY))'
                . $svcSql('a.division') . ' GROUP BY a.division, a.stage', $svcType, $svcArg);
            $order = array_flip(array_merge(RG_STAGES, RG_STAGE_EXITS));
            usort($rows, static fn($x, $y) => [$x['service'], $order[$x['stage']] ?? 99] <=> [$y['service'], $order[$y['stage']] ?? 99]);
            foreach ($rows as &$r) $r['candidates'] = (int) $r['candidates'];
            unset($r);
            $open = array_sum(array_map(static fn($r) => in_array($r['stage'], ['rejected', 'withdrawn', 'onboarded'], true) ? 0 : $r['candidates'], $rows));
            return [
                'columns' => ['service' => 'Service', 'stage' => 'Stage', 'candidates' => 'Candidates'],
                'rows' => $rows,
                'totals' => ['candidates' => array_sum(array_column($rows, 'candidates')), 'open' => $open],
            ];
        }

        case 'workforce': {
            $workers = rpt_rows($conn,
                'SELECT w.id, w.person_id, COALESCE(p.full_name, w.name) AS name, w.role, w.division AS service, w.status
                   FROM workers w LEFT JOIN people p ON p.id = w.person_id
                  WHERE w.deleted_at IS NULL AND w.status NOT IN ("left","archived")'
                . $svcSql('w.division') . ' ORDER BY w.division, name', $svcType, $svcArg);
            $rows = [];
            foreach ($workers as $w) {
                // Screening, not deciding: nothing is written ($persist = false).
                $v = rg_deployment_check($conn, (int) $w['id'], $w['service'], false);
                $rows[] = [
                    'person_id' => $w['person_id'] !== null ? (int) $w['person_id'] : null,
                    'name' => $w['name'], 'role' => $w['role'], 'service' => $w['service'], 'status' => $w['status'],
                    'deployable' => (bool) ($v['eligible'] ?? false),
                    'blocked_by' => ($v['eligible'] ?? false) ? null : ($v['blocked_by'] ?? 'evidence'),
                    'reason' => ($v['eligible'] ?? false) ? null : ($v['reason'] ?? ''),
                ];
            }
            $deployable = count(array_filter($rows, static fn($r) => $r['deployable']));
            return [
                'columns' => ['name' => 'Name', 'role' => 'Role', 'service' => 'Service', 'status' => 'Status',
                    'deployable' => 'Can work', 'reason' => 'Why not'],
                'rows' => $rows,
                'totals' => ['workers' => count($rows), 'deployable' => $deployable, 'blocked' => count($rows) - $deployable],
            ];
        }

        case 'expiring': {
            $rows = [];
            $push = static function (array $r, string $evidence, ?string $expiry) use (&$rows, $today): void {
                if ($expiry === null || $expiry === '') return;
                $c = rg_classify($expiry, $today);
                $band = rg_expiry_band($c['days']);
                if ($band === null) return;
                $rows[] = ['person_id' => $r['person_id'] !== null ? (int) $r['person_id'] : null, 'name' => $r['name'],
                    'service' => $r['service'], 'evidence' => $evidence, 'expiry' => substr($expiry, 0, 10),
                    'days' => $c['days'], 'band' => $band];
            };
            $within = 'DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
            foreach (rpt_rows($conn,
                "SELECT w.person_id, COALESCE(p.full_name, w.name) AS name, w.division AS service, w.dbs_expiry, w.sia_expiry
                   FROM workers w LEFT JOIN people p ON p.id = w.person_id
                  WHERE w.deleted_at IS NULL AND (w.dbs_expiry <= $within OR w.sia_expiry <= $within)"
                . $svcSql('w.division'), $svcType, $svcArg) as $w) {
                // rg_expiry_band() drops anything further out than 90 days.
                $push($w, 'DBS check', $w['dbs_expiry']);
                $push($w, 'SIA licence', $w['sia_expiry']);
            }
            foreach (rpt_rows($conn,
                "SELECT w.person_id, COALESCE(p.full_name, w.name) AS name, w.division AS service, c.type, c.expiry
                   FROM certifications c JOIN workers w ON w.id = c.worker_id LEFT JOIN people p ON p.id = w.person_id
                  WHERE w.deleted_at IS NULL AND c.expiry IS NOT NULL AND c.expiry <= $within"
                . $svcSql('w.division'), $svcType, $svcArg) as $c) {
                $push($c, (string) $c['type'], $c['expiry']);
            }
            // Right to work is about the person, not a service: the recheck is
            // listed against each in-scope service the person works in.
            foreach (rpt_rows($conn,
                "SELECT r.person_id, p.full_name AS name, w.division AS service, r.recheck_due
                   FROM right_to_work_checks r JOIN people p ON p.id = r.person_id
                   JOIN workers w ON w.person_id = r.person_id AND w.deleted_at IS NULL
                  WHERE r.recheck_due IS NOT NULL AND r.recheck_due <= $within AND p.deleted_at IS NULL
                    AND r.id = (SELECT MAX(r2.id) FROM right_to_work_checks r2 WHERE r2.person_id = r.person_id)"
                . $svcSql('w.division'), $svcType, $svcArg) as $r) {
                $push($r, 'Right to work recheck', $r['recheck_due']);
            }
            usort($rows, static fn($a, $b) => $a['days'] <=> $b['days']);
            $totals = ['expired' => 0, '30' => 0, '60' => 0, '90' => 0];
            foreach ($rows as $r) $totals[$r['band']]++;
            return [
                'columns' => ['name' => 'Name', 'service' => 'Service', 'evidence' => 'Evidence', 'expiry' => 'Expires',
                    'days' => 'Days left', 'band' => 'Within'],
                'rows' => $rows,
                'totals' => ['items' => count($rows), 'expired' => $totals['expired'],
                    'within_30' => $totals['30'], 'within_60' => $totals['60'], 'within_90' => $totals['90']],
            ];
        }

        case 'unfilled': {
            $from = rg_week_start($today);
            $to = date('Y-m-d', strtotime($from . ' +56 days'));
            $raw = rpt_rows($conn,
                'SELECT s.division AS service, DATE(s.starts_at) AS day, s.status, s.is_draft
                   FROM shifts s
                  WHERE s.deleted_at IS NULL AND s.status IN ("open","scheduled") AND s.starts_at >= ? AND s.starts_at < ?'
                . $svcSql('s.division'), 'ss' . $svcType, array_merge([$from, $to], $svcArg));
            $by = [];
            foreach ($raw as $r) {
                $k = rg_week_start($r['day']) . '|' . $r['service'];
                $by[$k] ??= ['week' => rg_week_start($r['day']), 'service' => $r['service'], 'shifts' => 0, 'unfilled' => 0];
                $by[$k]['shifts']++;
                if ($r['status'] === 'open') $by[$k]['unfilled']++;
            }
            ksort($by);
            $rows = array_values($by);
            return [
                'columns' => ['week' => 'Week starting', 'service' => 'Service', 'shifts' => 'Shifts', 'unfilled' => 'Unfilled'],
                'rows' => $rows,
                'totals' => ['shifts' => array_sum(array_column($rows, 'shifts')), 'unfilled' => array_sum(array_column($rows, 'unfilled'))],
            ];
        }

        case 'hours': {
            $from = date('Y-m-d', strtotime(rg_week_start($today) . ' -49 days'));
            $raw = rpt_rows($conn,
                'SELECT w.division AS service, t.work_date, t.minutes, t.break_minutes
                   FROM timesheets t JOIN workers w ON w.id = t.worker_id
                  WHERE t.deleted_at IS NULL AND t.status = "approved" AND t.work_date >= ?'
                . $svcSql('w.division'), 's' . $svcType, array_merge([$from], $svcArg));
            $by = [];
            foreach ($raw as $r) {
                $k = rg_week_start($r['work_date']) . '|' . $r['service'];
                $by[$k] ??= ['week' => rg_week_start($r['work_date']), 'service' => $r['service'], 'entries' => 0, 'minutes' => 0];
                $by[$k]['entries']++;
                $by[$k]['minutes'] += max(0, (int) $r['minutes'] - (int) $r['break_minutes']);
            }
            ksort($by);
            $rows = [];
            foreach ($by as $b) {
                $rows[] = ['week' => $b['week'], 'service' => $b['service'], 'entries' => $b['entries'],
                    'hours' => round($b['minutes'] / 60, 2), '_minutes' => $b['minutes']];
            }
            return [
                'columns' => ['week' => 'Week starting', 'service' => 'Service', 'entries' => 'Timesheets', 'hours' => 'Approved hours'],
                'rows' => $rows,
                'totals' => ['entries' => array_sum(array_column($rows, 'entries')),
                    'hours' => round(array_sum(array_column($rows, '_minutes')) / 60, 2)],
            ];
        }

        case 'workload': {
            // Counted per owner over the records this account may see, so a
            // scoped manager sees colleagues' load in their own service only.
            $cands = rpt_rows($conn,
                'SELECT a.recruiter_id AS uid, COUNT(*) AS n FROM applicants a
                  WHERE a.deleted_at IS NULL AND a.recruiter_id IS NOT NULL
                    AND a.stage IN ("applied","screened","interview","vetting","offer","onboarding")'
                . $svcSql('a.division') . ' GROUP BY a.recruiter_id', $svcType, $svcArg);
            $tasks = rpt_rows($conn,
                'SELECT t.owner_id AS uid, COUNT(*) AS n, SUM(t.due_on IS NOT NULL AND t.due_on < CURDATE()) AS overdue
                   FROM onboarding_tasks t JOIN applicants a ON a.id = t.applicant_id
                  WHERE t.done = 0 AND t.owner_id IS NOT NULL AND a.deleted_at IS NULL'
                . $svcSql('a.division') . ' GROUP BY t.owner_id', $svcType, $svcArg);
            $load = [];
            foreach ($cands as $c) $load[(int) $c['uid']]['candidates'] = (int) $c['n'];
            foreach ($tasks as $t) {
                $load[(int) $t['uid']]['tasks'] = (int) $t['n'];
                $load[(int) $t['uid']]['overdue'] = (int) $t['overdue'];
            }
            $rows = [];
            foreach (rpt_rows($conn, 'SELECT id, name, role FROM users WHERE deleted_at IS NULL ORDER BY name') as $u) {
                $l = $load[(int) $u['id']] ?? null;
                if ($l === null) continue;
                $rows[] = ['name' => $u['name'], 'role' => $u['role'], 'candidates' => $l['candidates'] ?? 0,
                    'open_tasks' => $l['tasks'] ?? 0, 'overdue_tasks' => $l['overdue'] ?? 0];
            }
            usort($rows, static fn($a, $b) => [$b['overdue_tasks'], $b['open_tasks']] <=> [$a['overdue_tasks'], $a['open_tasks']]);
            // Work with nobody's name on it is still work.
            $unownedTasks = (int) (rpt_rows($conn,
                'SELECT COUNT(*) AS n FROM onboarding_tasks t JOIN applicants a ON a.id = t.applicant_id
                  WHERE t.done = 0 AND t.owner_id IS NULL AND a.deleted_at IS NULL' . $svcSql('a.division'), $svcType, $svcArg)[0]['n'] ?? 0);
            return [
                'columns' => ['name' => 'Staff member', 'role' => 'Role', 'candidates' => 'Candidates',
                    'open_tasks' => 'Open tasks', 'overdue_tasks' => 'Overdue tasks'],
                'rows' => $rows,
                'totals' => ['candidates' => array_sum(array_column($rows, 'candidates')),
                    'open_tasks' => array_sum(array_column($rows, 'open_tasks')),
                    'overdue_tasks' => array_sum(array_column($rows, 'overdue_tasks')),
                    'unowned_tasks' => $unownedTasks],
            ];
        }
    }
    fail('Unknown report.', 400);
}

/** The service to filter by: the account's scope, narrowed by a request, never widened. */
function rpt_service(?string $scope): ?string
{
    $asked = trim((string) ($_GET['service'] ?? ''));
    if ($asked === '' || $asked === 'all') return $scope;
    if (!in_array($asked, RPT_SERVICES, true)) fail('Unknown service.');
    if ($scope !== null && $asked !== $scope) fail('You do not have access to this.', 403);
    return $asked;
}

if ($action === 'list' && method() === 'GET') {
    $list = [];
    foreach (RPT_REPORTS as $key => $r) {
        if (!rpt_allowed($conn, $user, $key)) continue;
        $list[] = ['key' => $key, 'title' => $r['title'], 'description' => $r['description'], 'personal' => $r['personal']];
    }
    json_out(['reports' => $list, 'scope' => $scope]);
}

if (($action === 'run' || $action === 'export') && method() === 'GET') {
    $key = (string) ($_GET['report'] ?? '');
    if (!isset(RPT_REPORTS[$key])) fail('Unknown report.', 404);
    if (!rpt_allowed($conn, $user, $key)) fail('You do not have access to this.', 403);
    $service = rpt_service($scope);
    $built = rpt_build($conn, $key, $service);

    if ($action === 'run') {
        // Internal helper keys (leading underscore) never leave the server.
        $rows = array_map(static fn(array $r): array => array_filter($r, static fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $built['rows']);
        json_out([
            'report' => $key,
            'title' => RPT_REPORTS[$key]['title'],
            'description' => RPT_REPORTS[$key]['description'],
            'columns' => array_map(static fn($k, $l) => ['key' => $k, 'label' => $l], array_keys($built['columns']), $built['columns']),
            'rows' => $rows,
            'totals' => $built['totals'],
            'service' => $service,
            'personal' => RPT_REPORTS[$key]['personal'],
            'generated_at' => date('Y-m-d H:i'),
        ]);
    }

    // Export: the declared columns only, recorded before a byte is sent.
    $purpose = substr(trim((string) ($_GET['purpose'] ?? '')), 0, 500) ?: null;
    $count = count($built['rows']);
    $scopeLabel = 'report=' . $key . '; service=' . ($service ?? 'all');
    $personal = RPT_REPORTS[$key]['personal'] ? 1 : 0;
    $ip = client_ip();
    $uid = (int) $user['id'];
    $type = 'report.' . $key;
    $format = 'csv';
    $stmt = $conn->prepare(
        'INSERT INTO export_log (user_id, export_type, format, record_count, scope, purpose, contains_personal_data, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississis', $uid, $type, $format, $count, $scopeLabel, $purpose, $personal, $ip);
    $stmt->execute();
    audit($conn, $uid, 'report.export', "$key ($count rows, " . ($service ?? 'all services') . ')');

    $filename = 'ritagreen-' . $key . ($service ? '-' . $service : '') . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    // A byte-order mark so Excel reads the file as UTF-8.
    echo "\xEF\xBB\xBF" . rg_csv($built['columns'], $built['rows']);
    exit;
}

// ---------------------------------------------------------------------------
// Older actions. Kept for existing callers; scoped like everything else.

if ($action === 'client_kpi' && method() === 'GET') {
    $res = $conn->query(
        "SELECT c.id, c.name, c.type, c.status, c.stage,
                u.name AS account_manager,
                COALESCE((SELECT SUM(i.net_pence) FROM invoices i WHERE i.client_id = c.id AND i.status != 'void'), 0) AS invoiced_pence,
                COALESCE((SELECT SUM(i.total_pence) FROM invoices i WHERE i.client_id = c.id AND i.status = 'paid'), 0) AS paid_pence,
                COALESCE((SELECT SUM(i.total_pence) FROM invoices i WHERE i.client_id = c.id AND i.status IN ('sent','overdue')), 0) AS outstanding_pence,
                (SELECT COUNT(*) FROM jobs j WHERE j.client_id = c.id) AS jobs,
                (SELECT MAX(i.created_at) FROM invoices i WHERE i.client_id = c.id) AS last_invoice
         FROM clients c LEFT JOIN users u ON u.id = c.account_manager_id
         WHERE c.deleted_at IS NULL
         ORDER BY outstanding_pence DESC, c.name"
    );
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $r['id'], 'name' => $r['name'], 'type' => $r['type'],
            'status' => $r['status'], 'stage' => $r['stage'], 'account_manager' => $r['account_manager'],
            'invoiced_pence' => (int) $r['invoiced_pence'], 'paid_pence' => (int) $r['paid_pence'],
            'outstanding_pence' => (int) $r['outstanding_pence'], 'jobs' => (int) $r['jobs'],
            'last_invoice' => $r['last_invoice'],
        ];
    }
    json_out(['clients' => $rows]);
}

// Helper: RAG-classify an expiry date.
function rpt_status(?string $expiry): string
{
    if (!$expiry) return 'none';
    $days = (int) floor((strtotime($expiry) - strtotime(date('Y-m-d'))) / 86400);
    return $days < 0 ? 'expired' : ($days <= 30 ? 'expiring' : 'valid');
}

// The regulator packs each cover one service's vetting records: they take the
// compliance zone and the service's scope (S5 audit; they took neither).
$packService = ['cqc_pack' => 'care', 'sia_acs' => 'security', 'coshh' => 'cleaning', 'skills_for_care' => 'academy'][$action] ?? null;
if ($packService !== null) {
    if ($action !== 'skills_for_care' && !can_view_zone($conn, $user, 'compliance')) fail('You do not have access to this.', 403);
    require_service_scope($user, $packService);
}

// CQC evidence (Care): every care worker's checked-and-ready status.
if ($action === 'cqc_pack' && method() === 'GET') {
    $res = $conn->query(
        "SELECT w.id, w.name, w.status, w.right_to_work, w.dbs_status, w.dbs_expiry,
                (SELECT COUNT(*) FROM worker_references r WHERE r.worker_id = w.id AND r.status = 'verified') AS refs_verified,
                (SELECT COUNT(*) FROM certifications c WHERE c.worker_id = w.id) AS certs
         FROM workers w WHERE w.deleted_at IS NULL AND w.division = 'care' ORDER BY w.name"
    );
    $rows = [];
    while ($w = $res->fetch_assoc()) {
        $rows[] = [
            'worker' => $w['name'], 'status' => $w['status'],
            'right_to_work' => $w['right_to_work'] ? 'Verified' : 'MISSING',
            'dbs' => rpt_status($w['dbs_expiry']), 'dbs_expiry' => $w['dbs_expiry'],
            'references_verified' => (int) $w['refs_verified'], 'certificates' => (int) $w['certs'],
        ];
    }
    json_out(['title' => 'CQC evidence pack: Care', 'rows' => $rows]);
}

// SIA ACS audit (Security): licence + vetting evidence.
if ($action === 'sia_acs' && method() === 'GET') {
    $res = $conn->query(
        "SELECT w.id, w.name, w.status, w.right_to_work, w.sia_number, w.sia_expiry,
                (SELECT COUNT(*) FROM worker_references r WHERE r.worker_id = w.id AND r.status = 'verified') AS refs_verified
         FROM workers w WHERE w.deleted_at IS NULL AND w.division = 'security' ORDER BY w.name"
    );
    $rows = [];
    while ($w = $res->fetch_assoc()) {
        $rows[] = [
            'officer' => $w['name'], 'status' => $w['status'],
            'right_to_work' => $w['right_to_work'] ? 'Verified' : 'MISSING',
            'sia_number' => $w['sia_number'] ?: '', 'sia' => rpt_status($w['sia_expiry']), 'sia_expiry' => $w['sia_expiry'],
            'references_verified' => (int) $w['refs_verified'],
        ];
    }
    json_out(['title' => 'SIA ACS audit export: Security', 'rows' => $rows]);
}

// COSHH / H&S (Cleaning): workforce compliance + logged incidents.
if ($action === 'coshh' && method() === 'GET') {
    $res = $conn->query(
        "SELECT w.name, w.status, w.right_to_work,
                (SELECT COUNT(*) FROM certifications c WHERE c.worker_id = w.id AND c.type LIKE '%COSHH%') AS coshh_certs
         FROM workers w WHERE w.deleted_at IS NULL AND w.division = 'cleaning' ORDER BY w.name"
    );
    $rows = [];
    while ($w = $res->fetch_assoc()) {
        $rows[] = [
            'worker' => $w['name'], 'status' => $w['status'],
            'right_to_work' => $w['right_to_work'] ? 'Verified' : 'MISSING',
            'coshh_certificates' => (int) $w['coshh_certs'],
        ];
    }
    // Cleaning incidents (H&S record).
    $ir = $conn->query("SELECT ref, type, severity, title, status, created_at FROM incidents WHERE division = 'cleaning' ORDER BY created_at DESC LIMIT 200");
    $incidents = [];
    while ($i = $ir->fetch_assoc()) $incidents[] = $i;
    json_out(['title' => 'COSHH and H&S report: Cleaning', 'rows' => $rows, 'incidents' => $incidents]);
}

// Skills for Care (Academy): training delivery records.
if ($action === 'skills_for_care' && method() === 'GET') {
    $res = $conn->query(
        "SELECT p.title, p.cpd_hours, p.status,
                (SELECT COUNT(*) FROM enrolments e WHERE e.programme_id = p.id) AS enrolled,
                (SELECT COUNT(*) FROM enrolments e WHERE e.programme_id = p.id AND e.status = 'completed') AS completed
         FROM programmes p ORDER BY p.title"
    );
    $rows = [];
    while ($p = $res->fetch_assoc()) {
        $rows[] = [
            'programme' => $p['title'], 'status' => $p['status'], 'cpd_hours' => (int) $p['cpd_hours'],
            'enrolled' => (int) $p['enrolled'], 'completed' => (int) $p['completed'],
        ];
    }
    json_out(['title' => 'Skills for Care training report: Training', 'rows' => $rows]);
}

fail('Unknown report.', 400);
