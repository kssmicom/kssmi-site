<?php
/**
 * IP Debug Endpoint
 * Visit this page to see all IP-related headers
 * Useful for debugging Cloudflare IP detection
 */

header('Content-Type: application/json; charset=utf-8');

$ipHeaders = [
    // Cloudflare specific
    'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
    'HTTP_CF_IPCOUNTRY' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
    'HTTP_CF_VISITOR' => $_SERVER['HTTP_CF_VISITOR'] ?? null,
    'HTTP_CF_RAY' => $_SERVER['HTTP_CF_RAY'] ?? null,

    // Common proxy headers
    'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
    'HTTP_X_CLIENT_IP' => $_SERVER['HTTP_X_CLIENT_IP'] ?? null,
    'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? null,
    'HTTP_X_CLUSTER_CLIENT_IP' => $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'] ?? null,
    'HTTP_FORWARDED_FOR' => $_SERVER['HTTP_FORWARDED_FOR'] ?? null,
    'HTTP_FORWARDED' => $_SERVER['HTTP_FORWARDED'] ?? null,

    // Standard
    'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null,

    // Request info
    'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'REQUEST_TIME' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
];

// Determine the real IP
$realIP = 'Unknown';
$ipSource = 'None';

if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    $realIP = $_SERVER['HTTP_CF_CONNECTING_IP'];
    $ipSource = 'HTTP_CF_CONNECTING_IP (Cloudflare)';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $realIP = trim($ips[0]);
    $ipSource = 'HTTP_X_FORWARDED_FOR';
} elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
    $realIP = $_SERVER['HTTP_X_REAL_IP'];
    $ipSource = 'HTTP_X_REAL_IP';
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
    $realIP = $_SERVER['REMOTE_ADDR'];
    $ipSource = 'REMOTE_ADDR';
}

// Get country from Cloudflare or IP-API
$country = 'Unknown';
$countrySource = 'None';

if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $country = $_SERVER['HTTP_CF_IPCOUNTRY'];
    $countrySource = 'HTTP_CF_IPCOUNTRY (Cloudflare)';
} elseif (!in_array(strtolower($realIP), ['127.0.0.1', '::1', 'unknown', 'localhost']) &&
           filter_var($realIP, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
    $ch = curl_init("http://ip-api.com/json/{$realIP}?fields=countryCode,country,city,isp&lang=en");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        $country = $data['countryCode'] ?? 'Unknown';
        $countrySource = 'IP-API (' . ($data['country'] ?? 'Unknown') . ', ' . ($data['city'] ?? 'Unknown') . ')';
    }
}

$result = [
    'status' => 'success',
    'visitor' => [
        'ip' => $realIP,
        'ip_source' => $ipSource,
        'country' => $country,
        'country_source' => $countrySource,
    ],
    'all_headers' => $ipHeaders,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'is_cloudflare' => !empty($_SERVER['HTTP_CF_RAY']),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
