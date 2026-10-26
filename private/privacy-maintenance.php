<?php
declare(strict_types=1);

require_once __DIR__ . '/inquiry-outbox.php';
require_once __DIR__ . '/inquiry-privacy.php';
require_once __DIR__ . '/email-log-store.php';

/** No correspondence content is returned or sent. Operator runs as the site account. */
function kssmi_privacy_maintenance(string $emailLogPath, bool $includeShortLinks = true): array {
    $result = ['outbox' => kssmi_outbox_prune(), 'consent_receipts' => kssmi_inquiry_prune_consent_receipts(kssmi_outbox_dir()), 'email_logs' => 0, 'short_link_events' => 0];
    if (is_file($emailLogPath)) {
        $removed = 0;
        $write = kssmi_email_logs_mutate($emailLogPath, static function ($logs) use (&$removed) {
            $kept = kssmi_email_logs_within_retention($logs);
            $removed = count($logs) - count($kept);
            return $kept;
        });
        if (!$write['ok']) throw new RuntimeException('Email-log retention could not obtain a safe write.');
        $result['email_logs'] = $removed;
    }
    if ($includeShortLinks) {
        require_once __DIR__ . '/short-link-store.php';
        // Never create a new production store just to perform retention.
        if (is_file(short_link_data_dir() . '/shortlinks.sqlite')) {
            $result['short_link_events'] = short_link_prune_expired(short_link_db(), null, true);
        }
    }
    return $result;
}
