<?php
declare(strict_types=1);

/**
 * No-store issuer for server-generated VJT identities and one-time write
 * capabilities. This endpoint is deliberately separate from vjt-config.php,
 * whose public tuning response may be shared-cached.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Pragma: no-cache');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

require_once __DIR__ . '/vjt-helpers.php';
require_once dirname(__DIR__, 2) . '/private/vjt-event-auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$corsAllowed = kssmi_vjt_apply_cors();
if ($method === 'OPTIONS') {
    http_response_code($corsAllowed ? 204 : 403);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}
if (!$corsAllowed || !kssmi_vjt_same_site_issuance_request()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Capability issuance rejected']);
    exit;
}

require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('vjt-capability', 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 2048) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 2049);
if ($body === false || strlen($body) > 2048) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$mode = is_string($data['mode'] ?? null) ? $data['mode'] : '';
if (!in_array($mode, ['analytics', 'contact'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid capability mode']);
    exit;
}

// CORS and Fetch Metadata only constrain cooperative browsers. Every public
// caller must also present a fresh, server-verified Turnstile proof before we
// mint a signed cookie or a capability; localhost development is the sole
// TCP-bound exception because Turnstile cannot run on that hostname.
if (!kssmi_vjt_is_local_development_request()) {
    $_privateConfigPath = dirname(__DIR__, 2) . '/private_config.php';
    $_privateCfg = is_file($_privateConfigPath) ? require $_privateConfigPath : [];
    $turnstileSecret = is_array($_privateCfg) ? ($_privateCfg['turnstile_secret'] ?? '') : '';
    $turnstileToken = is_scalar($data['turnstile_token'] ?? null)
        ? (string)$data['turnstile_token'] : '';
    $turnstile = kssmi_vjt_verify_capability_turnstile($turnstileToken, $turnstileSecret);
    if (!$turnstile['ok']) {
        $status = $turnstile['service_error'] ? 503 : 403;
        if ($turnstile['service_error']) {
            error_log('VJT capability Turnstile verification unavailable: ' . $turnstile['reason']);
        }
        http_response_code($status);
        echo json_encode(['success' => false, 'error' => $status === 503
            ? 'Capability service unavailable' : 'Capability proof rejected']);
        exit;
    }
}

try {
    vjt_data_init();
    if ($mode === 'contact') {
        $contact = kssmi_vjt_bootstrap_contact_session();
        echo json_encode([
            'success' => true,
            'capabilities' => [
                'contact_intent' => kssmi_vjt_issue_capabilities('contact_intent', $contact, 8),
            ],
        ]);
        exit;
    }

    $settings = vjt_get_settings();
    $sessionLifetime = min(120, max(5, (int)($settings['session_timeout'] ?? 30))) * 60;
    $rotateSession = ($data['rotate_session'] ?? false) === true;
    $identity = kssmi_vjt_bootstrap_identity($rotateSession, $sessionLifetime);
    echo json_encode([
        'success' => true,
        'visitor_id' => $identity['visitor_id'],
        'session_id' => $identity['session_id'],
        'heartbeat_seconds' => min(120, max(30, (int)($settings['heartbeat_seconds'] ?? 45))),
        'capabilities' => [
            'pageview' => kssmi_vjt_issue_capabilities('pageview', $identity, 16),
            'submission' => kssmi_vjt_issue_capabilities('submission', $identity, 6),
        ],
    ]);
} catch (Throwable $e) {
    error_log('VJT capability issuer error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Capability service unavailable']);
}
