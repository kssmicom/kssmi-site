<?php
/**
 * VJT Data Helper
 * JSON flat-file storage (no database required).
 */

date_default_timezone_set('Asia/Shanghai');

// Store data directly inside the API folder to avoid web-root permission issues on shared hosting
define('VJT_DATA_DIR', __DIR__ . '/vjt_data');

function vjt_data_init() {
    if (!is_dir(VJT_DATA_DIR)) {
        if (!@mkdir(VJT_DATA_DIR, 0755, true)) {
            error_log("VJT ERROR: Failed to create data directory at " . VJT_DATA_DIR);
        } else {
            // Secure the directory
            @file_put_contents(VJT_DATA_DIR . '/.htaccess', "Deny from all\n");
        }
    }
    $defaults = [
        'visitors.json'    => new stdClass(),
        'sessions.json'    => new stdClass(),
        'pageviews.json'   => [],
        'submissions.json' => [],
        'geo_cache.json'   => new stdClass(),
        'settings.json'    => ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'],
    ];
    foreach ($defaults as $file => $default) {
        $path = VJT_DATA_DIR . '/' . $file;
        if (!file_exists($path)) {
            vjt_write_json($file, $default);
        }
        // Ensure writable (deploy scripts may lock permissions to 644)
        @chmod($path, 0666);
    }
}

function vjt_read_json($filename) {
    $path = VJT_DATA_DIR . '/' . $filename;
    if (!file_exists($path)) return null;
    $fp = @fopen($path, 'r');
    if (!$fp) return null;
    if (!flock($fp, LOCK_SH)) { fclose($fp); return null; }
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($content === false || $content === '') return null;
    $data = json_decode($content, true);
    return $data;
}

function vjt_write_json($filename, $data) {
    $path = VJT_DATA_DIR . '/' . $filename;
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        error_log("VJT ERROR: Failed to open file for writing: " . $path);
        return false;
    }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// ── UUID generation ─────────────────────────────────────────────────────────

function vjt_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Visitor ─────────────────────────────────────────────────────────────────

function vjt_upsert_visitor($data) {
    $visitors = vjt_read_json('visitors.json');
    if ($visitors === null) $visitors = [];
    $vid = $data['visitor_id'];
    $now = date('Y-m-d H:i:s');
    if (isset($visitors[$vid])) {
        $visitors[$vid]['last_seen_at'] = $now;
        if (!empty($data['country'])) $visitors[$vid]['country'] = $data['country'];
        if (!empty($data['city'])) $visitors[$vid]['city'] = $data['city'];
        if (!empty($data['browser']) && $data['browser'] !== 'Unknown') $visitors[$vid]['browser'] = $data['browser'];
        if (!empty($data['device_type']) && $data['device_type'] !== 'Unknown') $visitors[$vid]['device_type'] = $data['device_type'];
        if (!empty($data['screen_resolution'])) $visitors[$vid]['screen_resolution'] = $data['screen_resolution'];
        if (!empty($data['timezone'])) $visitors[$vid]['timezone'] = $data['timezone'];
        if (!empty($data['language'])) $visitors[$vid]['language'] = $data['language'];
        if (!empty($data['user_agent'])) $visitors[$vid]['user_agent'] = $data['user_agent'];
    } else {
        $visitors[$vid] = [
            'visitor_id'        => $vid,
            'first_ip'          => $data['first_ip'] ?? '',
            'country'           => $data['country'] ?? '',
            'city'              => $data['city'] ?? '',
            'user_agent'        => $data['user_agent'] ?? '',
            'browser'           => $data['browser'] ?? 'Unknown',
            'device_type'       => $data['device_type'] ?? 'Unknown',
            'screen_resolution' => $data['screen_resolution'] ?? '',
            'timezone'          => $data['timezone'] ?? '',
            'language'          => $data['language'] ?? '',
            'first_seen_at'     => $now,
            'last_seen_at'      => $now,
        ];
    }
    vjt_write_json('visitors.json', $visitors);
}

// ── Session ─────────────────────────────────────────────────────────────────

