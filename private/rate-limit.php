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
 *   - Fallback:  File-based, stored in a fixed set of hash buckets at
 *                /home/kssmi.com/rate_limit/ (outside the webroot)
 *
 * Key:    "rl:<endpoint>:<sha256(ip)>"
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

function kssmi_rate_limit_array_is_list($value): bool {
    if (!is_array($value)) return false;
    if (function_exists('array_is_list')) return array_is_list($value);

    $expectedKey = 0;
    foreach ($value as $key => $_value) {
        if ($key !== $expectedKey) return false;
        $expectedKey++;
    }
    return true;
}

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
    $forceFileFallback =
        PHP_SAPI === 'cli' &&
        getenv('KSSMI_FORCE_FILE_RATE_LIMIT') === '1';
    $apcuAvailable =
        function_exists('apcu_add') &&
        function_exists('apcu_inc') &&
        (!function_exists('apcu_enabled') || apcu_enabled());
    if (!$forceFileFallback && $apcuAvailable) {
        if (apcu_add($cacheKey, 1, $windowSeconds)) {
            return true;
        }

        $count = apcu_inc($cacheKey, 1, $success);
        if (!$success) {
            // The entry can expire between add() and inc(). Retry one atomic
            // initialization; fail closed only if the cache remains unusable.
            return apcu_add($cacheKey, 1, $windowSeconds);
        }
        return $count <= $maxRequests;
    }

    // Fallback: file-based counter, slower but works without APCu
    return checkRateLimitFile($key, $ip, $maxRequests, $windowSeconds);
}

/**
 * File-based rate limiter — used when APCu is unavailable.
 * Uses 256 bucket files rather than one permanent file per IP. Each bucket is
 * protected by a stable sidecar lock and atomically replaced. This prevents a
 * killed PHP worker from leaving a partially truncated bucket that would reset
 * the quota. Each bucket is capped, bounding inode and memory/disk growth.
 */
function kssmi_rate_limit_cleanup_temp_files(string $file, int $maxAgeSeconds = 3600): void {
    $cutoff = time() - max(300, $maxAgeSeconds);
    foreach (glob($file . '.tmp-*') ?: [] as $tempPath) {
        $mtime = @filemtime($tempPath);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($tempPath);
        }
    }
}

function kssmi_rate_limit_atomic_write(string $file, array $bucket): bool {
    // An empty bucket is a JSON object, not a list. The object form lets the
    // reader distinguish valid empty state from an invalid top-level array.
    $encoded = json_encode((object)$bucket);
    if ($encoded === false) return false;

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $suffix = str_replace('.', '', uniqid('', true));
    }

    $tempPath = $file . '.tmp-' . $suffix;
    $handle = @fopen($tempPath, 'x+b');
    if ($handle === false) return false;

    // Apply private permissions before any client identity is written.
    if (!@chmod($tempPath, 0600)) {
        fclose($handle);
        @unlink($tempPath);
        return false;
    }

    $length = strlen($encoded);
    $offset = 0;
    $writtenAll = true;
    while ($offset < $length) {
        $written = fwrite($handle, substr($encoded, $offset));
        if ($written === false || $written === 0) {
            $writtenAll = false;
            break;
        }
        $offset += $written;
    }

    if ($writtenAll) $writtenAll = fflush($handle);
    if ($writtenAll && function_exists('fsync')) $writtenAll = @fsync($handle);
    fclose($handle);

    if (!$writtenAll || !@rename($tempPath, $file)) {
        @unlink($tempPath);
        return false;
    }

    @chmod($file, 0600);
    return true;
}

function kssmi_rate_limit_decode_bucket(string $raw, ?array &$bucket): bool {
    if (trim($raw) === '') {
        $bucket = null;
        return false;
    }

    $object = json_decode($raw);
    if (!is_object($object) || json_last_error() !== JSON_ERROR_NONE) {
        $bucket = null;
        return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $bucket = null;
        return false;
    }

    foreach ($decoded as $identity => $expiries) {
        if (
            !is_string($identity) ||
            preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1 ||
            !is_array($expiries) ||
            !kssmi_rate_limit_array_is_list($expiries)
        ) {
            $bucket = null;
            return false;
        }
        foreach ($expiries as $expiry) {
            if (!is_int($expiry)) {
                $bucket = null;
                return false;
            }
        }
    }

    $bucket = $decoded;
    return true;
}

function checkRateLimitFile(string $key, string $ip, int $max, int $window): bool {
    // Whitelist bypass — also applies to the file-fallback path
    if (in_array($ip, kssmi_load_rate_limit_whitelist(), true)) {
        return true;
    }

    $configuredDir =
        PHP_SAPI === 'cli' ? trim((string)getenv('KSSMI_RATE_LIMIT_DIR')) : '';
    $dir = $configuredDir !== '' ? $configuredDir : dirname(__DIR__) . '/rate_limit';

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    }
    @chmod($dir, 0750);

    $identity = hash('sha256', "{$key}:{$ip}");
    $file = $dir . '/bucket-' . substr($identity, 0, 2) . '.json';
    $lockFile = $file . '.lock';
    $now = time();
    $maxEntriesPerBucket = 512;

    $lockHandle = @fopen($lockFile, 'c+b');
    if ($lockHandle === false) return false;
    if (!@chmod($lockFile, 0600)) {
        fclose($lockHandle);
        return false;
    }

    if (!flock($lockHandle, LOCK_EX)) {
        fclose($lockHandle);
        return false;
    }

    try {
        kssmi_rate_limit_cleanup_temp_files($file);
        // Missing storage is a legitimate first-use state. An existing
        // zero-byte file is evidence of truncation/corruption and must not be
        // confused with it, otherwise the entire bucket quota resets.
        $fileExists = file_exists($file);
        $raw = $fileExists ? @file_get_contents($file) : '{}';
        if (!is_string($raw)) return false;

        $bucket = null;
        if (!kssmi_rate_limit_decode_bucket($raw, $bucket)) {
            // Never reset a quota because storage is corrupt or partially
            // written. Preserve the evidence and fail closed for this bucket.
            static $reportedCorruptBuckets = [];
            if (!isset($reportedCorruptBuckets[$file])) {
                error_log('KSSMI Rate Limit: invalid bucket preserved at ' . $file);
                $reportedCorruptBuckets[$file] = true;
            }
            return false;
        }

        // Entries store absolute expiry times, so different endpoint windows
        // can safely coexist in the same bucket.
        $dirty = false;
        foreach ($bucket as $entryKey => $expiries) {
            $active = array_values(array_filter(
                $expiries,
                fn($expiry) => is_int($expiry) && $expiry > $now
            ));
            if ($active === []) {
                unset($bucket[$entryKey]);
                $dirty = true;
            } else {
                if ($active !== $expiries) $dirty = true;
                $bucket[$entryKey] = $active;
            }
        }

        $requests = $bucket[$identity] ?? [];
        $allowed = count($requests) < max(1, $max);
        if ($allowed) {
            if (!isset($bucket[$identity]) && count($bucket) >= $maxEntriesPerBucket) {
                $allowed = false;
            } else {
                $requests[] = $now + max(1, $window);
                $bucket[$identity] = $requests;
                $dirty = true;
            }
        }

        // A denied request with no expired entries does not change state and
        // therefore avoids an unnecessary disk rewrite and fsync.
        if ($dirty && !kssmi_rate_limit_atomic_write($file, $bucket)) return false;

        return $allowed;
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
