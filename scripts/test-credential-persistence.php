<?php
declare(strict_types=1);

/**
 * KS-05: admin credentials persist across releases.
 *
 * A release holds only links to the persistent credential files in the
 * private root. These cases build that layout under a temporary directory:
 *
 *   1. With no change, a session and a reset token issued through release R1
 *      are honoured through R2, and the token works once.
 *   2. A password change through R1 lands in the persistent files (hash,
 *      generation, cleared tokens); through R2 only the new password works
 *      and the earlier session is rejected.
 *   3. A reset through R2 does the same; its token is consumed once, and a
 *      token from the superseded generation is invalid.
 *   4. A lost or corrupt generation fails closed, creates nothing and is
 *      never overwritten.
 *   5. Dangling links, links to a directory and chains with a missing end
 *      resolve to null; nothing is written or locked through them.
 *   6. With R1's root read-only (0551), changes through its links succeed
 *      and plain paths inside it fail.
 *   7. Concurrent workers through both releases: one reset winner, lock
 *      files only beside the persistent files, and locks taken in the order
 *      reset tokens, generation, password.
 *   8. Two credential paths resolving to one file fail at once instead of
 *      waiting forever for a lock that is already held.
 *   9. Sessions and reset tokens from before credential generations are
 *      rejected.
 *
 * Every case runs on Linux as an unprivileged user; the test refuses to run
 * as root, which ignores directory permissions. A Windows host that cannot
 * create symbolic links runs cases 4, 7, 8 and 9 against plain paths and
 * reports what it skipped.
 *
 * Run: php scripts/test-credential-persistence.php
 */

// Warnings must not reach the output layer: once output starts, PHP refuses
// to regenerate session ids, and a worker's stdout carries only its result.
ini_set('display_errors', 'stderr');

require_once dirname(__DIR__) . '/private/http-security.php';

const KSSMI_PERSIST_PASSWORD = 'Persistent admin password 2026!';
const KSSMI_PERSIST_KINDS = ['password', 'tokens', 'generation'];

function kssmi_persist_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_persist_remove_tree(string $path): void {
    if (is_link($path) || !is_dir($path)) {
        @unlink($path);
        return;
    }
    @chmod($path, 0700);
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') kssmi_persist_remove_tree($path . '/' . $name);
    }
    @rmdir($path);
}

function kssmi_persist_put(string $path, string $contents): void {
    kssmi_persist_assert(
        file_put_contents($path, $contents) === strlen($contents) && chmod($path, 0600),
        'write ' . basename($path)
    );
}

function kssmi_persist_read(string $path): string {
    clearstatcache(true);
    $contents = @file_get_contents($path);
    kssmi_persist_assert(is_string($contents), 'read ' . basename($path));
    return $contents;
}

/**
 * The production layout: the persistent credential files in a private root,
 * and two releases whose credential paths are links to them. Without link
 * support both "releases" are the persistent paths themselves.
 */
function kssmi_persist_fixture(string $name, bool $lockFiles = true): array {
    $base = KSSMI_PERSIST_ROOT . '/' . $name;
    $private = $base . '/home/kssmi.com';
    $sessions = $base . '/sessions';
    kssmi_persist_assert(mkdir($private . '/releases', 0700, true) && mkdir($sessions, 0700), "{$name}: create the private root");
    $persistent = [
        'password' => $private . '/.email_logs_password',
        'tokens' => $private . '/.email_reset_tokens.json',
        'generation' => kssmi_admin_credential_generation_path($private . '/.email_logs_password'),
    ];
    $generation = kssmi_admin_credential_generation_new();
    kssmi_persist_assert($generation !== null, "{$name}: create a credential generation");
    kssmi_persist_put($persistent['password'], password_hash(KSSMI_PERSIST_PASSWORD, PASSWORD_BCRYPT));
    kssmi_persist_put($persistent['tokens'], '[]');
    kssmi_persist_put($persistent['generation'], $generation);
    if ($lockFiles) {
        // The deploy pre-creates these; case 7 lets the operations create them.
        foreach ($persistent as $path) kssmi_persist_put($path . '.lock', '');
    }

    $releases = [];
    foreach (['r1', 'r2'] as $id) {
        if (!KSSMI_PERSIST_LINKS) {
            $releases[$id] = $persistent + ['dir' => $private];
            continue;
        }
        $dir = $private . '/releases/' . $id;
        kssmi_persist_assert(mkdir($dir, 0751), "{$name}: create release {$id}");
        foreach ($persistent as $kind => $target) {
            kssmi_persist_assert(symlink($target, $dir . '/' . basename($target)), "{$name}: link the {$id} {$kind}");
        }
        $releases[$id] = [
            'dir' => $dir,
            'password' => $dir . '/.email_logs_password',
            'tokens' => $dir . '/.email_reset_tokens.json',
            'generation' => kssmi_admin_credential_generation_path($dir . '/.email_logs_password'),
        ];
    }

    return [
        'base' => $base,
        'private' => $private,
        'sessions' => $sessions,
        'persistent' => $persistent,
        'releases' => $releases,
    ];
}

/**
 * Name => kind and content digest for each entry of one directory, so a
 * comparison catches created, removed, retargeted or rewritten entries. Lock
 * files are empty sidecars and only their presence is recorded.
 */
function kssmi_persist_snapshot(string $directory): array {
    clearstatcache(true);
    $entries = [];
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . '/' . $name;
        if (is_link($path)) $entries[$name] = 'link:' . readlink($path);
        elseif (is_dir($path)) $entries[$name] = 'dir';
        elseif (str_ends_with($name, '.lock')) $entries[$name] = 'lock';
        else $entries[$name] = 'file:' . hash_file('sha256', $path);
    }
    return $entries;
}

