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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://kssmi.com',
    'https://www.kssmi.com',
    'http://localhost:4321',
    'http://localhost:4324',
    'http://localhost:4325',
    'http://127.0.0.1:4321',
];
if (!in_array($origin, $allowedOrigins, true)) {
    if ($origin !== '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Origin not allowed']);
        exit;
    }
} else {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Reject cross-site subresource abuse. Same-origin production requests and
// same-site localhost development requests remain allowed.
$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
if ($fetchSite === 'cross-site') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Cross-site request rejected']);
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

require_once __DIR__ . '/vjt-helpers.php';

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
    $allowedHosts = ['kssmi.com', 'www.kssmi.com', 'localhost', '127.0.0.1'];
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

        if (preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $candidateVisitor)
            && preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $candidateSession)) {
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
    echo json_encode(['success' => true] + $result);
} catch (Throwable $e) {
    error_log('Contact Core POST error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
