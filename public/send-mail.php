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

/**
 * Get country code from IP address using free IP-API service
 */
function getCountryFromIP($ip) {
    // Skip for localhost/private IPs
    if (in_array($ip, ['127.0.0.1', '::1', 'unknown']) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'LOCAL';
    }

    $ch = curl_init("http://ip-api.com/json/{$ip}?fields=countryCode&lang=en");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        return $data['countryCode'] ?? 'UNKNOWN';
    }

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

---

## Here is the user details...

{$data['details']}

---

## Metadata

| Field | Value |
|-------|-------|
| **Time** | {$timestamp} |
| **Source** | {$source} |
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

    // Header title: "Name - Kssmi"
    $headerTitle = htmlspecialchars($data['name']) . ' - Kssmi';

    return "
<!DOCTYPE html>
<html>
<head>
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
                    <div class='field-value'>" . htmlspecialchars($data['name']) . "</div>
                </div>
                <div class='field'>
                    <div class='field-label'>{$t['email']}</div>
                    <div class='field-value'><a href='mailto:" . htmlspecialchars($data['email']) . "'>" . htmlspecialchars($data['email']) . "</a></div>
                </div>
                <div class='field'>
                    <div class='field-label'>{$t['product']}</div>
                    <div class='field-value'>" . htmlspecialchars($data['product_name']) . "</div>
                </div>
            </div>
            <div class='section'>
                <div class='section-title'>{$t['projectDetails']}</div>
                <div class='details-box'>" . htmlspecialchars($data['details']) . "</div>
            </div>
            <div class='section'>
                <div class='section-title'>{$t['metadata']}</div>
                <table class='meta-table'>
                    <tr><td>{$t['time']}</td><td>{$timestamp}</td></tr>
                    <tr><td>{$t['source']}</td><td><a href='{$source}' style='color: #8B7355;'>{$source}</a></td></tr>
                    <tr><td>IP</td><td>{$ip}</td></tr>
                    <tr><td>{$t['country']}</td><td>{$country}</td></tr>
                    <tr><td>ID</td><td><span class='inquiry-id'>{$inquiryId}</span></td></tr>
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

PROJECT DETAILS:
----------------
{$data['details']}

METADATA:
---------
Time: {$timestamp}
Source: {$source}
IP: {$ip}
Country: {$country}
ID: {$inquiryId}

---
This email was automatically generated from the KSSMI Eyewear contact form.
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

// Get visitor metadata
$visitorIP = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$visitorCountry = getCountryFromIP($visitorIP);
$inquiryId = generateInquiryId();

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
    $mail->Subject = "KSSMI - {$formData['name']} - {$inquiryId}";
    $mail->Body = buildHtmlEmail($formData, $visitorIP, $visitorCountry, $inquiryId);
    $mail->AltBody = buildTextEmail($formData, $visitorIP, $visitorCountry, $inquiryId);

    // Set higher timeout for slow connections
    $mail->Timeout = 30;

    // Send
    $mail->send();

    // Log success
    logEmail($config, $formData, 'success', 'Email sent successfully');

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
