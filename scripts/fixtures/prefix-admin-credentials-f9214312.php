<?php
declare(strict_types=1);

// KS-05 test fixture, never deployed: the admin credential functions of the
// pre-fix code, so scripts/test-deploy-credential-transition.sh can change or
// reset the password as the pre-fix release does, including a change that is
// in flight while a deployment freezes the release root. It is not part of
// the release bundle (the tar list in deploy.yml and the upload source of the
// deploy-environment action).
//
// Each KS05-COPY block is copied verbatim from the named lines of
// f9214312:private/http-security.php, with every "kssmi_admin_" renamed to
// "kssmi_prefix_admin_" so that it loads beside the current code. The only
// addition is the KS05-PAUSE block in the copied atomic write. With the rename
// reverted and that block removed, the blocks hash to the SHA-256 of
// `git show f9214312:private/http-security.php | sed -n '211,344p;570,831p'`:
// KS05-SOURCE-SHA256: 38c4759e434f1ec9b85d5b4422aa0bb19b69abd45ebc2dcc471681295806c045

// KS05-COPY-BEGIN f9214312:private/http-security.php:211-344
/**
 * Write every byte to the handle; returns false on short/zero write.
 */
function kssmi_prefix_admin_write_all($handle, string $contents): bool {
    $length = strlen($contents);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) return false;
        $offset += $written;
    }
    return fflush($handle);
}

/**
 * Atomic file write: temp file in the same directory, chmod before any
 * content is written, fsync, then rename into place. Never leaves a partial
 * destination file.
 */
function kssmi_prefix_admin_atomic_write(string $path, string $contents, int $mode = 0600): bool {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) return false;

    try {
        $tempPath = $path . '.tmp-' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return false;
    }

    $handle = @fopen($tempPath, 'xb');
    if ($handle === false) return false;
    if (!@chmod($tempPath, $mode)) {
        fclose($handle);
        @unlink($tempPath);
        return false;
    }

    $written = kssmi_prefix_admin_write_all($handle, $contents);
    if ($written && function_exists('fsync')) $written = @fsync($handle);
    fclose($handle);
    // KS05-PAUSE-BEGIN: test-only pause after fsync and before rename.
    // KSSMI_PREFIX_PAUSE_FILE names a control file that holds the basename of
    // the write to pause. When it matches, the temporary path is written to
    // "<control>.paused", and the write waits up to 10 seconds for
    // "<control>.continue" before it renames.
    $pauseControl = getenv('KSSMI_PREFIX_PAUSE_FILE');
    if (is_string($pauseControl) && $pauseControl !== '' && is_file($pauseControl)
        && trim((string)file_get_contents($pauseControl)) === basename($path)) {
        file_put_contents($pauseControl . '.paused', $tempPath);
        $pauseDeadline = microtime(true) + 10;
        while (!is_file($pauseControl . '.continue') && microtime(true) < $pauseDeadline) {
            usleep(20000);
        }
    }
    // KS05-PAUSE-END
    if (!$written || !@rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }
    @chmod($path, $mode);
    return true;
}

/**
 * Acquire an exclusive (LOCK_EX) or shared (LOCK_SH) flock on a stable
 * sidecar lock file. Returns ['ok' => bool, 'handle' => resource|null,
 * 'error' => string|null].
 */
function kssmi_prefix_admin_file_lock(string $path, int $operation): array {
    $parent = dirname($path);
    if (!is_dir($parent) || !is_writable($parent)) {
        return ['ok' => false, 'error' => 'parent_not_writable'];
    }
    $handle = @fopen($path . '.lock', 'c+b');
    if ($handle === false) return ['ok' => false, 'error' => 'lock_open_failed'];
    @chmod($path . '.lock', 0600);
    if (!flock($handle, $operation)) {
        fclose($handle);
        return ['ok' => false, 'error' => 'lock_failed'];
    }
    return ['ok' => true, 'handle' => $handle, 'error' => null];
}

/**
 * Release a lock acquired by kssmi_prefix_admin_file_lock.
 */
function kssmi_prefix_admin_file_unlock(array $lock): void {
    if (!is_resource($lock['handle'] ?? null)) return;
    flock($lock['handle'], LOCK_UN);
    fclose($lock['handle']);
}

/**
 * Read a secret file (password hash / reset tokens) under a shared lock.
 * Missing or empty file → null, matching the backends' current getPasswordHash
 * fallback semantics (they treat an absent hash as "no password set").
 */
