<?php
declare(strict_types=1);

/**
 * Server-issued identities and one-time capabilities for public VJT writes.
 *
 * The signing key is generated once inside the private VJT data directory.
 * Browser-visible visitor/session IDs are never accepted as proof by
 * themselves: every write must also present the matching HttpOnly identity
 * cookie and a short-lived, purpose-bound capability.
 */

const KSSMI_VJT_IDENTITY_COOKIE = 'KSSMI_VJT_IDENTITY';
const KSSMI_VJT_CONTACT_COOKIE = 'KSSMI_VJT_CONTACT';
const KSSMI_VJT_CAPABILITY_TTL = 600;
const KSSMI_VJT_IDENTITY_TTL = 31536000;
const KSSMI_VJT_CONTACT_TTL = 1800;

function kssmi_vjt_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function kssmi_vjt_base64url_decode(string $value): ?string {
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) return null;
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return is_string($decoded) ? $decoded : null;
}

function kssmi_vjt_event_auth_secret(): string {
    static $secret = null;
    if (is_string($secret) && strlen($secret) === 32) return $secret;
    if (!defined('VJT_DATA_DIR')) {
        throw new RuntimeException('VJT data directory is not initialized');
    }

    $override = getenv('KSSMI_VJT_EVENT_AUTH_SECRET');
    if (is_string($override) && $override !== '') {
        $decoded = preg_match('/^[a-f0-9]{64}$/Di', $override) === 1
            ? hex2bin($override)
            : base64_decode($override, true);
        if (!is_string($decoded) || strlen($decoded) < 32) {
            throw new RuntimeException('KSSMI_VJT_EVENT_AUTH_SECRET must contain at least 32 bytes');
        }
        $secret = substr($decoded, 0, 32);
        return $secret;
    }

    if (!is_dir(VJT_DATA_DIR) && !@mkdir(VJT_DATA_DIR, 0700, true) && !is_dir(VJT_DATA_DIR)) {
        throw new RuntimeException('Unable to create the private VJT data directory');
    }

    $path = VJT_DATA_DIR . DIRECTORY_SEPARATOR . 'event-auth.key';
    $readSecret = static function () use ($path): ?string {
        $encoded = @file_get_contents($path);
        if (!is_string($encoded)) return null;
        $encoded = trim($encoded);
        if (preg_match('/^[a-f0-9]{64}$/Di', $encoded) !== 1) return null;
        $value = hex2bin($encoded);
        return is_string($value) && strlen($value) === 32 ? $value : null;
    };

    $existing = $readSecret();
    if ($existing !== null) {
        $secret = $existing;
        return $secret;
    }

    $candidate = random_bytes(32);
    $handle = @fopen($path, 'x+b');
    if (is_resource($handle)) {
        @chmod($path, 0600);
        $encoded = bin2hex($candidate) . "\n";
        $written = 0;
        while ($written < strlen($encoded)) {
            $count = fwrite($handle, substr($encoded, $written));
            if ($count === false || $count === 0) break;
            $written += $count;
        }
        fflush($handle);
        fclose($handle);
        if ($written === strlen($encoded)) {
            $secret = $candidate;
            return $secret;
        }
        @unlink($path);
        throw new RuntimeException('Unable to persist the VJT event-auth key');
    }

    // Another request may have won the exclusive-create race.
    $existing = $readSecret();
    if ($existing === null) {
        throw new RuntimeException('Unable to read the VJT event-auth key');
    }
    $secret = $existing;
    return $secret;
}

function kssmi_vjt_sign_claims(array $claims, string $context): string {
    $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Unable to encode VJT claims');
    $payload = kssmi_vjt_base64url_encode($json);
    $signature = hash_hmac('sha256', $context . "\0" . $payload, kssmi_vjt_event_auth_secret(), true);
    return $payload . '.' . kssmi_vjt_base64url_encode($signature);
}

function kssmi_vjt_verify_claims($token, string $context): ?array {
    if (!is_string($token) || strlen($token) < 40 || strlen($token) > 2048) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$payload, $encodedSignature] = $parts;
    $signature = kssmi_vjt_base64url_decode($encodedSignature);
    if ($signature === null || strlen($signature) !== 32) return null;
    $expected = hash_hmac('sha256', $context . "\0" . $payload, kssmi_vjt_event_auth_secret(), true);
    if (!hash_equals($expected, $signature)) return null;
    $json = kssmi_vjt_base64url_decode($payload);
    if ($json === null) return null;
    $claims = json_decode($json, true);
    return is_array($claims) && ($claims['v'] ?? null) === 1 ? $claims : null;
}

function kssmi_vjt_random_tracking_id(string $prefix): string {
    return $prefix . kssmi_vjt_base64url_encode(random_bytes(18));
}

function kssmi_vjt_request_cookie_secure(): bool {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $host = strtolower(preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))));
    $local = in_array($remote, ['127.0.0.1', '::1'], true)
        && in_array($host, ['localhost', '127.0.0.1', '[::1]', ''], true);
    return !$local;
}

