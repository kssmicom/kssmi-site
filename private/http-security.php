<?php
declare(strict_types=1);

/**
 * Kssmi shared HTTP security module (优化-001 阶段 3 步骤 2).
 *
 * This is the Kssmi counterpart of the XinXin private/http-security.php, built
 * with kssmi_* prefixes and only the pieces Kssmi needs TODAY:
 *
 *   1. Admin origin gate + security headers (parameterized CSP because the two admin
 *      pages ship different policies; the .htaccess FilesMatch CSP is not
 *      reliably honored by OpenLiteSpeed, so pages set their own).
 *   2. Atomic file write / stable flock helpers for credentials and reset
 *      tokens (tmp + fsync + rename, lock sidecar).
 *   3. Secret read/write with the same semantics the backends already use:
 *      an empty or missing file reads as null; writes are 0600.
 *   4. Scalar/text sanitization helpers used by later stages.
 *   5. One hardened admin-session bootstrap and bounded request normalization.
 *
 * Cloudflare Access remains the authentication gate at the edge. The origin
 * gate below independently verifies the TCP peer address before either admin
 * page starts a session. LiteSpeed exposes that peer as PROXY_REMOTE_ADDR when
 * it rewrites REMOTE_ADDR to the visitor IP. Client-controlled CF-* headers
 * are never accepted as proof that a request traversed Cloudflare.
 */

if (defined('KSSMI_HTTP_SECURITY_LOADED')) {
    return;
}
define('KSSMI_HTTP_SECURITY_LOADED', true);

/**
 * Return true only when the connection peer is a published Cloudflare proxy.
 *
 * LiteSpeed supplies PROXY_REMOTE_ADDR from the TCP connection when trusted
 * proxy processing has replaced REMOTE_ADDR with the visitor IP. Other web
 * servers fall back to REMOTE_ADDR. Unlike CF-Ray / CF-Connecting-IP /
 * X-Forwarded-For, a direct-origin HTTP client cannot choose either server
 * variable. The CIDR matcher and strict versioned-snapshot consumer live in
 * rate-limit.php and are shared here so the two trust decisions cannot drift
 * apart. If that snapshot is unusable, this predicate returns false.
 */
function kssmi_admin_request_from_trusted_proxy($remoteAddress = null): bool {
    if (!function_exists('kssmi_is_cloudflare_proxy')) {
        $rateLimitModule = __DIR__ . '/rate-limit.php';
        if (!is_file($rateLimitModule) || !is_readable($rateLimitModule)) {
            return false;
        }
        require_once $rateLimitModule;
    }

    if ($remoteAddress === null) {
        $remoteAddress = function_exists('kssmi_connection_peer_ip')
            ? kssmi_connection_peer_ip()
            : ($_SERVER['REMOTE_ADDR'] ?? null);
    }
    if (!is_string($remoteAddress)) return false;

    $remoteAddress = trim($remoteAddress);
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) return false;

    return function_exists('kssmi_is_cloudflare_proxy')
        && kssmi_is_cloudflare_proxy($remoteAddress);
}

/**
 * Fail closed before session or application initialization on admin pages.
 */
function kssmi_admin_require_trusted_proxy(): void {
    if (kssmi_admin_request_from_trusted_proxy()) return;

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    kssmi_admin_security_headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    echo 'Forbidden';
    exit;
}

/*
 * Cloudflare Access proof (KS-02). The trusted-proxy gate only proves that a
 * request came through *some* Cloudflare edge; another Cloudflare account can
 * proxy to this origin with our Host header and skip our Access policy. Pages
 * behind Access therefore also require the Access JWT that Cloudflare signs
 * for our team and application (users and service tokens alike).
 */
const KSSMI_ACCESS_TEAM_DOMAIN = 'https://kssmi.cloudflareaccess.com';
const KSSMI_ACCESS_AUD = '79e1eeeb1fe317952ec328b0360bc9205c8ff2c72e91461057ae36b302373648';
const KSSMI_ACCESS_CLOCK_SKEW = 60;
const KSSMI_ACCESS_CERTS_FRESH = 6 * 3600;
const KSSMI_ACCESS_CERTS_STALE_LIMIT = 7 * 86400;
const KSSMI_ACCESS_CERTS_RETRY = 60;

function kssmi_access_b64url_decode(string $value): ?string {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) return null;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $decoded === false ? null : $decoded;
}

/** Decode the JWT header without trusting it; returns null when malformed. */
function kssmi_access_jwt_header(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $raw = kssmi_access_b64url_decode($parts[0]);
    $header = $raw === null ? null : json_decode($raw, true);
    return is_array($header) ? $header : null;
}

/**
 * Verify an Access JWT against PEM certificates or public keys keyed by kid.
 * Returns the claims, or null for any failure.
 */