function kssmi_prefix_admin_secret_read(string $path): ?string {
    $lock = kssmi_prefix_admin_file_lock($path, LOCK_SH);
    if (!$lock['ok']) return null;
    try {
        if (!is_file($path) || !is_readable($path)) return null;
        $contents = @file_get_contents($path);
        if (!is_string($contents)) return null;
        $contents = trim($contents);
        return $contents === '' ? null : $contents;
    } finally {
        kssmi_prefix_admin_file_unlock($lock);
    }
}

/**
 * Write a secret file atomically (0600) under an exclusive lock.
 * Empty contents are rejected (matching the "missing hash disables login"
 * semantics of the current backends).
 */
function kssmi_prefix_admin_secret_write(string $path, string $contents): bool {
    if ($contents === '') return false;
    $lock = kssmi_prefix_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok']) return false;
    try {
        return kssmi_prefix_admin_atomic_write($path, $contents, 0600);
    } finally {
        kssmi_prefix_admin_file_unlock($lock);
    }
}

// ── Credential versioned admin sessions ─────────────────────────────────────
// A password change advances this server-owned value. Every pre-change
// session then fails closed, even though PHP's file-backed sessions cannot be
// enumerated and deleted individually.

function kssmi_prefix_admin_credential_version_path(string $passwordPath): string {
    return dirname($passwordPath) . '/.admin_credential_version';
}

function kssmi_prefix_admin_credential_version_read(string $path): ?int {
    $value = kssmi_prefix_admin_secret_read($path);
    if (!is_string($value) || preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) return null;
    $version = (int)$value;
    return $version > 0 ? $version : null;
}

function kssmi_prefix_admin_credential_version_ensure(string $path): ?int {
    $version = kssmi_prefix_admin_credential_version_read($path);
    if ($version !== null) return $version;
    if (file_exists($path) || !kssmi_prefix_admin_secret_write($path, '1')) return null;
    return kssmi_prefix_admin_credential_version_read($path);
}
// KS05-COPY-END

// KS05-COPY-BEGIN f9214312:private/http-security.php:570-831
// ── Reset token atomic consumption (阶段 3 步骤 5) ────────────────────────
// The reset-token store (.email_reset_tokens.json) must be read-modified-
// written under an exclusive lock so concurrent clicks on the emailed reset
// link can only ever consume the token once (replay protection). The token
// file shape is { "<64-hex>": { "created": int, "expires": int }, ... }.

function kssmi_prefix_admin_reset_tokens_decode($raw, &$error): ?array {
    $error = null;
    if (!is_string($raw) || trim($raw) === '') {
        // An empty/whitespace store is a valid "no tokens yet" state
        // (deploy-release.sh touch-creates the file empty on activation).
        return [];
    }
    $tokens = json_decode($raw, true);
    if (!is_array($tokens) || json_last_error() !== JSON_ERROR_NONE) {
        $error = 'invalid_json';
        return null;
    }
    foreach ($tokens as $token => $data) {
        if (
            !is_string($token) ||
            preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 ||
            !is_array($data) ||
            !is_numeric($data['expires'] ?? null) ||
            (array_key_exists('credential_version', $data)
                && (!is_int($data['credential_version']) || $data['credential_version'] < 1))
        ) {
            $error = 'invalid_schema';
            return null;
        }
    }
    return $tokens;
}

function kssmi_prefix_admin_reset_tokens_read(string $path, ?int $now = null): array {
    $lock = kssmi_prefix_admin_file_lock($path, LOCK_SH);
    if (!$lock['ok']) return ['ok' => false, 'tokens' => [], 'error' => $lock['error']];
    try {
        if (!file_exists($path)) return ['ok' => true, 'tokens' => [], 'error' => null];
        $raw = @file_get_contents($path);
        $decodeError = null;
        $tokens = kssmi_prefix_admin_reset_tokens_decode($raw, $decodeError);
        if ($decodeError !== null) {
            return ['ok' => false, 'tokens' => [], 'error' => $decodeError];
        }
        $current = $now ?? time();
        $tokens = array_filter(
            $tokens,
            fn($data) => (int)($data['expires'] ?? 0) > $current
        );
        return ['ok' => true, 'tokens' => $tokens, 'error' => null];
    } finally {
        kssmi_prefix_admin_file_unlock($lock);
    }
}

