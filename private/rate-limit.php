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

function kssmi_ip_in_cidr(string $ip, string $cidr): bool {
    [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
    if ($network === null || $prefix === null || !preg_match('/^[0-9]{1,3}$/', $prefix)) {
        return false;
    }

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

function kssmi_rate_limit_has_exact_keys(array $value, array $expected): bool {
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    return $actual === $expected;
}

function kssmi_cloudflare_snapshot_path(): string {
    if (PHP_SAPI === 'cli') {
        $override = getenv('KSSMI_CLOUDFLARE_RANGES_FILE');
        if (is_string($override) && $override !== '') return $override;
    }
    return __DIR__ . '/cloudflare-ip-ranges.json';
}

function kssmi_cloudflare_snapshot_max_age_seconds(): int {
    return 45 * 86400;
}

function kssmi_cloudflare_cidr_valid(string $cidr, int $expectedFamily): bool {
    if (!preg_match('/^([^\/\s]+)\/([0-9]{1,3})$/', $cidr, $match)) return false;

    $address = $match[1];
    $prefix = (int)$match[2];
    $addressBinary = @inet_pton($address);
    if ($addressBinary === false) return false;

    $family = strlen($addressBinary) === 4 ? 4 : (strlen($addressBinary) === 16 ? 6 : 0);
    $maximumPrefix = $family === 4 ? 32 : 128;
    if ($family !== $expectedFamily || $prefix < 0 || $prefix > $maximumPrefix) return false;

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($remainingBits > 0) {
        $hostMask = (1 << (8 - $remainingBits)) - 1;
        if ((ord($addressBinary[$fullBytes]) & $hostMask) !== 0) return false;
        $fullBytes++;
    }
    for ($index = $fullBytes, $length = strlen($addressBinary); $index < $length; $index++) {
        if (ord($addressBinary[$index]) !== 0) return false;
    }
    return true;
}

/**
 * Strictly load a locally verified Cloudflare proxy-range snapshot.
 *
 * Returning null is intentional fail-closed behaviour: forwarded client-IP
 * headers are ignored whenever the snapshot is missing, malformed, stale or
 * otherwise outside the format validated by the offline update tool.
 */
function kssmi_cloudflare_snapshot_load(string $path, ?int $now = null): ?array {
    clearstatcache(true, $path);
    if (!is_file($path) || !is_readable($path)) return null;
    $size = @filesize($path);
    if (!is_int($size) || $size < 1 || $size > 65536) return null;

    $contents = @file_get_contents($path);
    if (!is_string($contents) || strlen($contents) !== $size) return null;
    try {
        $snapshot = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $_error) {
        return null;
    }
    if (!is_array($snapshot) || kssmi_rate_limit_array_is_list($snapshot)) return null;
    if (!kssmi_rate_limit_has_exact_keys(
        $snapshot,
        ['schema_version', 'verified_at', 'sources', 'ipv4', 'ipv6']
    )) return null;
    if (($snapshot['schema_version'] ?? null) !== 1) return null;

    $sources = $snapshot['sources'] ?? null;
    if (!is_array($sources) || kssmi_rate_limit_array_is_list($sources)) return null;
    if (!kssmi_rate_limit_has_exact_keys($sources, ['ipv4', 'ipv6'])) return null;
    if (
        ($sources['ipv4'] ?? null) !== 'https://www.cloudflare.com/ips-v4/' ||
        ($sources['ipv6'] ?? null) !== 'https://www.cloudflare.com/ips-v6/'
    ) return null;

    $verifiedAtText = $snapshot['verified_at'] ?? null;
    if (!is_string($verifiedAtText) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $verifiedAtText)) {
        return null;
    }
    $verifiedAt = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $verifiedAtText,
        new DateTimeZone('UTC')
    );
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($verifiedAt === false ||
        ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
        $verifiedAt->format('Y-m-d\TH:i:s\Z') !== $verifiedAtText) {
        return null;
    }
    $currentTime = $now ?? time();
    $verifiedTimestamp = $verifiedAt->getTimestamp();
    if ($verifiedTimestamp > $currentTime + 300 ||
        $currentTime - $verifiedTimestamp > kssmi_cloudflare_snapshot_max_age_seconds()) {
        return null;
    }

    $allRanges = [];
    foreach ([['ipv4', 4, 10], ['ipv6', 6, 5]] as [$key, $family, $minimum]) {
        $ranges = $snapshot[$key] ?? null;
        if (!kssmi_rate_limit_array_is_list($ranges) || count($ranges) < $minimum || count($ranges) > 100) {
            return null;
        }
        $seen = [];
        foreach ($ranges as $cidr) {
            if (!is_string($cidr) || !kssmi_cloudflare_cidr_valid($cidr, $family) || isset($seen[$cidr])) {
                return null;
            }
            $seen[$cidr] = true;
            $allRanges[] = $cidr;
        }
    }
    return $allRanges;
}

