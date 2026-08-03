<?php
declare(strict_types=1);

/**
 * One-shot Kssmi LSAPI capability probe.
 *
 * deploy-release.sh installs this file temporarily into the current webroot,
 * calls it only through the loopback OpenLiteSpeed listener, validates the
 * result, and removes it before activation. It never prints secret contents.
 */

function kssmi_probe_identity(): array
{
    if (function_exists('posix_geteuid') && function_exists('posix_getegid')) {
        return [(int) posix_geteuid(), (int) posix_getegid()];
    }

    $status = @file_get_contents('/proc/self/status');
    if (is_string($status)
        && preg_match('/^Uid:\s+(\d+)/m', $status, $uidMatch) === 1
        && preg_match('/^Gid:\s+(\d+)/m', $status, $gidMatch) === 1
    ) {
        return [(int) $uidMatch[1], (int) $gidMatch[1]];
    }

    throw new RuntimeException('Unable to determine the LSAPI effective UID/GID.');
}

function kssmi_probe_require_readable(array $paths): void
{
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Required private runtime file is not readable.');
        }
    }
}

function kssmi_probe_atomic_write(string $directory, string $label): void
{
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException($label . ' directory is not writable.');
    }
    if (!function_exists('fsync')) {
        throw new RuntimeException('PHP fsync() is required for the atomic-write capability.');
    }

    $suffix = bin2hex(random_bytes(12));
    $temporary = $directory . '/.kssmi-capability-' . $suffix . '.tmp';
    $moved = $directory . '/.kssmi-capability-' . $suffix . '.moved';
    $handle = null;
    $oldUmask = umask(0077);
    try {
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException($label . ' create failed.');
        }
        if (!@chmod($temporary, 0600)) {
            throw new RuntimeException($label . ' chmod failed.');
        }
        if (fwrite($handle, "probe\n") === false || !fflush($handle) || !fsync($handle)) {
            throw new RuntimeException($label . ' fsync failed.');
        }
        fclose($handle);
        $handle = null;
        if (!@rename($temporary, $moved)) {
            throw new RuntimeException($label . ' atomic rename failed.');
        }
        if (!@unlink($moved)) {
            throw new RuntimeException($label . ' cleanup failed.');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        @unlink($temporary);
        @unlink($moved);
        umask($oldUmask);
    }
}

function kssmi_probe_mode_is_0600(string $path): bool
{
    clearstatcache(true, $path);
    $permissions = @fileperms($path);
    return is_int($permissions) && (($permissions & 0777) === 0600);
}

function kssmi_probe_sqlite(string $databasePath): void
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('pdo_sqlite is not loaded by LSAPI.');
    }
    if (!is_file($databasePath) || !is_readable($databasePath) || !is_writable($databasePath)) {
        throw new RuntimeException('VJT SQLite database is not readable and writable.');
    }
    if (!kssmi_probe_mode_is_0600($databasePath)) {
        throw new RuntimeException('VJT SQLite database mode is not 0600.');
    }

    $table = '__kssmi_capability_' . bin2hex(random_bytes(8));
    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
    if ($db->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('VJT SQLite integrity_check failed.');
    }

    try {
        $db->beginTransaction();
        $db->exec('CREATE TABLE "' . $table . '" (value INTEGER NOT NULL)');
        $db->exec('INSERT INTO "' . $table . '" (value) VALUES (1)');
        $db->rollBack();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $statement = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $statement->execute([$table]);
    if ((int) $statement->fetchColumn() !== 0) {
        throw new RuntimeException('VJT SQLite rollback left probe data behind.');
    }
    $db = null;

    foreach ([$databasePath . '-wal', $databasePath . '-shm'] as $sidecar) {
        if (file_exists($sidecar) && !kssmi_probe_mode_is_0600($sidecar)) {
            throw new RuntimeException('VJT SQLite sidecar mode is not 0600.');
        }
    }
}