function kssmi_access_jwt_claims(string $jwt, array $certsByKid, ?int $now = null): ?array {
    if (strlen($jwt) > 8192) return null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;

    $header = kssmi_access_jwt_header($jwt);
    if ($header === null || ($header['alg'] ?? null) !== 'RS256') return null;
    $kid = $header['kid'] ?? null;
    if (!is_string($kid) || !isset($certsByKid[$kid]) || !is_string($certsByKid[$kid])) return null;

    $signature = kssmi_access_b64url_decode($parts[2]);
    $payloadRaw = kssmi_access_b64url_decode($parts[1]);
    if ($signature === null || $payloadRaw === null) return null;

    $key = @openssl_pkey_get_public($certsByKid[$kid]);
    if ($key === false) return null;
    if (openssl_verify($parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) return null;

    $claims = json_decode($payloadRaw, true);
    if (!is_array($claims)) return null;

    $now ??= time();
    if (($claims['iss'] ?? null) !== KSSMI_ACCESS_TEAM_DOMAIN) return null;
    $audience = $claims['aud'] ?? null;
    $audiences = is_string($audience) ? [$audience] : (is_array($audience) ? $audience : []);
    if (!in_array(KSSMI_ACCESS_AUD, $audiences, true)) return null;
    if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - KSSMI_ACCESS_CLOCK_SKEW) return null;
    foreach (['nbf', 'iat'] as $claim) {
        if (!array_key_exists($claim, $claims)) continue;
        if (!is_int($claims[$claim]) || $claims[$claim] > $now + KSSMI_ACCESS_CLOCK_SKEW) return null;
    }
    return $claims;
}

function kssmi_access_certs_cache_path(): string {
    return dirname(__DIR__) . '/rate_limit/cloudflare-access-certs.json';
}

/** Fetch the team's signing certificates; returns [kid => PEM] or null. */
function kssmi_access_fetch_certs(): ?array {
    $url = KSSMI_ACCESS_TEAM_DOMAIN . '/cdn-cgi/access/certs';
    $body = null;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $result = curl_exec($curl);
        if (is_string($result) && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200) $body = $result;
        curl_close($curl);
    }
    if (!is_string($body) || strlen($body) > 65536) return null;

    $decoded = json_decode($body, true);
    $certs = [];
    foreach ((array)($decoded['public_certs'] ?? []) as $entry) {
        $kid = $entry['kid'] ?? null;
        $cert = $entry['cert'] ?? null;
        if (!is_string($kid) || preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $kid) !== 1) continue;
        if (!is_string($cert) || @openssl_pkey_get_public($cert) === false) continue;
        $certs[$kid] = $cert;
    }
    return $certs === [] ? null : $certs;
}

/**
 * Return [kid => PEM] that can verify $kid. Certificates are cached on disk;
 * an unknown kid or an old cache triggers at most one fetch per minute, and an
 * unreachable endpoint falls back to a cache younger than seven days.
 */
function kssmi_access_certs_for(string $kid, ?int $now = null): array {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        $override = getenv('KSSMI_ACCESS_CERTS_FILE');
        if (is_string($override) && $override !== '') {
            $certs = json_decode((string)@file_get_contents($override), true);
            return is_array($certs) ? $certs : [];
        }
    }

    $now ??= time();
    $path = kssmi_access_certs_cache_path();
    $cache = json_decode((string)@file_get_contents($path), true);
    if (!is_array($cache) || !is_array($cache['certs'] ?? null)) $cache = ['certs' => [], 'fetched_at' => 0, 'attempted_at' => 0];
    $fetchedAt = (int)($cache['fetched_at'] ?? 0);
    $known = isset($cache['certs'][$kid]);

    if ($known && $now - $fetchedAt < KSSMI_ACCESS_CERTS_FRESH) return $cache['certs'];

    if ($now - (int)($cache['attempted_at'] ?? 0) >= KSSMI_ACCESS_CERTS_RETRY) {
        $fresh = kssmi_access_fetch_certs();
        $cache['attempted_at'] = $now;
        if ($fresh !== null) {
            $cache['certs'] = $fresh;
            $cache['fetched_at'] = $now;
        }
        kssmi_admin_atomic_write($path, (string)json_encode($cache), 0600);
        if ($fresh !== null) return $fresh;
    }

    return ($known && $now - $fetchedAt < KSSMI_ACCESS_CERTS_STALE_LIMIT) ? $cache['certs'] : [];
}

/**
 * True when the request carries a valid Access JWT for this application.
 * Cloudflare sends it as Cf-Access-Jwt-Assertion on paths the Access app
 * covers; $allowCookie also accepts the browser's CF_Authorization cookie for
 * same-origin APIs that the admin pages call.
 */
function kssmi_admin_access_jwt_valid(bool $allowCookie = false, ?int $now = null): bool {
    $token = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? null;
    if ((!is_string($token) || $token === '') && $allowCookie) $token = $_COOKIE['CF_Authorization'] ?? null;
    if (!is_string($token) || $token === '' || strlen($token) > 8192) return false;

    $header = kssmi_access_jwt_header($token);
    $kid = $header['kid'] ?? null;
    if (!is_string($kid) || preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $kid) !== 1) return false;

    return kssmi_access_jwt_claims($token, kssmi_access_certs_for($kid, $now), $now) !== null;
}

