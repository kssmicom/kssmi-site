<?php
/**
 * Anonymous Page View Counter (consent-independent, anonymous aggregate)
 *
 * Counts only "this page was opened": (Beijing day, root-relative path) → +1.
 * The anon_views table stores only aggregate counts: a technical primary key
 * (id) plus (day, url, views) — no visitor/session identifiers, no IP, no
 * user agent, no referrer, no per-event timestamps.
 * IP and UA are used in memory for rate limiting / bot filtering and are
 * never written into anon_views. Separate security processing (rate-limit
 * counters, the admin exclusion marker, web-server logs) is handled by the
 * existing shared modules and is described in the privacy policy.
 * This endpoint deliberately never reads or writes the consent-gated VJT
 * Journey tables (visitors/sessions/pageviews/submissions/contact_events).
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://kssmi.com', 'https://www.kssmi.com', 'http://localhost:4321', 'http://localhost:4324', 'http://localhost:4325', 'http://127.0.0.1:4321', 'http://127.0.0.1:4324', 'http://127.0.0.1:4325'];
if (!in_array($origin, $allowedOrigins, true)) {
    if (!empty($origin)) {
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

// Lenient per-IP cap (one counter hit per page view). The IP is used for rate
// limiting / exclusions and is never written into anon_views; the shared rate
// limiter may persist IP-derived counters (see private/rate-limit.php).
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-anon', 60, 60)) {
    http_response_code(429);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/vjt-helpers.php';

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) {
    http_response_code(413);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 4097);
if ($body === false || strlen($body) > 4096) { // 4KB cap — payload is a single path
    http_response_code(413);
    exit;
}
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    exit;
}

// Only a root-relative path is accepted (scheme/host/query intentionally rejected).
$path = vjt_safe_anon_path($data['url'] ?? '');
if ($path === '') {
    http_response_code(400);
    exit;
}

$ip = vjt_get_client_ip();
if (vjt_ip_is_excluded($ip) || vjt_tracking_admin_excluded()) {
    http_response_code(204);
    exit;
}

$ua = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);
if (vjt_is_bot($ua)) {
    http_response_code(204);
    exit;
}

// Lightweight init: ensures only the anonymous table + index + meta exist.
// Unlike vjt_data_init(), it never triggers VJT schema migrations, JSON
// migration, source backfill or cleanup from this public endpoint.
vjt_anon_init();
vjt_upsert_anon_view(vjt_anon_day(), $path);

http_response_code(204);
