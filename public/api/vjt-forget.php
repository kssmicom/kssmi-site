<?php
declare(strict_types=1);
/**
 * Expires the HttpOnly VJT identity cookie after the visitor withdraws
 * analytics consent. Browser JavaScript cannot delete an HttpOnly cookie, so
 * the consent controller calls this endpoint. It issues nothing, reads no VJT
 * data and needs no Turnstile proof: the only effect is a cookie deletion.
 * Same-site checks still apply so another site cannot sign a visitor out.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Pragma: no-cache');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

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
    echo json_encode(['success' => false, 'error' => 'Forget request rejected']);
    exit;
}

require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('vjt-forget', 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 256) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}

// Idempotent: expiring an absent cookie is harmless.
kssmi_vjt_clear_private_cookie(KSSMI_VJT_IDENTITY_COOKIE);
http_response_code(204);
