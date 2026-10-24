<?php
declare(strict_types=1);

/**
 * Live Cloudflare snapshot tests.
 *
 * The site prefers rate_limit/cloudflare-ip-ranges.live.json, refreshed weekly
 * on the server by scripts/refresh-cloudflare-ranges.php, and falls back to the
 * bundled snapshot. Both pass the same strict loader and the 45-day limit.
 *
 * Run: php scripts/test-cloudflare-live-ranges.php
 */

function kssmi_live_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_live_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        is_dir($path) ? kssmi_live_remove_tree($path) : @unlink($path);
    }
    @rmdir($directory);
}

$repo = dirname(__DIR__);
$tree = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-live-ranges-' . bin2hex(random_bytes(6));
kssmi_live_assert(mkdir($tree . '/sources', 0700, true), 'create test tree');
$bundledPath = $tree . '/bundled.json';
$livePath = $tree . '/live.json';

$official = json_decode((string)file_get_contents($repo . '/private/cloudflare-ip-ranges.json'), true);
kssmi_live_assert(is_array($official), 'read the repository snapshot');
$writeSnapshot = static function (string $path, int $verifiedAt, array $extraV4 = []) use ($official): void {
    $snapshot = $official;
    $snapshot['verified_at'] = gmdate('Y-m-d\TH:i:s\Z', $verifiedAt);
    $snapshot['ipv4'] = array_merge($official['ipv4'], $extraV4);
    file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    touch($path, time() - random_int(1, 1000));
};
$marker = '198.51.100.0/24';
$markerIp = '198.51.100.7';

putenv('KSSMI_CLOUDFLARE_RANGES_FILE=' . $bundledPath);
putenv('KSSMI_CLOUDFLARE_LIVE_RANGES_FILE=' . $livePath);

try {
    require_once $repo . '/private/rate-limit.php';
    $now = time();

    // ── selection ──
    kssmi_live_assert(kssmi_cloudflare_live_snapshot_path() === $livePath, 'the live override must be honoured');
    $writeSnapshot($bundledPath, $now - 3600);
    kssmi_live_assert(kssmi_is_cloudflare_proxy('173.245.48.1') && !kssmi_is_cloudflare_proxy($markerIp), 'without a live file the bundled snapshot must be used');

    $writeSnapshot($livePath, $now - 60, [$marker]);
    kssmi_live_assert(kssmi_is_cloudflare_proxy($markerIp), 'a fresh live snapshot must win over the bundled one');

    $writeSnapshot($bundledPath, $now - 60 * 86400);
    kssmi_live_assert(kssmi_is_cloudflare_proxy($markerIp) && kssmi_is_cloudflare_proxy('173.245.48.1'), 'a fresh live snapshot must keep the site working when the bundled copy is stale');

    $writeSnapshot($livePath, $now - 46 * 86400, [$marker]);
    $writeSnapshot($bundledPath, $now - 3600);
    kssmi_live_assert(!kssmi_is_cloudflare_proxy($markerIp) && kssmi_is_cloudflare_proxy('173.245.48.1'), 'a stale live snapshot must fall back to the bundled one');

    file_put_contents($livePath, '{"schema_version":1');
    kssmi_live_assert(kssmi_is_cloudflare_proxy('173.245.48.1'), 'a corrupt live snapshot must fall back to the bundled one');

    $writeSnapshot($livePath, $now - 50 * 86400);
    $writeSnapshot($bundledPath, $now - 50 * 86400);
    kssmi_live_assert(kssmi_cloudflare_ranges() === null && !kssmi_is_cloudflare_proxy('173.245.48.1'), 'two stale snapshots must fail closed');

    putenv('KSSMI_CLOUDFLARE_LIVE_RANGES_FILE');
    kssmi_live_assert(kssmi_cloudflare_live_snapshot_path() === null, 'tests that pin the bundled file must not read a live file');
    putenv('KSSMI_CLOUDFLARE_LIVE_RANGES_FILE=' . $livePath);

    // ── refresh script ──
    $runRefresh = static function () use ($repo, $tree): array {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false && extension_loaded('curl')) {
            $command[] = '-d'; $command[] = 'extension_dir=' . (string)ini_get('extension_dir');
            $command[] = '-d'; $command[] = 'extension=curl';
        }
        $command[] = $repo . '/scripts/refresh-cloudflare-ranges.php';
        $environment = getenv();
        $environment['KSSMI_CLOUDFLARE_SOURCE_DIR'] = $tree . '/sources';
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tree, $environment);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string)$out, (string)$err];
    };
    $writeSources = static function (array $v4, array $v6) use ($tree): void {
        file_put_contents($tree . '/sources/ips-v4', implode("\n", $v4));
        file_put_contents($tree . '/sources/ips-v6', implode("\r\n", $v6) . "\r\n");
    };

    @unlink($livePath);
    $writeSnapshot($bundledPath, $now - 40 * 86400);
    $writeSources($official['ipv4'], $official['ipv6']);
    [$code, $out, $err] = $runRefresh();
    kssmi_live_assert($code === 0, "refresh must succeed: {$err}");
    kssmi_live_assert(str_contains($out, 'same ranges as the bundled snapshot') && $err === '', 'unchanged ranges must be reported as unchanged');
    $live = json_decode((string)file_get_contents($livePath), true);
    kssmi_live_assert(
        $live['ipv4'] === $official['ipv4'] && $live['ipv6'] === $official['ipv6']
            && abs(strtotime($live['verified_at']) - time()) < 120,
        'the live snapshot must hold the downloaded ranges, verified now'
    );
    kssmi_live_assert(kssmi_cloudflare_snapshot_load($livePath) !== null, 'the written live snapshot must pass the strict loader');
    if (DIRECTORY_SEPARATOR === '/') {
        kssmi_live_assert((fileperms($livePath) & 0777) === 0600, 'the live snapshot must be 0600');
    }

    $writeSources(array_merge($official['ipv4'], [$marker]), $official['ipv6']);
    [$code, $out, $err] = $runRefresh();
    kssmi_live_assert($code === 0 && str_contains($err, '+' . $marker) && str_contains($out, 'ranges changed'), 'a range change must be applied and reported on stderr');

    $before = (string)file_get_contents($livePath);
    foreach ([
        'an HTML error page' => ["<html><body>error</body></html>", implode("\n", $official['ipv6'])],
        'a too-short list' => ["173.245.48.0/20\n103.21.244.0/22", implode("\n", $official['ipv6'])],
        'a non-canonical range' => [implode("\n", array_merge($official['ipv4'], ['10.0.0.1/8'])), implode("\n", $official['ipv6'])],
        'an empty IPv6 list' => [implode("\n", $official['ipv4']), ''],
    ] as $label => [$v4, $v6]) {
        file_put_contents($tree . '/sources/ips-v4', $v4);
        file_put_contents($tree . '/sources/ips-v6', $v6);
        [$code, , $err] = $runRefresh();
        kssmi_live_assert($code === 1 && str_contains($err, 'refresh failed'), "refresh must reject {$label}");
        kssmi_live_assert((string)file_get_contents($livePath) === $before, "{$label} must leave the previous live snapshot in place");
    }
    kssmi_live_assert(glob($livePath . '.tmp-*') === [], 'no temporary files may remain');

    fwrite(STDOUT, "Cloudflare live range tests passed.\n");
} finally {
    putenv('KSSMI_CLOUDFLARE_RANGES_FILE');
    putenv('KSSMI_CLOUDFLARE_LIVE_RANGES_FILE');
    kssmi_live_remove_tree($tree);
}
