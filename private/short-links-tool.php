<?php
declare(strict_types=1);

/*
 * Shared support layer for the standalone Short Links tool
 * (public/short-links.php + public/api/short-links-*.php).
 *
 * Session/CSRF keys live in their own namespace (short_links_tool_*) so this
 * tool can never unlock the VJT dashboard or email logs.
 */

require_once __DIR__ . '/http-security.php';
require_once __DIR__ . '/short-link-store.php';
require_once __DIR__ . '/rate-limit.php';

const KSSMI_SHORT_LINK_EMAIL_DOMAIN = '@kssmi.com';

function kssmi_short_links_session(): void { kssmi_admin_session_bootstrap(); }

/** Remove every privilege-bearing value from this tool's separate session. */
function kssmi_short_links_session_revoke(): void {
    unset(
        $_SESSION['short_links_tool_auth'],
        $_SESSION['short_links_tool_email'],
        $_SESSION['short_links_tool_admin'],
        $_SESSION['short_links_tool_password_fingerprint'],
        $_SESSION['short_links_tool_authenticated_at'],
        $_SESSION['short_links_tool_last_seen_at'],
        $_SESSION['short_links_tool_csrf']
    );
}

function kssmi_short_links_password_fingerprint(string $hash): string {
    // A fingerprint is enough to bind the session to the current credential;
    // never duplicate a password or bcrypt hash into session storage.
    return hash('sha256', $hash);
}

function kssmi_short_links_session_establish(string $email, array $entry, ?int $now = null): bool {
    $hash = $entry['hash'] ?? null;
    if (!is_string($hash) || $hash === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $now ??= time();
    $_SESSION['short_links_tool_auth'] = true;
    $_SESSION['short_links_tool_email'] = strtolower($email);
    $_SESSION['short_links_tool_admin'] = ($entry['admin'] ?? false) === true;
    $_SESSION['short_links_tool_password_fingerprint'] = kssmi_short_links_password_fingerprint($hash);
    $_SESSION['short_links_tool_authenticated_at'] = $now;
    $_SESSION['short_links_tool_last_seen_at'] = $now;
    kssmi_admin_csrf_rotate('short_links_tool_csrf');
    return true;
}

/**
 * Return the currently valid tool principal, or revoke a stale/invalid session.
 *
 * The account file is intentionally checked on every protected request: account
 * deletion, password reset/change, and admin-role demotion take effect without
 * waiting for an old browser session to expire.
 */
function kssmi_short_links_session_principal(?array $users = null, ?int $now = null): ?array {
    $now ??= time();
    $email = $_SESSION['short_links_tool_email'] ?? null;
    $fingerprint = $_SESSION['short_links_tool_password_fingerprint'] ?? null;
    $issuedAt = $_SESSION['short_links_tool_authenticated_at'] ?? null;
    $lastSeenAt = $_SESSION['short_links_tool_last_seen_at'] ?? null;
    $validShape = ($_SESSION['short_links_tool_auth'] ?? false) === true
        && is_string($email)
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN)
        && is_string($fingerprint)
        && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1
        && is_int($issuedAt)
        && is_int($lastSeenAt);
    $users ??= kssmi_short_links_users();
    $entry = $validShape ? ($users[strtolower($email)] ?? null) : null;
    $validEntry = is_array($entry)
        && is_string($entry['hash'] ?? null)
        && hash_equals($fingerprint, kssmi_short_links_password_fingerprint($entry['hash']))
        && $issuedAt <= $now
        && $lastSeenAt <= $now
        && ($now - $issuedAt) <= kssmi_admin_session_absolute_ttl()
        && ($now - $lastSeenAt) <= kssmi_admin_session_inactivity_ttl();
    if (!$validEntry) {
        kssmi_short_links_session_revoke();
        return null;
    }
    // The current file is authoritative; a cached role must never preserve
    // administration after a role change.
    $isAdmin = ($entry['admin'] ?? false) === true;
    $_SESSION['short_links_tool_admin'] = $isAdmin;
    $_SESSION['short_links_tool_last_seen_at'] = $now;
    return ['email' => strtolower($email), 'admin' => $isAdmin];
}

