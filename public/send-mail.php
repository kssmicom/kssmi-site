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

// CORS Headers for local development
$allowedOrigins = [
    'http://localhost:4321',
    'http://localhost:4324',
    'http://localhost:4325',
    'http://127.0.0.1:4321',
    'http://127.0.0.1:4324',
    'http://127.0.0.1:4325',
    'https://kssmi.com',
    'https://www.kssmi.com',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
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

// ============================================
// CONFIGURATION
// ============================================

$config = [
    // Email Settings
    'to_email' => 'sales@kssmi.com',
    'to_name' => 'KSSMI Sales Team',
    'from_email' => 'kssmi@kssmi.com',
    'from_name' => 'KSSMI Website',

    // Gmail Workspace SMTP Settings
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'user' => 'kssmi@kssmi.com',
        'pass' => 'chnxqxdkktgehtlt',  // Gmail App Password
        'secure' => 'tls',
    ],

    // Cloudflare Turnstile
    'turnstile_secret' => '0x4AAAAAACGlmdz4wrPYmoFzuR_9vDknUOQ',

    // Debug Mode - Set to true to skip Turnstile (for localhost testing)
    'debug_mode' => true,  // Change to false in production!

    // Email Logging
    'log_enabled' => true,
    'log_file' => __DIR__ . '/email-logs.json',
];

// ============================================
// EMAIL LOGGING FUNCTIONS
// ============================================