function vjt_upsert_session($data) {
    $sessions = vjt_read_json('sessions.json');
    if ($sessions === null) $sessions = [];
    $sid = $data['session_id'];
    $now = date('Y-m-d H:i:s');
    if (isset($sessions[$sid])) {
        $sessions[$sid]['last_seen_at'] = $now;
        if (!empty($data['ip'])) $sessions[$sid]['ip'] = $data['ip'];
        if (!empty($data['country'])) $sessions[$sid]['country'] = $data['country'];
        if (!empty($data['city'])) $sessions[$sid]['city'] = $data['city'];
        if (!empty($data['region'])) $sessions[$sid]['region'] = $data['region'];
        if (!empty($data['calling_code'])) $sessions[$sid]['calling_code'] = $data['calling_code'];
    } else {
        $sessions[$sid] = [
            'session_id'    => $sid,
            'visitor_id'    => $data['visitor_id'],
            'ip'            => $data['ip'] ?? '',
            'country'       => $data['country'] ?? '',
            'city'          => $data['city'] ?? '',
            'region'        => $data['region'] ?? '',
            'calling_code'  => $data['calling_code'] ?? '',
            'referrer'      => $data['referrer'] ?? '',
            'landing_url'   => $data['landing_url'] ?? '',
            'landing_title' => $data['landing_title'] ?? '',
            'utm_source'    => $data['utm_source'] ?? '',
            'utm_medium'    => $data['utm_medium'] ?? '',
            'utm_campaign'  => $data['utm_campaign'] ?? '',
            'utm_content'   => $data['utm_content'] ?? '',
            'utm_term'      => $data['utm_term'] ?? '',
            'started_at'    => $now,
            'last_seen_at'  => $now,
        ];
    }
    vjt_write_json('sessions.json', $sessions);
}

// ── Pageview ────────────────────────────────────────────────────────────────

function vjt_add_pageview($data) {
    $pageviews = vjt_read_json('pageviews.json');
    if ($pageviews === null) $pageviews = [];
    $pageviews[] = [
        'session_id'       => $data['session_id'],
        'visitor_id'       => $data['visitor_id'],
        'url'              => $data['url'] ?? '',
        'title'            => $data['title'] ?? '',
        'visited_at'       => $data['visited_at'] ?? date('Y-m-d H:i:s'),
        'leave_at'         => $data['leave_at'] ?? null,
        'duration_seconds' => (int)($data['duration_seconds'] ?? 0),
        'scroll_depth'     => (int)($data['scroll_depth'] ?? 0),
        'step_order'       => (int)($data['step_order'] ?? 1),
    ];
    vjt_write_json('pageviews.json', $pageviews);
}

function vjt_update_pageview_leave($sessionId, $url, $leaveAt, $duration, $scrollDepth) {
    $pageviews = vjt_read_json('pageviews.json');
    if ($pageviews === null) return;
    for ($i = count($pageviews) - 1; $i >= 0; $i--) {
        if ($pageviews[$i]['session_id'] === $sessionId && $pageviews[$i]['url'] === $url && $pageviews[$i]['leave_at'] === null) {
            $pageviews[$i]['leave_at'] = $leaveAt;
            $pageviews[$i]['duration_seconds'] = max(0, (int)$duration);
            $pageviews[$i]['scroll_depth'] = max($pageviews[$i]['scroll_depth'], (int)$scrollDepth);
            vjt_write_json('pageviews.json', $pageviews);
            return;
        }
    }
}

function vjt_sync_pageview_snapshot($sessionId, $pages) {
    if (empty($pages)) return;
    $pageviews = vjt_read_json('pageviews.json');
    if ($pageviews === null) $pageviews = [];
    $existingUrls = [];
    foreach ($pageviews as $pv) {
        if ($pv['session_id'] === $sessionId) {
            $existingUrls[$pv['url']] = true;
        }
    }
    $step = count($existingUrls);
    foreach ($pages as $page) {
        $url = $page['url'] ?? '';
        if (isset($existingUrls[$url])) continue;
        $pageviews[] = [
            'session_id'       => $sessionId,
            'visitor_id'       => $page['visitor_id'] ?? '',
            'url'              => $url,
            'title'            => $page['title'] ?? '',
            'visited_at'       => $page['visited_at'] ?? date('Y-m-d H:i:s'),
            'leave_at'         => null,
            'duration_seconds' => 0,
            'scroll_depth'     => 0,
            'step_order'       => ++$step,
        ];
        $existingUrls[$url] = true;
    }
    vjt_write_json('pageviews.json', $pageviews);
}