function kssmi_vjt_set_private_cookie(string $name, string $value, int $expiresAt): void {
    setcookie($name, $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => kssmi_vjt_request_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function kssmi_vjt_identity_from_token($token, ?int $now = null): ?array {
    $now = $now ?? time();
    $claims = kssmi_vjt_verify_claims($token, 'identity-v1');
    if ($claims === null
        || !is_int($claims['exp'] ?? null)
        || $claims['exp'] < $now
        || !is_int($claims['session_exp'] ?? null)
        || preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/D', (string)($claims['visitor_id'] ?? '')) !== 1
        || preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/D', (string)($claims['session_id'] ?? '')) !== 1) {
        return null;
    }
    return $claims;
}

function kssmi_vjt_identity_from_request(?int $now = null): ?array {
    return kssmi_vjt_identity_from_token($_COOKIE[KSSMI_VJT_IDENTITY_COOKIE] ?? null, $now);
}

function kssmi_vjt_bootstrap_identity(
    bool $rotateSession,
    int $sessionLifetime,
    ?int $now = null,
    bool $emitCookie = true
): array {
    $now = $now ?? time();
    $sessionLifetime = min(7200, max(300, $sessionLifetime));
    $existing = kssmi_vjt_identity_from_request($now);
    $visitorId = $existing['visitor_id'] ?? kssmi_vjt_random_tracking_id('vjtv_');
    $sessionExpired = $existing === null || (int)($existing['session_exp'] ?? 0) < $now;
    $sessionId = (!$rotateSession && !$sessionExpired)
        ? (string)$existing['session_id']
        : kssmi_vjt_random_tracking_id('vjts_');
    $claims = [
        'v' => 1,
        'visitor_id' => $visitorId,
        'session_id' => $sessionId,
        'session_exp' => $now + $sessionLifetime,
        'iat' => $now,
        'exp' => $now + KSSMI_VJT_IDENTITY_TTL,
    ];
    $token = kssmi_vjt_sign_claims($claims, 'identity-v1');
    if ($emitCookie) kssmi_vjt_set_private_cookie(KSSMI_VJT_IDENTITY_COOKIE, $token, $claims['exp']);
    $claims['token'] = $token;
    return $claims;
}

function kssmi_vjt_contact_session_from_token($token, ?int $now = null): ?array {
    $now = $now ?? time();
    $claims = kssmi_vjt_verify_claims($token, 'contact-session-v1');
    if ($claims === null
        || !is_int($claims['exp'] ?? null)
        || $claims['exp'] < $now
        || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', (string)($claims['contact_id'] ?? '')) !== 1) {
        return null;
    }
    return $claims;
}

function kssmi_vjt_contact_session_from_request(?int $now = null): ?array {
    return kssmi_vjt_contact_session_from_token($_COOKIE[KSSMI_VJT_CONTACT_COOKIE] ?? null, $now);
}

function kssmi_vjt_bootstrap_contact_session(?int $now = null, bool $emitCookie = true): array {
    $now = $now ?? time();
    $existing = kssmi_vjt_contact_session_from_request($now);
    $claims = [
        'v' => 1,
        'contact_id' => $existing['contact_id'] ?? kssmi_vjt_base64url_encode(random_bytes(18)),
        'iat' => $now,
        'exp' => $now + KSSMI_VJT_CONTACT_TTL,
    ];
    $token = kssmi_vjt_sign_claims($claims, 'contact-session-v1');
    if ($emitCookie) kssmi_vjt_set_private_cookie(KSSMI_VJT_CONTACT_COOKIE, $token, $claims['exp']);
    $claims['token'] = $token;
    return $claims;
}

function kssmi_vjt_issue_capabilities(string $purpose, array $binding, int $count = 1, ?int $now = null): array {
    if (!in_array($purpose, ['pageview', 'submission', 'contact_intent'], true)) {
        throw new InvalidArgumentException('Invalid VJT capability purpose');
    }
    $now = $now ?? time();
    $count = min(16, max(1, $count));
    $tokens = [];
    for ($i = 0; $i < $count; $i++) {
        $claims = [
            'v' => 1,
            'purpose' => $purpose,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + KSSMI_VJT_CAPABILITY_TTL,
        ];
        if ($purpose === 'contact_intent') {
            $claims['contact_id'] = (string)($binding['contact_id'] ?? '');
        } else {
            $claims['visitor_id'] = (string)($binding['visitor_id'] ?? '');
            $claims['session_id'] = (string)($binding['session_id'] ?? '');
        }
        $tokens[] = kssmi_vjt_sign_claims($claims, 'capability-v1');
    }
    return $tokens;
}

function kssmi_vjt_validate_capability(
    $token,
    string $purpose,
    array $binding,
    ?int $now = null
): ?array {
    $now = $now ?? time();
    $claims = kssmi_vjt_verify_claims($token, 'capability-v1');
    if ($claims === null
        || !hash_equals($purpose, (string)($claims['purpose'] ?? ''))
        || !is_int($claims['iat'] ?? null)
        || !is_int($claims['exp'] ?? null)
        || $claims['iat'] > $now + 60
        || $claims['exp'] < $now
        || $claims['exp'] - $claims['iat'] > KSSMI_VJT_CAPABILITY_TTL
        || preg_match('/^[a-f0-9]{32}$/D', (string)($claims['jti'] ?? '')) !== 1) {
        return null;
    }

    if ($purpose === 'contact_intent') {
        $expected = (string)($binding['contact_id'] ?? '');
        if ($expected === '' || !hash_equals($expected, (string)($claims['contact_id'] ?? ''))) return null;
    } else {
        $visitorId = (string)($binding['visitor_id'] ?? '');
        $sessionId = (string)($binding['session_id'] ?? '');
        if ($visitorId === '' || $sessionId === ''
            || !hash_equals($visitorId, (string)($claims['visitor_id'] ?? ''))
            || !hash_equals($sessionId, (string)($claims['session_id'] ?? ''))) {
            return null;
        }
    }
    return $claims;
}

/**
 * Consume a validated capability inside the caller's write transaction.
 * The unique token hash makes concurrent replay attempts lose atomically.
 */
function kssmi_vjt_consume_capability(array $claims): bool {
    $db = vjt_db();
    if (!$db->inTransaction()) {
        throw new LogicException('VJT capability consumption requires an active transaction');
    }
    $jti = (string)($claims['jti'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/D', $jti) !== 1) return false;
    $stmt = $db->prepare('INSERT OR IGNORE INTO used_event_capabilities
        (token_hash, purpose, consumed_at, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        hash('sha256', $jti),
        (string)($claims['purpose'] ?? ''),
        gmdate('Y-m-d H:i:s'),
        (int)($claims['exp'] ?? 0),
    ]);
    return $stmt->rowCount() === 1;
}

function kssmi_vjt_allowed_origins(): array {
    return [
        'https://kssmi.com',
        'https://www.kssmi.com',
        'http://localhost:4321',
        'http://localhost:4324',
        'http://localhost:4325',
        'http://127.0.0.1:4321',
        'http://127.0.0.1:4324',
        'http://127.0.0.1:4325',
        'http://[::1]:4321',
        'http://[::1]:4324',
        'http://[::1]:4325',
    ];
}

function kssmi_vjt_apply_cors(): bool {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || !in_array($origin, kssmi_vjt_allowed_origins(), true)) return false;
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    return true;
}

function kssmi_vjt_same_site_issuance_request(): bool {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '' || !in_array($origin, kssmi_vjt_allowed_origins(), true)) return false;
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    return in_array($fetchSite, ['same-origin', 'same-site'], true);
}

/**
 * Local development does not have a usable Turnstile hostname. This exception
 * is deliberately tied to the TCP peer as well as the Host header, so a
 * public caller cannot select it by forging ordinary HTTP metadata.
 */
function kssmi_vjt_is_local_development_request(): bool {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) return false;
    $host = strtolower((string)preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))));
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', ''], true);
}

