<?php
declare(strict_types=1);

/**
 * Refresh the live Cloudflare range snapshot on the server.
 *
 * Downloads Cloudflare's published ranges, builds a snapshot in the same
 * schema as private/cloudflare-ip-ranges.json, proves it with the same strict
 * PHP loader the site uses, and atomically replaces
 * rate_limit/cloudflare-ip-ranges.live.json. A failed download or an invalid
 * list leaves the previous live file untouched (the bundled snapshot remains
 * the fallback). Run it as the site account, never root, e.g. weekly:
 *
 *   php "$(dirname "$(readlink -f /home/kssmi.com/public_html)")/scripts/refresh-cloudflare-ranges.php"
 *
 * Prints one summary line; a change in the ranges is reported on stderr so it
 * reaches the cron log, and the monthly workflow opens a PR for the repo copy.
 *
 * Tests: KSSMI_CLOUDFLARE_SOURCE_DIR (files ips-v4, ips-v6) replaces the
 * download, and KSSMI_CLOUDFLARE_LIVE_RANGES_FILE / KSSMI_CLOUDFLARE_RANGES_FILE
 * choose the files.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/private/rate-limit.php';

const KSSMI_CLOUDFLARE_SOURCES = [
    'ipv4' => 'https://www.cloudflare.com/ips-v4/',
    'ipv6' => 'https://www.cloudflare.com/ips-v6/',
];

function kssmi_refresh_fail(string $message): void {
    fwrite(STDERR, "Cloudflare range refresh failed: {$message}\n");
    exit(1);
}

function kssmi_refresh_download(string $family): ?string {
    $sourceDir = getenv('KSSMI_CLOUDFLARE_SOURCE_DIR');
    if (is_string($sourceDir) && $sourceDir !== '') {
        $body = @file_get_contents($sourceDir . '/ips-v' . ($family === 'ipv4' ? '4' : '6'));
        return is_string($body) ? $body : null;
    }
    if (!function_exists('curl_init')) return null;
    $curl = curl_init(KSSMI_CLOUDFLARE_SOURCES[$family]);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $finalHost = parse_url((string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
    curl_close($curl);
    if (!is_string($body) || $status !== 200 || $finalHost !== 'www.cloudflare.com') return null;
    return $body;
}

/** @return list<string>|null */
function kssmi_refresh_parse(string $body): ?array {
    if (strlen($body) > 64 * 1024) return null;
    $ranges = [];
    foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') $ranges[] = $line;
    }
    return $ranges;
}

/** @return array{ipv4: list<string>, ipv6: list<string>}|null */
function kssmi_refresh_read_ranges(?string $path): ?array {
    if ($path === null) return null;
    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['ipv4'] ?? null) || !is_array($decoded['ipv6'] ?? null)) return null;
    return ['ipv4' => $decoded['ipv4'], 'ipv6' => $decoded['ipv6']];
}

$ranges = [];
foreach (['ipv4', 'ipv6'] as $family) {
    $body = kssmi_refresh_download($family);
    $parsed = $body === null ? null : kssmi_refresh_parse($body);
    if ($parsed === null) kssmi_refresh_fail("could not download the {$family} list");
    $ranges[$family] = $parsed;
}

$snapshot = [
    'schema_version' => 1,
    'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'sources' => KSSMI_CLOUDFLARE_SOURCES,
    'ipv4' => $ranges['ipv4'],
    'ipv6' => $ranges['ipv6'],
];
$encoded = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$livePath = kssmi_cloudflare_live_snapshot_path();
if ($livePath === null) kssmi_refresh_fail('no live snapshot path is configured');
$directory = dirname($livePath);
if (!is_dir($directory) || !is_writable($directory)) kssmi_refresh_fail("{$directory} is not writable");

$temporary = $livePath . '.tmp-' . bin2hex(random_bytes(6));
$handle = @fopen($temporary, 'xb');
if ($handle === false) kssmi_refresh_fail('cannot create a temporary file');
$written = @chmod($temporary, 0600) && fwrite($handle, $encoded) === strlen($encoded) && fflush($handle);
if ($written && function_exists('fsync')) $written = @fsync($handle);
fclose($handle);

// Prove the new file with the site's own strict loader before it goes live.
if (!$written || kssmi_cloudflare_snapshot_load($temporary) === null) {
    @unlink($temporary);
    kssmi_refresh_fail('the downloaded lists did not form a valid snapshot');
}
if (!@rename($temporary, $livePath)) {
    @unlink($temporary);
    kssmi_refresh_fail('cannot replace the live snapshot');
}
@chmod($livePath, 0600);

$bundled = kssmi_refresh_read_ranges(kssmi_cloudflare_snapshot_path());
$changes = [];
foreach (['ipv4', 'ipv6'] as $family) {
    $now = $ranges[$family];
    $before = $bundled[$family] ?? [];
    foreach (array_diff($now, $before) as $cidr) $changes[] = "+{$cidr}";
    foreach (array_diff($before, $now) as $cidr) $changes[] = "-{$cidr}";
}
if ($changes !== []) {
    fwrite(STDERR, '[' . gmdate('c') . '] Cloudflare ranges differ from the bundled snapshot: ' . implode(' ', $changes) . "\n");
}
echo sprintf(
    "Live Cloudflare snapshot refreshed: %d IPv4, %d IPv6, verified %s, %s\n",
    count($ranges['ipv4']),
    count($ranges['ipv6']),
    $snapshot['verified_at'],
    $changes === [] ? 'same ranges as the bundled snapshot' : 'ranges changed'
);
