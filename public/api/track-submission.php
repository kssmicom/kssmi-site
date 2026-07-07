<?php
/**
 * VJT Submission Tracking Endpoint
 * Receives form submission data from client-side tracker. Stores in JSON flat files.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json');

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

// Rate limit: 10 submission writes per IP per 60s (prevents SQLite fill attacks
// and protects form-submission analytics from scripted flooding)
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-sub', 10, 60)) {
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

try {
    // Upsert visitor
    vjt_upsert_visitor([
        'visitor_id'    => $visitorId,
        'first_ip'      => $ip,
        'country'       => $geo['country'],
        'city'          => $geo['city'],
        'user_agent'    => $ua,
        'browser'       => $browser,
        'device_type'   => $device,
        'site_language' => $data['site_language'] ?? 'EN',
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

    // Sync pageview snapshot from path_snapshot
    $snapshot = $data['path_snapshot'] ?? [];
    if (is_string($snapshot)) {
        $snapshot = json_decode($snapshot, true);
    }
    if (is_array($snapshot) && !empty($snapshot)) {
        $cleanSnapshot = [];
        foreach (array_slice($snapshot, -20) as $item) {
            if (is_array($item)) {
                $cleanSnapshot[] = $item;
            }
        }
        vjt_sync_pageview_snapshot($sessionId, $cleanSnapshot);
    }

    // Store submission
    $status = in_array($data['status'] ?? '', ['attempt', 'success', 'error']) ? $data['status'] : 'attempt';
    vjt_add_submission([
        'visitor_id'   => $visitorId,
        'session_id'   => $sessionId,
        'form_plugin'  => $data['form_plugin'] ?? 'generic',
        'form_id'      => $data['form_id'] ?? '',
        'form_name'    => $data['form_name'] ?? '',
        'submit_page'  => $data['submit_page'] ?? '',
        'submit_title' => $data['submit_title'] ?? '',
        'status'       => $status,
        'contact_url'  => $data['contact_url'] ?? '',
        'ip'           => $ip,
        'country'      => $geo['country'],
        'city'         => $geo['city'],
        'region'       => $geo['region'],
        'calling_code' => $geo['calling_code'],
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('VJT submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
