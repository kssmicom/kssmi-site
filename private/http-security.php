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
 *
 * Cloudflare Access remains the authentication gate at the edge. The origin
 * gate below independently verifies the TCP peer address (REMOTE_ADDR) before
 * either admin page starts a session. Client-controlled CF-* headers are never
 * accepted as proof that a request traversed Cloudflare.
 */

if (defined('KSSMI_HTTP_SECURITY_LOADED')) {
    return;
}
define('KSSMI_HTTP_SECURITY_LOADED', true);

/**
 * Return true only when the connection peer is a published Cloudflare proxy.
 *
 * REMOTE_ADDR is supplied by the web server from the TCP connection. Unlike
 * CF-Ray / CF-Connecting-IP / X-Forwarded-For, a direct-origin HTTP client
 * cannot choose it. The CIDR matcher currently lives in rate-limit.php and is
 * shared here so the two trust decisions cannot drift apart. 阶段 4 will move
 * its range list to the validated versioned snapshot.
 */
function kssmi_admin_request_from_trusted_proxy($remoteAddress = null): bool {
    if ($remoteAddress === null) {
        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    }
    if (!is_string($remoteAddress)) return false;

    $remoteAddress = trim($remoteAddress);
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) return false;

    if (!function_exists('kssmi_is_cloudflare_proxy')) {
        $rateLimitModule = __DIR__ . '/rate-limit.php';
        if (!is_file($rateLimitModule) || !is_readable($rateLimitModule)) {
            return false;
        }
        require_once $rateLimitModule;
    }

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
 * Atomic file write: temp file in the same directory, chmod before any
 * content is written, fsync, then rename into place. Never leaves a partial
 * destination file.
 */
function kssmi_admin_atomic_write(string $path, string $contents, int $mode = 0600): bool {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) return false;

    try {
        $tempPath = $path . '.tmp-' . bin2hex(random_bytes(8));
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
    if (!$written || !@rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }
    @chmod($path, $mode);
    return true;
}

/**
 * Acquire an exclusive (LOCK_EX) or shared (LOCK_SH) flock on a stable
 * sidecar lock file. Returns ['ok' => bool, 'handle' => resource|null,
 * 'error' => string|null].
 */
function kssmi_admin_file_lock(string $path, int $operation): array {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) {
        return ['ok' => false, 'error' => 'parent_not_writable'];
    }
    $handle = @fopen($path . '.lock', 'c+b');
    if ($handle === false) return ['ok' => false, 'error' => 'lock_open_failed'];
    @chmod($path . '.lock', 0600);
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
// file shape is { "<64-hex>": { "created": int, "expires": int }, ... }.

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
            !is_numeric($data['expires'] ?? null)
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

function kssmi_admin_reset_token_add(string $path, string $token, int $expires, ?int $now = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $current = $now ?? time();
    if ($expires <= $current) return false;
    $result = kssmi_admin_reset_tokens_mutate(
        $path,
        function(array $tokens) use ($token, $expires, $current): array {
            foreach ($tokens as $storedToken => $data) {
                if ((int)($data['expires'] ?? 0) <= $current) unset($tokens[$storedToken]);
            }
            $tokens[$token] = ['created' => $current, 'expires' => $expires];
            return $tokens;
        }
    );
    return $result['ok'];
}

function kssmi_admin_reset_token_valid(string $path, string $token, ?int $now = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $result = kssmi_admin_reset_tokens_read($path, $now);
    return $result['ok'] && isset($result['tokens'][$token]);
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