// ── Submission ──────────────────────────────────────────────────────────────

function vjt_add_submission($data) {
    $submissions = vjt_read_json('submissions.json');
    if ($submissions === null) $submissions = [];
    // Deduplication: skip if same visitor+session+form submitted within 10 minutes
    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', strtotime('-10 minutes'));
    foreach (array_reverse($submissions) as $sub) {
        if ($sub['submitted_at'] < $cutoff) break;
        if ($sub['visitor_id'] === $data['visitor_id']
            && $sub['session_id'] === $data['session_id']
            && $sub['form_plugin'] === ($data['form_plugin'] ?? '')
            && $sub['status'] === ($data['status'] ?? 'attempt')) {
            return; // Duplicate
        }
    }
    $submissions[] = [
        'visitor_id'   => $data['visitor_id'],
        'session_id'   => $data['session_id'],
        'form_plugin'  => $data['form_plugin'] ?? '',
        'form_id'      => $data['form_id'] ?? '',
        'form_name'    => $data['form_name'] ?? '',
        'submit_page'  => $data['submit_page'] ?? '',
        'submit_title' => $data['submit_title'] ?? '',
        'submitted_at' => $now,
        'status'       => $data['status'] ?? 'attempt',
        'contact_url'  => $data['contact_url'] ?? '',
        'ip'           => $data['ip'] ?? '',
        'country'      => $data['country'] ?? '',
        'city'         => $data['city'] ?? '',
        'region'       => $data['region'] ?? '',
        'calling_code' => $data['calling_code'] ?? '',
    ];
    vjt_write_json('submissions.json', $submissions);
}

// ── Geo ─────────────────────────────────────────────────────────────────────

function vjt_resolve_geo($ip) {
    if (in_array($ip, ['127.0.0.1', '::1', '']) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    }

    $cache = vjt_read_json('geo_cache.json');
    if ($cache === null) $cache = [];
    if (isset($cache[$ip]) && isset($cache[$ip]['cached_at']) && $cache[$ip]['cached_at'] > date('Y-m-d H:i:s', strtotime('-1 day'))) {
        return [
            'country'      => $cache[$ip]['country'] ?? '',
            'city'         => $cache[$ip]['city'] ?? '',
            'region'       => $cache[$ip]['region'] ?? '',
            'calling_code' => $cache[$ip]['calling_code'] ?? '',
        ];
    }

    $result = ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode,city,regionName,callingCode", false, $ctx);
        if ($response) {
            $geo = json_decode($response, true);
            if (!empty($geo['countryCode'])) {
                $result = [
                    'country'      => $geo['countryCode'] ?? '',
                    'city'         => $geo['city'] ?? '',
                    'region'       => $geo['regionName'] ?? '',
                    'calling_code' => isset($geo['callingCode']) ? (string)$geo['callingCode'] : '',
                ];
                $cache[$ip] = $result + ['cached_at' => date('Y-m-d H:i:s')];
                vjt_write_json('geo_cache.json', $cache);
            }
        }
    } catch (Exception $e) {}

    return $result;
}

// ── Utility ─────────────────────────────────────────────────────────────────

function vjt_get_client_ip() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
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

