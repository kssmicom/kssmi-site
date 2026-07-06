<?php
/**
 * KSSMI Rate Limiter — shared by send-mail.php / email-logs.php / visitor-journey.php
 *                          / track-pageview.php / track-submission.php
 *
 * Location: public/api/rate-limit.php (webroot内, blocked by .htaccess)
 * Block rule: `RewriteRule ^api/rate-limit\.php$ - [F,L]` in public/.htaccess
 *
 * Storage:
 *   - Preferred: APCu (in-memory, fast). Check `apcu_fetch` exists.
 *   - Fallback:  File-based, stored at /home/kssmi.com/rate_limit/ (webroot外)
 *
 * Key:    "rl:<endpoint>:<md5(ip)>"
 * Per-IP, per-endpoint quotas — different endpoints don't share quotas.
 *
 * Usage:
 *   require_once __DIR__ . '/rate-limit.php';   // from public/api/
 *   require_once dirname(__DIR__) . '/api/rate-limit.php';  // from public/
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
 * Returns true if the request is within the quota; false if rate-limited.
 *
 * @param string $key           Endpoint identifier, e.g. 'send-mail', 'admin-login'
 * @param int    $maxRequests   Max requests allowed in the window
 * @param int    $windowSeconds Sliding window length in seconds
 */
function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): bool {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? 'unknown';

    // Take only the first IP if X-Forwarded-For is a chain
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

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

    // __DIR__ here is /home/kssmi.com/public_html/api
    // dirname(__DIR__, 2) jumps 2 levels up to /home/kssmi.com/
    $dir = dirname(__DIR__, 2) . '/rate_limit';

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