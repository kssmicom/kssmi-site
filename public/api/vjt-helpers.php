<?php
/**
 * VJT Database Helper
 * Shared SQLite connection and utility functions.
 */

define('VJT_DB_DIR', dirname(__DIR__, 2) . '/vjt');
define('VJT_DB_PATH', VJT_DB_DIR . '/tracker.sqlite');

function vjt_db_setup($db) {
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    $db->exec("CREATE TABLE IF NOT EXISTS visitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id TEXT NOT NULL UNIQUE,
        first_ip TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        user_agent TEXT,
        browser TEXT NOT NULL DEFAULT '',
        device_type TEXT NOT NULL DEFAULT '',
        screen_resolution TEXT NOT NULL DEFAULT '',
        timezone TEXT NOT NULL DEFAULT '',
        language TEXT NOT NULL DEFAULT '',
        first_seen_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_visitors_last_seen ON visitors(last_seen_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL UNIQUE,
        visitor_id TEXT NOT NULL,
        ip TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        calling_code TEXT NOT NULL DEFAULT '',
        referrer TEXT,
        landing_url TEXT,
        landing_title TEXT NOT NULL DEFAULT '',
        utm_source TEXT NOT NULL DEFAULT '',
        utm_medium TEXT NOT NULL DEFAULT '',
        utm_campaign TEXT NOT NULL DEFAULT '',
        utm_content TEXT NOT NULL DEFAULT '',
        utm_term TEXT NOT NULL DEFAULT '',
        started_at TEXT NOT NULL,
        last_seen_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_visitor ON sessions(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_last_seen ON sessions(last_seen_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS pageviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id TEXT NOT NULL,
        visitor_id TEXT NOT NULL,
        url TEXT,
        title TEXT NOT NULL DEFAULT '',
        visited_at TEXT NOT NULL,
        leave_at TEXT,
        duration_seconds INTEGER UNSIGNED NOT NULL DEFAULT 0,
        scroll_depth INTEGER UNSIGNED NOT NULL DEFAULT 0,
        step_order INTEGER UNSIGNED NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pageviews_session ON pageviews(session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pageviews_visitor ON pageviews(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pageviews_visited ON pageviews(visited_at)");

    $db->exec("CREATE TABLE IF NOT EXISTS submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id TEXT NOT NULL,
        session_id TEXT NOT NULL,
        form_plugin TEXT NOT NULL DEFAULT '',
        form_id TEXT NOT NULL DEFAULT '',
        form_name TEXT NOT NULL DEFAULT '',
        submit_page TEXT,
        submit_title TEXT NOT NULL DEFAULT '',
        submitted_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'attempt',
        contact_url TEXT,
        ip TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        calling_code TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_submissions_visitor ON submissions(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_submissions_session ON submissions(session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_submissions_submitted ON submissions(submitted_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_submissions_status ON submissions(status)");

    $db->exec("CREATE TABLE IF NOT EXISTS geo_cache (
        ip TEXT PRIMARY KEY,
        country TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        calling_code TEXT NOT NULL DEFAULT '',
        cached_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['session_timeout', '30']);
    $stmt->execute(['retention_days', '90']);
    $stmt->execute(['enable_geo', '1']);
}

function vjt_db() {
    static $db = null;
    if ($db === null) {
        try {
            if (!is_dir(VJT_DB_DIR)) {
                if (!@mkdir(VJT_DB_DIR, 0755, true)) {
                    error_log('VJT: Cannot create directory: ' . VJT_DB_DIR . ' (permission denied)');
                    return null;
                }
            }
            $needsSetup = !file_exists(VJT_DB_PATH);
            $db = new PDO('sqlite:' . VJT_DB_PATH);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if ($needsSetup) {
                vjt_db_setup($db);
            }
            $db->exec('PRAGMA journal_mode=WAL');
        } catch (Exception $e) {
            error_log('VJT DB error: ' . $e->getMessage());
            $db = null;
        }
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