function vjt_classify_source($session) {
    $referrer   = $session['referrer'] ?? '';
    $utm_medium = $session['utm_medium'] ?? '';

    $adsMediums = ['cpc', 'paid', 'ppc', 'ads'];
    if (in_array(strtolower($utm_medium), $adsMediums, true)) return 'ads';

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

// ── Settings ────────────────────────────────────────────────────────────────

function vjt_get_settings() {
    $settings = vjt_read_json('settings.json');
    return is_array($settings) ? $settings : ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'];
}

function vjt_save_settings($data) {
    $settings = vjt_get_settings();
    $settings['session_timeout'] = max(5, (int)($data['session_timeout'] ?? 30));
    $settings['retention_days'] = max(1, (int)($data['retention_days'] ?? 90));
    $settings['enable_geo'] = !empty($data['enable_geo']) ? '1' : '0';
    vjt_write_json('settings.json', $settings);
}

// ── Dashboard: Overview Stats ───────────────────────────────────────────────

function vjt_get_overview($since) {
    $visitors = vjt_read_json('visitors.json');
    $sessions = vjt_read_json('sessions.json');
    $pageviews = vjt_read_json('pageviews.json');
    $submissions = vjt_read_json('submissions.json');

    $visitors = $visitors ?: new stdClass();
    $sessions = $sessions ?: new stdClass();
    $pageviews = $pageviews ?: [];
    $submissions = $submissions ?: [];

    $totalVisitors = 0;
    foreach ($visitors as $v) {
        if (($v['last_seen_at'] ?? '') >= $since) $totalVisitors++;
    }

    $totalSessions = 0;
    foreach ($sessions as $s) {
        if (($s['started_at'] ?? '') >= $since) $totalSessions++;
    }

    $totalSubmissions = 0;
    $successSubmissions = 0;
    foreach ($submissions as $sub) {
        if (($sub['submitted_at'] ?? '') >= $since) {
            $totalSubmissions++;
            if (($sub['status'] ?? '') === 'success') $successSubmissions++;
        }
    }

    $totalDuration = 0;
    $durationCount = 0;
    foreach ($pageviews as $pv) {
        if (($pv['visited_at'] ?? '') >= $since && ($pv['duration_seconds'] ?? 0) > 0) {
            $totalDuration += $pv['duration_seconds'];
            $durationCount++;
        }
    }
    $avgDuration = $durationCount > 0 ? round($totalDuration / $durationCount) : 0;
    $conversionRate = $totalSessions > 0 ? round(($successSubmissions / $totalSessions) * 100, 1) : 0;

    // Submission trend (30 days)
    $trend = [];
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $trend[$day] = 0;
    }
    foreach ($submissions as $sub) {
        $day = substr($sub['submitted_at'] ?? '', 0, 10);
        if (isset($trend[$day])) $trend[$day]++;
    }

    // Top referrers
    $referrerCounts = [];
    foreach ($sessions as $s) {
        if (($s['started_at'] ?? '') >= $since && !empty($s['referrer']) && $s['referrer'] !== 'direct') {
            $r = $s['referrer'];
            $referrerCounts[$r] = ($referrerCounts[$r] ?? 0) + 1;
        }
    }
    arsort($referrerCounts);
    $topReferrers = array_slice($referrerCounts, 0, 8);

    // Device breakdown
    $deviceCounts = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'Unknown' => 0];
    foreach ($visitors as $v) {
        if (($v['last_seen_at'] ?? '') >= $since) {
            $d = $v['device_type'] ?? 'Unknown';
            $deviceCounts[$d] = ($deviceCounts[$d] ?? 0) + 1;
        }
    }

    // Source breakdown
    $sourceCounts = ['direct' => 0, 'search' => 0, 'social' => 0, 'ads' => 0, 'other' => 0];
    foreach ($sessions as $s) {
        if (($s['started_at'] ?? '') >= $since) {
            $src = vjt_classify_source($s);
            $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
        }
    }

    return [
        'totalVisitors'       => $totalVisitors,
        'totalSessions'       => $totalSessions,
        'totalSubmissions'    => $totalSubmissions,
        'successSubmissions'  => $successSubmissions,
        'avgDuration'         => $avgDuration,
        'conversionRate'      => $conversionRate,
        'trend'               => $trend,
        'topReferrers'        => $topReferrers,
        'deviceCounts'        => $deviceCounts,
        'sourceCounts'        => $sourceCounts,
    ];
}

// ── Dashboard: Submissions List ──────────────────────────────────────────────

