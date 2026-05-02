<?php
/**
 * VJT Visitor Journey Tracker - Admin Dashboard
 * Password-protected (shares password with email-logs.php)
 */

session_start();

require_once __DIR__ . '/api/vjt-helpers.php';

// Password config (shared with email-logs.php)
define('PASSWORD_FILE', __DIR__ . '/.email_logs_password');
define('ADMIN_EMAIL', 'kssmi@kssmi.com');

function getPassword() {
    $default = 'kssmi2024';
    if (!file_exists(PASSWORD_FILE)) return $default;
    $content = @file_get_contents(PASSWORD_FILE);
    if ($content === false) return $default;
    $password = trim($content);
    return !empty($password) ? $password : $default;
}

$PASSWORD = getPassword();
$error = '';
$message = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $submitted = trim($_POST['password']);
    if ($submitted === $PASSWORD) {
        $_SESSION['email_logs_auth'] = true;
        session_regenerate_id(true);
    } else {
        $error = 'Invalid password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: visitor-journey.php');
    exit;
}

$isAuthenticated = isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true;

// Determine active tab
$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'submissions', 'visitors', 'journey', 'settings'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

// ── Data helpers ────────────────────────────────────────────────────────────

function getDB() {
    return vjt_db();
}

function getSettings() {
    $db = getDB();
    if (!$db) return ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'];
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

// ── Handle settings save ─────────────────────────────────────────────────────

if ($isAuthenticated && isset($_POST['save_settings'])) {
    $db = getDB();
    if ($db) {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['session_timeout', max(5, (int)($_POST['session_timeout'] ?? 30))]);
        $stmt->execute(['retention_days', max(1, (int)($_POST['retention_days'] ?? 90))]);
        $stmt->execute(['enable_geo', isset($_POST['enable_geo']) ? '1' : '0']);
        $message = 'Settings saved.';
    }
}

// Handle data cleanup
if ($isAuthenticated && isset($_POST['cleanup_data'])) {
    $db = getDB();
    if ($db) {
        $settings = getSettings();
        $days = max(1, (int)($settings['retention_days'] ?? 90));
        $threshold = date('Y-m-d H:i:s', time() - ($days * 86400));
        $db->exec("DELETE FROM pageviews WHERE visited_at < '{$threshold}'");
        $db->exec("DELETE FROM submissions WHERE submitted_at < '{$threshold}'");
        $db->exec("DELETE FROM sessions WHERE last_seen_at < '{$threshold}'");
        $db->exec("DELETE FROM visitors WHERE last_seen_at < '{$threshold}'");
        $message = 'Old data cleaned up (retention: ' . $days . ' days).';
    }
}

// Handle CSV export
if ($isAuthenticated && isset($_GET['export_csv'])) {
    $db = getDB();
    if ($db) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vjt-submissions-' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Time', 'Visitor ID', 'Form', 'Page', 'Status', 'IP', 'Country', 'City']);
        $stmt = $db->query("SELECT * FROM submissions ORDER BY submitted_at DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'], $row['submitted_at'], $row['visitor_id'],
                $row['form_plugin'] . ': ' . $row['form_name'], $row['submit_page'],
                $row['status'], $row['ip'], $row['country'], $row['city']
            ]);
        }
        fclose($output);
        exit;
    }
}

// ── Fetch data for tabs ──────────────────────────────────────────────────────

$db = getDB();
$overview = null;
$submissions = [];
$visitors = [];
$journeyData = null;
$settings = $db ? getSettings() : ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'];

