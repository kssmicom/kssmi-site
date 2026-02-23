<?php
/**
 * KSSMI Email Log Viewer
 * View email sending records (like WordPress SMTP plugins)
 *
 * FEATURES:
 * - View all email logs
 * - Click to see full email content
 * - Resend failed emails
 * - Change password
 *
 * ACCESS: https://kssmi.com/email-logs.php
 */

// ============================================
// CONFIGURATION
// ============================================

// Password file for persistence
define('PASSWORD_FILE', __DIR__ . '/.email_logs_password');
define('LOGS_FILE', __DIR__ . '/email-logs.json');

// Load or set default password
function getPassword() {
    if (file_exists(PASSWORD_FILE)) {
        return trim(file_get_contents(PASSWORD_FILE));
    }
    return 'kssmi2024'; // Default password
}

function setPassword($newPassword) {
    file_put_contents(PASSWORD_FILE, $newPassword);
}

// ============================================
// SESSION & AUTH
// ============================================

session_start();
$PASSWORD = getPassword();

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $PASSWORD) {
        $_SESSION['email_logs_auth'] = true;
    } else {
        $error = 'Invalid password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: email-logs.php');
    exit;
}

// Handle password change
if (isset($_POST['change_password']) && isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true) {
    $newPass = trim($_POST['new_password']);
    if (strlen($newPass) >= 6) {
        setPassword($newPass);
        $PASSWORD = $newPass;
        $passwordMessage = 'Password changed successfully!';
    } else {
        $passwordError = 'Password must be at least 6 characters';
    }
}

$isAuthenticated = isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true;

// ============================================
// LOG OPERATIONS
// ============================================

// Handle clear logs
if ($isAuthenticated && isset($_POST['clear_logs'])) {
    file_put_contents(LOGS_FILE, '[]');
    $message = 'All logs cleared';
}