function vjt_get_submissions_list($filters) {
    $submissions = vjt_read_json('submissions.json');
    if (!$submissions) return ['items' => [], 'total' => 0];

    $status  = $filters['status'] ?? '';
    $plugin  = $filters['plugin'] ?? '';
    $dateFrom = $filters['date_from'] ?? '';
    $dateTo   = $filters['date_to'] ?? '';

    $filtered = [];
    foreach (array_reverse($submissions) as $sub) {
        if ($status && ($sub['status'] ?? '') !== $status) continue;
        if ($plugin && ($sub['form_plugin'] ?? '') !== $plugin) continue;
        if ($dateFrom && ($sub['submitted_at'] ?? '') < $dateFrom) continue;
        if ($dateTo && ($sub['submitted_at'] ?? '') > $dateTo . ' 23:59:59') continue;
        $filtered[] = $sub;
    }

    $total = count($filtered);
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 50));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($filtered, $offset, $perPage),
        'total' => $total,
    ];
}

// ── Dashboard: Visitors List ─────────────────────────────────────────────────

function vjt_get_visitors_list($filters) {
    $visitors = vjt_read_json('visitors.json');
    $sessions = vjt_read_json('sessions.json');
    $submissions = vjt_read_json('submissions.json');

    if (!$visitors) return ['items' => [], 'total' => 0];

    $search    = trim($filters['search'] ?? '');
    $device    = $filters['device'] ?? '';
    $source    = $filters['source'] ?? '';
    $dateFrom  = $filters['date_from'] ?? '';
    $dateTo    = $filters['date_to'] ?? '';

    // Pre-compute session/submission counts and source per visitor
    $visitorSessions = [];
    $visitorSubmissions = [];
    $visitorSource = [];
    foreach ($sessions ?: [] as $s) {
        $vid = $s['visitor_id'];
        $visitorSessions[$vid] = ($visitorSessions[$vid] ?? 0) + 1;
        if (!isset($visitorSource[$vid])) {
            $visitorSource[$vid] = vjt_classify_source($s);
        }
    }
    foreach ($submissions ?: [] as $sub) {
        $vid = $sub['visitor_id'];
        $visitorSubmissions[$vid] = ($visitorSubmissions[$vid] ?? 0) + 1;
    }

    $filtered = [];
    foreach ($visitors as $v) {
        $vid = $v['visitor_id'];
        if ($search) {
            $matchIp = stripos($v['first_ip'] ?? '', $search) !== false;
            $matchCountry = stripos($v['country'] ?? '', $search) !== false;
            $matchBrowser = stripos($v['browser'] ?? '', $search) !== false;
            $matchVid = stripos($vid, $search) !== false;
            if (!$matchIp && !$matchCountry && !$matchBrowser && !$matchVid) continue;
        }
        if ($device && ($v['device_type'] ?? '') !== $device) continue;
        $vSource = $visitorSource[$vid] ?? 'direct';
        if ($source && $vSource !== $source) continue;
        if ($dateFrom && ($v['first_seen_at'] ?? '') < $dateFrom) continue;
        if ($dateTo && ($v['first_seen_at'] ?? '') > $dateTo . ' 23:59:59') continue;
        $filtered[] = [
            'visitor_id'    => $vid,
            'first_ip'      => $v['first_ip'] ?? '',
            'country'       => $v['country'] ?? '',
            'city'          => $v['city'] ?? '',
            'browser'       => $v['browser'] ?? 'Unknown',
            'device_type'   => $v['device_type'] ?? 'Unknown',
            'screen_resolution' => $v['screen_resolution'] ?? '',
            'timezone'      => $v['timezone'] ?? '',
            'language'      => $v['language'] ?? '',
            'first_seen_at' => $v['first_seen_at'] ?? '',
            'last_seen_at'  => $v['last_seen_at'] ?? '',
            'user_agent'    => $v['user_agent'] ?? '',
            'sessions'      => $visitorSessions[$vid] ?? 0,
            'submissions'   => $visitorSubmissions[$vid] ?? 0,
            'source'        => $vSource,
        ];
    }

    // Sort by last_seen_at descending
    usort($filtered, function($a, $b) {
        return strcmp($b['last_seen_at'] ?? '', $a['last_seen_at'] ?? '');
    });

    $total = count($filtered);
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 50));
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($filtered, $offset, $perPage),
        'total' => $total,
    ];
}

