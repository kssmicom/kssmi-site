<?php
declare(strict_types=1);

// KS-03: short-links sign-in and reset budgets, device cookies and uniform
// outcomes. Uses the file rate-limit backend in a temporary directory.

if (($argv[1] ?? '') === '--reset-worker') {
    putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $argv[2]);
    require_once dirname(__DIR__) . '/private/short-links-tool.php';
    $deadline = microtime(true) + 10;
    while (!file_exists($argv[3]) && microtime(true) < $deadline) usleep(1000);
    if (!file_exists($argv[3])) exit(2);
    $users = ['target@kssmi.com' => ['hash' => KSSMI_SHORT_LINKS_DUMMY_HASH, 'admin' => false]];
    echo kssmi_short_links_reset_request('target@kssmi.com', $users, static function (string $email): void {});
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-short-links-auth-' . bin2hex(random_bytes(6));
if (!mkdir($root . DIRECTORY_SEPARATOR . 'main', 0700, true)) {
    fwrite(STDERR, "Short-links auth test failed: unable to create a temporary directory\n");
    exit(1);
}
putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
putenv('KSSMI_RATE_LIMIT_DIR=' . $root . DIRECTORY_SEPARATOR . 'main');
$errorLog = $root . DIRECTORY_SEPARATOR . 'php-error.log';
ini_set('error_log', $errorLog);
require_once dirname(__DIR__) . '/private/short-links-tool.php';

function sla_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function sla_attempts(array $users, string $email, string $password, $cookie, int $count): array {
    $results = [];
    for ($index = 0; $index < $count; $index++) {
        $results[] = kssmi_short_links_authenticate($users, $email, $password, $cookie)['result'];
    }
    return $results;
}

function sla_use_rate_limit_dir(string $root, string $name): string {
    $dir = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Unable to create ' . $dir);
    putenv('KSSMI_RATE_LIMIT_DIR=' . $dir);
    return $dir;
}

function sla_worker_command(string $dir, string $gate): array {
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false && extension_loaded('mbstring')) {
        $command[] = '-d'; $command[] = 'extension_dir=' . (string)ini_get('extension_dir');
        $command[] = '-d'; $command[] = 'extension=mbstring';
    }
    return [...$command, __FILE__, '--reset-worker', $dir, $gate];
}

function sla_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? sla_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