function kssmi_persist_tree(array $f): array {
    $tree = ['private' => kssmi_persist_snapshot($f['private'])];
    if (KSSMI_PERSIST_LINKS) {
        foreach ($f['releases'] as $id => $release) $tree[$id] = kssmi_persist_snapshot($release['dir']);
    }
    return $tree;
}

/**
 * Each release path is still the exact link to its persistent file, and the
 * releases hold nothing else: no lock, temporary or credential file.
 */
function kssmi_persist_assert_links_intact(array $f, string $label): void {
    if (!KSSMI_PERSIST_LINKS) return;
    foreach ($f['releases'] as $id => $release) {
        $expected = [];
        foreach (KSSMI_PERSIST_KINDS as $kind) {
            clearstatcache(true);
            kssmi_persist_assert(
                is_link($release[$kind]) && readlink($release[$kind]) === $f['persistent'][$kind],
                "{$label}: the {$id} {$kind} is still the link to the persistent file"
            );
            $expected[basename($release[$kind])] = 'link:' . $f['persistent'][$kind];
        }
        $actual = kssmi_persist_snapshot($release['dir']);
        ksort($expected);
        ksort($actual);
        kssmi_persist_assert($actual === $expected, "{$label}: {$id} holds only its three credential links");
    }
}

function kssmi_persist_tokens(array $f): array {
    $tokens = json_decode(kssmi_persist_read($f['persistent']['tokens']), true);
    kssmi_persist_assert(is_array($tokens), 'the persistent token store is valid JSON');
    return $tokens;
}

function kssmi_persist_generation(array $f): string {
    return kssmi_persist_read($f['persistent']['generation']);
}

function kssmi_persist_password_is(array $f, string $password): bool {
    return password_verify($password, trim(kssmi_persist_read($f['persistent']['password'])));
}

function kssmi_persist_login(array $release, string $password, int $now): bool {
    return kssmi_admin_authenticate_and_establish(
        $release['password'],
        $release['tokens'],
        $release['generation'],
        $password,
        $now
    );
}

/**
 * Whether a request carrying $session is accepted through $release. Each call
 * starts from the same session data, as an independent request would.
 */
function kssmi_persist_session_accepted(array $session, array $release, int $now): bool {
    $_SESSION = $session;
    return kssmi_admin_session_authenticated($release['generation'], $now);
}

// ── Worker processes ────────────────────────────────────────────────────────

/**
 * One credential operation in its own process, for cases 7 and 8. A barrier
 * file, when given, lines concurrent workers up. Prints {"result": ...}.
 */
function kssmi_persist_worker(array $arguments): int {
    if (count($arguments) !== 8) {
        fwrite(STDERR, "worker expects 8 arguments\n");
        return 2;
    }
    [$operation, $tokensPath, $passwordPath, $generationPath, $password, $token, $barrier, $sessions] = $arguments;
    if ($barrier !== '-') {
        $deadline = microtime(true) + 15.0;
        while (!file_exists($barrier)) {
            if (microtime(true) >= $deadline) {
                fwrite(STDERR, "worker barrier timeout\n");
                return 2;
            }
            usleep(1000);
        }
    }

    if ($operation === 'login') {
        session_save_path($sessions);
        session_name('KSSMI_PERSIST_WORKER');
        kssmi_admin_session_bootstrap();
        $result = kssmi_admin_authenticate_and_establish($passwordPath, $tokensPath, $generationPath, $password);
    } elseif ($operation === 'change') {
        $result = kssmi_admin_change_password($tokensPath, $passwordPath, $password, $generationPath);
    } elseif ($operation === 'reset') {
        $result = kssmi_admin_reset_password($tokensPath, $passwordPath, $token, $password, null, $generationPath);
    } else {
        fwrite(STDERR, "unknown worker operation\n");
        return 2;
    }
    fwrite(STDOUT, json_encode(['result' => $result], JSON_UNESCAPED_SLASHES));
    return 0;
}

/**
 * Start a worker; returns its id for kssmi_persist_collect().
 *
 * @param array{password: string, tokens: string, generation: string} $paths
 */
function kssmi_persist_spawn(string $operation, array $paths, string $password, string $token, string $barrier, string $sessions): int {
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY, __FILE__, 'worker', $operation,
            $paths['tokens'], $paths['password'], $paths['generation'],
            $password, $token, $barrier, $sessions,
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    kssmi_persist_assert(is_resource($process), "start a {$operation} worker");
    fclose($pipes[0]);
    $GLOBALS['kssmi_persist_workers'][] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    return array_key_last($GLOBALS['kssmi_persist_workers']);
}

/**
 * Wait for a worker and return its decoded output. A worker still running
 * after $seconds is killed and the test fails, so a hang cannot stall CI.
 */
function kssmi_persist_collect(int $id, string $label, float $seconds = 30.0): array {
    $worker = $GLOBALS['kssmi_persist_workers'][$id];
    $deadline = microtime(true) + $seconds;
    $status = proc_get_status($worker['process']);
    while ($status['running']) {
        if (microtime(true) >= $deadline) {
            proc_terminate($worker['process'], 9);
            kssmi_persist_assert(false, "{$label} did not finish within {$seconds} seconds");
        }
        usleep(5000);
        $status = proc_get_status($worker['process']);
    }
    $stdout = (string)stream_get_contents($worker['stdout']);
    $stderr = (string)stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    proc_close($worker['process']);
    unset($GLOBALS['kssmi_persist_workers'][$id]);
    kssmi_persist_assert($status['exitcode'] === 0, "{$label} exited cleanly: {$stderr}");
    $output = json_decode($stdout, true);
    kssmi_persist_assert(is_array($output) && array_key_exists('result', $output), "{$label} returned a result: {$stdout}");
    return $output;
}

