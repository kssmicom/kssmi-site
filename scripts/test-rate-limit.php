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

try {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
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
    remove_rate_limit_test_tree($testRoot);
    @rmdir($testRoot);
}

echo "Rate limit tests passed.\n";
