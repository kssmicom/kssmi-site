<?php
/**
 * VJT Pageview Tracking Endpoint
 * Receives pageview data from client-side tracker and stores in SQLite.
 */

header('Content-Type: application/json');

// CORS - only allow from kssmi.com
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://kssmi.com', 'https://www.kssmi.com', 'http://localhost:4321', 'http://localhost:4324', 'http://localhost:4325', 'http://127.0.0.1:4321'];
if (!in_array($origin, $allowedOrigins)) {
    // Also allow same-origin (no Origin header)
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

// Parse JSON payload
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

$ip       = vjt_get_client_ip();
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
$browser  = vjt_detect_browser($ua);
$device   = vjt_detect_device($ua);
$geo      = vjt_resolve_geo($ip, $db);
$now      = date('Y-m-d H:i:s');

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
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $visitorId, $ip, $geo['country'], $geo['city'], $ua, $browser, $device,
            $data['screen_resolution'] ?? '', $data['timezone'] ?? '', $data['language'] ?? '', $now, $now
        ]);
    }

    // Upsert session
    $stmt = $db->prepare("SELECT id, referrer, landing_url, landing_title, country, city, region, calling_code FROM sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existingSession = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSession) {
        // Guard: preserve existing non-empty values when new value is empty
        $fields = ['referrer', 'landing_url', 'landing_title', 'country', 'city', 'region', 'calling_code', 'ip', 'visitor_id'];
        $update = [
            'last_seen_at' => $now,
            'visitor_id' => $visitorId,
            'ip' => $ip,
            'referrer' => $data['referrer'] ?? '',
            'landing_url' => $data['landing_url'] ?? '',
            'landing_title' => $data['landing_title'] ?? '',
            'country' => $geo['country'],
            'city' => $geo['city'],
            'region' => $geo['region'],
            'calling_code' => $geo['calling_code'],
        ];
        foreach ($update as $field => $newVal) {
            if (empty($newVal) && !empty($existingSession[$field])) {
                unset($update[$field]);
            }
        }
        if (!empty($update)) {
            $setClauses = array_map(function($f) { return "$f = ?"; }, array_keys($update));
            $sql = "UPDATE sessions SET " . implode(', ', $setClauses) . ", updated_at = datetime('now') WHERE session_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge(array_values($update), [$sessionId]));
        }
    } else {
        $stmt = $db->prepare("INSERT INTO sessions (session_id, visitor_id, ip, country, city, region, calling_code, referrer, landing_url, landing_title, utm_source, utm_medium, utm_campaign, utm_content, utm_term, started_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $sessionId, $visitorId, $ip, $geo['country'], $geo['city'], $geo['region'], $geo['calling_code'],
            $data['referrer'] ?? '', $data['landing_url'] ?? '', $data['landing_title'] ?? '',
            $data['utm_source'] ?? '', $data['utm_medium'] ?? '', $data['utm_campaign'] ?? '',
            $data['utm_content'] ?? '', $data['utm_term'] ?? '',
            $data['session_started_at'] ?? $now, $now
        ]);
    }

    // Upsert pageview
    $stepOrder    = max(1, (int)($data['step_order'] ?? 1));
    $visitedAt    = $data['visited_at'] ?? $now;
    $leaveAt      = $data['leave_at'] ?? null;
    $durationSecs = max(0, (int)($data['duration_seconds'] ?? 0));
    $scrollDepth  = min(100, max(0, (int)($data['scroll_depth'] ?? 0)));

    $stmt = $db->prepare("SELECT id FROM pageviews WHERE session_id = ? AND step_order = ? AND visited_at = ?");
    $stmt->execute([$sessionId, $stepOrder, $visitedAt]);
    $existingPv = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingPv) {
        $stmt = $db->prepare("UPDATE pageviews SET leave_at = ?, duration_seconds = ?, scroll_depth = ? WHERE id = ?");
        $stmt->execute([$leaveAt, $durationSecs, $scrollDepth, $existingPv['id']]);
        $pageviewId = $existingPv['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO pageviews (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, scroll_depth, step_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $visitorId, $data['url'] ?? '', $data['title'] ?? '', $visitedAt, $leaveAt, $durationSecs, $scrollDepth, $stepOrder]);
        $pageviewId = $db->lastInsertId();
    }

    echo json_encode(['success' => true, 'pageview_id' => $pageviewId]);

} catch (Exception $e) {
    error_log('VJT pageview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
