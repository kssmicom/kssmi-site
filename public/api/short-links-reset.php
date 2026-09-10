<?php
declare(strict_types=1);

/*
 * Password reset endpoint for the /short-links tool.
 *
 * action "request": emails a single-use reset link (60 min) to an authorized
 *                   @kssmi.com address. The response is deliberately
 *                   identical whether or not the address is registered.
 * action "confirm": exchanges the token for a new password, updating the
 *                   account's bcrypt hash in the shared short-links users file.
 *
 * Tokens live in private/short-links-reset-tokens.json (release-private,
 * site-user writable) and are consumed on use; stale entries are pruned.
 */

require_once dirname(__DIR__, 2) . '/private/short-links-tool.php';
kssmi_admin_require_trusted_proxy();
kssmi_short_links_session();
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private', true);
header('X-Robots-Tag: noindex, nofollow');

const SL_RESET_TOKEN_TTL = 3600;
const SL_RESET_CSRF_KEY = 'short_links_tool_csrf';

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
if ($contentType !== 'application/json' || $contentLength < 1 || $contentLength > 4096) {
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
if (!kssmi_admin_csrf_valid($input['csrf_token'] ?? null, SL_RESET_CSRF_KEY)) {
    http_response_code(403);
    echo '{"error":"Security check failed."}';
    exit;
}

function sl_reset_tokens_path(): string {
    return dirname(__DIR__, 2) . '/private/short-links-reset-tokens.json';
}

/**
 * Read-mutate-write the token store under an exclusive lock so concurrent
 * requests can never lose a created token or resurrect a consumed one.
 * $mutator receives the token map BY REFERENCE, may modify it, and its
 * return value is passed through. Expired entries are pruned on write.
 *
 * @param callable(array&,mixed):mixed $mutator
 */
function sl_reset_tokens_mutate(callable $mutator): mixed {
    $path = sl_reset_tokens_path();
    $lock = kssmi_admin_file_lock($path, LOCK_EX);
    if (!$lock['ok']) {
        throw new RuntimeException('Reset token store is unavailable.');
    }
    try {
        $tokens = [];
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true, 16);
            if (is_array($decoded)) $tokens = $decoded;
        }
        $result = $mutator($tokens);
        $now = time();
        $tokens = array_filter(
            $tokens,
            static fn($t) => is_array($t) && is_string($t['email'] ?? null) && (int)($t['expires'] ?? 0) > $now
        );
        if (!kssmi_admin_atomic_write($path, json_encode($tokens, JSON_THROW_ON_ERROR) . "\n", 0600)) {
            throw new RuntimeException('Reset token store could not be updated.');
        }
        return $result;
    } finally {
        kssmi_admin_file_unlock($lock);
    }
}

try {
    $action = $input['action'] ?? '';
    if ($action === 'request') {
        if (!checkRateLimit('short-links-reset-request', 10, 3600)) {
            http_response_code(429);
            header('Retry-After: 3600');
            echo '{"ok":true}';
            exit;
        }
        $email = strtolower(kssmi_scalar_text($input['email'] ?? null, 254));
        $users = kssmi_short_links_users();
        if (
            filter_var($email, FILTER_VALIDATE_EMAIL) &&
            str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN) &&
            isset($users[$email]) &&
            checkRateLimit('short-links-reset-mail:' . $email, 3, 3600)
        ) {
            $token = bin2hex(random_bytes(32));
            sl_reset_tokens_mutate(
                static function (array &$tokens) use ($token, $email): void {
                    $tokens[$token] = ['email' => $email, 'expires' => time() + SL_RESET_TOKEN_TTL];
                }
            );
            // Throws RuntimeException (surfaced as 500) when the mailer is
            // unavailable or the send fails.
            kssmi_short_links_send_reset($email, $token);
        }
        // Identical response whether or not the address is registered.
        echo '{"ok":true}';
        exit;
    }
    if ($action === 'confirm') {
        if (!checkRateLimit('short-links-reset-confirm', 20, 3600)) {
            http_response_code(429);
            echo '{"error":"Too many requests."}';
            exit;
        }
        $token = (string)($input['token'] ?? '');
        $new = (string)($input['new_password'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Reset link is invalid or has expired.');
        }
        if (strlen($new) < 10 || strlen($new) > 128) {
            throw new InvalidArgumentException('New password must be 10-128 characters.');
        }
        $entry = sl_reset_tokens_mutate(
            static function (array &$tokens) use ($token): ?array {
                $entry = $tokens[$token] ?? null;
                unset($tokens[$token]);
                return $entry;
            }
        );
        if (
            !is_array($entry) ||
            !is_string($entry['email'] ?? null) ||
            (int)($entry['expires'] ?? 0) <= time() ||
            !isset(kssmi_short_links_users()[$entry['email']])
        ) {
            throw new InvalidArgumentException('Reset link is invalid or has expired.');
        }
        kssmi_short_links_update_user_password($entry['email'], password_hash($new, PASSWORD_DEFAULT));
        echo '{"ok":true}';
        exit;
    }
    http_response_code(400);
    echo '{"error":"Unknown action."}';
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (RuntimeException $error) {
    error_log('KSSMI short-links reset failure: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('KSSMI short-links reset failure: ' . $error->getMessage());
    http_response_code(500);
    echo '{"error":"Unable to process the request."}';
}
