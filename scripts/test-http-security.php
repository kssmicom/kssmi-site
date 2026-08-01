<?php
declare(strict_types=1);

/**
 * HTTP security module unit tests (优化-001 阶段 3 步骤 2).
 *
 * Verifies the pure utility functions in private/http-security.php:
 *   - scalar/text sanitization bounds;
 *   - array_is_list polyfill behavior;
 *   - atomic write (temp + fsync + rename, no partial destination);
 *   - shared/exclusive file lock acquisition and release;
 *   - secret read/write semantics (missing/empty → null, write 0600).
 *
 * Run: php scripts/test-http-security.php
 */

function kssmi_sec_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_sec_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_sec_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-http-security-' . bin2hex(random_bytes(6));
kssmi_sec_assert(mkdir($testDirectory, 0700, true), 'create test directory');

try {
    require_once dirname(__DIR__) . '/private/http-security.php';

    // ── scalar_text sanitization ──
    kssmi_sec_assert(kssmi_scalar_text('  hello world  ', 20) === 'hello world', 'scalar_text trims and bounds');
    kssmi_sec_assert(kssmi_scalar_text('hello world', 5) === 'hello', 'scalar_text truncates to max');
    kssmi_sec_assert(kssmi_scalar_text(42, 5) === '42', 'scalar_text accepts int');
    kssmi_sec_assert(kssmi_scalar_text(['x'], 5) === '', 'scalar_text rejects array');
    kssmi_sec_assert(kssmi_scalar_text('abc', -1) === '', 'scalar_text rejects negative max');

    // ── array_is_list polyfill ──
    kssmi_sec_assert(kssmi_array_is_list([1, 2, 3]) === true, 'sequential list is a list');
    kssmi_sec_assert(kssmi_array_is_list(['a' => 1]) === false, 'associative array is not a list');
    kssmi_sec_assert(kssmi_array_is_list([0 => 'a', 2 => 'b']) === false, 'gapped keys are not a list');
    kssmi_sec_assert(kssmi_array_is_list('nope') === false, 'non-array is not a list');

    // ── atomic write: no partial destination, correct mode ──
    $secretPath = $testDirectory . DIRECTORY_SEPARATOR . 'secret.txt';
    kssmi_sec_assert(kssmi_admin_atomic_write($secretPath, 'first-secret', 0600), 'atomic write succeeds');
    kssmi_sec_assert(file_get_contents($secretPath) === 'first-secret', 'atomic write content stored');
    if (PHP_OS_FAMILY !== 'Windows') {
        kssmi_sec_assert((fileperms($secretPath) & 0777) === 0600, 'atomic write mode is 0600');
    }
    kssmi_sec_assert(kssmi_admin_atomic_write($secretPath, 'second-longer-secret-value', 0600), 'atomic overwrite succeeds');
    kssmi_sec_assert(file_get_contents($secretPath) === 'second-longer-secret-value', 'atomic overwrite replaces content');
    $leftoverTemps = glob($secretPath . '.tmp-*');
    kssmi_sec_assert($leftoverTemps === false || count($leftoverTemps) === 0, 'no leftover temp files after write');

    // ── file lock: exclusive then shared, released cleanly ──
    $lock = kssmi_admin_file_lock($secretPath, LOCK_EX);
    kssmi_sec_assert($lock['ok'] === true, 'exclusive lock acquired');
    kssmi_sec_assert(is_resource($lock['handle'] ?? null), 'lock handle is a resource');
    kssmi_admin_file_unlock($lock);
    $shared = kssmi_admin_file_lock($secretPath, LOCK_SH);
    kssmi_sec_assert($shared['ok'] === true, 'shared lock acquired after release');
    kssmi_admin_file_unlock($shared);
    kssmi_sec_assert(
        (kssmi_admin_file_lock($testDirectory . '/missing-dir/no-such-file', LOCK_EX))['ok'] === false,
        'lock on missing parent dir fails cleanly'
    );

    // ── secret read/write semantics ──
    kssmi_sec_assert(kssmi_admin_secret_read($testDirectory . '/does-not-exist') === null, 'missing secret reads null');
    $emptyPath = $testDirectory . DIRECTORY_SEPARATOR . 'empty.txt';
    file_put_contents($emptyPath, "   \n");
    kssmi_sec_assert(kssmi_admin_secret_read($emptyPath) === null, 'whitespace-only secret reads null');
    kssmi_sec_assert(kssmi_admin_secret_read($secretPath) === 'second-longer-secret-value', 'secret read returns trimmed content');
    kssmi_sec_assert(kssmi_admin_secret_write($secretPath, '  new-secret  ') === true, 'secret write succeeds');
    kssmi_sec_assert(kssmi_admin_secret_read($secretPath) === 'new-secret', 'secret write trims on read');
    if (PHP_OS_FAMILY !== 'Windows') {
        kssmi_sec_assert((fileperms($secretPath) & 0777) === 0600, 'secret write keeps 0600');
    }
    kssmi_sec_assert(kssmi_admin_secret_write($secretPath, '') === false, 'empty secret write rejected');

    fwrite(STDOUT, "HTTP security module tests passed.\n");
} finally {
    kssmi_sec_remove_tree($testDirectory);
}
