<?php
/**
 * Non-blocking Contact Core ingest.
 *
 * Contact links navigate directly to WhatsApp/mailto. This endpoint receives a
 * best-effort POST in parallel and never participates in the navigation path.
 * Without analytics consent it stores only the minimal Contact Core fields.
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

$corsAllowed = kssmi_vjt_apply_cors();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code($corsAllowed ? 204 : 403);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// A capability is necessary but not sufficient: public writes also require
// browser-supplied same-site request metadata, including on direct requests.
if (!$corsAllowed || !kssmi_vjt_same_site_issuance_request()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Event write rejected']);
    exit;
}

require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-contact-intent', 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 8192) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}

$body = file_get_contents('php://input', false, null, 0, 8193);
if ($body === false || strlen($body) > 8192) {
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

$channel = is_scalar($data['channel'] ?? null)
    ? strtolower(trim((string)$data['channel'])) : '';
if (!in_array($channel, ['whatsapp', 'mailto'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid channel']);
    exit;
}

$eventId = is_scalar($data['event_id'] ?? null)
    ? trim((string)$data['event_id']) : '';
if (!preg_match('/^vjtce_[A-Za-z0-9_-]{8,80}$/', $eventId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid event_id']);
    exit;
}

$contactSession = kssmi_vjt_contact_session_from_request();
$capabilityToken = is_scalar($data['capability_token'] ?? null)
    ? (string)$data['capability_token'] : '';
$capability = $contactSession === null ? null : kssmi_vjt_validate_capability(
    $capabilityToken,
    'contact_intent',
    $contactSession
);
if ($capability === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid event capability']);
    exit;
}

$ua = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);
if (vjt_is_bot($ua)) {
    echo json_encode(['success' => true, 'result' => 'skipped_bot']);
    exit;
}

// Prefer the same-site Referer path over the browser payload. The payload is a
// fallback for privacy settings or local cross-port development that omit it.
$pagePath = vjt_contact_page_path($data['page_path'] ?? '');
$referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
if ($referer !== '') {
    $parts = parse_url($referer);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowedHosts = ['kssmi.com', 'www.kssmi.com', 'localhost', '127.0.0.1', '::1', '[::1]'];
    if (in_array($host, $allowedHosts, true)) {
        $pagePath = vjt_contact_page_path((string)($parts['path'] ?? ''));
    }
}

$knownLanguages = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
$segments = array_values(array_filter(explode('/', trim($pagePath, '/')), 'strlen'));
$siteLanguage = 'EN';
if (!empty($segments[0]) && in_array(strtolower($segments[0]), $knownLanguages, true)) {
    $siteLanguage = strtoupper($segments[0]);
}

$productSku = '';
$productIndex = (!empty($segments[0]) && in_array(strtolower($segments[0]), $knownLanguages, true)) ? 1 : 0;
if (($segments[$productIndex] ?? '') === 'product' && !empty($segments[$productIndex + 1])) {
    $productSku = vjt_contact_token($segments[$productIndex + 1], 80);
}

$placement = is_scalar($data['placement'] ?? null)
    ? vjt_contact_token((string)$data['placement'], 64) : '';

// Analytics linkage is optional enrichment. Never inspect or accept these
// identifiers unless the browser explicitly reports current analytics consent.
$visitorId = '';
$sessionId = '';
$journeyStep = 0;
$analyticsValue = $data['analytics'] ?? false;
$analytics = $analyticsValue === true
    || (is_scalar($analyticsValue) && (string)$analyticsValue === '1');

try {
    vjt_data_init();

    if ($analytics) {
        $candidateVisitor = is_scalar($data['vjt_visitor_id'] ?? null)
            ? trim((string)$data['vjt_visitor_id']) : '';
        $candidateSession = is_scalar($data['vjt_session_id'] ?? null)
            ? trim((string)$data['vjt_session_id']) : '';
        $candidateStep = is_scalar($data['journey_step'] ?? null) && is_numeric($data['journey_step'])
            ? min(10000, max(0, (int)$data['journey_step'])) : 0;

        $identity = kssmi_vjt_identity_from_request();
        if ($identity !== null
            && preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $candidateVisitor)
            && preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $candidateSession)
            && hash_equals((string)$identity['visitor_id'], $candidateVisitor)
            && hash_equals((string)$identity['session_id'], $candidateSession)) {
            $clientIp = vjt_get_client_ip();
            $resolved = (vjt_ip_is_excluded($clientIp) || vjt_tracking_admin_excluded())
                ? ['valid' => false]
                : vjt_wait_for_analytics_link(
                    $candidateVisitor,
                    $candidateSession,
                    $candidateStep,
                    $pagePath
                );
            if (!empty($resolved['valid'])) {
                $visitorId = $candidateVisitor;
                $sessionId = $candidateSession;
                $journeyStep = (int)($resolved['journey_step'] ?? 0);
            }
        }
    }

    $db = vjt_db();
    $db->beginTransaction();
    if (!kssmi_vjt_consume_capability($capability)) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Event capability already used']);
        exit;
    }
    $result = vjt_add_contact_event([
        'event_id' => $eventId,
        'channel' => $channel,
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => $pagePath,
        'placement' => $placement,
        'product_sku' => $productSku,
        'site_language' => $siteLanguage,
        'vjt_visitor_id' => $visitorId,
        'vjt_session_id' => $sessionId,
        'journey_step' => $journeyStep,
    ]);
    $db->commit();
    echo json_encode(['success' => true] + $result);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('Contact Core POST error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