function kssmi_prefix_admin_reset_tokens_mutate(string $path, callable $mutator): array {
    $lock = kssmi_prefix_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok']) return ['ok' => false, 'tokens' => [], 'error' => $lock['error']];
    try {
        $raw = file_exists($path) ? @file_get_contents($path) : '[]';
        $decodeError = null;
        $tokens = kssmi_prefix_admin_reset_tokens_decode($raw, $decodeError);
        if ($decodeError !== null) {
            return ['ok' => false, 'tokens' => [], 'error' => $decodeError];
        }
        try {
            $tokens = $mutator($tokens);
        } catch (Throwable $e) {
            return ['ok' => false, 'tokens' => [], 'error' => 'mutation_failed'];
        }
        if (!is_array($tokens)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'mutation_invalid'];
        }
        $encoded = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'encode_failed'];
        }
        if (!kssmi_prefix_admin_atomic_write($path, $encoded, 0600)) {
            return ['ok' => false, 'tokens' => [], 'error' => 'write_failed'];
        }
        return ['ok' => true, 'tokens' => $tokens, 'error' => null];
    } finally {
        kssmi_prefix_admin_file_unlock($lock);
    }
}

function kssmi_prefix_admin_reset_token_add(string $path, string $token, int $expires, ?int $now = null, ?string $credentialVersionPath = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $current = $now ?? time();
    if ($expires <= $current) return false;
    $credentialVersion = 1;
    if ($credentialVersionPath !== null) {
        $credentialVersion = kssmi_prefix_admin_credential_version_ensure($credentialVersionPath);
        if ($credentialVersion === null) return false;
    }
    $result = kssmi_prefix_admin_reset_tokens_mutate(
        $path,
        function(array $tokens) use ($token, $expires, $current, $credentialVersion): array {
            // A reset email is a credential issuance event, not a collection
            // of parallel credentials. Replacing the complete set while the
            // store lock is held atomically revokes any previous active link.
            return [
                $token => [
                    'created' => $current,
                    'expires' => $expires,
                    'credential_version' => $credentialVersion,
                ],
            ];
        }
    );
    return $result['ok'];
}

function kssmi_prefix_admin_reset_token_valid(string $path, string $token, ?int $now = null, ?string $credentialVersionPath = null): bool {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) return false;
    $result = kssmi_prefix_admin_reset_tokens_read($path, $now);
    if (!$result['ok'] || !isset($result['tokens'][$token])) return false;
    if ($credentialVersionPath === null) return true;
    $credentialVersion = kssmi_prefix_admin_credential_version_read($credentialVersionPath);
    $tokenVersion = (int)($result['tokens'][$token]['credential_version'] ?? 1);
    return $credentialVersion !== null && $tokenVersion === $credentialVersion;
}

/**
 * Atomically remove a reset token (and expired tokens) under an exclusive
 * lock. Returns ['ok' => bool, 'consumed' => bool, 'error' => ?string].
 * Replay of an already-consumed token returns consumed=false, never true.
 */
function kssmi_prefix_admin_reset_token_consume(string $path, string $token, ?int $now = null): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['ok' => true, 'consumed' => false, 'error' => null];
    }
    $current = $now ?? time();
    $consumed = false;
    $result = kssmi_prefix_admin_reset_tokens_mutate(
        $path,
        function(array $tokens) use ($token, $current, &$consumed): array {
            foreach ($tokens as $storedToken => $data) {
                if ((int)($data['expires'] ?? 0) <= $current) {
                    unset($tokens[$storedToken]);
                }
            }
            if (isset($tokens[$token])) {
                unset($tokens[$token]);
                $consumed = true;
            }
            return $tokens;
        }
    );
    return [
        'ok' => $result['ok'],
        'consumed' => $result['ok'] && $consumed,
        'error' => $result['error'] ?? null,
    ];
}

/**
 * Rotate password, reset credentials, and the session-revocation version
 * under the reset-token store's exclusive lock. The security-safe order is
 * token revocation, version advance, then password replacement: a filesystem
 * failure can require a new reset, but cannot leave a sibling reset token or
 * old authenticated session usable after a successful password change.
 *
 * Returns ['ok' => bool, 'changed' => bool, 'consumed' => bool,
 *          'error' => ?string]. A replay is ok=true/changed=false.
 */
