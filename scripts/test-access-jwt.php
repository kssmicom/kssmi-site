<?php
declare(strict_types=1);

/**
 * Cloudflare Access JWT verification tests (KS-02).
 *
 * The admin pages accept a request only with an Access JWT that our team
 * signed for our application. These tests sign tokens with throwaway RSA keys
 * and check every rejection path, the cookie fallback and the certificate cache.
 *
 * Run: php scripts/test-access-jwt.php
 */

function kssmi_jwt_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_jwt_b64url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function kssmi_jwt_sign(array $header, array $claims, $privateKey): string {
    $input = kssmi_jwt_b64url((string)json_encode($header)) . '.' . kssmi_jwt_b64url((string)json_encode($claims));
    openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    return $input . '.' . kssmi_jwt_b64url($signature);
}

function kssmi_jwt_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        is_dir($path) ? kssmi_jwt_remove_tree($path) : @unlink($path);
    }
    @rmdir($directory);
}

$tree = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-access-jwt-' . bin2hex(random_bytes(6));
kssmi_jwt_assert(mkdir($tree . '/private', 0700, true) && mkdir($tree . '/rate_limit', 0700), 'create test tree');
copy(dirname(__DIR__) . '/private/http-security.php', $tree . '/private/http-security.php');
putenv('KSSMI_ADMIN_MARKER_SECRET_FILE=' . $tree . '/.email_logs_password');

