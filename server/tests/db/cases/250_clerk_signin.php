<?php
// Clerk email-code sign-in: a Clerk session token is accepted by current_user(), linked to a
// users row by clerk_user_id or (first sign-in) by verified email, and never creates a user.
//
// The signing keys come from a cache file this test writes (the issuer is synthetic, so nothing is
// fetched), and the tokens are minted here with a throwaway key pair.

/** @var RgtClient $client */
/** @var mysqli $db */

$issuer = 'https://clerk.test.example';
$azp = 'https://ritagreenservices.com';
$b64u = fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
$mkKey = function () use ($b64u): array {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($k);
    return [$k, ['kty' => 'RSA', 'kid' => 'k1', 'n' => $b64u($d['rsa']['n']), 'e' => $b64u($d['rsa']['e'])]];
};
[$good, $jwk] = $mkKey();
[$other] = $mkKey();

$dir = sys_get_temp_dir() . '/rg-clerk-test-cache';
@mkdir($dir, 0700, true);
file_put_contents($dir . '/clerk-jwks-' . hash('sha256', $issuer) . '.json', json_encode(['fetched_at' => time(), 'keys' => [$jwk]]));

$token = function (array $claims = [], $key = null, array $hdr = []) use ($good, $issuer, $azp, $b64u): string {
    $now = time();
    $h = $b64u(json_encode($hdr + ['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $p = $b64u(json_encode($claims + ['iss' => $issuer, 'sub' => 'user_abc', 'azp' => $azp, 'exp' => $now + 60, 'nbf' => $now - 5, 'email' => 'clerk-staff@local.invalid']));
    openssl_sign("$h.$p", $sig, $key ?? $good, OPENSSL_ALGO_SHA256);
    return "$h.$p." . $b64u($sig);
};
$me = fn (string $t): RgtResponse => $client->withToken($t)->get('/auth.php?action=me');
$hash = password_hash('unused-synthetic', PASSWORD_BCRYPT);
$insert = function (string $email, string $role) use ($db, $hash): void {
    // Every user hangs off a person (the people spine), so make one first.
    $name = 'Clerk ' . $role;
    $key = 'e:' . $email;
    $p = $db->prepare('INSERT INTO people (full_name, email, match_key) VALUES (?, ?, ?)');
    $p->bind_param('sss', $name, $email, $key);
    $p->execute();
    $pid = (int) $db->insert_id;
    $st = $db->prepare('INSERT INTO users (name, email, password_hash, role, person_id) VALUES (?, ?, ?, ?, ?)');
    $st->bind_param('ssssi', $name, $email, $hash, $role, $pid);
    $st->execute();
};
$insert('clerk-staff@local.invalid', 'admin');
$insert('clerk-worker@local.invalid', 'employee');
$users = fn (): int => (int) $db->query('SELECT COUNT(*) FROM users')->fetch_row()[0];

Rgt::group('clerk sign-in — linking');

$r = $me($token());
Rgt::status('an approved email signs in with a Clerk token', $r, 200);
Rgt::same('the role stays the database role', $r->get('user.role'), 'admin');
Rgt::same('the Clerk user id was stored on first sign-in',
    $db->query("SELECT clerk_user_id FROM users WHERE email = 'clerk-staff@local.invalid'")->fetch_row()[0], 'user_abc');
Rgt::status('the next sign-in matches on the Clerk user id alone', $me($token(['email' => ''])), 200);
Rgt::status('a different Clerk user cannot take over a linked row',
    $me($token(['sub' => 'user_mallory'])), 401);
$before = $users();
Rgt::status('an unknown email gets nothing', $me($token(['sub' => 'user_x', 'email' => 'stranger@local.invalid'])), 401);
Rgt::same('sign-in never creates a user', $users(), $before);
$db->query("UPDATE users SET deleted_at = NOW() WHERE email = 'clerk-worker@local.invalid'");
Rgt::status('a deleted user cannot sign in', $me($token(['sub' => 'user_w', 'email' => 'clerk-worker@local.invalid'])), 401);
$db->query("UPDATE users SET deleted_at = NULL WHERE email = 'clerk-worker@local.invalid'");

Rgt::group('clerk sign-in — roles still apply');

$worker = $token(['sub' => 'user_w', 'email' => 'clerk-worker@local.invalid']);
Rgt::status('a worker signs in with a Clerk token', $me($worker), 200);
Rgt::denied('a worker token cannot open an admin endpoint', $client->withToken($worker)->get('/audit.php'));
Rgt::status('an admin token can open it', $client->withToken($token())->get('/audit.php'), 200);

Rgt::group('clerk sign-in — bad tokens');

foreach ([
    'signed by another key' => $token([], $other),
    'wrong issuer' => $token(['iss' => 'https://clerk.evil.example']),
    'expired' => $token(['exp' => time() - 60]),
    'not valid yet' => $token(['nbf' => time() + 600]),
    'an unlisted authorised party' => $token(['azp' => 'https://evil.example']),
    'an unknown key id' => $token([], null, ['kid' => 'unknown']),
    'malformed' => 'a.b.c',
] as $why => $bad) {
    Rgt::status("a token $why is refused", $me($bad), 401);
}
Rgt::same('no refused token linked anything',
    (int) $db->query("SELECT COUNT(*) FROM users WHERE clerk_user_id IN ('user_mallory','user_x')")->fetch_row()[0], 0);

Rgt::group('clerk sign-in — the existing password session');

$legacy = $client->login('admin@local.invalid');
Rgt::status('a password session still works beside Clerk', $legacy->get('/auth.php?action=me'), 200);
