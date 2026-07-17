<?php
/**
 * VJT Submission Tracking Endpoint
 * Receives form submission data from client-side tracker. Stores in JSON flat files.
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

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

// Contact intents are low-cost analytics writes. Keep a finite per-IP limit,
// while allowing shared mobile/corporate networks and queued retries to work.
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-sub', 30, 60)) {
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
$data['submit_page'] = vjt_safe_http_url($data['submit_page'] ?? '');
$data['contact_url'] = vjt_safe_http_url($data['contact_url'] ?? '');
$data['landing_url'] = vjt_safe_http_url($data['landing_url'] ?? '');
$data['referrer'] = vjt_safe_http_url($data['referrer'] ?? '');

$visitorId = is_scalar($data['visitor_id'] ?? null) ? vjt_clip(trim((string)$data['visitor_id']), 64) : '';
$sessionId = is_scalar($data['session_id'] ?? null) ? vjt_clip(trim((string)$data['session_id']), 64) : '';
$eventId = is_scalar($data['event_id'] ?? null) ? vjt_clip(trim((string)$data['event_id']), 96) : '';
if (!preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $visitorId) || !preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid visitor_id or session_id']);
    exit;
}
if ($eventId !== '' && !preg_match('/^vjtev_[A-Za-z0-9_-]{8,80}$/', $eventId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid event_id']);
    exit;
}

$ip      = vjt_get_client_ip();
$ua      = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);

if (vjt_ip_is_excluded($ip)) {
    echo json_encode(['success' => true, 'result' => 'skipped_internal']);
    exit;
}

// Bot filtering: skip storage for crawlers/scripts/empty UA (return 200 so they don't retry)
if (vjt_is_bot($ua)) {
    echo json_encode(['success' => true, 'result' => 'skipped_bot']);
    exit;
}

// Only valid, non-bot writes should trigger schema migrations/backfills/cleanup.
vjt_data_init();

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
            if (!is_array($item) || empty($item['url'])) continue;
            $snapshotUrl = vjt_safe_http_url($item['url']);
            if ($snapshotUrl === '') continue;
            $cleanSnapshot[] = [
                'visitor_id' => $visitorId,
                'url' => $snapshotUrl,
                'title' => is_scalar($item['title'] ?? null) ? vjt_clip((string)$item['title'], 512) : '',
                'visited_at' => is_scalar($item['visited_at'] ?? null) ? vjt_clip((string)$item['visited_at'], 64) : '',
            ];
        }
        vjt_sync_pageview_snapshot($sessionId, $cleanSnapshot);
    }

    // Store submission
    $status = in_array($data['status'] ?? '', ['attempt', 'success', 'error', 'intent'], true) ? $data['status'] : 'attempt';
    $writeResult = vjt_add_submission([
        'visitor_id'   => $visitorId,
        'session_id'   => $sessionId,
        'form_plugin'  => $data['form_plugin'] ?? 'generic',
        'form_id'      => $data['form_id'] ?? '',
        'form_name'    => $data['form_name'] ?? '',
        'submit_page'  => $data['submit_page'] ?? '',
        'submit_title' => $data['submit_title'] ?? '',
        'event_id'     => $eventId,
        'status'       => $status,
        'contact_url'  => $data['contact_url'] ?? '',
        'ip'           => $ip,
        'country'      => $geo['country'],
        'city'         => $geo['city'],
        'region'       => $geo['region'],
        'calling_code' => $geo['calling_code'],
    ]);

    echo json_encode(['success' => true, 'result' => $writeResult]);

} catch (Exception $e) {
    error_log('VJT submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
