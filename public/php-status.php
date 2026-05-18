<?php
/**
 * PHP Status Check — DISABLED ON PRODUCTION
 * Uncomment the block below and comment out the die() for local testing only.
 */
http_response_code(403);
header('Content-Type: text/plain');
die('403 Forbidden');

header('Content-Type: application/json');

$status = [
    'php' => [
        'version' => PHP_VERSION,
        'loaded' => true,
    ],
    'extensions' => [
        'curl' => extension_loaded('curl'),
        'openssl' => extension_loaded('openssl'),
        'json' => extension_loaded('json'),
        'mbstring' => extension_loaded('mbstring'),
    ],
    'phpmailer' => [
        'installed' => file_exists(__DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php'),
    ],
    'smtp_config' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'user' => 'kssmi@kssmi.com',
        // Don't show password!
    ],
    'writeable' => [
        'logs' => is_writable(__DIR__) || is_writable(__DIR__ . '/email-logs.json'),
    ],
];

echo json_encode($status, JSON_PRETTY_PRINT);
