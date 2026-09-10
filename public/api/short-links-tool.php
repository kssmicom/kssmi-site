<?php
declare(strict_types=1);

/*
 * Standalone short-links API for /short-links (public/short-links.php).
 * Functionally identical to the visitor-journey short-link endpoints but
 * authenticated against the tool's own session (short_links_tool_auth), so
 * colleagues never need — and never receive — the VJT dashboard login. Uses
 * the same short-link store.
 */

require_once dirname(__DIR__, 2) . '/private/short-links-tool.php';
kssmi_admin_require_trusted_proxy();
kssmi_short_links_session();
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private', true);
header('X-Robots-Tag: noindex, nofollow');

const SL_TOOL_CSRF_KEY = 'short_links_tool_csrf';

if (!isset($_SESSION['short_links_tool_auth']) || $_SESSION['short_links_tool_auth'] !== true) {
    http_response_code(401);
    echo '{"error":"Authentication required."}';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo '{"error":"POST required."}';
    exit;
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if (
    !in_array($origin, ['https://kssmi.com', 'https://www.kssmi.com'], true) ||
    !in_array($fetchSite, ['same-origin', 'same-site'], true)
) {
    http_response_code(403);
    echo '{"error":"Invalid origin."}';
    exit;
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentType !== 'application/json' || $contentLength < 1 || $contentLength > 12288) {
    http_response_code(415);
    echo '{"error":"JSON request body required."}';
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true, 16);
if (!is_array($input)) {
    http_response_code(400);
    echo '{"error":"Invalid JSON body."}';
    exit;
}
if (!kssmi_admin_csrf_valid($input['csrf_token'] ?? null, SL_TOOL_CSRF_KEY)) {
    http_response_code(403);
    echo '{"error":"Security check failed."}';
    exit;
}
if (!checkRateLimit('short-links-tool', 200, 300)) {
    http_response_code(429);
    echo '{"error":"Too many requests."}';
    exit;
}

function short_link_api_id($value): int {
    if (is_int($value) && $value > 0) return $value;
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) return (int)$value;
    throw new InvalidArgumentException('A valid short-link ID is required.');
}

function sl_tool_update_password(string $email, string $hash): void {
    // Rewrite the shared users file under an exclusive lock, preserving every
    // other account (and each row's admin flag) exactly as parsed.
    $path = kssmi_short_links_users_path();
    $users = kssmi_short_links_users();
    if (!isset($users[$email])) {
        throw new RuntimeException('Account entry not found; ask the administrator.');
    }
    $users[$email]['hash'] = $hash;
    $lock = kssmi_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok'] || !kssmi_short_links_write_users($users)) {
        kssmi_admin_file_unlock($lock);
        throw new RuntimeException('Password could not be saved; ask the administrator.');
    }
    kssmi_admin_file_unlock($lock);
}

try {
    $action = $input['action'] ?? '';
    // Attribute writes to the signed-in colleague's email.
    $admin = (string)($_SESSION['short_links_tool_email'] ?? '');
    if ($admin === '' || !str_ends_with($admin, KSSMI_SHORT_LINK_EMAIL_DOMAIN)) {
        http_response_code(401);
        echo '{"error":"Authentication required."}';
        exit;
    }
    // Admin status is re-read from the users file on every request so a role
    // change (or a session created before the flag existed) takes effect
    // immediately without waiting for a new login.
    $usersFile = kssmi_short_links_users();
    $isToolAdmin = ($usersFile[$admin]['admin'] ?? false)
        || ($_SESSION['short_links_tool_admin'] ?? false) === true;
    // Regular accounts may only modify links they created.
    if (!$isToolAdmin && in_array($action, ['status', 'permanent-delete'], true)) {
        $owned = short_link_get(short_link_api_id($input['id'] ?? null));
        if (!$owned || !hash_equals((string)$owned['created_by'], $admin)) {
            http_response_code(403);
            echo '{"error":"You can only modify your own short links."}';
            exit;
        }
    }
    if ($action === 'change-password') {
        $current = (string)($input['current_password'] ?? '');
        $new = (string)($input['new_password'] ?? '');
        $users = kssmi_short_links_users();
        $entry = $users[$admin] ?? null;
        if (!$entry || !password_verify($current, $entry['hash'])) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }
        if (strlen($new) < 10 || strlen($new) > 128) {
            throw new InvalidArgumentException('New password must be 10-128 characters.');
        }
        sl_tool_update_password($admin, password_hash($new, PASSWORD_DEFAULT));
        echo '{"ok":true}';
        exit;
    }
    if ($action === 'destination') {
        if (!is_string($input['target_url'] ?? null)) {
            throw new InvalidArgumentException('Target URL is invalid.');
        }
        $result = short_link_destination_create($input['target_url'], $admin);
        if (!$result['created']) http_response_code(409);
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit;
    }
    if ($action === 'distribution') {
        $link = short_link_create_distribution(short_link_api_id($input['destination_id'] ?? null), $input, $admin);
        echo json_encode(['link' => $link], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($action === 'status') {
        if (!is_string($input['status'] ?? null)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        short_link_set_status(short_link_api_id($input['id'] ?? null), $input['status'], $admin);
        echo '{"ok":true}';
        exit;
    }
    if ($action === 'permanent-delete') {
        if (!is_string($input['confirmation'] ?? null)) {
            throw new InvalidArgumentException('Invalid deletion confirmation.');
        }
        short_link_permanently_delete(
            short_link_api_id($input['id'] ?? null),
            $input['confirmation'],
            $admin
        );
        echo '{"ok":true}';
        exit;
    }
    http_response_code(400);
    echo '{"error":"Unknown action."}';
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('KSSMI short-link tool failure: ' . $error->getMessage());
    http_response_code(500);
    echo '{"error":"Unable to process short-link request."}';
}