function kssmi_short_links_users_path(): string { return dirname(__DIR__) . '/short-links-users.txt'; }

function kssmi_short_links_users(): array {
    $users = [];
    $path = kssmi_short_links_users_path();
    if (!is_readable($path)) return $users;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 2 && filter_var($parts[0], FILTER_VALIDATE_EMAIL)) {
            // Lowercase the email only; bcrypt hashes are case-sensitive.
            $email = strtolower($parts[0]);
            $users[$email] = ['hash' => $parts[1], 'admin' => (($parts[2] ?? '') === 'admin')];
        }
    }
    return $users;
}

function kssmi_short_links_users_real_path(): string {
    // deploy-release.sh symlinks the users file into each release from the
    // shared private store. An atomic write renames a temp file OVER that
    // path, which would silently replace the symlink with a release-local
    // copy — detaching future server-side edits and rolling the change back
    // on the next deploy. Resolve the real target first so writes always
    // land on the shared file and the symlink survives.
    $path = kssmi_short_links_users_path();
    return is_file($path) ? (realpath($path) ?: $path) : $path;
}

function kssmi_short_links_write_users(array $users): bool {
    $lines = ['# email bcrypt-hash [admin]'];
    foreach ($users as $email => $row) $lines[] = $email . ' ' . $row['hash'] . ($row['admin'] ? ' admin' : '');
    return kssmi_admin_atomic_write(kssmi_short_links_users_real_path(), implode("\n", $lines) . "\n", 0600);
}

/**
 * Change the shared account file under one exclusive lock.
 *
 * @throws RuntimeException When the file cannot be safely updated.
 */
function kssmi_short_links_mutate_users(callable $mutator): mixed {
    $realPath = kssmi_short_links_users_real_path();
    $lock = kssmi_admin_file_lock($realPath, LOCK_EX);
    if (!$lock['ok']) throw new RuntimeException('Account file is unavailable; ask the administrator.');
    try {
        $users = kssmi_short_links_users();
        $result = $mutator($users);
        if (!kssmi_short_links_write_users($users)) {
            throw new RuntimeException('Account file could not be updated; ask the administrator.');
        }
        return $result;
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_short_links_account_email(string $email): string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN)) {
        throw new InvalidArgumentException('Use a valid @kssmi.com email address.');
    }
    return $email;
}

function kssmi_short_links_admin_count(array $users): int {
    return count(array_filter($users, static fn(array $row): bool => ($row['admin'] ?? false) === true));
}

/**
 * Change one account's bcrypt hash in the shared users file.
 *
 * The file is read INSIDE the exclusive lock so two concurrent changes
 * (password change vs. reset confirm vs. a second account's change) can
 * never overwrite each other with a stale snapshot.
 *
 * @throws InvalidArgumentException Account no longer exists in the file.
 * @throws RuntimeException         File unavailable or not writable.
 */