function kssmi_cloudflare_ranges(): ?array {
    static $cachedKey = null;
    static $cachedRanges = null;
    static $loggedFailures = [];

    $path = kssmi_cloudflare_snapshot_path();
    clearstatcache(true, $path);
    $mtime = @filemtime($path);
    $size = @filesize($path);
    $inode = @fileinode($path);
    $cacheKey = $path . '|' . (string)$inode . '|' . (string)$mtime . '|' .
        (string)$size . '|' . intdiv(time(), 3600);
    if ($cachedKey !== $cacheKey) {
        $cachedKey = $cacheKey;
        $cachedRanges = kssmi_cloudflare_snapshot_load($path);
    }
    if ($cachedRanges === null && !isset($loggedFailures[$cacheKey])) {
        if (count($loggedFailures) >= 32) $loggedFailures = [];
        $loggedFailures[$cacheKey] = true;
        error_log('KSSMI security: Cloudflare range snapshot invalid; forwarded client IP headers disabled.');
    }
    return $cachedRanges;
}

/**
 * Return true when an address belongs to a currently verified Cloudflare
 * proxy network. No embedded fallback is used: invalid snapshot = no trust.
 */
function kssmi_is_cloudflare_proxy(string $ip): bool {
    $ranges = kssmi_cloudflare_ranges();
    if ($ranges === null) return false;
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
        $cloudflareIp = kssmi_get_trusted_cloudflare_header('HTTP_CF_CONNECTING_IP');
        if (filter_var($cloudflareIp, FILTER_VALIDATE_IP)) return $cloudflareIp;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

/**
 * Return a Cloudflare-added request header only after authenticating the TCP
 * peer against the pinned Cloudflare CIDR snapshot. Direct-origin clients must
 * never be able to influence CF-* metadata.
 */
function kssmi_get_trusted_cloudflare_header(string $serverKey): ?string {
    if (preg_match('/^HTTP_CF_[A-Z0-9_]+$/D', $serverKey) !== 1) return null;
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remote, FILTER_VALIDATE_IP) === false || !kssmi_is_cloudflare_proxy($remote)) {
        return null;
    }
    $value = $_SERVER[$serverKey] ?? null;
    if (!is_string($value)) return null;
    $value = trim($value);
    return $value === '' ? null : $value;
}

/**
 * Cloudflare country metadata is an ISO-3166 alpha-2 code. Return null for
 * an untrusted peer or any malformed value so callers fail closed to UNKNOWN.
 */
function kssmi_get_trusted_cloudflare_country(): ?string {
    $country = kssmi_get_trusted_cloudflare_header('HTTP_CF_IPCOUNTRY');
    if ($country === null || preg_match('/^[A-Z]{2}$/D', $country) !== 1) return null;
    return $country;
}

/**
 * Returns true if the request is within the quota; false if rate-limited.
 *
 * @param string $key           Endpoint identifier, e.g. 'send-mail', 'admin-login'
 * @param int    $maxRequests   Max requests allowed in the window
 * @param int    $windowSeconds Sliding window length in seconds
 */
function checkRateLimit(string $key, int $maxRequests, int $windowSeconds): bool {
    return checkRateLimitCost($key, $maxRequests, $windowSeconds, 1);
}

/**
 * Consume a bounded number of quota units atomically where the backing store
 * permits it. This lets ingest endpoints charge for the durable work a request
 * can cause, rather than treating an empty request and a multi-row write alike.
 */
