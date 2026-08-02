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
putenv('KSSMI_ADMIN_MARKER_SECRET_FILE=' . $testDirectory . DIRECTORY_SEPARATOR . '.email_logs_password');

try {
    require_once dirname(__DIR__) . '/private/http-security.php';

    // ── trusted admin origin gate ──
    // The decision is based exclusively on the TCP peer (REMOTE_ADDR), never
    // on forgeable CF-* request headers.
    $_SERVER['HTTP_CF_RAY'] = 'forged-direct-request';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.50';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    kssmi_sec_assert(
        kssmi_admin_request_from_trusted_proxy('173.245.48.1') === true,
        'Cloudflare IPv4 peer is trusted'
    );
    kssmi_sec_assert(
        kssmi_admin_request_from_trusted_proxy('2606:4700::1234') === true,
        'Cloudflare IPv6 peer is trusted'
    );
    kssmi_sec_assert(
        kssmi_admin_request_from_trusted_proxy() === false,
        'REMOTE_ADDR direct peer is rejected even with forged CF headers'
    );
    kssmi_sec_assert(
        kssmi_admin_request_from_trusted_proxy('not-an-ip') === false,
        'invalid peer address is rejected'
    );
    kssmi_sec_assert(
        kssmi_admin_request_from_trusted_proxy(['173.245.48.1']) === false,
        'non-scalar peer address is rejected'
    );
    unset($_SERVER['HTTP_CF_RAY'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['REMOTE_ADDR']);

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

    // ── bounded request normalization ──
    $normalized = kssmi_admin_normalize_request([
        'password' => ['array-injection'],
        'search' => '  ' . str_repeat('x', 200000) . '  ',
        'flag' => '1',
        'unknown' => 'must be discarded',
        'ids' => ['  first  ', ['nested'], 'second', 'third'],
    ], [
        'password' => 1024,
        'search' => 32,
        'flag' => 8,
    ], [
        'ids' => [3, 16],
    ]);
    kssmi_sec_assert(!isset($normalized['password']), 'array-shaped scalar input is discarded');
    kssmi_sec_assert($normalized['search'] === str_repeat('x', 32), 'oversized scalar input is bounded');
    kssmi_sec_assert($normalized['flag'] === '1', 'allowed scalar input is retained');
    kssmi_sec_assert(!isset($normalized['unknown']), 'unknown request field is discarded');
    kssmi_sec_assert($normalized['ids'] === ['first', 'second'], 'list is bounded by inspected items and rejects nested values');
    kssmi_sec_assert(
        kssmi_admin_normalize_request(['ids' => ['named' => 'value']], [], ['ids' => [10, 10]]) === [],
        'associative array cannot impersonate a list field'
    );
    kssmi_sec_assert(
        kssmi_admin_normalize_request(['field' => 42], ['field' => 10]) === [],
        'non-string HTTP leaf is rejected'
    );

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

    // ── signed admin marker cookie ──
    // Set a real password hash so marker key derivation works.
    $passwordPath = $testDirectory . DIRECTORY_SEPARATOR . '.email_logs_password';
    kssmi_sec_assert(
        kssmi_admin_secret_write($passwordPath, password_hash('correct horse battery staple', PASSWORD_BCRYPT)),
        'seed password hash for marker tests'
    );

    // The marker secret path reads dirname(__DIR__) in production; for tests
    // point it at our temp file so we control the key.
    $markerNonce = str_repeat('a', 32);
    $expires = time() + 3600;
    $marker = kssmi_admin_marker_value($expires, $markerNonce);
    kssmi_sec_assert(is_string($marker), 'marker value generated');
    kssmi_sec_assert(
        preg_match('/^v1\.[0-9]{10,11}\.[a-f0-9]{32}\.[a-f0-9]{64}$/D', (string)$marker) === 1,
        'marker has v1.expires.nonce.hmac shape'
    );
    kssmi_sec_assert(kssmi_admin_marker_valid($marker, $expires - 10), 'valid unexpired marker passes');
    kssmi_sec_assert(kssmi_admin_marker_valid($marker, $expires + 1) === false, 'expired marker rejected');
    kssmi_sec_assert(kssmi_admin_marker_valid('v1.' . $expires . '.' . $markerNonce . '.' . str_repeat('0', 64), $expires - 10) === false, 'forged hmac rejected');
    kssmi_sec_assert(kssmi_admin_marker_valid('vjt_admin=1', $expires - 10) === false, 'plaintext legacy value rejected');
    kssmi_sec_assert(kssmi_admin_marker_valid('v1.99999999999.' . $markerNonce . '.' . str_repeat('f', 64), $expires - 10) === false, 'implausible future expiry rejected');
    kssmi_sec_assert(kssmi_admin_marker_valid(null, $expires - 10) === false, 'null marker rejected');

    // Changing the password hash changes the derived key → old marker invalid.
    kssmi_sec_assert(
        kssmi_admin_secret_write($passwordPath, password_hash('different password', PASSWORD_BCRYPT)),
        'rewrite password hash'
    );
    kssmi_sec_assert(kssmi_admin_marker_valid($marker, $expires - 10) === false, 'marker invalid after password change');

    // ── tracking exclusion consults the signed marker ──
    kssmi_sec_assert(
        kssmi_admin_secret_write($passwordPath, password_hash('correct horse battery staple', PASSWORD_BCRYPT)),
        'restore password hash for tracking tests'
    );
    $validMarker = kssmi_admin_marker_value(time() + 3600, str_repeat('b', 32));
    $_COOKIE['vjt_admin'] = (string)$validMarker;
    kssmi_sec_assert(kssmi_admin_tracking_excluded() === true, 'valid marker excludes admin from tracking');
    $_COOKIE['vjt_admin'] = '1';
    kssmi_sec_assert(kssmi_admin_tracking_excluded() === false, 'plaintext forged marker does not exclude');
    unset($_COOKIE['vjt_admin']);
    kssmi_sec_assert(kssmi_admin_tracking_excluded() === false, 'absent marker does not exclude');

    // ── CSRF helpers ──
    $_SESSION = [];
    $token = kssmi_admin_csrf_token();
    kssmi_sec_assert(is_string($token) && strlen($token) === 64, 'csrf token generated as 64-hex');
    kssmi_sec_assert(kssmi_admin_csrf_token() === $token, 'csrf token stable within session');
    kssmi_sec_assert(kssmi_admin_csrf_valid($token), 'valid csrf token passes');
    kssmi_sec_assert(kssmi_admin_csrf_valid('forged-token') === false, 'forged csrf token rejected');
    kssmi_sec_assert(kssmi_admin_csrf_valid(null) === false, 'null csrf token rejected');
    kssmi_sec_assert(kssmi_admin_csrf_valid('') === false, 'empty csrf token rejected');
    $rotated = kssmi_admin_csrf_rotate();
    kssmi_sec_assert($rotated !== $token && strlen($rotated) === 64, 'csrf rotate issues a new token');
    kssmi_sec_assert(kssmi_admin_csrf_valid($token) === false, 'old token invalid after rotation');
    kssmi_sec_assert(kssmi_admin_csrf_valid($rotated), 'rotated token valid');
    // Custom key (csrf_reset flow) stays independent.
    $resetToken = kssmi_admin_csrf_token('csrf_reset');
    kssmi_sec_assert(kssmi_admin_csrf_valid($resetToken, 'csrf_reset'), 'custom-key csrf token works');
    kssmi_sec_assert(kssmi_admin_csrf_valid($resetToken) === false, 'custom-key token not valid under default key');
    $_SESSION = [];

    // ── reset token atomic consumption ──
    $tokensPath = $testDirectory . DIRECTORY_SEPARATOR . '.email_reset_tokens.json';
    $now = time();
    $tokenA = str_repeat('a', 64);
    $tokenB = str_repeat('b', 64);

    // An existing EMPTY file (touch-created by deploy) must behave as no tokens.
    file_put_contents($tokensPath, '');
    kssmi_sec_assert(kssmi_admin_reset_tokens_read($tokensPath, $now)['ok'] === true, 'empty token store reads as ok');
    kssmi_sec_assert(count(kssmi_admin_reset_tokens_read($tokensPath, $now)['tokens']) === 0, 'empty token store has no tokens');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenA, $now) === false, 'empty store: no token valid');
    kssmi_sec_assert(kssmi_admin_reset_token_add($tokensPath, $tokenA, $now + 3600) === true, 'token A added to empty store');

    kssmi_sec_assert(kssmi_admin_reset_token_add($tokensPath, $tokenB, $now + 3600) === true, 'reset token B added');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenA, $now) === true, 'token A valid');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, str_repeat('c', 64), $now) === false, 'unknown token invalid');

    $consume = kssmi_admin_reset_token_consume($tokensPath, $tokenA, $now);
    kssmi_sec_assert($consume['ok'] === true && $consume['consumed'] === true, 'token A consumed atomically');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenA, $now) === false, 'consumed token A invalid (replay blocked)');
    $replay = kssmi_admin_reset_token_consume($tokensPath, $tokenA, $now);
    kssmi_sec_assert($replay['ok'] === true && $replay['consumed'] === false, 'replay of consumed token A reports not-consumed');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenB, $now) === true, 'unrelated token B survives consumption');

    // The complete password-reset operation must consume first and let only
    // the winning request write the password file.
    $resetPasswordPath = $testDirectory . DIRECTORY_SEPARATOR . '.reset_password_hash';
    $resetPassword = 'correct horse battery staple 2026';
    $passwordReset = kssmi_admin_reset_password(
        $tokensPath,
        $resetPasswordPath,
        $tokenB,
        $resetPassword,
        $now
    );
    kssmi_sec_assert(
        $passwordReset['ok'] === true && $passwordReset['changed'] === true && $passwordReset['consumed'] === true,
        'token-gated password reset succeeds exactly after consumption'
    );
    $storedResetHash = kssmi_admin_secret_read($resetPasswordPath);
    kssmi_sec_assert(
        is_string($storedResetHash) && password_verify($resetPassword, $storedResetHash),
        'winning reset stores the requested password hash'
    );
    $storedHashBeforeReplay = file_get_contents($resetPasswordPath);
    $passwordReplay = kssmi_admin_reset_password(
        $tokensPath,
        $resetPasswordPath,
        $tokenB,
        'replay must never become the password',
        $now
    );
    kssmi_sec_assert(
        $passwordReplay['ok'] === true && $passwordReplay['changed'] === false && $passwordReplay['consumed'] === false,
        'replayed token cannot change the password'
    );
    kssmi_sec_assert(
        file_get_contents($resetPasswordPath) === $storedHashBeforeReplay,
        'replay leaves the password file byte-for-byte unchanged'
    );

    $tokenD = str_repeat('d', 64);
    kssmi_sec_assert(kssmi_admin_reset_token_add($tokensPath, $tokenD, $now + 3600), 'token D added');
    $emptyPassword = kssmi_admin_reset_password($tokensPath, $resetPasswordPath, $tokenD, '', $now);
    kssmi_sec_assert($emptyPassword['ok'] === false && $emptyPassword['consumed'] === false, 'invalid password does not consume token');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenD, $now), 'token D remains valid after pre-consume failure');

    $tokenE = str_repeat('e', 64);
    kssmi_sec_assert(kssmi_admin_reset_token_add($tokensPath, $tokenE, $now + 3600), 'token E added');
    $writeFailure = kssmi_admin_reset_password(
        $tokensPath,
        $testDirectory . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'password',
        $tokenE,
        'valid password whose destination is unavailable',
        $now
    );
    kssmi_sec_assert(
        $writeFailure['ok'] === false && $writeFailure['changed'] === false && $writeFailure['consumed'] === true,
        'password write failure remains fail-closed after consumption'
    );
    kssmi_sec_assert(
        kssmi_admin_reset_token_valid($tokensPath, $tokenE, $now) === false,
        'write failure never restores a consumed token'
    );

    // Expired tokens are dropped on read/add.
    $tokenC = str_repeat('c', 64);
    kssmi_sec_assert(kssmi_admin_reset_token_add($tokensPath, $tokenC, $now - 10) === false, 'already-expired token rejected at add');
    kssmi_sec_assert(kssmi_admin_reset_token_valid($tokensPath, $tokenC, $now) === false, 'expired token invalid');

    // Malformed token shapes never crash and never consume.
    $malformed = kssmi_admin_reset_token_consume($tokensPath, 'not-64-hex', $now);
    kssmi_sec_assert($malformed['ok'] === true && $malformed['consumed'] === false, 'malformed token consume is a safe no-op');

    fwrite(STDOUT, "HTTP security module tests passed.\n");
} finally {
    kssmi_sec_remove_tree($testDirectory);
}
