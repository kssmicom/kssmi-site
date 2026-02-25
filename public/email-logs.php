<?php
/**
 * KSSMI Email Log Viewer
 * Features:
 * - Password protected access
 * - Forgot password with email reset
 * - Email log viewing and management
 * - Resend failed emails
 * - Country lookup from IP
 */

// Start session first
session_start();

// Load private credentials (file lives outside public_html)
$_privateConfigPath = dirname(__DIR__) . '/private_config.php';
if (file_exists($_privateConfigPath)) {
    $_privateCfg = require $_privateConfigPath;
} else {
    error_log('KSSMI: private_config.php not found at ' . $_privateConfigPath);
    $_privateCfg = ['smtp_pass' => '', 'turnstile_secret' => ''];
}

// Password configuration - use absolute path
define('PASSWORD_FILE', dirname(__FILE__) . '/.email_logs_password');
define('LOGS_FILE', dirname(dirname(__FILE__)) . '/email-logs.json');
define('RESET_TOKENS_FILE', dirname(__FILE__) . '/.email_reset_tokens.json');
define('ADMIN_EMAIL', 'kssmi@kssmi.com');

// Country code to name mapping
$COUNTRY_NAMES = [
    'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
    'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
    'AZ' => 'Azerbaijan', 'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados',
    'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
    'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei',
    'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi', 'KH' => 'Cambodia', 'CM' => 'Cameroon',
    'CA' => 'Canada', 'CV' => 'Cape Verde', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile',
    'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (Democratic Republic)',
    'CR' => 'Costa Rica', 'CI' => 'Côte d\'Ivoire', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
    'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic',
    'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea',
    'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France',
    'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
    'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau',
    'GY' => 'Guyana', 'HT' => 'Haiti', 'HN' => 'Honduras', 'HU' => 'Hungary', 'IS' => 'Iceland',
    'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland',
    'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan',
    'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'North Korea', 'KR' => 'South Korea',
    'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon',
    'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania',
    'LU' => 'Luxembourg', 'MK' => 'North Macedonia', 'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia',
    'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania',
    'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco',
    'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
    'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand',
    'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NO' => 'Norway', 'OM' => 'Oman',
    'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea',
    'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
    'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis',
    'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino',
    'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles',
    'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands',
    'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka',
    'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
    'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste',
    'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Turkey',
    'TM' => 'Turkmenistan', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
    'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu',
    'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia',
    'ZW' => 'Zimbabwe', 'LOCAL' => 'Local/Testing', 'UNKNOWN' => 'Unknown',
];

function getCountryName($code) {
    global $COUNTRY_NAMES;
    $code = strtoupper($code);
    return isset($COUNTRY_NAMES[$code]) ? $COUNTRY_NAMES[$code] . ' (' . $code . ')' : $code;
}

// Get password - with better error handling
function getPassword() {
    $defaultPassword = 'kssmi2024';
    if (!file_exists(PASSWORD_FILE)) {
        return $defaultPassword;
    }
    $content = @file_get_contents(PASSWORD_FILE);
    if ($content === false) {
        return $defaultPassword;
    }
    $password = trim($content);
    return !empty($password) ? $password : $defaultPassword;
}

// Set password - with better error handling
function setPassword($newPassword) {
    $result = @file_put_contents(PASSWORD_FILE, $newPassword);
    if ($result === false) {
        return false;
    }
    @chmod(PASSWORD_FILE, 0600);
    return true;
}

// Generate secure reset token
function generateResetToken() {
    return bin2hex(random_bytes(32));
}

// Get reset tokens
function getResetTokens() {
    if (!file_exists(RESET_TOKENS_FILE)) {
        return [];
    }
    $content = @file_get_contents(RESET_TOKENS_FILE);
    if ($content === false) {
        return [];
    }
    $tokens = json_decode($content, true);
    return is_array($tokens) ? $tokens : [];
}