try {
    require_once $tree . '/private/http-security.php';

    $newKey = static function () {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        kssmi_jwt_assert($key !== false, 'generate an RSA test key');
        return [$key, openssl_pkey_get_details($key)['key']];
    };
    [$key, $publicPem] = $newKey();
    [$otherKey, $otherPublicPem] = $newKey();
    $kid = str_repeat('ab', 32);
    $certs = [$kid => $publicPem];
    $now = 1_800_000_000;
    $header = ['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'];
    $claims = [
        'aud' => [KSSMI_ACCESS_AUD],
        'email' => 'owner@example.com',
        'exp' => $now + 3600,
        'iat' => $now - 10,
        'nbf' => $now - 10,
        'iss' => KSSMI_ACCESS_TEAM_DOMAIN,
        'type' => 'app',
    ];
    $sign = static fn(array $c, ?array $h = null, $k = null) => kssmi_jwt_sign($h ?? $header, $c, $k ?? $key);

    // ── accepted tokens ──
    kssmi_jwt_assert(kssmi_access_jwt_claims($sign($claims), $certs, $now)['email'] === 'owner@example.com', 'a valid user token must be accepted');
    $service = $claims;
    unset($service['email']);
    $service['common_name'] = 'smoke.access';
    kssmi_jwt_assert(kssmi_access_jwt_claims($sign($service), $certs, $now) !== null, 'a valid service-token JWT without email must be accepted');
    kssmi_jwt_assert(kssmi_access_jwt_claims($sign(['aud' => KSSMI_ACCESS_AUD] + $claims), $certs, $now) !== null, 'a string audience must be accepted');
    kssmi_jwt_assert(kssmi_access_jwt_claims($sign(['exp' => $now - 30] + $claims), $certs, $now) !== null, 'expiry within the clock skew must be accepted');

    // ── rejected tokens ──
    $rejected = [
        'another application audience' => $sign(['aud' => [str_repeat('0', 64)]] + $claims),
        'another team issuer' => $sign(['iss' => 'https://attacker.cloudflareaccess.com'] + $claims),
        'missing issuer' => $sign(array_diff_key($claims, ['iss' => 1])),
        'expired' => $sign(['exp' => $now - 61] + $claims),
        'missing expiry' => $sign(array_diff_key($claims, ['exp' => 1])),
        'not yet valid' => $sign(['nbf' => $now + 120] + $claims),
        'issued in the future' => $sign(['iat' => $now + 120] + $claims),
        'signed by another key' => $sign($claims, null, $otherKey),
        'unknown kid' => $sign($claims, ['kid' => str_repeat('cd', 32)] + $header),
        'alg none' => kssmi_jwt_b64url(json_encode(['alg' => 'none', 'kid' => $kid])) . '.' . kssmi_jwt_b64url(json_encode($claims)) . '.',
        'alg HS256 with the public key as secret' => (static function () use ($header, $claims, $publicPem) {
            $input = kssmi_jwt_b64url(json_encode(['alg' => 'HS256'] + $header)) . '.' . kssmi_jwt_b64url(json_encode($claims));
            return $input . '.' . kssmi_jwt_b64url(hash_hmac('sha256', $input, $publicPem, true));
        })(),
        'two segments' => 'abc.def',
        'garbage' => 'not a jwt',
        'oversized' => str_repeat('a', 9000),
    ];
    $tampered = explode('.', $sign($claims));
    $tampered[1] = kssmi_jwt_b64url(json_encode(['email' => 'attacker@example.com'] + $claims));
    $rejected['tampered payload'] = implode('.', $tampered);
    foreach ($rejected as $label => $token) {
        kssmi_jwt_assert(kssmi_access_jwt_claims($token, $certs, $now) === null, "Must reject: {$label}");
    }

    // ── request extraction: header always, cookie only when allowed ──
    $certsFile = $tree . '/certs.json';
    file_put_contents($certsFile, json_encode($certs));
    putenv('KSSMI_ACCESS_CERTS_FILE=' . $certsFile);
    $valid = $sign(['exp' => time() + 3600, 'iat' => time() - 5, 'nbf' => time() - 5] + $claims);

    $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] = $valid;
    kssmi_jwt_assert(kssmi_admin_access_jwt_valid(), 'the Cf-Access-Jwt-Assertion header must be accepted');
    unset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION']);
    kssmi_jwt_assert(!kssmi_admin_access_jwt_valid(), 'a request without the header must be rejected');
    $_COOKIE['CF_Authorization'] = $valid;
    kssmi_jwt_assert(!kssmi_admin_access_jwt_valid(), 'the cookie must not be accepted where only the header is allowed');
    kssmi_jwt_assert(kssmi_admin_access_jwt_valid(true), 'the CF_Authorization cookie must be accepted where allowed');
    $_COOKIE['CF_Authorization'] = $sign($claims, null, $otherKey);
    kssmi_jwt_assert(!kssmi_admin_access_jwt_valid(true), 'a forged cookie must be rejected');
    $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] = 'forged';
    $_COOKIE['CF_Authorization'] = $valid;
    kssmi_jwt_assert(!kssmi_admin_access_jwt_valid(true), 'a present but invalid header must not fall back to the cookie');
    unset($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'], $_COOKIE['CF_Authorization']);
    putenv('KSSMI_ACCESS_CERTS_FILE');

    // ── certificate cache (no network: attempted_at blocks a fetch) ──
    $cachePath = kssmi_access_certs_cache_path();
    kssmi_jwt_assert(realpath(dirname($cachePath)) === realpath($tree . '/rate_limit'), 'the cache lives in the persistent rate_limit directory');
    $writeCache = static function (int $fetchedAt, int $attemptedAt) use ($cachePath, $certs): void {
        file_put_contents($cachePath, json_encode(['certs' => $certs, 'fetched_at' => $fetchedAt, 'attempted_at' => $attemptedAt]));
    };
    $writeCache($now - 60, $now - 60);
    kssmi_jwt_assert(kssmi_access_certs_for($kid, $now) === $certs, 'a fresh cache must be used');
    $writeCache($now - 3 * 86400, $now - 10);
    kssmi_jwt_assert(kssmi_access_certs_for($kid, $now) === $certs, 'a stale cache must still serve while a refresh was just attempted');
    $writeCache($now - 8 * 86400, $now - 10);
    kssmi_jwt_assert(kssmi_access_certs_for($kid, $now) === [], 'a cache older than seven days must not be trusted');
    $writeCache($now - 60, $now - 10);
    kssmi_jwt_assert(kssmi_access_certs_for(str_repeat('ef', 32), $now) === [], 'an unknown kid must not be served from the cache');

    // ── wiring: every page behind Access checks the JWT right after the proxy gate ──
    $repo = dirname(__DIR__);
    foreach ([
        'public/email-logs.php' => 'kssmi_admin_require_access();',
        'public/visitor-journey.php' => 'kssmi_admin_require_access();',
        'public/short-links-admin.php' => 'kssmi_admin_require_access();',
        'public/api/short-links.php' => 'kssmi_admin_require_access(true);',
    ] as $file => $call) {
        $source = (string)file_get_contents("{$repo}/{$file}");
        $gate = strpos($source, 'kssmi_admin_require_trusted_proxy();');
        $access = strpos($source, $call);
        kssmi_jwt_assert($gate !== false && $access !== false && $gate < $access, "{$file} must call {$call} after the trusted-proxy gate");
        foreach (['kssmi_admin_session_bootstrap();', 'kssmi_short_links_session();'] as $session) {
            $sessionAt = strpos($source, $session);
            kssmi_jwt_assert($sessionAt === false || $access < $sessionAt, "{$file} must check Access before starting a session");
        }
    }

    fwrite(STDOUT, "Access JWT tests passed.\n");
} finally {
    kssmi_jwt_remove_tree($tree);
}
