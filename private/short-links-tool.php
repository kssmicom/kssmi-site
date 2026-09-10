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

const KSSMI_SHORT_LINK_EMAIL_DOMAIN = '@kssmi.com';

function kssmi_short_links_session(): void { kssmi_admin_session_bootstrap(); }

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
    $vendor = dirname(__DIR__) . '/public/vendor/autoload.php';
    if (!is_file($vendor)) {
        throw new RuntimeException('Reset mailer is unavailable.');
    }
    require_once $vendor;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'sales@kssmi.com'; $mail->Password = (string)($cfg['smtp_pass'] ?? ''); $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587; $mail->CharSet = 'UTF-8'; $mail->setFrom('sales@kssmi.com', 'KSSMI'); $mail->addAddress($email); $mail->Subject = 'KSSMI short-links password reset'; $mail->Body = "Reset your password within 60 minutes:\nhttps://kssmi.com/short-links?reset=" . $token; return $mail->send();
    } catch (Throwable $e) {
        error_log('KSSMI short-link reset mail failed: ' . $e->getMessage());
        // Surface the mailer's own message (never contains credentials) so a
        // failed reset attempt can be diagnosed from the API response.
        throw new RuntimeException('Reset email failed: ' . $e->getMessage());
    }
}
