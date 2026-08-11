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

require_once __DIR__ . '/vjt-helpers.php';
require_once dirname(__DIR__, 2) . '/private/vjt-event-auth.php';

$corsAllowed = kssmi_vjt_apply_cors();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code($corsAllowed ? 204 : 403);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}
if (!$corsAllowed || !kssmi_vjt_same_site_issuance_request()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Event write rejected']);
    exit;
}

// Keep a finite request-rate guard before parsing the body. The durable-write
// quota below is charged after the bounded snapshot has been validated.
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('track-sub-request', 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}

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

// This public endpoint records browser diagnostics only. Final business
// outcomes are written exclusively by send-mail.php after SMTP processing.
$requestedStatus = is_scalar($data['status'] ?? null) ? (string)$data['status'] : 'attempt';
if ($requestedStatus !== 'attempt') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only attempt status is accepted']);
    exit;
}

$identity = kssmi_vjt_identity_from_request();
$capabilityToken = is_scalar($data['capability_token'] ?? null)
    ? (string)$data['capability_token'] : '';
if ($identity === null
    || !hash_equals((string)$identity['visitor_id'], $visitorId)
    || !hash_equals((string)$identity['session_id'], $sessionId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid event identity']);
    exit;
}
$capability = kssmi_vjt_validate_capability($capabilityToken, 'submission', $identity);
if ($capability === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid event capability']);
    exit;
}

$ip      = vjt_get_client_ip();
$ua      = vjt_clip($_SERVER['HTTP_USER_AGENT'] ?? '', 512);

if (vjt_ip_is_excluded($ip) || vjt_tracking_admin_excluded()) {
    echo json_encode(['success' => true, 'result' => 'skipped_internal']);
    exit;
}

// Bot filtering: skip storage for crawlers/scripts/empty UA (return 200 so they don't retry)
if (vjt_is_bot($ua)) {
    echo json_encode(['success' => true, 'result' => 'skipped_bot']);
    exit;
}

// A submission can create more than one durable row. Normalize it into a
// bounded snapshot before spending the write-cost quota or opening a DB write
// transaction. A 20-page client payload retains only the final 8 pages.
$snapshot = $data['path_snapshot'] ?? [];
if (is_string($snapshot)) $snapshot = json_decode($snapshot, true);
$cleanSnapshot = [];
if (is_array($snapshot)) {
    foreach (array_slice($snapshot, -VJT_SUBMISSION_SNAPSHOT_MAX_ROWS) as $item) {
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
}
if (!checkRateLimitCost('track-sub-write', 120, 60, vjt_submission_write_cost(count($cleanSnapshot)))) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many write units']);
    exit;
}

// Only valid, non-bot writes should trigger schema migrations/backfills/cleanup.
vjt_data_init();

$browser = vjt_detect_browser($ua);
$device  = vjt_detect_device($ua);

try {
    $db = vjt_db();
    $db->beginTransaction();
    $writeBudget = vjt_submission_write_budget($visitorId, $sessionId, $cleanSnapshot);
    if (empty($writeBudget['ok'])) {
        vjt_log_submission_budget_rejection($writeBudget['reason'] ?? 'unknown');
        $db->rollBack();
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Storage capacity reached']);
        exit;
    }
    if (!kssmi_vjt_consume_capability($capability)) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Event capability already used']);
        exit;
    }
    // Geo misses enqueue one bounded row on this same transaction, so a
    // capacity rejection above cannot leave any geo, capability, or analytics
    // state behind.
    $geo = vjt_resolve_geo($ip);
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

    // Sync the bounded snapshot only after all session/SQLite budgets pass.
    if (!empty($cleanSnapshot)) {
        vjt_sync_pageview_snapshot($sessionId, $cleanSnapshot);
    }

    // Store submission
    $writeResult = vjt_add_submission([
        'visitor_id'   => $visitorId,
        'session_id'   => $sessionId,
        'form_plugin'  => $data['form_plugin'] ?? 'generic',
        'form_id'      => $data['form_id'] ?? '',
        'form_name'    => $data['form_name'] ?? '',
        'submit_page'  => $data['submit_page'] ?? '',
        'submit_title' => $data['submit_title'] ?? '',
        'event_id'     => $eventId,
        'status'       => 'attempt',
        'contact_url'  => $data['contact_url'] ?? '',
        'ip'           => $ip,
        'country'      => $geo['country'],
        'city'         => $geo['city'],
        'region'       => $geo['region'],
        'calling_code' => $geo['calling_code'],
    ]);

    $db->commit();
    echo json_encode(['success' => true, 'result' => $writeResult]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('VJT submission error: ' . $e->getMessage());
    if ($e instanceof PDOException && strpos($e->getMessage(), 'budget exceeded') !== false) {
        vjt_log_submission_budget_rejection('sqlite_trigger');
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Storage capacity reached']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
