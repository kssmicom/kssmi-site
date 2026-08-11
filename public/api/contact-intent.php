<?php
/**
 * Consent-independent Contact Core entry point.
 *
 * Records only the minimum contact intent, then immediately redirects to the
 * fixed WhatsApp or email destination. It never creates analytics identifiers,
 * reads browser storage, resolves geo data, or reconstructs a visitor journey.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$channelParam = $_GET['channel'] ?? '';
$channel = is_scalar($channelParam) ? strtolower(trim((string)$channelParam)) : '';
$destinations = [
    'whatsapp' => 'https://wa.me/8613510532553',
    'mailto' => 'mailto:sales@kssmi.com',
];
if (!isset($destinations[$channel])) {
    http_response_code(400);
    exit;
}
$destination = $destinations[$channel];

// The contact function must continue even when logging is unavailable or a
// crawler/rate-limited client follows the URL.
$shouldRecord = true;
try {
    require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
    $shouldRecord = checkRateLimit('contact-intent', 20, 60);
} catch (Throwable $e) {
    $shouldRecord = true;
    error_log('Contact Core rate-limit error: ' . $e->getMessage());
}

// This GET endpoint exists to preserve native WhatsApp/mail navigation, so it
// can be embedded or prefetched without JavaScript. Do not turn cross-site
// requests, images/subresources, or speculative prefetches into fake intents.
$fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
$fetchMode = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')));
$purpose = strtolower(trim((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? ($_SERVER['HTTP_PURPOSE'] ?? ''))));
if (!in_array($fetchSite, ['same-origin', 'same-site'], true)
    || $fetchMode !== 'navigate'
    || strpos($purpose, 'prefetch') !== false) {
    $shouldRecord = false;
}

try {
    require_once __DIR__ . '/vjt-helpers.php';
    require_once dirname(__DIR__, 2) . '/private/vjt-event-auth.php';
    $contactSession = kssmi_vjt_contact_session_from_request();
    $capabilityToken = is_scalar($_GET['capability_token'] ?? null)
        ? (string)$_GET['capability_token'] : '';
    $capability = $contactSession === null ? null : kssmi_vjt_validate_capability(
        $capabilityToken,
        'contact_intent',
        $contactSession
    );
    if ($capability === null) $shouldRecord = false;

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($shouldRecord && !vjt_is_bot($ua)) {
        vjt_data_init();

        $pagePath = '';
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            $host = strtolower((string)($parts['host'] ?? ''));
            $allowedHosts = ['kssmi.com', 'www.kssmi.com', 'localhost', '127.0.0.1', '::1', '[::1]'];
            if (in_array($host, $allowedHosts, true)) {
                $pagePath = vjt_contact_page_path((string)($parts['path'] ?? ''));
            }
        }

        $siteLanguage = 'EN';
        $knownLanguages = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
        $segments = array_values(array_filter(explode('/', trim($pagePath, '/')), 'strlen'));
        if (!empty($segments[0]) && in_array(strtolower($segments[0]), $knownLanguages, true)) {
            $siteLanguage = strtoupper($segments[0]);
        }

        $productSku = '';
        $productIndex = (!empty($segments[0]) && in_array(strtolower($segments[0]), $knownLanguages, true)) ? 1 : 0;
        if (($segments[$productIndex] ?? '') === 'product' && !empty($segments[$productIndex + 1])) {
            $productSku = vjt_contact_token($segments[$productIndex + 1], 80);
        }

        // Only the consent-gated VJT script appends analytics=1. Without that
        // explicit signal, any old/stale VJT cookies are ignored.
        $visitorId = '';
        $sessionId = '';
        $journeyStep = 0;
        if (is_scalar($_GET['analytics'] ?? null) && (string)$_GET['analytics'] === '1') {
            $visitorId = trim((string)($_COOKIE['vjt_visitor_id'] ?? ''));
            $sessionId = trim((string)($_COOKIE['vjt_session_id'] ?? ''));
            $journeyStep = is_scalar($_GET['step'] ?? null) && is_numeric($_GET['step'])
                ? min(10000, max(0, (int)$_GET['step'])) : 0;
            // A browser-controlled ID format is not enough to establish a
            // Journey link. Excluded traffic and stale/forged pairs keep the
            // business event, but it remains safely unattributed. A short
            // bounded retry handles a first click that overtakes pageview POST.
            $identity = kssmi_vjt_identity_from_request();
            $clientIp = vjt_get_client_ip();
            $identityMatches = $identity !== null
                && hash_equals((string)$identity['visitor_id'], $visitorId)
                && hash_equals((string)$identity['session_id'], $sessionId);
            $resolvedLink = (!$identityMatches || vjt_ip_is_excluded($clientIp) || vjt_tracking_admin_excluded())
                ? ['valid' => false]
                : vjt_wait_for_analytics_link($visitorId, $sessionId, $journeyStep, $pagePath);
            if (empty($resolvedLink['valid'])) {
                $visitorId = '';
                $sessionId = '';
                $journeyStep = 0;
            }
        }

        $db = vjt_db();
        $db->beginTransaction();
        if (!kssmi_vjt_consume_capability($capability)) {
            $db->rollBack();
            $shouldRecord = false;
        }
        if ($shouldRecord) vjt_add_contact_event([
            'channel' => $channel,
            'event_type' => 'open_intent',
            'status' => 'intent',
            'page_path' => $pagePath,
            'placement' => is_scalar($_GET['placement'] ?? null) ? (string)$_GET['placement'] : '',
            'product_sku' => $productSku,
            'site_language' => $siteLanguage,
            'vjt_visitor_id' => $visitorId,
            'vjt_session_id' => $sessionId,
            'journey_step' => $journeyStep,
        ]);
        if ($db->inTransaction()) $db->commit();
    }
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    // Recording must never stop a customer from opening their chosen channel.
    error_log('Contact Core write error: ' . $e->getMessage());
}

header('Location: ' . $destination, true, 302);
exit;