function checkRateLimitCost(string $key, int $maxRequests, int $windowSeconds, int $cost): bool {
    $maxRequests = max(1, $maxRequests);
    $windowSeconds = max(1, $windowSeconds);
    if ($cost < 1 || $cost > $maxRequests) return false;
    $ip = kssmi_get_client_ip();

    // Whitelist bypass — allow the admin's own IP through always
    if (in_array($ip, kssmi_load_rate_limit_whitelist(), true)) {
        return true;
    }

    return kssmi_check_rate_limit_identity($key, $ip, $maxRequests, $windowSeconds, $cost);
}

/**
 * Enforce a process-wide quota for work that must be bounded even when an
 * attacker distributes requests across source IPs. Unlike the per-IP helper,
 * this deliberately has no whitelist bypass.
 */
function checkRateLimitGlobal(string $key, int $maxRequests, int $windowSeconds): bool {
    return kssmi_check_rate_limit_identity($key, 'global', $maxRequests, $windowSeconds, 1);
}

/**
 * Consume a quota for a supplied server-derived identity. The public helpers
 * select either the trusted client IP or the fixed global identity above.
 */
function kssmi_check_rate_limit_identity(
    string $key,
    string $identity,
    int $maxRequests,
    int $windowSeconds,
    int $cost
): bool {
    $maxRequests = max(1, $maxRequests);
    $windowSeconds = max(1, $windowSeconds);
    if ($cost < 1 || $cost > $maxRequests) return false;

    $cacheKey = "rl:{$key}:" . md5($identity);

    // Preferred: APCu in-memory cache (very fast, shared across PHP-FPM workers)
    $forceFileFallback =
        PHP_SAPI === 'cli' &&
        getenv('KSSMI_FORCE_FILE_RATE_LIMIT') === '1';
    $apcuAvailable =
        function_exists('apcu_add') &&
        function_exists('apcu_inc') &&
        (!function_exists('apcu_enabled') || apcu_enabled());
    if (!$forceFileFallback && $apcuAvailable) {
        if (apcu_add($cacheKey, $cost, $windowSeconds)) {
            return true;
        }

        // CAS avoids charging a denied request. The common APCu extension
        // supports it; retain the increment fallback for older deployments.
        if (function_exists('apcu_cas')) {
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $current = apcu_fetch($cacheKey, $found);
                if (!$found) return apcu_add($cacheKey, $cost, $windowSeconds);
                if (!is_int($current)) return false;
                if ($current > $maxRequests - $cost) return false;
                if (apcu_cas($cacheKey, $current, $current + $cost)) return true;
            }
            return false;
        }

        $count = apcu_inc($cacheKey, $cost, $success);
        if (!$success) {
            // The entry can expire between add() and inc(). Retry one atomic
            // initialization; fail closed only if the cache remains unusable.
            return apcu_add($cacheKey, $cost, $windowSeconds);
        }
        return $count <= $maxRequests;
    }

    // Fallback: file-based counter, slower but works without APCu
    return checkRateLimitFile($key, $identity, $maxRequests, $windowSeconds, $cost, false);
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

function checkRateLimitFile(
    string $key,
    string $ip,
    int $max,
    int $window,
    int $cost = 1,
    bool $allowWhitelistBypass = true
): bool {
    // Whitelist bypass — also applies to the file-fallback path
    if ($allowWhitelistBypass && in_array($ip, kssmi_load_rate_limit_whitelist(), true)) {
        return true;
    }

    $configuredDir =
        PHP_SAPI === 'cli' ? trim((string)getenv('KSSMI_RATE_LIMIT_DIR')) : '';
    $dir = $configuredDir !== '' ? $configuredDir : dirname(__DIR__) . '/rate_limit';

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    }
    @chmod($dir, 0750);

    $max = max(1, $max);
    $window = max(1, $window);
    if ($cost < 1 || $cost > $max) return false;

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
        $allowed = count($requests) <= $max - $cost;
        if ($allowed) {
            if (!isset($bucket[$identity]) && count($bucket) >= $maxEntriesPerBucket) {
                $allowed = false;
            } else {
                for ($unit = 0; $unit < $cost; $unit++) {
                    $requests[] = $now + $window;
                }
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
