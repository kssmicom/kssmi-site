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