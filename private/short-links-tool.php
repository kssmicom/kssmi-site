<?php
declare(strict_types=1);

require_once __DIR__ . '/http-security.php';
require_once __DIR__ . '/short-link-store.php';

const KSSMI_SHORT_LINK_EMAIL_DOMAIN = '@kssmi.com';

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
            $email = strtolower($parts[0]);
            $users[$email] = ['hash' => $parts[1], 'admin' => (($parts[2] ?? '') === 'admin')];
        }
    }
    return $users;
}
function kssmi_short_links_session(): void { kssmi_admin_session_bootstrap(); }
function kssmi_short_links_csrf(): string { return kssmi_admin_csrf_token('short_links_tool_csrf'); }
function kssmi_short_links_json(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex, nofollow'); echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit; }
function kssmi_short_links_origin_ok(): bool {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    return in_array($origin, ['https://kssmi.com', 'https://www.kssmi.com'], true) && in_array($site, ['same-origin', 'same-site'], true);
}
function kssmi_short_links_identity(): ?array {
    $email = strtolower((string)($_SESSION['short_links_tool_email'] ?? ''));
    $users = kssmi_short_links_users();
    if (!isset($_SESSION['short_links_tool_auth']) || $_SESSION['short_links_tool_auth'] !== true || !isset($users[$email]) || !str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN)) return null;
    return ['email' => $email, 'admin' => (bool)$users[$email]['admin']];
}
function kssmi_short_links_login(string $email, string $password): bool {
    $email = strtolower(trim($email)); $users = kssmi_short_links_users();
    if (!str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN) || !isset($users[$email]) || !password_verify($password, $users[$email]['hash'])) return false;
    $_SESSION['short_links_tool_auth'] = true; $_SESSION['short_links_tool_email'] = $email; $_SESSION['short_links_tool_admin'] = (bool)$users[$email]['admin']; kssmi_admin_csrf_rotate('short_links_tool_csrf'); return true;
}
function kssmi_short_links_write_users(array $users): bool {
    $lines = ['# email bcrypt-hash [admin]'];
    foreach ($users as $email => $row) $lines[] = $email . ' ' . $row['hash'] . ($row['admin'] ? ' admin' : '');
    return kssmi_admin_atomic_write(kssmi_short_links_users_path(), implode("\n", $lines) . "\n", 0600);
}
function kssmi_short_links_send_reset(string $email, string $token): bool {
    $configPath = dirname(__DIR__) . '/private_config.php';
    $cfg = file_exists($configPath) ? (array)require $configPath : [];
    $vendor = dirname(__DIR__) . '/public/vendor/autoload.php';
    if (!is_file($vendor)) return false;
    require_once $vendor;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host = 'smtp.gmail.com'; $mail->SMTPAuth = true; $mail->Username = 'sales@kssmi.com'; $mail->Password = (string)($cfg['smtp_pass'] ?? ''); $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587; $mail->CharSet = 'UTF-8'; $mail->setFrom('sales@kssmi.com', 'KSSMI'); $mail->addAddress($email); $mail->Subject = 'KSSMI short-links password reset'; $mail->Body = "Reset your password within 60 minutes:\nhttps://kssmi.com/short-links?reset=" . $token; return $mail->send();
    } catch (Throwable $e) { error_log('KSSMI short-link reset mail failed: ' . $e->getMessage()); return false; }
}