/**
 * Hold a persistent lock file directly, as a concurrent request would.
 *
 * @return resource
 */
function kssmi_persist_hold(string $lockPath) {
    $handle = fopen($lockPath, 'c+b');
    kssmi_persist_assert($handle !== false && flock($handle, LOCK_EX), 'hold ' . basename($lockPath));
    return $handle;
}

/** @param resource $handle */
function kssmi_persist_release($handle): void {
    flock($handle, LOCK_UN);
    fclose($handle);
}

/** True while no other process holds the lock file. */
function kssmi_persist_lock_free(string $lockPath): bool {
    $handle = fopen($lockPath, 'c+b');
    kssmi_persist_assert($handle !== false, 'open ' . basename($lockPath));
    $free = flock($handle, LOCK_EX | LOCK_NB);
    if ($free) flock($handle, LOCK_UN);
    fclose($handle);
    return $free;
}

function kssmi_persist_wait_until(callable $condition, string $message, float $seconds = 15.0): void {
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        kssmi_persist_assert(microtime(true) < $deadline, $message);
        usleep(5000);
    }
}

// ── Cases ───────────────────────────────────────────────────────────────────

function kssmi_persist_case_unchanged_password(array $f, int $now): void {
    ['r1' => $r1, 'r2' => $r2] = $f['releases'];
    kssmi_persist_assert(kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now), 'case 1: log in through R1');
    $session = $_SESSION;
    kssmi_persist_assert(kssmi_persist_session_accepted($session, $r2, $now + 1), 'case 1: the R1 session is accepted through R2');

    $token = str_repeat('1', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 1: issue a reset token through R1'
    );
    kssmi_persist_assert(
        kssmi_admin_reset_token_valid($r2['tokens'], $token, $r2['generation'], $now),
        'case 1: the R1 reset token is valid through R2'
    );
    $newPassword = 'Case 1 reset through R2 2026!';
    $reset = kssmi_admin_reset_password($r2['tokens'], $r2['password'], $token, $newPassword, $now, $r2['generation']);
    kssmi_persist_assert(
        $reset['ok'] === true && $reset['changed'] === true && $reset['consumed'] === true,
        'case 1: the R1 reset token resets the password through R2'
    );
    $replay = kssmi_admin_reset_password($r1['tokens'], $r1['password'], $token, 'Case 1 replay through R1 2026!', $now, $r1['generation']);
    kssmi_persist_assert(
        $replay['ok'] === true && $replay['changed'] === false && $replay['consumed'] === false,
        'case 1: the used token is rejected through R1'
    );
    kssmi_persist_assert(kssmi_persist_password_is($f, $newPassword), 'case 1: the persistent hash holds the reset password');
    kssmi_persist_assert_links_intact($f, 'case 1');
}

function kssmi_persist_case_change_through_r1(array $f, int $now): void {
    ['r1' => $r1, 'r2' => $r2] = $f['releases'];
    kssmi_persist_assert(kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now), 'case 2: log in through R1');
    $oldSession = $_SESSION;
    $oldGeneration = kssmi_persist_generation($f);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r2['tokens'], str_repeat('2', 64), $now + 3600, $r2['generation'], $now),
        'case 2: issue a reset token that the change must revoke'
    );

    $newPassword = 'Case 2 changed through R1 2026!';
    $change = kssmi_admin_change_password($r1['tokens'], $r1['password'], $newPassword, $r1['generation'], $now);
    kssmi_persist_assert($change['ok'] === true && $change['changed'] === true, 'case 2: change the password through R1');
    kssmi_persist_assert_links_intact($f, 'case 2');
    kssmi_persist_assert(kssmi_persist_password_is($f, $newPassword), 'case 2: the persistent hash holds the new password');
    $newGeneration = kssmi_persist_generation($f);
    kssmi_persist_assert(
        kssmi_admin_credential_generation_valid($newGeneration) && $newGeneration !== $oldGeneration,
        'case 2: the persistent generation is replaced'
    );
    kssmi_persist_assert(kssmi_persist_tokens($f) === [], 'case 2: the persistent token store is cleared');

    kssmi_persist_assert(!kssmi_persist_login($r2, KSSMI_PERSIST_PASSWORD, $now + 1), 'case 2: the old password is rejected through R2');
    kssmi_persist_assert(kssmi_persist_login($r2, $newPassword, $now + 1), 'case 2: the new password logs in through R2');
    foreach ($f['releases'] as $id => $release) {
        kssmi_persist_assert(
            !kssmi_persist_session_accepted($oldSession, $release, $now + 2),
            "case 2: the session from before the change is rejected through {$id}"
        );
    }
}

