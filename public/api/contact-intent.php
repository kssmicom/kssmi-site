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

$channel = strtolower(trim((string)($_GET['channel'] ?? '')));
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

try {
    require_once __DIR__ . '/vjt-helpers.php';
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($shouldRecord && !vjt_is_bot($ua)) {
        vjt_data_init();

        $pagePath = '';
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            $host = strtolower((string)($parts['host'] ?? ''));
            $allowedHosts = ['kssmi.com', 'www.kssmi.com', 'localhost', '127.0.0.1'];
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
        if (($_GET['analytics'] ?? '') === '1') {
            $visitorId = trim((string)($_COOKIE['vjt_visitor_id'] ?? ''));
            $sessionId = trim((string)($_COOKIE['vjt_session_id'] ?? ''));
        }

        vjt_add_contact_event([
            'channel' => $channel,
            'event_type' => 'open_intent',
            'status' => 'intent',
            'page_path' => $pagePath,
            'placement' => $_GET['placement'] ?? '',
            'product_sku' => $productSku,
            'site_language' => $siteLanguage,
            'vjt_visitor_id' => $visitorId,
            'vjt_session_id' => $sessionId,
        ]);
    }
} catch (Throwable $e) {
    // Recording must never stop a customer from opening their chosen channel.
    error_log('Contact Core write error: ' . $e->getMessage());
}

header('Location: ' . $destination, true, 302);
exit;
