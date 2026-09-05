<?php
/**
 * KSSMI Contact Form Handler
 * Sends emails via SMTP (Gmail Workspace)
 * Protected by Cloudflare Turnstile
 * Includes email logging functionality
 *
 * FEATURES:
 * - SMTP email sending via PHPMailer
 * - Cloudflare Turnstile anti-spam
 * - Email logging to JSON file (like WordPress SMTP plugins)
 * - Debug mode for testing
 * - CORS support for local development
 */

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

// Load private credentials (file lives outside public_html)
$_privateConfigPath = dirname(__DIR__) . '/private_config.php';
if (file_exists($_privateConfigPath)) {
    $_privateCfg = require $_privateConfigPath;
} else {
    error_log('KSSMI: private_config.php not found at ' . $_privateConfigPath);
    $_privateCfg = ['smtp_pass' => '', 'turnstile_secret' => ''];
}

// CORS Headers for local development
$allowedOrigins = [
    'http://localhost:4321',
    'http://localhost:4324',
    'http://localhost:4325',
    'http://127.0.0.1:4321',
    'http://127.0.0.1:4324',
    'http://127.0.0.1:4325',
    'http://[::1]:4321',
    'http://[::1]:4324',
    'http://[::1]:4325',
    'https://kssmi.com',
    'https://www.kssmi.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Origin not allowed']);
    exit;
}
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Prevent direct access without POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Reject oversized form bodies before values are copied into logs, email, or VJT.
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072) {
    http_response_code(413);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Payload too large']);
    exit;
}

// Rate limit: 2 form submissions per IP per 60s (prevents mail-bomb attacks
// that would exhaust Gmail SMTP quota and blacklist our sender IP)
require_once dirname(__DIR__) . '/private/rate-limit.php';
require_once dirname(__DIR__) . '/private/email-log-store.php';
$emailLogPath = dirname(__DIR__) . '/email_data/email-logs.json';
if (kssmi_email_logs_cutover_is_active($emailLogPath)) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    echo json_encode([
        'success' => false,
        'message' => 'The inquiry service is being updated. Please try again in one minute.',
    ]);
    exit;
}
if (!checkRateLimit('send-mail', 2, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Too many requests. Please wait 60 seconds before trying again.'
    ]);
    exit;
}

// ============================================
// CONFIGURATION
// ============================================

$config = [
    // Email Settings
    'to_email' => 'sales@kssmi.com',
    'to_name' => 'KSSMI Sales Team',
    'from_email' => 'sales@kssmi.com',
    'from_name' => 'Kssmi Eyewear',

    // Gmail Workspace SMTP Settings
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'user' => 'sales@kssmi.com',
        'pass' => $_privateCfg['smtp_pass'],
        'secure' => 'tls',
    ],

    // Cloudflare Turnstile
    'turnstile_secret' => $_privateCfg['turnstile_secret'],

    // Debug Mode - Set to true to skip Turnstile (for localhost testing)
    'debug_mode' => false,  // Set to true only for local testing

    // Email Logging
    'log_enabled' => true,
    'log_file' => $emailLogPath,

];

// ============================================
// EMAIL LOGGING FUNCTIONS
// ============================================