function kssmi_persist_case_reset_through_r2(array $f, int $now): void {
    ['r1' => $r1, 'r2' => $r2] = $f['releases'];
    kssmi_persist_assert(kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now), 'case 3: log in through R1');
    $oldSession = $_SESSION;
    $oldGeneration = kssmi_persist_generation($f);
    $token = str_repeat('3', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 3: issue a reset token through R1'
    );

    $newPassword = 'Case 3 reset through R2 2026!';
    $reset = kssmi_admin_reset_password($r2['tokens'], $r2['password'], $token, $newPassword, $now, $r2['generation']);
    kssmi_persist_assert(
        $reset['ok'] === true && $reset['changed'] === true && $reset['consumed'] === true,
        'case 3: reset the password through R2'
    );
    kssmi_persist_assert_links_intact($f, 'case 3');
    kssmi_persist_assert(kssmi_persist_password_is($f, $newPassword), 'case 3: the persistent hash holds the reset password');
    $newGeneration = kssmi_persist_generation($f);
    kssmi_persist_assert(
        kssmi_admin_credential_generation_valid($newGeneration) && $newGeneration !== $oldGeneration,
        'case 3: the persistent generation is replaced'
    );
    kssmi_persist_assert(kssmi_persist_tokens($f) === [], 'case 3: the persistent token store is cleared');
    kssmi_persist_assert(!kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now + 1), 'case 3: the old password is rejected through R1');
    kssmi_persist_assert(kssmi_persist_login($r1, $newPassword, $now + 1), 'case 3: the reset password logs in through R1');
    foreach ($f['releases'] as $id => $release) {
        kssmi_persist_assert(
            !kssmi_persist_session_accepted($oldSession, $release, $now + 2),
            "case 3: the session from before the reset is rejected through {$id}"
        );
    }

    $hash = kssmi_persist_read($f['persistent']['password']);
    $replay = kssmi_admin_reset_password($r1['tokens'], $r1['password'], $token, 'Case 3 replay through R1 2026!', $now, $r1['generation']);
    kssmi_persist_assert(
        $replay['ok'] === true && $replay['changed'] === false && $replay['consumed'] === false,
        'case 3: the consumed token is rejected through R1'
    );
    // add() always stamps the current generation, so a token from the
    // superseded generation is written to the store directly.
    $staleToken = str_repeat('4', 64);
    $seed = kssmi_admin_reset_tokens_mutate($r2['tokens'], fn(array $tokens): array => [
        $staleToken => ['created' => $now, 'expires' => $now + 3600, 'credential_generation' => $oldGeneration],
    ]);
    kssmi_persist_assert($seed['ok'] === true, 'case 3: store a token from the superseded generation through R2');
    kssmi_persist_assert(
        !kssmi_admin_reset_token_valid($r1['tokens'], $staleToken, $r1['generation'], $now),
        'case 3: a token from the superseded generation is invalid'
    );
    $stale = kssmi_admin_reset_password($r1['tokens'], $r1['password'], $staleToken, 'Case 3 stale token 2026!', $now, $r1['generation']);
    kssmi_persist_assert(
        $stale['ok'] === true && $stale['changed'] === false && $stale['consumed'] === false,
        'case 3: a token from the superseded generation cannot reset the password'
    );
    kssmi_persist_assert(kssmi_persist_read($f['persistent']['password']) === $hash, 'case 3: rejected tokens leave the hash unchanged');
    kssmi_persist_assert_links_intact($f, 'case 3');
}

/**
 * While the generation is unusable, every credential operation through
 * either release fails closed and changes nothing.
 */
function kssmi_persist_assert_fails_closed(array $f, array $session, string $token, int $now, string $label): void {
    $before = kssmi_persist_tree($f);
    foreach ($f['releases'] as $id => $release) {
        kssmi_persist_assert(
            !kssmi_persist_session_accepted($session, $release, $now + 1),
            "case 4: a session is rejected through {$id} {$label}"
        );
        kssmi_persist_assert(!kssmi_persist_login($release, KSSMI_PERSIST_PASSWORD, $now + 1), "case 4: login fails through {$id} {$label}");
        kssmi_persist_assert(
            kssmi_admin_credential_generation_ensure($release['generation']) === null,
            "case 4: {$id} creates no generation {$label}"
        );
        kssmi_persist_assert(
            !kssmi_admin_reset_token_valid($release['tokens'], $token, $release['generation'], $now),
            "case 4: the reset token is invalid through {$id} {$label}"
        );
        kssmi_persist_assert(
            !kssmi_admin_reset_token_add($release['tokens'], str_repeat('6', 64), $now + 3600, $release['generation'], $now),
            "case 4: no reset token is issued through {$id} {$label}"
        );
        $change = kssmi_admin_change_password($release['tokens'], $release['password'], 'Case 4 must not be stored 2026!', $release['generation'], $now);
        kssmi_persist_assert(
            $change['ok'] === false && $change['changed'] === false && $change['error'] === 'generation_read_failed',
            "case 4: a password change fails through {$id} {$label}"
        );
    }
    kssmi_persist_assert(kssmi_persist_tree($f) === $before, "case 4: nothing is created or changed {$label}");
}

function kssmi_persist_case_lost_generation(array $f, int $now): void {
    ['r1' => $r1, 'r2' => $r2] = $f['releases'];
    kssmi_persist_assert(kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now), 'case 4: log in before the generation is lost');
    $session = $_SESSION;
    $token = str_repeat('5', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 4: issue a reset token before the generation is lost'
    );
    $hash = kssmi_persist_read($f['persistent']['password']);
    kssmi_persist_assert(unlink($f['persistent']['generation']), 'case 4: remove the persistent generation');
    kssmi_persist_assert_fails_closed($f, $session, $token, $now, 'once the generation is lost');

    // A presented reset token is burned rather than left for a replay.
    $expected = kssmi_persist_tree($f);
    $expected['private']['.email_reset_tokens.json'] = 'file:' . hash('sha256', '[]');
    $reset = kssmi_admin_reset_password($r2['tokens'], $r2['password'], $token, 'Case 4 must not be stored 2026!', $now, $r2['generation']);
    kssmi_persist_assert(
        $reset['ok'] === false && $reset['changed'] === false && $reset['consumed'] === true
            && $reset['error'] === 'generation_read_failed',
        'case 4: a reset without a generation fails and burns its token'
    );
    kssmi_persist_assert(kssmi_persist_tree($f) === $expected, 'case 4: burning the token changes only the token store');
    kssmi_persist_assert(kssmi_persist_read($f['persistent']['password']) === $hash, 'case 4: the persistent hash is unchanged');
    kssmi_persist_assert_links_intact($f, 'case 4');
}

