<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/short-link-store.php';
require_once dirname(__DIR__) . '/private/rate-limit.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow', true);
$code = (string)($_GET['code'] ?? '');
try { $link = short_link_find_active($code); } catch (Throwable $e) { $link = null; error_log('KSSMI short-link lookup failed'); }
if (!$link) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'Not found.'; exit; }
try {
    // This strict public analytics budget deliberately bypasses neither the
    // administrator whitelist nor the redirect itself.
    if (kssmi_check_rate_limit_identity('short-link-open-event', kssmi_get_client_ip(), 60, 3600, 1)) {
        short_link_record_open((int)$link['id'], (string)$link['recipient_ref'], short_link_is_bot((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), kssmi_get_trusted_cloudflare_country() ?? '');
    }
} catch (Throwable $e) { error_log('KSSMI short-link event write failed'); }
header('Location: ' . $link['target_url'], true, 302); exit;
