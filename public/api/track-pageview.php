<?php
/**
 * VJT Pageview Tracking Endpoint
 * Receives pageview data from client-side tracker. Stores in JSON flat files.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json');

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://kssmi.com', 'https://www.kssmi.com', 'http://localhost:4321', 'http://localhost:4324', 'http://localhost:4325', 'http://127.0.0.1:4321'];
if (!in_array($origin, $allowedOrigins)) {
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
require_once __DIR__ . '/rate-limit.php';
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

// Ensure data directory and files exist
vjt_data_init();

$body = file_get_contents('php://input');
if (strlen($body) > 65536) { // 64KB hard cap — real payloads are a few KB
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
foreach (vjt_field_caps() as $k => $max) { if (isset($data[$k])) $data[$k] = vjt_clip($data[$k], $max); }

$visitorId = vjt_clip(trim($data['visitor_id'] ?? ''), 64);
$sessionId = vjt_clip(trim($data['session_id'] ?? ''), 64);
if (empty($visitorId) || empty($sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing visitor_id or session_id']);
    exit;
}

$ip      = vjt_get_client_ip();
$ua      = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);

// Bot filtering: skip storage for crawlers/scripts/empty UA (return 200 so they don't retry)
if (vjt_is_bot($ua)) {
    echo json_encode(['success' => true, 'skipped' => 'bot']);
    exit;
}

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
    if ($isLeave) {
        vjt_update_pageview_leave(
            $sessionId,
            $data['url'] ?? '',
            $data['leave_at'],
            $data['duration_seconds'] ?? 0,
            $data['scroll_depth'] ?? 0
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
            'scroll_depth'     => $data['scroll_depth'] ?? 0,
            'step_order'       => max(1, (int)($data['step_order'] ?? 1)),
        ]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('VJT pageview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