if ($db && $tab === 'overview') {
    $since = date('Y-m-d H:i:s', time() - (30 * 86400));

    $totalVisitors = (int)$db->query("SELECT COUNT(*) FROM visitors WHERE last_seen_at >= '{$since}'")->fetchColumn();
    $totalSessions = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE started_at >= '{$since}'")->fetchColumn();
    $totalSubmissions = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE submitted_at >= '{$since}'")->fetchColumn();
    $successSubmissions = (int)$db->query("SELECT COUNT(*) FROM submissions WHERE submitted_at >= '{$since}' AND status = 'success'")->fetchColumn();
    $avgDuration = (float)$db->query("SELECT AVG(duration_seconds) FROM pageviews WHERE visited_at >= '{$since}' AND duration_seconds > 0")->fetchColumn();

    // Submission trend (14 days)
    $trend = $db->query("
        SELECT DATE(submitted_at) AS day, COUNT(*) AS cnt
        FROM submissions WHERE submitted_at >= datetime('now', '-14 days')
        GROUP BY DATE(submitted_at) ORDER BY day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Top referrers
    $referrers = $db->query("
        SELECT referrer, COUNT(*) AS cnt FROM sessions
        WHERE started_at >= '{$since}' AND referrer != '' AND referrer != 'direct'
        GROUP BY referrer ORDER BY cnt DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Device breakdown
    $devices = $db->query("
        SELECT device_type, COUNT(*) AS cnt FROM visitors
        WHERE last_seen_at >= '{$since}' GROUP BY device_type ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Source breakdown
    $dbSessions = $db->query("SELECT referrer, utm_medium, utm_source FROM sessions WHERE started_at >= '{$since}'")->fetchAll(PDO::FETCH_ASSOC);
    $sourceCounts = ['search' => 0, 'social' => 0, 'direct' => 0, 'ads' => 0, 'other' => 0];
    foreach ($dbSessions as $s) {
        $sourceCounts[vjt_classify_source($s)]++;
    }

    $overview = compact('totalVisitors', 'totalSessions', 'totalSubmissions', 'successSubmissions', 'avgDuration', 'trend', 'referrers', 'devices', 'sourceCounts');
}

// ── Submissions list ─────────────────────────────────────────────────────────

$subPage     = max(1, (int)($_GET['sp'] ?? 1));
$subPerPage  = 50;
$subStatus   = $_GET['status'] ?? '';
$subPlugin   = $_GET['plugin'] ?? '';
$subDateFrom = $_GET['date_from'] ?? '';
$subDateTo   = $_GET['date_to'] ?? '';
$subTotal    = 0;

if ($db && $tab === 'submissions') {
    $where = [];
    $params = [];
    if ($subStatus) { $where[] = "status = ?"; $params[] = $subStatus; }
    if ($subPlugin) { $where[] = "form_plugin = ?"; $params[] = $subPlugin; }
    if ($subDateFrom) { $where[] = "submitted_at >= ?"; $params[] = $subDateFrom . ' 00:00:00'; }
    if ($subDateTo) { $where[] = "submitted_at <= ?"; $params[] = $subDateTo . ' 23:59:59'; }
    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) FROM submissions {$whereClause}");
    $stmt->execute($params);
    $subTotal = (int)$stmt->fetchColumn();

    $offset = ($subPage - 1) * $subPerPage;
    $stmt = $db->prepare("SELECT * FROM submissions {$whereClause} ORDER BY submitted_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$subPerPage, $offset]));
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark "attempt" submissions that are older than 30 min as "abandoned" for display
    foreach ($submissions as &$sub) {
        if ($sub['status'] === 'attempt' && strtotime($sub['submitted_at']) < time() - 1800) {
            $sub['display_status'] = 'abandoned';
        } else {
            $sub['display_status'] = $sub['status'];
        }
    }
    unset($sub);
}

// ── Visitors list ────────────────────────────────────────────────────────────

$visPage    = max(1, (int)($_GET['vp'] ?? 1));
$visPerPage = 50;
$visSearch  = $_GET['search'] ?? '';
$visDevice  = $_GET['device'] ?? '';
$visSource  = $_GET['source'] ?? '';
$visTotal   = 0;

if ($db && $tab === 'visitors') {
    $where = [];
    $params = [];
    $joins = '';

    if ($visSearch) {
        $where[] = "(v.visitor_id LIKE ? OR v.first_ip LIKE ? OR v.country LIKE ? OR v.browser LIKE ?)";
        $s = '%' . $visSearch . '%';
        $params = array_merge($params, [$s, $s, $s, $s]);
    }
    if ($visDevice) { $where[] = "v.device_type = ?"; $params[] = $visDevice; }

    // Source filter needs session join
    if ($visSource) {
        $adsMediums = "('cpc','paid','ppc','ads')";
        $searchEngines = ['google.', 'bing.', 'yahoo.', 'baidu.', 'duckduckgo.', 'yandex.', 'ask.', 'aol.', 'chatgpt.com', 'perplexity.ai', 'claude.ai'];
        $socialPlatforms = ['facebook.', 'instagram.', 'twitter.', 'x.com', 'linkedin.', 'youtube.', 'tiktok.', 'pinterest.', 'reddit.', 'weibo.', 't.co', 'fb.me', 'fb.com'];

        $joins = "LEFT JOIN (SELECT s1.visitor_id, s1.referrer, s1.utm_medium, s1.utm_source, MAX(s1.started_at) as max_started FROM sessions s1 GROUP BY s1.visitor_id) latest ON v.visitor_id = latest.visitor_id";

        switch ($visSource) {
            case 'ads':
                $where[] = "latest.utm_medium IN " . $adsMediums;
                break;
            case 'search':
                $searchLike = array_map(function($se) { return "latest.referrer LIKE '%{$se}%'"; }, $searchEngines);
                $where[] = "(" . implode(' OR ', $searchLike) . ") AND (latest.utm_medium NOT IN " . $adsMediums . " OR latest.utm_medium IS NULL)";
                break;
            case 'social':
                $socialLike = array_map(function($sp) { return "latest.referrer LIKE '%{$sp}%'"; }, $socialPlatforms);
                $where[] = "(" . implode(' OR ', $socialLike) . ") AND (latest.utm_medium NOT IN " . $adsMediums . " OR latest.utm_medium IS NULL)";
                break;
            case 'direct':
                $where[] = "(latest.referrer IS NULL OR latest.referrer = '' OR latest.referrer = 'direct') AND (latest.utm_medium NOT IN " . $adsMediums . " OR latest.utm_medium IS NULL)";
                break;
        }
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM visitors v {$joins} {$whereClause}";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $visTotal = (int)$stmt->fetchColumn();

    $offset = ($visPage - 1) * $visPerPage;
    $sql = "SELECT v.*,
        (SELECT COUNT(*) FROM sessions s WHERE s.visitor_id = v.visitor_id) AS session_count,
        (SELECT COUNT(*) FROM submissions su WHERE su.visitor_id = v.visitor_id) AS submission_count
        FROM visitors v {$joins} {$whereClause}
        ORDER BY v.last_seen_at DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$visPerPage, $offset]));
    $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich with latest session source
    foreach ($visitors as &$v) {
        $stmt = $db->prepare("SELECT referrer, utm_medium, utm_source, utm_campaign FROM sessions WHERE visitor_id = ? ORDER BY started_at DESC LIMIT 1");
        $stmt->execute([$v['visitor_id']]);
        $lastSession = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($lastSession) {
            $v['source'] = vjt_classify_source($lastSession);
            $v['last_referrer'] = $lastSession['referrer'];
            $v['utm_campaign'] = $lastSession['utm_campaign'];
        } else {
            $v['source'] = 'direct';
            $v['last_referrer'] = '';
            $v['utm_campaign'] = '';
        }
    }
    unset($v);

    // Visitor ID display: strip vjtv_ prefix
    foreach ($visitors as &$v) {
        $v['short_id'] = str_replace('vjtv_', '', $v['visitor_id']);
    }
    unset($v);
}

// ── Journey detail ───────────────────────────────────────────────────────────

if ($db && $tab === 'journey' && !empty($_GET['visitor_id'])) {
    $journeyVisitorId = $_GET['visitor_id'];

    // Get visitor info
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$journeyVisitorId]);
    $journeyVisitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($journeyVisitor) {
        // Get all sessions
        $stmt = $db->prepare("SELECT * FROM sessions WHERE visitor_id = ? ORDER BY started_at DESC");
        $stmt->execute([$journeyVisitorId]);
        $journeySessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all pageviews across all sessions
        $stmt = $db->prepare("SELECT * FROM pageviews WHERE visitor_id = ? ORDER BY visited_at ASC");
        $stmt->execute([$journeyVisitorId]);
        $journeyPageviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get all submissions
        $stmt = $db->prepare("SELECT * FROM submissions WHERE visitor_id = ? ORDER BY submitted_at DESC");
        $stmt->execute([$journeyVisitorId]);
        $journeySubmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $journeyData = [
            'visitor' => $journeyVisitor,
            'sessions' => $journeySessions,
            'pageviews' => $journeyPageviews,
            'submissions' => $journeySubmissions
        ];
    }
}

