<?php
declare(strict_types=1);

/**
 * Integration test for password-reset concurrency.
 *
 * Run: php scripts/test-reset-token-race.php
 */

require_once dirname(__DIR__) . '/private/http-security.php';

function kssmi_reset_race_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_reset_race_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_reset_race_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $tokensPath, $passwordPath, $token, $barrierPath, $workerIndex, $now] = $argv;
    $deadline = microtime(true) + 15.0;
    while (!file_exists($barrierPath)) {
        if (microtime(true) >= $deadline) {
            fwrite(STDERR, "worker barrier timeout\n");
            exit(2);
        }
        usleep(1000);
    }

    $password = 'Concurrent reset password ' . $workerIndex . ' 2026!';
    $result = kssmi_admin_reset_password(
        $tokensPath,
        $passwordPath,
        $token,
        $password,
        (int)$now
    );
    fwrite(STDOUT, json_encode([
        'worker' => (int)$workerIndex,
        'password' => $password,
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES));
    exit(0);
}

kssmi_reset_race_assert(function_exists('proc_open'), 'proc_open is required for the concurrency test');

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-reset-race-' . bin2hex(random_bytes(6));
kssmi_reset_race_assert(mkdir($testDirectory, 0700, true), 'create race-test directory');

$tokensPath = $testDirectory . DIRECTORY_SEPARATOR . '.email_reset_tokens.json';
$passwordPath = $testDirectory . DIRECTORY_SEPARATOR . '.email_logs_password';
$barrierPath = $testDirectory . DIRECTORY_SEPARATOR . '.start';
$token = str_repeat('f', 64);
$now = time();
$workerCount = 8;
$processes = [];

try {
    kssmi_reset_race_assert(
        kssmi_admin_reset_token_add($tokensPath, $token, $now + 3600, $now),
        'seed one reset token for concurrent workers'
    );

    for ($index = 0; $index < $workerCount; $index++) {
        $command = [
            PHP_BINARY,
            __FILE__,
            'worker',
            $tokensPath,
            $passwordPath,
            $token,
            $barrierPath,
            (string)$index,
            (string)$now,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        kssmi_reset_race_assert(is_resource($process), "start worker {$index}");
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    kssmi_reset_race_assert(file_put_contents($barrierPath, 'go') === 2, 'release worker barrier');

    $results = [];
    foreach ($processes as $index => $worker) {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $exitCode = proc_close($worker['process']);
        kssmi_reset_race_assert($exitCode === 0, "worker {$index} exited cleanly: {$stderr}");

        $decoded = json_decode($stdout, true);
        kssmi_reset_race_assert(is_array($decoded), "worker {$index} returned JSON");
        $results[] = $decoded;
    }
    $processes = [];

    $winners = array_values(array_filter(
        $results,
        fn($entry) => ($entry['result']['changed'] ?? false) === true
    ));
    kssmi_reset_race_assert(count($winners) === 1, 'exactly one concurrent request changes the password');

    foreach ($results as $entry) {
        kssmi_reset_race_assert(
            ($entry['result']['ok'] ?? false) === true,
            'every concurrent worker completes without storage errors'
        );
        $changed = ($entry['result']['changed'] ?? false) === true;
        $consumed = ($entry['result']['consumed'] ?? false) === true;
        kssmi_reset_race_assert(
            $changed === $consumed,
            'only the token-consumption winner reports a password change'
        );
    }

    $storedHash = kssmi_admin_secret_read($passwordPath);
    kssmi_reset_race_assert(is_string($storedHash), 'winner writes a password hash');
    kssmi_reset_race_assert(
        password_verify($winners[0]['password'], $storedHash),
        'stored password belongs to the sole token-consumption winner'
    );
    kssmi_reset_race_assert(
        kssmi_admin_reset_token_valid($tokensPath, $token, $now) === false,
        'concurrently consumed token is no longer valid'
    );

    $hashBeforeReplay = file_get_contents($passwordPath);
    $replay = kssmi_admin_reset_password(
        $tokensPath,
        $passwordPath,
        $token,
        'Post-race replay password must not win 2026!',
        $now
    );
    kssmi_reset_race_assert(
        $replay['ok'] === true && $replay['changed'] === false && $replay['consumed'] === false,
        'post-race replay is rejected without a password change'
    );
    kssmi_reset_race_assert(
        file_get_contents($passwordPath) === $hashBeforeReplay,
        'post-race replay leaves the winning hash unchanged'
    );

    fwrite(STDOUT, "Reset token concurrency test passed.\n");
} finally {
    foreach ($processes as $worker) {
        foreach (['stdout', 'stderr'] as $stream) {
            if (isset($worker[$stream]) && is_resource($worker[$stream])) fclose($worker[$stream]);
        }
        if (isset($worker['process']) && is_resource($worker['process'])) proc_terminate($worker['process']);
    }
    kssmi_reset_race_remove_tree($testDirectory);
}
