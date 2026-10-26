<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/privacy-maintenance.php';
$mode = $argv[1] ?? '--dry-run';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Usage: php privacy-maintenance-cli.php [--dry-run|--apply]\n");
    exit(2);
}
$logPath = dirname(__DIR__) . '/email_data/email-logs.json';
try {
    if ($mode === '--apply') {
        $result = kssmi_privacy_maintenance($logPath);
        echo json_encode(['mode' => 'apply', 'result' => $result], JSON_THROW_ON_ERROR), "\n";
        exit($result['outbox']['invalid'] > 0 ? 1 : 0);
    }
    $now = time();
    $result = ['mode' => 'dry-run', 'queue_retention_days' => 30, 'short_link_event_retention_days' => 90,
        'email_log_retention_days' => 730, 'expired_queue_candidates' => 0, 'unknown_queue_records' => 0,
        'expired_email_logs' => 0, 'expired_short_link_events' => 0, 'expired_consent_receipts' => 0, 'unknown_consent_receipts' => 0];
    $outbox = kssmi_outbox_dir();
    if (is_dir($outbox) && !is_link($outbox)) {
        $root = realpath($outbox);
        foreach (kssmi_outbox_job_ids() as $id) {
            $path = $outbox . '/' . $id;
            if (is_link($path) || dirname((string)realpath($path)) !== $root) continue;
            $job = kssmi_outbox_load_job($path);
            $created = $job['created_at'] ?? null;
            if (!is_int($created) || $created <= 0 || $created > $now) $result['unknown_queue_records']++;
            elseif ($created < $now - KSSMI_OUTBOX_RETENTION_SECONDS) $result['expired_queue_candidates']++;
        }
    }
    if (is_file($logPath)) {
        $logs = json_decode((string)file_get_contents($logPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($logs)) throw new RuntimeException('Email-log store is not an array.');
        $result['expired_email_logs'] = count($logs) - count(kssmi_email_logs_within_retention($logs, $now));
    }
    $receiptDir = dirname($outbox) . '/inquiry-consents';
    if (is_dir($receiptDir) && !is_link($receiptDir)) {
        $receiptRoot = realpath($receiptDir);
        foreach (glob($receiptDir . '/*.json') ?: [] as $path) {
            if (is_link($path) || dirname((string)realpath($path)) !== $receiptRoot
                || !preg_match('/^[0-9]{14}-[a-f0-9]{16}\.json$/D', basename($path))) continue;
            $entry = json_decode((string)file_get_contents($path), true);
            $created = $entry['unix_time'] ?? null;
            if (!is_int($created) || $created <= 0 || $created > $now) $result['unknown_consent_receipts']++;
            elseif ($created < $now - KSSMI_EMAIL_LOG_RETENTION_SECONDS) $result['expired_consent_receipts']++;
        }
    }
    require_once __DIR__ . '/short-link-store.php';
    $dbPath = short_link_data_dir() . '/shortlinks.sqlite';
    if (is_file($dbPath)) {
        $db = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only = ON');
        $query = $db->prepare('SELECT COUNT(*) FROM short_link_events WHERE julianday(opened_at) < julianday(?)');
        $query->execute([gmdate('Y-m-d\TH:i:s\Z', $now - 90 * 86400)]);
        $result['expired_short_link_events'] = (int)$query->fetchColumn();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Privacy maintenance failed: ' . $error->getMessage() . "\n");
    exit(1);
}
