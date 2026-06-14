<?php
// Reporting endpoints for export (CSV/PDF built client-side from this data).
// GET /reports.php?action=client_kpi -> per-client KPIs (revenue, outstanding, activity)
require __DIR__ . '/lib.php';

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin', 'manager');
$action = $_GET['action'] ?? '';

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
    json_out(['title' => 'CQC evidence pack — Care', 'rows' => $rows]);
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
            'sia_number' => $w['sia_number'] ?: '—', 'sia' => rpt_status($w['sia_expiry']), 'sia_expiry' => $w['sia_expiry'],
            'references_verified' => (int) $w['refs_verified'],
        ];
    }
    json_out(['title' => 'SIA ACS audit export — Security', 'rows' => $rows]);
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
    json_out(['title' => 'COSHH & H&S report — Cleaning', 'rows' => $rows, 'incidents' => $incidents]);
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
    json_out(['title' => 'Skills for Care training report — Academy', 'rows' => $rows]);
}

fail('Unknown report.', 400);