function kssmi_persist_case_corrupt_generation(array $f, int $now): void {
    ['r1' => $r1] = $f['releases'];
    kssmi_persist_assert(kssmi_persist_login($r1, KSSMI_PERSIST_PASSWORD, $now), 'case 4: log in before the generation is corrupted');
    $session = $_SESSION;
    $token = str_repeat('5', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 4: issue a reset token before the generation is corrupted'
    );
    kssmi_persist_put($f['persistent']['generation'], str_repeat('A', 32) . "\n");
    kssmi_persist_assert_fails_closed($f, $session, $token, $now, 'while the generation is corrupt');
    $before = kssmi_persist_tree($f);
    kssmi_persist_assert(
        kssmi_admin_credential_generation_ensure($f['persistent']['generation']) === null,
        'case 4: the corrupt persistent generation is not replaced'
    );
    kssmi_persist_assert(kssmi_persist_tree($f) === $before, 'case 4: the corrupt generation is left byte-for-byte unchanged');
    kssmi_persist_assert_links_intact($f, 'case 4');
}

function kssmi_persist_case_abnormal_links(array $f): void {
    $dir = $f['private'] . '/releases/abnormal';
    $directory = $f['private'] . '/directory-target';
    kssmi_persist_assert(mkdir($dir, 0751) && mkdir($directory, 0700), 'case 5: create the abnormal release');
    $links = [
        'dangling' => [$dir . '/dangling', $f['private'] . '/missing-credential'],
        'directory' => [$dir . '/directory', $directory],
        'missing chain end' => [$dir . '/chain-end', $f['private'] . '/missing-chain-end'],
        'chain' => [$dir . '/chain', $dir . '/chain-end'],
    ];
    foreach ($links as $name => [$link, $target]) {
        kssmi_persist_assert(symlink($target, $link), "case 5: create the {$name} link");
    }

    $before = [kssmi_persist_snapshot($dir), kssmi_persist_snapshot($f['private']), kssmi_persist_snapshot($directory)];
    foreach ($links as $name => [$link]) {
        kssmi_persist_assert(kssmi_admin_storage_path($link) === null, "case 5: a {$name} link resolves to null");
        kssmi_persist_assert(!kssmi_admin_atomic_write($link, 'x', 0600), "case 5: an atomic write through a {$name} link fails");
        kssmi_persist_assert(!kssmi_admin_secret_write($link, 'x'), "case 5: a secret write through a {$name} link fails");
        kssmi_persist_assert(kssmi_admin_secret_read($link) === null, "case 5: a secret read through a {$name} link fails");
        foreach ([LOCK_SH, LOCK_EX] as $operation) {
            $lock = kssmi_admin_file_lock($link, $operation);
            kssmi_persist_assert(
                $lock['ok'] === false && $lock['error'] === 'storage_path_invalid',
                "case 5: a lock through a {$name} link fails"
            );
        }
        kssmi_persist_assert(
            kssmi_admin_credential_generation_ensure($link) === null,
            "case 5: no generation is created through a {$name} link"
        );
        kssmi_persist_assert(!kssmi_admin_reset_tokens_read($link)['ok'], "case 5: a token read through a {$name} link fails");
        kssmi_persist_assert(
            !kssmi_admin_reset_tokens_mutate($link, fn(array $tokens): array => $tokens)['ok'],
            "case 5: a token write through a {$name} link fails"
        );
    }
    kssmi_persist_assert(
        [kssmi_persist_snapshot($dir), kssmi_persist_snapshot($f['private']), kssmi_persist_snapshot($directory)] === $before,
        'case 5: nothing is created beside or behind an abnormal link'
    );

    $final = $f['private'] . '/chain-final';
    kssmi_persist_put($final, 'before');
    kssmi_persist_assert(
        symlink($final, $dir . '/good-end') && symlink($dir . '/good-end', $dir . '/good-chain'),
        'case 5: create a chain that ends in a file'
    );
    kssmi_persist_assert(
        kssmi_admin_storage_path($dir . '/good-chain') === realpath($final),
        'case 5: a chain resolves to its final file'
    );
    kssmi_persist_assert(kssmi_admin_secret_write($dir . '/good-chain', 'after'), 'case 5: a write through the chain succeeds');
    clearstatcache(true);
    kssmi_persist_assert(
        kssmi_persist_read($final) === 'after' && is_link($dir . '/good-chain') && is_link($dir . '/good-end'),
        "case 5: the chain's final file is replaced and both links stay"
    );
}

