<?php
/**
 * VJT Visitor Journey Tracker - Admin Dashboard
 * Password-protected (shares password with email-logs.php)
 */

session_start();

header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/api/vjt-helpers.php';

// Password config (shared with email-logs.php, stored ABOVE public_html)
define('PASSWORD_FILE_OLD', __DIR__ . '/.email_logs_password');
define('PASSWORD_FILE', dirname(__DIR__) . '/.email_logs_password');
define('ADMIN_EMAIL', 'kssmi@kssmi.com');

// Migrate password file if it still lives inside public_html
if (file_exists(PASSWORD_FILE_OLD) && !file_exists(PASSWORD_FILE)) {
    @rename(PASSWORD_FILE_OLD, PASSWORD_FILE);
    @chmod(PASSWORD_FILE, 0600);
    error_log('KSSMI: Migrated .email_logs_password outside public_html');
}

function getPasswordHash() {
    if (file_exists(PASSWORD_FILE)) {
        $content = @file_get_contents(PASSWORD_FILE);
        if ($content !== false) {
            $hash = trim($content);
            if (!empty($hash)) return $hash;
        }
    }
    // Fallback: file may not have been migrated yet
    if (file_exists(PASSWORD_FILE_OLD)) {
        $content = @file_get_contents(PASSWORD_FILE_OLD);
        if ($content !== false) {
            $hash = trim($content);
            if (!empty($hash)) return $hash;
        }
    }
    return null;
}

