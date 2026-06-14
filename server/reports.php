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

fail('Unknown report.', 400);