try {
    // Unknown addresses run one bcrypt check at the cost new hashes use.
    $dummy = password_get_info(KSSMI_SHORT_LINKS_DUMMY_HASH);
    $default = password_get_info(password_hash('cost probe', PASSWORD_DEFAULT));
    sla_assert(
        $dummy['algo'] === $default['algo'] && $dummy['options'] == $default['options'],
        'The dummy hash must use the algorithm and cost of new account hashes.'
    );

    $password = 'correct horse battery';
    $users = [];
    foreach (['alice', 'boss', 'carol', 'dave', 'erin'] as $name) {
        $users[$name . '@kssmi.com'] = ['hash' => password_hash($name . ' ' . $password, PASSWORD_DEFAULT), 'admin' => $name === 'boss'];
    }

    // One result for every kind of failure; the right password signs in.
    foreach ([
        ['ghost@kssmi.com', 'ghost ' . $password],
        ['alice@example.com', 'alice ' . $password],
        ['alice@kssmi.com', 'wrong password!'],
        ['not-an-address', 'anything at all'],
    ] as [$email, $guess]) {
        $result = kssmi_short_links_authenticate($users, $email, $guess, null);
        sla_assert($result === ['result' => 'invalid', 'entry' => null], "Failed sign-in for {$email} must be the uniform invalid result.");
    }
    $signedIn = kssmi_short_links_authenticate($users, 'boss@kssmi.com', 'boss ' . $password, null);
    sla_assert($signedIn['result'] === 'ok' && $signedIn['entry'] === $users['boss@kssmi.com'], 'The correct password must sign in.');

    // An account and a made-up address are throttled identically, and the
    // account budget has no IP in it.
    $expected = array_merge(array_fill(0, 10, 'invalid'), ['throttled']);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    sla_assert(sla_attempts($users, 'carol@kssmi.com', 'wrong guess 123', null, 11) === $expected, 'An account must be throttled after 10 attempts.');
    sla_assert(sla_attempts($users, 'nobody@kssmi.com', 'wrong guess 123', null, 11) === $expected, 'An unknown address must be throttled exactly like an account.');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    sla_assert(
        kssmi_short_links_authenticate($users, 'carol@kssmi.com', 'carol ' . $password, null)['result'] === 'throttled',
        'Neither a new IP nor the right password may bypass an exhausted account budget.'
    );
    sla_assert(
        kssmi_short_links_authenticate($users, 'boss@kssmi.com', 'boss ' . $password, null)['result'] === 'ok',
        'An exhausted budget must not affect other accounts.'
    );

    // A device cookie keeps a known browser working for its own account only.
    $now = time();
    $carolHash = $users['carol@kssmi.com']['hash'];
    $carolCookie = kssmi_short_links_device_cookie_value('carol@kssmi.com', $carolHash, $now + 3600);
    sla_assert(is_string($carolCookie), 'A device cookie must be minted.');
    sla_assert(
        kssmi_short_links_authenticate($users, 'carol@kssmi.com', 'carol ' . $password, $carolCookie)['result'] === 'ok',
        'A known device must sign in while the account budget is exhausted.'
    );
    sla_assert(kssmi_short_links_device_nonce($carolCookie, 'boss@kssmi.com', $users['boss@kssmi.com']) === null, 'A device cookie must be bound to its account.');
    sla_assert(kssmi_short_links_device_nonce($carolCookie, 'nobody@kssmi.com', null) === null, 'A device cookie must not apply to an unknown address.');
    $lastCharacter = substr($carolCookie, -1);
    $tampered = substr($carolCookie, 0, -1) . ($lastCharacter === '0' ? '1' : '0');
    sla_assert(kssmi_short_links_device_nonce($tampered, 'carol@kssmi.com', $users['carol@kssmi.com']) === null, 'A tampered device cookie must be rejected.');
    foreach ([$now - 1, $now + KSSMI_SHORT_LINKS_DEVICE_TTL + 3600] as $badExpiry) {
        $cookie = kssmi_short_links_device_cookie_value('carol@kssmi.com', $carolHash, $badExpiry);
        sla_assert(kssmi_short_links_device_nonce($cookie, 'carol@kssmi.com', $users['carol@kssmi.com']) === null, 'An expired or overlong device cookie must be rejected.');
    }
    $changed = $users['carol@kssmi.com'];
    $changed['hash'] = password_hash('carol new password', PASSWORD_DEFAULT);
    sla_assert(kssmi_short_links_device_nonce($carolCookie, 'carol@kssmi.com', $changed) === null, 'A password change must revoke device cookies.');

    // A device has its own budget; one stolen cookie does not allow unlimited guessing.
    $deviceCookie = kssmi_short_links_device_cookie_value('carol@kssmi.com', $carolHash, $now + 3600);
    sla_assert(
        sla_attempts($users, 'carol@kssmi.com', 'wrong guess 456', $deviceCookie, 11) === $expected,
        'A device must be throttled after 10 attempts of its own.'
    );

    // The daily account budget is wired in as well.
    for ($unit = 0; $unit < 40; $unit++) {
        kssmi_check_rate_limit_identity('short-links-login-account-day', 'dave@kssmi.com', 40, 86400, 1);
    }
    sla_assert(
        kssmi_short_links_authenticate($users, 'dave@kssmi.com', 'dave ' . $password, null)['result'] === 'throttled',
        'The daily account budget must apply.'
    );

    // Reset requests: per address whatever the IP, global only for real mail.
    sla_use_rate_limit_dir($root, 'reset');
    $sent = [];
    $deliver = static function (string $email) use (&$sent): void { $sent[] = $email; };
    sla_assert(kssmi_short_links_reset_request('alice@example.com', $users, $deliver) === 'ignored', 'Another domain must be ignored.');
    sla_assert(kssmi_short_links_reset_request('not-an-address', $users, $deliver) === 'ignored', 'A malformed address must be ignored.');
    $outcomes = [];
    foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4', '192.0.2.5'] as $ip) {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $outcomes[] = kssmi_short_links_reset_request('alice@kssmi.com', $users, $deliver);
    }
    sla_assert(
        $outcomes === ['sent', 'sent', 'sent', 'account_budget', 'account_budget'],
        'An address must get at most 3 reset mails per hour, whatever the source IP.'
    );
    for ($index = 0; $index < 5; $index++) {
        sla_assert(kssmi_short_links_reset_request("ghost{$index}@kssmi.com", $users, $deliver) === 'unknown', 'An unknown address must not be mailed.');
    }
    $failing = static function (string $email): void {
        throw new RuntimeException('SMTP Error: Could not authenticate. relay-detail-7f3a');
    };
    sla_assert(kssmi_short_links_reset_request('erin@kssmi.com', $users, $failing) === 'failed', 'A delivery failure must be contained.');
    sla_assert(str_contains((string)@file_get_contents($errorLog), 'relay-detail-7f3a'), 'A delivery failure must be logged on the server.');

    $many = $users;
    for ($index = 0; $index < 30; $index++) {
        $many["colleague{$index}@kssmi.com"] = ['hash' => KSSMI_SHORT_LINKS_DUMMY_HASH, 'admin' => false];
    }
    $globalOutcomes = [];
    for ($index = 0; $index < 30; $index++) {
        $globalOutcomes[] = kssmi_short_links_reset_request("colleague{$index}@kssmi.com", $many, $deliver);
    }
    // 3 to alice and 1 failed attempt to erin were already charged.
    $counts = array_count_values($globalOutcomes);
    sla_assert(($counts['sent'] ?? 0) === 16 && ($counts['global_budget'] ?? 0) === 14, 'At most 20 reset mails per hour may be attempted in total.');
    sla_assert(count($sent) === 19, 'Exactly the allowed reset mails must be delivered.');

    sla_use_rate_limit_dir($root, 'reset-day');
    for ($unit = 0; $unit < 50; $unit++) {
        checkRateLimitGlobal('short-links-reset-mail-day', 50, 86400);
    }
    sla_assert(kssmi_short_links_reset_request('boss@kssmi.com', $users, $deliver) === 'global_budget', 'The daily global reset budget must apply.');

    // Concurrent requests for one address never exceed its budget.
    $workerDir = sla_use_rate_limit_dir($root, 'concurrent');
    $gate = $root . DIRECTORY_SEPARATOR . '.gate';
    $workers = [];
    for ($index = 0; $index < 8; $index++) {
        $pipes = [];
        $process = proc_open(sla_worker_command($workerDir, $gate), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        sla_assert(is_resource($process), 'Unable to start a reset worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    touch($gate);
    $concurrent = [];
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        sla_assert(proc_close($process) === 0, 'Reset worker failed: ' . trim((string)$stderr));
        $concurrent[] = trim((string)$stdout);
    }
    $concurrentCounts = array_count_values($concurrent);
    sla_assert(
        ($concurrentCounts['sent'] ?? 0) === 3 && ($concurrentCounts['account_budget'] ?? 0) === 5,
        'Concurrent reset requests exceeded the per-address budget: ' . implode(',', $concurrent)
    );

    echo "Short-links sign-in and reset budget tests passed.\n";
} finally {
    sla_remove_tree($root);
}
