<?php
declare(strict_types=1);

/**
 * Integration test for the shared Kssmi admin-session bootstrap.
 *
 * Run: php scripts/test-admin-session.php
 */

function kssmi_session_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_session_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_session_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-admin-session-' . bin2hex(random_bytes(6));
kssmi_session_assert(mkdir($testDirectory, 0700, true), 'create isolated session directory');

try {
    require_once dirname(__DIR__) . '/private/http-security.php';

    session_save_path($testDirectory);
    session_name('KSSMI_ADMIN_TEST');
    kssmi_session_assert(session_save_path() === $testDirectory, 'set isolated session save path');
    kssmi_session_assert(session_name() === 'KSSMI_ADMIN_TEST', 'set isolated session name');

    $attackerChosenId = str_repeat('a', 32);
    session_id($attackerChosenId);
    kssmi_admin_session_bootstrap();

    kssmi_session_assert(session_status() === PHP_SESSION_ACTIVE, 'bootstrap starts the session');
    kssmi_session_assert(session_id() !== $attackerChosenId, 'strict mode rejects an unknown caller-supplied session id');
    kssmi_session_assert(ini_get('session.use_strict_mode') === '1', 'strict mode is enabled');
    kssmi_session_assert(ini_get('session.use_cookies') === '1', 'session cookies are enabled');
    kssmi_session_assert(ini_get('session.use_only_cookies') === '1', 'session ids are accepted only from cookies');
    kssmi_session_assert(ini_get('session.use_trans_sid') === '0', 'URL-based session ids are disabled');

    $cookie = session_get_cookie_params();
    kssmi_session_assert(($cookie['lifetime'] ?? null) === 0, 'session cookie has browser-session lifetime');
    kssmi_session_assert(($cookie['path'] ?? null) === '/', 'session cookie covers both admin pages');
    kssmi_session_assert(($cookie['secure'] ?? null) === true, 'session cookie is Secure');
    kssmi_session_assert(($cookie['httponly'] ?? null) === true, 'session cookie is HttpOnly');
    kssmi_session_assert(($cookie['samesite'] ?? null) === 'Strict', 'session cookie is SameSite=Strict');

    $establishedId = session_id();
    kssmi_admin_session_bootstrap();
    kssmi_session_assert(session_id() === $establishedId, 'bootstrap is idempotent for an active session');

    // Credential-version sessions are accepted by both admin entrypoints only
    // while the version, absolute lifetime, and inactivity lifetime remain valid.
    $credentialVersionPath = $testDirectory . DIRECTORY_SEPARATOR . '.admin_credential_version';
    $now = time();
    kssmi_session_assert(kssmi_admin_session_establish($credentialVersionPath, $now), 'establish versioned session');
    kssmi_session_assert(kssmi_admin_session_authenticated($credentialVersionPath, $now + 60), 'fresh versioned session is accepted');
    kssmi_session_assert(
        !kssmi_admin_session_authenticated($credentialVersionPath, $now + 60 + kssmi_admin_session_inactivity_ttl() + 1),
        'inactive admin session is revoked'
    );
    kssmi_session_assert(kssmi_admin_session_establish($credentialVersionPath, $now), 're-establish after inactivity revocation');
    kssmi_session_assert(
        !kssmi_admin_session_authenticated($credentialVersionPath, $now + kssmi_admin_session_absolute_ttl() + 1),
        'absolute-lifetime-expired session is revoked'
    );
    kssmi_session_assert(kssmi_admin_session_establish($credentialVersionPath, $now), 're-establish before version revoke');
    kssmi_session_assert(kssmi_admin_secret_write($credentialVersionPath, '2'), 'advance credential version');
    kssmi_session_assert(!kssmi_admin_session_authenticated($credentialVersionPath, $now + 1), 'pre-change session is revoked by version mismatch');
    kssmi_session_assert(kssmi_admin_session_establish($credentialVersionPath, $now + 2), 'new-version login establishes session');
    kssmi_session_assert(kssmi_admin_session_authenticated($credentialVersionPath, $now + 3), 'new-version session remains authenticated');

    foreach (['public/email-logs.php', 'public/visitor-journey.php'] as $relativePath) {
        $source = file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath);
        kssmi_session_assert(is_string($source), "read {$relativePath}");
        kssmi_session_assert(
            strpos($source, 'kssmi_admin_session_establish(CREDENTIAL_VERSION_FILE)') !== false
                && strpos($source, 'kssmi_admin_session_authenticated(CREDENTIAL_VERSION_FILE)') !== false,
            "{$relativePath} uses the shared versioned admin session boundary"
        );
    }

    $_SESSION = [];
    session_destroy();
    fwrite(STDOUT, "Admin session bootstrap test passed.\n");
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    kssmi_session_remove_tree($testDirectory);
}
