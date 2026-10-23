<?php
declare(strict_types=1);

/**
 * Send inquiry mail still waiting in the outbox (private/inquiry-outbox.php).
 *
 * send-mail.php already sends each inquiry right after answering the visitor
 * and retries older jobs on every later inquiry. This worker covers the time
 * between inquiries. Run it as the site account (never root), for example
 * from that account's crontab once a minute:
 *
 *   php "$(dirname "$(readlink -f /home/kssmi.com/public_html)")/private/inquiry-outbox-worker.php"
 *
 * It exits without sending while the deploy's email-log cutover barrier is
 * active, and prints only job ids, results and counts.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$release = dirname(__DIR__);
$webroot = is_file($release . '/dist/send-mail.php') ? $release . '/dist' : $release . '/public';

require_once $release . '/private/email-log-store.php';
require_once $release . '/private/inquiry-outbox.php';
require_once $webroot . '/api/vjt-helpers.php';
define('KSSMI_SEND_MAIL_LIBRARY', true);
require_once $webroot . '/send-mail.php';
foreach (['Exception', 'PHPMailer', 'SMTP'] as $class) {
    require_once $webroot . '/vendor/phpmailer/phpmailer/src/' . $class . '.php';
}

$privateConfigPath = $release . '/private_config.php';
$privateConfig = is_file($privateConfigPath) ? (array)require $privateConfigPath : [];
$config = kssmi_inquiry_config($privateConfig, $release . '/email_data/email-logs.json');

if (kssmi_email_logs_cutover_is_active($config['log_file'])) {
    echo "email log cutover active; nothing sent\n";
    exit(0);
}

$results = kssmi_outbox_drain(kssmi_inquiry_outbox_sender($config), kssmi_inquiry_outbox_final($config), 20, 50);
foreach ($results as $id => $result) echo $id, ' ', $result, "\n";
echo 'outbox ', json_encode(kssmi_outbox_summary()), "\n";