/**
 * Fail closed unless Cloudflare Access authenticated this request. Call right
 * after kssmi_admin_require_trusted_proxy() on every page behind Access.
 */
function kssmi_admin_require_access(bool $allowCookie = false): void {
    if (kssmi_admin_access_jwt_valid($allowCookie)) return;

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    kssmi_admin_security_headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    echo 'Forbidden';
    exit;
}

/**
 * Emit the security headers every Kssmi admin page must send.
 *
 * The CSP differs between the two admin pages (email-logs.php ships a richer
 * policy that allows Cloudflare Turnstile + GTM; visitor-journey.php uses a
 * minimal frame/form policy). Pass the exact policy string; the remaining
 * headers are common to all admin pages.
 */
function kssmi_admin_security_headers(string $contentSecurityPolicy): void {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, private', true);
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Security-Policy: ' . $contentSecurityPolicy);
}

/**
 * Sanitize a scalar to a bounded string; non-scalar or negative max → ''.
 */
function kssmi_scalar_text($value, int $maxLength, bool $trim = true): string {
    if (!is_scalar($value) || $maxLength < 0) return '';
    $text = (string)$value;
    if ($trim) $text = trim($text);
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }
    return substr($text, 0, $maxLength);
}

/**
 * Normalize an HTTP input array against an explicit per-page allowlist.
 *
 * PHP normally supplies GET/POST leaf values as strings, but an attacker can
 * turn any field into an array with `field[]=...`. Scalar fields with any
 * other shape are discarded, unknown fields are discarded, and accepted
 * strings are trimmed and bounded before application code sees them.
 *
 * List rules use [maximum item count, maximum item length]. They exist only
 * for forms that intentionally submit `name[]` controls.
 */
function kssmi_admin_normalize_request(
    array $input,
    array $scalarLimits,
    array $listLimits = []
): array {
    $normalized = [];

    foreach ($scalarLimits as $key => $maxLength) {
        if (!is_string($key) || !is_int($maxLength) || $maxLength < 0) continue;
        if (!array_key_exists($key, $input) || !is_string($input[$key])) continue;
        $normalized[$key] = kssmi_scalar_text($input[$key], $maxLength);
    }

    foreach ($listLimits as $key => $limits) {
        if (!is_string($key)
            || !is_array($limits)
            || count($limits) !== 2
            || !is_int($limits[0] ?? null)
            || !is_int($limits[1] ?? null)
            || $limits[0] < 0
            || $limits[1] < 0
            || !array_key_exists($key, $input)
            || !kssmi_array_is_list($input[$key])) {
            continue;
        }

        $items = [];
        $seen = 0;
        foreach ($input[$key] as $value) {
            if ($seen++ >= $limits[0]) break;
            if (!is_string($value)) continue;
            $items[] = kssmi_scalar_text($value, $limits[1]);
        }
        $normalized[$key] = $items;
    }

    return $normalized;
}

/**
 * Canonical session policy shared by every Kssmi admin page.
 */
function kssmi_admin_session_options(): array {
    return [
        'use_strict_mode' => 1,
        'use_cookies' => 1,
        'use_only_cookies' => 1,
        'use_trans_sid' => 0,
        'cookie_lifetime' => 0,
        'cookie_path' => '/',
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ];
}

/**
 * Start the admin session once with strict, cookie-only semantics.
 *
 * Failure is closed centrally so an admin page can never continue with a
 * missing or partially configured session.
 */
function kssmi_admin_session_bootstrap(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    if (session_status() === PHP_SESSION_DISABLED || !@session_start(kssmi_admin_session_options())) {
        error_log('KSSMI: unable to start hardened admin session');
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        kssmi_admin_security_headers("default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        echo 'Service unavailable.';
        exit;
    }
}

/**
 * array_is_list polyfill (PHP < 8.1 servers, mirrors email-log-store.php).
 */
function kssmi_array_is_list($value): bool {
    if (!is_array($value)) return false;
    if (function_exists('array_is_list')) return array_is_list($value);
    $expected = 0;
    foreach ($value as $key => $_value) {
        if ($key !== $expected++) return false;
    }
    return true;
}

/**
 * Write every byte to the handle; returns false on short/zero write.
 */
function kssmi_admin_write_all($handle, string $contents): bool {
    $length = strlen($contents);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }
    return fflush($handle);
}

/**
 * Resolve where a credential file actually lives. A release holds only links
 * to the persistent credential files, and renaming onto or locking beside the
 * link itself would put the credential in the release, where the next deploy
 * discards it. A link must resolve to an existing regular file; a dangling
 * link, or one that ends in a directory, returns null so that callers fail
 * closed instead of creating state beside it. Any other path is returned
 * unchanged.
 */