function kssmi_persist_case_read_only_release(array $f, int $now): void {
    ['r1' => $r1] = $f['releases'];
    kssmi_persist_assert(chmod($r1['dir'], 0551), 'case 6: make the R1 root read-only');
    clearstatcache(true);
    kssmi_persist_assert(!is_writable($r1['dir']), 'case 6: the R1 root must be read-only for this user; run as an unprivileged user');
    $before = kssmi_persist_snapshot($r1['dir']);

    $changed = 'Case 6 changed through read-only R1 2026!';
    $change = kssmi_admin_change_password($r1['tokens'], $r1['password'], $changed, $r1['generation'], $now);
    kssmi_persist_assert(
        $change['ok'] === true && $change['changed'] === true && kssmi_persist_password_is($f, $changed),
        'case 6: a change through the read-only R1 lands in the persistent file'
    );
    $token = str_repeat('6', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 6: a reset token is issued through the read-only R1'
    );
    $resetPassword = 'Case 6 reset through read-only R1 2026!';
    $reset = kssmi_admin_reset_password($r1['tokens'], $r1['password'], $token, $resetPassword, $now, $r1['generation']);
    kssmi_persist_assert(
        $reset['ok'] === true && $reset['changed'] === true && $reset['consumed'] === true
            && kssmi_persist_password_is($f, $resetPassword),
        'case 6: a reset through the read-only R1 lands in the persistent file'
    );
    kssmi_persist_assert(kssmi_persist_login($r1, $resetPassword, $now + 1), 'case 6: login works through the read-only R1');

    $plain = $r1['dir'] . '/.plain-credential';
    kssmi_persist_assert(!kssmi_admin_atomic_write($plain, 'plain', 0600), 'case 6: an atomic write to a plain path in R1 fails');
    kssmi_persist_assert(!kssmi_admin_file_lock($plain, LOCK_EX)['ok'], 'case 6: a lock at a plain path in R1 fails');
    kssmi_persist_assert(!kssmi_admin_secret_write($plain, 'plain'), 'case 6: a secret write to a plain path in R1 fails');
    kssmi_persist_assert(
        kssmi_admin_credential_generation_ensure($r1['dir'] . '/.plain-generation') === null,
        'case 6: no generation is created at a plain path in R1'
    );
    kssmi_persist_assert(kssmi_persist_snapshot($r1['dir']) === $before, 'case 6: the read-only R1 is unchanged');
    kssmi_persist_assert(chmod($r1['dir'], 0751), 'case 6: restore the R1 root');
}

function kssmi_persist_case_concurrency(array $f, int $now): void {
    ['r1' => $r1, 'r2' => $r2] = $f['releases'];
    $p = $f['persistent'];

    // Eight workers, half through each release, race for one reset token.
    $token = str_repeat('7', 64);
    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], $token, $now + 3600, $r1['generation'], $now),
        'case 7: issue one reset token through R1'
    );
    $barrier = $f['base'] . '/reset.start';
    $workers = [];
    for ($index = 0; $index < 8; $index++) {
        $password = "Case 7 concurrent reset {$index} 2026!";
        $workers[$password] = kssmi_persist_spawn('reset', $index < 4 ? $r1 : $r2, $password, $token, $barrier, $f['sessions']);
    }
    kssmi_persist_assert(file_put_contents($barrier, 'go') === 2, 'case 7: release the reset workers');
    $winners = [];
    foreach ($workers as $password => $worker) {
        $result = kssmi_persist_collect($worker, 'case 7 reset worker')['result'];
        kssmi_persist_assert(($result['ok'] ?? null) === true, 'case 7: every reset worker completes without a storage error');
        kssmi_persist_assert(
            ($result['changed'] ?? null) === ($result['consumed'] ?? null),
            'case 7: only the worker that consumes the token reports a change'
        );
        if ($result['changed'] === true) $winners[] = $password;
    }
    kssmi_persist_assert(count($winners) === 1, 'case 7: exactly one of eight cross-release workers consumes the token');
    kssmi_persist_assert(kssmi_persist_password_is($f, $winners[0]), 'case 7: the persistent hash belongs to the winner');
    kssmi_persist_assert_links_intact($f, 'case 7');
    foreach ($p as $kind => $path) {
        clearstatcache(true);
        kssmi_persist_assert(is_file($path . '.lock'), "case 7: the {$kind} lock file is created beside the persistent file");
    }

    // Concurrent changes leave exactly one submitted password and revoke
    // every earlier session.
    kssmi_persist_assert(kssmi_persist_login($r2, $winners[0], $now), 'case 7: log in before the concurrent changes');
    $oldSession = $_SESSION;
    $oldGeneration = kssmi_persist_generation($f);
    $barrier = $f['base'] . '/change.start';
    $workers = [];
    for ($index = 0; $index < 8; $index++) {
        $password = "Case 7 concurrent change {$index} 2026!";
        $workers[$password] = kssmi_persist_spawn('change', $index % 2 === 0 ? $r1 : $r2, $password, '-', $barrier, $f['sessions']);
    }
    kssmi_persist_assert(file_put_contents($barrier, 'go') === 2, 'case 7: release the change workers');
    foreach ($workers as $worker) {
        $result = kssmi_persist_collect($worker, 'case 7 change worker')['result'];
        kssmi_persist_assert(
            ($result['ok'] ?? null) === true && ($result['changed'] ?? null) === true,
            'case 7: every concurrent change completes in turn'
        );
    }
    $stored = array_values(array_filter(
        array_keys($workers),
        fn(string $password): bool => kssmi_persist_password_is($f, $password)
    ));
    kssmi_persist_assert(count($stored) === 1, 'case 7: the persistent hash is exactly one of the submitted passwords');
    $newGeneration = kssmi_persist_generation($f);
    kssmi_persist_assert(
        kssmi_admin_credential_generation_valid($newGeneration) && $newGeneration !== $oldGeneration,
        'case 7: the concurrent changes replace the generation'
    );
    foreach ($f['releases'] as $id => $release) {
        kssmi_persist_assert(
            !kssmi_persist_session_accepted($oldSession, $release, $now + 1),
            "case 7: the session from before the changes is rejected through {$id}"
        );
    }

    // Lock order. The test holds a persistent lock file directly while a
    // worker goes through a release link, so the worker waits on that same
    // file; what the worker already holds or wrote by then shows the order.
    $locks = [];
    foreach ($p as $kind => $path) $locks[$kind] = $path . '.lock';

    $held = kssmi_persist_hold($locks['generation']);
    $login = kssmi_persist_spawn('login', $r2, $stored[0], '-', '-', $f['sessions']);
    kssmi_persist_wait_until(
        fn(): bool => !kssmi_persist_lock_free($locks['tokens']),
        'case 7: login takes the token lock before the generation lock'
    );
    kssmi_persist_assert(kssmi_persist_lock_free($locks['password']), 'case 7: a waiting login holds no password lock');
    kssmi_persist_release($held);
    kssmi_persist_assert(kssmi_persist_collect($login, 'case 7 login worker')['result'] === true, 'case 7: the waiting login completes');

    $tokenStore = kssmi_persist_read($p['tokens']);
    $hash = kssmi_persist_read($p['password']);
    $generation = kssmi_persist_read($p['generation']);
    $held = kssmi_persist_hold($locks['generation']);
    $first = 'Case 7 ordered change one 2026!';
    $change = kssmi_persist_spawn('change', $r2, $first, '-', '-', $f['sessions']);
    kssmi_persist_wait_until(
        fn(): bool => !kssmi_persist_lock_free($locks['tokens']),
        'case 7: a change takes the token lock before the generation lock'
    );
    usleep(100000);
    kssmi_persist_assert(
        kssmi_persist_read($p['tokens']) === $tokenStore
            && kssmi_persist_read($p['password']) === $hash
            && kssmi_persist_read($p['generation']) === $generation,
        'case 7: a change waiting for the generation lock has changed nothing'
    );
    kssmi_persist_assert(kssmi_persist_lock_free($locks['password']), 'case 7: a change waiting for the generation lock holds no password lock');
    kssmi_persist_release($held);
    $result = kssmi_persist_collect($change, 'case 7 first ordered change')['result'];
    kssmi_persist_assert(
        $result['ok'] === true && $result['changed'] === true && kssmi_persist_password_is($f, $first),
        'case 7: the change completes once the generation lock is free'
    );

    kssmi_persist_assert(
        kssmi_admin_reset_token_add($r1['tokens'], str_repeat('8', 64), $now + 3600, $r1['generation'], $now),
        'case 7: issue a reset token for the change to revoke'
    );
    $hash = kssmi_persist_read($p['password']);
    $generation = kssmi_persist_read($p['generation']);
    $held = kssmi_persist_hold($locks['password']);
    $second = 'Case 7 ordered change two 2026!';
    $change = kssmi_persist_spawn('change', $r1, $second, '-', '-', $f['sessions']);
    kssmi_persist_wait_until(
        fn(): bool => kssmi_persist_read($p['generation']) !== $generation,
        'case 7: a change replaces the generation before it takes the password lock'
    );
    kssmi_persist_assert(!kssmi_persist_lock_free($locks['tokens']), 'case 7: the change keeps the token lock while it waits for the password lock');
    kssmi_persist_assert(kssmi_persist_tokens($f) === [], 'case 7: the change cleared the reset tokens first');
    kssmi_persist_assert(kssmi_persist_read($p['password']) === $hash, 'case 7: the password is written only under its lock');
    kssmi_persist_release($held);
    $result = kssmi_persist_collect($change, 'case 7 second ordered change')['result'];
    kssmi_persist_assert(
        $result['ok'] === true && $result['changed'] === true && kssmi_persist_password_is($f, $second),
        'case 7: the change completes once the password lock is free'
    );
    kssmi_persist_assert_links_intact($f, 'case 7');
}

