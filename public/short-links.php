<?php
declare(strict_types=1);
/**
 * Standalone Short Links tool.
 *
 * External-facing sibling of visitor-journey.php?tab=short-links with its own
 * login so colleagues never touch the VJT dashboard. Shares the short-link
 * store (private/short-link-store.php) and its SQLite database; the session,
 * CSRF key, and API endpoint are independent.
 *
 * Login policy:
 *  - Email must end in @kssmi.com AND appear in the shared short-links users
 *    file (see private/short-links-tool.php). Format, one account per line:
 *    "email bcrypt-hash [admin]" — the optional word admin may see every
 *    account's links. Generate a hash with:
 *    php -r "echo password_hash('YOUR PASSWORD', PASSWORD_DEFAULT);"
 *  - Signed-in users can change their own password (API action change-password).
 *    Forgotten passwords are recovered through /api/short-links-reset.php.
 */

require_once dirname(__DIR__) . '/private/short-links-tool.php';
kssmi_admin_require_trusted_proxy();
kssmi_short_links_session();
require_once dirname(__DIR__) . '/private/rate-limit.php';
// Formatting helpers only (vjt_format_for_admin, vjt_country_name); no data init.
require_once __DIR__ . '/api/vjt-helpers.php';

kssmi_admin_security_headers("default-src 'none'; base-uri 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");

const SL_TOOL_CSRF_KEY = 'short_links_tool_csrf';

$error = '';

// Logout is state-changing and requires the tool CSRF token.
if (isset($_SESSION['short_links_tool_auth']) && $_SESSION['short_links_tool_auth'] === true
    && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!kssmi_admin_csrf_valid($_POST['csrf_token'] ?? null, SL_TOOL_CSRF_KEY)) {
        $error = 'Security check failed. Please try again.';
    } else {
        $_SESSION['short_links_tool_auth'] = false;
        unset($_SESSION['short_links_tool_email'], $_SESSION['short_links_tool_admin']);
        header('Location: /short-links');
        exit;
    }
}

// Handle login.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'], $_POST['password'])) {
    if (!checkRateLimit('short-links-tool-login', 30, 900)) {
        http_response_code(429);
        header('Retry-After: 900');
        $error = 'Too many login attempts. Please try again in 15 minutes.';
    } elseif (!kssmi_admin_csrf_valid($_POST['csrf_token'] ?? null, SL_TOOL_CSRF_KEY)) {
        $error = 'Security check failed. Please try again.';
    } else {
        $email = strtolower(kssmi_scalar_text($_POST['email'] ?? null, 254));
        $submitted = kssmi_scalar_text($_POST['password'] ?? null, 4096, false);
        $users = kssmi_short_links_users();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, KSSMI_SHORT_LINK_EMAIL_DOMAIN)) {
            $error = 'Only @kssmi.com email addresses can sign in.';
        } elseif (!isset($users[$email])) {
            $error = 'This email is not authorized for the short-links tool.';
        } elseif (!password_verify($submitted, $users[$email]['hash'])) {
            $error = 'Invalid password.';
        } else {
            // Deliberately does NOT touch any shared admin session: this tool
            // must never unlock the VJT dashboard or email logs.
            // A fresh session ID keeps a pre-login session identifier from
            // surviving the authentication boundary (fixation defense).
            if (!session_regenerate_id(true)) {
                $error = 'Sign-in failed. Please try again.';
            } else {
                $_SESSION['short_links_tool_auth'] = true;
                $_SESSION['short_links_tool_email'] = $email;
                $_SESSION['short_links_tool_admin'] = $users[$email]['admin'];
                kssmi_admin_csrf_rotate(SL_TOOL_CSRF_KEY);
            }
        }
    }
}

$isAuthenticated = isset($_SESSION['short_links_tool_auth']) && $_SESSION['short_links_tool_auth'] === true;
$toolEmail = (string)($_SESSION['short_links_tool_email'] ?? '');
$toolIsAdmin = $isAuthenticated && ($_SESSION['short_links_tool_admin'] ?? false) === true;
// Regular accounts only ever see links they created; admins see everything.
$shortLinkOwner = $toolIsAdmin ? null : $toolEmail;

if ($isAuthenticated) {
    kssmi_admin_set_marker_cookie(true);
}
// The tool CSRF token is issued for anonymous visitors too: the forgot/reset
// password forms POST through /api/short-links-reset.php before any login.
kssmi_admin_csrf_token(SL_TOOL_CSRF_KEY);