function logEmail(
    $config,
    $data,
    $status,
    $message = '',
    $error = '',
    $visitorIP = null,
    $visitorCountry = null,
    $securityState = 'unverified',
    $failureType = null
) {
    if (!$config['log_enabled']) return;

    // Use provided IP or fall back to server detection
    $ipToLog = $visitorIP ?? getRealIP();

    // Store form details for potential resend
    $formDetails = $data['details'] ?? '';

    try {
        $logId = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $logId = hash('sha256', uniqid('kssmi-log-', true));
    }

    $deliveryOutcome = null;
    if ($status === 'success') {
        $deliveryOutcome = 'success';
    } elseif ($failureType === 'delivery_uncertain') {
        $deliveryOutcome = 'uncertain';
    } elseif ($failureType === 'delivery') {
        $deliveryOutcome = 'definite_failure';
    }

    $logEntry = [
        'id' => $logId,
        'timestamp' => date('Y-m-d H:i:s T'),
        'unix_time' => time(),
        'status' => $status, // 'success', 'failed', 'pending'
        'email' => [
            'to' => $config['to_email'],
            'from' => $config['from_email'],
            'reply_to' => $data['email'] ?? '',
            'subject' => ($data['name'] ?? 'Unknown') . " - Kssmi Eyewear",
        ],
        'form_data' => [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
            'details' => $formDetails,
            'product_name' => $data['product_name'] ?? '',
            'product_sku' => $data['product_sku'] ?? '',
            'source' => $data['source'] ?? '',
            'language' => $data['language'] ?? '',
            'product_url' => $data['product_url'] ?? '',
        ],
        'message' => $message,
        'error' => $error,
        'security_state' => $securityState,
        'security_verified' => $securityState === 'verified',
        'failure_type' => is_string($failureType) && $failureType !== '' ? $failureType : null,
        'delivery_outcome' => $deliveryOutcome,
        'ip_address' => $ipToLog,
        'country' => $visitorCountry ?? 'Unknown',
        'user_agent' => vjt_clip((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 512),
    ];

    $result = kssmi_email_logs_mutate($config['log_file'], function($logs) use ($logEntry) {
        array_unshift($logs, $logEntry);
        return array_slice($logs, 0, 1000);
    });
    if (!$result['ok']) {
        error_log('KSSMI Email Logs: unable to append log entry; reason=' . ($result['error'] ?? 'unknown'));
    }
}

// VJT Tracking
require_once __DIR__ . '/api/vjt-helpers.php';
require_once dirname(__DIR__) . '/private/vjt-event-auth.php';

function recordInquiryOutcome($config, $data, $status, $visitorIP, $visitorCountry) {
    // Tracking is strictly best-effort. No VJT/auth/storage failure may change
    // the already determined SMTP outcome returned to the customer.
    try {
    $hasAnalyticsConsent = ($data['vjt_analytics_consent'] ?? '') === '1';
    $visitorId = trim($data['vjt_visitor_id'] ?? '');
    $sessionId = trim($data['vjt_session_id'] ?? '');
    $journeyStep = is_numeric($data['vjt_journey_step'] ?? null)
        ? min(10000, max(0, (int)$data['vjt_journey_step'])) : 0;
    $submissionEventId = trim((string)($data['vjt_submission_event_id'] ?? ''));
    if (!preg_match('/^vjtev_[A-Za-z0-9_-]{8,80}$/', $submissionEventId)) {
        $submissionEventId = '';
    }
    if (!$hasAnalyticsConsent) {
        $visitorId = '';
        $sessionId = '';
        $journeyStep = 0;
        $submissionEventId = '';
    }
    try {
        $identity = kssmi_vjt_identity_from_request();
    } catch (Throwable $identityError) {
        error_log('VJT inquiry identity error: ' . $identityError->getMessage());
        $identity = null;
    }
    $verifiedIdentityVisitorId = '';
    $verifiedIdentitySessionId = '';
    if ($identity === null
        || !hash_equals((string)$identity['visitor_id'], $visitorId)
        || !hash_equals((string)$identity['session_id'], $sessionId)) {
        $visitorId = '';
        $sessionId = '';
        $journeyStep = 0;
        $submissionEventId = '';
    } else {
        // Keep the proven browser identity for canonical deduplication even if
        // the optional Journey row has not reached SQLite yet and attribution
        // is therefore stored as unlinked below.
        $verifiedIdentityVisitorId = $visitorId;
        $verifiedIdentitySessionId = $sessionId;
    }
    $submitPage = $data['vjt_submit_page'] ?? '';
    if (empty($submitPage)) {
        $submitPage = 'https://kssmi.com' . ($data['product_url'] ?? '');
    }

    $siteLanguage = strtoupper(trim((string)($data['language'] ?? '')));
    $knownLanguages = ['EN','IT','ES','FR','DE','PT','RU','JA','TR','AR','KO','ZH','HI','VI','JV','MS','TG'];
    if (!in_array($siteLanguage, $knownLanguages, true)) {
        $siteLanguage = 'EN';
    }

    $productSku = vjt_contact_token($data['product_sku'] ?? '', 80);
    if ($productSku === '') {
        $productPath = vjt_contact_page_path($data['product_url'] ?? '');
        $segments = array_values(array_filter(explode('/', trim($productPath, '/')), 'strlen'));
        $productIndex = array_search('product', $segments, true);
        if ($productIndex !== false && !empty($segments[$productIndex + 1])) {
            $productSku = vjt_contact_token($segments[$productIndex + 1], 80);
        }
    }

    // The business outcome is authoritative and consent-independent. It is
    // intentionally stored without IP, UA, geo, referrer, UTM or path history.
    // Linkage is optional enrichment, so require an existing visitor/session
    // pair and strip it for excluded staff/test traffic. The Core event itself
    // is still retained because the business email outcome remains real.
    try {
        vjt_data_init();
        $resolvedLink = vjt_ip_is_excluded($visitorIP)
            ? ['valid' => false, 'journey_step' => 0]
            : vjt_resolve_analytics_link($visitorId, $sessionId, $journeyStep, $submitPage);
        if (empty($resolvedLink['valid'])) {
            $visitorId = '';
            $sessionId = '';
            $journeyStep = 0;
        } else {
            $journeyStep = (int)($resolvedLink['journey_step'] ?? 0);
        }
        $canonicalCorrelation = $submissionEventId !== ''
            ? implode('|', [
                $status,
                $verifiedIdentityVisitorId,
                $verifiedIdentitySessionId,
                $submissionEventId,
            ])
            : bin2hex(random_bytes(16));
        vjt_add_verified_contact_event([
            // One browser submission lifecycle maps to one authoritative Core
            // result. The server-only namespace and keyed derivation prevent a
            // public event from pre-claiming the canonical row identifier.
            'event_id' => 'vjtcv_' . hash_hmac(
                'sha256',
                'inquiry-outcome|' . $canonicalCorrelation,
                kssmi_vjt_event_auth_secret()
            ),
            'channel' => 'inquiry',
            'event_type' => $status === 'success' ? 'submission_success' : 'submission_error',
            'status' => $status === 'success' ? 'success' : 'error',
            'page_path' => $submitPage,
            'placement' => 'inquiry-form',
            'product_sku' => $productSku,
            'site_language' => $siteLanguage,
            'vjt_visitor_id' => $visitorId,
            'vjt_session_id' => $sessionId,
            'journey_step' => $journeyStep,
        ]);
    } catch (Throwable $e) {
        error_log('Contact Core inquiry record error: ' . $e->getMessage());
    }

    // Journey enrichment remains analytics-consent gated: without both real
    // browser VJT IDs, do not create synthetic visitor/session records.
    if (!preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $visitorId)
        || !preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $sessionId)) {
        return;
    }

    try {
        vjt_data_init();

        $ua = vjt_clip((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 512);
        vjt_upsert_visitor([
            'visitor_id'    => $visitorId,
            'first_ip'      => $visitorIP,
            'country'       => $visitorCountry,
            'user_agent'    => $ua,
            'browser'       => vjt_detect_browser($ua),
            'device_type'   => vjt_detect_device($ua),
            'site_language' => $siteLanguage,
        ]);

        vjt_upsert_session([
            'session_id'   => $sessionId,
            'visitor_id'   => $visitorId,
            'ip'           => $visitorIP,
            'country'      => $visitorCountry,
            'referrer'     => $data['vjt_referrer'] ?? '',
            'landing_url'  => !empty($data['vjt_landing_url']) ? $data['vjt_landing_url'] : $submitPage,
            'landing_title' => !empty($data['vjt_landing_title']) ? $data['vjt_landing_title'] : ($data['vjt_submit_title'] ?? 'KSSMI Inquiry'),
            'utm_source'   => $data['vjt_utm_source'] ?? '',
            'utm_medium'   => $data['vjt_utm_medium'] ?? '',
            'utm_campaign' => $data['vjt_utm_campaign'] ?? '',
            'utm_content'  => $data['vjt_utm_content'] ?? '',
            'utm_term'     => $data['vjt_utm_term'] ?? '',
        ]);

        $snapshot = json_decode((string)($data['vjt_path_snapshot'] ?? ''), true);
        if (is_array($snapshot)) {
            $cleanSnapshot = [];
            foreach (array_slice($snapshot, -20) as $item) {
                if (!is_array($item) || empty($item['url'])) continue;
                $snapshotUrl = vjt_safe_http_url($item['url']);
                if ($snapshotUrl === '') continue;
                $cleanSnapshot[] = [
                    'visitor_id' => $visitorId,
                    'url' => $snapshotUrl,
                    'title' => is_scalar($item['title'] ?? null) ? vjt_clip((string)$item['title'], 512) : '',
                    'visited_at' => is_scalar($item['visited_at'] ?? null) ? vjt_clip((string)$item['visited_at'], 64) : '',
                ];
            }
            vjt_sync_pageview_snapshot($sessionId, $cleanSnapshot);
        }

        vjt_add_submission([
            'visitor_id'   => $visitorId,
            'session_id'   => $sessionId,
            'form_plugin'  => 'kssmi-inquiry',
            'form_name'    => $data['product_name'] ?? 'Inquiry',
            'submit_page'  => $submitPage,
            'submit_title' => 'KSSMI Inquiry',
            'event_id'     => $submissionEventId,
            'status'       => $status,
            'ip'           => $visitorIP,
            'country'      => $visitorCountry,
        ]);
    } catch (Throwable $e) {
        error_log('VJT inquiry enrichment error: ' . $e->getMessage());
    }
    } catch (Throwable $e) {
        error_log('VJT inquiry tracking error: ' . $e->getMessage());
    }
}

