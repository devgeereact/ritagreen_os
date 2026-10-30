<?php
// Clerk session token checks (clerk-lib.php). Required by run.php, which provides group() and check().
// No database and no network: keys are generated here and the key fetch is injected.

declare(strict_types=1);

require_once __DIR__ . '/../clerk-lib.php';

$b64u = fn (string $x): string => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
$mk = function () use ($b64u): array {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($k);
    return [$k, ['kty' => 'RSA', 'kid' => 'k1', 'n' => $b64u($d['rsa']['n']), 'e' => $b64u($d['rsa']['e'])]];
};
[$goodKey, $jwk] = $mk();
[$otherKey] = $mk();
$iss = 'https://clerk.ritagreenservices.com';
$now = 1800000000;
$sign = function ($key, array $claims, array $hdr = []) use ($b64u): string {
    $h = $b64u(json_encode($hdr + ['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $p = $b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . $b64u($sig);
};
$good = ['iss' => $iss, 'sub' => 'user_abc', 'azp' => 'https://ritagreenservices.com', 'exp' => $now + 60, 'nbf' => $now - 5, 'email' => 'a@b.co'];
$cache = sys_get_temp_dir() . '/rg-clerk-unit-' . getmypid();
@mkdir($cache, 0700, true);
$verify = function (string $tok) use ($iss, $cache, $jwk, $now): string {
    foreach (glob("$cache/*") ?: [] as $f) @unlink($f);
    try {
        $v = new RgClerkJwt($iss, ['https://ritagreenservices.com', 'https://local.ritagreenservices.com'], $cache, fn ($u) => json_encode(['keys' => [$jwk]]), $now);
        return (string) $v->verify($tok)['sub'];
    } catch (RuntimeException $e) {
        return 'rejected';
    }
};

group('clerk token checks');
check('a correctly signed token verifies', $verify($sign($goodKey, $good)), 'user_abc');
check('local-live origin is accepted', $verify($sign($goodKey, ['azp' => 'https://local.ritagreenservices.com'] + $good)), 'user_abc');
check('a token signed by another key is rejected', $verify($sign($otherKey, $good)), 'rejected');
check('the wrong issuer is rejected', $verify($sign($goodKey, ['iss' => 'https://clerk.evil.com'] + $good)), 'rejected');
check('an expired token is rejected', $verify($sign($goodKey, ['exp' => $now - 60] + $good)), 'rejected');
check('a not-yet-valid token is rejected', $verify($sign($goodKey, ['nbf' => $now + 600] + $good)), 'rejected');
check('an unlisted azp is rejected', $verify($sign($goodKey, ['azp' => 'https://evil.example'] + $good)), 'rejected');
$noAzp = $good; unset($noAzp['azp']);
check('a missing azp is rejected', $verify($sign($goodKey, $noAzp)), 'rejected');
$noSub = $good; unset($noSub['sub']);
check('a missing sub is rejected', $verify($sign($goodKey, $noSub)), 'rejected');
check('a non-RS256 alg is rejected', $verify($sign($goodKey, $good, ['alg' => 'HS256'])), 'rejected');
check('alg=none is rejected', $verify($b64u(json_encode(['alg' => 'none', 'kid' => 'k1'])) . '.' . $b64u(json_encode($good)) . '.'), 'rejected');
check('an unknown kid is rejected', $verify($sign($goodKey, $good, ['kid' => 'unknown'])), 'rejected');
check('a malformed token is rejected', $verify('a.b'), 'rejected');
check('a JWT bearer is told apart from a 64-hex session token', [rg_clerk_looks_like_jwt('a.b.c'), rg_clerk_looks_like_jwt(bin2hex(random_bytes(32)))], [true, false]);
check('not configured without issuer and parties', rg_clerk_configured(), false);
try { new RgClerkJwt('http://clerk.example.com', ['x'], $cache, fn ($u) => ''); $threw = false; } catch (InvalidArgumentException $e) { $threw = true; }
check('an http issuer is refused', $threw, true);
foreach (glob("$cache/*") ?: [] as $f) @unlink($f);
@rmdir($cache);
