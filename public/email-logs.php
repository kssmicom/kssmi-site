<?php
/**
 * KSSMI Email Log Viewer
 * View email sending records (like WordPress SMTP plugins)
 *
 * ACCESS: https://kssmi.com/email-logs.php
 * SECURITY: Change the password below!
 */

// Simple password protection
$PASSWORD = 'kssmi2024';  // CHANGE THIS PASSWORD!

session_start();

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
$ isAuthenticated = isset($_SESSION['email_logs_auth']) && $_SESSION['email_logs_auth'] === true;

// Handle clear logs
if ($isAuthenticated && isset($_POST['clear_logs'])) {
    file_put_contents(__DIR__ . '/email-logs.json', '[]');
    $message = 'All logs cleared';
}

// Load logs
$logFile = __DIR__ . '/email-logs.json';
$logs = [];
if (file_exists($logFile)) {
    $logs = json_decode(file_get_contents($logFile), true) ?: [];
}

// Stats
$totalEmails = count($logs);
$successCount = count(array_filter($logs, fn($l) => $l['status'] === 'success'));
$failedCount = count(array_filter($logs, fn($l) => $l['status'] === 'failed'));
$recent24h = count(array_filter($logs, fn($l) => ($l['unix_time'] ?? 0) > time() - 86400));
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

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card h3 { font-size: 12px; text-transform: uppercase; color: #666; margin-bottom: 5px; }
        .stat-card .value { font-size: 32px; font-weight: bold; color: #5D4E37; }
        .stat-card.success .value { color: #27ae60; }
        .stat-card.failed .value { color: #e74c3c; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-actions { display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn-primary { background: #8B7355; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-secondary { background: #666; color: white; }
        .btn:hover { opacity: 0.9; }

        /* Table */
        .table-container { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f8f8; font-weight: 600; color: #5D4E37; font-size: 12px; text-transform: uppercase; }
        tr:hover { background: #fafafa; }
        .status { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status.success { background: #d4edda; color: #155724; }
        .status.failed { background: #f8d7da; color: #721c24; }
        .email-link { color: #8B7355; text-decoration: none; }
        .email-link:hover { text-decoration: underline; }
        .time { color: #666; font-size: 13px; }
        .detail-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .error-msg { color: #e74c3c; font-size: 12px; margin-top: 5px; }

        /* Empty state */
        .empty { text-align: center; padding: 60px 20px; color: #666; }
        .empty-icon { font-size: 48px; margin-bottom: 15px; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 10px; font-size: 13px; }
            .hide-mobile { display: none; }
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
                    <p class="subtitle">Track all email sending attempts</p>
                </div>
                <div class="header-actions">
                    <a href="?logout" class="btn btn-secondary">Logout</a>
                </div>
            </div>

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
                                <th class="hide-mobile">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($logs, 0, 100) as $log): ?>
                                <tr>
                                    <td>
                                        <span class="status <?php echo $log['status']; ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                        <?php if ($log['status'] === 'failed' && !empty($log['error'])): ?>
                                            <div class="error-msg"><?php echo htmlspecialchars($log['error']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="time"><?php echo $log['timestamp'] ?? 'Unknown'; ?></td>
                                    <td><?php echo htmlspecialchars($log['form_data']['name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($log['form_data']['email'] ?? ''); ?>" class="email-link">
                                            <?php echo htmlspecialchars($log['form_data']['email'] ?? 'N/A'); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['form_data']['product_name'] ?? 'N/A'); ?></td>
                                    <td class="detail-cell hide-mobile" title="<?php echo htmlspecialchars($log['form_data']['source'] ?? ''); ?>">
                                        <?php
                                        $details = [];
                                        if (!empty($log['form_data']['language'])) $details[] = 'Lang: ' . $log['form_data']['language'];
                                        if (!empty($log['form_data']['source'])) $details[] = $log['form_data']['source'];
                                        echo implode(' | ', $details);
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Clear Logs -->
            <?php if (!empty($logs)): ?>
                <div style="margin-top: 20px; text-align: center;">
                    <form method="POST" onsubmit="return confirm('Are you sure you want to clear all logs?');">
                        <button type="submit" name="clear_logs" class="btn btn-danger">Clear All Logs</button>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</body>
</html>