$PASSWORD_HASH = getPasswordHash();
$error = '';
$message = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $submitted = trim($_POST['password']);
    if ($PASSWORD_HASH && password_verify($submitted, $PASSWORD_HASH)) {
        $_SESSION['email_logs_auth'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setcookie('vjt_admin', '1', time() + 86400 * 7, '/', '', false, true);
        session_regenerate_id(true);
    } else {
        $error = 'Invalid password.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('vjt_admin', '', time() - 3600, '/');
    header('Location: visitor-journey.php');
    exit;
}

$isAuthenticated = isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true;

// Keep admin cookie alive so the tracker skips admin visits
if ($isAuthenticated) {
    setcookie('vjt_admin', '1', time() + 86400 * 7, '/', '', false, true);
}

// Determine active tab
$tab = $_GET['tab'] ?? 'overview';
$trendPeriod = $_GET['trend'] ?? 'days';
$validTabs = ['overview', 'submissions', 'traffic', 'visitors', 'journey', 'countries', 'products', 'settings'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

// ── Data helpers ────────────────────────────────────────────────────────────

vjt_data_init();

function getSettings() {
    return vjt_get_settings();
}

// ── Handle settings save ─────────────────────────────────────────────────────

if ($isAuthenticated && isset($_POST['save_settings'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Security check failed. Please try again.';
    } else {
        $allowed = ['session_timeout', 'retention_days', 'enable_geo'];
        $settings = array_intersect_key($_POST, array_flip($allowed));
        vjt_save_settings($settings);
        $message = 'Settings saved.';
    }
}

// Handle data cleanup
if ($isAuthenticated && isset($_POST['cleanup_data'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Security check failed. Please try again.';
    } else {
        $days = max(0, (int)($_POST['cleanup_days'] ?? 90));
        if ($days === 0) {
            vjt_wipe_all_data();
            $message = 'All tracking data has been deleted.';
        } else {
            vjt_cleanup_old_data($days);
            $message = 'Old data cleaned up (older than ' . $days . ' days).';
        }
    }
}

// Handle CSV export
if ($isAuthenticated && isset($_GET['export_csv'])) {
    vjt_export_submissions_csv_start([
        'status' => $_GET['status'] ?? '',
        'plugin' => $_GET['plugin'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'page' => 1,
        'per_page' => 10000,
    ]);
    exit;
}

// ── Fetch data for tabs ──────────────────────────────────────────────────────

$overview = null;
$submissions = [];
$visitors = [];
$journeyData = null;
$settings = getSettings();

if ($tab === 'overview') {
    if ($trendPeriod === 'months') {
        $since = date('Y-m-d H:i:s', time() - (365 * 86400));
        $periodLabel = '12m';
    } elseif ($trendPeriod === 'years') {
        $since = date('Y-m-d H:i:s', time() - (5 * 365 * 86400));
        $periodLabel = '5y';
    } else {
        $since = date('Y-m-d H:i:s', time() - (30 * 86400));
        $periodLabel = '30d';
    }
    $overview = vjt_get_overview($since);
}

// ── Submissions list ─────────────────────────────────────────────────────────

$subPage     = max(1, (int)($_GET['sp'] ?? 1));
$subPerPage  = 100;
$subStatus   = $_GET['status'] ?? '';
$subPlugin   = $_GET['plugin'] ?? '';
$subDateFrom = $_GET['date_from'] ?? '';
$subDateTo   = $_GET['date_to'] ?? '';
$subTotal    = 0;

if ($tab === 'submissions') {
    $result = vjt_get_submissions_list([
        'status' => $subStatus,
        'plugin' => $subPlugin,
        'date_from' => $subDateFrom,
        'date_to' => $subDateTo,
        'page' => $subPage,
        'per_page' => $subPerPage,
    ]);
    $submissions = $result['items'];
    $subTotal = $result['total'];

    foreach ($submissions as &$sub) {
        if ($sub['status'] === 'attempt' && strtotime($sub['submitted_at']) < time() - 1800) {
            $sub['display_status'] = 'abandoned';
        } else {
            $sub['display_status'] = $sub['status'];
        }
    }
    unset($sub);
}

// ── Traffic Performance ──────────────────────────────────────────────────────

$trafficData = null;
$trafficPeriod = $_GET['tp'] ?? 'days';
if ($tab === 'traffic') {
    if ($trafficPeriod === 'months') {
        $trafficSince = date('Y-m-d H:i:s', time() - (365 * 86400));
    } elseif ($trafficPeriod === 'years') {
        $trafficSince = date('Y-m-d H:i:s', time() - (5 * 365 * 86400));
    } else {
        $trafficSince = date('Y-m-d H:i:s', time() - (30 * 86400));
    }
    $trafficData = vjt_get_traffic_data($trafficSince);
}

// ── Visitors list ────────────────────────────────────────────────────────────

$visPage    = max(1, (int)($_GET['vp'] ?? 1));
$visPerPage = 100;
$visSearch       = $_GET['search'] ?? '';
$visDevice       = $_GET['device'] ?? '';
$visSource       = $_GET['source'] ?? '';
$visSessionsMin  = $_GET['sessions_min'] ?? '';
$visSessionsMax  = $_GET['sessions_max'] ?? '';
$visSubmissionsMin = $_GET['submissions_min'] ?? '';
$visSubmissionsMax = $_GET['submissions_max'] ?? '';
$visSessionTimeMin = $_GET['session_time_min'] ?? '';
$visSortBy    = $_GET['sort'] ?? 'last_seen_at';
$visSortOrder = $_GET['order'] ?? 'desc';
$visTotal     = 0;

if ($tab === 'visitors') {
    $result = vjt_get_visitors_list([
        'search' => $visSearch,
        'device' => $visDevice,
        'source' => $visSource,
        'sessions_min' => $visSessionsMin,
        'sessions_max' => $visSessionsMax,
        'submissions_min' => $visSubmissionsMin,
        'submissions_max' => $visSubmissionsMax,
        'session_time_min' => $visSessionTimeMin,
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'sort_by' => $visSortBy,
        'sort_order' => $visSortOrder,
        'page' => $visPage,
        'per_page' => $visPerPage,
    ]);
    $visitors = $result['items'];
    $visTotal = $result['total'];


}

// ── Journey detail ───────────────────────────────────────────────────────────

if ($tab === 'journey' && !empty($_GET['visitor_id'])) {
    $journeyData = vjt_get_journey($_GET['visitor_id']);
}

// ── Countries & Products ──────────────────────────────────────────────────────

$countries = [];
if ($tab === 'countries') {
    $countries = vjt_get_countries();
}

$products = [];
$prodDateFrom = $_GET['prod_date_from'] ?? '';
$prodDateTo   = $_GET['prod_date_to'] ?? '';
if ($tab === 'products') {
    $products = vjt_get_products($prodDateFrom, $prodDateTo);
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

// Truncate URL for display (strip VJT params, limit length)
function fmtUrl($url) {
    // Strip VJT tracking params from display
    $clean = preg_replace('/[?&]vjt_[^&]+/', '', $url);
    $clean = preg_replace('/\?$/', '', $clean);
    $clean = preg_replace('/&$/', '', $clean);
    if (strlen($clean) > 80) {
        return substr($clean, 0, 77) . '...';
    }
    return $clean;
}

// Source badge color
function sourceBadge($source) {
    $map = [
        'ads' => ['#e74c3c', '#fdeaea', 'Ads'],
        'ai' => ['#8e44ad', '#f3e5f5', 'AI'],
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

// Sortable header link helper
function sortLink($column, $label, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentOrder === 'asc' ? ' ▲' : ' ▼';
    }
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    $params['vp'] = 1; // reset to page 1 on re-sort
    $url = '?' . http_build_query($params);
    return '<a href="' . htmlspecialchars($url) . '" style="color:#5D4E37;text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Journey Tracker - KSSMI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 14px 20px; color: #333; }
        .container { max-width: 1500px; margin: 0 auto; }
        h1 { color: #5D4E37; }
        .subtitle { color: #888; }

        /* Login */
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:hover { background: #5D4E37; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; background: white; padding: 12px 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border-left: 3px solid #8B7355; }
        .header-left { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .header-left h1 { font-size: 16px; font-weight: 700; color: #5D4E37; white-space: nowrap; }
        .header-left .subtitle { font-size: 13px; color: #888; }
        .header-right { display: flex; gap: 6px; align-items: center; }

        /* Tabs */
        .tabs { display: flex; gap: 0; margin-bottom: 14px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .tab { padding: 10px 20px; text-decoration: none; color: #666; font-weight: 500; font-size: 13px; transition: all 0.15s; border-bottom: 3px solid transparent; }
        .tab:hover { color: #5D4E37; background: #faf9f7; }
        .tab.active { color: #5D4E37; border-bottom-color: #8B7355; background: #faf9f7; }

        /* Buttons */
        .btn { padding: 6px 14px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 12px; display: inline-block; font-weight: 500; }
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
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 14px; align-items: stretch; }
        .stat-card { background: white; padding: 16px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-top: 3px solid #8B7355; transition: box-shadow 0.15s; display: flex; flex-direction: column; }
        .stat-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,0.08); }
        .stat-card h3 { font-size: 10px; text-transform: uppercase; color: #888; margin-bottom: 4px; letter-spacing: 0.8px; }
        .stat-card .value { font-size: 26px; font-weight: bold; color: #5D4E37; flex: 1; display: flex; align-items: center; }
        .stat-card .sub { font-size: 12px; color: #888; margin-top: 4px; }

        /* Panels */
        .panel { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 14px; }
        .panel-header { padding: 12px 16px; border-bottom: 1px solid #eee; font-weight: 600; color: #5D4E37; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
        .panel-body { padding: 16px; }

        /* Tables */
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
        tr:hover { background: #fafafa; }
        .mono { font-family: monospace; font-size: 11px; }

        /* Status badges */
        .status { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-attempt { background: #fff3cd; color: #856404; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-abandoned { background: #eaeded; color: #7f8c8d; }

        /* Filters */
        .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .filters select, .filters input { padding: 5px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
        .filters input[type="date"] { width: 140px; }

        /* Pagination */
        .pagination { display: flex; gap: 4px; justify-content: center; margin-top: 15px; }
        .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #666; font-size: 13px; }
        .pagination a:hover { background: #8B7355; color: white; border-color: #8B7355; }
        .pagination .current { background: #8B7355; color: white; border-color: #8B7355; }

        /* Bar chart */
        .bar-chart { display: flex; align-items: flex-end; gap: 6px; height: 140px; padding: 0 10px; }
        .bar-col { flex: 1; display: flex; flex-direction: column; align-items: center; min-width: 0; }
        .bar { background: #8B7355; width: 100%; max-width: 40px; border-radius: 3px 3px 0 0; min-height: 2px; transition: all 0.2s ease; cursor: pointer; }
        .bar:hover { background: #5D4E37; opacity: 0.85; transform: scaleY(1.05); transform-origin: bottom; }
        .bar-label { font-size: 9px; color: #888; margin-top: 4px; transform: rotate(-45deg); transform-origin: top left; white-space: nowrap; }
        .bar-value { font-size: 10px; color: #5D4E37; font-weight: 600; margin-bottom: 4px; }

        /* Trend sub-tabs */
        .trend-tab { padding: 3px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 500; color: #888; background: #f0f0f0; transition: all 0.15s; }
        .trend-tab:hover { color: #5D4E37; background: #e0d8cc; }
        .trend-tab-active { background: #8B7355; color: white; }
        .trend-tab-active:hover { background: #5D4E37; color: white; }

        /* Journey detail */
        .journey-section { margin-bottom: 25px; }
        .journey-section h3 { color: #5D4E37; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #8B7355; font-size: 14px; }
        .journey-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .journey-item { background: #f8f7f5; padding: 10px 14px; border-radius: 6px; }
        .journey-item label { font-size: 10px; color: #888; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
        .journey-item .val { font-size: 13px; color: #333; word-break: break-word; }
        .city-link { color: #8B7355; text-decoration: none; border-bottom: 1px dashed #c4b896; }
        .city-link:hover { color: #5D4E37; border-bottom-color: #5D4E37; }
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

        /* Mobile responsive */
        @media (max-width: 768px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .tabs { flex-wrap: wrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .tab { padding: 10px 12px; font-size: 12px; white-space: nowrap; }
            .filters { flex-direction: column; align-items: flex-start; }
            .header { flex-direction: column; align-items: flex-start; }
            .header-right { width: 100%; justify-content: flex-end; }
            /* Overview grid: stack referrers + source/device vertically */
            .overview-grid { grid-template-columns: 1fr !important; }
            .journey-grid { grid-template-columns: 1fr; }
            /* Tables: tighter padding on mobile */
            th, td { padding: 6px 8px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            .stats { grid-template-columns: 1fr; }
            .stat-card { padding: 12px 14px; }
            .stat-card .value { font-size: 22px; }
            th, td { padding: 5px 6px; font-size: 11px; }
            .panel-header { font-size: 12px; padding: 10px 12px; }
            .container { padding: 0 4px; }
            body { padding: 8px 4px; }
            .filters input, .filters select { font-size: 11px; padding: 4px 6px; }
            .url-cell { max-width: 140px; }
            .mono { font-size: 10px; }
            .login-box { margin: 40px auto; padding: 24px; }
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

                <!-- Tabs -->
                <div class="tabs">
                    <a href="?tab=overview" class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">Overview</a>
                    <a href="?tab=submissions" class="tab <?php echo $tab === 'submissions' ? 'active' : ''; ?>">Submissions</a>
                    <a href="?tab=traffic" class="tab <?php echo $tab === 'traffic' ? 'active' : ''; ?>">Traffic</a>
                    <a href="?tab=visitors" class="tab <?php echo $tab === 'visitors' ? 'active' : ''; ?>">Visitors</a>
                    <a href="?tab=countries" class="tab <?php echo $tab === 'countries' ? 'active' : ''; ?>">Countries</a>
                    <a href="?tab=products" class="tab <?php echo $tab === 'products' ? 'active' : ''; ?>">Products</a>
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
                    <?php
                    $trendData = $overview['trend'] ?? [];
                    $trendTitle = 'Submission Trend (30 Days)';
                    if ($trendPeriod === 'months') {
                        $trendData = $overview['trendMonthly'] ?? [];
                        $trendTitle = 'Submission Trend (12 Months)';
                    } elseif ($trendPeriod === 'years') {
                        $trendData = $overview['trendYearly'] ?? [];
                        $trendTitle = 'Submission Trend (Years)';
                    }
                    ?>
                    <?php if (!empty($trendData)): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <span><?php echo $trendTitle; ?></span>
                            <div style="display:flex;gap:4px;">
                                <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="bar-chart">
                                <?php
                                $maxCnt = max($trendData) ?: 1;
                                foreach ($trendData as $key => $cnt):
                                    $h = $maxCnt > 0 ? round(($cnt / $maxCnt) * 100) : 0;
                                    if ($trendPeriod === 'months') {
                                        $label = date('M', strtotime($key . '-01'));
                                    } elseif ($trendPeriod === 'years') {
                                        $label = $key;
                                    } else {
                                        $label = substr($key, 5); // MM-DD
                                    }
                                ?>
                                    <div class="bar-col">
                                        <div class="bar-value"><?php echo $cnt; ?></div>
                                        <div class="bar" style="height:<?php echo max(2, $h); ?>px;" title="<?php echo $key; ?>: <?php echo $cnt; ?>"></div>
                                        <div class="bar-label"><?php echo htmlspecialchars($label); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="overview-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <!-- Top Referrers -->
                        <div class="panel">
                            <div class="panel-header">
                                <span>Top Referrers (<?php echo $periodLabel; ?>)</span>
                                <div style="display:flex;gap:4px;">
                                    <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                    <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                    <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                                </div>
                            </div>
                            <div class="panel-body" style="padding:0;">
                                <?php if (empty($overview['topReferrers'])): ?>
                                    <div class="empty" style="padding:30px;"><p>No referrer data yet</p></div>
                                <?php else: ?>
                                    <table>
                                        <thead><tr><th>Source</th><th style="text-align:right;">Sessions</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($overview['topReferrers'] as $label => $cnt):
                                                $host = parse_url($label, PHP_URL_HOST);
                                                $display = $host ?: ($label ?: 'Direct');
                                                $display = preg_replace('/^www\./', '', $display);
                                            ?>
                                                <tr>
                                                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($display); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($cnt); ?></td>
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
                                <div class="panel-header">
                                    <span>Traffic Sources (<?php echo $periodLabel; ?>)</span>
                                    <div style="display:flex;gap:4px;">
                                        <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                        <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                        <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                                    </div>
                                </div>
                                <div class="panel-body" style="padding:0;">
                                    <table>
                                        <thead><tr><th>Source</th><th style="text-align:right;">Sessions</th></tr></thead>
                                        <tbody>
                                            <?php foreach (['search', 'social', 'ai', 'direct', 'ads', 'other'] as $src):
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
                                <div class="panel-header">
                                    <span>Device Breakdown (<?php echo $periodLabel; ?>)</span>
                                    <div style="display:flex;gap:4px;">
                                        <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                        <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                        <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                                    </div>
                                </div>
                                <div class="panel-body" style="padding:0;">
                                    <?php if (empty($overview['deviceCounts'])): ?>
                                        <div class="empty" style="padding:30px;"><p>No device data yet</p></div>
                                    <?php else: ?>
                                        <table>
                                            <thead><tr><th>Device</th><th style="text-align:right;">Visitors</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($overview['deviceCounts'] as $deviceType => $cnt): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(ucfirst($deviceType)); ?></td>
                                                        <td style="text-align:right;"><?php echo number_format($cnt); ?></td>
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
                                                    <td class="url-cell"><a href="<?php echo htmlspecialchars($sub['submit_page'] ?? '#'); ?>" target="_blank"><?php echo htmlspecialchars(fmtUrl($sub['submit_page'] ?? '-')); ?></a></td>
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

                <?php elseif ($tab === 'traffic' && $trafficData): ?>
                    <!-- Traffic Performance -->
                    <?php
                    $tpLabel = $trafficPeriod === 'months' ? '12m' : ($trafficPeriod === 'years' ? '5y' : '30d');
                    ?>
                    <div class="stats">
                        <div class="stat-card">
                            <h3>Total Pageviews (<?php echo $tpLabel; ?>)</h3>
                            <div class="value"><?php echo number_format($trafficData['totalPageviews']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Unique Pages</h3>
                            <div class="value"><?php echo number_format($trafficData['uniqueUrls']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Avg Dwell Time</h3>
                            <div class="value"><?php echo fmtDuration($trafficData['avgDwellAll']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Bounce Rate</h3>
                            <div class="value"><?php echo $trafficData['bounceRate']; ?>%</div>
                        </div>
                        <div class="stat-card">
                            <h3>Sessions</h3>
                            <div class="value"><?php echo number_format($trafficData['totalSessions']); ?></div>
                        </div>
                    </div>

                    <!-- Pageviews Trend -->
                    <?php
                    $trendChartData = $trafficData['dailyTrend'] ?? [];
                    if ($trafficPeriod === 'months') $trendChartData = $trafficData['monthlyTrend'] ?? [];
                    elseif ($trafficPeriod === 'years') $trendChartData = $trafficData['yearlyTrend'] ?? [];
                    ?>
                    <?php if (!empty($trendChartData)): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <span>Pageviews Trend</span>
                            <div style="display:flex;gap:4px;">
                                <a href="?tab=traffic&tp=days" class="trend-tab <?php echo $trafficPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                <a href="?tab=traffic&tp=months" class="trend-tab <?php echo $trafficPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                <a href="?tab=traffic&tp=years" class="trend-tab <?php echo $trafficPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="bar-chart">
                                <?php
                                $maxCnt = max($trendChartData) ?: 1;
                                foreach ($trendChartData as $key => $cnt):
                                    $h = $maxCnt > 0 ? round(($cnt / $maxCnt) * 100) : 0;
                                    if ($trafficPeriod === 'months') {
                                        $label = date('M', strtotime($key . '-01'));
                                    } elseif ($trafficPeriod === 'years') {
                                        $label = $key;
                                    } else {
                                        $label = substr($key, 5);
                                    }
                                ?>
                                    <div class="bar-col">
                                        <div class="bar-value"><?php echo $cnt; ?></div>
                                        <div class="bar" style="height:<?php echo max(2, $h); ?>px;" title="<?php echo $key; ?>: <?php echo $cnt; ?> pageviews"></div>
                                        <div class="bar-label"><?php echo htmlspecialchars($label); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Top Pages -->
                    <?php if (!empty($trafficData['topPages'])): ?>
                    <div class="panel">
                        <div class="panel-header">Top Pages</div>
                        <div class="panel-body" style="padding:0;">
                            <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Page URL</th>
                                        <th style="text-align:center;width:70px;">Views</th>
                                        <th style="text-align:center;width:80px;">Avg Dwell</th>
                                        <th style="text-align:center;width:80px;">Scroll</th>
                                        <th style="text-align:center;width:80px;">Submissions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rank = 1; foreach ($trafficData['topPages'] as $page): ?>
                                        <tr>
                                            <td style="color:#999;"><?php echo $rank++; ?></td>
                                            <td class="url-cell">
                                                <a href="<?php echo htmlspecialchars($page['url']); ?>" target="_blank" rel="noopener">
                                                    <?php echo htmlspecialchars(strlen($page['url']) > 80 ? substr($page['url'], 0, 80) . '...' : $page['url']); ?>
                                                </a>
                                            </td>
                                            <td style="text-align:center;font-weight:600;"><?php echo number_format($page['views']); ?></td>
                                            <td style="text-align:center;"><?php echo $page['avg_duration'] > 0 ? fmtDuration($page['avg_duration']) : '-'; ?></td>
                                            <td style="text-align:center;"><?php echo $page['avg_scroll'] > 0 ? $page['avg_scroll'] . '%' : '-'; ?></td>
                                            <td style="text-align:center;"><?php echo $page['submissions'] > 0 ? number_format($page['submissions']) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

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
                                    <option value="ai" <?php echo $visSource === 'ai' ? 'selected' : ''; ?>>AI</option>
                                    <option value="search" <?php echo $visSource === 'search' ? 'selected' : ''; ?>>Search</option>
                                    <option value="social" <?php echo $visSource === 'social' ? 'selected' : ''; ?>>Social</option>
                                    <option value="direct" <?php echo $visSource === 'direct' ? 'selected' : ''; ?>>Direct</option>
                                    <option value="other" <?php echo $visSource === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <select name="sessions_min">
                                    <option value="">Sessions (min)</option>
                                    <option value="1" <?php echo $visSessionsMin === '1' ? 'selected' : ''; ?>>1+</option>
                                    <option value="2" <?php echo $visSessionsMin === '2' ? 'selected' : ''; ?>>2+</option>
                                    <option value="3" <?php echo $visSessionsMin === '3' ? 'selected' : ''; ?>>3+</option>
                                    <option value="5" <?php echo $visSessionsMin === '5' ? 'selected' : ''; ?>>5+</option>
                                    <option value="10" <?php echo $visSessionsMin === '10' ? 'selected' : ''; ?>>10+</option>
                                </select>
                                <select name="submissions_min">
                                    <option value="">Submissions (min)</option>
                                    <option value="1" <?php echo $visSubmissionsMin === '1' ? 'selected' : ''; ?>>1+</option>
                                    <option value="2" <?php echo $visSubmissionsMin === '2' ? 'selected' : ''; ?>>2+</option>
                                    <option value="3" <?php echo $visSubmissionsMin === '3' ? 'selected' : ''; ?>>3+</option>
                                    <option value="5" <?php echo $visSubmissionsMin === '5' ? 'selected' : ''; ?>>5+</option>
                                </select>
                                <select name="session_time_min">
                                    <option value="">Session Time (min)</option>
                                    <option value="30" <?php echo $visSessionTimeMin === '30' ? 'selected' : ''; ?>>30s+</option>
                                    <option value="60" <?php echo $visSessionTimeMin === '60' ? 'selected' : ''; ?>>1m+</option>
                                    <option value="180" <?php echo $visSessionTimeMin === '180' ? 'selected' : ''; ?>>3m+</option>
                                    <option value="300" <?php echo $visSessionTimeMin === '300' ? 'selected' : ''; ?>>5m+</option>
                                    <option value="600" <?php echo $visSessionTimeMin === '600' ? 'selected' : ''; ?>>10m+</option>
                                    <option value="1800" <?php echo $visSessionTimeMin === '1800' ? 'selected' : ''; ?>>30m+</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($visSearch || $visDevice || $visSource || $visSessionsMin !== '' || $visSessionsMax !== '' || $visSubmissionsMin !== '' || $visSubmissionsMax !== '' || $visSessionTimeMin !== ''): ?>
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
                                                <th><?php echo sortLink('visitor_id', 'Visitor ID', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('first_seen_at', 'First Seen', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('last_seen_at', 'Last Seen', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('session_time', 'Session Time', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('country', 'Country', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('device_type', 'Device', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('browser', 'Browser', $visSortBy, $visSortOrder); ?></th>
                                                <th style="text-align:center;"><?php echo sortLink('sessions', 'Sessions', $visSortBy, $visSortOrder); ?></th>
                                                <th style="text-align:center;"><?php echo sortLink('submissions', 'Submissions', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('source', 'Source', $visSortBy, $visSortOrder); ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($visitors as $v): ?>
                                                <tr>
                                                    <td class="mono"><a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>" class="link"><?php echo htmlspecialchars(str_replace('vjtv_', '', $v['visitor_id'])); ?></a></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars($v['first_seen_at']); ?></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars($v['last_seen_at']); ?></td>
                                                    <td style="font-size:12px;"><?php echo $v['session_time'] > 0 ? fmtDuration($v['session_time']) : '-'; ?></td>
                                                    <td><?php echo $v['country'] ? htmlspecialchars(getCountryName($v['country'])) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars(ucfirst($v['device_type'] ?: '-')); ?></td>
                                                    <td><?php echo htmlspecialchars($v['browser'] ?: '-'); ?></td>
                                                    <td style="text-align:center;"><?php echo $v['sessions']; ?></td>
                                                    <td style="text-align:center;"><?php echo $v['submissions']; ?></td>
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
                                            if ($visSessionsMin !== '') $pageUrl .= '&sessions_min=' . urlencode($visSessionsMin);
                                            if ($visSessionsMax !== '') $pageUrl .= '&sessions_max=' . urlencode($visSessionsMax);
                                            if ($visSubmissionsMin !== '') $pageUrl .= '&submissions_min=' . urlencode($visSubmissionsMin);
                                            if ($visSubmissionsMax !== '') $pageUrl .= '&submissions_max=' . urlencode($visSubmissionsMax);
                                            if ($visSessionTimeMin !== '') $pageUrl .= '&session_time_min=' . urlencode($visSessionTimeMin);
                                            $pageUrl .= '&sort=' . urlencode($visSortBy) . '&order=' . urlencode($visSortOrder);
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
                    <?php
                        $v = $journeyData['visitor'];
                        // Total Session Time = sum of each session window (last_seen_at - started_at)
                        $totalSessionTime = 0;
                        foreach ($journeyData['sessions'] as $sess) {
                            $st = strtotime($sess['started_at'] ?? '');
                            $ls = strtotime($sess['last_seen_at'] ?? '');
                            if ($st && $ls && $ls > $st) {
                                $totalSessionTime += ($ls - $st);
                            }
                        }
                    ?>
                    <div class="panel">
                        <div class="panel-header">
                            Journey: <?php echo htmlspecialchars(str_replace('vjtv_', '', $v['visitor_id'])); ?>
                            <a href="?tab=visitors" class="btn btn-secondary btn-small">Back to Visitors</a>
                        </div>
                        <div class="panel-body">
                            <div class="journey-section">
                                <h3>Visitor Info</h3>
                                <div class="journey-grid">
                                    <div class="journey-item"><label>Visitor ID</label><div class="val mono"><?php echo htmlspecialchars(str_replace('vjtv_', '', $v['visitor_id'])); ?></div></div>
                                    <div class="journey-item"><label>First IP</label><div class="val"><?php echo htmlspecialchars($v['first_ip']); ?></div></div>
                                    <div class="journey-item"><label>Country</label><div class="val"><?php echo htmlspecialchars(getCountryName($v['country'])); ?></div></div>
                                    <div class="journey-item"><label>City</label><div class="val"><?php if ($v['city']): ?><a href="https://www.google.com/maps/search/<?php echo urlencode($v['city'] . ($v['country'] ? ', ' . getCountryName($v['country']) : '')); ?>" target="_blank" rel="noopener" class="city-link" title="Open in Google Maps"><?php echo htmlspecialchars($v['city']); ?></a><?php else: ?>-<?php endif; ?></div></div>
                                    <div class="journey-item"><label>Site <span style="font-weight:400;color:#888;">(Language)</span></label><div class="val"><?php echo htmlspecialchars($v['site_language'] ?? 'EN'); ?></div></div>
                                    <div class="journey-item"><label>Language <span style="font-weight:400;color:#888;">(Browser)</span></label><div class="val"><?php echo htmlspecialchars($v['language'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Timezone</label><div class="val"><?php echo htmlspecialchars($v['timezone'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Browser</label><div class="val"><?php echo htmlspecialchars($v['browser'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>Device</label><div class="val"><?php echo htmlspecialchars(ucfirst($v['device_type'] ?: '-')); ?></div></div>
                                    <div class="journey-item"><label>Screen</label><div class="val"><?php echo htmlspecialchars($v['screen_resolution'] ?: '-'); ?></div></div>
                                    <div class="journey-item"><label>First Seen</label><div class="val"><?php echo htmlspecialchars($v['first_seen_at']); ?></div></div>
                                    <div class="journey-item"><label>Last Seen</label><div class="val"><?php echo htmlspecialchars($v['last_seen_at']); ?></div></div>
                                    <div class="journey-item"><label>Session Time <span style="font-weight:400;color:#888;">(Total on site)</span></label><div class="val"><?php echo $totalSessionTime > 0 ? fmtDuration($totalSessionTime) : '-'; ?></div></div>
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
                                                    <td class="url-cell"><a href="<?php echo htmlspecialchars($sub['submit_page'] ?? '#'); ?>" target="_blank"><?php echo htmlspecialchars(fmtUrl($sub['submit_page'] ?? '-')); ?></a></td>
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
                                                    <br><a href="<?php echo htmlspecialchars($pv['url']); ?>" target="_blank" style="color:#8B7355;font-size:11px;"><?php echo htmlspecialchars(fmtUrl($pv['url'])); ?></a>
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

                <?php elseif ($tab === 'countries'): ?>
                    <!-- Countries -->
                    <div class="panel">
                        <div class="panel-header">Countries (<?php echo number_format(count($countries)); ?>)</div>
                        <div class="panel-body">
                            <?php if (empty($countries)): ?>
                                <div class="empty"><div class="empty-icon">🌍</div><p>No country data yet</p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Country</th>
                                                <th style="text-align:center;">Visitors</th>
                                                <th style="text-align:center;">Sessions</th>
                                                <th style="text-align:center;">Submissions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $rank = 1; foreach ($countries as $c): ?>
                                                <tr>
                                                    <td style="color:#999;font-size:12px;"><?php echo $rank++; ?></td>
                                                    <td>
                                                        <span class="country-badge" style="font-size:13px;"><?php echo htmlspecialchars(getCountryName($c['code'])); ?></span>
                                                    </td>
                                                    <td style="text-align:center;font-weight:600;"><?php echo number_format($c['visitors']); ?></td>
                                                    <td style="text-align:center;"><?php echo number_format($c['sessions']); ?></td>
                                                    <td style="text-align:center;"><?php echo number_format($c['submissions']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'products'): ?>
                    <!-- Products -->
                    <div class="panel">
                        <div class="panel-header">Products (<?php echo number_format(count($products)); ?>)</div>
                        <div class="panel-body">
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="products">
                                <input type="date" name="prod_date_from" value="<?php echo htmlspecialchars($prodDateFrom); ?>" placeholder="From">
                                <input type="date" name="prod_date_to" value="<?php echo htmlspecialchars($prodDateTo); ?>" placeholder="To">
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($prodDateFrom || $prodDateTo): ?>
                                    <a href="?tab=products" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                            </form>

                            <?php if (empty($products)): ?>
                                <div class="empty"><div class="empty-icon">📦</div><p>No product pageviews yet</p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>SKU</th>
                                                <th>URL</th>
                                                <th style="text-align:center;">Views</th>
                                                <th style="text-align:center;">Visitors</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $rank = 1; foreach ($products as $p): ?>
                                                <tr>
                                                    <td style="color:#999;font-size:12px;"><?php echo $rank++; ?></td>
                                                    <td class="mono" style="font-weight:600;"><?php echo htmlspecialchars($p['sku']); ?></td>
                                                    <td class="url-cell"><a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" style="font-size:12px;"><?php echo htmlspecialchars($p['url']); ?></a></td>
                                                    <td style="text-align:center;font-weight:600;"><?php echo number_format($p['views']); ?></td>
                                                    <td style="text-align:center;"><?php echo number_format($p['visitors']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'settings'): ?>
                    <!-- Settings -->
                    <div class="panel">
                        <div class="panel-header">Settings</div>
                        <div class="panel-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <div class="setting-row">
                                    <label>Session Timeout (minutes)</label>
                                    <input type="number" name="session_timeout" value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>" min="1" max="525600">
                                    <span style="color:#888;font-size:12px;">Inactivity before a new session starts (max 525600 = 1 year)</span>
                                </div>
                                <div class="setting-row">
                                    <label>Data Retention (days)</label>
                                    <input type="number" name="retention_days" value="<?php echo htmlspecialchars($settings['retention_days'] ?? '90'); ?>" min="1" max="3650">
                                    <span style="color:#888;font-size:12px;">Auto-delete data older than this (max 3650 = 10 years)</span>
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
                                Delete tracking data older than the specified number of days.
                            </p>
                            <form method="POST" onsubmit="return confirm('Delete old data? This cannot be undone.');" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <input type="number" name="cleanup_days" value="<?php echo htmlspecialchars($settings['retention_days'] ?? '90'); ?>" min="0" max="3650" style="width:80px;padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;" required>
                                <span style="color:#888;font-size:12px;">days (0 = delete all)</span>
                                <button type="submit" name="cleanup_data" class="btn btn-danger">Clean Up Old Data</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
