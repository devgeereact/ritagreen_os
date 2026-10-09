<?php
// Company details and social links (2026 plan, file 06, section S7).
//
// Public (no auth), an explicit list of fields, cached for five minutes so a
// published change reaches the site within that:
//   GET  /company.php                    -> { company: {...} }
//
// Owner only (super_admin | admin):
//   GET  /company.php?action=edit        -> { company: {...}, updated_at, updated_by_name }
//   POST /company.php?action=update      body {...} -> { ok, company }
//
// One row, id 1, seeded by migration 039 from src/lib/company.ts. The website
// keeps those same facts in code as the fallback, so it still shows a phone
// number if this endpoint cannot be reached.
require __DIR__ . '/lib.php';

const COMPANY_PUBLIC_FIELDS = [
    'trading_name', 'legal_name', 'company_no', 'phone', 'urgent_phone', 'email', 'office_hours', 'area',
    'registered_address', 'instagram_url', 'facebook_url', 'linkedin_url', 'tiktok_url', 'whatsapp_url',
];

function company_row(mysqli $conn): ?array
{
    $res = $conn->query(
        'SELECT c.' . implode(', c.', COMPANY_PUBLIC_FIELDS) . ', c.updated_at, u.name AS updated_by_name
         FROM company_settings c LEFT JOIN users u ON u.id = c.updated_by WHERE c.id = 1 LIMIT 1'
    );
    return $res->fetch_assoc() ?: null;
}

function company_public(array $row): array
{
    $out = [];
    foreach (COMPANY_PUBLIC_FIELDS as $f) $out[$f] = (string) ($row[$f] ?? '');
    return $out;
}

$action = $_GET['action'] ?? '';
$m = method();

if ($m === 'GET' && $action === '') {
    $row = company_row($conn);
    if (!$row) fail('Company details have not been set up.', 404);
    header('Cache-Control: public, max-age=300');
    json_out(['company' => company_public($row)]);
}

$user = require_auth($conn);
require_role($user, 'super_admin', 'admin');
require_staff($user);
// The office copy is never cached: after a save it must show what is stored.
header('Cache-Control: no-store');

if ($m === 'GET' && $action === 'edit') {
    $row = company_row($conn);
    if (!$row) fail('Company details have not been set up.', 404);
    json_out(['company' => company_public($row), 'updated_at' => $row['updated_at'], 'updated_by_name' => $row['updated_by_name']]);
}

if ($m === 'POST' && $action === 'update') {
    $before = company_row($conn);
    if (!$before) fail('Company details have not been set up.', 404);
    $d = body();

    $in = [];
    $limits = [
        'trading_name' => 160, 'legal_name' => 160, 'company_no' => 20, 'phone' => 40, 'urgent_phone' => 40,
        'email' => 190, 'office_hours' => 160, 'area' => 160, 'registered_address' => 300,
        'instagram_url' => 300, 'facebook_url' => 300, 'linkedin_url' => 300, 'tiktok_url' => 300, 'whatsapp_url' => 300,
    ];
    foreach ($limits as $f => $max) {
        // A field left out keeps its stored value; one sent empty clears it.
        $v = array_key_exists($f, $d) ? trim((string) $d[$f]) : (string) $before[$f];
        if (mb_strlen($v) > $max) fail("That value is too long ($f, at most $max characters).", 422);
        if (preg_match('/[<>]/', $v)) fail("Angle brackets are not allowed ($f).", 422);
        $in[$f] = $v;
    }

    if ($in['trading_name'] === '' || $in['legal_name'] === '') fail('The trading name and legal name are required.', 422);
    if (!rg_valid_phone($in['phone'])) fail('The office phone number is not a number that can be rung.', 422);
    if ($in['urgent_phone'] !== '' && !rg_valid_phone($in['urgent_phone'])) fail('The urgent line is not a number that can be rung.', 422);
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) fail('The email address is not valid.', 422);
    if ($in['company_no'] !== '' && !preg_match('/^[A-Z0-9]{6,10}$/', strtoupper($in['company_no']))) {
        fail('A company number is letters and digits, as on Companies House.', 422);
    }
    $in['company_no'] = strtoupper($in['company_no']);
    foreach (['instagram', 'facebook', 'linkedin', 'tiktok', 'whatsapp'] as $net) {
        if (!rg_social_url_ok($net, $in[$net . '_url'])) {
            fail('The ' . ($net === 'whatsapp' ? 'WhatsApp' : ucfirst($net)) . ' link must be an https address on that network.', 422);
        }
    }

    $userId = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE company_settings SET trading_name = ?, legal_name = ?, company_no = ?, phone = ?, urgent_phone = ?,
                email = ?, office_hours = ?, area = ?, registered_address = ?, instagram_url = ?, facebook_url = ?,
                linkedin_url = ?, tiktok_url = ?, whatsapp_url = ?, updated_by = ?, updated_at = UTC_TIMESTAMP()
         WHERE id = 1'
    );
    $stmt->bind_param('ssssssssssssssi',
        $in['trading_name'], $in['legal_name'], $in['company_no'], $in['phone'], $in['urgent_phone'], $in['email'],
        $in['office_hours'], $in['area'], $in['registered_address'], $in['instagram_url'], $in['facebook_url'],
        $in['linkedin_url'], $in['tiktok_url'], $in['whatsapp_url'], $userId);
    $stmt->execute();

    [$b, $a] = audit_diff($before, $in, COMPANY_PUBLIC_FIELDS);
    if ($a) audit($conn, $userId, 'company.update', implode(', ', array_keys($a)), 'company_settings', 1, $b, $a);
    json_out(['ok' => true, 'company' => $in]);
}

fail('Method not allowed.', 405);
