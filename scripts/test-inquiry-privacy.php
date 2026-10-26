<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/kssmi-privacy-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) throw new RuntimeException('Could not create synthetic fixture.');
putenv('KSSMI_OUTBOX_DIR=' . $root . '/outbox');
putenv('KSSMI_SHORTLINK_DATA_DIR=' . $root . '/links');
putenv('KSSMI_SHORTLINK_ALLOWED_HOSTS=drive.google.com');
ini_set('error_log', $root . '/test-errors.log');
require_once dirname(__DIR__) . '/private/inquiry-privacy.php';
require_once dirname(__DIR__) . '/private/inquiry-outbox.php';
require_once dirname(__DIR__) . '/private/short-link-store.php';
require_once dirname(__DIR__) . '/private/privacy-maintenance.php';

function privacy_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function privacy_remove_fixture(string $path, string $root): void {
    $resolved = realpath($path);
    if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) throw new RuntimeException('Fixture cleanup escaped its verified root.');
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . '/' . $name;
        is_dir($entry) && !is_link($entry) ? privacy_remove_fixture($entry, $root) : unlink($entry);
    }
    rmdir($path);
}

try {
    $now = time();
    $notice = kssmi_inquiry_privacy_notice();
    $valid = ['inquiry_privacy_consent' => '1', 'inquiry_notice_id' => $notice['id'], 'received_at' => 'forged'];
    privacy_assert(kssmi_inquiry_consent_record([], 'en', $now) === null, 'Missing consent must fail.');
    privacy_assert(kssmi_inquiry_consent_record(array_replace($valid, ['inquiry_privacy_consent' => ['1']]), 'en', $now) === null, 'Array-shaped consent must fail.');
    privacy_assert(kssmi_inquiry_consent_record(array_replace($valid, ['inquiry_notice_id' => 'obsolete']), 'en', $now) === null, 'Obsolete notice must fail.');
    foreach ($notice['consent'] as $lang => $copy) {
        $record = kssmi_inquiry_consent_record($valid, $lang, $now);
        privacy_assert(($record['notice_sha256'] ?? '') === hash('sha256', $copy), 'Consent must bind the exact localized notice.');
        privacy_assert(($record['notice_text'] ?? '') === $copy, 'Exact wording must remain available after later notice revisions.');
        privacy_assert(($record['received_at'] ?? '') === gmdate('Y-m-d\TH:i:s\Z', $now), 'Consent time must be server-derived.');
    }

    $messages = ['sales' => [], 'autoreply' => []];
    $a = kssmi_outbox_enqueue($messages, ['inquiry_id' => 'synthetic-old'], [], 'old', $now);
    $b = kssmi_outbox_enqueue($messages, ['inquiry_id' => 'synthetic-recent'], [], 'recent', $now);
    $c = kssmi_outbox_enqueue($messages, ['inquiry_id' => 'synthetic-invalid'], [], 'invalid', $now);
    $d = kssmi_outbox_enqueue($messages, ['inquiry_id' => 'synthetic-locked'], [], 'locked', $now);
    foreach ([$a, $d] as $accepted) {
        $path = $root . '/outbox/' . $accepted['id'];
        $job = kssmi_outbox_load_job($path);
        $job['created_at'] = $now - 31 * 86400;
        $job['steps']['sales']['state'] = 'dead';
        privacy_assert(kssmi_outbox_save_job($path, $job), 'Write expired fixture.');
        file_put_contents($path . '/att-0', 'synthetic attachment');
    }
    file_put_contents($root . '/outbox/' . $c['id'] . '/job.json', 'unreadable synthetic job');
    $lock = fopen($root . '/outbox/' . $d['id'] . '/.lock', 'c');
    flock($lock, LOCK_EX);
    $pruned = kssmi_outbox_prune($now);
    privacy_assert($pruned['deleted'] === 1 && $pruned['busy'] === 1 && $pruned['invalid'] === 1, 'Prune must delete old jobs and preserve busy/unknown ones.');
    privacy_assert(!is_dir($root . '/outbox/' . $a['id']) && is_dir($root . '/outbox/' . $b['id']), 'Retention boundary must preserve recent jobs.');
    flock($lock, LOCK_UN); fclose($lock);
    privacy_assert(kssmi_outbox_prune($now)['deleted'] === 1, 'An expired job may be deleted once unlocked.');

    $kept = kssmi_email_logs_within_retention([
        ['unix_time' => $now - 731 * 86400], ['unix_time' => $now], ['timestamp' => 'unknown legacy date'],
    ], $now);
    privacy_assert(count($kept) === 2, 'Logs must prune expired records and preserve unknown legacy dates for review.');
    $destination = short_link_destination_create('https://drive.google.com/synthetic', 'test');
    $link = short_link_create_distribution((int)$destination['destination']['id'], ['recipient_ref' => 'synthetic-only'], 'test');
    short_link_record_open((int)$link['id'], 'synthetic-only', false, 'US');
    $db = short_link_db();
    $db->exec("UPDATE short_link_events SET opened_at = '2000-01-01T00:00:00Z'");
    privacy_assert(short_link_prune_expired($db, $now, true) === 1, 'Per-open data older than 90 days must expire.');
    privacy_assert((int)$db->query('SELECT COUNT(*) FROM short_link_events')->fetchColumn() === 0, 'No expired per-open row may remain.');
    privacy_assert(short_link_get((int)$link['id']) !== null, 'Pruning opens must preserve working redirects.');
    $proofRecord = ['inquiry_id' => 'synthetic-consent', 'form' => ['email' => 'person@example.com', 'inquiry_consent' => kssmi_inquiry_consent_record($valid, 'en', $now)]];
    $accepted = kssmi_outbox_enqueue($messages, $proofRecord, [], 'proof', $now);
    privacy_assert($accepted['ok'], 'A consented synthetic job must persist both queue and receipt.');
    $receipts = glob($root . '/inquiry-consents/*.json') ?: [];
    privacy_assert(count($receipts) === 1, 'An independent receipt is required.');
    $receipt = json_decode((string)file_get_contents($receipts[0]), true);
    $receipt['unix_time'] = $now - 731 * 86400;
    file_put_contents($receipts[0], json_encode($receipt));
    privacy_assert(kssmi_inquiry_prune_consent_receipts($root . '/outbox', $now) === 1, 'Independent receipts must expire after 730 days.');
    rmdir($root . '/inquiry-consents');
    file_put_contents($root . '/inquiry-consents', 'blocked synthetic receipt store');
    privacy_assert(!kssmi_outbox_enqueue($messages, $proofRecord, [], 'blocked-proof', $now)['ok'], 'A receipt write failure must not accept a new inquiry.');
    echo "Inquiry consent and privacy-retention tests passed.\n";
} finally {
    // Close process-owned PDO on Windows before the fixture is removed.
    unset($db);
    if (PHP_OS_FAMILY !== 'Windows') privacy_remove_fixture($root, (string)realpath($root));
    else echo "Windows SQLite fixture retained for cleanup: {$root}\n";
}
