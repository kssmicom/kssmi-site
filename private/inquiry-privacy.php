<?php
declare(strict_types=1);

/** Public notice shared with the built form; never contains configuration secrets. */
function kssmi_inquiry_privacy_notice(): array {
    static $notice = null;
    if ($notice === null) {
        $raw = @file_get_contents(__DIR__ . '/inquiry-privacy-notice.json');
        if (!is_string($raw)) throw new RuntimeException('Inquiry privacy notice is unavailable.');
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['id'] ?? null) || $decoded['id'] === ''
            || !is_string($decoded['policyVersion'] ?? null) || $decoded['policyVersion'] === ''
            || !is_array($decoded['consent'] ?? null) || !is_string($decoded['consent']['en'] ?? null)) {
            throw new RuntimeException('Inquiry privacy notice has an invalid schema.');
        }
        foreach ($decoded['consent'] as $copy) if (!is_string($copy) || $copy === '') throw new RuntimeException('Inquiry privacy text is invalid.');
        $notice = $decoded;
    }
    return $notice;
}

/** Only an explicit current-notice affirmative action is accepted. Client times are ignored. */
function kssmi_inquiry_consent_record(array $input, string $language, ?int $now = null): ?array {
    $notice = kssmi_inquiry_privacy_notice();
    if (($input['inquiry_privacy_consent'] ?? null) !== '1'
        || ($input['inquiry_notice_id'] ?? null) !== $notice['id']) return null;
    if (!isset($notice['consent'][$language])) return null;
    return [
        'granted' => true,
        'purpose' => 'inquiry_and_related_project_correspondence',
        'notice_id' => $notice['id'],
        'policy_version' => $notice['policyVersion'],
        'notice_sha256' => hash('sha256', $notice['consent'][$language]),
        'notice_text' => $notice['consent'][$language],
        'language' => $language,
        'received_at' => gmdate('Y-m-d\TH:i:s\Z', $now ?? time()),
    ];
}

const KSSMI_EMAIL_LOG_RETENTION_SECONDS = 730 * 86400;

/** Unknown legacy dates remain visible for manual review rather than being guessed. */
function kssmi_email_logs_within_retention(array $logs, ?int $now = null): array {
    $cutoff = ($now ?? time()) - KSSMI_EMAIL_LOG_RETENTION_SECONDS;
    return array_values(array_filter($logs, static function ($entry) use ($cutoff): bool {
        if (!is_array($entry)) return false;
        $created = $entry['unix_time'] ?? null;
        if (!is_int($created) && !(is_string($created) && ctype_digit($created))) {
            $created = is_string($entry['timestamp'] ?? null) ? strtotime($entry['timestamp']) : false;
        }
        if ($created === false || $created === null || (int)$created <= 0) {
            error_log('KSSMI retention: unknown email-log date requires manual review.');
            return true;
        }
        return (int)$created >= $cutoff;
    }));
}

/** Receipt survives successful queue removal and log truncation; no message or raw email. */
function kssmi_inquiry_persist_consent_receipt(string $outbox, string $jobId, array $record, int $now): bool {
    if (!preg_match('/^[0-9]{14}-[a-f0-9]{16}$/D', $jobId)) return false;
    $consent = $record['form']['inquiry_consent'] ?? null;
    if (!is_array($consent) || ($consent['granted'] ?? false) !== true) return false;
    $dir = dirname($outbox) . '/inquiry-consents';
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return false;
    $receipt = ['inquiry_id' => $record['inquiry_id'] ?? '', 'unix_time' => $now,
        'subject_sha256' => hash('sha256', strtolower(trim((string)($record['form']['email'] ?? '')))),
        'consent' => $consent];
    $temp = $dir . '/.tmp-' . $jobId;
    $dest = $dir . '/' . $jobId . '.json';
    if (file_exists($dest) || is_link($dest)) return false;
    try { $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    catch (Throwable $error) { return false; }
    if (!kssmi_outbox_write_file($temp, $bytes)) return false;
    if (!@rename($temp, $dest)) { @unlink($temp); return false; }
    return true;
}

function kssmi_inquiry_prune_consent_receipts(string $outbox, ?int $now = null): int {
    $dir = dirname($outbox) . '/inquiry-consents';
    if (!is_dir($dir) || is_link($dir)) return 0;
    $root = realpath($dir);
    $cutoff = ($now ?? time()) - KSSMI_EMAIL_LOG_RETENTION_SECONDS;
    $removed = 0;
    $invalid = 0;
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_link($path) || dirname((string)realpath($path)) !== $root
            || !preg_match('/^[0-9]{14}-[a-f0-9]{16}\.json$/D', basename($path))) continue;
        $entry = json_decode((string)@file_get_contents($path), true);
        if (!is_array($entry) || !is_int($entry['unix_time'] ?? null) || $entry['unix_time'] <= 0) {
            $invalid++;
            continue;
        }
        if (is_array($entry) && is_int($entry['unix_time'] ?? null) && $entry['unix_time'] > 0
            && $entry['unix_time'] < $cutoff && @unlink($path)) $removed++;
    }
    if ($invalid > 0) error_log('KSSMI retention: invalid consent receipts require manual review: ' . $invalid);
    return $removed;
}