function kssmi_probe_run(array $config): array
{
    [$uid, $gid] = kssmi_probe_identity();
    $results = [
        'uid' => (string) $uid,
        'gid' => (string) $gid,
        'private_modules_read' => 'FAIL',
        'password_hash_read' => 'FAIL',
        'gsc_read_if_present' => 'FAIL',
        'email_atomic_write' => 'FAIL',
        'rate_limit_atomic_write' => 'FAIL',
        'sqlite_transaction_rollback' => 'FAIL',
        'sqlite_wal_shm_modes' => 'FAIL',
    ];

    try {
        kssmi_probe_require_readable($config['private_files']);
        $results['private_modules_read'] = 'PASS';

        kssmi_probe_require_readable([$config['password_file']]);
        $results['password_hash_read'] = 'PASS';

        if (!file_exists($config['gsc_json']) || is_readable($config['gsc_json'])) {
            $results['gsc_read_if_present'] = 'PASS';
        } else {
            throw new RuntimeException('GSC credential exists but is not readable.');
        }

        kssmi_probe_atomic_write($config['email_directory'], 'Email');
        $results['email_atomic_write'] = 'PASS';
        kssmi_probe_atomic_write($config['rate_limit_directory'], 'Rate-limit');
        $results['rate_limit_atomic_write'] = 'PASS';

        kssmi_probe_sqlite($config['sqlite_database']);
        $results['sqlite_transaction_rollback'] = 'PASS';
        $results['sqlite_wal_shm_modes'] = 'PASS';
    } catch (Throwable $error) {
        $results['error'] = get_class($error) . ': ' . $error->getMessage();
    }

    return $results;
}

function kssmi_probe_environment_config(string $privateRoot): array
{
    return [
        'private_files' => [
            $privateRoot . '/private_config.php',
            $privateRoot . '/private/http-security.php',
            $privateRoot . '/private/rate-limit.php',
            $privateRoot . '/private/email-log-store.php',
            $privateRoot . '/private/cloudflare-ip-ranges.json',
        ],
        'password_file' => $privateRoot . '/.email_logs_password',
        'gsc_json' => $privateRoot . '/private/gsc/google-service-account.json',
        'email_directory' => $privateRoot . '/email_data',
        'rate_limit_directory' => $privateRoot . '/rate_limit',
        'sqlite_database' => $privateRoot . '/vjt_data/vjt.sqlite',
    ];
}

function kssmi_probe_emit(array $results): void
{
    echo "KSSMI_RUNTIME_CAPABILITY_V1\n";
    foreach ($results as $name => $value) {
        echo $name . '=' . str_replace(["\r", "\n"], ' ', (string) $value) . "\n";
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--identity-self-test') {
    [$uid, $gid] = kssmi_probe_identity();
    if ($uid < 0 || $gid < 0) {
        fwrite(STDERR, "Runtime identity self-test failed.\n");
        exit(1);
    }
    echo "Runtime identity parser self-test passed.\n";
    exit(0);
}

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This probe may only run through LSAPI; use --identity-self-test in CI.\n");
    exit(2);
}

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$expectedRelease = basename(__FILE__, '.php');
$expectedRelease = preg_replace('/^kssmi-runtime-capability-/', '', $expectedRelease);
$providedRelease = $_SERVER['HTTP_X_KSSMI_RUNTIME_PROBE'] ?? '';
$privateRoot = $_SERVER['HTTP_X_KSSMI_PRIVATE_ROOT'] ?? '';
if (($remoteAddress !== '127.0.0.1' && $remoteAddress !== '::1')
    || !is_string($expectedRelease)
    || !hash_equals($expectedRelease, $providedRelease)
    || !is_string($privateRoot)
    || preg_match('#^/home/[A-Za-z0-9._-]+$#', $privateRoot) !== 1
    || $privateRoot === '/home/.'
    || $privateRoot === '/home/..'
) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$results = kssmi_probe_run(kssmi_probe_environment_config($privateRoot));
$failed = in_array('FAIL', $results, true);
http_response_code($failed ? 500 : 200);
kssmi_probe_emit($results);
