<?php
/**
 * KSSMI Email Log Viewer
 */

// Start session first
session_start();

// Password configuration
define('PASSWORD_FILE', __DIR__ . '/.email_logs_password');
define('LOGS_FILE', __DIR__ . '/email-logs.json');

// Get password
function getPassword() {
    if (file_exists(PASSWORD_FILE)) {
        return trim(file_get_contents(PASSWORD_FILE));
    }
    return 'kssmi2024';
}

// Set password
function setPassword($newPassword) {
    file_put_contents(PASSWORD_FILE, $newPassword);
}

$PASSWORD = getPassword();
$error = '';
$message = '';
$passwordMessage = '';
$passwordError = '';

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

// Check auth
$isAuthenticated = false;
if (isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true) {
    $isAuthenticated = true;
}

// Handle password change
if ($isAuthenticated && isset($_POST['change_password'])) {
    $newPass = trim($_POST['new_password']);
    if (strlen($newPass) >= 6) {
        setPassword($newPass);
        $PASSWORD = $newPass;
        $passwordMessage = 'Password changed successfully!';
    } else {
        $passwordError = 'Password must be at least 6 characters';
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
    $logs = array();
    if (file_exists(LOGS_FILE)) {
        $content = file_get_contents(LOGS_FILE);
        $logs = json_decode($content, true);
        if (!$logs) $logs = array();
    }

    foreach ($logs as $key => $log) {
        if (isset($log['id']) && $log['id'] === $resendId) {
            $result = resendEmail($log);
            $logs[$key]['status'] = $result['success'] ? 'success' : 'failed';
            if (!$result['success']) {
                $logs[$key]['error'] = isset($result['error']) ? $result['error'] : 'Unknown error';
            }
            $logs[$key]['resent_at'] = date('Y-m-d H:i:s T');
            $resendMessage = $result['success'] ? 'Email resent successfully!' : 'Resend failed';
            break;
        }
    }

    file_put_contents(LOGS_FILE, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Load logs
$logs = array();
if (file_exists(LOGS_FILE)) {
    $content = file_get_contents(LOGS_FILE);
    $logs = json_decode($content, true);
    if (!$logs) $logs = array();
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

// Resend function
function resendEmail($log) {
    $config = array(
        'to_email' => 'sales@kssmi.com',
        'to_name' => 'KSSMI Sales Team',
        'from_email' => 'kssmi@kssmi.com',
        'from_name' => 'Kssmi Eyewear',
        'smtp' => array(
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'user' => 'kssmi@kssmi.com',
            'pass' => 'chnxqxdkktgehtlt',
            'secure' => 'tls',
        ),
    );

    $phpmailerPath = __DIR__ . '/vendor/phpmailer/phpmailer/src/';

    if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
        return array('success' => false, 'error' => 'PHPMailer not installed');
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

        $formData = isset($log['form_data']) ? $log['form_data'] : array();
        if (!empty($formData['email'])) {
            $name = isset($formData['name']) ? $formData['name'] : '';
            $mail->addReplyTo($formData['email'], $name);
        }

        $name = isset($formData['name']) ? $formData['name'] : 'Unknown';
        $mail->isHTML(true);
        $mail->Subject = $name . " - Kssmi Eyewear [RESENT]";

        $details = isset($formData['details']) ? $formData['details'] : 'No details';
        $email = isset($formData['email']) ? $formData['email'] : 'N/A';
        $product = isset($formData['product_name']) ? $formData['product_name'] : 'N/A';
        $ip = isset($log['ip_address']) ? $log['ip_address'] : 'Unknown';
        $country = isset($log['country']) ? $log['country'] : 'Unknown';
        $origTime = isset($log['timestamp']) ? $log['timestamp'] : 'Unknown';
        $timestamp = date('Y-m-d H:i:s');

        $mail->Body = "<html><body style='font-family:sans-serif;'>
            <h2>RESENT EMAIL</h2>
            <p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>
            <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
            <p><strong>Product:</strong> " . htmlspecialchars($product) . "</p>
            <hr>
            <p><strong>Message:</strong></p>
            <pre style='background:#f5f5f5;padding:15px;border-radius:5px;'>" . htmlspecialchars($details) . "</pre>
            <hr>
            <p><small>Original: $origTime | Resent: $timestamp | IP: $ip | Country: $country</small></p>
        </body></html>";

        $mail->AltBody = "RESENT EMAIL\n\nName: $name\nEmail: $email\nProduct: $product\n\nMessage:\n$details\n\nOriginal: $origTime | IP: $ip | Country: $country";

        $mail->Timeout = 30;
        $mail->send();

        return array('success' => true);
    } catch (Exception $e) {
        return array('success' => false, 'error' => $e->getMessage());
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
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #5D4E37; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 400px; margin: 100px auto; }
        .login-box h2 { margin-bottom: 20px; color: #5D4E37; }
        .login-box input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .login-box button { width: 100%; padding: 12px; background: #8B7355; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .login-box button:hover { background: #5D4E37; }
        .error { color: #e74c3c; margin-bottom: 15px; }
        .success { color: #27ae60; padding: 10px; background: #d4edda; border-radius: 4px; margin-bottom: 15px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); min-width: 150px; }
        .stat-card h3 { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .stat-card .value { font-size: 28px; font-weight: bold; color: #5D4E37; }
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
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 12px; text-transform: uppercase; }
        tr:hover { background: #fafafa; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-success { background: #d4edda; color: #155724; }
        .status-failed { background: #f8d7da; color: #721c24; }
        .email-link { color: #8B7355; text-decoration: none; }
        .email-link:hover { text-decoration: underline; }
        .time { color: #666; font-size: 13px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%; }
        .modal-content h3 { margin-bottom: 20px; color: #5D4E37; }
        .modal-content input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; font-size: 16px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .detail-box { background: white; border-radius: 8px; padding: 20px; margin-top: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: none; }
        .detail-box.show { display: block; }
        .detail-box h4 { color: #5D4E37; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #8B7355; }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .detail-item label { font-size: 12px; color: #666; display: block; margin-bottom: 3px; }
        .detail-item .value { font-size: 14px; color: #333; }
        .message-box { background: #f8f7f5; padding: 20px; border-radius: 8px; white-space: pre-wrap; font-size: 14px; line-height: 1.7; border-left: 4px solid #8B7355; max-height: 300px; overflow-y: auto; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$isAuthenticated): ?>
            <div class="login-box">
                <h2>Email Logs</h2>
                <?php if ($error): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                <form method="POST">
                    <input type="password" name="password" placeholder="Enter password" required autofocus>
                    <button type="submit">Login</button>
                </form>
            </div>
        <?php else: ?>
            <div class="header">
                <div>
                    <h1>Email Logs</h1>
                    <p class="subtitle">Track and manage all email inquiries</p>
                </div>
                <div>
                    <button class="btn btn-secondary" onclick="document.getElementById('passwordModal').classList.add('show')">Change Password</button>
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

            <?php if ($resendMessage): ?>
                <p class="success"><?php echo htmlspecialchars($resendMessage); ?></p>
            <?php endif; ?>

            <?php if ($passwordMessage): ?>
                <p class="success"><?php echo htmlspecialchars($passwordMessage); ?></p>
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
                            <th>IP</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $displayLogs = array_slice($logs, 0, 100);
                        foreach ($displayLogs as $i => $log):
                            $status = isset($log['status']) ? $log['status'] : 'unknown';
                            $timestamp = isset($log['timestamp']) ? $log['timestamp'] : 'Unknown';
                            $name = isset($log['form_data']['name']) ? $log['form_data']['name'] : 'N/A';
                            $email = isset($log['form_data']['email']) ? $log['form_data']['email'] : 'N/A';
                            $product = isset($log['form_data']['product_name']) ? $log['form_data']['product_name'] : 'N/A';
                            $ip = isset($log['ip_address']) ? $log['ip_address'] : 'N/A';
                            $country = isset($log['country']) ? $log['country'] : '';
                            $error = isset($log['error']) ? $log['error'] : '';
                        ?>
                            <tr onclick="toggleDetail(<?php echo $i; ?>)" style="cursor:pointer;">
                                <td>
                                    <span class="status status-<?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                                    <?php if ($status === 'failed' && $error): ?>
                                        <br><small style="color:#e74c3c;"><?php echo htmlspecialchars(substr($error, 0, 40)); ?>...</small>
                                    <?php endif; ?>
                                </td>
                                <td class="time"><?php echo htmlspecialchars($timestamp); ?></td>
                                <td><?php echo htmlspecialchars($name); ?></td>
                                <td><a href="mailto:<?php echo htmlspecialchars($email); ?>" class="email-link" onclick="event.stopPropagation();"><?php echo htmlspecialchars($email); ?></a></td>
                                <td><?php echo htmlspecialchars($product); ?></td>
                                <td><?php echo htmlspecialchars($ip); ?> <?php echo $country ? '/ ' . htmlspecialchars($country) : ''; ?></td>
                                <td onclick="event.stopPropagation();">
                                    <?php if ($status === 'failed'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Resend this email?');">
                                            <input type="hidden" name="resend_id" value="<?php echo htmlspecialchars($log['id']); ?>">
                                            <button type="submit" class="btn btn-success btn-small">Resend</button>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php foreach ($displayLogs as $i => $log):
                    $formData = isset($log['form_data']) ? $log['form_data'] : array();
                ?>
                    <div class="detail-box" id="detail-<?php echo $i; ?>">
                        <h4>Email Details</h4>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>Name</label>
                                <div class="value"><?php echo htmlspecialchars(isset($formData['name']) ? $formData['name'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Email</label>
                                <div class="value"><a href="mailto:<?php echo htmlspecialchars(isset($formData['email']) ? $formData['email'] : ''); ?>" class="email-link"><?php echo htmlspecialchars(isset($formData['email']) ? $formData['email'] : 'N/A'); ?></a></div>
                            </div>
                            <div class="detail-item">
                                <label>Product</label>
                                <div class="value"><?php echo htmlspecialchars(isset($formData['product_name']) ? $formData['product_name'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Language</label>
                                <div class="value"><?php echo htmlspecialchars(isset($formData['language']) ? $formData['language'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>IP Address</label>
                                <div class="value"><?php echo htmlspecialchars(isset($log['ip_address']) ? $log['ip_address'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Country</label>
                                <div class="value"><?php echo htmlspecialchars(isset($log['country']) ? $log['country'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Time</label>
                                <div class="value"><?php echo htmlspecialchars(isset($log['timestamp']) ? $log['timestamp'] : 'N/A'); ?></div>
                            </div>
                            <div class="detail-item">
                                <label>User Agent</label>
                                <div class="value" style="font-size:11px;word-break:break-all;"><?php echo htmlspecialchars(isset($log['user_agent']) ? $log['user_agent'] : 'N/A'); ?></div>
                            </div>
                        </div>
                        <h4 style="margin-top:20px;">Message</h4>
                        <div class="message-box"><?php echo htmlspecialchars(isset($formData['details']) ? $formData['details'] : 'No details provided'); ?></div>
                        <div class="actions">
                            <?php if (isset($log['status']) && $log['status'] === 'failed'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Resend this email?');">
                                    <input type="hidden" name="resend_id" value="<?php echo htmlspecialchars($log['id']); ?>">
                                    <button type="submit" class="btn btn-success">Resend Email</button>
                                </form>
                            <?php endif; ?>
                            <a href="mailto:<?php echo htmlspecialchars(isset($formData['email']) ? $formData['email'] : ''); ?>" class="btn btn-primary">Reply to Customer</a>
                            <button class="btn btn-secondary" onclick="toggleDetail(<?php echo $i; ?>)">Close</button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="margin-top:20px;text-align:center;">
                    <form method="POST" onsubmit="return confirm('Clear all logs?');">
                        <button type="submit" name="clear_logs" class="btn btn-danger">Clear All Logs</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="modal" id="passwordModal">
        <div class="modal-content">
            <h3>Change Password</h3>
            <?php if ($passwordError): ?>
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
        function toggleDetail(index) {
            var panels = document.querySelectorAll('.detail-box');
            for (var i = 0; i < panels.length; i++) {
                panels[i].classList.remove('show');
            }
            var panel = document.getElementById('detail-' + index);
            if (panel) {
                panel.classList.toggle('show');
                if (panel.classList.contains('show')) {
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }
    </script>
</body>
</html>
