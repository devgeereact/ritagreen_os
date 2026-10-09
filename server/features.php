<?php
// Feature switches: which optional areas of the system are in use.
//
// GET  /features.php                    -> { features: {name: bool}, switches: [...] }   any staff account
// POST /features.php {name, enabled}    -> the same, after the change                   owner only, audited
//
// The switches and what each one does are described in migration 036. The list
// of names is fixed in code (rg_feature_defaults() in pure.php); this endpoint
// cannot create a switch, only set one that exists.
//
// "Owner" is super_admin and admin, as in the 2026 plan (file 02, "Access").
require __DIR__ . '/lib.php';

$user = require_auth($conn);
// Above every branch. The office interface reads this to decide what to show,
// so every staff account may read it; a worker or client login has no use for
// it and is refused, like the rest of the office API.
require_staff($user);

/**
 * The switches as stored, with who last changed each one. Missing rows (or a
 * missing table, on a server where migration 036 has not run) read as their
 * defaults, the same answer feature_enabled() gives.
 */
function features_snapshot(mysqli $conn): array
{
    $stored = [];
    $meta = [];
    try {
        $res = $conn->query(
            'SELECT f.name, f.enabled, f.updated_at, u.name AS updated_by_name
               FROM feature_switches f LEFT JOIN users u ON u.id = f.updated_by'
        );
        while ($r = $res->fetch_assoc()) {
            $stored[$r['name']] = (int) $r['enabled'];
            $meta[$r['name']] = ['updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by_name']];
        }
    } catch (mysqli_sql_exception $e) {
        error_log('feature_switches unreadable, using defaults: ' . $e->getMessage());
    }

    $features = rg_feature_map($stored);
    $switches = [];
    foreach ($features as $name => $enabled) {
        $switches[] = [
            'name' => $name,
            'enabled' => $enabled,
            'updated_at' => $meta[$name]['updated_at'] ?? null,
            'updated_by' => $meta[$name]['updated_by'] ?? null,
        ];
    }
    return ['features' => $features, 'switches' => $switches];
}

if (method() === 'GET') {
    json_out(features_snapshot($conn));
}

if (method() === 'POST') {
    require_role($user, 'super_admin', 'admin');

    $data = body();
    $name = (string) ($data['name'] ?? '');
    if (!array_key_exists($name, rg_feature_defaults())) fail('There is no feature switch with that name.', 422);
    $raw = $data['enabled'] ?? null;
    if (!is_bool($raw) && $raw !== 0 && $raw !== 1) fail('enabled must be true or false.', 422);
    $enabled = $raw ? 1 : 0;

    $before = feature_enabled($conn, $name);
    if ($before !== (bool) $enabled) {
        $uid = (int) $user['id'];
        $stmt = $conn->prepare(
            'INSERT INTO feature_switches (name, enabled, updated_by, updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by),
                                     updated_at = VALUES(updated_at)'
        );
        $stmt->bind_param('sii', $name, $enabled, $uid);
        $stmt->execute();
        audit(
            $conn, $uid, 'feature.switch',
            $name . ' switched ' . ($enabled ? 'on' : 'off'),
            'feature_switch', null,
            ['name' => $name, 'enabled' => $before],
            ['name' => $name, 'enabled' => (bool) $enabled]
        );
    }

    json_out(features_snapshot($conn));
}

fail('Method not allowed.', 405);
