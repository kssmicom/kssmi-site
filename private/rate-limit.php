<?php
/**
 * KSSMI Rate Limiter — shared by send-mail.php / email-logs.php / visitor-journey.php
 *                          / track-pageview.php / track-submission.php
 *
 * Location: private/rate-limit.php — DEPLOYED to /home/kssmi.com/private/rate-limit.php
 * (OUTSIDE public_html, alongside private_config.php — cannot be accessed via HTTP)
 *
 * Storage:
 *   - Preferred: APCu (in-memory, fast). Check `apcu_fetch` exists.
 *   - Fallback:  File-based, stored at /home/kssmi.com/rate_limit/ (webroot外)
 *
 * Key:    "rl:<endpoint>:<md5(ip)>"
 * Per-IP, per-endpoint quotas — different endpoints don't share quotas.
 *
 * Usage:
 *   // From files in public_html/:
 *   require_once dirname(__DIR__) . '/private/rate-limit.php';
 *   // From files in public_html/api/:
 *   require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
 *
 *   if (!checkRateLimit('send-mail', 2, 60)) {
 *       http_response_code(429);
 *       exit;
 *   }
 */

declare(strict_types=1);

/**
 * IP whitelist — these IPs bypass rate limiting entirely.
 *
 * Single-admin setup: the admin's home/office IP should be here, so
 * accidental wrong-password attempts don't lock out the legitimate owner.
 *
 * IMPORTANT: The whitelist is read from an external file at
 *   /home/kssmi.com/private/rate-limit-whitelist.txt
 * (deliberately OUTSIDE the deploy path so it doesn't get clobbered by
 * git pushes). To manage your whitelist:
 *
 *   # View current whitelist
 *   cat /home/kssmi.com/private/rate-limit-whitelist.txt
 *
 *   # Add your current IP (one-time setup)
 *   echo "$(curl -s ifconfig.me)" > /home/kssmi.com/private/rate-limit-whitelist.txt
 *   chmod 640 /home/kssmi.com/private/rate-limit-whitelist.txt
 *
 *   # When your IP changes (e.g. ISP rotated your IPv6)
 *   echo "$(curl -s ifconfig.me)" > /home/kssmi.com/private/rate-limit-whitelist.txt
 *
 *   # To whitelist multiple IPs (office + home), one per line:
 *   cat > /home/kssmi.com/private/rate-limit-whitelist.txt <<'EOF'
 *   203.0.113.45      # office
 *   198.51.100.7      # home
 *   2600:1f14:2c31::1 # another location (IPv6 supported)
 *   EOF
 *
 * The file is re-read on every checkRateLimit() call, so changes take
 * effect immediately — no PHP-FPM restart needed.
 *
 * If the file doesn't exist or is empty, no IPs are whitelisted.
 */
function kssmi_load_rate_limit_whitelist(): array {
    $whitelistFile = '/home/kssmi.com/private/rate-limit-whitelist.txt';
    if (!file_exists($whitelistFile) || !is_readable($whitelistFile)) {
        return [];
    }
    $lines = @file($whitelistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $trimmed = array_map('trim', $lines);
    return array_values(array_filter($trimmed, fn($l) => $l !== '' && $l[0] !== '#'));
}

/**
 * Return true when an address belongs to a Cloudflare proxy network.
 *
 * Client-controlled headers are accepted only when REMOTE_ADDR is one of
 * Cloudflare's published proxy ranges. This prevents direct-origin callers
 * from spoofing CF-Connecting-IP/X-Forwarded-For to bypass rate limits.
 * Keep this list synchronized with https://www.cloudflare.com/ips/.
 */
function kssmi_ip_in_cidr(string $ip, string $cidr): bool {
    [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
    if ($network === null || $prefix === null) return false;

    $ipBinary = @inet_pton($ip);
    $networkBinary = @inet_pton($network);
    $prefixLength = (int)$prefix;
    if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
        return false;
    }
    $maxBits = strlen($ipBinary) * 8;
    if ($prefixLength < 0 || $prefixLength > $maxBits) return false;

    $fullBytes = intdiv($prefixLength, 8);
    if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
        return false;
    }
    $remainingBits = $prefixLength % 8;
    if ($remainingBits === 0) return true;

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
}

function kssmi_is_cloudflare_proxy(string $ip): bool {
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
    foreach ($ranges as $range) {
        if (kssmi_ip_in_cidr($ip, $range)) return true;
    }
    return false;
}

/**
 * Resolve the client IP without trusting spoofable headers on direct-origin requests.
 */
function kssmi_get_client_ip(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) && kssmi_is_cloudflare_proxy($remote)) {
        $cloudflareIp = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($cloudflareIp, FILTER_VALIDATE_IP)) return $cloudflareIp;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

/**
 * Returns true if the request is within the quota; false if rate-limited.
 *
 * @param string $key           Endpoint identifier, e.g. 'send-mail', 'admin-login'
 * @param int    $maxRequests   Max requests allowed in the window
 * @param int    $windowSeconds Sliding window length in seconds
 */
function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): bool {
    $ip = kssmi_get_client_ip();

    // Whitelist bypass — allow the admin's own IP through always
    if (in_array($ip, kssmi_load_rate_limit_whitelist(), true)) {
        return true;
    }

    $cacheKey = "rl:{$key}:" . md5($ip);

    // Preferred: APCu in-memory cache (very fast, shared across PHP-FPM workers)
    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($cacheKey, $success);
        if (!$success) {
            // First request in this window — initialize
            apcu_store($cacheKey, 1, $windowSeconds);
            return true;
        }
        if ($count >= $maxRequests) {
            return false;
        }
        apcu_inc($cacheKey);
        return true;
    }

    // Fallback: file-based counter, slower but works without APCu
    return checkRateLimitFile($key, $ip, $maxRequests, $windowSeconds);
}

/**
 * File-based rate limiter — used when APCu is unavailable.
 * Stores counter files in /home/kssmi.com/rate_limit/ (outside public_html).
 */
function checkRateLimitFile(string $key, string $ip, int $max, int $window): bool {
    // Whitelist bypass — also applies to the file-fallback path
    if (in_array($ip, kssmi_load_rate_limit_whitelist(), true)) {
        return true;
    }

    // This file lives at /home/kssmi.com/private/rate-limit.php
    // dirname(__DIR__) = /home/kssmi.com/
    $dir = dirname(__DIR__) . '/rate_limit';

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $file = $dir . '/' . md5("{$key}:{$ip}") . '.json';
    $now  = time();

    // Load existing timestamps, filter out expired ones
    $data = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    $data = array_values(array_filter($data, fn($t) => is_int($t) && $t > $now - $window));

    if (count($data) >= $max) {
        return false;
    }

    // Record this request
    $data[] = $now;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}