// ── Country helpers ──────────────────────────────────────────────────────────

$COUNTRY_NAMES = [
    'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AR' => 'Argentina', 'AU' => 'Australia',
    'AT' => 'Austria', 'BD' => 'Bangladesh', 'BE' => 'Belgium', 'BR' => 'Brazil', 'CA' => 'Canada',
    'CN' => 'China', 'CO' => 'Colombia', 'DE' => 'Germany', 'EG' => 'Egypt', 'ES' => 'Spain',
    'FR' => 'France', 'GB' => 'United Kingdom', 'IN' => 'India', 'IT' => 'Italy', 'JP' => 'Japan',
    'KR' => 'South Korea', 'MX' => 'Mexico', 'NL' => 'Netherlands', 'NG' => 'Nigeria', 'PH' => 'Philippines',
    'PK' => 'Pakistan', 'PL' => 'Poland', 'PT' => 'Portugal', 'RU' => 'Russia', 'SA' => 'Saudi Arabia',
    'SE' => 'Sweden', 'TH' => 'Thailand', 'TR' => 'Turkey', 'UA' => 'Ukraine', 'US' => 'United States',
    'VN' => 'Vietnam', 'ZA' => 'South Africa', 'LOCAL' => 'Local/Testing', 'UNKNOWN' => 'Unknown',
];

function getCountryName($code) {
    global $COUNTRY_NAMES;
    $code = strtoupper($code);
    return isset($COUNTRY_NAMES[$code]) ? $COUNTRY_NAMES[$code] : $code;
}

// Format duration
function fmtDuration($secs) {
    if ($secs < 60) return $secs . 's';
    if ($secs < 3600) return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
}

// Source badge color
function sourceBadge($source) {
    $map = [
        'ads' => ['#e74c3c', '#fdeaea', 'Ads'],
        'search' => ['#27ae60', '#d4edda', 'Search'],
        'social' => ['#3498db', '#d6eaf8', 'Social'],
        'direct' => ['#95a5a6', '#eaeded', 'Direct'],
        'other' => ['#f39c12', '#fef9e7', 'Other'],
    ];
    $info = $map[$source] ?? ['#95a5a6', '#eaeded', ucfirst($source)];
    return "<span style='background:{$info[1]};color:{$info[0]};padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;'>{$info[2]}</span>";
}