function logEmail($config, $data, $status, $message = '', $error = '') {
    if (!$config['log_enabled']) return;

    $logEntry = [
        'id' => uniqid(),
        'timestamp' => date('Y-m-d H:i:s T'),
        'unix_time' => time(),
        'status' => $status, // 'success', 'failed', 'pending'
        'email' => [
            'to' => $config['to_email'],
            'from' => $config['from_email'],
            'reply_to' => $data['email'] ?? '',
            'subject' => "New Inquiry from KSSMI Website - " . ($data['name'] ?? 'Unknown'),
        ],
        'form_data' => [
            'name' => $data['name'] ?? '',
            'email' => $data['email'] ?? '',
            'product_name' => $data['product_name'] ?? '',
            'source' => $data['source'] ?? '',
            'language' => $data['language'] ?? '',
            'product_url' => $data['product_url'] ?? '',
        ],
        'message' => $message,
        'error' => $error,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    ];

    // Read existing logs
    $logs = [];
    if (file_exists($config['log_file'])) {
        $logsContent = file_get_contents($config['log_file']);
        $logs = json_decode($logsContent, true) ?: [];
    }

    // Add new entry (keep last 1000 entries)
    array_unshift($logs, $logEntry);
    $logs = array_slice($logs, 0, 1000);

    // Save logs
    file_put_contents($config['log_file'], json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getRecentLogs($config, $limit = 50) {
    if (!file_exists($config['log_file'])) {
        return [];
    }

    $logsContent = file_get_contents($config['log_file']);
    $logs = json_decode($logsContent, true) ?: [];

    return array_slice($logs, 0, $limit);
}

// ============================================
// TURNSTILE VERIFICATION
// ============================================

function verifyTurnstile($token, $secret) {
    if (empty($token)) return false;

    $data = [
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return false;
    }

    $result = json_decode($response, true);
    return isset($result['success']) && $result['success'] === true;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function buildHtmlEmail($data) {
    return "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #8B7355 0%, #5D4E37 100%); color: white; padding: 25px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h2 { margin: 0; font-size: 24px; }
        .content { background: #f9f9f9; padding: 25px; border: 1px solid #e0e0e0; border-top: none; }
        .field { margin-bottom: 20px; }
        .label { font-weight: bold; color: #5D4E37; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .value { padding: 12px 15px; background: white; border-radius: 6px; border: 1px solid #e0e0e0; }
        .details-box { background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #8B7355; }
        .footer { text-align: center; padding: 20px; color: #888; font-size: 12px; background: #fafafa; border-radius: 0 0 8px 8px; border: 1px solid #e0e0e0; border-top: none; }
        .meta { font-size: 11px; color: #999; margin-top: 20px; padding-top: 15px; border-top: 1px dashed #ddd; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h2>New Project Inquiry</h2>
        </div>
        <div class='content'>
            <div class='field'>
                <div class='label'>Name / Organization</div>
                <div class='value'>{$data['name']}</div>
            </div>
            <div class='field'>
                <div class='label'>Email Address</div>
                <div class='value'><a href='mailto:{$data['email']}' style='color: #8B7355;'>{$data['email']}</a></div>
            </div>
            <div class='field'>
                <div class='label'>Product Interest</div>
                <div class='value'>{$data['product_name']}</div>
            </div>
            <div class='field'>
                <div class='label'>Project Details</div>
                <div class='details-box'>" . nl2br($data['details']) . "</div>
            </div>
            <div class='meta'>
                <strong>Source:</strong> {$data['source']}<br>
                <strong>Language:</strong> {$data['language']}<br>
                <strong>Page:</strong> <a href='https://kssmi.com{$data['product_url']}'>https://kssmi.com{$data['product_url']}</a>
            </div>
        </div>
        <div class='footer'>
            <p>This email was sent from the KSSMI website contact form.</p>
            <p>Submitted: " . date('Y-m-d H:i:s T') . "</p>
        </div>
    </div>
</body>
</html>";
}

function buildTextEmail($data) {
    return "
NEW PROJECT INQUIRY - KSSMI WEBSITE
====================================

Name/Organization: {$data['name']}
Email: {$data['email']}
Product: {$data['product_name']}

PROJECT DETAILS:
----------------
{$data['details']}

META INFO:
----------
Source: {$data['source']}
Language: {$data['language']}
Page URL: https://kssmi.com{$data['product_url']}
Submitted: " . date('Y-m-d H:i:s T') . "
IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "
";
}

// ============================================
// MAIN PROCESSING
// ============================================

// Get and sanitize form data
$formData = [
    'name' => sanitize($_POST['name'] ?? ''),
    'email' => sanitize($_POST['email'] ?? ''),
    'details' => sanitize($_POST['details'] ?? ''),
    'source' => sanitize($_POST['source'] ?? 'unknown'),
    'product_url' => sanitize($_POST['product_url'] ?? ''),
    'product_name' => sanitize($_POST['product_name'] ?? 'N/A'),
    'language' => sanitize($_POST['language'] ?? 'en'),
];

$turnstileToken = $_POST['cf-turnstile-response'] ?? '';

// Set JSON response header
header('Content-Type: application/json');

// Validate required fields
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

// Verify Turnstile (skip in debug mode)
if (!$config['debug_mode']) {
    if (!verifyTurnstile($turnstileToken, $config['turnstile_secret'])) {
        $errors[] = 'Security verification failed. Please complete the captcha.';
    }
} else {
    // Log that we're in debug mode
    error_log("KSSMI Form: Debug mode enabled - Turnstile verification skipped");
}

// Return errors if any
if (!empty($errors)) {
    logEmail($config, $formData, 'failed', 'Validation failed', implode(', ', $errors));
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ============================================
// SEND EMAIL VIA PHPMAILER
// ============================================

// Check if PHPMailer is installed
$phpmailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/';

if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
    // PHPMailer not installed - log and return error
    $errorMsg = 'PHPMailer not installed. Run: composer require phpmailer/phpmailer';
    logEmail($config, $formData, 'failed', 'PHPMailer missing', $errorMsg);
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

    // Sender & Recipient
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email'], $config['to_name']);
    $mail->addReplyTo($formData['email'], $formData['name']);

    // Email Content
    $mail->isHTML(true);
    $mail->Subject = "New Inquiry from KSSMI Website - {$formData['name']}";
    $mail->Body = buildHtmlEmail($formData);
    $mail->AltBody = buildTextEmail($formData);

    // Set higher timeout for slow connections
    $mail->Timeout = 30;

    // Send
    $mail->send();

    // Log success
    logEmail($config, $formData, 'success', 'Email sent successfully');

    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your inquiry has been sent successfully. We will contact you within 24 hours.'
    ]);

} catch (PHPMailerException $e) {
    // Log failure
    $errorMsg = $e->getMessage();
    logEmail($config, $formData, 'failed', 'PHPMailer error', $errorMsg);
    error_log("KSSMI Form Error (PHPMailer): " . $errorMsg);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, there was an error sending your message. Please email us directly at sales@kssmi.com',
        'debug' => $config['debug_mode'] ? $errorMsg : null
    ]);
} catch (Exception $e) {
    // Log failure
    $errorMsg = $e->getMessage();
    logEmail($config, $formData, 'failed', 'General error', $errorMsg);
    error_log("KSSMI Form Error: " . $errorMsg);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, there was an error sending your message. Please email us directly at sales@kssmi.com',
        'debug' => $config['debug_mode'] ? $errorMsg : null
    ]);
}