function kssmi_short_links_update_user_password(string $email, string $hash): void {
    $realPath = kssmi_short_links_users_real_path();
    $lock = kssmi_admin_file_lock($realPath, LOCK_EX);
    if (!$lock['ok']) {
        throw new RuntimeException('Account file is unavailable; ask the administrator.');
    }
    try {
        $users = kssmi_short_links_users();
        if (!isset($users[$email])) {
            throw new InvalidArgumentException('Account entry not found; ask the administrator.');
        }
        $users[$email]['hash'] = $hash;
        if (!kssmi_short_links_write_users($users)) {
            throw new RuntimeException('Password could not be saved; ask the administrator.');
        }
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

function kssmi_short_links_send_reset(string $email, string $token): bool {
    $configPath = dirname(__DIR__) . '/private_config.php';
    $cfg = file_exists($configPath) ? (array)require $configPath : [];
    // The webroot is named "public" in the repo but "dist" in the deployed
    // release (deploy-release.sh: NEW_WEBROOT="$RELEASE_DIR/dist"). Accept
    // both so dev, CI, and production resolve the composer autoloader.
    $vendor = null;
    foreach (['dist/vendor/autoload.php', 'public/vendor/autoload.php'] as $candidate) {
        $candidatePath = dirname(__DIR__) . '/' . $candidate;
        if (is_file($candidatePath)) { $vendor = $candidatePath; break; }
    }
    if ($vendor === null) {
        throw new RuntimeException('Reset mailer is unavailable.');
    }
    require_once $vendor;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'sales@kssmi.com'; $mail->Password = (string)($cfg['smtp_pass'] ?? ''); $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587; $mail->CharSet = 'UTF-8'; $mail->setFrom('sales@kssmi.com', 'KSSMI'); $mail->addAddress($email); $mail->Subject = 'KSSMI short-links password reset'; $mail->Body = "Reset your password within 60 minutes:\nhttps://kssmi.com/short-links?reset=" . $token; return $mail->send();
    } catch (Throwable $e) {
        // The mailer's message (never the SMTP password) goes to the server
        // log only. It is not returned to the caller: a failure here happens
        // only for registered addresses, so it would reveal accounts.
        error_log('KSSMI short-link reset mail failed: ' . $e->getMessage());
        throw new RuntimeException('Reset email could not be sent.');
    }
}

// ── Sign-in and reset abuse limits (KS-03) ─────────────────────────────────
// An anonymous caller gets the same answer whether or not an address has an
// account. Budgets are kept per IP, per submitted address (with no IP in the
// identity, so spreading requests over many IPs does not help) and, for reset
// mail, globally. A browser that has signed in to an account before carries a
// device cookie for it. Its attempts use their own budget, so exhausting an
// account's budget from elsewhere does not lock the colleague out of a device
// they already use.

const KSSMI_SHORT_LINKS_DEVICE_COOKIE = '__Secure-kssmi_sl_device';
const KSSMI_SHORT_LINKS_DEVICE_TTL = 30 * 86400;
// bcrypt (cost 10, PASSWORD_DEFAULT on PHP 8.3) of a random value that was
// discarded. Unknown addresses are verified against it, so they take the same
// path, and about the same time, as a wrong password.
const KSSMI_SHORT_LINKS_DUMMY_HASH = '$2y$10$iuh/OUA3lNWPE9.MuSlM6uhksQ2pmkpYcqSF8A0V.4Efv7zOvAAEW';

function kssmi_short_links_login_email($value): string {
    return strtolower(kssmi_scalar_text($value, 254));
}

function kssmi_short_links_device_mac(string $email, string $hash, string $payload): string {
    // Keyed by the account's current bcrypt hash, like the admin marker: only
    // the server can mint one, and a password change or reset revokes every
    // device cookie of that account.
    $key = hash_hmac('sha256', 'kssmi-short-links-device-v1', $hash, true);
    return hash_hmac('sha256', $email . '|' . $payload, $key);
}

/** Build a device cookie value: v1.<expires>.<nonce>.<hmac>. */
function kssmi_short_links_device_cookie_value(string $email, string $hash, int $expiresAt, ?string $nonce = null): ?string {
    if ($nonce === null) {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return null;
        }
    }
    if ($hash === '' || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) return null;
    $payload = 'v1.' . $expiresAt . '.' . $nonce;
    return $payload . '.' . kssmi_short_links_device_mac($email, $hash, $payload);
}

