<?php
if (($argv[1] ?? '') === '--worker') {
    $workerDir = $argv[2] ?? '';
    $gatePath = $argv[3] ?? '';
    putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $workerDir);
    require_once dirname(__DIR__) . '/private/rate-limit.php';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.50';
    $deadline = microtime(true) + 10;
    while (!file_exists($gatePath) && microtime(true) < $deadline) {
        usleep(1000);
    }
    if (!file_exists($gatePath)) exit(2);
    echo checkRateLimit('parallel-endpoint', 3, 60) ? '1' : '0';
    exit(0);
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-rate-limit-' . bin2hex(random_bytes(8));
if (!mkdir($testRoot, 0700, true)) {
    fwrite(STDERR, "Rate limit test failed: unable to create temporary directory\n");
    exit(1);
}

putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
putenv('KSSMI_RATE_LIMIT_DIR=' . $testRoot);
$snapshotPath = $testRoot . DIRECTORY_SEPARATOR . 'cloudflare-ip-ranges.json';
$repositorySnapshot = dirname(__DIR__) . '/private/cloudflare-ip-ranges.json';
if (!copy($repositorySnapshot, $snapshotPath)) {
    fwrite(STDERR, "Rate limit test failed: unable to copy Cloudflare snapshot\n");
    exit(1);
}
putenv('KSSMI_CLOUDFLARE_RANGES_FILE=' . $snapshotPath);
require_once dirname(__DIR__) . '/private/rate-limit.php';

function rate_limit_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Rate limit test failed: {$message}\n");
        exit(1);
    }
}

function run_rate_limit_workers($testRoot, $workerCount) {
    $gatePath = $testRoot . DIRECTORY_SEPARATOR . '.worker-gate';
    $processes = [];
    for ($index = 0; $index < $workerCount; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--worker', $testRoot, $gatePath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        rate_limit_assert(is_resource($process), 'unable to start concurrent worker');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }

    touch($gatePath);
    $allowed = 0;
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        rate_limit_assert($exitCode === 0, "concurrent worker failed: {$stderr}");
        $allowed += trim($stdout) === '1' ? 1 : 0;
    }
    @unlink($gatePath);
    return $allowed;
}