/**
 * Validate the short-lived browser proof required before public VJT
 * capabilities are issued. Origin and Fetch Metadata remain browser-side CSRF
 * signals only; Turnstile Siteverify supplies the server-verified boundary.
 */
function kssmi_vjt_verify_capability_turnstile($token, $secret): array {
    if (!is_string($token) || $token === '' || strlen($token) > 4096) {
        return ['ok' => false, 'service_error' => false, 'reason' => 'missing_or_invalid_token'];
    }
    if (!is_string($secret) || $secret === '') {
        return ['ok' => false, 'service_error' => true, 'reason' => 'missing_secret'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'service_error' => true, 'reason' => 'curl_unavailable'];
    }

    $request = [
        'secret' => $secret,
        'response' => $token,
    ];
    if (function_exists('kssmi_get_client_ip')) {
        $clientIp = kssmi_get_client_ip();
        if (filter_var($clientIp, FILTER_VALIDATE_IP)) $request['remoteip'] = $clientIp;
    }

    $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($handle === false) {
        return ['ok' => false, 'service_error' => true, 'reason' => 'siteverify_init_failed'];
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($request),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($handle);
    $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($response === false || $httpCode !== 200) {
        return ['ok' => false, 'service_error' => true, 'reason' => 'siteverify_unavailable'];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return ['ok' => false, 'service_error' => true, 'reason' => 'siteverify_invalid_json'];
    }
    if (($result['success'] ?? false) !== true) {
        return ['ok' => false, 'service_error' => false, 'reason' => 'token_rejected'];
    }

    $hostname = strtolower((string)($result['hostname'] ?? ''));
    if (!in_array($hostname, ['kssmi.com', 'www.kssmi.com'], true)) {
        return ['ok' => false, 'service_error' => false, 'reason' => 'hostname_mismatch'];
    }
    if (!hash_equals('vjt_capability', (string)($result['action'] ?? ''))) {
        return ['ok' => false, 'service_error' => false, 'reason' => 'action_mismatch'];
    }
    return ['ok' => true, 'service_error' => false, 'reason' => 'verified'];
}
