<?php
/**
 * VJT Submission Tracking Endpoint
 * Receives form submission data from client-side tracker and stores in SQLite.
 */

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/vjt-helpers.php';

$db = vjt_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not initialized']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$visitorId = trim($data['visitor_id'] ?? '');
$sessionId = trim($data['session_id'] ?? '');

if (empty($visitorId) || empty($sessionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing visitor_id or session_id']);
    exit;
}

$ip      = vjt_get_client_ip();
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$browser = vjt_detect_browser($ua);
$device  = vjt_detect_device($ua);
$geo     = vjt_resolve_geo($ip, $db);
$now     = date('Y-m-d H:i:s');

try {
    // Upsert visitor
    $stmt = $db->prepare("SELECT id FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $db->prepare("UPDATE visitors SET last_seen_at = ?, updated_at = datetime('now') WHERE visitor_id = ?");
        $stmt->execute([$now, $visitorId]);
    } else {
        $stmt = $db->prepare("INSERT INTO visitors (visitor_id, first_ip, country, city, user_agent, browser, device_type, screen_resolution, timezone, language, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, '', '', '', ?, ?)");
        $stmt->execute([$visitorId, $ip, $geo['country'], $geo['city'], $ua, $browser, $device, $now, $now]);
    }

    // Upsert session
    $stmt = $db->prepare("SELECT id FROM sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existingSession = $stmt->fetch(PDO::FETCH_ASSOC);

    $submittedAt = $data['submitted_at'] ?? $now;

    if ($existingSession) {
        $stmt = $db->prepare("UPDATE sessions SET last_seen_at = ?, updated_at = datetime('now') WHERE session_id = ?");
        $stmt->execute([$submittedAt, $sessionId]);
    } else {
        $stmt = $db->prepare("INSERT INTO sessions (session_id, visitor_id, ip, country, city, region, calling_code, referrer, landing_url, landing_title, utm_source, utm_medium, utm_campaign, utm_content, utm_term, started_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $sessionId, $visitorId, $ip, $geo['country'], $geo['city'], $geo['region'], $geo['calling_code'],
            $data['referrer'] ?? '', $data['landing_url'] ?? '', $data['landing_title'] ?? '',
            $data['utm_source'] ?? '', $data['utm_medium'] ?? '', $data['utm_campaign'] ?? '',
            $data['utm_content'] ?? '', $data['utm_term'] ?? '',
            $submittedAt, $submittedAt
        ]);
    }

    // Sync pageview snapshot from path_snapshot
    $snapshot = $data['path_snapshot'] ?? [];
    if (is_string($snapshot)) {
        $snapshot = json_decode($snapshot, true);
    }
    if (is_array($snapshot)) {
        $snapshot = array_slice($snapshot, -20);
        foreach ($snapshot as $item) {
            if (!is_array($item)) continue;
            $pvStep    = max(1, (int)($item['step_order'] ?? 1));
            $pvVisited = $item['visited_at'] ?? $now;
            $stmt = $db->prepare("SELECT id FROM pageviews WHERE session_id = ? AND step_order = ? AND visited_at = ?");
            $stmt->execute([$sessionId, $pvStep, $pvVisited]);
            $existingPv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingPv) {
                $stmt = $db->prepare("INSERT INTO pageviews (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, scroll_depth, step_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $sessionId, $visitorId, $item['url'] ?? '', $item['title'] ?? '', $pvVisited,
                    $item['leave_at'] ?? null, max(0, (int)($item['duration_seconds'] ?? 0)),
                    min(100, max(0, (int)($item['scroll_depth'] ?? 0))), $pvStep
                ]);
            }
        }
    }

    // Store submission (upsert for recent attempts)
    $status     = in_array($data['status'] ?? '', ['attempt', 'success', 'error']) ? $data['status'] : 'attempt';
    $formPlugin = $data['form_plugin'] ?? 'generic';
    $formId     = $data['form_id'] ?? '';

    // Check for recent matching attempt to upgrade
    $stmt = $db->prepare("SELECT id, status FROM submissions WHERE session_id = ? AND (form_plugin = ? OR form_plugin = 'generic') AND (form_id = ? OR ? = '' OR form_id = '') AND submitted_at >= datetime('now', '-10 minutes') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sessionId, $formPlugin, $formId, $formId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($match) {
        $stmt = $db->prepare("UPDATE submissions SET visitor_id = ?, form_plugin = ?, form_id = ?, form_name = ?, submit_page = ?, submit_title = ?, submitted_at = ?, status = ?, contact_url = ?, ip = ?, country = ?, city = ?, region = ?, calling_code = ? WHERE id = ?");
        $stmt->execute([
            $visitorId, $formPlugin, $formId, $data['form_name'] ?? '', $data['submit_page'] ?? '',
            $data['submit_title'] ?? '', $submittedAt, $status, $data['contact_url'] ?? '',
            $ip, $geo['country'], $geo['city'], $geo['region'], $geo['calling_code'], $match['id']
        ]);
        $submissionId = $match['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO submissions (visitor_id, session_id, form_plugin, form_id, form_name, submit_page, submit_title, submitted_at, status, contact_url, ip, country, city, region, calling_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $visitorId, $sessionId, $formPlugin, $formId, $data['form_name'] ?? '',
            $data['submit_page'] ?? '', $data['submit_title'] ?? '', $submittedAt, $status,
            $data['contact_url'] ?? '', $ip, $geo['country'], $geo['city'], $geo['region'], $geo['calling_code']
        ]);
        $submissionId = $db->lastInsertId();
    }

    echo json_encode(['success' => true, 'submission_id' => $submissionId]);

} catch (Exception $e) {
    error_log('VJT submission error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