$subTotalPages = ceil($subTotal / $subPerPage);
$visTotalPages = ceil($visTotal / $visPerPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Journey Tracker - KSSMI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; color: #333; }
        .container { max-width: 1500px; margin: 0 auto; }
        h1 { color: #5D4E37; }
        .subtitle { color: #666; margin-bottom: 20px; }

        /* Login */
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:hover { background: #5D4E37; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .header-left h1 { margin-bottom: 4px; }
        .header-right { display: flex; gap: 8px; align-items: center; }

        /* Tabs */
        .tabs { display: flex; gap: 0; margin-bottom: 20px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .tab { padding: 12px 24px; text-decoration: none; color: #666; font-weight: 500; font-size: 14px; transition: all 0.15s; border-bottom: 3px solid transparent; }
        .tab:hover { color: #5D4E37; background: #faf9f7; }
        .tab.active { color: #5D4E37; border-bottom-color: #8B7355; background: #faf9f7; }

        /* Buttons */
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 13px; display: inline-block; }
        .btn-primary { background: #8B7355; color: white; }
        .btn-primary:hover { background: #5D4E37; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }
        .btn-secondary { background: #666; color: white; }
        .btn-secondary:hover { background: #444; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #219a52; }
        .btn-small { padding: 4px 10px; font-size: 11px; }
        .btn:hover { opacity: 0.9; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 11px; text-transform: uppercase; color: #888; margin-bottom: 6px; letter-spacing: 0.5px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #5D4E37; }
        .stat-card .sub { font-size: 12px; color: #888; margin-top: 4px; }

        /* Panels */
        .panel { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .panel-header { padding: 15px 20px; border-bottom: 1px solid #eee; font-weight: 600; color: #5D4E37; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
        .panel-body { padding: 20px; }

        /* Tables */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 11px; text-transform: uppercase; white-space: nowrap; }
        tr:hover { background: #fafafa; }
        .mono { font-family: monospace; font-size: 11px; }

        /* Status badges */
        .status { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-attempt { background: #fff3cd; color: #856404; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-abandoned { background: #eaeded; color: #7f8c8d; }

        /* Filters */
        .filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 15px; }
        .filters select, .filters input { padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        .filters input[type="date"] { width: 140px; }

        /* Pagination */
        .pagination { display: flex; gap: 4px; justify-content: center; margin-top: 15px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #666; font-size: 13px; }
        .pagination a:hover { background: #8B7355; color: white; border-color: #8B7355; }
        .pagination .current { background: #8B7355; color: white; border-color: #8B7355; }

        /* Bar chart */
        .bar-chart { display: flex; align-items: flex-end; gap: 3px; height: 120px; padding: 0 10px; }
        .bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0; }
        .bar { background: #8B7355; width: 100%; max-width: 40px; border-radius: 3px 3px 0 0; min-height: 2px; transition: height 0.3s; }
        .bar-label { font-size: 9px; color: #888; margin-top: 4px; transform: rotate(-45deg); transform-origin: top left; white-space: nowrap; }
        .bar-value { font-size: 10px; color: #5D4E37; font-weight: 600; margin-bottom: 2px; }

        /* Journey detail */
        .journey-section { margin-bottom: 25px; }
        .journey-section h3 { color: #5D4E37; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #8B7355; font-size: 14px; }
        .journey-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .journey-item { background: #f8f7f5; padding: 10px 14px; border-radius: 6px; }
        .journey-item label { font-size: 10px; color: #888; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
        .journey-item .val { font-size: 13px; color: #333; word-break: break-word; }
        .timeline { position: relative; padding-left: 20px; }
        .timeline::before { content: ''; position: absolute; left: 7px; top: 0; bottom: 0; width: 2px; background: #e0d8cc; }
        .timeline-item { position: relative; padding: 8px 0 8px 16px; margin-bottom: 4px; }
        .timeline-item::before { content: ''; position: absolute; left: -17px; top: 14px; width: 10px; height: 10px; border-radius: 50%; background: #8B7355; }
        .timeline-item .pv-url { font-size: 13px; color: #5D4E37; font-weight: 500; }
        .timeline-item .pv-meta { font-size: 11px; color: #888; margin-top: 2px; }

        /* Messages */
        .error { color: #e74c3c; margin-bottom: 15px; padding: 10px; background: #fdeaea; border-radius: 4px; }
        .success { color: #27ae60; padding: 10px; background: #d4edda; border-radius: 4px; margin-bottom: 15px; }

        /* Settings */
        .setting-row { display: flex; align-items: center; gap: 15px; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
        .setting-row label { font-weight: 500; min-width: 200px; }
        .setting-row input[type="number"] { width: 80px; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; }
        .setting-row input[type="checkbox"] { width: 20px; height: 20px; }

        /* Empty state */
        .empty { text-align: center; padding: 60px; }
        .empty-icon { font-size: 48px; margin-bottom: 15px; }
        .empty p { color: #999; }

        .link { color: #8B7355; text-decoration: none; }
        .link:hover { text-decoration: underline; }

        .country-badge { background: #e8e4df; padding: 2px 8px; border-radius: 3px; font-size: 11px; }
        .url-cell { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .url-cell a { color: #8B7355; text-decoration: none; font-size: 12px; }

        @media (max-width: 768px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .tabs { flex-wrap: wrap; }
            .filters { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$isAuthenticated): ?>
            <div class="login-box">
                <h2>Visitor Journey Tracker</h2>
                <?php if ($error): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter password" required autofocus>
                    <button type="submit">Login</button>
                </form>
                <p style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
                    Shares password with <a href="/email-logs.php" class="link">Email Logs</a>
                </p>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1>Visitor Journey Tracker</h1>
                    <p class="subtitle"><?php echo htmlspecialchars($subtitle ?? 'Track visitor behavior, traffic sources, and conversions'); ?></p>
                </div>
                <div class="header-right">
                    <a href="/email-logs.php" class="btn btn-secondary">Email Logs</a>
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <?php if ($message): ?>
                <p class="success"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (!$db): ?>
                <div class="panel">
                    <div class="panel-body">
                        <div class="empty">
                            <div class="empty-icon">🔧</div>
                            <p style="font-size:16px;color:#5D4E37;">Database not initialized</p>
                            <p style="margin-top:8px;">Run the setup script on the server:</p>
                            <code style="background:#f5f5f5;padding:4px 8px;border-radius:4px;font-size:14px;">php public/vjt-db-setup.php</code>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Tabs -->
                <div class="tabs">
                    <a href="?tab=overview" class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">Overview</a>
                    <a href="?tab=submissions" class="tab <?php echo $tab === 'submissions' ? 'active' : ''; ?>">Submissions</a>
                    <a href="?tab=visitors" class="tab <?php echo $tab === 'visitors' ? 'active' : ''; ?>">Visitors</a>
                    <?php if ($tab === 'journey'): ?>
                        <a href="?tab=journey&visitor_id=<?php echo urlencode($_GET['visitor_id'] ?? ''); ?>" class="tab active">Journey Detail</a>
                    <?php endif; ?>
                    <a href="?tab=settings" class="tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Settings</a>
                </div>

                <?php if ($tab === 'overview' && $overview): ?>
                    <!-- Overview -->
                    <div class="stats">
                        <div class="stat-card">
                            <h3>Visitors (30d)</h3>
                            <div class="value"><?php echo number_format($overview['totalVisitors']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Sessions (30d)</h3>
                            <div class="value"><?php echo number_format($overview['totalSessions']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Submissions (30d)</h3>
                            <div class="value"><?php echo number_format($overview['totalSubmissions']); ?></div>
                            <div class="sub"><?php echo number_format($overview['successSubmissions']); ?> successful</div>
                        </div>
                        <div class="stat-card">
                            <h3>Conversion Rate</h3>
                            <div class="value"><?php echo $overview['totalSessions'] > 0 ? round(($overview['totalSubmissions'] / $overview['totalSessions']) * 100, 1) . '%' : '0%'; ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Avg Dwell Time</h3>
                            <div class="value"><?php echo fmtDuration((int)$overview['avgDuration']); ?></div>
                        </div>
                    </div>

                    <!-- Submission Trend -->
                    <?php if (!empty($overview['trend'])): ?>
                    <div class="panel">
                        <div class="panel-header">Submission Trend (14 Days)</div>
                        <div class="panel-body">
                            <div class="bar-chart">
                                <?php
                                $maxCnt = max(array_column($overview['trend'], 'cnt')) ?: 1;
                                foreach ($overview['trend'] as $day):
                                    $h = $maxCnt > 0 ? round(($day['cnt'] / $maxCnt) * 100) : 0;
                                    $label = substr($day['day'] ?? '', 5); // MM-DD
                                ?>
                                    <div class="bar-col">
                                        <div class="bar-value"><?php echo $day['cnt']; ?></div>
                                        <div class="bar" style="height:<?php echo max(2, $h); ?>px;" title="<?php echo $day['day']; ?>: <?php echo $day['cnt']; ?>"></div>
                                        <div class="bar-label"><?php echo htmlspecialchars($label); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <!-- Top Referrers -->
                        <div class="panel">
                            <div class="panel-header">Top Referrers (30d)</div>
                            <div class="panel-body" style="padding:0;">
                                <?php if (empty($overview['referrers'])): ?>
                                    <div class="empty" style="padding:30px;"><p>No referrer data yet</p></div>
                                <?php else: ?>
                                    <table>
                                        <thead><tr><th>Source</th><th style="text-align:right;">Sessions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($overview['referrers'] as $ref):
                                                $label = $ref['referrer'];
                                                $host = parse_url($label, PHP_URL_HOST);
                                                $display = $host ?: ($label ?: 'Direct');
                                                $display = preg_replace('/^www\./', '', $display);
                                            ?>
                                                <tr>
                                                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($display); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($ref['cnt']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Source & Device -->
                        <div style="display:flex;flex-direction:column;gap:20px;">
                            <div class="panel">
                                <div class="panel-header">Traffic Sources (30d)</div>
                                <div class="panel-body" style="padding:0;">
                                    <table>
                                        <thead><tr><th>Source</th><th style="text-align:right;">Sessions</th></tr></thead>
                                        <tbody>
                                            <?php foreach (['search', 'social', 'direct', 'ads', 'other'] as $src):
                                                $cnt = $overview['sourceCounts'][$src] ?? 0;
                                            ?>
                                                <tr>
                                                    <td><?php echo sourceBadge($src); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($cnt); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="panel-header">Device Breakdown (30d)</div>
                                <div class="panel-body" style="padding:0;">
                                    <?php if (empty($overview['devices'])): ?>
                                        <div class="empty" style="padding:30px;"><p>No device data yet</p></div>
                                    <?php else: ?>
                                        <table>
                                            <thead><tr><th>Device</th><th style="text-align:right;">Visitors</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($overview['devices'] as $dev): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(ucfirst($dev['device_type'])); ?></td>
                                                        <td style="text-align:right;"><?php echo number_format($dev['cnt']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab === 'submissions'): ?>
                    <!-- Submissions List -->
                    <div class="panel">
                        <div class="panel-header">
                            Submissions (<?php echo number_format($subTotal); ?>)
                            <div>
                                <a href="?tab=submissions&export_csv=1<?php echo $subStatus ? '&status=' . urlencode($subStatus) : ''; ?><?php echo $subPlugin ? '&plugin=' . urlencode($subPlugin) : ''; ?>" class="btn btn-success btn-small">Export CSV</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="submissions">
                                <select name="status">
                                    <option value="">All Statuses</option>
                                    <option value="success" <?php echo $subStatus === 'success' ? 'selected' : ''; ?>>Success</option>
                                    <option value="attempt" <?php echo $subStatus === 'attempt' ? 'selected' : ''; ?>>Attempt</option>
                                    <option value="error" <?php echo $subStatus === 'error' ? 'selected' : ''; ?>>Error</option>
                                </select>
                                <select name="plugin">
                                    <option value="">All Forms</option>
                                    <option value="kssmi-inquiry" <?php echo $subPlugin === 'kssmi-inquiry' ? 'selected' : ''; ?>>Inquiry Form</option>
                                    <option value="whatsapp" <?php echo $subPlugin === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp Click</option>
                                    <option value="mailto" <?php echo $subPlugin === 'mailto' ? 'selected' : ''; ?>>Mailto Click</option>
                                    <option value="generic" <?php echo $subPlugin === 'generic' ? 'selected' : ''; ?>>Generic</option>
                                </select>
                                <input type="date" name="date_from" value="<?php echo htmlspecialchars($subDateFrom); ?>" placeholder="From">
                                <input type="date" name="date_to" value="<?php echo htmlspecialchars($subDateTo); ?>" placeholder="To">
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($subStatus || $subPlugin || $subDateFrom || $subDateTo): ?>
                                    <a href="?tab=submissions" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                            </form>

                            <?php if (empty($submissions)): ?>
                                <div class="empty"><div class="empty-icon">📋</div><p>No submissions found</p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Visitor ID</th>
                                                <th>Form</th>
                                                <th>Page</th>
                                                <th>IP / Country</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($submissions as $sub): ?>
                                                <tr>
                                                    <td style="white-space:nowrap;font-size:12px;"><?php echo htmlspecialchars($sub['submitted_at']); ?></td>
                                                    <td class="mono"><a href="?tab=journey&visitor_id=<?php echo urlencode($sub['visitor_id']); ?>" class="link"><?php echo htmlspecialchars(str_replace('vjtv_', '', $sub['visitor_id'])); ?></a></td>
                                                    <td><?php echo htmlspecialchars($sub['form_plugin'] . ': ' . ($sub['form_name'] ?: $sub['form_id'])); ?></td>
                                                    <td class="url-cell"><a href="<?php echo htmlspecialchars($sub['submit_page'] ?? '#'); ?>" target="_blank"><?php echo htmlspecialchars($sub['submit_page'] ?? '-'); ?></a></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($sub['ip']); ?>
                                                        <?php if ($sub['country']): ?>
                                                            <br><span class="country-badge"><?php echo htmlspecialchars(getCountryName($sub['country'])); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><span class="status status-<?php echo $sub['display_status']; ?>"><?php echo ucfirst($sub['display_status']); ?></span></td>
                                                    <td><a href="?tab=journey&visitor_id=<?php echo urlencode($sub['visitor_id']); ?>" class="btn btn-primary btn-small">Journey</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($subTotalPages > 1): ?>
                                    <div class="pagination">
                                        <?php for ($i = 1; $i <= min($subTotalPages, 20); $i++):
                                            $pageUrl = "?tab=submissions&sp={$i}";
                                            if ($subStatus) $pageUrl .= '&status=' . urlencode($subStatus);
                                            if ($subPlugin) $pageUrl .= '&plugin=' . urlencode($subPlugin);
                                            if ($subDateFrom) $pageUrl .= '&date_from=' . urlencode($subDateFrom);
                                            if ($subDateTo) $pageUrl .= '&date_to=' . urlencode($subDateTo);
                                        ?>
                                            <?php if ($i === $subPage): ?>
                                                <span class="current"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="<?php echo $pageUrl; ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'visitors'): ?>
                    <!-- Visitors List -->
                    <div class="panel">
                        <div class="panel-header">Visitors (<?php echo number_format($visTotal); ?>)</div>
                        <div class="panel-body">
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="visitors">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($visSearch); ?>" placeholder="Search ID, IP, country, browser..." style="width:250px;">
                                <select name="device">
                                    <option value="">All Devices</option>
                                    <option value="desktop" <?php echo $visDevice === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                    <option value="mobile" <?php echo $visDevice === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                                    <option value="tablet" <?php echo $visDevice === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                </select>
                                <select name="source">
                                    <option value="">All Sources</option>
                                    <option value="ads" <?php echo $visSource === 'ads' ? 'selected' : ''; ?>>Ads</option>
                                    <option value="search" <?php echo $visSource === 'search' ? 'selected' : ''; ?>>Search</option>
                                    <option value="social" <?php echo $visSource === 'social' ? 'selected' : ''; ?>>Social</option>
                                    <option value="direct" <?php echo $visSource === 'direct' ? 'selected' : ''; ?>>Direct</option>
                                    <option value="other" <?php echo $visSource === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($visSearch || $visDevice || $visSource): ?>
                                    <a href="?tab=visitors" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                            </form>

                            <?php if (empty($visitors)): ?>
                                <div class="empty"><div class="empty-icon">👥</div><p>No visitors found</p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Visitor ID</th>
                                                <th>First Seen</th>
                                                <th>Last Seen</th>
                                                <th>Country</th>
                                                <th>Device</th>
                                                <th>Browser</th>
                                                <th style="text-align:center;">Sessions</th>
                                                <th style="text-align:center;">Submissions</th>
                                                <th>Source</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($visitors as $v): ?>
                                                <tr>
                                                    <td class="mono"><a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>" class="link"><?php echo htmlspecialchars($v['short_id']); ?></a></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars($v['first_seen_at']); ?></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars($v['last_seen_at']); ?></td>
                                                    <td><?php echo $v['country'] ? htmlspecialchars(getCountryName($v['country'])) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars(ucfirst($v['device_type'] ?: '-')); ?></td>
                                                    <td><?php echo htmlspecialchars($v['browser'] ?: '-'); ?></td>
                                                    <td style="text-align:center;"><?php echo $v['session_count']; ?></td>
                                                    <td style="text-align:center;"><?php echo $v['submission_count']; ?></td>
                                                    <td><?php echo sourceBadge($v['source']); ?></td>
                                                    <td><a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>" class="btn btn-primary btn-small">Journey</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php if ($visTotalPages > 1): ?>
                                    <div class="pagination">
                                        <?php for ($i = 1; $i <= min($visTotalPages, 20); $i++):
                                            $pageUrl = "?tab=visitors&vp={$i}";
                                            if ($visSearch) $pageUrl .= '&search=' . urlencode($visSearch);
                                            if ($visDevice) $pageUrl .= '&device=' . urlencode($visDevice);
                                            if ($visSource) $pageUrl .= '&source=' . urlencode($visSource);
                                        ?>
                                            <?php if ($i === $visPage): ?>
                                                <span class="current"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="<?php echo $pageUrl; ?>"><?php echo $i; ?></a>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'journey' && $journeyData): ?>
                    <!-- Journey Detail -->
                    <?php $v = $journeyData['visitor']; ?>
                    <div class="panel">
                        <div class="panel-header">
                            Journey: <?php echo htmlspecialchars(str_replace('vjtv_', '', $v['visitor_id'])); ?>
                            <a href="?tab=visitors" class="btn btn-secondary btn-small">Back to Visitors</a>
                        </div>
                        <div class="panel-body">
                            <div class="journey-section">
                                <h3>Visitor Info</h3>
                                <div class="journey-grid">
                                    <div class="journey-item"><label>Visitor ID</label><div class="val mono"><?php echo htmlspecialchars($v['visitor_id']); ?></div></div>
                                    <div class="journey-item"><label>First IP</label><div class="val"><?php echo htmlspecialchars($v['first_ip']); ?></div></div>
                                    <div class="journey-item"><label>Country</label><div class="val"><?php echo htmlspecialchars(getCountryName($v['country'])); ?></div></div>
                                    <div class="journey-item"><label>City</label><div class="val"><?php echo htmlspecialchars($v['city'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Browser</label><div class="val"><?php echo htmlspecialchars($v['browser'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Device</label><div class="val"><?php echo htmlspecialchars(ucfirst($v['device_type'] ?: '-')); ?></div></div>
                                    <div class="journey-item"><label>Screen</label><div class="val"><?php echo htmlspecialchars($v['screen_resolution'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Timezone</label><div class="val"><?php echo htmlspecialchars($v['timezone'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Language</label><div class="val"><?php echo htmlspecialchars($v['language'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>First Seen</label><div class="val"><?php echo htmlspecialchars($v['first_seen_at']); ?></div></div>
                                    <div class="journey-item"><label>Last Seen</label><div class="val"><?php echo htmlspecialchars($v['last_seen_at']); ?></div></div>
                                    <div class="journey-item" style="grid-column: 1 / -1;"><label>User Agent</label><div class="val" style="font-size:11px;word-break:break-all;"><?php echo htmlspecialchars($v['user_agent'] ?? 'N/A'); ?></div></div>
                                </div>
                            </div>

                            <?php if (!empty($journeyData['submissions'])): ?>
                            <div class="journey-section">
                                <h3>Submissions (<?php echo count($journeyData['submissions']); ?>)</h3>
                                <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Time</th><th>Form</th><th>Page</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($journeyData['submissions'] as $sub): ?>
                                                <tr>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars($sub['submitted_at']); ?></td>
                                                    <td><?php echo htmlspecialchars($sub['form_plugin'] . ': ' . $sub['form_name']); ?></td>
                                                    <td class="url-cell"><a href="<?php echo htmlspecialchars($sub['submit_page'] ?? '#'); ?>" target="_blank"><?php echo htmlspecialchars($sub['submit_page'] ?? '-'); ?></a></td>
                                                    <td><span class="status status-<?php echo $sub['status']; ?>"><?php echo ucfirst($sub['status']); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($journeyData['sessions'] as $sess):
                                $sessPageviews = array_filter($journeyData['pageviews'], function($pv) use ($sess) {
                                    return $pv['session_id'] === $sess['session_id'];
                                });
                                usort($sessPageviews, function($a, $b) { return $a['step_order'] - $b['step_order']; });
                            ?>
                            <div class="journey-section">
                                <h3>
                                    Session: <?php echo htmlspecialchars(substr($sess['session_id'], 0, 20)); ?>...
                                    <?php echo sourceBadge(vjt_classify_source($sess)); ?>
                                    <span style="font-weight:normal;font-size:12px;color:#888;">
                                        | <?php echo htmlspecialchars($sess['started_at']); ?> - <?php echo htmlspecialchars($sess['last_seen_at']); ?>
                                        <?php if ($sess['utm_campaign']): ?>
                                            | Campaign: <?php echo htmlspecialchars($sess['utm_campaign']); ?>
                                        <?php endif; ?>
                                    </span>
                                </h3>
                                <div class="journey-grid">
                                    <div class="journey-item"><label>Landing Page</label><div class="val" style="font-size:12px;"><?php echo htmlspecialchars($sess['landing_url'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Referrer</label><div class="val" style="font-size:12px;"><?php echo htmlspecialchars($sess['referrer'] ?: 'Direct'); ?></div></div>
                                    <?php if ($sess['utm_source']): ?>
                                        <div class="journey-item"><label>UTM Source</label><div class="val"><?php echo htmlspecialchars($sess['utm_source']); ?></div></div>
                                    <?php endif; ?>
                                    <?php if ($sess['utm_medium']): ?>
                                        <div class="journey-item"><label>UTM Medium</label><div class="val"><?php echo htmlspecialchars($sess['utm_medium']); ?></div></div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($sessPageviews)): ?>
                                <div class="timeline">
                                    <?php foreach ($sessPageviews as $pv): ?>
                                        <div class="timeline-item">
                                            <div class="pv-url"><?php echo htmlspecialchars($pv['title'] ?: $pv['url']); ?></div>
                                            <div class="pv-meta">
                                                Step <?php echo $pv['step_order']; ?> |
                                                <?php echo htmlspecialchars($pv['visited_at']); ?> |
                                                Dwell: <?php echo fmtDuration($pv['duration_seconds']); ?> |
                                                Scroll: <?php echo $pv['scroll_depth']; ?>%
                                                <?php if ($pv['url']): ?>
                                                    <br><a href="<?php echo htmlspecialchars($pv['url']); ?>" target="_blank" style="color:#8B7355;font-size:11px;"><?php echo htmlspecialchars($pv['url']); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'settings'): ?>
                    <!-- Settings -->
                    <div class="panel">
                        <div class="panel-header">Settings</div>
                        <div class="panel-body">
                            <form method="POST">
                                <div class="setting-row">
                                    <label>Session Timeout (minutes)</label>
                                    <input type="number" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>" min="5" max="120">
                                    <span style="color:#888;font-size:12px;">How long before a visitor session expires</span>
                                </div>
                                <div class="setting-row">
                                    <label>Data Retention (days)</label>
                                    <input type="number" name="retention_days" value="<?php echo htmlspecialchars($settings['retention_days'] ?? '90'); ?>" min="1" max="730">
                                    <span style="color:#888;font-size:12px;">Auto-delete data older than this</span>
                                </div>
                                <div class="setting-row">
                                    <label>Enable Geo Lookup</label>
                                    <input type="checkbox" name="enable_geo" <?php echo ($settings['enable_geo'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <span style="color:#888;font-size:12px;">Resolve IP to country/city via ip-api.com (free)</span>
                                </div>
                                <div style="margin-top:15px;">
                                    <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
                                </div>
                            </form>

                            <hr style="margin:30px 0;border:none;border-top:1px solid #eee;">

                            <h3 style="color:#e74c3c;margin-bottom:10px;">Danger Zone</h3>
                            <p style="color:#666;font-size:13px;margin-bottom:10px;">
                                Delete tracking data older than the retention period (<?php echo htmlspecialchars($settings['retention_days'] ?? '90'); ?> days).
                            </p>
                            <form method="POST" onsubmit="return confirm('Delete old data? This cannot be undone.');">
                                <button type="submit" name="cleanup_data" class="btn btn-danger">Clean Up Old Data</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