function kssmi_admin_storage_path(string $path): ?string {
    if (!is_link($path)) return $path;
    $target = realpath($path);
    return (is_string($target) && is_file($target)) ? $target : null;
}

/**
 * Atomic file write: temp file in the same directory, chmod before any
 * content is written, fsync, then rename into place. Never leaves a partial
 * destination file. A link is resolved first, so the file it points to is
 * replaced and the link itself stays in place.
 */
function kssmi_admin_atomic_write(string $path, string $contents, int $mode = 0600): bool {
    $target = kssmi_admin_storage_path($path);
    if ($target === null) return false;
    $parent = dirname($target);
    if (!is_dir($parent) || !is_writable($parent)) return false;

    try {
        $tempPath = $target . '.tmp-' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return false;
    }

    $handle = @fopen($tempPath, 'xb');
    if ($handle === false) return false;
    if (!@chmod($tempPath, $mode)) {
        fclose($handle);
        @unlink($tempPath);
        return false;
    }

    $written = kssmi_admin_write_all($handle, $contents);
    if ($written && function_exists('fsync')) $written = @fsync($handle);
    fclose($handle);
    if (!$written || !@rename($tempPath, $target)) {
        @unlink($tempPath);
        return false;
    }
    @chmod($target, $mode);
    return true;
}

/**
 * Acquire an exclusive (LOCK_EX) or shared (LOCK_SH) flock on a stable
 * sidecar lock file beside the resolved storage path, so every release locks
 * the same persistent lock file. Returns ['ok' => bool,
 * 'handle' => resource|null, 'error' => string|null].
 */
function kssmi_admin_file_lock(string $path, int $operation): array {
    $target = kssmi_admin_storage_path($path);
    if ($target === null) return ['ok' => false, 'error' => 'storage_path_invalid'];
    $parent = dirname($target);
    if (!is_dir($parent) || !is_writable($parent)) {
        return ['ok' => false, 'error' => 'parent_not_writable'];
    }
    $lockPath = $target . '.lock';
    $handle = @fopen($lockPath, 'c+b');
    if ($handle === false) return ['ok' => false, 'error' => 'lock_open_failed'];
    @chmod($lockPath, 0600);
    if (!flock($handle, $operation)) {
        fclose($handle);
        return ['ok' => false, 'error' => 'lock_failed'];
    }
    return ['ok' => true, 'handle' => $handle, 'error' => null];
}

/**
 * Release a lock acquired by kssmi_admin_file_lock.
 */
function kssmi_admin_file_unlock(array $lock): void {
    if (!is_resource($lock['handle'] ?? null)) return;
    flock($lock['handle'], LOCK_UN);
    fclose($lock['handle']);
}

/**
 * Read a secret file (password hash / reset tokens) under a shared lock.
 * Missing or empty file → null, matching the backends' current getPasswordHash
 * fallback semantics (they treat an absent hash as "no password set").
 */