function remove_rate_limit_test_tree($directory) {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_dir($path)) {
            remove_rate_limit_test_tree($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}

function write_cloudflare_snapshot_variant($testRoot, $name, $snapshot) {
    $path = $testRoot . DIRECTORY_SEPARATOR . $name . '.json';
    $encoded = is_string($snapshot)
        ? $snapshot
        : json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    rate_limit_assert(
        is_string($encoded) && file_put_contents($path, $encoded) !== false,
        "unable to write Cloudflare snapshot variant: {$name}"
    );
    return $path;
}

try {
    $validSnapshot = json_decode(file_get_contents($snapshotPath), true);
    rate_limit_assert(is_array($validSnapshot), 'repository Cloudflare snapshot is invalid JSON');
    rate_limit_assert(
        is_array(kssmi_cloudflare_snapshot_load($snapshotPath)),
        'valid Cloudflare snapshot was rejected'
    );

    // Direct-origin callers cannot turn client-controlled CF headers into a
    // trusted identity, while a verified Cloudflare peer can supply one.
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
    rate_limit_assert(
        kssmi_get_client_ip() === '203.0.113.10',
        'direct caller changed its identity with a forged Cloudflare header'
    );
    $_SERVER['REMOTE_ADDR'] = '173.245.48.1';
    rate_limit_assert(
        kssmi_get_client_ip() === '198.51.100.77',
        'trusted Cloudflare peer did not supply the forwarded client IP'
    );
    $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
    rate_limit_assert(
        kssmi_get_client_ip() === '173.245.48.1',
        'invalid forwarded IP did not fall back to REMOTE_ADDR'
    );
    rate_limit_assert(kssmi_is_cloudflare_proxy('2606:4700::1'), 'Cloudflare IPv6 range was rejected');
    rate_limit_assert(!kssmi_is_cloudflare_proxy('2001:db8::1'), 'unrelated IPv6 address was trusted');

    $invalidSnapshots = [];
    $invalidSnapshots['malformed'] = '{';
    $stale = $validSnapshot;
    $stale['verified_at'] = '2020-01-01T00:00:00Z';
    $invalidSnapshots['stale'] = $stale;
    $future = $validSnapshot;
    $future['verified_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    $invalidSnapshots['future'] = $future;
    $nonCanonical = $validSnapshot;
    $nonCanonical['ipv4'][0] = '173.245.48.1/20';
    $invalidSnapshots['noncanonical'] = $nonCanonical;
    $duplicate = $validSnapshot;
    $duplicate['ipv4'][1] = $duplicate['ipv4'][0];
    $invalidSnapshots['duplicate'] = $duplicate;
    $wrongSource = $validSnapshot;
    $wrongSource['sources']['ipv4'] = 'https://example.com/ips-v4/';
    $invalidSnapshots['nonofficial-source'] = $wrongSource;
    $unexpected = $validSnapshot;
    $unexpected['fallback_ranges'] = [];
    $invalidSnapshots['unexpected-field'] = $unexpected;

    foreach ($invalidSnapshots as $name => $variant) {
        $invalidPath = write_cloudflare_snapshot_variant($testRoot, 'cloudflare-' . $name, $variant);
        rate_limit_assert(
            kssmi_cloudflare_snapshot_load($invalidPath) === null,
            "invalid Cloudflare snapshot was accepted: {$name}"
        );
        putenv('KSSMI_CLOUDFLARE_RANGES_FILE=' . $invalidPath);
        $_SERVER['REMOTE_ADDR'] = '173.245.48.1';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.88';
        rate_limit_assert(
            kssmi_get_client_ip() === '173.245.48.1',
            "invalid snapshot trusted a forwarded header: {$name}"
        );
    }
    $missingPath = $testRoot . DIRECTORY_SEPARATOR . 'cloudflare-missing.json';
    putenv('KSSMI_CLOUDFLARE_RANGES_FILE=' . $missingPath);
    rate_limit_assert(
        kssmi_get_client_ip() === '173.245.48.1',
        'missing snapshot trusted a forwarded header'
    );
    putenv('KSSMI_CLOUDFLARE_RANGES_FILE=' . $snapshotPath);

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    unset($_SERVER['HTTP_CF_CONNECTING_IP']);
    rate_limit_assert(checkRateLimit('test-endpoint', 2, 60), 'first request rejected');
    rate_limit_assert(checkRateLimit('test-endpoint', 2, 60), 'second request rejected');
    rate_limit_assert(!checkRateLimit('test-endpoint', 2, 60), 'third request was not limited');

    $initialBuckets = glob($testRoot . DIRECTORY_SEPARATOR . 'bucket-*.json') ?: [];
    rate_limit_assert(count($initialBuckets) === 1, 'one identity created more than one bucket');
    if (DIRECTORY_SEPARATOR === '/') {
        rate_limit_assert(
            (fileperms($initialBuckets[0]) & 0777) === 0600,
            'bucket permissions are not 0600'
        );
        $initialLocks = glob($testRoot . DIRECTORY_SEPARATOR . 'bucket-*.json.lock') ?: [];
        rate_limit_assert(
            count($initialLocks) === 1 && (fileperms($initialLocks[0]) & 0777) === 0600,
            'bucket sidecar lock permissions are not 0600'
        );
    }

    // A denied request that has nothing to clean must not replace or rewrite
    // the bucket. This keeps abusive traffic from forcing a write + fsync.
    $oldMtime = time() - 120;
    rate_limit_assert(touch($initialBuckets[0], $oldMtime), 'unable to set bucket test mtime');
    clearstatcache(true, $initialBuckets[0]);
    rate_limit_assert(!checkRateLimit('test-endpoint', 2, 60), 'fourth request was not limited');
    clearstatcache(true, $initialBuckets[0]);
    rate_limit_assert(
        filemtime($initialBuckets[0]) === $oldMtime,
        'unchanged denied request rewrote the bucket'
    );

    rate_limit_assert(
        run_rate_limit_workers($testRoot, 8) === 3,
        'concurrent requests did not enforce the exact quota'
    );

    $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    $expiryIdentity = hash('sha256', 'expiry-test:203.0.113.99');
    $expiryBucket = $testRoot . DIRECTORY_SEPARATOR . 'bucket-' . substr($expiryIdentity, 0, 2) . '.json';
    file_put_contents($expiryBucket, json_encode([
        $expiryIdentity => [time() - 1],
        str_repeat('f', 64) => [time() - 1],
    ]));
    rate_limit_assert(checkRateLimit('expiry-test', 1, 60), 'expired request was not removed');
    $cleanedBucket = json_decode(file_get_contents($expiryBucket), true);
    rate_limit_assert(
        count($cleanedBucket) === 1 && isset($cleanedBucket[$expiryIdentity]),
        'expired bucket identities were not cleaned'
    );

    // Corrupt or partially written state must fail closed and remain untouched
    // for diagnosis. It must never reset an attacker's quota.
    $corruptDir = $testRoot . DIRECTORY_SEPARATOR . 'corrupt';
    rate_limit_assert(mkdir($corruptDir, 0700), 'unable to create corrupt-state test directory');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $corruptDir);
    $corruptIp = '203.0.113.200';
    $corruptIdentity = hash('sha256', 'corrupt-test:' . $corruptIp);
    $corruptBucket = $corruptDir . DIRECTORY_SEPARATOR .
        'bucket-' . substr($corruptIdentity, 0, 2) . '.json';
    $partialJson = '{"' . $corruptIdentity . '":[9999999999]';
    file_put_contents($corruptBucket, $partialJson);
    rate_limit_assert(
        !checkRateLimitFile('corrupt-test', $corruptIp, 2, 60),
        'corrupt bucket failed open'
    );
    rate_limit_assert(
        file_get_contents($corruptBucket) === $partialJson,
        'corrupt bucket was overwritten'
    );

    $zeroDir = $testRoot . DIRECTORY_SEPARATOR . 'zero-byte';
    rate_limit_assert(mkdir($zeroDir, 0700), 'unable to create zero-byte test directory');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $zeroDir);
    $zeroIp = '203.0.113.201';
    $zeroIdentity = hash('sha256', 'zero-test:' . $zeroIp);
    $zeroBucket = $zeroDir . DIRECTORY_SEPARATOR .
        'bucket-' . substr($zeroIdentity, 0, 2) . '.json';
    file_put_contents($zeroBucket, '');
    rate_limit_assert(
        !checkRateLimitFile('zero-test', $zeroIp, 2, 60),
        'existing zero-byte bucket reset the quota'
    );
    rate_limit_assert(
        file_get_contents($zeroBucket) === '',
        'existing zero-byte bucket was overwritten'
    );

    // Exercise the real 512-identity cap in one hash bucket. Existing
    // identities may consume their quota, while a 513th identity is rejected.
    $capDir = $testRoot . DIRECTORY_SEPARATOR . 'capacity';
    rate_limit_assert(mkdir($capDir, 0700), 'unable to create capacity test directory');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $capDir);
    $capPrefix = null;
    $capIdentities = [];
    $capIps = [];
    for ($index = 1; count($capIdentities) < 513; $index++) {
        $candidateIp = sprintf(
            '2001:db8:%x::%x',
            intdiv($index, 65536),
            $index % 65536
        );
        $candidateIdentity = hash('sha256', 'capacity-test:' . $candidateIp);
        if ($capPrefix === null) $capPrefix = substr($candidateIdentity, 0, 2);
        if (substr($candidateIdentity, 0, 2) !== $capPrefix) continue;
        $capIdentities[] = $candidateIdentity;
        $capIps[] = $candidateIp;
    }
    $preloaded = [];
    $future = time() + 600;
    for ($index = 0; $index < 512; $index++) {
        $preloaded[$capIdentities[$index]] = [$future];
    }
    $capBucket = $capDir . DIRECTORY_SEPARATOR . 'bucket-' . $capPrefix . '.json';
    file_put_contents($capBucket, json_encode((object)$preloaded));
    rate_limit_assert(
        checkRateLimitFile('capacity-test', $capIps[0], 2, 60),
        'existing identity was blocked at bucket capacity'
    );
    rate_limit_assert(
        !checkRateLimitFile('capacity-test', $capIps[512], 2, 60),
        '513th identity bypassed the bucket capacity'
    );
    $cappedBucket = json_decode(file_get_contents($capBucket), true);
    rate_limit_assert(
        is_array($cappedBucket) && count($cappedBucket) === 512,
        'bucket identity cap was not preserved'
    );

    // Many distinct IPs must remain bounded by the fixed 256-bucket design.
    putenv('KSSMI_RATE_LIMIT_DIR=' . $testRoot);
    for ($index = 1; $index <= 1024; $index++) {
        $third = ($index >> 8) & 255;
        $fourth = $index & 255;
        $_SERVER['REMOTE_ADDR'] = "198.51.{$third}.{$fourth}";
        checkRateLimit('distributed-test', 1, 60);
    }

    $buckets = glob($testRoot . DIRECTORY_SEPARATOR . 'bucket-*.json') ?: [];
    rate_limit_assert(count($buckets) <= 256, 'file count exceeded the bucket bound');
    foreach ($buckets as $bucket) {
        $decoded = json_decode(file_get_contents($bucket), true);
        rate_limit_assert(is_array($decoded), 'bucket JSON is invalid');
        rate_limit_assert(count($decoded) <= 512, 'bucket identity cap was exceeded');
    }
} finally {
    putenv('KSSMI_CLOUDFLARE_RANGES_FILE');
    remove_rate_limit_test_tree($testRoot);
    @rmdir($testRoot);
}

echo "Rate limit tests passed.\n";
