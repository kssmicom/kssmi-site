<?php
/**
 * VJT Visitor Journey Tracker - Admin Dashboard
 * Password-protected (shares password with email-logs.php)
 */

session_set_cookie_params([
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

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
$messageClass = 'success';

// Rate limit password guessing without creating an attacker-triggered year-long
// lockout for an administrator behind a shared/changing IP.
require_once dirname(__DIR__) . '/private/rate-limit.php';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!checkRateLimit('vj-login', 10, 900)) {
        $error = 'Too many login attempts. Please wait 15 minutes.';
    } else {
        $submitted = trim($_POST['password']);
        if ($PASSWORD_HASH && password_verify($submitted, $PASSWORD_HASH)) {
            $_SESSION['email_logs_auth'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            setcookie('vjt_admin', '1', time() + 86400 * 7, '/', '', true, true);
            session_regenerate_id(true);
        } else {
            $error = 'Invalid password.';
        }
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
    setcookie('vjt_admin', '1', time() + 86400 * 7, '/', '', true, true);
}

// Determine active tab
$tab = $_GET['tab'] ?? 'overview';
$trendPeriod = $_GET['trend'] ?? 'days';
$validTabs = ['overview', 'contacts', 'submissions', 'traffic', 'visitors', 'journey', 'countries', 'products', 'gsc', 'settings'];
if (!in_array($tab, $validTabs)) $tab = 'overview';

// ── Data helpers ────────────────────────────────────────────────────────────

// Never initialize, migrate, backfill, clean, or query analytics data before
// authentication. This keeps the public login page cheap and prevents DB-work DoS.
if ($isAuthenticated) vjt_data_init();

// Resolve any pending geo lookups off the visitor ingest path (admin-only).
// Backfills country/city for IPs collected since the last dashboard view.
if ($isAuthenticated && function_exists('vjt_process_geo_queue')) {
    vjt_process_geo_queue(100);
}

function getSettings() {
    return vjt_get_settings();
}

function isGscPermissionError($message) {
    $message = strtolower((string)$message);
    return strpos($message, 'sufficient permission') !== false
        || strpos($message, 'insufficient permission') !== false
        || strpos($message, 'permission denied') !== false;
}

// ── Handle settings save ─────────────────────────────────────────────────────

if ($isAuthenticated && isset($_POST['save_settings'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Security check failed. Please try again.';
    } else {
        $allowed = ['session_timeout', 'retention_days', 'enable_geo', 'excluded_ips', 'heartbeat_seconds', 'enable_email_summary', 'contact_intent_retention_days', 'contact_inquiry_retention_days'];
        $settings = array_intersect_key($_POST, array_flip($allowed));
        vjt_save_settings($settings);
        $message = 'Settings saved.';
    }
}

// Core rows are independent business events and use their own IDs.
if ($isAuthenticated && isset($_POST['delete_contact_ids'], $_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $ids = array_filter(array_map('intval', explode(',', (string)$_POST['delete_contact_ids'])));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = vjt_db()->prepare("DELETE FROM contact_events WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $message = count($ids) . ' Core event(s) deleted.';
    }
}

// Test the server-side service account and Search Console property without ever
// exposing the private key or OAuth token to the browser.
if ($isAuthenticated && isset($_POST['test_gsc_connection'])) {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Security check failed. Please try again.';
        $messageClass = 'error';
    } else {
        $gscTest = vjt_gsc_diagnostics(true);
        $gscLastTest = $gscTest['last_test'] ?? [];
        if (!empty($gscLastTest['ok'])) {
            $message = 'GSC connection test passed.';
        } elseif (isGscPermissionError($gscLastTest['message'] ?? '')) {
            $message = 'GSC connection test failed: grant the displayed service account access to the exact Search Console property, then test again.';
        } else {
            $message = 'GSC connection test failed: ' . ($gscLastTest['message'] ?? 'Unknown error.');
        }
        $messageClass = !empty($gscLastTest['ok']) ? 'success' : 'error';
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
            $message = 'Old Analytics Journey data cleaned up (older than ' . $days . ' days). Core follows its separate retention settings.';
        }
    }
}

// Handle submission deletion (single or bulk)
if ($isAuthenticated && isset($_POST['delete_ids'], $_POST['csrf_token'], $_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['delete_ids'])));
    if ($ids) {
        $db = vjt_db();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("DELETE FROM submissions WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $message = count($ids) . ' submission(s) deleted. Stats will update on next page load.';
    }
}

// Canonical Lead rows are backed by Contact Core. Linked rows are grouped by
// Visitor ID; unlinked rows are addressed by their stable Core event ID. This
// never deletes Journey visitor/session/pageview history or legacy enrichment.
$leadDeleteRaw = $_POST['delete_lead_keys'] ?? null;
$legacyLeadDeleteRaw = $_POST['delete_lead_visitors'] ?? null;
if ($isAuthenticated && ($leadDeleteRaw !== null || $legacyLeadDeleteRaw !== null)
    && isset($_POST['csrf_token'], $_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $keys = array_values(array_filter(array_map('trim', explode(',', (string)($leadDeleteRaw ?? '')))));
    // Accept a cached copy of the old dashboard once during rolling deployment.
    if (!$keys && $legacyLeadDeleteRaw !== null) {
        foreach (array_filter(array_map('trim', explode(',', (string)$legacyLeadDeleteRaw))) as $visitorId) {
            $keys[] = 'visitor:' . $visitorId;
        }
    }
    if ($keys) {
        $db = vjt_db();
        $deleted = 0;
        $db->beginTransaction();
        try {
            $deleteVisitorEvents = $db->prepare("DELETE FROM contact_events WHERE vjt_visitor_id = ?");
            $deleteSingleEvent = $db->prepare("DELETE FROM contact_events WHERE event_id = ?");
            foreach (array_unique($keys) as $key) {
                if (strpos($key, 'visitor:') === 0) {
                    $visitorId = substr($key, 8);
                    if (!preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $visitorId)) continue;
                    $deleteVisitorEvents->execute([$visitorId]);
                    $deleted += $deleteVisitorEvents->rowCount();
                } elseif (strpos($key, 'event:') === 0) {
                    $eventId = substr($key, 6);
                    if (!preg_match('/^vjtce_[A-Za-z0-9_-]{8,80}$/', $eventId)) continue;
                    $deleteSingleEvent->execute([$eventId]);
                    $deleted += $deleteSingleEvent->rowCount();
                }
            }
            $db->commit();
            $message = $deleted . ' Contact Core event(s) deleted. Journey history was preserved.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = 'Failed to delete Lead event(s).';
            error_log('VJT Lead deletion error: ' . $e->getMessage());
        }
    }
}

// Handle visitor deletion (deletes visitor + all associated sessions/pageviews/submissions)
if ($isAuthenticated && isset($_POST['delete_visitor'], $_POST['csrf_token'], $_SESSION['csrf_token'])
    && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $vid = trim((string)($_POST['delete_visitor'] ?? ''));
    if ($vid !== '') {
        $db = vjt_db();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM pageviews   WHERE visitor_id = ?")->execute([$vid]);
            $db->prepare("DELETE FROM sessions    WHERE visitor_id = ?")->execute([$vid]);
            $db->prepare("DELETE FROM submissions WHERE visitor_id = ?")->execute([$vid]);
            // Preserve independently lawful business events, but remove the
            // consented analytics linkage when the Journey visitor is deleted.
            $db->prepare("UPDATE contact_events SET vjt_visitor_id = '', vjt_session_id = '', journey_step = 0 WHERE vjt_visitor_id = ?")->execute([$vid]);
            $db->prepare("DELETE FROM visitors    WHERE visitor_id = ?")->execute([$vid]);
            $db->commit();
            $message = 'Visitor and all associated records deleted.';
        } catch (Exception $e) {
            $db->rollBack();
            $message = 'Failed to delete visitor.';
            error_log('VJT visitor delete error: ' . $e->getMessage());
        }
    }
}