function kssmi_admin_secret_read(string $path): ?string {
    $lock = kssmi_admin_file_lock($path, LOCK_SH);
    if (!$lock['ok']) return null;
    try {
        if (!is_file($path) || !is_readable($path)) return null;
        $contents = @file_get_contents($path);
        if (!is_string($contents)) return null;
        $contents = trim($contents);
        return $contents === '' ? null : $contents;
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

/**
 * Write a secret file atomically (0600) under an exclusive lock.
 * Empty contents are rejected (matching the "missing hash disables login"
 * semantics of the current backends).
 */
function kssmi_admin_secret_write(string $path, string $contents): bool {
    if ($contents === '') return false;
    $lock = kssmi_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok']) return false;
    try {
        return kssmi_admin_atomic_write($path, $contents, 0600);
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

// ── Credential generation admin sessions ────────────────────────────────────
// A password change or reset replaces this server-owned random value. Every
// session and reset token issued before then fails closed, even though PHP's
// file-backed sessions cannot be enumerated and deleted individually. The
// value is random rather than a counter, so the deploy can replace it during
// migration or recovery without knowing which values were ever issued.

function kssmi_admin_credential_generation_path(string $passwordPath): string {
    return dirname($passwordPath) . '/.admin_credential_generation';
}

function kssmi_admin_credential_generation_valid($value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
}

function kssmi_admin_credential_generation_read(string $path): ?string {
    $value = kssmi_admin_secret_read($path);
    return kssmi_admin_credential_generation_valid($value) ? $value : null;
}

/**
 * A fresh 128-bit generation, or null when no secure random source is
 * available. The shape is checked here, so an empty or partial value can
 * never reach a writer.
 */
function kssmi_admin_credential_generation_new(): ?string {
    try {
        $generation = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return null;
    }
    return kssmi_admin_credential_generation_valid($generation) ? $generation : null;
}

/**
 * Return the current generation. One is created only for a development or CI
 * store: a plain path with no file yet. A link, dangling or not, and an
 * existing file with invalid contents fail closed and are never overwritten;
 * in production the deploy owns this state.
 */
function kssmi_admin_credential_generation_ensure(string $path): ?string {
    $generation = kssmi_admin_credential_generation_read($path);
    if ($generation !== null) return $generation;
    if (is_link($path) || file_exists($path)) return null;
    $generation = kssmi_admin_credential_generation_new();
    if ($generation === null || !kssmi_admin_secret_write($path, $generation)) return null;
    return kssmi_admin_credential_generation_read($path);
}

/**
 * Credential operations nest the reset-token, generation and password locks
 * in that order. A process's flock() locks on separate handles still exclude
 * each other, so two of these paths resolving to one file would block forever
 * on the inner lock. Callers check this before taking any lock and fail closed
 * on such a misconfigured link. A path that cannot be resolved is skipped: it
 * can never be locked, so the operation that needs it fails on its own.
 */
function kssmi_admin_credential_paths_distinct(string ...$paths): bool {
    $seen = [];
    foreach ($paths as $path) {
        $target = kssmi_admin_storage_path($path);
        if ($target === null) continue;
        $parent = realpath(dirname($target));
        $key = $parent === false ? $target : $parent . DIRECTORY_SEPARATOR . basename($target);
        if (isset($seen[$key])) return false;
        $seen[$key] = true;
    }
    return true;
}

function kssmi_admin_session_absolute_ttl(): int {
    return 8 * 3600;
}

function kssmi_admin_session_inactivity_ttl(): int {
    return 30 * 60;
}

function kssmi_admin_session_revoke_local(): void {
    unset(
        $_SESSION['email_logs_auth'],
        $_SESSION['credential_generation'],
        // Sessions issued before credential generations carry this key.
        $_SESSION['credential_version'],
        $_SESSION['admin_authenticated_at'],
        $_SESSION['admin_last_seen_at'],
        $_SESSION['csrf_token']
    );
}

function kssmi_admin_session_establish(
    string $credentialGenerationPath,
    ?int $now = null,
    ?string $expectedCredentialGeneration = null
): bool {
    $generation = kssmi_admin_credential_generation_ensure($credentialGenerationPath);
    if ($generation === null
        || ($expectedCredentialGeneration !== null
            && !hash_equals($generation, $expectedCredentialGeneration))) {
        return false;
    }
    $current = $now ?? time();
    if (!session_regenerate_id(true)) return false;
    $_SESSION['email_logs_auth'] = true;
    $_SESSION['credential_generation'] = $generation;
    $_SESSION['admin_authenticated_at'] = $current;
    $_SESSION['admin_last_seen_at'] = $current;
    kssmi_admin_csrf_rotate();
    return true;
}

/**
 * Authenticate and create an admin session while holding the same lock used
 * by password rotation. The password hash and the credential generation must
 * come from one rotation: accepting an old hash and then issuing a session
 * with a newly-read generation would defeat revocation.
 */
function kssmi_admin_authenticate_and_establish(
    string $passwordPath,
    string $rotationLockPath,
    string $credentialGenerationPath,
    $submittedPassword,
    ?int $now = null
): bool {
    if (!is_string($submittedPassword) || $submittedPassword === '') return false;
    if (!kssmi_admin_credential_paths_distinct($rotationLockPath, $credentialGenerationPath, $passwordPath)) {
        return false;
    }

    $lock = kssmi_admin_file_lock($rotationLockPath, LOCK_EX);
    if (!$lock['ok']) return false;

    try {
        $storedHash = @file_get_contents($passwordPath);
        if (!is_string($storedHash) || trim($storedHash) === '') return false;
        $generation = kssmi_admin_credential_generation_ensure($credentialGenerationPath);
        if ($generation === null || !password_verify($submittedPassword, trim($storedHash))) return false;

        return kssmi_admin_session_establish($credentialGenerationPath, $now, $generation);
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_admin_session_authenticated(string $credentialGenerationPath, ?int $now = null): bool {
    $current = $now ?? time();
    $storedGeneration = kssmi_admin_credential_generation_read($credentialGenerationPath);
    $sessionGeneration = $_SESSION['credential_generation'] ?? null;
    $issuedAt = $_SESSION['admin_authenticated_at'] ?? null;
    $lastSeenAt = $_SESSION['admin_last_seen_at'] ?? null;
    $valid = ($_SESSION['email_logs_auth'] ?? false) === true
        && $storedGeneration !== null
        && kssmi_admin_credential_generation_valid($sessionGeneration)
        && hash_equals($storedGeneration, $sessionGeneration)
        && is_int($issuedAt) && $issuedAt > 0 && $issuedAt <= $current
        && is_int($lastSeenAt) && $lastSeenAt > 0 && $lastSeenAt <= $current
        && $current - $issuedAt <= kssmi_admin_session_absolute_ttl()
        && $current - $lastSeenAt <= kssmi_admin_session_inactivity_ttl();
    if (!$valid) {
        kssmi_admin_session_revoke_local();
        return false;
    }
    $_SESSION['admin_last_seen_at'] = $current;
    return true;
}

// ── Signed admin marker cookie (阶段 3 步骤 3) ────────────────────────────
// The admin pages used to set a plaintext `vjt_admin=1` cookie that any
// visitor could forge to disappear from analytics. The cookie now carries a
// server-signed value `v1.<expires>.<nonce>.<hmac>` whose key is derived from
// the password hash: without the hash nobody can mint a valid marker, and
// expiry is enforced on every validation. The cookie is HttpOnly and is
// intentionally evaluated only by server-side tracking endpoints.

function kssmi_admin_marker_ttl(): int {
    return 43200; // 12 hours, matching the admin session lifetime
}

/**
 * The marker key derives from the password hash file (never stored
 * separately). Changing the password invalidates every outstanding marker.
 */
function kssmi_admin_marker_secret_path(): string {
    $override = getenv('KSSMI_ADMIN_MARKER_SECRET_FILE');
    if (is_string($override) && trim($override) !== '') return trim($override);
    return dirname(__DIR__) . '/.email_logs_password';
}

function kssmi_admin_marker_key(): ?string {
    $passwordHash = kssmi_admin_secret_read(kssmi_admin_marker_secret_path());
    if (!is_string($passwordHash) || $passwordHash === '') return null;
    return hash_hmac('sha256', 'kssmi-admin-marker-key-v1', $passwordHash, true);
}

/**
 * Build a signed marker value: v1.<expires>.<nonce>.<hmac>.
 * Returns null when no key is available (e.g. no password set yet).
 */
function kssmi_admin_marker_value(int $expiresAt, ?string $nonce = null): ?string {
    $key = kssmi_admin_marker_key();
    if ($key === null) return null;

    if ($nonce === null) {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return null;
        }
    }
    if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) return null;

    $payload = 'v1.' . $expiresAt . '.' . $nonce;
    return $payload . '.' . hash_hmac('sha256', $payload, $key);
}

/**
 * Validate a marker value: format, expiry window, and HMAC. A forged or
 * expired marker returns false (a visitor can set any cookie value; only a
 * signed one is accepted).
 */
function kssmi_admin_marker_valid($value, ?int $now = null): bool {
    if (!is_string($value)) return false;
    if (!preg_match('/^v1\.([0-9]{10,11})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $value, $parts)) {
        return false;
    }

    $now = $now ?? time();
    $expiresAt = (int)$parts[1];
    if ($expiresAt <= $now || $expiresAt > $now + kssmi_admin_marker_ttl()) return false;

    $key = kssmi_admin_marker_key();
    if ($key === null) return false;
    $payload = 'v1.' . $parts[1] . '.' . $parts[2];
    return hash_equals(hash_hmac('sha256', $payload, $key), $parts[3]);
}

/**
 * Set or clear the signed admin marker cookie. Authenticated → signed value
 * with a 12h expiry; otherwise → expired empty cookie (logout).
 */
function kssmi_admin_set_marker_cookie(bool $authenticated): void {
    $expiresAt = time() - 3600;
    $value = '';
    if ($authenticated) {
        $expiresAt = time() + kssmi_admin_marker_ttl();
        $signedValue = kssmi_admin_marker_value($expiresAt);
        if (is_string($signedValue)) {
            $value = $signedValue;
        } else {
            $expiresAt = time() - 3600;
        }
    }

    setcookie('vjt_admin', $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if ($value !== '') {
        $_COOKIE['vjt_admin'] = $value;
    } else {
        unset($_COOKIE['vjt_admin']);
    }
}

/**
 * True when the request carries a VALID signed admin marker. This is used to
 * exclude admin traffic from analytics — it never grants admin access (the
 * session check does that).
 */
function kssmi_admin_tracking_excluded(): bool {
    return kssmi_admin_marker_valid($_COOKIE['vjt_admin'] ?? null);
}

// ── CSRF tokens (阶段 3 步骤 4) ───────────────────────────────────────────
// The admin pages used to hand-roll CSRF checks with scattered
// hash_equals($_SESSION['csrf_token'], ...) calls. These helpers centralize
// token generation/validation/rotation with a stable 64-hex shape. The reset
// flow keeps its own csrf_reset token (consumed atomically in step 5).

function kssmi_admin_csrf_token(string $key = 'csrf_token'): string {
    if (!is_string($_SESSION[$key] ?? null) || strlen($_SESSION[$key]) !== 64) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function kssmi_admin_csrf_valid($submitted, string $key = 'csrf_token'): bool {
    return is_string($submitted) &&
        is_string($_SESSION[$key] ?? null) &&
        hash_equals($_SESSION[$key], $submitted);
}

function kssmi_admin_csrf_rotate(string $key = 'csrf_token'): string {
    $_SESSION[$key] = bin2hex(random_bytes(32));
    return $_SESSION[$key];
}

// ── Reset token atomic consumption (阶段 3 步骤 5) ────────────────────────
// The reset-token store (.email_reset_tokens.json) must be read-modified-
// written under an exclusive lock so concurrent clicks on the emailed reset
// link can only ever consume the token once (replay protection). The token
// file shape is { "<64-hex>": { "created": int, "expires": int,
// "credential_generation": "<32-hex>" }, ... }. A token is valid only while
// the generation it was issued under is still current.

function kssmi_admin_reset_tokens_decode($raw, &$error): ?array {
    $error = null;
    if (!is_string($raw) || trim($raw) === '') {
        // An empty/whitespace store is a valid "no tokens yet" state
        // (deploy-release.sh touch-creates the file empty on activation).
        return [];
    }
    $tokens = json_decode($raw, true);
    if (!is_array($tokens) || json_last_error() !== JSON_ERROR_NONE) {
        $error = 'invalid_json';
        return null;
    }
    foreach ($tokens as $token => $data) {
        if (
            !is_string($token) ||
            preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 ||
            !is_array($data) ||
            !is_numeric($data['expires'] ?? null) ||
            !kssmi_admin_credential_generation_valid($data['credential_generation'] ?? null)
        ) {
            $error = 'invalid_schema';
            return null;
        }
    }
    return $tokens;
}

function kssmi_admin_reset_tokens_read(string $path, ?int $now = null): array {
    $lock = kssmi_admin_file_lock($path, LOCK_SH);
    if (!$lock['ok']) return ['ok' => false, 'tokens' => [], 'error' => $lock['error']];
    try {
        if (!file_exists($path)) return ['ok' => true, 'tokens' => [], 'error' => null];
        $raw = @file_get_contents($path);
        $decodeError = null;
        $tokens = kssmi_admin_reset_tokens_decode($raw, $decodeError);
        if ($decodeError !== null) {
            return ['ok' => false, 'tokens' => [], 'error' => $decodeError];
        }
        $current = $now ?? time();
        $tokens = array_filter(
            $tokens,
            fn($data) => (int)($data['expires'] ?? 0) > $current
        );
        return ['ok' => true, 'tokens' => $tokens, 'error' => null];
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_admin_reset_tokens_mutate(string $path, callable $mutator): array {
    $lock = kssmi_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok']) return ['ok' => false, 'tokens' => [], 'error' => $lock['error']];
    try {
        $raw = file_exists($path) ? @file_get_contents($path) : '[]';
        $decodeError = null;
        $tokens = kssmi_admin_reset_tokens_decode($raw, $decodeError);
        if ($decodeError !== null) {
            return ['ok' => false, 'tokens' => [], 'error' => $decodeError];
        }
        try {
            $tokens = $mutator($tokens);
        } catch (Throwable $e) {
            return ['ok' => false, 'tokens' => [], 'error' => 'mutation_failed'];
        }
        if (!is_array($tokens)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'mutation_invalid'];
        }
        $encoded = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'encode_failed'];
        }
        if (!kssmi_admin_atomic_write($path, $encoded, 0600)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'write_failed'];
        }
        return ['ok' => true, 'tokens' => $tokens, 'error' => null];
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_admin_reset_token_add(string $path, string $token, int $expires, string $credentialGenerationPath, ?int $now = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $current = $now ?? time();
    if ($expires <= $current) return false;
    $generation = kssmi_admin_credential_generation_ensure($credentialGenerationPath);
    if ($generation === null) return false;
    $result = kssmi_admin_reset_tokens_mutate(
        $path,
        function(array $tokens) use ($token, $expires, $current, $generation): array {
            // A reset email is a credential issuance event, not a collection
            // of parallel credentials. Replacing the complete set while the
            // store lock is held atomically revokes any previous active link.
            return [
                $token => [
                    'created' => $current,
                    'expires' => $expires,
                    'credential_generation' => $generation,
                ],
            ];
        }
    );
    return $result['ok'];
}

function kssmi_admin_reset_token_valid(string $path, string $token, string $credentialGenerationPath, ?int $now = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $result = kssmi_admin_reset_tokens_read($path, $now);
    if (!$result['ok'] || !isset($result['tokens'][$token])) return false;
    $generation = kssmi_admin_credential_generation_read($credentialGenerationPath);
    return $generation !== null
        && hash_equals($generation, $result['tokens'][$token]['credential_generation']);
}

/**
 * Atomically remove a reset token (and expired tokens) under an exclusive
 * lock. Returns ['ok' => bool, 'consumed' => bool, 'error' => ?string].
 * Replay of an already-consumed token returns consumed=false, never true.
 */
function kssmi_admin_reset_token_consume(string $path, string $token, ?int $now = null): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['ok' => true, 'consumed' => false, 'error' => null];
    }
    $current = $now ?? time();
    $consumed = false;
    $result = kssmi_admin_reset_tokens_mutate(
        $path,
        function(array $tokens) use ($token, $current, &$consumed): array {
            foreach ($tokens as $storedToken => $data) {
                if ((int)($data['expires'] ?? 0) <= $current) {
                    unset($tokens[$storedToken]);
                }
            }
            if (isset($tokens[$token])) {
                unset($tokens[$token]);
                $consumed = true;
            }
            return $tokens;
        }
    );
    return [
        'ok' => $result['ok'],
        'consumed' => $result['ok'] && $consumed,
        'error' => $result['error'] ?? null,
    ];
}

/**
 * Rotate password, reset credentials, and the credential generation under
 * the reset-token store's exclusive lock. The security-safe order is token
 * revocation, generation replacement, then password replacement: a filesystem
 * failure can require a new reset, but cannot leave a sibling reset token or
 * old authenticated session usable after a successful password change. The
 * locks nest as reset tokens, then generation, then password, and the three
 * paths must resolve to different files, so no lock is ever taken twice.
 *
 * Returns ['ok' => bool, 'changed' => bool, 'consumed' => bool,
 *          'error' => ?string]. A replay is ok=true/changed=false.
 */
function kssmi_admin_rotate_credentials(
    string $tokensPath,
    string $passwordPath,
    string $newPassword,
    ?string $token = null,
    ?string $credentialGenerationPath = null,
    ?int $now = null
): array {
    if ($newPassword === '') {
        return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'empty_password'];
    }
    if ($token !== null && preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['ok' => true, 'changed' => false, 'consumed' => false, 'error' => null];
    }
    $generationPath = $credentialGenerationPath ?? kssmi_admin_credential_generation_path($passwordPath);
    if (!kssmi_admin_credential_paths_distinct($tokensPath, $generationPath, $passwordPath)) {
        return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'credential_paths_not_distinct'];
    }
    $lock = kssmi_admin_file_lock($tokensPath, LOCK_EX);
    if (!$lock['ok']) return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => $lock['error']];
    try {
        $raw = file_exists($tokensPath) ? @file_get_contents($tokensPath) : '[]';
        $decodeError = null;
        $tokens = kssmi_admin_reset_tokens_decode($raw, $decodeError);
        if ($tokens === null) return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => $decodeError];
        $current = $now ?? time();
        foreach ($tokens as $storedToken => $data) {
            if ((int)($data['expires'] ?? 0) <= $current) unset($tokens[$storedToken]);
        }
        $generation = kssmi_admin_credential_generation_ensure($generationPath);
        if ($generation === null) {
            // The credential generation is unavailable or invalid (for
            // example a dangling link, or a missing parent directory). Fail
            // closed: a presented token is burned so the failed attempt can
            // never be replayed, and no password change is ever reported.
            if ($token !== null && isset($tokens[$token])) {
                if (!kssmi_admin_atomic_write($tokensPath, '[]', 0600)) {
                    return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'token_revoke_failed'];
                }
                return ['ok' => false, 'changed' => false, 'consumed' => true, 'error' => 'generation_read_failed'];
            }
            return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'generation_read_failed'];
        }
        if ($token !== null && (!isset($tokens[$token])
            || !hash_equals($generation, $tokens[$token]['credential_generation']))) {
            return ['ok' => true, 'changed' => false, 'consumed' => false, 'error' => null];
        }

        // Clear every active reset credential first. If a later file write
        // fails, the operation may require a fresh reset but never leaves a
        // sibling token capable of replacing the password.
        if (!kssmi_admin_atomic_write($tokensPath, '[]', 0600)) {
            return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'token_revoke_failed'];
        }
        // Password hashing is intentionally deferred until this request owns
        // the reset token. Concurrent replays return above without spending
        // CPU on attacker-chosen password input.
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'password_hash_failed'];
        }
        $nextGeneration = kssmi_admin_credential_generation_new();
        if ($nextGeneration === null
            || !kssmi_admin_secret_write($generationPath, $nextGeneration)) {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'generation_write_failed'];
        }
        if (!kssmi_admin_secret_write($passwordPath, $passwordHash)) {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'password_write_failed'];
        }
        return ['ok' => true, 'changed' => true, 'consumed' => $token !== null, 'error' => null];
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_admin_change_password(
    string $tokensPath,
    string $passwordPath,
    string $newPassword,
    ?string $credentialGenerationPath = null,
    ?int $now = null
): array {
    return kssmi_admin_rotate_credentials(
        $tokensPath, $passwordPath, $newPassword, null, $credentialGenerationPath, $now
    );
}

function kssmi_admin_reset_password(
    string $tokensPath,
    string $passwordPath,
    string $token,
    string $newPassword,
    ?int $now = null,
    ?string $credentialGenerationPath = null
): array {
    return kssmi_admin_rotate_credentials(
        $tokensPath, $passwordPath, $newPassword, $token, $credentialGenerationPath, $now
    );
}
