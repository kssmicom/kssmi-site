<?php
/**
 * VJT Database Helper
 * Shared SQLite connection and utility functions.
 */

define('VJT_DB_PATH', dirname(__DIR__, 2) . '/vjt/tracker.sqlite');

function vjt_db() {
    static $db = null;
    if ($db === null) {
        if (!file_exists(VJT_DB_PATH)) {
            return null;
        }
        $db = new PDO('sqlite:' . VJT_DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
    }
    return $db;
}

function vjt_get_client_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '';
}

function vjt_detect_browser($ua) {
    $ua = strtolower($ua);
    if (strpos($ua, 'edg/') !== false) return 'Edge';
    if (strpos($ua, 'chrome/') !== false && strpos($ua, 'edg/') === false) return 'Chrome';
    if (strpos($ua, 'firefox/') !== false) return 'Firefox';
    if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) return 'Safari';
    if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) return 'Opera';
    return 'Unknown';
}

function vjt_detect_device($ua) {
    $ua = strtolower($ua);
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) return 'tablet';
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'android') !== false) return 'mobile';
    return 'desktop';
}

function vjt_resolve_geo($ip, $db) {
    // Skip for private/local IPs
    if (in_array($ip, ['127.0.0.1', '::1', '']) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    }

    // Check cache (24h)
    try {
        $stmt = $db->prepare("SELECT * FROM geo_cache WHERE ip = ? AND cached_at > datetime('now', '-1 day')");
        $stmt->execute([$ip]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached) {
            return [
                'country' => $cached['country'],
                'city' => $cached['city'],
                'region' => $cached['region'],
                'calling_code' => $cached['calling_code']
            ];
        }
    } catch (Exception $e) {}

    // Resolve via ip-api.com
    $result = ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    try {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode,city,regionName,callingCode");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['countryCode'])) {
                $result = [
                    'country' => $data['countryCode'] ?? '',
                    'city' => $data['city'] ?? '',
                    'region' => $data['regionName'] ?? '',
                    'calling_code' => isset($data['callingCode']) ? (string)$data['callingCode'] : ''
                ];

                // Cache result
                try {
                    $stmt = $db->prepare("INSERT OR REPLACE INTO geo_cache (ip, country, city, region, calling_code, cached_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
                    $stmt->execute([$ip, $result['country'], $result['city'], $result['region'], $result['calling_code']]);
                } catch (Exception $e) {}
            }
        }
    } catch (Exception $e) {}

    return $result;
}

function vjt_classify_source($session) {
    $referrer   = $session['referrer'] ?? '';
    $utm_medium = $session['utm_medium'] ?? '';
    $utm_source = $session['utm_source'] ?? '';

    $adsMediums = ['cpc', 'paid', 'ppc', 'ads'];
    if (in_array(strtolower($utm_medium), $adsMediums, true)) {
        return 'ads';
    }

    $searchEngines = ['google.', 'bing.', 'yahoo.', 'baidu.', 'duckduckgo.', 'yandex.', 'ask.', 'aol.', 'chatgpt.com', 'perplexity.ai', 'claude.ai'];
    foreach ($searchEngines as $se) {
        if (stripos($referrer, $se) !== false) return 'search';
    }

    $socialPlatforms = ['facebook.', 'instagram.', 'twitter.', 'x.com', 'linkedin.', 'youtube.', 'tiktok.', 'pinterest.', 'reddit.', 'weibo.', 't.co', 'fb.me', 'fb.com'];
    foreach ($socialPlatforms as $sp) {
        if (stripos($referrer, $sp) !== false) return 'social';
    }

    if (empty($referrer) || $referrer === 'direct') return 'direct';

    return 'other';
}
