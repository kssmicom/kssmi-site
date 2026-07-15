<?php
/**
 * VJT Pageview Tracking Endpoint
 * Receives pageview data from client-side tracker. Stores in JSON flat files.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://kssmi.com', 'https://www.kssmi.com', 'http://localhost:4321', 'http://localhost:4324', 'http://localhost:4325', 'http://127.0.0.1:4321'];
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

// Rate limit: 30 pageview writes per IP per 60s (prevents SQLite fill attacks
// and protects analytics data integrity from scripted flooding)
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-pv', 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/vjt-helpers.php';

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 65536) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 65537);
if ($body === false || strlen($body) > 65536) { // 64KB hard cap — real payloads are a few KB
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

// Clip free-text fields to guard against storage bloat / abuse
foreach (vjt_field_caps() as $k => $max) {
    if (isset($data[$k])) $data[$k] = is_scalar($data[$k]) ? vjt_clip((string)$data[$k], $max) : '';
}
$data['url'] = vjt_safe_http_url($data['url'] ?? '');
$data['landing_url'] = vjt_safe_http_url($data['landing_url'] ?? '');
$data['referrer'] = vjt_safe_http_url($data['referrer'] ?? '');
if ($data['url'] === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid page URL']);
    exit;
}
foreach (['visited_at', 'leave_at', 'last_activity_at'] as $field) {
    $data[$field] = is_scalar($data[$field] ?? null) ? vjt_clip((string)$data[$field], 64) : '';
}
foreach (['duration_seconds', 'active_duration_seconds'] as $field) {
    $data[$field] = is_numeric($data[$field] ?? null) ? min(86400, max(0, (int)$data[$field])) : 0;
}
foreach (['scroll_depth', 'max_scroll_depth', 'engagement_score'] as $field) {
    $data[$field] = is_numeric($data[$field] ?? null) ? min(100, max(0, (int)$data[$field])) : 0;
}
$data['heartbeat_count'] = is_numeric($data['heartbeat_count'] ?? null) ? min(10000, max(0, (int)$data['heartbeat_count'])) : 0;
$data['step_order'] = is_numeric($data['step_order'] ?? null) ? min(10000, max(0, (int)$data['step_order'])) : 0;
$data['event_type'] = is_scalar($data['event_type'] ?? null) ? (string)$data['event_type'] : '';

$visitorId = is_scalar($data['visitor_id'] ?? null) ? vjt_clip(trim((string)$data['visitor_id']), 64) : '';
$sessionId = is_scalar($data['session_id'] ?? null) ? vjt_clip(trim((string)$data['session_id']), 64) : '';
if (!preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $visitorId) || !preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid visitor_id or session_id']);
    exit;
}

$ip      = vjt_get_client_ip();
$ua      = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);

if (vjt_ip_is_excluded($ip)) {
    echo json_encode(['success' => true, 'skipped' => 'internal']);
    exit;
}

// Bot filtering: skip storage for crawlers/scripts/empty UA (return 200 so they don't retry)
if (vjt_is_bot($ua)) {
    echo json_encode(['success' => true, 'skipped' => 'bot']);
    exit;
}

// Only valid, non-bot writes should trigger schema migrations/backfills/cleanup.
vjt_data_init();

$browser = vjt_detect_browser($ua);
$device  = vjt_detect_device($ua);
$geo     = vjt_resolve_geo($ip);
$now     = date('Y-m-d H:i:s');

try {
    // Upsert visitor
    vjt_upsert_visitor([
        'visitor_id'        => $visitorId,
        'first_ip'          => $ip,
        'country'           => $geo['country'],
        'city'              => $geo['city'],
        'user_agent'        => $ua,
        'browser'           => $browser,
        'device_type'       => $device,
        'screen_resolution' => $data['screen_resolution'] ?? '',
        'timezone'          => $data['timezone'] ?? '',
        'language'          => $data['language'] ?? '',
        'site_language'     => $data['site_language'] ?? 'EN',
    ]);

    // Upsert session
    vjt_upsert_session([
        'session_id'    => $sessionId,
        'visitor_id'    => $visitorId,
        'ip'            => $ip,
        'country'       => $geo['country'],
        'city'          => $geo['city'],
        'region'        => $geo['region'],
        'calling_code'  => $geo['calling_code'],
        'referrer'      => $data['referrer'] ?? '',
        'landing_url'   => $data['landing_url'] ?? '',
        'landing_title' => $data['landing_title'] ?? '',
        'utm_source'    => $data['utm_source'] ?? '',
        'utm_medium'    => $data['utm_medium'] ?? '',
        'utm_campaign'  => $data['utm_campaign'] ?? '',
        'utm_content'   => $data['utm_content'] ?? '',
        'utm_term'      => $data['utm_term'] ?? '',
    ]);

    // Handle leave event (update existing pageview) vs new pageview
    $isLeave = !empty($data['leave_at']);
    $isHeartbeat = ($data['event_type'] ?? '') === 'heartbeat';
    if ($isLeave || $isHeartbeat) {
        vjt_update_pageview_leave(
            $sessionId,
            $data['url'] ?? '',
            $data['leave_at'],
            $data['duration_seconds'] ?? 0,
            $data['scroll_depth'] ?? 0,
            $data['active_duration_seconds'] ?? 0,
            $data['heartbeat_count'] ?? 0,
            $data['max_scroll_depth'] ?? 0,
            $data['last_activity_at'] ?? '',
            $data['engagement_score'] ?? 0,
            !empty($data['is_engaged']),
            max(0, (int)($data['step_order'] ?? 0))
        );
    } else {
        vjt_add_pageview([
            'session_id'       => $sessionId,
            'visitor_id'       => $visitorId,
            'url'              => $data['url'] ?? '',
            'title'            => $data['title'] ?? '',
            'visited_at'       => $data['visited_at'] ?? $now,
            'leave_at'         => $data['leave_at'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? 0,
            'active_duration_seconds' => $data['active_duration_seconds'] ?? 0,
            'engagement_score' => $data['engagement_score'] ?? 0,
            'is_engaged'       => !empty($data['is_engaged']),
            'last_activity_at' => $data['last_activity_at'] ?? '',
            'heartbeat_count'  => $data['heartbeat_count'] ?? 0,
            'scroll_depth'     => $data['scroll_depth'] ?? 0,
            'max_scroll_depth' => $data['max_scroll_depth'] ?? ($data['scroll_depth'] ?? 0),
            'step_order'       => max(1, (int)($data['step_order'] ?? 1)),
        ]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('VJT pageview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