// Handle CSV export
if ($isAuthenticated && isset($_GET['export_csv'])) {
    session_write_close();
    vjt_export_leads_csv_start([
        'status' => $_GET['status'] ?? 'contact',
        'channel' => $_GET['channel'] ?? ($_GET['plugin'] ?? ''),
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
    ]);
    exit;
}
if ($isAuthenticated && isset($_GET['export_contacts_csv'])) {
    session_write_close();
    vjt_export_contact_events_csv_start([
        'channel' => $_GET['channel'] ?? '',
        'status' => $_GET['status'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
    ]);
}
if ($isAuthenticated && isset($_GET['export_gsc_csv'])) {
    session_write_close();
    $days = in_array((int)($_GET['days'] ?? 28), [7, 28, 90], true) ? (int)($_GET['days'] ?? 28) : 28;
    vjt_export_gsc_keywords_csv_start($days);
}
if ($isAuthenticated && isset($_GET['export_visitors_csv'])) {
    vjt_export_visitors_csv_start([
        'search' => $_GET['search'] ?? '', 'device' => $_GET['device'] ?? '', 'source' => $_GET['source'] ?? '',
        'sessions_min' => $_GET['sessions_min'] ?? '', 'sessions_max' => $_GET['sessions_max'] ?? '',
        'submissions_min' => $_GET['submissions_min'] ?? '', 'submissions_max' => $_GET['submissions_max'] ?? '',
        'session_time_min' => $_GET['session_time_min'] ?? '', 'country' => $_GET['country'] ?? '', 'product_sku' => $_GET['product'] ?? '',
        'date_from' => $_GET['date_from'] ?? '', 'date_to' => $_GET['date_to'] ?? '',
        'sort_by' => $_GET['sort'] ?? 'last_seen_at', 'sort_order' => $_GET['order'] ?? 'desc',
    ]);
}
if ($isAuthenticated && !empty($_GET['export_journey_csv']) && !empty($_GET['visitor_id'])) {
    vjt_export_journey_csv_start($_GET['visitor_id']);
}

// ── Fetch data for tabs ──────────────────────────────────────────────────────

$overview = null;
$contactEvents = [];
$leads = [];
$visitors = [];
$journeyData = null;
$aiReferrals = [];
$settings = $isAuthenticated ? getSettings() : [];
$gscDiagnostics = [];
$gscReport = [];
$gscDays = in_array((int)($_GET['days'] ?? 28), [7, 28, 90], true) ? (int)($_GET['days'] ?? 28) : 28;
$gscPage = min(1000, max(1, (int)($_GET['gkp'] ?? 1)));
$gscPerPage = 100;

if ($isAuthenticated && ($tab === 'settings' || $tab === 'gsc')) {
    $gscDiagnostics = vjt_gsc_diagnostics(false);
}
if ($isAuthenticated && $tab === 'gsc' && !empty($gscDiagnostics['ready'])) {
    $gscReport = vjt_gsc_query_page($gscDays, $gscPage, $gscPerPage);
}

if ($isAuthenticated && $tab === 'overview') {
    if ($trendPeriod === 'months') {
        $since = vjt_utc_since_seconds(365 * 86400);
    } elseif ($trendPeriod === 'years') {
        $since = vjt_utc_since_seconds(5 * 365 * 86400);
    } else {
        $since = vjt_utc_since_seconds(30 * 86400);
    }
    $overview = vjt_get_overview($since);
    $aiReferrals = vjt_get_ai_referrals($since);
}

// ── Consent-independent Core events ──────────────────────────────────────────

$contactPage = max(1, (int)($_GET['cp'] ?? 1));
$contactPerPage = 100;
$contactChannel = $_GET['channel'] ?? '';
$contactStatus = $_GET['status'] ?? '';
$contactDateFrom = $_GET['date_from'] ?? '';
$contactDateTo = $_GET['date_to'] ?? '';
$contactTotal = 0;
if ($isAuthenticated && $tab === 'contacts') {
    $result = vjt_get_contact_events_list([
        'channel' => $contactChannel,
        'status' => $contactStatus,
        'date_from' => $contactDateFrom,
        'date_to' => $contactDateTo,
        'page' => $contactPage,
        'per_page' => $contactPerPage,
    ]);
    $contactEvents = $result['items'];
    $contactTotal = $result['total'];
}

// ── Canonical Contact Core Leads list ────────────────────────────────────────

$leadPage     = max(1, (int)($_GET['sp'] ?? 1));
$leadPerPage  = 100;
$leadStatus   = $_GET['status'] ?? 'contact';
$leadChannel  = $_GET['channel'] ?? ($_GET['plugin'] ?? '');
if ($leadChannel === 'kssmi-inquiry') $leadChannel = 'inquiry';
$leadDateFrom = $_GET['date_from'] ?? '';
$leadDateTo   = $_GET['date_to'] ?? '';
$leadTotal    = 0;

if ($isAuthenticated && $tab === 'submissions') {
    $result = vjt_get_leads_list([
        'status' => $leadStatus,
        'channel' => $leadChannel,
        'date_from' => $leadDateFrom,
        'date_to' => $leadDateTo,
        'page' => $leadPage,
        'per_page' => $leadPerPage,
    ]);
    $leads = $result['items'];
    $leadTotal = $result['total'];
}

// ── Traffic Performance ──────────────────────────────────────────────────────

$trafficData = null;
$trafficPeriod = $_GET['tp'] ?? 'days';
if ($isAuthenticated && $tab === 'traffic') {
    if ($trafficPeriod === 'months') {
        $trafficSince = vjt_utc_since_seconds(365 * 86400);
    } elseif ($trafficPeriod === 'years') {
        $trafficSince = vjt_utc_since_seconds(5 * 365 * 86400);
    } else {
        $trafficSince = vjt_utc_since_seconds(30 * 86400);
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
$visCountry   = $_GET['country'] ?? '';
$visProduct   = $_GET['product'] ?? '';
$visSortBy    = $_GET['sort'] ?? 'last_seen_at';
$visSortOrder = $_GET['order'] ?? 'desc';
$visTotal     = 0;

if ($isAuthenticated && $tab === 'visitors') {
    $result = vjt_get_visitors_list([
        'search' => $visSearch,
        'device' => $visDevice,
        'source' => $visSource,
        'sessions_min' => $visSessionsMin,
        'sessions_max' => $visSessionsMax,
        'submissions_min' => $visSubmissionsMin,
        'submissions_max' => $visSubmissionsMax,
        'session_time_min' => $visSessionTimeMin,
        'country' => $visCountry,
        'product_sku' => $visProduct,
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

if ($isAuthenticated && $tab === 'journey' && !empty($_GET['visitor_id'])) {
    $journeyData = vjt_get_journey($_GET['visitor_id']);
}

// ── Countries & Products ──────────────────────────────────────────────────────

$countries = [];
if ($isAuthenticated && $tab === 'countries') {
    $countries = vjt_get_countries();
}

$products = [];
$prodDateFrom = $_GET['prod_date_from'] ?? '';
$prodDateTo   = $_GET['prod_date_to'] ?? '';
if ($isAuthenticated && $tab === 'products') {
    $products = vjt_get_products($prodDateFrom, $prodDateTo);
}

// ── Country helpers ──────────────────────────────────────────────────────────

// Country display uses the canonical map in vjt-helpers.php (single source of
// truth shared with search/sort), so the alpha-3 shown here is always searchable.
function getCountryName($code) {
    return vjt_country_alpha3($code);
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

// Safe href for legacy or poisoned analytics rows. New ingest also rejects
// non-HTTP(S) URLs, but output encoding must remain independently safe.
function safeHref($url) {
    $url = is_scalar($url) ? trim((string)$url) : '';
    // Contact Core deliberately stores only a same-site root-relative path.
    // Keep it clickable without accepting protocol-relative external URLs.
    if ($url !== '' && preg_match('#^/(?!/)#', $url)
        && !preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
    $safe = vjt_safe_http_url($url);
    return $safe !== '' ? htmlspecialchars($safe, ENT_QUOTES, 'UTF-8') : '#';
}

function safeStatus($status) {
    $status = strtolower((string)$status);
    return in_array($status, ['success', 'attempt', 'error', 'intent', 'abandoned'], true) ? $status : 'unknown';
}

function jsArg($value) {
    return htmlspecialchars(
        json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Source badge color
function sourceBadge($source) {
    $map = [
        'ads' => ['#e74c3c', '#fdeaea', 'Ads'],
        'ai' => ['#8e44ad', '#f3e5f5', 'AI'],
        'search' => ['#27ae60', '#d4edda', 'Search'],
        'social' => ['#3498db', '#d6eaf8', 'Social'],
        'direct' => ['#95a5a6', '#eaeded', 'Direct'],
        'internal' => ['#7f8c8d', '#f0f3f4', 'Internal'],
        'other' => ['#f39c12', '#fef9e7', 'Other'],
    ];
    $info = $map[$source] ?? ['#95a5a6', '#eaeded', htmlspecialchars(ucfirst((string)$source), ENT_QUOTES, 'UTF-8')];
    return "<span style='background:{$info[1]};color:{$info[0]};padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;'>{$info[2]}</span>";
}

$leadTotalPages = ceil($leadTotal / $leadPerPage);
$contactTotalPages = ceil($contactTotal / $contactPerPage);
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

// Windowed pagination with first/prev/next/last + a go-to-page box.
// Replaces the old min($totalPages, 20) cap so all pages stay reachable.
// $baseParams = query params to preserve (tab, filters, sort…), minus the page param.
function vjtPagination($pageParam, $currentPage, $totalPages, $baseParams) {
    if ($totalPages <= 1) return '';
    $currentPage = max(1, min((int)$currentPage, $totalPages));
    $mk = function ($p) use ($pageParam, $baseParams) {
        $params = $baseParams;
        $params[$pageParam] = $p;
        return '?' . htmlspecialchars(http_build_query($params));
    };
    $html = '<div class="pagination">';
    if ($currentPage > 1) {
        $html .= '<a href="' . $mk(1) . '">« First</a>';
        $html .= '<a href="' . $mk($currentPage - 1) . '">‹ Prev</a>';
    }
    $window = 3;
    $start = max(1, $currentPage - $window);
    $end   = min($totalPages, $currentPage + $window);
    if ($start > 1) $html .= '<span>…</span>';
    for ($i = $start; $i <= $end; $i++) {
        $html .= ($i === $currentPage)
            ? '<span class="current">' . $i . '</span>'
            : '<a href="' . $mk($i) . '">' . $i . '</a>';
    }
    if ($end < $totalPages) $html .= '<span>…</span>';
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $mk($currentPage + 1) . '">Next ›</a>';
        $html .= '<a href="' . $mk($totalPages) . '">Last »</a>';
    }
    // Go-to-page form (GET; hidden inputs keep current filters/sort)
    $hidden = '';
    foreach ($baseParams as $k => $v) {
        $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
    }
    $html .= '<form method="GET" style="display:inline-flex;gap:4px;align-items:center;margin-left:8px;">'
        . $hidden
        . '<input type="number" name="' . htmlspecialchars($pageParam) . '" min="1" max="' . $totalPages . '" value="' . $currentPage . '" '
        . 'style="width:64px;padding:5px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;" title="Go to page" aria-label="Go to page"> '
        . '<span style="color:#888;font-size:12px;">/ ' . number_format($totalPages) . '</span> '
        . '<button type="submit" class="btn btn-primary btn-small">Go</button>'
        . '</form>';
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VJT - KSSMI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; max-width: 100%; overflow-x: hidden; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 14px 20px; color: #333; overflow-y: scroll; }
        .container { width: 100%; max-width: 1500px; min-width: 0; margin: 0 auto; }
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
        .stat-card .core-leads { flex: 1; display: flex; align-items: center; gap: 20px; color: #5D4E37; }
        .stat-card .core-leads span { display: inline-flex; align-items: baseline; gap: 6px; font-size: 12px; color: #888; }
        .stat-card .core-leads strong { font-size: 14px; font-weight: 600; color: #5D4E37; }

        /* Panels */
        .panel { min-width: 0; max-width: 100%; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 14px; }
        .panel-header { min-width: 0; padding: 12px 16px; border-bottom: 1px solid #eee; font-weight: 600; color: #5D4E37; font-size: 13px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .panel-header > * { min-width: 0; }
        .panel-body { min-width: 0; max-width: 100%; padding: 16px; }

        /* Tables */
        .table-wrapper { width: 100%; max-width: 100%; min-width: 0; overflow-x: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
        .table-wrapper table { min-width: 640px; }
        .table-wrapper.table-compact table { min-width: 420px; }
        .table-wrapper.table-wide table { min-width: 720px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
        tr:hover { background: #fafafa; }
        .mono { font-family: monospace; font-size: 11px; }

        /* Status badges */
        .status { padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-intent { background: #d6eaf8; color: #1f618d; }
        .status-attempt { background: #fff3cd; color: #856404; }
        .status-error { background: #f8d7da; color: #721c24; }
        .status-abandoned { background: #eaeded; color: #7f8c8d; }
        .status-unknown { background: #eaeded; color: #555; }

        /* Filters */
        .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .filters select, .filters input { padding: 5px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
        .filters input[type="date"] { width: 140px; }
        .filter-disclosure { width: 100%; }
        .filter-disclosure > summary { display: none; }
        .keyword-filters { margin-bottom: 16px; }

        /* Pagination */
        .pagination { display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; max-width: 100%; margin-top: 15px; }
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
        .bar-chart-scroll { max-width: 100%; overflow-x: auto; }
        .bar-chart-dual { min-width: 720px; }
        .bar-pair { width: 100%; height: 112px; display: flex; justify-content: center; align-items: flex-end; gap: 2px; }
        .bar-series { flex: 1; max-width: 18px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; }
        .bar-series .bar { max-width: 18px; }
        .bar-series .bar-value { font-size: 8px; white-space: nowrap; }
        .bar-core { background: #B8A58A; }
        .bar-core:hover { background: #8B7355; }

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
        .gsc-status-grid { display: grid; grid-template-columns: minmax(180px, 240px) 1fr; gap: 8px 18px; margin: 14px 0; }
        .gsc-status-label { color: #666; font-size: 12px; font-weight: 600; }
        .gsc-status-value { color: #333; font-size: 12px; word-break: break-word; }
        .status-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .status-ok { color: #20733a; background: #d4edda; }
        .status-bad { color: #b52b27; background: #fdeaea; }
        .gsc-action { margin: 12px 0 15px; padding: 12px 14px; color: #6f4d00; background: #fff7d6; border-left: 3px solid #d4a72c; border-radius: 4px; font-size: 12px; line-height: 1.55; }

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
            .tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .tab { padding: 10px 12px; font-size: 12px; white-space: nowrap; }
            .filter-disclosure { margin: 0 0 12px; }
            .filter-disclosure > summary {
                display: flex;
                width: 100%;
                min-height: 42px;
                box-sizing: border-box;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 9px 12px;
                color: #5D4E37;
                background: #f8f7f5;
                border: 1px solid #ddd6ce;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                list-style: none;
                user-select: none;
            }
            .filter-disclosure > summary::-webkit-details-marker { display: none; }
            .filter-disclosure > summary::after {
                content: '\25BC';
                flex: 0 0 auto;
                color: #8B7355;
                font-size: 10px;
                transition: transform 0.15s ease;
            }
            .filter-disclosure[open] > summary::after { transform: rotate(180deg); }
            .filter-summary-hint { margin-left: auto; color: #888; font-size: 10px; font-weight: 400; }
            .filter-summary-hint::before { content: 'Tap to expand'; }
            .filter-disclosure[open] .filter-summary-hint::before { content: 'Tap to collapse'; }
            .filters {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                width: 100%;
                box-sizing: border-box;
                gap: 8px;
                align-items: stretch;
                margin: 8px 0 0;
                padding: 10px;
                background: #fcfbfa;
                border: 1px solid #eee8e2;
                border-radius: 6px;
            }
            .filter-disclosure:not([open]) > .filters { display: none; }
            .filters input:not([type="hidden"]),
            .filters select,
            .filters .btn,
            .filters .country-badge {
                width: 100% !important;
                min-width: 0;
                max-width: none;
                min-height: 36px;
                box-sizing: border-box;
                margin: 0 !important;
            }
            .filters .btn,
            .filters .country-badge {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .keyword-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .keyword-filters .trend-tab {
                display: flex;
                width: 100%;
                min-height: 36px;
                box-sizing: border-box;
                align-items: center;
                justify-content: center;
                margin: 0;
                text-align: center;
            }
            .header { flex-wrap: nowrap; gap: 6px; }
            .header-left h1 { font-size: 14px; }
            .header-right { display: flex; gap: 4px; }
            .header-right .btn { padding: 4px 8px; font-size: 11px; }
            /* Overview grid: stack referrers + source/device vertically */
            .overview-grid { min-width: 0; grid-template-columns: minmax(0, 1fr) !important; }
            .overview-grid > * { min-width: 0; max-width: 100%; }
            .journey-grid { grid-template-columns: 1fr; }
            .setting-row { flex-direction: column; align-items: flex-start; gap: 7px; }
            .setting-row label { min-width: 0; }
            .gsc-status-grid { grid-template-columns: minmax(0, 1fr); gap: 4px; }
            .gsc-status-value { margin-bottom: 8px; }
            .panel-header { flex-wrap: wrap; align-items: flex-start; }
            /* Tables: tighter padding on mobile */
            th, td { padding: 6px 8px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            .stats { grid-template-columns: 1fr; }
            .stat-card { padding: 12px 14px; }
            .stat-card .value { font-size: 22px; }
            th, td { padding: 5px 6px; font-size: 11px; }
            .panel-header { font-size: 12px; padding: 10px 12px; }
            .panel-body { padding: 12px; }
            .container { padding: 0 4px; }
            body { padding: 8px 4px; }
            .filters input:not([type="hidden"]), .filters select { font-size: 12px; padding: 7px 9px; }
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
                <h2>VJT</h2>
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
                    <h1>VJT</h1>
                </div>
                <div class="header-right">
                    <a href="/email-logs.php" class="btn btn-secondary">Email Logs</a>
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <?php if ($message): ?>
                <p class="<?php echo $messageClass === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

                <!-- Global CSRF token for all tabs (used by JS delete functions) -->
                <input type="hidden" id="vjt_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                <!-- Tabs -->
                <div class="tabs">
                    <a href="?tab=overview" class="tab <?php echo $tab === 'overview' ? 'active' : ''; ?>">Overview</a>
                    <a href="?tab=contacts" class="tab <?php echo $tab === 'contacts' ? 'active' : ''; ?>">Core</a>
                    <a href="?tab=submissions" class="tab <?php echo $tab === 'submissions' ? 'active' : ''; ?>">Leads</a>
                    <a href="?tab=traffic" class="tab <?php echo $tab === 'traffic' ? 'active' : ''; ?>">Traffic</a>
                    <a href="?tab=visitors" class="tab <?php echo $tab === 'visitors' ? 'active' : ''; ?>">Visitors</a>
                    <a href="?tab=countries" class="tab <?php echo $tab === 'countries' ? 'active' : ''; ?>">Countries</a>
                    <a href="?tab=products" class="tab <?php echo $tab === 'products' ? 'active' : ''; ?>">Products</a>
                    <?php if ($tab === 'journey'): ?>
                        <a href="?tab=journey&visitor_id=<?php echo urlencode($_GET['visitor_id'] ?? ''); ?>" class="tab active">Journey Detail</a>
                    <?php endif; ?>
                    <a href="?tab=gsc" class="tab <?php echo $tab === 'gsc' ? 'active' : ''; ?>">Keywords</a>
                    <a href="?tab=settings" class="tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Settings</a>
                </div>

                <?php if ($tab === 'overview' && $overview): ?>
                    <!-- Overview -->
                    <div class="stats">
                        <div class="stat-card">
                            <h3>Visitors</h3>
                            <div class="value"><?php echo number_format($overview['totalVisitors']); ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Sessions</h3>
                            <div class="value"><?php echo number_format($overview['totalSessions']); ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="core-leads">
                                <span title="Core contacts in selected period">C <strong><?php echo number_format($overview['totalCore']); ?></strong></span>
                                <span title="Journey-attributed leads in selected period">L <strong><?php echo number_format($overview['totalLeads']); ?></strong></span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <h3>Lead Rate</h3>
                            <div class="value"><?php echo htmlspecialchars((string)$overview['conversionRate']); ?>%</div>
                        </div>
                        <div class="stat-card">
                            <h3>Avg Active Time</h3>
                            <div class="value"><?php echo fmtDuration((int)$overview['avgDuration']); ?></div>
                        </div>
                    </div>

                    <!-- Canonical Lead Trend -->
                    <?php
                    $trendData = $overview['trend'] ?? [];
                    $coreTrendData = $overview['coreTrend'] ?? [];
                    if ($trendPeriod === 'months') {
                        $trendData = $overview['trendMonthly'] ?? [];
                        $coreTrendData = $overview['coreTrendMonthly'] ?? [];
                    } elseif ($trendPeriod === 'years') {
                        $trendData = $overview['trendYearly'] ?? [];
                        $coreTrendData = $overview['coreTrendYearly'] ?? [];
                    }
                    ?>
                    <?php if (!empty($trendData)): ?>
                    <div class="panel">
                        <div class="panel-header" style="justify-content:flex-end;">
                            <div style="display:flex;gap:4px;">
                                <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="bar-chart-scroll">
                            <div class="bar-chart bar-chart-dual">
                                <?php
                                $maxCnt = max(max($trendData), max($coreTrendData ?: [0]), 1);
                                foreach ($trendData as $key => $leadCnt):
                                    $coreCnt = (int)($coreTrendData[$key] ?? 0);
                                    $leadH = round(($leadCnt / $maxCnt) * 100);
                                    $coreH = round(($coreCnt / $maxCnt) * 100);
                                    if ($trendPeriod === 'months') {
                                        $label = date('M', strtotime($key . '-01'));
                                    } elseif ($trendPeriod === 'years') {
                                        $label = $key;
                                    } else {
                                        $label = substr($key, 5); // MM-DD
                                    }
                                ?>
                                    <div class="bar-col">
                                        <div class="bar-pair">
                                            <div class="bar-series">
                                                <div class="bar-value">L<?php echo number_format($leadCnt); ?></div>
                                                <div class="bar" style="height:<?php echo max(2, $leadH); ?>px;" title="<?php echo htmlspecialchars($key); ?> · L <?php echo number_format($leadCnt); ?>"></div>
                                            </div>
                                            <div class="bar-series">
                                                <div class="bar-value">C<?php echo number_format($coreCnt); ?></div>
                                                <div class="bar bar-core" style="height:<?php echo max(2, $coreH); ?>px;" title="<?php echo htmlspecialchars($key); ?> · C <?php echo number_format($coreCnt); ?>"></div>
                                            </div>
                                        </div>
                                        <div class="bar-label"><?php echo htmlspecialchars($label); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="overview-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <!-- Top Sources -->
                        <div class="panel">
                            <div class="panel-header" style="justify-content:flex-end;">
                                <div style="display:flex;gap:4px;">
                                    <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                    <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                    <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                                </div>
                            </div>
                            <div class="panel-body" style="padding:0;">
                                <?php if (empty($overview['topReferrers'])): ?>
                                    <div class="empty" style="padding:30px;"><p>No source data yet</p></div>
                                <?php else: ?>
                                    <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Attributed Source</th><th style="text-align:right;">Sessions</th><th style="text-align:right;">Visitors</th><th style="text-align:right;">Leads</th></tr></thead>
                                        <tbody>
                                            <?php foreach (($overview['topReferrerStats'] ?? []) as $label => $stats):
                                                $display = $label ?: 'Direct';
                                            ?>
                                                <tr>
                                                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($display); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($stats['sessions']); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($stats['visitors']); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($stats['leads']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Source & Device -->
                        <div style="display:flex;flex-direction:column;gap:20px;">
                            <div class="panel">
                                <div class="panel-header" style="justify-content:flex-end;">
                                    <div style="display:flex;gap:4px;">
                                        <a href="?tab=overview&trend=days" class="trend-tab <?php echo $trendPeriod === 'days' ? 'trend-tab-active' : ''; ?>">30 Days</a>
                                        <a href="?tab=overview&trend=months" class="trend-tab <?php echo $trendPeriod === 'months' ? 'trend-tab-active' : ''; ?>">12 Months</a>
                                        <a href="?tab=overview&trend=years" class="trend-tab <?php echo $trendPeriod === 'years' ? 'trend-tab-active' : ''; ?>">Years</a>
                                    </div>
                                </div>
                                <div class="panel-body" style="padding:0;">
                                    <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Source</th><th style="text-align:right;">Sessions</th><th style="text-align:right;">Visitors</th><th style="text-align:right;">Leads</th></tr></thead>
                                        <tbody>
                                            <?php foreach (['search', 'social', 'ai', 'direct', 'internal', 'ads', 'other'] as $src):
                                                $cnt = $overview['sourceCounts'][$src] ?? 0;
                                            ?>
                                                <tr>
                                                    <td><?php echo sourceBadge($src); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($cnt); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($overview['sourceVisitorCounts'][$src] ?? 0); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($overview['sourceLeadCounts'][$src] ?? 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    </div>
                                </div>
                            </div>

                            <div class="panel">
                                <div class="panel-header" style="justify-content:flex-end;">
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
                                        <div class="table-wrapper table-compact">
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
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top:20px;">
                        <div class="panel-body" style="padding:0;">
                            <?php if (empty($aiReferrals)): ?>
                                <div class="empty" style="padding:24px;"><p>No verified AI referrals yet</p></div>
                            <?php else: ?>
                                <div class="table-wrapper table-wide">
                                <table><thead><tr><th>Platform</th><th style="text-align:right;">Sessions</th><th style="text-align:right;">Visitors</th><th style="text-align:right;">Leads</th><th style="text-align:right;">Landing Pages</th><th style="text-align:right;">Lead Rate</th></tr></thead><tbody>
                                <?php foreach ($aiReferrals as $row): ?><tr><td><?php echo htmlspecialchars(ucfirst($row['platform'])); ?></td><td style="text-align:right;"><?php echo number_format($row['sessions']); ?></td><td style="text-align:right;"><?php echo number_format($row['visitors']); ?></td><td style="text-align:right;"><?php echo number_format($row['leads']); ?></td><td style="text-align:right;"><?php echo number_format($row['pages']); ?></td><td style="text-align:right;"><?php echo $row['conversion_rate']; ?>%</td></tr><?php endforeach; ?>
                                </tbody></table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'contacts'): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <span>Core Events (<?php echo number_format($contactTotal); ?>)</span>
                            <div>
                                <a href="?tab=contacts&amp;export_contacts_csv=1<?php echo $contactChannel ? '&amp;channel=' . urlencode($contactChannel) : ''; ?><?php echo $contactStatus ? '&amp;status=' . urlencode($contactStatus) : ''; ?><?php echo $contactDateFrom ? '&amp;date_from=' . urlencode($contactDateFrom) : ''; ?><?php echo $contactDateTo ? '&amp;date_to=' . urlencode($contactDateTo) : ''; ?>" class="btn btn-success btn-small">Export CSV (<?php echo number_format($contactTotal); ?> rows)</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <details class="filter-disclosure" open>
                                <summary>Filters <span class="filter-summary-hint"></span></summary>
                                <form class="filters" method="GET">
                                    <input type="hidden" name="tab" value="contacts">
                                    <select name="channel">
                                        <option value="">All Channels</option>
                                        <option value="whatsapp" <?php echo $contactChannel === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp</option>
                                        <option value="mailto" <?php echo $contactChannel === 'mailto' ? 'selected' : ''; ?>>Mailto</option>
                                        <option value="inquiry" <?php echo $contactChannel === 'inquiry' ? 'selected' : ''; ?>>Inquiry</option>
                                    </select>
                                    <select name="status">
                                        <option value="">All Statuses</option>
                                        <option value="intent" <?php echo $contactStatus === 'intent' ? 'selected' : ''; ?>>Intent</option>
                                        <option value="success" <?php echo $contactStatus === 'success' ? 'selected' : ''; ?>>Confirmed Success</option>
                                        <option value="error" <?php echo $contactStatus === 'error' ? 'selected' : ''; ?>>Error</option>
                                    </select>
                                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($contactDateFrom); ?>">
                                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($contactDateTo); ?>">
                                    <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                    <?php if ($contactChannel || $contactStatus || $contactDateFrom || $contactDateTo): ?>
                                        <a href="?tab=contacts" class="btn btn-secondary btn-small">Clear</a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-danger btn-small" onclick="vjtDeleteContactEvents()">Delete</button>
                                </form>
                            </details>

                            <?php if (empty($contactEvents)): ?>
                                <div class="empty"><div class="empty-icon">☎</div><p>No Core events found</p></div>
                            <?php else: ?>
                                <div class="table-wrapper table-wide">
                                    <table>
                                        <thead><tr>
                                            <th style="width:30px;"><input type="checkbox" id="contactSelectAll" onclick="vjtToggleContactEvents()"></th>
                                            <th>Time (Beijing)</th><th>Channel / Event</th><th>Page / Placement</th>
                                            <th>Product / Language</th><th>Attribution</th><th>Status</th><th>Retention</th><th>Actions</th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ($contactEvents as $event): ?>
                                            <tr>
                                                <td><input type="checkbox" class="contact-row-cb" value="<?php echo (int)$event['id']; ?>"></td>
                                                <td style="white-space:nowrap;font-size:12px;"><?php echo htmlspecialchars(vjt_format_for_admin($event['occurred_at'] ?? '')); ?></td>
                                                <td><strong><?php echo htmlspecialchars(ucfirst($event['channel'] ?? '')); ?></strong><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars(str_replace('_', ' ', $event['event_type'] ?? '')); ?></span></td>
                                                <td><span class="mono"><?php echo htmlspecialchars($event['page_path'] ?: '-'); ?></span><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars($event['placement'] ?: '-'); ?></span></td>
                                                <td><?php echo htmlspecialchars($event['product_sku'] ?: '-'); ?><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars($event['site_language'] ?: '-'); ?></span></td>
                                                <td>
                                                    <?php if (!empty($event['vjt_visitor_id'])): ?>
                                                        <a class="link" href="?tab=journey&amp;visitor_id=<?php echo urlencode($event['vjt_visitor_id']); ?>">Consented Journey</a>
                                                        <?php if ((int)($event['journey_step'] ?? 0) > 0): ?>
                                                            <br><span style="color:#888;font-size:11px;">Step <?php echo (int)$event['journey_step']; ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color:#888;">Unattributed / no analytics linkage</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php $contactDisplayStatus = safeStatus($event['status'] ?? ''); ?>
                                                <td><span class="status status-<?php echo $contactDisplayStatus; ?>"><?php echo htmlspecialchars(ucfirst($contactDisplayStatus)); ?></span></td>
                                                <td style="font-size:11px;"><?php echo htmlspecialchars($event['retention_class'] ?? ''); ?></td>
                                                <td style="white-space:nowrap;">
                                                    <?php if (!empty($event['vjt_visitor_id'])): ?>
                                                        <a href="?tab=journey&amp;visitor_id=<?php echo urlencode($event['vjt_visitor_id']); ?>" class="btn btn-primary btn-small">Check</a>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-secondary btn-small" disabled aria-disabled="true" title="No consented Journey is linked to this Core event" style="opacity:.45;cursor:not-allowed;">Check</button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-danger btn-small" onclick="vjtDeleteContactEvent(<?php echo (int)$event['id']; ?>)" title="Delete this Core event">Del</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php
                                    $contactBase = ['tab' => 'contacts'];
                                    if ($contactChannel) $contactBase['channel'] = $contactChannel;
                                    if ($contactStatus) $contactBase['status'] = $contactStatus;
                                    if ($contactDateFrom) $contactBase['date_from'] = $contactDateFrom;
                                    if ($contactDateTo) $contactBase['date_to'] = $contactDateTo;
                                    echo vjtPagination('cp', $contactPage, $contactTotalPages, $contactBase);
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'submissions'): ?>
                    <!-- Canonical Contact Core Leads -->
                    <div class="panel">
                        <div class="panel-header">
                            Leads (canonical rows: <?php echo number_format($leadTotal); ?>)
                            <div>
                                <a href="?tab=submissions&export_csv=1&status=<?php echo urlencode($leadStatus); ?><?php echo $leadChannel ? '&channel=' . urlencode($leadChannel) : ''; ?><?php echo $leadDateFrom ? '&date_from=' . urlencode($leadDateFrom) : ''; ?><?php echo $leadDateTo ? '&date_to=' . urlencode($leadDateTo) : ''; ?>" class="btn btn-success btn-small">Export CSV (<?php echo number_format($leadTotal); ?> rows)</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div style="margin-bottom:12px;color:#666;font-size:12px;line-height:1.5;">
                                Contact Core is the canonical Lead source. Linked events are grouped by Visitor; unlinked events remain separate because no Analytics identity exists.
                            </div>
                            <details class="filter-disclosure" open>
                                <summary>Filters <span class="filter-summary-hint"></span></summary>
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="submissions">
                                <input type="hidden" name="csrf_token" id="vjt_csrf" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <select name="status">
                                    <option value="contact" <?php echo $leadStatus === 'contact' ? 'selected' : ''; ?>>Contacts (Success + Intent)</option>
                                    <option value="">All Statuses</option>
                                    <option value="success" <?php echo $leadStatus === 'success' ? 'selected' : ''; ?>>Confirmed Inquiry</option>
                                    <option value="intent" <?php echo $leadStatus === 'intent' ? 'selected' : ''; ?>>Contact Intent</option>
                                    <option value="error" <?php echo $leadStatus === 'error' ? 'selected' : ''; ?>>Error</option>
                                </select>
                                <select name="channel">
                                    <option value="">All Channels</option>
                                    <option value="inquiry" <?php echo $leadChannel === 'inquiry' ? 'selected' : ''; ?>>Inquiry Form</option>
                                    <option value="whatsapp" <?php echo $leadChannel === 'whatsapp' ? 'selected' : ''; ?>>WhatsApp Intent</option>
                                    <option value="mailto" <?php echo $leadChannel === 'mailto' ? 'selected' : ''; ?>>Mailto Intent</option>
                                </select>
                                <input type="date" name="date_from" value="<?php echo htmlspecialchars($leadDateFrom); ?>" placeholder="From">
                                <input type="date" name="date_to" value="<?php echo htmlspecialchars($leadDateTo); ?>" placeholder="To">
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($leadStatus !== 'contact' || $leadChannel || $leadDateFrom || $leadDateTo): ?>
                                    <a href="?tab=submissions" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-danger btn-small" onclick="vjtBulkDelete()" style="margin-left:12px;">Delete</button>
                            </form>
                            </details>

                            <?php if (empty($leads)): ?>
                                <div class="empty"><div class="empty-icon">📋</div><p>No Contact Core Leads found</p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th style="width:30px;"><input type="checkbox" id="vjtSelectAll" onclick="vjtToggleAll()"></th>
                                                <th>Last Contact (Beijing)</th>
                                                <th>Attribution</th>
                                                <th>Channels</th>
                                                <th style="text-align:center;">Events</th>
                                                <th>Page / Placement</th>
                                                <th>Latest Event</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($leads as $lead): ?>
                                                <tr>
                                                    <td><input type="checkbox" class="vjt-row-cb" value="<?php echo htmlspecialchars($lead['lead_key']); ?>"></td>
                                                    <td style="white-space:nowrap;font-size:12px;">
                                                        <?php echo htmlspecialchars(vjt_format_for_admin($lead['last_contact_at'] ?? $lead['occurred_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($lead['vjt_visitor_id'])): ?>
                                                            <a href="?tab=journey&visitor_id=<?php echo urlencode($lead['vjt_visitor_id']); ?>" class="link mono"><?php echo htmlspecialchars(str_replace('vjtv_', '', $lead['vjt_visitor_id'])); ?></a>
                                                            <br><span style="color:#888;font-size:11px;">Consented Journey</span>
                                                        <?php else: ?>
                                                            <span style="color:#888;">Unattributed</span>
                                                            <br><span style="color:#888;font-size:11px;">No analytics linkage</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($lead['channels'] ?? $lead['channel']); ?></td>
                                                    <td style="text-align:center;"><?php echo number_format((int)($lead['event_count'] ?? 1)); ?></td>
                                                    <td><span class="mono"><?php echo htmlspecialchars($lead['page_path'] ?: '-'); ?></span><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars($lead['placement'] ?: '-'); ?><?php echo (int)($lead['journey_step'] ?? 0) > 0 ? ' · Step ' . (int)$lead['journey_step'] : ''; ?></span></td>
                                                    <td><?php echo htmlspecialchars(str_replace('_', ' ', $lead['event_type'] ?? '-')); ?><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars($lead['product_sku'] ?: ($lead['site_language'] ?: '-')); ?></span></td>
                                                    <?php $displayStatus = safeStatus($lead['display_status'] ?? ''); ?>
                                                    <td><span class="status status-<?php echo $displayStatus; ?>"><?php echo htmlspecialchars(ucfirst($displayStatus)); ?></span></td>
                                                    <td style="white-space:nowrap;">
                                                        <?php if (!empty($lead['vjt_visitor_id'])): ?>
                                                            <a href="?tab=journey&visitor_id=<?php echo urlencode($lead['vjt_visitor_id']); ?>" class="btn btn-primary btn-small">Check</a>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-secondary btn-small" disabled aria-disabled="true" title="No consented Journey is linked" style="opacity:.45;cursor:not-allowed;">Check</button>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-danger btn-small" onclick="vjtDeleteLead(<?php echo jsArg($lead['lead_key']); ?>)" title="Delete this canonical Contact Core Lead event set">Del</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php
                                    $leadBase = ['tab' => 'submissions'];
                                    if ($leadStatus)   $leadBase['status'] = $leadStatus;
                                    if ($leadChannel)  $leadBase['channel'] = $leadChannel;
                                    if ($leadDateFrom) $leadBase['date_from'] = $leadDateFrom;
                                    if ($leadDateTo)   $leadBase['date_to'] = $leadDateTo;
                                    echo vjtPagination('sp', $leadPage, $leadTotalPages, $leadBase);
                                ?>
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
                            <h3>Avg Active Time</h3>
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
                                        <th style="text-align:center;width:90px;">Avg Active</th>
                                        <th style="text-align:center;width:80px;">Scroll</th>
                                                <th style="text-align:center;width:80px;">Leads</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $rank = 1; foreach ($trafficData['topPages'] as $page): ?>
                                        <tr>
                                            <td style="color:#999;"><?php echo $rank++; ?></td>
                                            <td class="url-cell">
                                                <a href="<?php echo safeHref($page['url']); ?>" target="_blank" rel="noopener noreferrer">
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
                        <div class="panel-header">Visitors (<?php echo number_format($visTotal); ?>)<a href="?<?php echo htmlspecialchars(http_build_query([
                            'tab'=>'visitors', 'export_visitors_csv'=>1, 'search'=>$visSearch, 'source'=>$visSource, 'device'=>$visDevice,
                            'sessions_min'=>$visSessionsMin, 'sessions_max'=>$visSessionsMax,
                            'submissions_min'=>$visSubmissionsMin, 'submissions_max'=>$visSubmissionsMax,
                            'session_time_min'=>$visSessionTimeMin, 'country'=>$visCountry, 'product'=>$visProduct,
                            'date_from'=>$_GET['date_from'] ?? '', 'date_to'=>$_GET['date_to'] ?? '',
                            'sort'=>$visSortBy, 'order'=>$visSortOrder,
                        ])); ?>" class="btn btn-success btn-small">Export CSV (filtered)</a></div>
                        <div class="panel-body">
                            <details class="filter-disclosure" open>
                                <summary>Filters <span class="filter-summary-hint"></span></summary>
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="visitors">
                                <?php if ($visCountry !== ''): ?>
                                    <input type="hidden" name="country" value="<?php echo htmlspecialchars($visCountry); ?>">
                                <?php endif; ?>
                                <?php if ($visProduct !== ''): ?>
                                    <input type="hidden" name="product" value="<?php echo htmlspecialchars($visProduct); ?>">
                                <?php endif; ?>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($visSearch); ?>" placeholder="Search ID, IP, country, browser..." style="width:250px;">
                                <button type="submit" class="btn btn-primary btn-small">Search</button>
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
                                    <option value="internal" <?php echo $visSource === 'internal' ? 'selected' : ''; ?>>Internal</option>
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
                                    <option value="">Any Lead status</option>
                                    <option value="1" <?php echo $visSubmissionsMin === '1' ? 'selected' : ''; ?>>Has Lead</option>
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
                                <?php if ($visCountry !== ''): ?>
                                    <span class="country-badge" style="font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                                        Country: <strong><?php echo htmlspecialchars(getCountryName($visCountry)); ?></strong>
                                        <a href="?tab=visitors" title="Remove country filter" style="color:#e74c3c;text-decoration:none;font-weight:700;">✕</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($visProduct !== ''): ?>
                                    <span class="country-badge" style="font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                                        Product: <strong><?php echo htmlspecialchars(strtoupper($visProduct)); ?></strong>
                                        <a href="?tab=visitors" title="Remove product filter" style="color:#e74c3c;text-decoration:none;font-weight:700;">✕</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($visSearch || $visDevice || $visSource || $visCountry !== '' || $visProduct !== '' || $visSessionsMin !== '' || $visSessionsMax !== '' || $visSubmissionsMin !== '' || $visSubmissionsMax !== '' || $visSessionTimeMin !== ''): ?>
                                    <a href="?tab=visitors" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                            </form>
                            </details>

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
                                                <th style="text-align:center;"><?php echo sortLink('submissions', 'Leads', $visSortBy, $visSortOrder); ?></th>
                                                <th><?php echo sortLink('source', 'Source', $visSortBy, $visSortOrder); ?></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($visitors as $v): ?>
                                                <tr>
                                                    <td class="mono"><a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>" class="link"><?php echo htmlspecialchars(str_replace('vjtv_', '', $v['visitor_id'])); ?></a></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars(vjt_format_for_admin($v['first_seen_at'])); ?></td>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars(vjt_format_for_admin($v['last_seen_at'])); ?></td>
                                                    <td style="font-size:12px;"><?php echo $v['session_time'] > 0 ? fmtDuration($v['session_time']) : '-'; ?></td>
                                                    <td><?php echo $v['country'] ? htmlspecialchars(getCountryName($v['country'])) : '-'; ?></td>
                                                    <td><?php echo htmlspecialchars(ucfirst($v['device_type'] ?: '-')); ?></td>
                                                    <td><?php echo htmlspecialchars($v['browser'] ?: '-'); ?></td>
                                                    <td style="text-align:center;"><?php echo $v['sessions']; ?></td>
                                                    <td style="text-align:center;"><?php echo $v['submissions']; ?></td>
                                                    <td><?php echo sourceBadge($v['source']); ?></td>
                                                    <td style="white-space:nowrap;">
                                                        <a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>" class="btn btn-primary btn-small">Check</a>
                                                        <button type="button" class="btn btn-danger btn-small" onclick="vjtDeleteVisitor(<?php echo jsArg($v['visitor_id']); ?>)" title="Delete visitor and all records">Del</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php
                                    $visBase = ['tab' => 'visitors'];
                                    if ($visSearch !== '')        $visBase['search'] = $visSearch;
                                    if ($visDevice !== '')        $visBase['device'] = $visDevice;
                                    if ($visSource !== '')        $visBase['source'] = $visSource;
                                    if ($visSessionsMin !== '')   $visBase['sessions_min'] = $visSessionsMin;
                                    if ($visSessionsMax !== '')   $visBase['sessions_max'] = $visSessionsMax;
                                    if ($visSubmissionsMin !== '') $visBase['submissions_min'] = $visSubmissionsMin;
                                    if ($visSubmissionsMax !== '') $visBase['submissions_max'] = $visSubmissionsMax;
                                    if ($visSessionTimeMin !== '') $visBase['session_time_min'] = $visSessionTimeMin;
                                    if ($visCountry !== '')       $visBase['country'] = $visCountry;
                                    if ($visProduct !== '')       $visBase['product'] = $visProduct;
                                    $visBase['sort'] = $visSortBy;
                                    $visBase['order'] = $visSortOrder;
                                    echo vjtPagination('vp', $visPage, $visTotalPages, $visBase);
                                ?>
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
                            <span><a href="?tab=journey&visitor_id=<?php echo urlencode($v['visitor_id']); ?>&export_journey_csv=1" class="btn btn-success btn-small">Export CSV</a> <a href="?tab=visitors" class="btn btn-secondary btn-small">Back to Visitors</a></span>
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
                                    <div class="journey-item"><label>First Seen</label><div class="val"><?php echo htmlspecialchars(vjt_format_for_admin($v['first_seen_at'])); ?></div></div>
                                    <div class="journey-item"><label>Last Seen</label><div class="val"><?php echo htmlspecialchars(vjt_format_for_admin($v['last_seen_at'])); ?></div></div>
                                    <div class="journey-item"><label>Session Time <span style="font-weight:400;color:#888;">(Total on site)</span></label><div class="val"><?php echo $totalSessionTime > 0 ? fmtDuration($totalSessionTime) : '-'; ?></div></div>
                                    <div class="journey-item" style="grid-column: 1 / -1;"><label>User Agent</label><div class="val" style="font-size:11px;word-break:break-all;"><?php echo htmlspecialchars($v['user_agent'] ?? 'N/A'); ?></div></div>
                                </div>
                            </div>

                            <?php
                                $realSubmissions = array_filter($journeyData['submissions'], function($s) {
                                    return ($s['form_plugin'] ?? '') !== 'generic';
                                });
                            ?>
                            <?php if (!empty($realSubmissions)): ?>
                            <div class="journey-section">
                                <h3>Legacy Analytics Enrichment (<?php echo count($realSubmissions); ?>)</h3>
                                <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Time</th><th>Form</th><th>Page</th><th>Status</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($realSubmissions as $sub): ?>
                                                <tr>
                                                    <td style="font-size:12px;"><?php echo htmlspecialchars(vjt_format_for_visitor($sub['submitted_at'], $v['timezone'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars(($sub['form_plugin'] ?? '') . ': ' . ($sub['form_name'] ?? '')); ?></td>
                                                    <td class="url-cell"><a href="<?php echo safeHref($sub['submit_page'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(fmtUrl($sub['submit_page'] ?? '-')); ?></a></td>
                                                    <?php $submissionStatus = safeStatus($sub['status'] ?? ''); ?>
                                                    <td><span class="status status-<?php echo $submissionStatus; ?>"><?php echo htmlspecialchars(ucfirst($submissionStatus)); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($journeyData['contact_events'])): ?>
                            <div class="journey-section">
                                <h3>Linked Core Events (<?php echo count($journeyData['contact_events']); ?>)</h3>
                                <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>Time</th><th>Journey Step</th><th>Channel</th><th>Event</th><th>Page / Placement</th><th>Status</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($journeyData['contact_events'] as $event): ?>
                                            <tr>
                                                <td style="font-size:12px;"><?php echo htmlspecialchars(vjt_format_for_visitor($event['occurred_at'], $v['timezone'] ?? '')); ?></td>
                                                <td style="white-space:nowrap;font-weight:600;">
                                                    <?php $coreJourneyStep = (int)($event['journey_step'] ?? 0); ?>
                                                    <?php echo $coreJourneyStep > 0 ? 'Step ' . $coreJourneyStep : 'Before / without pageview'; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars(ucfirst($event['channel'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars(str_replace('_', ' ', $event['event_type'] ?? '')); ?></td>
                                                <td><span class="mono"><?php echo htmlspecialchars($event['page_path'] ?: '-'); ?></span><br><span style="color:#888;font-size:11px;"><?php echo htmlspecialchars($event['placement'] ?: '-'); ?></span></td>
                                                <?php $linkedContactStatus = safeStatus($event['status'] ?? ''); ?>
                                                <td><span class="status status-<?php echo $linkedContactStatus; ?>"><?php echo htmlspecialchars(ucfirst($linkedContactStatus)); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($journeyData['sessions'] as $sess):
                                // The session timeline uses Contact Core as the canonical business
                                // event source. Legacy submissions remain in the clearly labelled
                                // enrichment table above and are not repeated here.
                                $timeline = [];
                                $stepSortTimes = [];
                                foreach ($journeyData['pageviews'] as $pv) {
                                    if ($pv['session_id'] !== $sess['session_id']) continue;
                                    $pv['_type'] = 'pageview';
                                    $pv['_sort'] = $pv['visited_at'];
                                    $pv['_sort_rank'] = 0;
                                    $stepSortTimes[(int)($pv['step_order'] ?? 0)] = $pv['visited_at'];
                                    $timeline[] = $pv;
                                }
                                foreach ($journeyData['contact_events'] as $event) {
                                    if (($event['vjt_session_id'] ?? '') !== $sess['session_id']) continue;
                                    $event['_type'] = 'contact';
                                    $resolvedStep = (int)($event['journey_step'] ?? 0);
                                    // A validated step is stronger ordering evidence than clocks
                                    // from two devices. Place the contact immediately after its
                                    // pageview; legacy/unmatched rows still use occurred_at.
                                    $event['_sort'] = $resolvedStep > 0 && isset($stepSortTimes[$resolvedStep])
                                        ? $stepSortTimes[$resolvedStep] : $event['occurred_at'];
                                    // A contact at the same stored second as its pageview belongs
                                    // after that pageview, so the Journey step is readable in order.
                                    $event['_sort_rank'] = 1;
                                    $timeline[] = $event;
                                }
                                usort($timeline, function($a, $b) {
                                    $timeOrder = strcmp($a['_sort'], $b['_sort']);
                                    if ($timeOrder !== 0) return $timeOrder;
                                    $rankOrder = ($a['_sort_rank'] ?? 0) <=> ($b['_sort_rank'] ?? 0);
                                    if ($rankOrder !== 0) return $rankOrder;
                                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                                });
                            ?>
                            <div class="journey-section">
                                <h3>
                                    Session: <?php echo htmlspecialchars(substr($sess['session_id'], 0, 20)); ?>...
                                    <?php echo sourceBadge(vjt_classify_source($sess)); ?>
                                    <span style="font-weight:normal;font-size:12px;color:#888;">
                                        | <?php echo htmlspecialchars(vjt_format_for_admin($sess['started_at'])); ?> - <?php echo htmlspecialchars(vjt_format_for_admin($sess['last_seen_at'])); ?>
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
                                    <?php if (!empty($sess['gsc_keywords'])): ?>
                                        <div class="journey-item" style="grid-column:1/-1;">
                                            <label>Related GSC Queries</label>
                                            <div class="val"><?php echo htmlspecialchars(implode(', ', $sess['gsc_keywords'])); ?></div>
                                            <div style="margin-top:4px;font-size:11px;color:#888;">Aggregate queries for this landing page/date; not this visitor's exact search.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($timeline)): ?>
                                <div class="timeline">
                                    <?php foreach ($timeline as $item): ?>
                                        <?php if ($item['_type'] === 'contact'): ?>
                                            <?php
                                                $timelineContactStep = (int)($item['journey_step'] ?? 0);
                                                $timelineContactStatus = safeStatus($item['status'] ?? '');
                                                $timelineContactChannel = ucfirst((string)($item['channel'] ?? 'Contact'));
                                                $timelineContactEvent = str_replace('_', ' ', (string)($item['event_type'] ?? ''));
                                                $timelineContactIcon = ($item['channel'] ?? '') === 'whatsapp' ? '💬' : ((($item['channel'] ?? '') === 'mailto') ? '✉️' : '📨');
                                            ?>
                                            <div class="timeline-item" style="background:#f0fdf4;border-left:3px solid #22c55e;">
                                                <div class="pv-url" style="color:#166534;">
                                                    <?php echo $timelineContactIcon; ?>
                                                    <?php echo $timelineContactStep > 0 ? 'At Step ' . $timelineContactStep : 'Before / without pageview'; ?> ·
                                                    <?php echo htmlspecialchars($timelineContactChannel . ': ' . $timelineContactEvent); ?>
                                                </div>
                                                <div class="pv-meta">
                                                    <?php echo htmlspecialchars(vjt_format_for_visitor($item['occurred_at'], $v['timezone'] ?? '')); ?> |
                                                    <span class="status status-<?php echo $timelineContactStatus; ?>"><?php echo htmlspecialchars(ucfirst($timelineContactStatus)); ?></span> |
                                                    Placement: <?php echo htmlspecialchars($item['placement'] ?: '-'); ?>
                                                    <?php if (!empty($item['page_path'])): ?>
                                                        <br>Contact page: <a href="<?php echo safeHref($item['page_path']); ?>" target="_blank" rel="noopener noreferrer" style="color:#8B7355;font-size:11px;"><?php echo htmlspecialchars($item['page_path']); ?></a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="timeline-item">
                                                <div class="pv-url"><?php echo htmlspecialchars($item['title'] ?: $item['url']); ?></div>
                                                <div class="pv-meta">
                                                    Step <?php echo $item['step_order']; ?> |
                                                    <?php echo htmlspecialchars(vjt_format_for_visitor($item['visited_at'], $v['timezone'] ?? '')); ?> |
                                                    Dwell: <?php echo fmtDuration($item['duration_seconds']); ?> |
                                                    Scroll: <?php echo $item['scroll_depth']; ?>%
                                                    <?php if ($item['url']): ?>
                                                        <br><a href="<?php echo safeHref($item['url']); ?>" target="_blank" rel="noopener noreferrer" style="color:#8B7355;font-size:11px;"><?php echo htmlspecialchars(fmtUrl($item['url'])); ?></a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
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
                                                <th style="text-align:center;">Leads</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $rank = 1; foreach ($countries as $c): ?>
                                                <tr>
                                                    <td style="color:#999;font-size:12px;"><?php echo $rank++; ?></td>
                                                    <td>
                                                        <a href="?tab=visitors&country=<?php echo urlencode($c['code']); ?>" class="country-badge" style="font-size:13px;text-decoration:none;color:#5D4E37;cursor:pointer;" title="View all visitors from this country"><?php echo htmlspecialchars(getCountryName($c['code'])); ?></a>
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
                            <details class="filter-disclosure" open>
                                <summary>Filters <span class="filter-summary-hint"></span></summary>
                            <form class="filters" method="GET">
                                <input type="hidden" name="tab" value="products">
                                <input type="date" name="prod_date_from" value="<?php echo htmlspecialchars($prodDateFrom); ?>" placeholder="From">
                                <input type="date" name="prod_date_to" value="<?php echo htmlspecialchars($prodDateTo); ?>" placeholder="To">
                                <button type="submit" class="btn btn-primary btn-small">Filter</button>
                                <?php if ($prodDateFrom || $prodDateTo): ?>
                                    <a href="?tab=products" class="btn btn-secondary btn-small">Clear</a>
                                <?php endif; ?>
                            </form>
                            </details>

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
                                                    <td class="url-cell"><a href="<?php echo safeHref($p['url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size:12px;"><?php echo htmlspecialchars($p['url']); ?></a></td>
                                                    <td style="text-align:center;font-weight:600;"><?php echo number_format($p['views']); ?></td>
                                                    <td style="text-align:center;">
                                                        <?php if ($p['visitors'] > 0): ?>
                                                            <a href="?tab=visitors&product=<?php echo urlencode($p['sku']); ?>" class="link" style="font-weight:600;" title="View visitors who viewed this product"><?php echo number_format($p['visitors']); ?></a>
                                                        <?php else: ?>
                                                            <?php echo number_format($p['visitors']); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'gsc'): ?>
                    <!-- Google Search Console aggregate query report -->
                    <div class="panel">
                        <div class="panel-header">
                            <span>Google Search Console Keywords</span>
                            <div>
                                <a href="?tab=gsc&amp;days=<?php echo $gscDays; ?>&amp;export_gsc_csv=1" class="btn btn-success btn-small">Export All CSV</a>
                            </div>
                        </div>
                        <div class="panel-body">
                            <details class="filter-disclosure" open>
                                <summary>Period &amp; options <span class="filter-summary-hint"></span></summary>
                            <div class="filters keyword-filters">
                                <?php foreach ([7, 28, 90] as $daysOption): ?>
                                    <a href="?tab=gsc&amp;days=<?php echo $daysOption; ?>" class="trend-tab <?php echo $gscDays === $daysOption ? 'trend-tab-active' : ''; ?>"><?php echo $daysOption; ?> days</a>
                                <?php endforeach; ?>
                                <a href="?tab=settings" class="trend-tab">Connection status</a>
                            </div>
                            </details>

                            <?php if (empty($gscDiagnostics['ready'])): ?>
                                <p class="error">GSC is not ready on this server. Open <a href="?tab=settings" class="link">Settings</a> to see the failed configuration check and test the connection.</p>
                            <?php elseif (empty($gscReport['ok'])): ?>
                                <?php if (isGscPermissionError($gscReport['error'] ?? '')): ?>
                                    <div class="gsc-action"><strong>Search Console permission required.</strong> Add <strong><?php echo htmlspecialchars($gscDiagnostics['service_account'] ?? 'the service account shown in Settings'); ?></strong> as a Restricted or Full user of the exact property <strong><?php echo htmlspecialchars($gscDiagnostics['site_url'] ?? 'sc-domain:kssmi.com'); ?></strong>, then return to Settings and run the connection test.</div>
                                <?php else: ?>
                                    <p class="error">Could not load GSC keywords: <?php echo htmlspecialchars($gscReport['error'] ?? 'Unknown Google API error.'); ?></p>
                                <?php endif; ?>
                            <?php elseif (empty($gscReport['rows'])): ?>
                                <div class="empty"><div class="empty-icon">🔎</div><p><?php echo $gscPage > 1 ? 'No keyword rows on this page.' : 'The connection works, but Google returned no query rows for this period.'; ?></p></div>
                            <?php else: ?>
                                <div class="table-wrapper">
                                    <table>
                                        <thead><tr><th>#</th><th>Query</th><th style="text-align:right;">Clicks</th><th style="text-align:right;">Impressions</th><th style="text-align:right;">CTR</th><th style="text-align:right;">Avg Position</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($gscReport['rows'] as $index => $row): ?>
                                                <tr>
                                                    <td style="color:#999;font-size:12px;"><?php echo (int)($gscReport['row_offset'] ?? 0) + $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($row['query']); ?></td>
                                                    <td style="text-align:right;font-weight:600;"><?php echo number_format($row['clicks']); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($row['impressions']); ?></td>
                                                    <td style="text-align:right;"><?php echo number_format($row['ctr'] * 100, 2); ?>%</td>
                                                    <td style="text-align:right;"><?php echo number_format($row['position'], 1); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($gscReport['ok']) && ($gscPage > 1 || !empty($gscReport['has_next']))): ?>
                                <div class="pagination">
                                    <?php if ($gscPage > 1): ?>
                                        <a href="?tab=gsc&amp;days=<?php echo $gscDays; ?>&amp;gkp=<?php echo $gscPage - 1; ?>">&laquo; Previous</a>
                                    <?php endif; ?>
                                    <span class="current">Page <?php echo number_format($gscPage); ?></span>
                                    <?php if (!empty($gscReport['has_next'])): ?>
                                        <a href="?tab=gsc&amp;days=<?php echo $gscDays; ?>&amp;gkp=<?php echo $gscPage + 1; ?>">Next &raquo;</a>
                                    <?php endif; ?>
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
                                    <span style="color:#888;font-size:12px;">Analytics Journey retention only (max 3650 = 10 years)</span>
                                </div>
                                <div class="setting-row">
                                    <label>Contact Intent Retention (days)</label>
                                    <input type="number" name="contact_intent_retention_days" value="<?php echo htmlspecialchars($settings['contact_intent_retention_days'] ?? '90'); ?>" min="1" max="365">
                                    <span style="color:#888;font-size:12px;">WhatsApp/mailto open intents; default 90, maximum 365</span>
                                </div>
                                <div class="setting-row">
                                    <label>Confirmed Inquiry Retention (days)</label>
                                    <input type="number" name="contact_inquiry_retention_days" value="<?php echo htmlspecialchars($settings['contact_inquiry_retention_days'] ?? '730'); ?>" min="1" max="3650">
                                    <span style="color:#888;font-size:12px;">Server-confirmed inquiry outcomes; default 730</span>
                                </div>
                                <div class="setting-row">
                                    <label>Enable Geo Lookup</label>
                                    <input type="checkbox" name="enable_geo" <?php echo ($settings['enable_geo'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <span style="color:#888;font-size:12px;">Resolve IP to country/city via ip-api.com (free)</span>
                                </div>
                                <div class="setting-row">
                                    <label>Internal IP / CIDR Exclusions</label>
                                    <textarea name="excluded_ips" rows="3" style="width:320px;padding:6px 10px;border:1px solid #ddd;border-radius:4px;font-size:12px;" placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo htmlspecialchars($settings['excluded_ips'] ?? ''); ?></textarea>
                                    <span style="color:#888;font-size:12px;">One per line. Excludes analytics writes only; inquiry email delivery remains unaffected.</span>
                                </div>
                                <div class="setting-row">
                                    <label>Active Dwell Heartbeat</label>
                                    <input type="number" name="heartbeat_seconds" value="<?php echo htmlspecialchars($settings['heartbeat_seconds'] ?? '45'); ?>" min="30" max="120">
                                    <span style="color:#888;font-size:12px;">Seconds between active-page recovery writes. Tracker refreshes this public setting with a short cache.</span>
                                </div>
                                <div class="setting-row">
                                    <label>Inquiry Email Journey Summary</label>
                                    <input type="checkbox" name="enable_email_summary" <?php echo ($settings['enable_email_summary'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <span style="color:#888;font-size:12px;">Append attributed source and a compact journey summary to successful inquiry emails.</span>
                                </div>
                                <div style="margin-top:15px;">
                                    <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
                                </div>
                            </form>

                            <hr style="margin:30px 0;border:none;border-top:1px solid #eee;">

                            <h3 style="color:#5D4E37;margin-bottom:8px;">Google Search Console</h3>
                            <p style="color:#666;font-size:13px;line-height:1.5;">
                                Server-only service-account configuration. The private key and OAuth token are never displayed or sent to the browser.
                            </p>
                            <div class="gsc-status-grid">
                                <div class="gsc-status-label">Server configuration</div>
                                <div class="gsc-status-value">
                                    <?php $gscServerEnv = ($gscDiagnostics['credentials_source'] ?? '') === 'server environment' && ($gscDiagnostics['site_source'] ?? '') === 'server environment'; ?>
                                    <span class="status-pill <?php echo !empty($gscDiagnostics['path_configured']) && !empty($gscDiagnostics['site_configured']) ? 'status-ok' : 'status-bad'; ?>"><?php echo $gscServerEnv ? 'Environment active' : 'Application fallback active'; ?></span>
                                    Credentials: <?php echo htmlspecialchars($gscDiagnostics['credentials_source'] ?? '-'); ?>; property: <?php echo htmlspecialchars($gscDiagnostics['site_source'] ?? '-'); ?>
                                </div>

                                <div class="gsc-status-label">Credentials file</div>
                                <div class="gsc-status-value"><span class="status-pill <?php echo !empty($gscDiagnostics['file_readable']) ? 'status-ok' : 'status-bad'; ?>"><?php echo !empty($gscDiagnostics['file_readable']) ? 'Readable by PHP' : 'Not readable by PHP'; ?></span> <?php echo htmlspecialchars($gscDiagnostics['credentials_path'] ?? '-'); ?></div>

                                <div class="gsc-status-label">Credentials JSON</div>
                                <div class="gsc-status-value"><span class="status-pill <?php echo !empty($gscDiagnostics['json_valid']) ? 'status-ok' : 'status-bad'; ?>"><?php echo !empty($gscDiagnostics['json_valid']) ? 'Valid' : 'Invalid or unavailable'; ?></span></div>

                                <div class="gsc-status-label">Service account</div>
                                <div class="gsc-status-value"><?php echo htmlspecialchars($gscDiagnostics['service_account'] ?: '-'); ?></div>

                                <div class="gsc-status-label">Search Console property</div>
                                <div class="gsc-status-value"><?php echo htmlspecialchars($gscDiagnostics['site_url'] ?: '-'); ?></div>

                                <div class="gsc-status-label">PHP requirements</div>
                                <div class="gsc-status-value"><span class="status-pill <?php echo !empty($gscDiagnostics['openssl']) && !empty($gscDiagnostics['http_transport']) ? 'status-ok' : 'status-bad'; ?>"><?php echo !empty($gscDiagnostics['openssl']) && !empty($gscDiagnostics['http_transport']) ? 'OpenSSL + HTTP ready' : 'Missing OpenSSL or HTTP transport'; ?></span></div>

                                <div class="gsc-status-label">Last connection test</div>
                                <div class="gsc-status-value">
                                    <?php $lastGscTest = $gscDiagnostics['last_test'] ?? []; ?>
                                    <?php if (empty($lastGscTest)): ?>
                                        <span class="status-pill status-bad">Not tested</span>
                                    <?php else: ?>
                                        <span class="status-pill <?php echo !empty($lastGscTest['ok']) ? 'status-ok' : 'status-bad'; ?>"><?php echo !empty($lastGscTest['ok']) ? 'Passed' : 'Failed'; ?></span>
                                        <?php echo htmlspecialchars($lastGscTest['tested_at'] ?? ''); ?>
                                        <?php if (!empty($lastGscTest['ok'])): ?>
                                            — <?php echo htmlspecialchars($lastGscTest['message'] ?? 'Connection successful.'); ?>
                                        <?php elseif (isGscPermissionError($lastGscTest['message'] ?? '')): ?>
                                            — Search Console access has not been granted for this property.
                                        <?php else: ?>
                                            — <?php echo htmlspecialchars($lastGscTest['message'] ?? 'Unknown error.'); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (empty($gscDiagnostics['file_readable'])): ?>
                                <p class="error">PHP user kssmi4374 still cannot read the credentials path shown above. Verify the current owner/mode on the server, then test again.</p>
                            <?php endif; ?>
                            <?php if (isGscPermissionError($gscDiagnostics['last_test']['message'] ?? '')): ?>
                                <div class="gsc-action"><strong>One manual Google step remains:</strong> in Search Console, select the exact property <strong><?php echo htmlspecialchars($gscDiagnostics['site_url'] ?? 'sc-domain:kssmi.com'); ?></strong>, open <strong>Settings → Users and permissions → Add user</strong>, add <strong><?php echo htmlspecialchars($gscDiagnostics['service_account'] ?? ''); ?></strong> with <strong>Restricted or Full</strong> permission, then click the test button below.</div>
                            <?php endif; ?>
                            <form method="POST" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                                <button type="submit" name="test_gsc_connection" class="btn btn-primary">Test GSC Connection</button>
                                <?php if (!empty($gscDiagnostics['last_test']['ok'])): ?><a href="?tab=gsc" class="btn btn-secondary">Open Keywords</a><?php endif; ?>
                            </form>

                            <hr style="margin:30px 0;border:none;border-top:1px solid #eee;">

                            <h3 style="color:#e74c3c;margin-bottom:10px;">Danger Zone</h3>
                            <p style="color:#666;font-size:13px;margin-bottom:10px;">
                                Delete Analytics Journey data older than the specified number of days. Core events follow the separate intent/inquiry retention settings above; 0 deletes everything.
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
<script>
(function () {
  var mobileFilters = window.matchMedia('(max-width: 768px)');

  function syncFilterDisclosures(event) {
    var disclosures = document.querySelectorAll('.filter-disclosure');
    for (var i = 0; i < disclosures.length; i++) {
      disclosures[i].open = !event.matches;
    }
  }

  syncFilterDisclosures(mobileFilters);
  if (typeof mobileFilters.addEventListener === 'function') {
    mobileFilters.addEventListener('change', syncFilterDisclosures);
  } else {
    mobileFilters.addListener(syncFilterDisclosures);
  }
})();

function vjtToggleAll() {
  var master = document.getElementById('vjtSelectAll');
  var cbs = document.querySelectorAll('.vjt-row-cb');
  for (var i = 0; i < cbs.length; i++) cbs[i].checked = master.checked;
}
function vjtGetSelected() {
  var cbs = document.querySelectorAll('.vjt-row-cb:checked');
  var ids = [];
  for (var i = 0; i < cbs.length; i++) ids.push(cbs[i].value);
  return ids;
}
function vjtToggleContactEvents() {
  var master = document.getElementById('contactSelectAll');
  var cbs = document.querySelectorAll('.contact-row-cb');
  for (var i = 0; i < cbs.length; i++) cbs[i].checked = !!(master && master.checked);
}
function vjtDeleteContactEvents() {
  var cbs = document.querySelectorAll('.contact-row-cb:checked');
  var ids = [];
  for (var i = 0; i < cbs.length; i++) ids.push(cbs[i].value);
  if (!ids.length) { alert('No Core events selected.'); return; }
  if (!confirm('Delete ' + ids.length + ' Core event(s)? This cannot be undone.')) return;
  vjtSubmitContactDeletion(ids);
}
function vjtDeleteContactEvent(id) {
  if (!confirm('Delete this Core event? This cannot be undone.')) return;
  vjtSubmitContactDeletion([id]);
}
function vjtSubmitContactDeletion(ids) {
  var form = document.createElement('form');
  form.method = 'POST';
  var idInput = document.createElement('input');
  idInput.name = 'delete_contact_ids';
  idInput.value = ids.join(',');
  form.appendChild(idInput);
  var csrf = document.createElement('input');
  csrf.name = 'csrf_token';
  csrf.value = (document.getElementById('vjt_csrf') || {}).value || '';
  form.appendChild(csrf);
  document.body.appendChild(form);
  form.submit();
}
function vjtBulkDelete() {
  var ids = vjtGetSelected();
  if (!ids.length) { alert('No rows selected.'); return; }
  if (!confirm('Delete the selected canonical Contact Core Lead event(s)? Journey history will be preserved. This cannot be undone.')) return;
  var form = document.createElement('form');
  form.method = 'POST';
  var leadInput = document.createElement('input');
  leadInput.name = 'delete_lead_keys';
  leadInput.value = ids.join(',');
  form.appendChild(leadInput);
  var csrf = document.createElement('input');
  csrf.name = 'csrf_token';
  csrf.value = (document.getElementById('vjt_csrf')||{}).value || '';
  form.appendChild(csrf);
  document.body.appendChild(form);
  form.submit();
}
function vjtDeleteLead(leadKey) {
  if (!confirm('Delete this canonical Contact Core Lead event set? Journey history will be preserved.')) return;
  var form = document.createElement('form');
  form.method = 'POST';
  var leadInput = document.createElement('input');
  leadInput.name = 'delete_lead_keys';
  leadInput.value = leadKey;
  form.appendChild(leadInput);
  var csrf = document.createElement('input');
  csrf.name = 'csrf_token';
  csrf.value = (document.getElementById('vjt_csrf')||{}).value || '';
  form.appendChild(csrf);
  document.body.appendChild(form);
  form.submit();
}
function vjtDeleteOne(id) {
  if (!confirm('Delete this submission? This cannot be undone.')) return;
  var form = document.createElement('form');
  form.method = 'POST';
  var deleteInput = document.createElement('input');
  deleteInput.name = 'delete_ids';
  deleteInput.value = id;
  form.appendChild(deleteInput);
  var csrf = document.createElement('input');
  csrf.name = 'csrf_token';
  csrf.value = (document.getElementById('vjt_csrf')||{}).value || '';
  form.appendChild(csrf);
  document.body.appendChild(form);
  form.submit();
}
function vjtDeleteVisitor(visitorId) {
  if (!confirm('Delete this visitor and ALL associated records (sessions, pageviews, submissions)? This cannot be undone.')) return;
  var form = document.createElement('form');
  form.method = 'POST';
  var visitorInput = document.createElement('input');
  visitorInput.name = 'delete_visitor';
  visitorInput.value = visitorId;
  form.appendChild(visitorInput);
  var csrf = document.createElement('input');
  csrf.name = 'csrf_token';
  csrf.value = (document.getElementById('vjt_csrf')||{}).value || '';
  form.appendChild(csrf);
  document.body.appendChild(form);
  form.submit();
}
</script>
</body>
</html>