// Password reset tokens arrive as /short-links?reset=<64 hex chars>.
$resetToken = (is_string($_GET['reset'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $_GET['reset']) === 1)
    ? $_GET['reset']
    : '';

// ── Short-link data (mirrors visitor-journey.php?tab=short-links) ──────────
$shortLinkRows = [];
$shortLinkTotal = 0;
$shortLinkPage = max(1, (int)kssmi_scalar_text($_GET['sl_page'] ?? '1', 10));
$shortLinkPerPage = 100;
$shortLinkPages = 1;
$shortLinkSearch = kssmi_scalar_text($_GET['sl_search'] ?? '', 256);
$shortLinkTracking = null;
$shortLinkTrackingNeighbors = ['previous' => null, 'next' => null];
$shortLinkCapacity = null;
if ($isAuthenticated) {
    try {
        $trackingId = filter_var(
            $_GET['sl_tracking'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($trackingId !== false && $trackingId !== null) {
            $shortLinkTracking = short_link_tracking((int)$trackingId, 250, $shortLinkOwner);
            if ($shortLinkTracking) {
                $shortLinkTrackingNeighbors = short_link_tracking_neighbors(
                    (int)$trackingId,
                    $shortLinkSearch,
                    $shortLinkOwner
                );
            }
        } else {
            $shortLinkTotal = short_link_count($shortLinkSearch, $shortLinkOwner);
            $shortLinkPages = max(1, (int)ceil($shortLinkTotal / $shortLinkPerPage));
            $shortLinkPage = min($shortLinkPage, $shortLinkPages);
            $shortLinkRows = short_link_list(
                $shortLinkSearch,
                $shortLinkPerPage,
                ($shortLinkPage - 1) * $shortLinkPerPage,
                $shortLinkOwner
            );
        }
        $shortLinkCapacity = short_link_event_capacity(
            $shortLinkTracking ? (int)$shortLinkTracking['link']['id'] : null
        );
    } catch (Throwable $shortLinkError) {
        error_log('KSSMI short-links tool data failure: ' . $shortLinkError->getMessage());
    }
}

function sl_tool_map_locations(array $locations): array {
    static $points = [
        'AR' => [-34, -64], 'AU' => [-25, 134], 'BR' => [-10, -55],
        'CA' => [56, -106], 'CL' => [-33, -71], 'CN' => [35, 103],
        'DE' => [51, 10], 'EG' => [27, 30], 'ES' => [40, -4],
        'FR' => [46, 2], 'GB' => [55, -3], 'ID' => [-2, 118],
        'IN' => [21, 79], 'IT' => [42, 12], 'JP' => [36, 138],
        'KE' => [1, 38], 'KR' => [36, 128], 'MX' => [23, -102],
        'MY' => [4, 102], 'NG' => [9, 8], 'NL' => [52, 5],
        'NO' => [62, 10], 'NZ' => [-41, 174], 'PH' => [13, 122],
        'PL' => [52, 20], 'RU' => [61, 105], 'SA' => [24, 45],
        'SE' => [62, 15], 'SG' => [1, 104], 'TH' => [15, 101],
        'TR' => [39, 35], 'US' => [39, -98], 'VN' => [16, 108],
        'ZA' => [-30, 25], 'AE' => [24, 54],
    ];
    $mapped = [];
    foreach ($locations as $location) {
        $country = strtoupper((string)($location['country'] ?? ''));
        if (!isset($points[$country])) continue;
        $location['lat'] = $points[$country][0];
        $location['lng'] = $points[$country][1];
        $mapped[] = $location;
    }
    return $mapped;
}

$shortLinkMapLocations = $shortLinkTracking
    ? sl_tool_map_locations($shortLinkTracking['locations'] ?? [])
    : [];
$shortLinkMapVisible = $shortLinkMapLocations !== [];

function sl_tool_query(array $overrides = []): string {
    $params = array_filter(array_merge([
        'sl_search' => $GLOBALS['shortLinkSearch'],
        'sl_page' => $GLOBALS['shortLinkPage'],
        'sl_tracking' => null,
    ], $overrides), static fn($v) => $v !== null && $v !== '');
    $query = http_build_query($params);
    return '/short-links' . ($query !== '' ? '?' . $query : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Short Links - KSSMI</title>
    <?php if ($isAuthenticated && $shortLinkMapVisible): ?>
    <link rel="stylesheet" href="/vendor/short-link-map/leaflet.css">
    <?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 14px 20px; color: #333; }
        .container { max-width: 1500px; margin: 0 auto; }
        h1 { color: #5D4E37; }
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:is(:hover, :active, :focus-visible, :focus-within) { background: #5D4E37; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px; background: white; padding: 12px 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); border-left: 3px solid #8B7355; }
        .header-left { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .header-left h1 { font-size: 16px; font-weight: 700; color: #5D4E37; white-space: nowrap; }
        .header-left .subtitle { font-size: 13px; color: #888; }
        .header-right { display: flex; gap: 6px; align-items: center; }
        .btn { padding: 6px 14px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 12px; display: inline-block; font-weight: 500; }
        .btn-primary { background: #8B7355; color: white; }
        .btn-primary:is(:hover, :active, :focus-visible, :focus-within) { background: #5D4E37; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:is(:hover, :active, :focus-visible, :focus-within) { background: #c0392b; }
        .btn-secondary { background: #666; color: white; }
        .btn-secondary:is(:hover, :active, :focus-visible, :focus-within) { background: #444; }
        .btn-small { padding: 4px 10px; font-size: 11px; }
        .btn:is(:hover, :active, :focus-visible, :focus-within) { opacity: 0.9; }
        .panel { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 14px; }
        .panel-header { padding: 12px 16px; border-bottom: 1px solid #eee; font-weight: 600; color: #5D4E37; font-size: 13px; display: flex; justify-content: space-between; align-items: center; }
        .panel-body { padding: 16px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 10px; text-transform: uppercase; white-space: nowrap; }
        tr:is(:hover, :active, :focus-visible, :focus-within) { background: #fafafa; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 14px; align-items: stretch; }
        .stat-card { background: white; padding: 16px 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-top: 3px solid #8B7355; display: flex; flex-direction: column; }
        .stat-card h3 { font-size: 10px; text-transform: uppercase; color: #888; margin-bottom: 4px; letter-spacing: 0.8px; }
        .stat-card .value { font-size: 26px; font-weight: bold; color: #5D4E37; flex: 1; display: flex; align-items: center; }
        .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .filters input { padding: 5px 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; }
        .error { color: #e74c3c; margin-bottom: 15px; padding: 10px; background: #fdeaea; border-radius: 4px; }
        .success { color: #27ae60; padding: 10px; background: #d4edda; border-radius: 4px; margin-bottom: 15px; }
        .url-cell { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .short-link-table { min-width: 840px; }
        .short-link-table .short-target { max-width: 170px; }
        .short-actions { display: flex; gap: 6px; }
        .short-actions .btn { width: 52px; padding-left: 3px; padding-right: 3px; text-align: center; }
        .short-links-toolbar { gap: 10px; flex-wrap: wrap; }
        .short-links-search { flex: 1 1 360px; max-width: 520px; margin: 0; }
        .short-links-search-input { position: relative; display: block; width: 360px; max-width: 100%; }
        .short-links-search input[name="sl_search"] { width: 100%; padding-right: 30px; }
        .short-links-search-clear { position: absolute; right: 4px; top: 50%; transform: translateY(-50%); width: 22px; height: 22px; border: 0; border-radius: 50%; background: transparent; color: #888; font-size: 18px; line-height: 1; cursor: pointer; }
        .short-links-search-clear:is(:hover, :active, :focus-visible, :focus-within), .short-links-search-clear:focus-visible { color: #333; background: #eee; outline: 0; }
        .short-link-pagination { display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 12px; color: #666; font-size: 12px; }
        .short-target-copy { display: block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border: 0; padding: 0; background: transparent; color: #8B7355; cursor: pointer; font: inherit; text-align: left; }
        .short-plain-copy { color: #333; }
        .short-target-copy:is(:hover, :active, :focus-visible, :focus-within), .short-target-copy:focus-visible { text-decoration: underline; outline: 0; }
        .short-target-copy.is-copied { color: #27734a; }
        .short-index { width: 32px; color: #888; text-align: right; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        #shortLinkMap { width: 100%; height: clamp(320px, 34vw, 430px); border: 1px solid #d5d9dd; border-radius: 8px; background: #e8f0f7; overflow: hidden; }
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .header-right { width: 100%; justify-content: flex-end; }
            th, td { padding: 6px 8px; font-size: 12px; }
        }
        @media (max-width: 480px) {
            th, td { padding: 5px 6px; font-size: 11px; }
            .panel-header { font-size: 12px; padding: 10px 12px; }
            .container { padding: 0 4px; }
            body { padding: 8px 4px; }
            .url-cell { max-width: 140px; }
            .login-box { margin: 40px auto; padding: 24px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <input type="hidden" id="sl_tool_csrf" value="<?php echo htmlspecialchars(kssmi_admin_csrf_token(SL_TOOL_CSRF_KEY)); ?>">
        <?php if (!$isAuthenticated): ?>
            <div class="login-box">
                <h2>Short Links</h2>
                <?php if ($error): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <?php if ($resetToken !== ''): ?>
                    <p style="font-size:14px;color:#333;margin-bottom:14px;">Choose a new password for your account.</p>
                    <div id="resetMessage" style="font-size:13px;margin-bottom:10px;" role="status" aria-live="polite"></div>
                    <form id="resetForm">
                        <input type="password" id="resetNew" placeholder="New password (min 10 characters)" required minlength="10" maxlength="128" autocomplete="new-password">
                        <input type="password" id="resetConfirm" placeholder="Repeat new password" required autocomplete="new-password">
                        <button type="submit">Set new password</button>
                    </form>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kssmi_admin_csrf_token(SL_TOOL_CSRF_KEY)); ?>">
                        <input type="email" name="email" placeholder="name@kssmi.com" required autofocus autocomplete="username">
                        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
                        <button type="submit">Login</button>
                    </form>
                    <p style="text-align:center;margin-top:10px;">
                        <a href="#" id="forgotLink" class="link" style="font-size:13px;">Forgot password?</a>
                    </p>
                    <form id="forgotForm" hidden style="margin-top:12px;">
                        <input type="email" id="forgotEmail" placeholder="name@kssmi.com" required autocomplete="username">
                        <button type="submit">Send reset link</button>
                    </form>
                    <div id="forgotMessage" style="font-size:13px;margin-top:10px;" role="status" aria-live="polite"></div>
                    <p style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
                        Access is limited to approved @kssmi.com accounts.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="header">
                <div class="header-left">
                    <h1>Short Links</h1>
                    <span class="subtitle"><?php echo htmlspecialchars($toolEmail); ?><?php echo $toolIsAdmin ? ' · admin (all accounts)' : ''; ?></span>
                </div>
                <div class="header-right">
                    <button type="button" id="shortPasswordToggle" class="btn btn-secondary btn-small">Change password</button>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(kssmi_admin_csrf_token(SL_TOOL_CSRF_KEY)); ?>">
                        <button type="submit" name="logout" class="btn btn-secondary">Logout</button>
                    </form>
                </div>
            </div>

            <div class="panel" id="shortPasswordPanel" hidden style="max-width:480px;">
                <div class="panel-header">Change my password</div>
                <div class="panel-body">
                    <div id="shortPasswordMessage" style="margin-bottom:10px;font-size:13px;" role="status" aria-live="polite"></div>
                    <form id="shortPasswordForm">
                        <input id="shortPasswordCurrent" type="password" placeholder="Current password" required autocomplete="current-password" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-bottom:8px;">
                        <input id="shortPasswordNew" type="password" placeholder="New password (min 10 characters)" required minlength="10" maxlength="128" autocomplete="new-password" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-bottom:8px;">
                        <input id="shortPasswordConfirm" type="password" placeholder="Repeat new password" required autocomplete="new-password" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;">
                        <button class="btn btn-primary" type="submit">Update password</button>
                    </form>
                </div>
            </div>

            <span id="shortCopyStatus" class="sr-only" role="status" aria-live="polite"></span>
            <div class="panel">
                <div class="panel-header">Short Links</div>
                <div class="panel-body">
                    <?php if ($shortLinkCapacity && (($shortLinkCapacity['pruned_events'] ?? 0) > 0 || ($shortLinkCapacity['global_total'] ?? 0) > ($shortLinkCapacity['global_count'] ?? 0))): ?>
                    <p class="success">
                        Older event details are automatically pruned to protect storage. Lifetime opens remain accurate; recent event history is retained.
                    </p>
                    <?php endif; ?>
                    <form id="shortDestinationForm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <div style="flex:1;min-width:280px;">
                            <label class="sr-only" for="shortTarget">Destination URL</label>
                            <input id="shortTarget" type="url" required maxlength="4096" placeholder="https://example.com/..." style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">
                        </div>
                        <button class="btn btn-primary" type="submit">Find or register destination</button>
                    </form>
                    <div id="shortDestinationResult" style="margin-top:12px;" role="status" aria-live="polite"></div>
                    <form id="shortDistributionForm" hidden style="margin-top:14px;padding-top:14px;border-top:1px solid #eee;display:none;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                        <input id="shortDestinationId" type="hidden">
                        <div>
                            <label for="shortLabel" style="display:block;font-size:11px;color:#666;margin-bottom:4px;">Label</label>
                            <input id="shortLabel" maxlength="256" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                        </div>
                        <div>
                            <label for="shortCampaign" style="display:block;font-size:11px;color:#666;margin-bottom:4px;">Campaign</label>
                            <input id="shortCampaign" maxlength="256" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                        </div>
                        <div>
                            <label for="shortRecipient" style="display:block;font-size:11px;color:#666;margin-bottom:4px;">Recipient</label>
                            <input id="shortRecipient" maxlength="256" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                        </div>
                        <button class="btn btn-primary" type="submit">Create</button>
                    </form>
                </div>
            </div>

            <?php if ($shortLinkTracking): ?>
            <div class="panel">
                <div class="panel-header" style="gap:12px;flex-wrap:wrap;">
                    <span>Track: <?php echo htmlspecialchars($shortLinkTracking['link']['code']); ?></span>
                    <span style="display:flex;gap:6px;align-items:center;">
                        <?php foreach (['previous' => 'Previous', 'next' => 'Next'] as $direction => $label): ?>
                            <?php $neighbor = $shortLinkTrackingNeighbors[$direction]; ?>
                            <?php if ($neighbor): ?>
                                <a class="btn btn-secondary btn-small" style="min-width:72px;text-align:center;" href="<?php echo htmlspecialchars(sl_tool_query(['sl_tracking' => (int)$neighbor['id'], 'sl_page' => null])); ?>"><?php echo $label; ?></a>
                            <?php else: ?>
                                <span class="btn btn-secondary btn-small" style="min-width:72px;text-align:center;opacity:.45;cursor:default;" aria-disabled="true"><?php echo $label; ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <a class="btn btn-secondary btn-small" style="min-width:52px;text-align:center;" href="<?php echo htmlspecialchars(sl_tool_query(['sl_tracking' => null, 'sl_page' => null])); ?>">Back</a>
                    </span>
                </div>
                <div class="panel-body">
                    <?php $trackingLink = $shortLinkTracking['link']; ?>
                    <p style="margin:0 0 8px;">
                        <strong>Short link:</strong>
                        <button type="button" class="short-target-copy" data-copy="https://kssmi.com/<?php echo htmlspecialchars($trackingLink['code'], ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)">https://kssmi.com/<?php echo htmlspecialchars($trackingLink['code']); ?></button>
                    </p>
                    <p style="margin:0 0 12px;color:#666;">
                        <strong>Label:</strong>
                        <?php $trackingLabel = trim($trackingLink['label'] . ' ' . $trackingLink['campaign'] . ' ' . $trackingLink['recipient_ref']); ?>
                        <button type="button" class="short-target-copy" data-copy="<?php echo htmlspecialchars($trackingLabel, ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><?php echo htmlspecialchars($trackingLabel !== '' ? $trackingLabel : '-'); ?></button>
                    </p>
                    <p style="margin:0 0 12px;color:#666;word-break:break-all;">
                        <strong>Target:</strong>
                        <button type="button" class="short-target-copy" data-copy="<?php echo htmlspecialchars($trackingLink['target_url'], ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($trackingLink['target_url'], ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><?php echo htmlspecialchars($trackingLink['target_url']); ?></button>
                    </p>
                    <div class="stats" style="margin-bottom:16px;">
                        <div class="stat-card"><h3>Opens</h3><div class="value"><?php echo number_format((int)$shortLinkTracking['summary']['opens']); ?></div></div>
                        <div class="stat-card"><h3>Bots filtered</h3><div class="value"><?php echo number_format((int)$shortLinkTracking['summary']['bots']); ?></div></div>
                        <div class="stat-card"><h3>Last opened</h3><div class="value" style="font-size:15px;"><?php echo htmlspecialchars(vjt_format_for_admin($shortLinkTracking['summary']['last_opened'] ?? '')); ?></div></div>
                    </div>

                    <?php if (!empty($shortLinkTracking['locations'])): ?>
                    <div style="margin:0 0 16px;">
                        <strong style="display:block;margin-bottom:8px;">Recent opening locations (country-level)</strong>
                        <?php if ($shortLinkMapVisible): ?>
                        <div id="shortLinkMap" data-locations="<?php echo htmlspecialchars(json_encode($shortLinkMapLocations, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES); ?>"></div>
                        <?php endif; ?>
                        <div class="table-wrapper" style="margin-top:8px;">
                            <table>
                                <thead><tr><th>Country</th><th>Opens</th><th>Last opened</th></tr></thead>
                                <tbody>
                                    <?php foreach ($shortLinkTracking['locations'] as $location): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(vjt_country_name($location['country'])); ?></td>
                                        <td><?php echo number_format((int)$location['opens']); ?></td>
                                        <td><?php echo htmlspecialchars(vjt_format_for_admin($location['last_opened'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php else: ?>
                    <p style="color:#666;font-size:12px;margin:0 0 10px;">Location data is collected country-by-country for new opens only; existing opens cannot be located retroactively.</p>
                    <?php endif; ?>

                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>#</th><th>Opened</th><th>Location</th><th>Recipient</th><th>Recent opens</th></tr></thead>
                            <tbody>
                                <?php foreach ($shortLinkTracking['events'] as $index => $event): ?>
                                <tr>
                                    <td class="short-index"><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars(vjt_format_for_admin($event['opened_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($event['country'] ? vjt_country_name($event['country']) : '-'); ?></td>
                                    <td><?php echo htmlspecialchars($event['recipient_ref_snapshot'] ?: '-'); ?></td>
                                    <td><?php echo $event['recipient_opens'] === null ? '-' : number_format((int)$event['recipient_opens']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$shortLinkTracking['events']): ?>
                                <tr><td colspan="5" style="text-align:center;color:#888;">No opens recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="panel">
                <div class="panel-header short-links-toolbar">
                    <form method="get" class="filters short-links-search">
                        <span class="short-links-search-input">
                            <input type="search" name="sl_search" maxlength="256" value="<?php echo htmlspecialchars($shortLinkSearch); ?>" placeholder="Code, URL, label, campaign, recipient" aria-label="Search distribution links">
                            <?php if ($shortLinkSearch !== ''): ?>
                            <button class="short-links-search-clear" type="button" title="Clear search" aria-label="Clear search" onclick="window.location.href='/short-links'">&times;</button>
                            <?php endif; ?>
                        </span>
                        <button class="btn btn-secondary" type="submit">Search</button>
                    </form>
                    <button id="shortDeleteSelected" type="button" class="btn btn-danger btn-small">Delete selected</button>
                    <button id="shortExport" type="button" class="btn btn-secondary btn-small">Export CSV</button>
                </div>
                <div class="panel-body">
                    <div class="table-wrapper">
                        <table class="short-link-table">
                            <thead><tr><th><input id="shortSelectAll" type="checkbox" aria-label="Select all current links"></th><th>#</th><th>Short link</th><th>Target</th><th>Label</th><th>Campaign</th><?php if ($toolIsAdmin): ?><th>By</th><?php endif; ?><th>Opens</th><th>Last</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($shortLinkRows as $index => $row): ?>
                                <?php $rowLabel = trim($row['label']); $rowCampaign = trim($row['campaign']); ?>
                                <tr data-short-link="https://kssmi.com/<?php echo htmlspecialchars($row['code'], ENT_QUOTES); ?>" data-short-target="<?php echo htmlspecialchars($row['target_url'], ENT_QUOTES); ?>" data-short-label="<?php echo htmlspecialchars($rowLabel, ENT_QUOTES); ?>" data-short-campaign="<?php echo htmlspecialchars($rowCampaign, ENT_QUOTES); ?>">
                                    <td><input class="short-row-select" type="checkbox" data-short-id="<?php echo (int)$row['id']; ?>" aria-label="Select <?php echo htmlspecialchars($row['code'], ENT_QUOTES); ?>"></td>
                                    <td class="short-index"><?php echo $shortLinkTotal - (($shortLinkPage - 1) * $shortLinkPerPage) - $index; ?></td>
                                    <td><button type="button" class="short-target-copy" data-copy="https://kssmi.com/<?php echo htmlspecialchars($row['code'], ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><code>https://kssmi.com/<?php echo htmlspecialchars($row['code']); ?></code></button></td>
                                    <td class="url-cell short-target"><button type="button" class="short-target-copy" data-copy="<?php echo htmlspecialchars($row['target_url'], ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($row['target_url'], ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><?php echo htmlspecialchars($row['target_url']); ?></button></td>
                                    <td><button type="button" class="short-target-copy short-plain-copy" data-copy="<?php echo htmlspecialchars($rowLabel, ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><?php echo htmlspecialchars($rowLabel ?: '-'); ?></button></td>
                                    <td><button type="button" class="short-target-copy short-plain-copy" data-copy="<?php echo htmlspecialchars($rowCampaign, ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)"><?php echo htmlspecialchars($rowCampaign ?: '-'); ?></button></td>
                                    <?php if ($toolIsAdmin): ?><td style="font-size:11px;color:#666;"><?php echo htmlspecialchars(str_replace('@kssmi.com', '', (string)($row['created_by'] ?? '')) ?: '-'); ?></td><?php endif; ?>
                                    <td><?php echo number_format((int)$row['opens']); ?> <span style="color:#888;">(+<?php echo number_format((int)$row['bots']); ?> bots)</span></td>
                                    <td><?php echo htmlspecialchars(vjt_format_for_admin($row['last_opened'] ?? '')); ?></td>
                                    <td><div class="short-actions"><button class="btn btn-danger btn-small" type="button" onclick="shortPermanentlyDelete(<?php echo (int)$row['id']; ?>,'<?php echo htmlspecialchars($row['code'], ENT_QUOTES); ?>')">Delete</button><button class="btn btn-secondary btn-small" type="button" data-copy="https://kssmi.com/<?php echo htmlspecialchars($row['code'], ENT_QUOTES); ?>" onclick="shortCopy(this.dataset.copy,this)">Copy</button><a class="btn btn-secondary btn-small" href="<?php echo htmlspecialchars(sl_tool_query(['sl_tracking' => (int)$row['id'], 'sl_page' => null])); ?>">Track</a></div></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!$shortLinkRows): ?>
                                <tr><td colspan="9" style="text-align:center;color:#888;">No distribution links found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($shortLinkPages > 1): ?>
                    <nav class="short-link-pagination" aria-label="Short link pages">
                        <?php if ($shortLinkPage > 1): ?><a class="btn btn-secondary btn-small" href="<?php echo htmlspecialchars(sl_tool_query(['sl_page' => $shortLinkPage - 1])); ?>">Previous</a><?php endif; ?>
                        <span>Page <?php echo $shortLinkPage; ?> of <?php echo $shortLinkPages; ?></span>
                        <?php if ($shortLinkPage < $shortLinkPages): ?><a class="btn btn-secondary btn-small" href="<?php echo htmlspecialchars(sl_tool_query(['sl_page' => $shortLinkPage + 1])); ?>">Next</a><?php endif; ?>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    function toolApi(endpoint, payload) {
      payload.csrf_token = (document.getElementById('sl_tool_csrf') || {}).value || '';
      return fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(async function(response) {
        var body = await response.json();
        if (!response.ok && response.status !== 409) {
          throw new Error(body.error || 'Request failed.');
        }
        return { status: response.status, body: body };
      });
    }
    function shortApi(payload) {
      return toolApi('/api/short-links-tool.php', payload);
    }
    function resetApi(payload) {
      return toolApi('/api/short-links-reset.php', payload);
    }
    (function setupForgotPassword() {
      var link = document.getElementById('forgotLink');
      var form = document.getElementById('forgotForm');
      var message = document.getElementById('forgotMessage');
      if (!link || !form || !message) return;
      link.addEventListener('click', function(event) {
        event.preventDefault();
        form.hidden = !form.hidden;
      });
      form.addEventListener('submit', function(event) {
        event.preventDefault();
        message.textContent = 'Sending...';
        message.style.color = '#666';
        resetApi({ action: 'request', email: document.getElementById('forgotEmail').value })
          .then(function() {
            message.textContent = 'If that address is authorized, a reset link is on its way. Check the inbox (and spam folder).';
            message.style.color = '#27734a';
            form.reset();
          })
          .catch(function(error) {
            message.textContent = error.message;
            message.style.color = '#c0392b';
          });
      });
    })();
    (function setupPasswordReset() {
      var form = document.getElementById('resetForm');
      var message = document.getElementById('resetMessage');
      if (!form || !message) return;
      form.addEventListener('submit', function(event) {
        event.preventDefault();
        var next = document.getElementById('resetNew').value;
        var confirmValue = document.getElementById('resetConfirm').value;
        if (next !== confirmValue) {
          message.textContent = 'The new passwords do not match.';
          message.style.color = '#c0392b';
          return;
        }
        message.textContent = 'Updating...';
        message.style.color = '#666';
        resetApi({
          action: 'confirm',
          token: window.location.search.replace(/^.*[?&]reset=/, '').replace(/&.*$/, ''),
          new_password: next
        }).then(function() {
          message.textContent = 'Password updated. You can now sign in with the new password.';
          message.style.color = '#27734a';
          setTimeout(function() { window.location.assign('/short-links'); }, 1500);
        }).catch(function(error) {
          message.textContent = error.message;
          message.style.color = '#c0392b';
        });
      });
    })();
    function shortDestinationMessage(text, error) {
      var node = document.getElementById('shortDestinationResult');
      if (!node) return;
      node.textContent = text;
      node.style.color = error ? '#c0392b' : '#27734a';
    }
    function shortCopy(value, button) {
      function copied() {
        var status = document.getElementById('shortCopyStatus');
        if (status) status.textContent = 'Copied to clipboard.';
        if (!button) return;
        button.classList.add('is-copied');
        setTimeout(function() { button.classList.remove('is-copied'); }, 900);
      }
      function legacyCopy() {
        var area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (error) {}
        document.body.removeChild(area);
        if (ok) copied();
        else window.prompt('Copy this link:', value);
      }
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(value).then(copied).catch(legacyCopy);
      } else {
        legacyCopy();
      }
    }
    function shortPermanentlyDelete(id, code) {
      if (!confirm('Permanently delete ' + code + ' and its open-event data? This cannot be undone.')) return;
      shortApi({ action: 'permanent-delete', id: id, confirmation: 'DELETE ' + code })
        .then(function() { location.reload(); })
        .catch(function(error) { alert(error.message); });
    }
    (function setupShortLinkSelection() {
      var selectAll = document.getElementById('shortSelectAll');
      var exportButton = document.getElementById('shortExport');
      var boxes = Array.prototype.slice.call(document.querySelectorAll('.short-row-select'));
      var lastSelected = -1;
      function selectedRows() {
        var chosen = boxes.filter(function(box) { return box.checked; });
        return (chosen.length ? chosen : boxes).map(function(box) { return box.closest('tr'); });
      }
      function syncSelection() {
        if (!selectAll) return;
        selectAll.checked = boxes.length > 0 && boxes.every(function(box) { return box.checked; });
        selectAll.indeterminate = boxes.some(function(box) { return box.checked; }) && !selectAll.checked;
      }
      if (selectAll) {
        selectAll.addEventListener('change', function() {
          boxes.forEach(function(box) { box.checked = selectAll.checked; });
          syncSelection();
        });
      }
      boxes.forEach(function(box, index) {
        box.addEventListener('click', function(event) {
          if (event.shiftKey && lastSelected >= 0) {
            var low = Math.min(lastSelected, index);
            var high = Math.max(lastSelected, index);
            for (var cursor = low; cursor <= high; cursor++) boxes[cursor].checked = box.checked;
          }
          lastSelected = index;
          syncSelection();
        });
      });
      if (exportButton) {
        exportButton.addEventListener('click', function() {
          function csvCell(value) {
            value = String(value || '');
            if (/^[=+\-@]/.test(value)) value = "'" + value;
            return '"' + value.replace(/"/g, '""') + '"';
          }
          var lines = ['Short link,Target,Label,Campaign'].concat(
            selectedRows().map(function(row) {
              return [
                row.dataset.shortLink,
                row.dataset.shortTarget,
                row.dataset.shortLabel,
                row.dataset.shortCampaign
              ].map(csvCell).join(',');
            })
          );
          var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
          var anchor = document.createElement('a');
          anchor.href = URL.createObjectURL(blob);
          anchor.download = 'kssmi-short-links.csv';
          anchor.click();
          setTimeout(function() { URL.revokeObjectURL(anchor.href); }, 0);
        });
      }
    })();
    (function setupPasswordChange() {
      var toggle = document.getElementById('shortPasswordToggle');
      var panel = document.getElementById('shortPasswordPanel');
      var form = document.getElementById('shortPasswordForm');
      var message = document.getElementById('shortPasswordMessage');
      if (!toggle || !panel || !form || !message) return;
      toggle.addEventListener('click', function() {
        panel.hidden = !panel.hidden;
      });
      form.addEventListener('submit', function(event) {
        event.preventDefault();
        var current = document.getElementById('shortPasswordCurrent').value;
        var next = document.getElementById('shortPasswordNew').value;
        var confirmValue = document.getElementById('shortPasswordConfirm').value;
        if (next !== confirmValue) {
          message.textContent = 'The new passwords do not match.';
          message.style.color = '#c0392b';
          return;
        }
        message.textContent = 'Updating...';
        message.style.color = '#666';
        shortApi({ action: 'change-password', current_password: current, new_password: next })
          .then(function() {
            message.textContent = 'Password updated. Use it on your next login.';
            message.style.color = '#27734a';
            form.reset();
          })
          .catch(function(error) {
            message.textContent = error.message;
            message.style.color = '#c0392b';
          });
      });
    })();
    (function setupShortLinkBatchDelete() {
      var deleteButton = document.getElementById('shortDeleteSelected');
      if (!deleteButton) return;
      deleteButton.addEventListener('click', function() {
        var selected = Array.prototype.slice.call(document.querySelectorAll('.short-row-select'))
          .filter(function(box) { return box.checked; });
        if (selected.length === 0) {
          alert('Tick the checkbox next to the links you want to delete first.');
          return;
        }
        var items = selected.map(function(box) {
          var row = box.closest('tr');
          return { id: Number(box.dataset.shortId || 0), code: (row.dataset.shortLink || '').split('/').pop() };
        }).filter(function(item) { return item.id > 0 && item.code; });
        if (items.length === 0) {
          alert('Could not read the selected links. Please refresh the page and try again.');
          return;
        }
        if (!confirm(
          'Permanently delete ' + items.length + ' link' + (items.length === 1 ? '' : 's') +
          ' (' + items.map(function(item) { return item.code; }).join(', ') + ') and all open-event data? This cannot be undone.'
        )) return;
        deleteButton.disabled = true;
        var failures = [];
        var done = 0;
        items.forEach(function(item) {
          shortApi({ action: 'permanent-delete', id: item.id, confirmation: 'DELETE ' + item.code })
            .catch(function(error) { failures.push(item.code + ': ' + error.message); })
            .then(function() {
              done += 1;
              if (done === items.length) {
                if (failures.length) alert('Some links could not be deleted:\n' + failures.join('\n'));
                location.reload();
              }
            });
        });
      });
    })();
    (function setupShortLinkForms() {
      var destinationForm = document.getElementById('shortDestinationForm');
      var distributionForm = document.getElementById('shortDistributionForm');
      if (!destinationForm || !distributionForm) return;
      destinationForm.addEventListener('submit', function(event) {
        event.preventDefault();
        shortDestinationMessage('Checking destination...');
        shortApi({ action: 'destination', target_url: document.getElementById('shortTarget').value })
          .then(function(response) {
            var destination = response.body.destination;
            if (!destination) throw new Error('No destination returned.');
            var count = Number(response.body.distribution_count || 0);
            if (response.status === 409 && count > 0) {
              shortDestinationMessage(
                'This destination is already registered with ' + count + ' existing link' + (count === 1 ? '' : 's') +
                ' (possibly from other accounts). You can still create your own link below.'
              );
            } else if (response.status === 409) {
              shortDestinationMessage(
                'This destination is registered, but has no distribution link yet. Create one below.'
              );
            } else {
              shortDestinationMessage('Destination registered. Create its first distribution link below.');
            }
            document.getElementById('shortDestinationId').value = destination.id;
            distributionForm.hidden = false;
            distributionForm.style.display = 'flex';
          })
          .catch(function(error) { shortDestinationMessage(error.message, true); });
      });
      distributionForm.addEventListener('submit', function(event) {
        event.preventDefault();
        shortApi({
          action: 'distribution',
          destination_id: document.getElementById('shortDestinationId').value,
          label: document.getElementById('shortLabel').value,
          campaign: document.getElementById('shortCampaign').value,
          recipient_ref: document.getElementById('shortRecipient').value
        }).then(function(response) {
          var shortUrl = 'https://kssmi.com/' + response.body.link.code;
          shortDestinationMessage('Created ' + shortUrl);
          shortCopy(shortUrl);
          setTimeout(function() { location.reload(); }, 500);
        }).catch(function(error) { shortDestinationMessage(error.message, true); });
      });
    })();
    </script>
    <?php if ($isAuthenticated && $shortLinkMapVisible): ?>
    <script src="/vendor/short-link-map/leaflet.js"></script>
    <script src="/vendor/short-link-map/topojson-client.min.js"></script>
    <script>
    (function setupShortLinkMap() {
      var node = document.getElementById('shortLinkMap');
      if (!node || !window.L || !window.topojson) return;
      var rows;
      try { rows = JSON.parse(node.dataset.locations || '[]'); } catch (error) { return; }
      var map = L.map(node, {
        scrollWheelZoom: true,
        worldCopyJump: false,
        zoomControl: false,
        attributionControl: false,
        zoomSnap: 0.25,
        zoomDelta: 0.5,
        maxBoundsViscosity: 1
      });
      function unwrapRing(ring) {
        var output = [];
        ring.forEach(function(point, index) {
          var longitude = point[0];
          if (index) {
            var previous = output[index - 1][0];
            while (longitude - previous > 180) longitude -= 360;
            while (longitude - previous < -180) longitude += 360;
          }
          output.push([longitude, point[1]]);
        });
        return output;
      }
      function unwrapFeature(feature) {
        var geometry = feature.geometry;
        if (geometry.type === 'Polygon') geometry.coordinates = geometry.coordinates.map(unwrapRing);
        if (geometry.type === 'MultiPolygon') {
          geometry.coordinates = geometry.coordinates.map(function(polygon) {
            return polygon.map(unwrapRing);
          });
        }
        return feature;
      }
      fetch('/vendor/short-link-map/countries-110m.json', { cache: 'force-cache' })
        .then(function(response) {
          if (!response.ok) throw new Error('Map data request failed.');
          return response.json();
        })
        .then(function(world) {
          var features = topojson.feature(world, world.objects.countries).features
            .filter(function(feature) { return String(feature.id) !== '010' && String(feature.id) !== '260'; })
            .map(unwrapFeature);
          var countries = L.geoJSON(
            { type: 'FeatureCollection', features: features },
            {
              style: {
                color: '#9aa0a6',
                weight: 0.8,
                fillColor: '#f7f7f5',
                fillOpacity: 1,
                opacity: 1
              },
              interactive: false
            }
          ).addTo(map);
          var bounds = countries.getBounds();
          map.fitBounds(bounds, { padding: [6, 6], animate: false });
          map.setMinZoom(map.getZoom());
          map.setMaxZoom(7);
          map.setMaxBounds(bounds.pad(0.05));
          rows.forEach(function(row) {
            L.circleMarker([Number(row.lat), Number(row.lng)], {
              radius: Math.min(14, 5 + Math.sqrt(Number(row.opens) || 1) * 2),
              color: '#fff',
              weight: 2,
              fillColor: '#1a73e8',
              fillOpacity: 0.9
            }).bindTooltip(row.country + ' - ' + row.opens + ' opens', { direction: 'top' }).addTo(map);
          });
          setTimeout(function() {
            map.invalidateSize(false);
            map.fitBounds(bounds, { padding: [6, 6], animate: false });
            map.setMinZoom(map.getZoom());
          }, 0);
        })
        .catch(function() { node.textContent = 'Map data could not be loaded.'; });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