function getRecentLogs($config, $limit = 50) {
    $result = kssmi_email_logs_read($config['log_file']);
    if (!$result['ok']) return [];
    return array_slice($result['logs'], 0, $limit);
}

// ============================================
// TURNSTILE VERIFICATION
// ============================================

function verifyTurnstileDetailed($token, $secret) {
    if (!is_string($token) || $token === '' || strlen($token) > 4096) {
        return [
            'ok' => false,
            'service_error' => false,
            'reason' => 'missing_or_invalid_token',
        ];
    }

    if (!is_string($secret) || $secret === '') {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'missing_secret',
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'curl_unavailable',
        ];
    }

    // Always use server-detected IP — never trust client-reported
    // (client IP was spoofable, see P1-4 of security-004)
    $ip = getRealIP();

    $data = [
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $ip
    ];

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($ch === false) {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'siteverify_init_failed',
        ];
    }
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'siteverify_transport_error',
        ];
    }

    if ($httpCode !== 200) {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'siteverify_http_error',
        ];
    }

    $result = json_decode($response, true);
    if (!is_array($result)) {
        return [
            'ok' => false,
            'service_error' => true,
            'reason' => 'siteverify_invalid_json',
        ];
    }

    if (($result['success'] ?? false) !== true) {
        $rawErrorCodes = $result['error-codes'] ?? [];
        $errorCodes = is_array($rawErrorCodes)
            ? array_values(array_filter($rawErrorCodes, 'is_string'))
            : [];
        $serviceErrorCodes = [
            'missing-input-secret',
            'invalid-input-secret',
            'internal-error',
        ];
        $serviceError = count(array_intersect($errorCodes, $serviceErrorCodes)) > 0;

        return [
            'ok' => false,
            'service_error' => $serviceError,
            'reason' => $serviceError ? 'siteverify_service_error' : 'token_rejected',
        ];
    }

    $hostname = strtolower((string)($result['hostname'] ?? ''));
    $allowedHostnames = ['kssmi.com', 'www.kssmi.com', 'localhost', '127.0.0.1'];
    if (!in_array($hostname, $allowedHostnames, true)) {
        return [
            'ok' => false,
            'service_error' => false,
            'reason' => 'hostname_mismatch',
        ];
    }

    if (!hash_equals('contact_form', (string)($result['action'] ?? ''))) {
        return [
            'ok' => false,
            'service_error' => false,
            'reason' => 'action_mismatch',
        ];
    }

    return [
        'ok' => true,
        'service_error' => false,
        'reason' => 'verified',
    ];
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function sanitize($input, $maxLength = 1024) {
    if (!is_scalar($input)) return '';
    $value = strip_tags(trim((string)$input));
    if (function_exists('mb_substr')) $value = mb_substr($value, 0, $maxLength);
    else $value = substr($value, 0, $maxLength);
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * HTML mail is an output sink. Legacy request values may already carry one
 * encoding layer, so normalize that layer and encode exactly once here.
 */
function kssmi_html_escape($value): string {
    if (!is_scalar($value)) return '';
    return htmlspecialchars(
        htmlspecialchars_decode((string)$value, ENT_QUOTES),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function sanitizeLocalPath($input) {
    $path = sanitize($input, 2048);
    if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || preg_match('/[\x00-\x1F\x7F]/', $path)) return '';
    return $path;
}

function requestString($value) {
    return is_scalar($value) ? (string)$value : '';
}

/**
 * Debug log for IP detection troubleshooting
 */
function debugIPLog($source, $data) {
    // P3-1 (N9): gate on debug_mode to avoid I/O on every production request.
    // The function still writes synchronously when explicitly enabled — only
    // when $config['debug_mode'] is true in the contact-form config.
    // Other call sites (outside of this file) won't have $GLOBALS['config']
    // populated, but they would just no-op (same as silent mode).
    if (empty($GLOBALS['config']['debug_mode'])) return;
    $logFile = dirname(__DIR__) . '/ip-debug.log';
    $entry = date('Y-m-d H:i:s') . " [$source]: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND);
}

/**
 * Get real visitor IP address
 * Handles Cloudflare proxy and other common proxy headers
 */
function getRealIP() {
    // private/rate-limit.php provides a trusted resolver: CF-Connecting-IP is
    // used only when REMOTE_ADDR belongs to a Cloudflare proxy range.
    $ip = function_exists('kssmi_get_client_ip') ? kssmi_get_client_ip() : 'unknown';
    debugIPLog('getRealIP-result', [
        'ip' => $ip,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    return $ip;
}

/**
 * Get country code from IP address
 * First tries Cloudflare's country header, then falls back to IP-API
 */
function getCountryFromIP($ip) {
    // Only a verified Cloudflare TCP peer may provide CF-IPCountry.
    $country = function_exists('kssmi_get_trusted_cloudflare_country')
        ? kssmi_get_trusted_cloudflare_country()
        : null;
    if ($country !== null) {
        debugIPLog('getCountryFromIP', ['source' => 'HTTP_CF_IPCOUNTRY', 'country' => $country]);
        return $country;
    }

    // Skip for localhost/private IPs
    if (in_array(strtolower($ip), ['127.0.0.1', '::1', 'unknown', 'localhost']) ||
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        debugIPLog('getCountryFromIP', ['source' => 'LOCAL', 'ip' => $ip]);
        return 'LOCAL';
    }

    // Fallback: Use ipapi.co (HTTPS — prevents MITM tampering of country code)
    // Note: ip-api.com (the previous service) only supports HTTPS on the paid Pro plan.
    // ipapi.co's free tier supports HTTPS single-IP lookups, which is enough for
    // a B2B site's per-inquiry geocoding (we hit this at most once per inquiry).
    $ch = curl_init("https://ipapi.co/{$ip}/json/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $httpCode === 200) {
        $data = json_decode($response, true);
        // ipapi.co returns ISO-2 country code in 'country_code' (ip-api.com used 'countryCode')
        $country = strtoupper(is_scalar($data['country_code'] ?? null) ? (string)$data['country_code'] : '');
        if (preg_match('/^[A-Z]{2}$/D', $country) === 1) {
            debugIPLog('getCountryFromIP', ['source' => 'IPAPI.CO', 'ip' => $ip, 'country' => $country]);
            return $country;
        }
    }

    debugIPLog('getCountryFromIP', ['source' => 'UNKNOWN', 'ip' => $ip]);
    return 'UNKNOWN';
}

/**
 * Generate unique inquiry ID
 */
function generateInquiryId() {
    return '#' . strtoupper(substr(uniqid(), -4));
}

function buildMarkdownEmail($data, $ip, $country, $inquiryId) {
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . $data['product_url'];

    return <<<MARKDOWN
# New Inquiry from KSSMI Website

---

## Contact Information

**Name:** {$data['name']}

**Email Address:** {$data['email']}

**Product Interest:** {$data['product_name']}

**SKU:** {$data['product_sku']}

---

## Here is the user details...

{$data['details']}

---

## Metadata

| Field | Value |
|-------|-------|
| **Time** | {$timestamp} |
| **Product Page** | {$source} |
| **Form Source** | {$data['source']} |
| **Language** | {$data['language']} |
| **IP** | {$ip} |
| **Country** | {$country} |
| **ID** | {$inquiryId} |

---

*This email was automatically generated from the KSSMI website contact form.*
MARKDOWN;
}

function buildHtmlEmail($data, $ip, $country, $inquiryId) {
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . $data['product_url'];
    $lang = $data['language'] ?? 'en';

    // Email translations
    $translations = [
        'en' => [
            'contactInfo' => 'Contact Information',
            'name' => 'Name',
            'email' => 'Email Address',
            'product' => 'Product Interest',
            'projectDetails' => 'Project Details',
            'metadata' => 'Metadata',
            'time' => 'Time',
            'source' => 'Source',
            'country' => 'Country',
            'footer' => 'This email was automatically generated from the KSSMI Eyewear contact form.',
        ],
        'it' => [
            'contactInfo' => 'Informazioni di Contatto',
            'name' => 'Nome',
            'email' => 'Indirizzo Email',
            'product' => 'Interesse Prodotto',
            'projectDetails' => 'Dettagli del Progetto',
            'metadata' => 'Metadati',
            'time' => 'Ora',
            'source' => 'Fonte',
            'country' => 'Paese',
            'footer' => 'Questa email è stata generata automaticamente dal modulo di contatto KSSMI Eyewear.',
        ],
        'es' => [
            'contactInfo' => 'Información de Contacto',
            'name' => 'Nombre',
            'email' => 'Dirección de Correo',
            'product' => 'Interés del Producto',
            'projectDetails' => 'Detalles del Proyecto',
            'metadata' => 'Metadatos',
            'time' => 'Hora',
            'source' => 'Fuente',
            'country' => 'País',
            'footer' => 'Este correo fue generado automáticamente desde el formulario de contacto de KSSMI Eyewear.',
        ],
        'fr' => [
            'contactInfo' => 'Informations de Contact',
            'name' => 'Nom',
            'email' => 'Adresse Email',
            'product' => 'Intérêt pour le Produit',
            'projectDetails' => 'Détails du Projet',
            'metadata' => 'Métadonnées',
            'time' => 'Heure',
            'source' => 'Source',
            'country' => 'Pays',
            'footer' => 'Cet email a été généré automatiquement depuis le formulaire de contact KSSMI Eyewear.',
        ],
        'de' => [
            'contactInfo' => 'Kontaktinformationen',
            'name' => 'Name',
            'email' => 'E-Mail-Adresse',
            'product' => 'Produktinteresse',
            'projectDetails' => 'Projektdetails',
            'metadata' => 'Metadaten',
            'time' => 'Zeit',
            'source' => 'Quelle',
            'country' => 'Land',
            'footer' => 'Diese E-Mail wurde automatisch vom KSSMI Eyewear Kontaktformular generiert.',
        ],
        'pt' => [
            'contactInfo' => 'Informações de Contato',
            'name' => 'Nome',
            'email' => 'Endereço de Email',
            'product' => 'Interesse no Produto',
            'projectDetails' => 'Detalhes do Projeto',
            'metadata' => 'Metadados',
            'time' => 'Hora',
            'source' => 'Fonte',
            'country' => 'País',
            'footer' => 'Este email foi gerado automaticamente pelo formulário de contato KSSMI Eyewear.',
        ],
        'ru' => [
            'contactInfo' => 'Контактная информация',
            'name' => 'Имя',
            'email' => 'Адрес электронной почты',
            'product' => 'Интерес к продукту',
            'projectDetails' => 'Детали проекта',
            'metadata' => 'Метаданные',
            'time' => 'Время',
            'source' => 'Источник',
            'country' => 'Страна',
            'footer' => 'Это письмо было автоматически создано формой связи KSSMI Eyewear.',
        ],
        'ja' => [
            'contactInfo' => '連絡先情報',
            'name' => '名前',
            'email' => 'メールアドレス',
            'product' => '製品への関心',
            'projectDetails' => 'プロジェクト詳細',
            'metadata' => 'メタデータ',
            'time' => '時間',
            'source' => 'ソース',
            'country' => '国',
            'footer' => 'このメールはKSSMI Eyewearのお問い合わせフォームから自動的に生成されました。',
        ],
        'tr' => [
            'contactInfo' => 'İletişim Bilgileri',
            'name' => 'İsim',
            'email' => 'E-posta Adresi',
            'product' => 'Ürün İlgi Alanı',
            'projectDetails' => 'Proje Detayları',
            'metadata' => 'Meta Veriler',
            'time' => 'Zaman',
            'source' => 'Kaynak',
            'country' => 'Ülke',
            'footer' => 'Bu e-posta KSSMI Eyewear iletişim formundan otomatik olarak oluşturulmuştur.',
        ],
        'ar' => [
            'contactInfo' => 'معلومات الاتصال',
            'name' => 'الاسم',
            'email' => 'البريد الإلكتروني',
            'product' => 'اهتمام المنتج',
            'projectDetails' => 'تفاصيل المشروع',
            'metadata' => 'البيانات الوصفية',
            'time' => 'الوقت',
            'source' => 'المصدر',
            'country' => 'البلد',
            'footer' => 'تم إنشاء هذا البريد الإلكتروني تلقائيًا من نموذج الاتصال KSSMI Eyewear.',
        ],
    ];

    $t = $translations[$lang] ?? $translations['en'];

    $name = kssmi_html_escape($data['name'] ?? '');
    $email = kssmi_html_escape($data['email'] ?? '');
    $productName = kssmi_html_escape($data['product_name'] ?? '');
    $productSku = kssmi_html_escape($data['product_sku'] ?? '');
    $details = kssmi_html_escape($data['details'] ?? '');
    $sourceHtml = kssmi_html_escape($source);
    $formSource = kssmi_html_escape($data['source'] ?? '');
    $language = kssmi_html_escape($data['language'] ?? '');
    $ipHtml = kssmi_html_escape($ip);
    $countryHtml = kssmi_html_escape($country);
    $inquiryIdHtml = kssmi_html_escape($inquiryId);
    $headerTitle = $name . ' - Kssmi';

    return "
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #8B7355 0%, #5D4E37 100%); color: white; padding: 30px; border-radius: 12px 12px 0 0; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 600; text-align: left; }
        .content { background: white; padding: 30px; border: 1px solid #e0e0e0; border-top: none; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 12px; font-weight: 600; color: #8B7355; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #8B7355; text-align: left; }
        .field { margin-bottom: 12px; text-align: left; }
        .field-label { font-size: 12px; color: #666; margin-bottom: 4px; }
        .field-value { font-size: 15px; color: #333; }
        .field-value a { color: #8B7355; text-decoration: none; }
        .details-box { background: #f8f7f5; padding: 20px; border-radius: 8px; border-left: 4px solid #8B7355; white-space: pre-wrap; font-size: 14px; line-height: 1.7; text-align: left; }
        .meta-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .meta-table td { padding: 10px 0; border-bottom: 1px solid #eee; text-align: left; }
        .meta-table td:first-child { color: #666; width: 100px; }
        .meta-table td:last-child { color: #333; font-weight: 500; }
        .inquiry-id { background: #8B7355; color: white; padding: 4px 10px; border-radius: 4px; font-family: monospace; font-size: 12px; }
        .footer { padding: 20px; color: #888; font-size: 12px; background: #fafafa; border-radius: 0 0 12px 12px; border: 1px solid #e0e0e0; border-top: none; text-align: left; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>{$headerTitle}</h1>
        </div>
        <div class='content'>
            <div class='section'>
                <div class='section-title'>{$t['contactInfo']}</div>
                <div class='field'>
                    <div class='field-label'>{$t['name']}</div>
                    <div class='field-value'>{$name}</div>
                </div>
                <div class='field'>
                    <div class='field-label'>{$t['email']}</div>
                    <div class='field-value'><a href='mailto:{$email}'>{$email}</a></div>
                </div>
                <div class='field'>
                    <div class='field-label'>{$t['product']}</div>
                    <div class='field-value'>{$productName}</div>
                </div>
                <div class='field'>
                    <div class='field-label'>SKU</div>
                    <div class='field-value'>{$productSku}</div>
                </div>
            </div>
            <div class='section'>
                <div class='section-title'>{$t['projectDetails']}</div>
                <div class='details-box'>{$details}</div>
            </div>
            <div class='section'>
                <div class='section-title'>{$t['metadata']}</div>
                <table class='meta-table'>
                    <tr><td>{$t['time']}</td><td>{$timestamp}</td></tr>
                    <tr><td>{$t['source']}</td><td><a href='{$sourceHtml}' style='color: #8B7355;'>{$sourceHtml}</a></td></tr>
                    <tr><td>Form Source</td><td>{$formSource}</td></tr>
                    <tr><td>Language</td><td>{$language}</td></tr>
                    <tr><td>IP</td><td>{$ipHtml}</td></tr>
                    <tr><td>{$t['country']}</td><td>{$countryHtml}</td></tr>
                    <tr><td>ID</td><td><span class='inquiry-id'>{$inquiryIdHtml}</span></td></tr>
                </table>
            </div>
        </div>
        <div class='footer'>
            <p>{$t['footer']}</p>
        </div>
    </div>
</body>
</html>";
}

function buildTextEmail($data, $ip, $country, $inquiryId) {
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . $data['product_url'];
    $name = $data['name'];

    return "
{$name} - Kssmi
================

Name: {$data['name']}
Email Address: {$data['email']}
Product Interest: {$data['product_name']}
SKU: {$data['product_sku']}

PROJECT DETAILS:
----------------
{$data['details']}

METADATA:
---------
Time: {$timestamp}
Source: {$source}
Form Source: {$data['source']}
Language: {$data['language']}
IP: {$ip}
Country: {$country}
ID: {$inquiryId}

---
This email was automatically generated from the KSSMI Eyewear contact form.
";
}

/**
 * Build the short, plain-text confirmation that is sent to an inquiry sender.
 * It deliberately excludes internal IDs and product references so the reply
 * reads as a simple conversation starter in every site language.
 */
function buildInquiryAutoReply($data) {
    $translations = [
        'en' => ['subject' => 'We received your inquiry — Kssmi Eyewear', 'greeting' => 'Hello {name},', 'received' => 'We have received your inquiry. Reply directly to this email to continue the conversation, or chat with us on WhatsApp:', 'follow_up' => 'Our team will follow up within one business day.', 'signoff' => 'Best regards,', 'team' => 'Kssmi Eyewear Team'],
        'it' => ['subject' => 'Abbiamo ricevuto la tua richiesta — Kssmi Eyewear', 'greeting' => 'Ciao {name},', 'received' => 'Abbiamo ricevuto la tua richiesta. Rispondi direttamente a questa email per continuare la conversazione oppure contattaci su WhatsApp:', 'follow_up' => 'Il nostro team ti risponderà entro un giorno lavorativo.', 'signoff' => 'Cordiali saluti,', 'team' => 'Team Kssmi Eyewear'],
        'es' => ['subject' => 'Hemos recibido su consulta — Kssmi Eyewear', 'greeting' => 'Hola {name},', 'received' => 'Hemos recibido su consulta. Responda directamente a este correo para continuar la conversación o escríbanos por WhatsApp:', 'follow_up' => 'Nuestro equipo responderá en un día laborable.', 'signoff' => 'Saludos cordiales,', 'team' => 'Equipo Kssmi Eyewear'],
        'fr' => ['subject' => 'Nous avons reçu votre demande — Kssmi Eyewear', 'greeting' => 'Bonjour {name},', 'received' => 'Nous avons reçu votre demande. Répondez directement à cet e-mail pour poursuivre la conversation, ou contactez-nous sur WhatsApp :', 'follow_up' => 'Notre équipe vous répondra sous un jour ouvré.', 'signoff' => 'Cordialement,', 'team' => 'Équipe Kssmi Eyewear'],
        'de' => ['subject' => 'Wir haben Ihre Anfrage erhalten — Kssmi Eyewear', 'greeting' => 'Hallo {name},', 'received' => 'Wir haben Ihre Anfrage erhalten. Antworten Sie direkt auf diese E-Mail, um das Gespräch fortzusetzen, oder schreiben Sie uns über WhatsApp:', 'follow_up' => 'Unser Team meldet sich innerhalb eines Werktags.', 'signoff' => 'Freundliche Grüße,', 'team' => 'Kssmi Eyewear Team'],
        'pt' => ['subject' => 'Recebemos a sua solicitação — Kssmi Eyewear', 'greeting' => 'Olá {name},', 'received' => 'Recebemos a sua solicitação. Responda diretamente a este e-mail para continuar a conversa ou fale connosco pelo WhatsApp:', 'follow_up' => 'A nossa equipa responderá dentro de um dia útil.', 'signoff' => 'Com os melhores cumprimentos,', 'team' => 'Equipa Kssmi Eyewear'],
        'ru' => ['subject' => 'Мы получили ваш запрос — Kssmi Eyewear', 'greeting' => 'Здравствуйте, {name},', 'received' => 'Мы получили ваш запрос. Ответьте прямо на это письмо, чтобы продолжить общение, или напишите нам в WhatsApp:', 'follow_up' => 'Наша команда ответит в течение одного рабочего дня.', 'signoff' => 'С уважением,', 'team' => 'Команда Kssmi Eyewear'],
        'ja' => ['subject' => 'お問い合わせを受け付けました — Kssmi Eyewear', 'greeting' => '{name} 様', 'received' => 'お問い合わせを受け付けました。このメールに直接返信するか、WhatsAppでお問い合わせください。', 'follow_up' => '担当チームが1営業日以内にご連絡します。', 'signoff' => 'よろしくお願いいたします。', 'team' => 'Kssmi Eyewear チーム'],
        'tr' => ['subject' => 'Talebinizi aldık — Kssmi Eyewear', 'greeting' => 'Merhaba {name},', 'received' => 'Talebinizi aldık. Görüşmeye devam etmek için bu e-postayı doğrudan yanıtlayın veya WhatsApp üzerinden bize yazın:', 'follow_up' => 'Ekibimiz bir iş günü içinde size dönüş yapacaktır.', 'signoff' => 'Saygılarımızla,', 'team' => 'Kssmi Eyewear Ekibi'],
        'ar' => ['subject' => 'لقد تلقينا استفسارك — Kssmi Eyewear', 'greeting' => 'مرحباً {name}،', 'received' => 'لقد تلقينا استفسارك. يمكنك الرد مباشرةً على هذا البريد لمتابعة المحادثة أو مراسلتنا عبر واتساب:', 'follow_up' => 'سيتابع فريقنا معك خلال يوم عمل واحد.', 'signoff' => 'مع أطيب التحيات،', 'team' => 'فريق Kssmi Eyewear'],
        'ko' => ['subject' => '문의가 접수되었습니다 — Kssmi Eyewear', 'greeting' => '{name}님, 안녕하세요.', 'received' => '문의가 접수되었습니다. 대화를 계속하려면 이 이메일에 직접 회신하거나 WhatsApp으로 문의해 주세요:', 'follow_up' => '저희 팀이 영업일 기준 1일 이내에 연락드리겠습니다.', 'signoff' => '감사합니다.', 'team' => 'Kssmi Eyewear 팀'],
        'zh' => ['subject' => '我们已收到您的询盘 — Kssmi Eyewear', 'greeting' => '{name}，您好：', 'received' => '我们已收到您的询盘。您可以直接回复此邮件继续沟通，或通过 WhatsApp 联系我们：', 'follow_up' => '我们的团队将在一个工作日内跟进。', 'signoff' => '此致，', 'team' => 'Kssmi Eyewear 团队'],
        'hi' => ['subject' => 'हमें आपकी पूछताछ मिल गई है — Kssmi Eyewear', 'greeting' => 'नमस्ते {name},', 'received' => 'हमें आपकी पूछताछ मिल गई है। बातचीत जारी रखने के लिए इस ईमेल का सीधे उत्तर दें या WhatsApp पर हमें संदेश भेजें:', 'follow_up' => 'हमारी टीम एक कार्य दिवस के भीतर आपसे संपर्क करेगी।', 'signoff' => 'सादर,', 'team' => 'Kssmi Eyewear टीम'],
        'vi' => ['subject' => 'Chúng tôi đã nhận được yêu cầu của bạn — Kssmi Eyewear', 'greeting' => 'Xin chào {name},', 'received' => 'Chúng tôi đã nhận được yêu cầu của bạn. Hãy trả lời trực tiếp email này để tiếp tục trao đổi hoặc nhắn cho chúng tôi qua WhatsApp:', 'follow_up' => 'Đội ngũ của chúng tôi sẽ phản hồi trong một ngày làm việc.', 'signoff' => 'Trân trọng,', 'team' => 'Đội ngũ Kssmi Eyewear'],
        'jv' => ['subject' => 'Panjaluk sampeyan wis ditampa — Kssmi Eyewear', 'greeting' => 'Halo {name},', 'received' => 'Panjaluk sampeyan wis ditampa. Wangsulana email iki langsung kanggo nerusake obrolan utawa hubungi kami liwat WhatsApp:', 'follow_up' => 'Tim kami bakal nanggapi sajrone siji dina kerja.', 'signoff' => 'Salam,', 'team' => 'Tim Kssmi Eyewear'],
        'ms' => ['subject' => 'Kami telah menerima pertanyaan anda — Kssmi Eyewear', 'greeting' => 'Helo {name},', 'received' => 'Kami telah menerima pertanyaan anda. Balas terus e-mel ini untuk meneruskan perbualan atau hubungi kami melalui WhatsApp:', 'follow_up' => 'Pasukan kami akan menghubungi anda dalam satu hari bekerja.', 'signoff' => 'Salam hormat,', 'team' => 'Pasukan Kssmi Eyewear'],
        'tg' => ['subject' => 'Мо дархости шуморо қабул кардем — Kssmi Eyewear', 'greeting' => 'Салом {name},', 'received' => 'Мо дархости шуморо қабул кардем. Барои идомаи суҳбат ба ин нома мустақиман ҷавоб диҳед ё тавассути WhatsApp ба мо нависед:', 'follow_up' => 'Гурӯҳи мо дар давоми як рӯзи корӣ бо шумо тамос мегирад.', 'signoff' => 'Бо эҳтиром,', 'team' => 'Гурӯҳи Kssmi Eyewear'],
    ];

    $language = $data['language'] ?? 'en';
    $copy = $translations[$language] ?? $translations['en'];
    $name = trim((string)preg_replace('/[\r\n]+/u', ' ', (string)($data['name'] ?? '')));
    $greeting = str_replace('{name}', $name !== '' ? $name : 'there', $copy['greeting']);

    return [
        'subject' => $copy['subject'],
        'body' => implode("\n", [
            $greeting,
            '',
            $copy['received'],
            'https://wa.me/8613510532553',
            '',
            $copy['follow_up'],
            '',
            $copy['signoff'],
            $copy['team'],
            'sales@kssmi.com',
        ]),
        'recipient_name' => $name,
    ];
}

/** Send a best-effort plain-text confirmation without affecting the inquiry outcome. */
function sendInquiryAutoReply($config, $data) {
    $reply = buildInquiryAutoReply($data);
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['smtp']['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp']['user'];
    $mail->Password = $config['smtp']['pass'];
    $mail->SMTPSecure = $config['smtp']['secure'];
    $mail->Port = $config['smtp']['port'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 15;
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($data['email'], $reply['recipient_name']);
    $mail->addCC('sales@kssmi.com', 'Kssmi Eyewear Team');
    $mail->addReplyTo('sales@kssmi.com', 'Kssmi Eyewear Team');
    $mail->addCustomHeader('Auto-Submitted', 'auto-replied');
    $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
    $mail->isHTML(false);
    $mail->Subject = $reply['subject'];
    $mail->Body = $reply['body'];

    if (!$mail->send()) {
        throw new \RuntimeException('SMTP auto-reply send returned false');
    }
}

// ============================================
// MAIN PROCESSING
// ============================================

// Get and sanitize form data
// Note: client_ip and client_country are NO LONGER accepted from the client.
// They were spoofable and have been removed for security (P1-4 of security-004).
// The server always uses HTTP_CF_CONNECTING_IP via getRealIP() instead.
$formData = [
    'name' => sanitize($_POST['name'] ?? '', 160),
    'email' => sanitize($_POST['email'] ?? '', 254),
    'details' => sanitize($_POST['details'] ?? '', 10000),
    'source' => sanitize($_POST['source'] ?? 'unknown', 128),
    'product_url' => sanitizeLocalPath($_POST['product_url'] ?? ''),
    'product_name' => sanitize($_POST['product_name'] ?? 'N/A', 256),
    'product_sku' => strtoupper(sanitize($_POST['product_sku'] ?? '', 80)),
    'language' => sanitize($_POST['language'] ?? 'en', 8),
];
$allowedLanguages = ['en','it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
if (!in_array($formData['language'], $allowedLanguages, true)) $formData['language'] = 'en';

// VJT context is intentionally separate from business form fields. These are
// client hints only: IP/country remain server-derived, and IDs/URLs are
// validated/length-capped before they can reach SQLite.
$vjtData = [
    'vjt_analytics_consent' => requestString($_POST['vjt_analytics_consent'] ?? '') === '1' ? '1' : '0',
    'vjt_visitor_id' => trim(requestString($_POST['vjt_visitor_id'] ?? '')),
    'vjt_session_id' => trim(requestString($_POST['vjt_session_id'] ?? '')),
    'vjt_journey_step' => is_numeric($_POST['vjt_journey_step'] ?? null)
        ? min(10000, max(0, (int)$_POST['vjt_journey_step'])) : 0,
    'vjt_submission_event_id' => vjt_clip(trim(requestString($_POST['vjt_submission_event_id'] ?? '')), 96),
    'vjt_submit_page' => vjt_safe_http_url($_POST['vjt_submit_page'] ?? ''),
    'vjt_submit_title' => vjt_clip(trim(requestString($_POST['vjt_submit_title'] ?? '')), 512),
    'vjt_referrer' => vjt_safe_http_url($_POST['vjt_referrer'] ?? ''),
    'vjt_landing_url' => vjt_safe_http_url($_POST['vjt_landing_url'] ?? ''),
    'vjt_landing_title' => vjt_clip(trim(requestString($_POST['vjt_landing_title'] ?? '')), 512),
    'vjt_path_snapshot' => vjt_clip(trim(requestString($_POST['vjt_path_snapshot'] ?? '')), 32768),
    'vjt_utm_source' => vjt_clip(trim(requestString($_POST['vjt_utm_source'] ?? '')), 256),
    'vjt_utm_medium' => vjt_clip(trim(requestString($_POST['vjt_utm_medium'] ?? '')), 256),
    'vjt_utm_campaign' => vjt_clip(trim(requestString($_POST['vjt_utm_campaign'] ?? '')), 256),
    'vjt_utm_content' => vjt_clip(trim(requestString($_POST['vjt_utm_content'] ?? '')), 256),
    'vjt_utm_term' => vjt_clip(trim(requestString($_POST['vjt_utm_term'] ?? '')), 256),
];

// Browser fields are attribution hints, not identity proof. Discard all
// Journey linkage unless the HttpOnly server-issued identity matches exactly.
try {
    $vjtIdentity = kssmi_vjt_identity_from_request();
} catch (Throwable $vjtIdentityError) {
    // Event-auth state must never become a dependency of the inquiry email.
    error_log('VJT request identity error: ' . $vjtIdentityError->getMessage());
    $vjtIdentity = null;
}
if (($vjtData['vjt_analytics_consent'] ?? '') !== '1'
    || $vjtIdentity === null
    || !hash_equals((string)$vjtIdentity['visitor_id'], (string)$vjtData['vjt_visitor_id'])
    || !hash_equals((string)$vjtIdentity['session_id'], (string)$vjtData['vjt_session_id'])) {
    $vjtData['vjt_analytics_consent'] = '0';
    $vjtData['vjt_visitor_id'] = '';
    $vjtData['vjt_session_id'] = '';
    $vjtData['vjt_journey_step'] = 0;
    $vjtData['vjt_submission_event_id'] = '';
}

$turnstileToken = is_string($_POST['cf-turnstile-response'] ?? null) ? $_POST['cf-turnstile-response'] : '';

// Set JSON response header
header('Content-Type: application/json');

// Verify Turnstile (skip in debug mode)
$securityState = 'debug_bypass';
if (!$config['debug_mode']) {
    $turnstileResult = verifyTurnstileDetailed($turnstileToken, $config['turnstile_secret']);
    if (!$turnstileResult['ok']) {
        $isServiceError = $turnstileResult['service_error'] === true;
        $reason = (string)($turnstileResult['reason'] ?? 'unknown');
        // Ordinary bot/token rejections are intentionally silent. Logging one
        // line per rejection lets distributed spam grow the PHP error log.
        if ($isServiceError) {
            error_log('KSSMI Turnstile service/configuration error: reason=' . $reason);
        }

        http_response_code($isServiceError ? 503 : 403);
        echo json_encode([
            'success' => false,
            'errors' => [
                $isServiceError
                    ? 'Security verification is temporarily unavailable. Please try again.'
                    : 'Security verification failed. Please complete the captcha and try again.'
            ]
        ]);
        exit;
    }
    $securityState = 'verified';
} else {
    // Log that we're in debug mode
    error_log("KSSMI Form: Debug mode enabled - Turnstile verification skipped");
}

// Validate required business fields only after the security gate has passed.
$errors = [];

if (empty($formData['name'])) {
    $errors[] = 'Name is required';
}

if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email is required';
}

if (empty($formData['details'])) {
    $errors[] = 'Project details are required';
}

// Invalid form data is not an email delivery attempt and does not belong in Email Logs.
if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// Get visitor metadata — ALWAYS server-detected, never trust client-reported
// (P1-4 of security-004: client IP was spoofable via hidden form fields)
$visitorIP = getRealIP();
$visitorCountry = getCountryFromIP($visitorIP);
debugIPLog('IP-source', ['source' => 'server-detected', 'ip' => $visitorIP]);
debugIPLog('Country-source', ['source' => 'server-detected', 'country' => $visitorCountry]);

$inquiryId = generateInquiryId();

// ============================================
// SEND EMAIL VIA PHPMAILER
// ============================================

// Check if PHPMailer is installed
$phpmailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/';

if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
    // PHPMailer not installed - log and return error
    $errorMsg = 'PHPMailer not installed. Run: composer require phpmailer/phpmailer';
    logEmail(
        $config,
        $formData,
        'failed',
        'PHPMailer missing',
        $errorMsg,
        $visitorIP,
        $visitorCountry,
        $securityState,
        'delivery'
    );
    error_log("KSSMI Form Error: " . $errorMsg);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error. Please contact administrator.',
        'debug' => $config['debug_mode'] ? $errorMsg : null
    ]);
    exit;
}

require_once $phpmailerPath . 'Exception.php';
require_once $phpmailerPath . 'PHPMailer.php';
require_once $phpmailerPath . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$smtpSendStarted = false;

try {
    $mail = new PHPMailer(true);

    // Enable verbose debug output (disable in production)
    if ($config['debug_mode']) {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer [$level]: $str");
        };
    }

    // SMTP Configuration
    $mail->isSMTP();
    $mail->Host = $config['smtp']['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp']['user'];
    $mail->Password = $config['smtp']['pass'];
    $mail->SMTPSecure = $config['smtp']['secure'];
    $mail->Port = $config['smtp']['port'];
    $mail->CharSet = 'UTF-8';

    // Sender & Recipient
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email'], $config['to_name']);
    $mail->addReplyTo($formData['email'], $formData['name']);

    // Email Content
    $mail->isHTML(true);
    $mail->Subject = "{$formData['name']} - Kssmi Eyewear - {$inquiryId}";
    $vjtSummary = '';
    try {
        $vjtSummary = vjt_build_email_summary($vjtData['vjt_visitor_id'], $vjtData['vjt_session_id']);
    } catch (Throwable $e) {
        // Attribution must never interfere with a real inquiry email.
        error_log('VJT email summary error: ' . $e->getMessage());
    }
    $htmlBody = buildHtmlEmail($formData, $visitorIP, $visitorCountry, $inquiryId);
    if ($vjtSummary !== '') {
        $safeSummary = nl2br(htmlspecialchars($vjtSummary, ENT_QUOTES, 'UTF-8'));
        $summaryHtml = "<div style='margin:20px 30px;padding:14px;border:1px solid #ded6ca;background:#faf8f5;font:12px/1.6 Arial;color:#4a4137'><strong>Visitor Journey</strong><br>{$safeSummary}</div>";
        $htmlBody = str_replace('</body>', $summaryHtml . '</body>', $htmlBody);
    }
    $mail->Body = $htmlBody;
    $mail->AltBody = buildTextEmail($formData, $visitorIP, $visitorCountry, $inquiryId) . ($vjtSummary !== '' ? "\n\n" . $vjtSummary : '');

    // Set higher timeout for slow connections
    $mail->Timeout = 30;

    // Any exception after this point has an ambiguous delivery outcome: the
    // SMTP server may have accepted DATA before the acknowledgement was lost.
    $smtpSendStarted = true;
    if (!$mail->send()) {
        throw new PHPMailerException('SMTP send returned false');
    }

    // Log success
    logEmail(
        $config,
        $formData,
        'success',
        'Email sent successfully',
        '',
        $visitorIP,
        $visitorCountry,
        $securityState,
        null
    );

    // Record to VJT database
    recordInquiryOutcome($config, array_merge($formData, $vjtData), 'success', $visitorIP, $visitorCountry);

    // The confirmation is intentionally best-effort: an SMTP issue here must
    // never turn a delivered inquiry into a failed form submission.
    try {
        sendInquiryAutoReply($config, $formData);
    } catch (Throwable $autoReplyError) {
        error_log('KSSMI inquiry auto-reply failed: ' . $autoReplyError->getMessage());
    }

    // Determine redirect URL based on language
    $lang = $formData['language'] ?? 'en';
    $thankYouUrl = ($lang === 'en') ? '/thank-you/' : "/{$lang}/thank-you/";

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your inquiry has been sent successfully. We will contact you within 24 hours.',
        'redirect' => $thankYouUrl,
        'inquiry_id' => $inquiryId
    ]);

} catch (PHPMailerException $e) {
    $errorMsg = $e->getMessage();
    $failureType = $smtpSendStarted ? 'delivery_uncertain' : 'delivery';
    $failureMessage = $smtpSendStarted
        ? 'PHPMailer delivery outcome uncertain'
        : 'PHPMailer definite failure';
    logEmail(
        $config,
        $formData,
        'failed',
        $failureMessage,
        $errorMsg,
        $visitorIP,
        $visitorCountry,
        $securityState,
        $failureType
    );
    recordInquiryOutcome($config, array_merge($formData, $vjtData), 'error', $visitorIP, $visitorCountry);
    error_log("KSSMI Form Error (PHPMailer): " . $errorMsg);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $smtpSendStarted
            ? 'We could not confirm the delivery result. Please do not submit again; our team will check the mailbox.'
            : 'Sorry, there was an error sending your message. Please email us directly at sales@kssmi.com',
        'debug' => $config['debug_mode'] ? $errorMsg : null
    ]);
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    $failureType = $smtpSendStarted ? 'delivery_uncertain' : 'delivery';
    $failureMessage = $smtpSendStarted
        ? 'General delivery outcome uncertain'
        : 'General definite failure';
    logEmail(
        $config,
        $formData,
        'failed',
        $failureMessage,
        $errorMsg,
        $visitorIP,
        $visitorCountry,
        $securityState,
        $failureType
    );
    recordInquiryOutcome($config, array_merge($formData, $vjtData), 'error', $visitorIP, $visitorCountry);
    error_log("KSSMI Form Error: " . $errorMsg);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $smtpSendStarted
            ? 'We could not confirm the delivery result. Please do not submit again; our team will check the mailbox.'
            : 'Sorry, there was an error sending your message. Please email us directly at sales@kssmi.com',
        'debug' => $config['debug_mode'] ? $errorMsg : null
    ]);
}