function kssmi_persist_case_self_lock(array $f): void {
    $p = $f['persistent'];
    $configurations = [
        // Another spelling of the same file needs no link.
        'generation path spelled as the token store' => [
            'password' => $p['password'],
            'tokens' => $p['tokens'],
            'generation' => dirname($p['tokens']) . '/./' . basename($p['tokens']),
        ],
    ];
    if (KSSMI_PERSIST_LINKS) {
        $index = 0;
        foreach ([
            'generation link to the token store' => ['generation' => 'tokens'],
            'password link to the token store' => ['password' => 'tokens'],
            'generation link to the password file' => ['generation' => 'password'],
        ] as $label => $retarget) {
            $dir = $f['private'] . '/releases/self-lock-' . ++$index;
            kssmi_persist_assert(mkdir($dir, 0751), "case 8: create the release with a {$label}");
            $paths = [];
            foreach (KSSMI_PERSIST_KINDS as $kind) {
                $paths[$kind] = $dir . '/' . basename($p[$kind]);
                kssmi_persist_assert(symlink($p[$retarget[$kind] ?? $kind], $paths[$kind]), "case 8: link the release with a {$label}");
            }
            $configurations[$label] = $paths;
        }
    }

    $before = kssmi_persist_snapshot($f['private']);
    foreach ($configurations as $label => $paths) {
        $login = kssmi_persist_spawn('login', $paths, KSSMI_PERSIST_PASSWORD, '-', '-', $f['sessions']);
        kssmi_persist_assert(
            kssmi_persist_collect($login, "case 8 login with a {$label}", 10.0)['result'] === false,
            "case 8: login fails with a {$label}"
        );
        $change = kssmi_persist_spawn('change', $paths, 'Case 8 must not be stored 2026!', '-', '-', $f['sessions']);
        $result = kssmi_persist_collect($change, "case 8 change with a {$label}", 10.0)['result'];
        kssmi_persist_assert(
            $result['ok'] === false && $result['changed'] === false && $result['error'] === 'credential_paths_not_distinct',
            "case 8: a password change fails with a {$label}"
        );
    }
    kssmi_persist_assert(kssmi_persist_snapshot($f['private']) === $before, 'case 8: the persistent files are unchanged');
}