// ── Dashboard: Journey Detail ────────────────────────────────────────────────

function vjt_get_journey($visitorId) {
    $visitors = vjt_read_json('visitors.json');
    $sessions = vjt_read_json('sessions.json');
    $pageviews = vjt_read_json('pageviews.json');
    $submissions = vjt_read_json('submissions.json');

    $visitor = null;
    if ($visitors && isset($visitors[$visitorId])) {
        $visitor = $visitors[$visitorId];
    }
    if (!$visitor) return null;

    $visitorSessions = [];
    $visitorPageviews = [];
    $visitorSubmissions = [];

    foreach ($sessions ?: [] as $s) {
        if ($s['visitor_id'] === $visitorId) {
            $visitorSessions[] = $s;
        }
    }
    foreach ($pageviews ?: [] as $pv) {
        if ($pv['visitor_id'] === $visitorId) {
            $visitorPageviews[] = $pv;
        }
    }
    foreach ($submissions ?: [] as $sub) {
        if ($sub['visitor_id'] === $visitorId) {
            $visitorSubmissions[] = $sub;
        }
    }

    return [
        'visitor'      => $visitor,
        'sessions'     => $visitorSessions,
        'pageviews'    => $visitorPageviews,
        'submissions'  => $visitorSubmissions,
    ];
}

// ── Dashboard: Data Cleanup ──────────────────────────────────────────────────

function vjt_cleanup_old_data($days) {
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

    $visitors = vjt_read_json('visitors.json');
    if ($visitors) {
        foreach ($visitors as $vid => $v) {
            if (($v['last_seen_at'] ?? '') < $cutoff) {
                unset($visitors[$vid]);
            }
        }
        vjt_write_json('visitors.json', $visitors);
    }

    $sessions = vjt_read_json('sessions.json');
    if ($sessions) {
        foreach ($sessions as $sid => $s) {
            if (($s['last_seen_at'] ?? '') < $cutoff) {
                unset($sessions[$sid]);
            }
        }
        vjt_write_json('sessions.json', $sessions);
    }

    $pageviews = vjt_read_json('pageviews.json');
    if ($pageviews) {
        $pageviews = array_filter($pageviews, function($pv) use ($cutoff) {
            return ($pv['visited_at'] ?? '') >= $cutoff;
        });
        vjt_write_json('pageviews.json', array_values($pageviews));
    }

    $submissions = vjt_read_json('submissions.json');
    if ($submissions) {
        $submissions = array_filter($submissions, function($sub) use ($cutoff) {
            return ($sub['submitted_at'] ?? '') >= $cutoff;
        });
        vjt_write_json('submissions.json', array_values($submissions));
    }

    // Also clean geo cache older than 7 days
    $geoCuttoff = date('Y-m-d H:i:s', time() - (7 * 86400));
    $geoCache = vjt_read_json('geo_cache.json');
    if ($geoCache) {
        foreach ($geoCache as $cip => $entry) {
            if (($entry['cached_at'] ?? '') < $geoCuttoff) {
                unset($geoCache[$cip]);
            }
        }
        vjt_write_json('geo_cache.json', $geoCache);
    }
}

// ── Dashboard: CSV Export ────────────────────────────────────────────────────

function vjt_export_submissions_csv_start($filters) {
    $result = vjt_get_submissions_list($filters);
    $items = $result['items'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vjt-submissions-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'Visitor ID', 'Form', 'Form Name', 'Page', 'IP', 'Country', 'Status']);
    foreach ($items as $sub) {
        fputcsv($output, [
            $sub['submitted_at'] ?? '',
            $sub['visitor_id'] ?? '',
            $sub['form_plugin'] ?? '',
            $sub['form_name'] ?? '',
            $sub['submit_page'] ?? '',
            $sub['ip'] ?? '',
            $sub['country'] ?? '',
            $sub['status'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}