function kssmi_prefix_admin_rotate_credentials(
    string $tokensPath,
    string $passwordPath,
    string $newPassword,
    ?string $token = null,
    ?string $credentialVersionPath = null,
    ?int $now = null
): array {
    if ($newPassword === '') {
        return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'empty_password'];
    }
    if ($token !== null && preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return ['ok' => true, 'changed' => false, 'consumed' => false, 'error' => null];
    }
    $lock = kssmi_prefix_admin_file_lock($tokensPath, LOCK_EX);
    if (!$lock['ok']) return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => $lock['error']];
    try {
        $raw = file_exists($tokensPath) ? @file_get_contents($tokensPath) : '[]';
        $decodeError = null;
        $tokens = kssmi_prefix_admin_reset_tokens_decode($raw, $decodeError);
        if ($tokens === null) return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => $decodeError];
        $current = $now ?? time();
        foreach ($tokens as $storedToken => $data) {
            if ((int)($data['expires'] ?? 0) <= $current) unset($tokens[$storedToken]);
        }
        $versionPath = $credentialVersionPath ?? kssmi_prefix_admin_credential_version_path($passwordPath);
        $version = kssmi_prefix_admin_credential_version_ensure($versionPath);
        if ($version === null) {
            // The credential version store is unavailable (for example when
            // the password file's parent directory is missing). Fail closed:
            // a presented token is burned so the failed attempt can never be
            // replayed, and no password change is ever reported.
            if ($token !== null && isset($tokens[$token])) {
                if (!kssmi_prefix_admin_atomic_write($tokensPath, '[]', 0600)) {
                    return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'token_revoke_failed'];
                }
                return ['ok' => false, 'changed' => false, 'consumed' => true, 'error' => 'version_read_failed'];
            }
            return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'version_read_failed'];
        }
        if ($token !== null && (!isset($tokens[$token])
            || (int)($tokens[$token]['credential_version'] ?? 1) !== $version)) {
            return ['ok' => true, 'changed' => false, 'consumed' => false, 'error' => null];
        }

        // Clear every active reset credential first. If a later file write
        // fails, the operation may require a fresh reset but never leaves a
        // sibling token capable of replacing the password.
        if (!kssmi_prefix_admin_atomic_write($tokensPath, '[]', 0600)) {
            return ['ok' => false, 'changed' => false, 'consumed' => false, 'error' => 'token_revoke_failed'];
        }
        // Password hashing is intentionally deferred until this request owns
        // the reset token. Concurrent replays return above without spending
        // CPU on attacker-chosen password input.
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'password_hash_failed'];
        }
        if ($version >= 2147483647
            || !kssmi_prefix_admin_secret_write($versionPath, (string)($version + 1))) {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'version_write_failed'];
        }
        if (!kssmi_prefix_admin_secret_write($passwordPath, $passwordHash)) {
            return ['ok' => false, 'changed' => false, 'consumed' => $token !== null, 'error' => 'password_write_failed'];
        }
        return ['ok' => true, 'changed' => true, 'consumed' => $token !== null, 'error' => null];
    } finally {
        kssmi_prefix_admin_file_unlock($lock);
    }
}

function kssmi_prefix_admin_change_password(
    string $tokensPath,
    string $passwordPath,
    string $newPassword,
    ?string $credentialVersionPath = null,
    ?int $now = null
): array {
    return kssmi_prefix_admin_rotate_credentials(
        $tokensPath, $passwordPath, $newPassword, null, $credentialVersionPath, $now
    );
}

function kssmi_prefix_admin_reset_password(
    string $tokensPath,
    string $passwordPath,
    string $token,
    string $newPassword,
    ?int $now = null,
    ?string $credentialVersionPath = null
): array {
    return kssmi_prefix_admin_rotate_credentials(
        $tokensPath, $passwordPath, $newPassword, $token, $credentialVersionPath, $now
    );
}
// KS05-COPY-END

// Test driver, outside the copied blocks. The paths are those of the pre-fix
// page (public/email-logs.php at f9214312): the password hash, the reset
// tokens and the version counter in the release root.
//   php prefix-admin-credentials-f9214312.php change <release-root> <new-password>
//   php prefix-admin-credentials-f9214312.php reset <release-root> <new-password> <token>
// The rotation result is printed as JSON.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $operation = $argv[1] ?? '';
    $releaseRoot = $argv[2] ?? '';
    $newPassword = $argv[3] ?? '';
    $passwordPath = $releaseRoot . '/.email_logs_password';
    $tokensPath = $releaseRoot . '/.email_reset_tokens.json';
    $versionPath = kssmi_prefix_admin_credential_version_path($passwordPath);
    if ($operation === 'change') {
        $result = kssmi_prefix_admin_change_password($tokensPath, $passwordPath, $newPassword, $versionPath);
    } elseif ($operation === 'reset') {
        $result = kssmi_prefix_admin_reset_password(
            $tokensPath, $passwordPath, $argv[4] ?? '', $newPassword, null, $versionPath
        );
    } else {
        fwrite(STDERR, "usage: change|reset <release-root> <new-password> [<token>]\n");
        exit(2);
    }
    echo json_encode($result), "\n";
}
