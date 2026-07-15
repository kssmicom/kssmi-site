<?php
// Public, non-identifying tracker tuning values. No visitor data or secrets.
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://kssmi.com', 'https://www.kssmi.com', 'http://localhost:4321', 'http://localhost:4324', 'http://localhost:4325', 'http://127.0.0.1:4321'];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) header('Access-Control-Allow-Origin: ' . $origin);
require_once __DIR__ . '/vjt-helpers.php';
$settings = file_exists(VJT_DB_PATH) ? vjt_get_settings() : ['heartbeat_seconds' => '45'];
if ($method === 'HEAD') exit;
echo json_encode([
    'heartbeat_seconds' => min(120, max(30, (int)($settings['heartbeat_seconds'] ?? 45))),
]);