/** Return the cookie's nonce when it is a live device cookie for this account. */
function kssmi_short_links_device_nonce($value, string $email, ?array $entry, ?int $now = null): ?string {
    $hash = $entry['hash'] ?? null;
    if (!is_string($value) || !is_string($hash) || $hash === '') return null;
    if (preg_match('/^v1\.([0-9]{10,11})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $value, $parts) !== 1) return null;
    $now ??= time();
    $expiresAt = (int)$parts[1];
    if ($expiresAt <= $now || $expiresAt > $now + KSSMI_SHORT_LINKS_DEVICE_TTL) return null;
    $payload = 'v1.' . $parts[1] . '.' . $parts[2];
    return hash_equals(kssmi_short_links_device_mac($email, $hash, $payload), $parts[3]) ? $parts[2] : null;
}

function kssmi_short_links_issue_device_cookie(string $email, string $hash): void {
    $expiresAt = time() + KSSMI_SHORT_LINKS_DEVICE_TTL;
    $value = kssmi_short_links_device_cookie_value($email, $hash, $expiresAt);
    if ($value === null) return;
    setcookie(KSSMI_SHORT_LINKS_DEVICE_COOKIE, $value, [
        'expires' => $expiresAt,
        'path' => '/short-links',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Charge one sign-in attempt before the password is checked. An unknown
 * address is charged exactly like an account.
 */
function kssmi_short_links_login_budget(string $email, ?string $deviceNonce): bool {
    if ($deviceNonce !== null) {
        return kssmi_check_rate_limit_identity('short-links-login-device', $deviceNonce, 10, 900, 1);
    }
    return kssmi_check_rate_limit_identity('short-links-login-account-day', $email, 40, 86400, 1)
        && kssmi_check_rate_limit_identity('short-links-login-account', $email, 10, 900, 1);
}

/**
 * Check one sign-in attempt. "invalid" covers an unknown address, another
 * domain and a wrong password alike, and each of them runs one bcrypt check.
 *
 * @return array{result: 'ok'|'invalid'|'throttled', entry: ?array}
 */
function kssmi_short_links_authenticate(array $users, string $email, string $password, $deviceCookie, ?int $now = null): array {
    $entry = is_array($users[$email] ?? null) ? $users[$email] : null;
    $deviceNonce = kssmi_short_links_device_nonce($deviceCookie, $email, $entry, $now);
    if (!kssmi_short_links_login_budget($email, $deviceNonce)) {
        return ['result' => 'throttled', 'entry' => null];
    }
    $known = $entry !== null
        && is_string($entry['hash'] ?? null)
        && filter_var($email, FILTER_VALIDATE_EMAIL)
        && str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN);
    $verified = password_verify($password, $known ? $entry['hash'] : KSSMI_SHORT_LINKS_DUMMY_HASH);
    return ($known && $verified)
        ? ['result' => 'ok', 'entry' => $entry]
        : ['result' => 'invalid', 'entry' => null];
}

/**
 * Act on one reset request after its response was sent. The result is for the
 * server log and tests only.
 *
 * @param callable(string): void $deliver Stores a reset token and sends the mail.
 * @return 'ignored'|'account_budget'|'unknown'|'global_budget'|'failed'|'sent'
 */
function kssmi_short_links_reset_request(string $email, array $users, callable $deliver): string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN)) {
        return 'ignored';
    }
    if (!kssmi_check_rate_limit_identity('short-links-reset-account-day', $email, 6, 86400, 1)
        || !kssmi_check_rate_limit_identity('short-links-reset-account', $email, 3, 3600, 1)) {
        return 'account_budget';
    }
    if (!isset($users[$email])) return 'unknown';
    // Reset mail shares the sales@ Gmail quota with inquiry mail. Only mail
    // that will really be sent is charged, so made-up addresses cannot use
    // this budget up.
    if (!checkRateLimitGlobal('short-links-reset-mail-day', 50, 86400)
        || !checkRateLimitGlobal('short-links-reset-mail', 20, 3600)) {
        return 'global_budget';
    }
    try {
        $deliver($email);
    } catch (Throwable $error) {
        error_log('KSSMI short-links reset delivery failed: ' . $error->getMessage());
        return 'failed';
    }
    return 'sent';
}

/**
 * Complete the response now, so that work which depends on the submitted
 * address cannot change its timing. Without SAPI support the work still runs,
 * but before the response completes.
 */
function kssmi_short_links_finish_response(): bool {
    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('litespeed_finish_request')) return litespeed_finish_request();
    if (function_exists('fastcgi_finish_request')) return fastcgi_finish_request();
    return false;
}