// Save reset tokens
function saveResetTokens($tokens) {
    $result = @file_put_contents(RESET_TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    if ($result !== false) {
        @chmod(RESET_TOKENS_FILE, 0600);
    }
    return $result !== false;
}

// Clean expired tokens (older than 1 hour)
function cleanExpiredTokens() {
    $tokens = getResetTokens();
    $now = time();
    $validTokens = [];
    foreach ($tokens as $token => $data) {
        if (isset($data['expires']) && $data['expires'] > $now) {
            $validTokens[$token] = $data;
        }
    }
    saveResetTokens($validTokens);
    return $validTokens;
}

// Send reset email via PHPMailer
function sendResetEmail($token) {
    $phpmailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/';

    if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
        return ['success' => false, 'error' => 'PHPMailer not installed'];
    }

    require_once $phpmailerPath . 'Exception.php';
    require_once $phpmailerPath . 'PHPMailer.php';
    require_once $phpmailerPath . 'SMTP.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'kssmi@kssmi.com';
        $mail->Password = $_privateCfg['smtp_pass'];
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('kssmi@kssmi.com', 'KSSMI Website');
        $mail->addAddress(ADMIN_EMAIL);

        $resetUrl = 'https://kssmi.com/email-logs.php?reset=' . $token;

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - KSSMI Email Logs';

        $mail->Body = "
        <html>
        <body style='font-family: -apple-system, BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; padding: 20px;'>
            <div style='max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 30px; border: 1px solid #e0e0e0;'>
                <h2 style='color: #5D4E37; margin-bottom: 20px;'>Password Reset Request</h2>
                <p style='color: #333; line-height: 1.6;'>Someone requested to reset the password for the KSSMI Email Logs admin panel.</p>
                <p style='color: #333; line-height: 1.6;'>Click the button below to set a new password:</p>
                <p style='margin: 30px 0;'>
                    <a href='{$resetUrl}' style='background: #8B7355; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; display: inline-block;'>Reset Password</a>
                </p>
                <p style='color: #666; font-size: 14px;'>Or copy this link to your browser:</p>
                <p style='background: #f5f5f5; padding: 10px; border-radius: 4px; word-break: break-all; font-size: 12px;'>{$resetUrl}</p>
                <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
                <p style='color: #999; font-size: 12px;'>
                    This link will expire in <strong>1 hour</strong>.<br>
                    If you did not request this reset, you can safely ignore this email.
                </p>
            </div>
        </body>
        </html>";

        $mail->AltBody = "Password Reset Request\n\nClick this link to reset your password:\n{$resetUrl}\n\nThis link expires in 1 hour.";

        $mail->Timeout = 30;
        $mail->send();

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

$PASSWORD = getPassword();
$error = '';
$message = '';
$passwordMessage = '';
$passwordError = '';
$showResetForm = false;
$resetMode = false;

// Handle password reset request
if (isset($_POST['request_reset'])) {
    // Generate token
    $token = generateResetToken();
    $tokens = cleanExpiredTokens();
    $tokens[$token] = [
        'created' => time(),
        'expires' => time() + 3600 // 1 hour
    ];

    if (saveResetTokens($tokens)) {
        $result = sendResetEmail($token);
        if ($result['success']) {
            $message = 'Reset link sent to ' . ADMIN_EMAIL . '. Please check your inbox. The link expires in 1 hour.';
        } else {
            $error = 'Failed to send reset email: ' . ($result['error'] ?? 'Unknown error');
        }
    } else {
        $error = 'Failed to generate reset token. Please check file permissions.';
    }
}

// Handle password reset with token
if (isset($_GET['reset'])) {
    $token = $_GET['reset'];
    $tokens = cleanExpiredTokens();

    if (isset($tokens[$token])) {
        $resetMode = true;

        // Handle new password submission
        if (isset($_POST['reset_password'])) {
            $newPass = trim($_POST['new_password']);
            $confirmPass = trim($_POST['confirm_password']);

            if (strlen($newPass) < 6) {
                $passwordError = 'Password must be at least 6 characters';
            } elseif ($newPass !== $confirmPass) {
                $passwordError = 'Passwords do not match';
            } else {
                if (setPassword($newPass)) {
                    // Remove used token
                    unset($tokens[$token]);
                    saveResetTokens($tokens);

                    $passwordMessage = 'Password reset successfully! You can now login with your new password.';
                    $resetMode = false;
                } else {
                    $passwordError = 'Failed to save new password. Please try again.';
                }
            }
        }
    } else {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_POST['change_password']) && !isset($_POST['reset_password'])) {
    $submittedPassword = trim($_POST['password']);
    if ($submittedPassword === $PASSWORD) {
        $_SESSION['email_logs_auth'] = true;
        session_regenerate_id(true);
    } else {
        $error = 'Invalid password. Please try again.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: email-logs.php');
    exit;
}

// Check auth
$isAuthenticated = false;
if (isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true) {
    $isAuthenticated = true;
}

// Handle password change (when logged in)
if ($isAuthenticated && isset($_POST['change_password'])) {
    $newPass = trim($_POST['new_password']);
    if (strlen($newPass) < 6) {
        $passwordError = 'Password must be at least 6 characters';
    } else {
        if (setPassword($newPass)) {
            $PASSWORD = $newPass;
            $passwordMessage = 'Password changed successfully! Please use the new password next time you login.';
        } else {
            $passwordError = 'Failed to save password. Please check file permissions.';
        }
    }
}

// Handle clear logs
if ($isAuthenticated && isset($_POST['clear_logs'])) {
    file_put_contents(LOGS_FILE, '[]');
    $message = 'All logs cleared';
}

// Handle resend
$resendMessage = '';
if ($isAuthenticated && isset($_POST['resend_id'])) {
    $resendId = $_POST['resend_id'];
    $logs = [];
    if (file_exists(LOGS_FILE)) {
        $content = file_get_contents(LOGS_FILE);
        $logs = json_decode($content, true) ?: [];
    }

    foreach ($logs as $key => $log) {
        if (isset($log['id']) && $log['id'] === $resendId) {
            $result = resendEmail($log);
            $logs[$key]['status'] = $result['success'] ? 'success' : 'failed';
            if (!$result['success']) {
                $logs[$key]['error'] = $result['error'] ?? 'Unknown error';
            }
            $logs[$key]['resent_at'] = date('Y-m-d H:i:s T');
            $resendMessage = $result['success'] ? 'Email resent successfully!' : 'Resend failed: ' . ($result['error'] ?? 'Unknown error');
            break;
        }
    }

    file_put_contents(LOGS_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Handle delete single log
if ($isAuthenticated && isset($_POST['delete_id'])) {
    $deleteId = $_POST['delete_id'];
    $logs = [];
    if (file_exists(LOGS_FILE)) {
        $content = file_get_contents(LOGS_FILE);
        $logs = json_decode($content, true) ?: [];
    }

    $logs = array_values(array_filter($logs, function($log) use ($deleteId) {
        return !isset($log['id']) || $log['id'] !== $deleteId;
    }));

    file_put_contents(LOGS_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $message = 'Log entry deleted';
}

// Handle bulk delete
if ($isAuthenticated && isset($_POST['bulk_delete']) && isset($_POST['selected_ids'])) {
    $selectedIds = $_POST['selected_ids'];
    $logs = [];
    if (file_exists(LOGS_FILE)) {
        $content = file_get_contents(LOGS_FILE);
        $logs = json_decode($content, true) ?: [];
    }

    $logs = array_values(array_filter($logs, function($log) use ($selectedIds) {
        return !isset($log['id']) || !in_array($log['id'], $selectedIds);
    }));

    file_put_contents(LOGS_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $message = count($selectedIds) . ' log entries deleted';
}

// Load logs
$logs = [];
if (file_exists(LOGS_FILE)) {
    $content = file_get_contents(LOGS_FILE);
    $logs = json_decode($content, true) ?: [];
}

// Stats
$totalEmails = count($logs);
$successCount = 0;
$failedCount = 0;
$recent24h = 0;

foreach ($logs as $l) {
    if (isset($l['status'])) {
        if ($l['status'] === 'success') $successCount++;
        if ($l['status'] === 'failed') $failedCount++;
    }
    if (isset($l['unix_time']) && $l['unix_time'] > time() - 86400) {
        $recent24h++;
    }
}

// Build HTML email (same format as send-mail.php)
function buildResendHtmlEmail($formData, $ip, $country, $inquiryId, $origTime) {
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . ($formData['product_url'] ?? '');
    $lang = $formData['language'] ?? 'en';

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
        'it' => ['contactInfo' => 'Informazioni di Contatto', 'name' => 'Nome', 'email' => 'Indirizzo Email', 'product' => 'Interesse Prodotto', 'projectDetails' => 'Dettagli del Progetto', 'metadata' => 'Metadati', 'time' => 'Ora', 'source' => 'Fonte', 'country' => 'Paese', 'footer' => 'Questa email è stata generata automaticamente dal modulo di contatto KSSMI Eyewear.'],
        'es' => ['contactInfo' => 'Información de Contacto', 'name' => 'Nombre', 'email' => 'Dirección de Correo', 'product' => 'Interés del Producto', 'projectDetails' => 'Detalles del Proyecto', 'metadata' => 'Metadatos', 'time' => 'Hora', 'source' => 'Fuente', 'country' => 'País', 'footer' => 'Este correo fue generado automáticamente desde el formulario de contacto de KSSMI Eyewear.'],
        'fr' => ['contactInfo' => 'Informations de Contact', 'name' => 'Nom', 'email' => 'Adresse Email', 'product' => 'Intérêt pour le Produit', 'projectDetails' => 'Détails du Projet', 'metadata' => 'Métadonnées', 'time' => 'Heure', 'source' => 'Source', 'country' => 'Pays', 'footer' => 'Cet email a été généré automatiquement depuis le formulaire de contact KSSMI Eyewear.'],
        'de' => ['contactInfo' => 'Kontaktinformationen', 'name' => 'Name', 'email' => 'E-Mail-Adresse', 'product' => 'Produktinteresse', 'projectDetails' => 'Projektdetails', 'metadata' => 'Metadaten', 'time' => 'Zeit', 'source' => 'Quelle', 'country' => 'Land', 'footer' => 'Diese E-Mail wurde automatisch vom KSSMI Eyewear Kontaktformular generiert.'],
        'pt' => ['contactInfo' => 'Informações de Contato', 'name' => 'Nome', 'email' => 'Endereço de Email', 'product' => 'Interesse no Produto', 'projectDetails' => 'Detalhes do Projeto', 'metadata' => 'Metadados', 'time' => 'Hora', 'source' => 'Fonte', 'country' => 'País', 'footer' => 'Este email foi gerado automaticamente pelo formulário de contato KSSMI Eyewear.'],
        'ru' => ['contactInfo' => 'Контактная информация', 'name' => 'Имя', 'email' => 'Адрес электронной почты', 'product' => 'Интерес к продукту', 'projectDetails' => 'Детали проекта', 'metadata' => 'Метаданные', 'time' => 'Время', 'source' => 'Источник', 'country' => 'Страна', 'footer' => 'Это письмо было автоматически создано формой связи KSSMI Eyewear.'],
        'ja' => ['contactInfo' => '連絡先情報', 'name' => '名前', 'email' => 'メールアドレス', 'product' => '製品への関心', 'projectDetails' => 'プロジェクト詳細', 'metadata' => 'メタデータ', 'time' => '時間', 'source' => 'ソース', 'country' => '国', 'footer' => 'このメールはKSSMI Eyewearのお問い合わせフォームから自動的に生成されました。'],
        'tr' => ['contactInfo' => 'İletişim Bilgileri', 'name' => 'İsim', 'email' => 'E-posta Adresi', 'product' => 'Ürün İlgi Alanı', 'projectDetails' => 'Proje Detayları', 'metadata' => 'Meta Veriler', 'time' => 'Zaman', 'source' => 'Kaynak', 'country' => 'Ülke', 'footer' => 'Bu e-posta KSSMI Eyewear iletişim formundan otomatik olarak oluşturulmuştur.'],
        'ar' => ['contactInfo' => 'معلومات الاتصال', 'name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'product' => 'اهتمام المنتج', 'projectDetails' => 'تفاصيل المشروع', 'metadata' => 'البيانات الوصفية', 'time' => 'الوقت', 'source' => 'المصدر', 'country' => 'البلد', 'footer' => 'تم إنشاء هذا البريد الإلكتروني تلقائيًا من نموذج الاتصال KSSMI Eyewear.'],
    ];

    $t = $translations[$lang] ?? $translations['en'];
    $name = htmlspecialchars($formData['name'] ?? 'Unknown');
    $email = htmlspecialchars($formData['email'] ?? 'N/A');
    $product = htmlspecialchars($formData['product_name'] ?? 'N/A');
    $details = htmlspecialchars($formData['details'] ?? 'No details');
    $countryName = getCountryName($country);

    return "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #8B7355 0%, #5D4E37 100%); color: white; padding: 30px; border-radius: 12px 12px 0 0; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 600; text-align: left; }
        .resent-badge { background: #e74c3c; color: white; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
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
            <h1>{$name} - Kssmi</h1>
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
                    <div class='field-value'>{$product}</div>
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
                    <tr><td>Original</td><td>{$origTime}</td></tr>
                    <tr><td>{$t['source']}</td><td><a href='{$source}' style='color: #8B7355;'>{$source}</a></td></tr>
                    <tr><td>IP</td><td>{$ip}</td></tr>
                    <tr><td>{$t['country']}</td><td>{$countryName}</td></tr>
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

function buildResendTextEmail($formData, $ip, $country, $inquiryId, $origTime) {
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . ($formData['product_url'] ?? '');
    $name = $formData['name'] ?? 'Unknown';
    $email = $formData['email'] ?? 'N/A';
    $product = $formData['product_name'] ?? 'N/A';
    $details = $formData['details'] ?? 'No details';
    $countryName = getCountryName($country);

    return "
{$name} - Kssmi
================

Name: {$name}
Email Address: {$email}
Product Interest: {$product}

PROJECT DETAILS:
----------------
{$details}

METADATA:
---------
Time: {$timestamp}
Original: {$origTime}
Source: {$source}
IP: {$ip}
Country: {$countryName}
ID: {$inquiryId}

---
This email was automatically generated from the KSSMI Eyewear contact form.
";
}

// Resend function
function resendEmail($log) {
    $config = [
        'to_email' => 'sales@kssmi.com',
        'to_name' => 'KSSMI Sales Team',
        'from_email' => 'kssmi@kssmi.com',
        'from_name' => 'Kssmi Eyewear',
        'smtp' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'user' => 'kssmi@kssmi.com',
            'pass' => $_privateCfg['smtp_pass'],
            'secure' => 'tls',
        ],
    ];

    $phpmailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/';

    if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
        return ['success' => false, 'error' => 'PHPMailer not installed'];
    }

    require_once $phpmailerPath . 'Exception.php';
    require_once $phpmailerPath . 'PHPMailer.php';
    require_once $phpmailerPath . 'SMTP.php';

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['smtp']['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp']['user'];
        $mail->Password = $config['smtp']['pass'];
        $mail->SMTPSecure = $config['smtp']['secure'];
        $mail->Port = $config['smtp']['port'];

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($config['to_email'], $config['to_name']);

        $formData = $log['form_data'] ?? [];
        if (!empty($formData['email'])) {
            $mail->addReplyTo($formData['email'], $formData['name'] ?? '');
        }

        $name = $formData['name'] ?? 'Unknown';
        $inquiryId = '#' . strtoupper(substr($log['id'] ?? uniqid(), -4));
        $ip = $log['ip_address'] ?? 'Unknown';
        $country = $log['country'] ?? 'Unknown';
        $origTime = $log['timestamp'] ?? 'Unknown';

        $mail->isHTML(true);
        $mail->Subject = "{$name} - Kssmi Eyewear - {$inquiryId}";
        $mail->Body = buildResendHtmlEmail($formData, $ip, $country, $inquiryId, $origTime);
        $mail->AltBody = buildResendTextEmail($formData, $ip, $country, $inquiryId, $origTime);

        $mail->Timeout = 30;
        $mail->send();

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Logs - KSSMI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #5D4E37; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:hover { background: #5D4E37; }
        .login-box button.secondary { background: #666; margin-top: 10px; }
        .login-box button.secondary:hover { background: #444; }
        .error { color: #e74c3c; margin-bottom: 15px; padding: 10px; background: #fdeaea; border-radius: 4px; }
        .success { color: #27ae60; padding: 10px; background: #d4edda; border-radius: 4px; margin-bottom: 15px; }
        .forgot-link { text-align: center; margin-top: 15px; }
        .forgot-link a { color: #8B7355; text-decoration: none; font-size: 14px; }
        .forgot-link a:hover { text-decoration: underline; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); min-width: 150px; }
        .stat-card h3 { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #5D4E37; }
        .stat-card.success { background: white; padding: 20px; margin-bottom: 0; }
        .stat-card.success .value { color: #27ae60; }
        .stat-card.failed .value { color: #e74c3c; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; }
        .btn-primary { background: #8B7355; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #666; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn:hover { opacity: 0.9; }
        .btn-small { padding: 5px 10px; font-size: 12px; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 12px; text-transform: uppercase; white-space: nowrap; }
        tr:hover { background: #fafafa; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .email-link { color: #8B7355; text-decoration: none; }
        .email-link:hover { text-decoration: underline; }
        .time { color: #666; font-size: 13px; white-space: nowrap; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%; }
        .modal-content h3 { margin-bottom: 20px; color: #5D4E37; }
        .modal-content input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .detail-row { display: none; }
        .detail-row.show { display: table-row; }
        .detail-content { background: #faf9f7; padding: 20px; border-top: 2px solid #8B7355; }
        .detail-content h4 { color: #5D4E37; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #8B7355; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .detail-item { background: #f8f7f5; padding: 12px 15px; border-radius: 6px; }
        .detail-item label { font-size: 11px; color: #666; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
        .detail-item .value { font-size: 14px; color: #333; word-break: break-word; }
        .detail-item .value a { color: #8B7355; }
        .message-box { background: #f8f7f5; padding: 20px; border-radius: 8px; white-space: pre-wrap; font-size: 14px; line-height: 1.7; border-left: 4px solid #8B7355; max-height: 300px; overflow-y: auto; }
        .actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
        .url-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .url-cell a { color: #8B7355; text-decoration: none; font-size: 12px; }
        .url-cell a:hover { text-decoration: underline; }
        .country-badge { background: #e8e4df; padding: 3px 8px; border-radius: 4px; font-size: 12px; }
        .reset-info { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; }
        .bulk-actions { background: #5D4E37; color: white; padding: 12px 20px; border-radius: 8px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .bulk-actions .btn-danger { background: #c0392b; }
        .bulk-actions .btn-danger:hover { background: #a93226; }
        .data-row.selected { background: #fff3cd !important; }
        .data-row:hover { background: #f5f5f5; }
        tr.detail-row.show + tr.data-row, tr.data-row:has(+ tr.detail-row.show) { background: #faf9f7; }
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .stats { flex-direction: column; }
            .stat-card { width: 100%; }
            th, td { padding: 8px 10px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($resetMode): ?>
            <!-- Password Reset Form -->
            <div class="login-box">
                <h2>Reset Password</h2>
                <?php if ($passwordError): ?>
                    <p class="error"><?php echo htmlspecialchars($passwordError); ?></p>
                <?php endif; ?>
                <?php if ($passwordMessage): ?>
                    <p class="success"><?php echo htmlspecialchars($passwordMessage); ?></p>
                    <p style="margin-top:15px;"><a href="email-logs.php" class="btn">Go to Login</a></p>
                <?php else: ?>
                    <p style="color:#666;margin-bottom:20px;">Enter your new password below.</p>
                    <form method="POST">
                        <input type="password" name="new_password" placeholder="New password (min 6 characters)" required minlength="6">
                        <input type="password" name="confirm_password" placeholder="Confirm new password" required minlength="6">
                        <button type="submit" name="reset_password">Set New Password</button>
                    </form>
                <?php endif; ?>
            </div>

        <?php elseif (!$isAuthenticated): ?>
            <!-- Login Form -->
            <div class="login-box">
                <h2>Email Logs</h2>
                <?php if ($error): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <?php if ($message): ?>
                    <p class="success"><?php echo htmlspecialchars($message); ?></p>
                <?php endif; ?>

                <?php if (strpos($message ?? '', 'Reset link sent') !== false): ?>
                    <p style="margin-top:15px;"><a href="email-logs.php" class="btn secondary">Back to Login</a></p>
                <?php else: ?>
                    <form method="POST">
                        <input type="password" name="password" placeholder="Enter password" required autofocus>
                        <button type="submit">Login</button>
                    </form>
                    <div class="forgot-link">
                        <a href="#" onclick="document.getElementById('forgotForm').style.display='block';return false;">Forgot password?</a>
                    </div>

                    <!-- Forgot Password Form -->
                    <div id="forgotForm" style="display:none;margin-top:20px;padding-top:20px;border-top:1px solid #eee;">
                        <p style="color:#666;font-size:14px;margin-bottom:15px;">
                            Click the button below to send a password reset link to:<br>
                            <strong><?php echo htmlspecialchars(ADMIN_EMAIL); ?></strong>
                        </p>
                        <form method="POST">
                            <button type="submit" name="request_reset" class="secondary">Send Reset Link</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Main Dashboard -->
            <div class="header">
                <div>
                    <h1>Email Logs</h1>
                    <p class="subtitle">Track and manage all email inquiries from kssmi.com</p>
                </div>
                <div>
                    <button class="btn btn-secondary" onclick="document.getElementById('passwordModal').classList.add('show')">Change Password</button>
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <?php if ($resendMessage): ?>
                <p class="<?php echo strpos($resendMessage, 'successfully') !== false ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($resendMessage); ?></p>
            <?php endif; ?>

            <?php if ($passwordMessage): ?>
                <p class="success"><?php echo htmlspecialchars($passwordMessage); ?></p>
            <?php endif; ?>

            <?php if ($passwordError): ?>
                <p class="error"><?php echo htmlspecialchars($passwordError); ?></p>
            <?php endif; ?>

            <?php if ($message): ?>
                <p class="success"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <div class="stats">
                <div class="stat-card">
                    <h3>Total Emails</h3>
                    <div class="value"><?php echo $totalEmails; ?></div>
                </div>
                <div class="stat-card success">
                    <h3>Successful</h3>
                    <div class="value"><?php echo $successCount; ?></div>
                </div>
                <div class="stat-card failed">
                    <h3>Failed</h3>
                    <div class="value"><?php echo $failedCount; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Last 24 Hours</h3>
                    <div class="value"><?php echo $recent24h; ?></div>
                </div>
            </div>

            <?php if (empty($logs)): ?>
                <div style="text-align:center;padding:60px;background:white;border-radius:8px;">
                    <p style="font-size:48px;margin-bottom:15px;">📭</p>
                    <p>No email logs yet</p>
                    <p style="color:#999;font-size:14px;margin-top:10px;">When visitors submit inquiries, they will appear here.</p>
                </div>
            <?php else: ?>
                <!-- Bulk Actions Bar -->
                <div class="bulk-actions" id="bulkActionsBar" style="display:none;">
                    <span id="selectedCount">0</span> selected
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete selected log entries?');" id="bulkDeleteForm">
                        <input type="hidden" name="bulk_delete" value="1">
                        <div id="selectedIdsContainer"></div>
                        <button type="submit" class="btn btn-danger btn-small">Delete Selected</button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-small" onclick="clearSelection()">Clear Selection</button>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:30px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                <th>Status</th>
                                <th>Time</th>
                                <th>From</th>
                                <th>Email</th>
                                <th>Product</th>
                                <th>Page URL</th>
                                <th>IP / Country</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $displayLogs = array_slice($logs, 0, 100);
                            foreach ($displayLogs as $i => $log):
                                $status = $log['status'] ?? 'unknown';
                                $timestamp = $log['timestamp'] ?? 'Unknown';
                                $name = $log['form_data']['name'] ?? 'N/A';
                                $email = $log['form_data']['email'] ?? 'N/A';
                                $product = $log['form_data']['product_name'] ?? 'N/A';
                                $pageUrl = $log['form_data']['product_url'] ?? '';
                                $fullUrl = $pageUrl ? 'https://kssmi.com' . $pageUrl : 'N/A';
                                $ip = $log['ip_address'] ?? 'N/A';
                                $countryCode = $log['country'] ?? '';
                                $countryName = $countryCode ? getCountryName($countryCode) : 'Unknown';
                                $error = $log['error'] ?? '';
                                $logId = $log['id'] ?? '';
                            ?>
                                <!-- Main Data Row -->
                                <tr class="data-row" data-index="<?php echo $i; ?>">
                                    <td onclick="event.stopPropagation();">
                                        <input type="checkbox" class="log-checkbox" data-id="<?php echo htmlspecialchars($logId); ?>" onchange="updateBulkActions()">
                                    </td>
                                    <td onclick="toggleDetail(<?php echo $i; ?>)" style="cursor:pointer;">
                                        <span class="status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                                        <?php if ($status === 'failed' && $error): ?>
                                            <br><small style="color:#e74c3c;"><?php echo htmlspecialchars(substr($error, 0, 30)); ?>...</small>
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="toggleDetail(<?php echo $i; ?>)" class="time" style="cursor:pointer;"><?php echo htmlspecialchars($timestamp); ?></td>
                                    <td onclick="toggleDetail(<?php echo $i; ?>)" style="cursor:pointer;"><?php echo htmlspecialchars($name); ?></td>
                                    <td><a href="mailto:<?php echo htmlspecialchars($email); ?>" class="email-link"><?php echo htmlspecialchars($email); ?></a></td>
                                    <td onclick="toggleDetail(<?php echo $i; ?>)" style="cursor:pointer;"><?php echo htmlspecialchars($product); ?></td>
                                    <td class="url-cell">
                                        <?php if ($pageUrl): ?>
                                            <a href="<?php echo htmlspecialchars($fullUrl); ?>" target="_blank" title="<?php echo htmlspecialchars($fullUrl); ?>"><?php echo htmlspecialchars($pageUrl); ?></a>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="toggleDetail(<?php echo $i; ?>)" style="cursor:pointer;">
                                        <?php echo htmlspecialchars($ip); ?>
                                        <?php if ($countryCode): ?>
                                            <br><span class="country-badge"><?php echo htmlspecialchars($countryName); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this log entry?');">
                                            <input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($logId); ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Del</button>
                                        </form>
                                    </td>
                                </tr>
                                <!-- Details Row (collapsible) -->
                                <tr class="detail-row" id="detail-row-<?php echo $i; ?>">
                                    <td colspan="9" style="padding:0;">
                                        <div class="detail-content" id="detail-<?php echo $i; ?>">
                                            <?php
                                            $formData = $log['form_data'] ?? [];
                                            ?>
                                            <div class="detail-grid">
                                                <div class="detail-item">
                                                    <label>Name</label>
                                                    <div class="value"><?php echo htmlspecialchars($formData['name'] ?? 'N/A'); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Email</label>
                                                    <div class="value"><a href="mailto:<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"><?php echo htmlspecialchars($formData['email'] ?? 'N/A'); ?></a></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Product</label>
                                                    <div class="value"><?php echo htmlspecialchars($formData['product_name'] ?? 'N/A'); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Page URL</label>
                                                    <div class="value"><a href="<?php echo htmlspecialchars($fullUrl); ?>" target="_blank"><?php echo htmlspecialchars($fullUrl); ?></a></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Language</label>
                                                    <div class="value"><?php echo htmlspecialchars(strtoupper($formData['language'] ?? 'N/A')); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Country</label>
                                                    <div class="value"><?php echo htmlspecialchars($countryName); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>IP Address</label>
                                                    <div class="value"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></div>
                                                </div>
                                                <div class="detail-item">
                                                    <label>Time</label>
                                                    <div class="value"><?php echo htmlspecialchars($log['timestamp'] ?? 'N/A'); ?></div>
                                                </div>
                                                <?php if (isset($log['resent_at'])): ?>
                                                <div class="detail-item">
                                                    <label>Last Resent</label>
                                                    <div class="value" style="color:#27ae60;"><?php echo htmlspecialchars($log['resent_at']); ?></div>
                                                </div>
                                                <?php endif; ?>
                                                <div class="detail-item" style="grid-column: 1 / -1;">
                                                    <label>User Agent</label>
                                                    <div class="value" style="font-size:11px;word-break:break-all;color:#666;"><?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?></div>
                                                </div>
                                            </div>
                                            <h4 style="margin-top:20px;">Message</h4>
                                            <div class="message-box"><?php echo htmlspecialchars($formData['details'] ?? 'No details provided'); ?></div>
                                            <?php if (!empty($log['error'])): ?>
                                            <h4 style="margin-top:20px;color:#e74c3c;">Error</h4>
                                            <div class="message-box" style="border-left-color:#e74c3c;background:#fdeaea;"><?php echo htmlspecialchars($log['error']); ?></div>
                                            <?php endif; ?>
                                            <div class="actions">
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Resend this email to sales@kssmi.com?');">
                                                    <input type="hidden" name="resend_id" value="<?php echo htmlspecialchars($logId); ?>">
                                                    <button type="submit" class="btn btn-success">Resend</button>
                                                </form>
                                                <a href="mailto:<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" class="btn btn-primary">Reply to Customer</a>
                                                <button class="btn btn-secondary" onclick="toggleDetail(<?php echo $i; ?>)">Collapse</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px;text-align:center;">
                    <form method="POST" onsubmit="return confirm('Clear all logs? This cannot be undone.');" style="display:inline;">
                        <button type="submit" name="clear_logs" class="btn btn-danger">Clear All Logs</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <h3>Change Password</h3>
            <?php if ($passwordError && $isAuthenticated): ?>
                <p class="error"><?php echo htmlspecialchars($passwordError); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="new_password" placeholder="New password (min 6 characters)" required minlength="6">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('passwordModal').classList.remove('show')">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        var currentOpenIndex = null;

        function toggleDetail(index) {
            var detailRow = document.getElementById('detail-row-' + index);
            var wasOpen = detailRow.classList.contains('show');

            // Close all detail rows first
            var allDetailRows = document.querySelectorAll('.detail-row');
            for (var i = 0; i < allDetailRows.length; i++) {
                allDetailRows[i].classList.remove('show');
            }

            // If this row wasn't already open, open it
            if (!wasOpen) {
                detailRow.classList.add('show');
                currentOpenIndex = index;
                // Scroll the data row into view
                var dataRow = document.querySelector('.data-row[data-index="' + index + '"]');
                if (dataRow) {
                    dataRow.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else {
                currentOpenIndex = null;
            }
        }

        function toggleSelectAll() {
            var selectAllCheckbox = document.getElementById('selectAll');
            var checkboxes = document.querySelectorAll('.log-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAllCheckbox.checked;
                var row = checkboxes[i].closest('.data-row');
                if (selectAllCheckbox.checked) {
                    row.classList.add('selected');
                } else {
                    row.classList.remove('selected');
                }
            }
            updateBulkActions();
        }

        function updateBulkActions() {
            var checkboxes = document.querySelectorAll('.log-checkbox:checked');
            var bulkBar = document.getElementById('bulkActionsBar');
            var selectedCount = document.getElementById('selectedCount');
            var container = document.getElementById('selectedIdsContainer');
            var selectAllCheckbox = document.getElementById('selectAll');
            var allCheckboxes = document.querySelectorAll('.log-checkbox');

            // Update selected count
            selectedCount.textContent = checkboxes.length;

            // Show/hide bulk actions bar
            if (checkboxes.length > 0) {
                bulkBar.style.display = 'flex';
            } else {
                bulkBar.style.display = 'none';
            }

            // Update select all checkbox state
            if (checkboxes.length === allCheckboxes.length && allCheckboxes.length > 0) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkboxes.length > 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            }

            // Update selected IDs in form
            container.innerHTML = '';
            for (var i = 0; i < checkboxes.length; i++) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = checkboxes[i].getAttribute('data-id');
                container.appendChild(input);
            }

            // Update row highlighting
            var allRows = document.querySelectorAll('.data-row');
            for (var i = 0; i < allRows.length; i++) {
                var checkbox = allRows[i].querySelector('.log-checkbox');
                if (checkbox.checked) {
                    allRows[i].classList.add('selected');
                } else {
                    allRows[i].classList.remove('selected');
                }
            }
        }

        function clearSelection() {
            var checkboxes = document.querySelectorAll('.log-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = false;
            }
            document.getElementById('selectAll').checked = false;
            updateBulkActions();
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            var checkboxes = document.querySelectorAll('.log-checkbox');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].addEventListener('change', function() {
                    updateBulkActions();
                });
            }
        });
    </script>
</body>
</html>