// Handle resend email
if ($isAuthenticated && isset($_POST['resend_id'])) {
    $logs = [];
    if (file_exists(LOGS_FILE)) {
        $logs = json_decode(file_get_contents(LOGS_FILE), true) ?: [];
    }

    foreach ($logs as &$log) {
        if ($log['id'] === $_POST['resend_id']) {
            // Resend the email
            $result = resendEmail($log);
            $log['status'] = $result['success'] ? 'success' : 'failed';
            $log['error'] = $result['error'] ?? '';
            $log['resent_at'] = date('Y-m-d H:i:s T');
            $resendMessage = $result['success'] ? 'Email resent successfully!' : 'Resend failed: ' . ($result['error'] ?? 'Unknown error');
            break;
        }
    }

    file_put_contents(LOGS_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Load logs
$logs = [];
if (file_exists(LOGS_FILE)) {
    $logs = json_decode(file_get_contents(LOGS_FILE), true) ?: [];
}

// Stats
$totalEmails = count($logs);
$successCount = count(array_filter($logs, fn($l) => $l['status'] === 'success'));
$failedCount = count(array_filter($logs, fn($l) => $l['status'] === 'failed'));
$recent24h = count(array_filter($logs, fn($l) => ($l['unix_time'] ?? 0) > time() - 86400));

// ============================================
// RESEND FUNCTION
// ============================================

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
            'pass' => 'chnxqxdkktgehtlt',
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

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception as PHPMailerException;

    try {
        $mail = new PHPMailer(true);
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

        // Build email content
        $ip = $log['ip_address'] ?? 'Unknown';
        $country = $log['country'] ?? 'Unknown';
        $inquiryId = '#' . strtoupper(substr($log['id'] ?? '', -4));

        $mail->isHTML(true);
        $mail->Subject = ($formData['name'] ?? 'Unknown') . " - Kssmi Eyewear [RESENT]";
        $mail->Body = buildResendEmailBody($log, $ip, $country, $inquiryId);
        $mail->AltBody = buildResendEmailText($log, $ip, $country, $inquiryId);

        $mail->Timeout = 30;
        $mail->send();

        return ['success' => true];
    } catch (PHPMailerException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function buildResendEmailBody($log, $ip, $country, $inquiryId) {
    $formData = $log['form_data'] ?? [];
    $timestamp = date('Y-m-d H:i:s');
    $source = 'https://kssmi.com' . ($formData['product_url'] ?? '');

    return "
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 30px; border-radius: 12px 12px 0 0; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 600; }
        .badge { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
        .content { background: white; padding: 30px; border: 1px solid #e0e0e0; border-top: none; }
        .section { margin-bottom: 25px; }
        .section-title { font-size: 12px; font-weight: 600; color: #e74c3c; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e74c3c; }
        .field { margin-bottom: 12px; }
        .field-label { font-size: 12px; color: #666; margin-bottom: 4px; }
        .field-value { font-size: 15px; color: #333; }
        .field-value a { color: #e74c3c; text-decoration: none; }
        .details-box { background: #fff5f5; padding: 20px; border-radius: 8px; border-left: 4px solid #e74c3c; white-space: pre-wrap; font-size: 14px; line-height: 1.7; }
        .meta-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .meta-table td { padding: 10px 0; border-bottom: 1px solid #eee; }
        .meta-table td:first-child { color: #666; width: 100px; }
        .meta-table td:last-child { color: #333; font-weight: 500; }
        .footer { padding: 20px; color: #888; font-size: 12px; background: #fafafa; border-radius: 0 0 12px 12px; border: 1px solid #e0e0e0; border-top: none; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>" . htmlspecialchars($formData['name'] ?? 'Unknown') . " - Kssmi <span class='badge'>RESENT</span></h1>
        </div>
        <div class='content'>
            <div class='section'>
                <div class='section-title'>Contact Information</div>
                <div class='field'>
                    <div class='field-label'>Name</div>
                    <div class='field-value'>" . htmlspecialchars($formData['name'] ?? 'N/A') . "</div>
                </div>
                <div class='field'>
                    <div class='field-label'>Email Address</div>
                    <div class='field-value'><a href='mailto:" . htmlspecialchars($formData['email'] ?? '') . "'>" . htmlspecialchars($formData['email'] ?? 'N/A') . "</a></div>
                </div>
                <div class='field'>
                    <div class='field-label'>Product Interest</div>
                    <div class='field-value'>" . htmlspecialchars($formData['product_name'] ?? 'N/A') . "</div>
                </div>
            </div>
            <div class='section'>
                <div class='section-title'>Project Details</div>
                <div class='details-box'>" . htmlspecialchars($formData['details'] ?? 'No details provided') . "</div>
            </div>
            <div class='section'>
                <div class='section-title'>Metadata</div>
                <table class='meta-table'>
                    <tr><td>Original Time</td><td>" . ($log['timestamp'] ?? 'Unknown') . "</td></tr>
                    <tr><td>Resent Time</td><td>{$timestamp}</td></tr>
                    <tr><td>Source</td><td><a href='{$source}' style='color: #e74c3c;'>{$source}</a></td></tr>
                    <tr><td>IP</td><td>{$ip}</td></tr>
                    <tr><td>Country</td><td>{$country}</td></tr>
                    <tr><td>ID</td><td>{$inquiryId}</td></tr>
                </table>
            </div>
        </div>
        <div class='footer'>
            <p>This email was resent from the KSSMI Email Logs admin panel.</p>
        </div>
    </div>
</body>
</html>";
}

function buildResendEmailText($log, $ip, $country, $inquiryId) {
    $formData = $log['form_data'] ?? [];
    $timestamp = date('Y-m-d H:i:s');

    return "
RESENT EMAIL - " . ($formData['name'] ?? 'Unknown') . " - Kssmi
==========================================

Name: " . ($formData['name'] ?? 'N/A') . "
Email: " . ($formData['email'] ?? 'N/A') . "
Product: " . ($formData['product_name'] ?? 'N/A') . "

PROJECT DETAILS:
----------------
" . ($formData['details'] ?? 'No details provided') . "

METADATA:
---------
Original Time: " . ($log['timestamp'] ?? 'Unknown') . "
Resent Time: {$timestamp}
IP: {$ip}
Country: {$country}
ID: {$inquiryId}

---
This email was resent from the KSSMI Email Logs admin panel.
";
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
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        h1 { color: #5D4E37; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }

        /* Login */
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:hover { background: #5D4E37; }
        .error { color: #e74c3c; margin-bottom: 15px; }
        .success-msg { color: #27ae60; margin-bottom: 15px; padding: 10px; background: #d4edda; border-radius: 4px; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .stat-card .value { font-size: 32px; font-weight: bold; color: #5D4E37; }
        .stat-card.success .value { color: #27ae60; }
        .stat-card.failed .value { color: #e74c3c; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; display: inline-block; }
        .btn-primary { background: #8B7355; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #666; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn:hover { opacity: 0.9; }

        /* Table */
        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 12px; text-transform: uppercase; cursor: pointer; }
        th:hover { background: #f0f0f0; }
        tr { cursor: pointer; transition: background 0.2s; }
        tr:hover { background: #fafafa; }
        tr.selected { background: #fff3e0; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status.success { background: #d4edda; color: #155724; }
        .status.failed { background: #f8d7da; color: #721c24; }
        .email-link { color: #8B7355; text-decoration: none; }
        .email-link:hover { text-decoration: underline; }
        .time { color: #666; font-size: 13px; }
        .detail-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .error-msg { color: #e74c3c; font-size: 12px; margin-top: 5px; }

        /* Detail Panel */
        .detail-panel { display: none; background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 20px; overflow: hidden; }
        .detail-panel.show { display: block; }
        .detail-header { background: #5D4E37; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .detail-header h3 { margin: 0; }
        .detail-content { padding: 20px; }
        .detail-section { margin-bottom: 20px; }
        .detail-section h4 { color: #5D4E37; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #8B7355; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .detail-item label { font-size: 12px; color: #666; display: block; margin-bottom: 3px; }
        .detail-item .value { font-size: 14px; color: #333; }
        .email-body { background: #f8f7f5; padding: 20px; border-radius: 8px; white-space: pre-wrap; font-size: 14px; line-height: 1.7; max-height: 300px; overflow-y: auto; border-left: 4px solid #8B7355; }
        .detail-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%; }
        .modal-content h3 { margin-bottom: 20px; color: #5D4E37; }
        .modal-content input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

        /* Empty state */
        .empty { text-align: center; padding: 60px 20px; color: #666; }
        .empty-icon { font-size: 48px; margin-bottom: 15px; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 10px; font-size: 13px; }
            .hide-mobile { display: none; }
            .header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$isAuthenticated): ?>
            <!-- Login Form -->
            <div class="login-box">
                <h2>Email Logs</h2>
                <?php if (isset($error)): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>

        <?php else: ?>
            <!-- Dashboard -->
            <div class="header">
                <div>
                    <h1>Email Logs</h1>
                    <p class="subtitle">Track and manage all email inquiries</p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="showPasswordModal()">Change Password</button>
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <?php if (isset($resendMessage)): ?>
                <p class="success-msg"><?php echo htmlspecialchars($resendMessage); ?></p>
            <?php endif; ?>

            <?php if (isset($passwordMessage)): ?>
                <p class="success-msg"><?php echo htmlspecialchars($passwordMessage); ?></p>
            <?php endif; ?>

            <!-- Stats -->
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

            <!-- Logs Table -->
            <div class="table-container">
                <?php if (empty($logs)): ?>
                    <div class="empty">
                        <div class="empty-icon">📭</div>
                        <p>No email logs yet</p>
                        <p style="font-size: 14px; margin-top: 10px;">Emails will appear here when the contact form is submitted.</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Time</th>
                                <th>From</th>
                                <th>Email</th>
                                <th>Product</th>
                                <th class="hide-mobile">IP / Country</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($logs, 0, 200) as $index => $log): ?>
                                <tr onclick="toggleDetail(<?php echo $index; ?>)" data-index="<?php echo $index; ?>">
                                    <td>
                                        <span class="status <?php echo $log['status']; ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                            <?php if (!empty($log['resent_at'])): ?>
                                                ↻
                                            <?php endif; ?>
                                        </span>
                                        <?php if ($log['status'] === 'failed' && !empty($log['error'])): ?>
                                            <div class="error-msg"><?php echo htmlspecialchars(substr($log['error'], 0, 50)); ?>...</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="time"><?php echo $log['timestamp'] ?? 'Unknown'; ?></td>
                                    <td><?php echo htmlspecialchars($log['form_data']['name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($log['form_data']['email'] ?? ''); ?>" class="email-link" onclick="event.stopPropagation();">
                                            <?php echo htmlspecialchars($log['form_data']['email'] ?? 'N/A'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['form_data']['product_name'] ?? 'N/A'); ?></td>
                                    <td class="hide-mobile">
                                        <?php
                                        $ip = $log['ip_address'] ?? 'N/A';
                                        $country = $log['country'] ?? 'N/A';
                                        echo htmlspecialchars($ip . ' / ' . $country);
                                        ?>
                                    </td>
                                    <td onclick="event.stopPropagation();">
                                        <?php if ($log['status'] === 'failed'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Resend this email?');">
                                                <input type="hidden" name="resend_id" value="<?php echo htmlspecialchars($log['id']); ?>">
                                                <button type="submit" class="btn btn-success" style="padding:5px 10px;font-size:12px;">Resend</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Detail Panel -->
            <?php foreach (array_slice($logs, 0, 200) as $index => $log): ?>
                <div class="detail-panel" id="detail-<?php echo $index; ?>">
                    <div class="detail-header">
                        <h3>Email Details - <?php echo htmlspecialchars($log['form_data']['name'] ?? 'Unknown'); ?></h3>
                        <button class="btn btn-secondary" onclick="toggleDetail(<?php echo $index; ?>)">Close</button>
                    </div>
                    <div class="detail-content">
                        <div class="detail-grid">
                            <div class="detail-section">
                                <h4>Contact Information</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <label>Name</label>
                                        <div class="value"><?php echo htmlspecialchars($log['form_data']['name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Email</label>
                                        <div class="value">
                                            <a href="mailto:<?php echo htmlspecialchars($log['form_data']['email'] ?? ''); ?>" class="email-link">
                                                <?php echo htmlspecialchars($log['form_data']['email'] ?? 'N/A'); ?>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Product</label>
                                        <div class="value"><?php echo htmlspecialchars($log['form_data']['product_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Language</label>
                                        <div class="value"><?php echo htmlspecialchars($log['form_data']['language'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Source</label>
                                        <div class="value"><?php echo htmlspecialchars($log['form_data']['source'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>URL</label>
                                        <div class="value">
                                            <a href="https://kssmi.com<?php echo htmlspecialchars($log['form_data']['product_url'] ?? ''); ?>" target="_blank" class="email-link">
                                                <?php echo htmlspecialchars($log['form_data']['product_url'] ?? 'N/A'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="detail-section">
                                <h4>Metadata</h4>
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <label>Status</label>
                                        <div class="value"><span class="status <?php echo $log['status']; ?>"><?php echo ucfirst($log['status']); ?></span></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>Time</label>
                                        <div class="value"><?php echo htmlspecialchars($log['timestamp'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>IP Address</label>
                                        <div class="value"><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <label>User Agent</label>
                                        <div class="value" style="font-size:11px;word-break:break-all;"><?php echo htmlspecialchars($log['user_agent'] ?? 'N/A'); ?></div>
                                    </div>
                                    <?php if (!empty($log['error'])): ?>
                                        <div class="detail-item" style="grid-column:1/-1;">
                                            <label>Error</label>
                                            <div class="value" style="color:#e74c3c;"><?php echo htmlspecialchars($log['error']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($log['resent_at'])): ?>
                                        <div class="detail-item">
                                            <label>Resent At</label>
                                            <div class="value"><?php echo htmlspecialchars($log['resent_at']); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="detail-section">
                            <h4>Message / Project Details</h4>
                            <div class="email-body"><?php echo htmlspecialchars($log['form_data']['details'] ?? 'No details provided'); ?></div>
                        </div>
                        <div class="detail-actions">
                            <?php if ($log['status'] === 'failed'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Resend this email?');">
                                    <input type="hidden" name="resend_id" value="<?php echo htmlspecialchars($log['id']); ?>">
                                    <button type="submit" class="btn btn-success">Resend Email</button>
                                </form>
                            <?php endif; ?>
                            <a href="mailto:<?php echo htmlspecialchars($log['form_data']['email'] ?? ''); ?>" class="btn btn-primary">Reply to Customer</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Clear Logs -->
            <?php if (!empty($logs)): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to clear all logs? This cannot be undone!');">
                        <button type="submit" name="clear_logs" class="btn btn-danger">Clear All Logs</button>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Password Change Modal -->
    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <h3>Change Password</h3>
            <?php if (isset($passwordError)): ?>
                <p class="error"><?php echo htmlspecialchars($passwordError); ?></p>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="new_password" placeholder="New password (min 6 characters)" required minlength="6">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hidePasswordModal()">Cancel</button>
                    <button type="submit" name="change_password" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleDetail(index) {
            // Hide all other details
            document.querySelectorAll('.detail-panel').forEach(panel => {
                if (panel.id !== 'detail-' + index) {
                    panel.classList.remove('show');
                }
            });
            document.querySelectorAll('tr[data-index]').forEach(row => {
                if (row.dataset.index != index) {
                    row.classList.remove('selected');
                }
            });

            // Toggle this detail
            var panel = document.getElementById('detail-' + index);
            var row = document.querySelector('tr[data-index="' + index + '"]');
            panel.classList.toggle('show');
            row.classList.toggle('selected');

            if (panel.classList.contains('show')) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function showPasswordModal() {
            document.getElementById('passwordModal').classList.add('show');
        }

        function hidePasswordModal() {
            document.getElementById('passwordModal').classList.remove('show');
        }

        // Close modal on outside click
        document.getElementById('passwordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hidePasswordModal();
            }
        });
    </script>
</body>
</html>