function kssmi_persist_case_legacy_credentials(array $f, int $now): void {
    ['r1' => $r1] = $f['releases'];
    $legacySession = [
        'email_logs_auth' => true,
        'credential_version' => 1,
        'admin_authenticated_at' => $now,
        'admin_last_seen_at' => $now,
        'csrf_token' => str_repeat('c', 64),
    ];
    kssmi_persist_assert(
        !kssmi_persist_session_accepted($legacySession, $r1, $now + 1),
        'case 9: a session holding an integer credential_version is rejected'
    );
    kssmi_persist_assert(!array_key_exists('credential_version', $_SESSION), 'case 9: the rejected session loses the legacy key');
    kssmi_persist_assert(
        !kssmi_persist_session_accepted(['credential_generation' => 1] + $legacySession, $r1, $now + 1),
        'case 9: a session holding an integer generation is rejected'
    );

    $hash = kssmi_persist_read($f['persistent']['password']);
    $generation = kssmi_persist_generation($f);
    $legacyToken = str_repeat('9', 64);
    foreach ([
        'an integer credential_version' => ['credential_version' => 1],
        'no generation' => [],
        'a malformed generation' => ['credential_generation' => substr($generation, 0, 31)],
    ] as $label => $fields) {
        kssmi_persist_put($f['persistent']['tokens'], (string)json_encode([
            $legacyToken => ['created' => $now, 'expires' => $now + 3600] + $fields,
        ]));
        $before = kssmi_persist_tree($f);
        kssmi_persist_assert(
            !kssmi_admin_reset_token_valid($r1['tokens'], $legacyToken, $r1['generation'], $now),
            "case 9: a token with {$label} is invalid"
        );
        $reset = kssmi_admin_reset_password($r1['tokens'], $r1['password'], $legacyToken, 'Case 9 must not be stored 2026!', $now, $r1['generation']);
        kssmi_persist_assert(
            $reset['ok'] === false && $reset['changed'] === false && $reset['consumed'] === false
                && $reset['error'] === 'invalid_schema',
            "case 9: a token with {$label} cannot reset the password"
        );
        // The store stays strict: no new token is issued beside an old one
        // until the deploy clears it.
        kssmi_persist_assert(
            !kssmi_admin_reset_token_add($r1['tokens'], str_repeat('a', 64), $now + 3600, $r1['generation'], $now),
            "case 9: no reset token is issued beside a token with {$label}"
        );
        kssmi_persist_assert(kssmi_persist_tree($f) === $before, "case 9: a token with {$label} changes nothing");
    }
    kssmi_persist_assert(
        kssmi_persist_read($f['persistent']['password']) === $hash && kssmi_persist_generation($f) === $generation,
        'case 9: the password hash and generation are unchanged'
    );
}

// ── Main ────────────────────────────────────────────────────────────────────

if (($argv[1] ?? '') === 'worker') {
    exit(kssmi_persist_worker(array_slice($argv, 2)));
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Refusing to run as root: root ignores the directory permissions these cases rely on.\n");
    exit(1);
}
kssmi_persist_assert(function_exists('proc_open'), 'proc_open is required for the concurrency cases');

$root = sys_get_temp_dir() . '/kssmi-credential-persistence-' . bin2hex(random_bytes(6));
kssmi_persist_assert(mkdir($root, 0700), 'create the test directory');
$GLOBALS['kssmi_persist_workers'] = [];
register_shutdown_function(static function () use ($root): void {
    foreach ($GLOBALS['kssmi_persist_workers'] as $worker) proc_terminate($worker['process'], 9);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    kssmi_persist_remove_tree($root);
});

kssmi_persist_put($root . '/link-probe-target', 'probe');
$linksSupported = @symlink($root . '/link-probe-target', $root . '/link-probe') && is_link($root . '/link-probe');
kssmi_persist_assert($linksSupported || PHP_OS_FAMILY === 'Windows', 'symbolic links are required on this platform');
define('KSSMI_PERSIST_ROOT', $root);
define('KSSMI_PERSIST_LINKS', $linksSupported);

kssmi_persist_assert(mkdir($root . '/sessions', 0700), 'create the session directory');
session_save_path($root . '/sessions');
session_name('KSSMI_PERSIST_TEST');
kssmi_admin_session_bootstrap();
kssmi_persist_assert(session_status() === PHP_SESSION_ACTIVE, 'start the admin session');

$now = time();
$skipped = [];
if (KSSMI_PERSIST_LINKS) {
    kssmi_persist_case_unchanged_password(kssmi_persist_fixture('case1'), $now);
    kssmi_persist_case_change_through_r1(kssmi_persist_fixture('case2'), $now);
    kssmi_persist_case_reset_through_r2(kssmi_persist_fixture('case3'), $now);
    kssmi_persist_case_lost_generation(kssmi_persist_fixture('case4-lost'), $now);
} else {
    $skipped[] = 'cases 1-3 and the lost-generation part of case 4 (symbolic links unavailable)';
}
kssmi_persist_case_corrupt_generation(kssmi_persist_fixture('case4-corrupt'), $now);
if (KSSMI_PERSIST_LINKS) {
    kssmi_persist_case_abnormal_links(kssmi_persist_fixture('case5'));
} else {
    $skipped[] = 'case 5 (symbolic links unavailable)';
}
if (KSSMI_PERSIST_LINKS && PHP_OS_FAMILY !== 'Windows') {
    kssmi_persist_case_read_only_release(kssmi_persist_fixture('case6'), $now);
} else {
    $skipped[] = 'case 6 (needs symbolic links and POSIX directory permissions)';
}
kssmi_persist_case_concurrency(kssmi_persist_fixture('case7', false), $now);
kssmi_persist_case_self_lock(kssmi_persist_fixture('case8'));
kssmi_persist_case_legacy_credentials(kssmi_persist_fixture('case9'), $now);

$_SESSION = [];
fwrite(STDOUT, "Credential persistence tests passed.\n");
if ($skipped !== []) {
    fwrite(STDOUT, 'Skipped on this host: ' . implode('; ', $skipped) . ".\n");
}
