<?php
declare(strict_types=1);

/**
 * Contact Core runtime tests (优化-001 阶段 1).
 *
 * Verifies the Kssmi Contact Core model (public/api/vjt-helpers.php):
 *   - contact_events never stores privacy columns (ip/user_agent/referrer/
 *     utm/message/contact_url);
 *   - analytics identifiers are optional enrichment, validated against
 *     existing visitor/session/pageview pairs, never trusted from the browser;
 *   - event_id deduplication keeps one row per conversion;
 *   - retention classes: intent_short vs customer_inquiry, with layered
 *     cleanup (Journey cleanup detaches, never deletes Core rows);
 *   - CSV formula injection is neutralized.
 *
 * Run: php scripts/test-contact-core.php
 */

function kssmi_contact_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_contact_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_contact_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-contact-core-' . bin2hex(random_bytes(6));
kssmi_contact_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();

    // ── Privacy: Core events never store browser/privacy columns ──
    $columns = [];
    foreach ($db->query('PRAGMA table_info(contact_events)') as $row) {
        $columns[] = $row['name'];
    }
    foreach (['ip', 'user_agent', 'referrer', 'utm_source', 'message', 'contact_url'] as $forbidden) {
        kssmi_contact_assert(!in_array($forbidden, $columns, true), "privacy column {$forbidden} must not exist");
    }

    // ── Deduplication + browser-controlled IDs are never trusted ──
    $eventId = 'vjtce_' . str_repeat('a', 32);
    $r = vjt_add_contact_event([
        'event_id' => $eventId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => 'https://kssmi.com/contact/?campaign=ignored',
        'vjt_visitor_id' => 'spoofed',
        'vjt_session_id' => 'spoofed',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'first contact event inserts');
    $r = vjt_add_contact_event([
        'event_id' => $eventId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'duplicate', 'duplicate event_id is ignored');

    $row = $db->query("SELECT * FROM contact_events WHERE event_id = " . $db->quote($eventId))->fetch();
    kssmi_contact_assert($row['vjt_visitor_id'] === '' && $row['vjt_session_id'] === '', 'invalid analytics identity is discarded');
    kssmi_contact_assert($row['page_path'] === '/contact/', 'query data is removed from the stored page path');
    kssmi_contact_assert($row['retention_class'] === 'intent_short', 'contact intent uses short retention');

    // ── Verified enrichment: existing visitor/session/pageview pair ──
    $db->exec("INSERT INTO visitors (visitor_id, first_seen_at, last_seen_at) VALUES ('vjtv_testuser01', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $db->exec("INSERT INTO sessions (session_id, visitor_id, started_at, last_seen_at) VALUES ('vjts_testsession01', 'vjtv_testuser01', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    $db->exec("INSERT INTO pageviews (session_id, visitor_id, url, visited_at, step_order)
        VALUES ('vjts_testsession01', 'vjtv_testuser01', 'https://kssmi.com/contact/', '2026-01-01 00:00:00', 4)");

    $r = vjt_add_contact_event([
        'event_id' => 'vjtce_' . str_repeat('b', 32),
        'channel' => 'mailto',
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => '/contact/',
        'vjt_visitor_id' => 'vjtv_testuser01',
        'vjt_session_id' => 'vjts_testsession01',
        'journey_step' => 4,
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'consented event inserts');
    $linked = $db->query("SELECT * FROM contact_events WHERE event_id = 'vjtce_" . str_repeat('b', 32) . "'")->fetch();
    kssmi_contact_assert($linked['vjt_visitor_id'] === 'vjtv_testuser01', 'existing visitor may be enriched');
    kssmi_contact_assert($linked['vjt_session_id'] === 'vjts_testsession01', 'existing session may be enriched');
    kssmi_contact_assert((int)$linked['journey_step'] === 4, 'existing page step may be enriched');

    // ── Inquiry class + nonexistent link is discarded, row still stored ──
    $r = vjt_add_contact_event([
        'event_id' => 'vjtce_' . str_repeat('c', 32),
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'vjt_visitor_id' => 'vjtv_missinguser01',
        'vjt_session_id' => 'vjts_missingsession1',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'event survives invalid optional analytics link');
    $unlinked = $db->query("SELECT vjt_session_id FROM contact_events WHERE event_id = 'vjtce_" . str_repeat('c', 32) . "'")->fetch();
    kssmi_contact_assert($unlinked['vjt_session_id'] === '', 'nonexistent analytics link is discarded');
    $inquiryClass = $db->query("SELECT retention_class FROM contact_events WHERE event_id = 'vjtce_" . str_repeat('c', 32) . "'")->fetch();
    kssmi_contact_assert($inquiryClass['retention_class'] === 'customer_inquiry', 'confirmed inquiry uses long retention');

    $list = vjt_get_contact_events_list(['page' => 1, 'per_page' => 100]);
    kssmi_contact_assert((int)$list['total'] === 3, 'deduplicated event list total');

    // ── Layered retention: intent short vs inquiry long ──
    $oldIntentId = 'vjtce_' . str_repeat('d', 32);
    $oldInquiryId = 'vjtce_' . str_repeat('e', 32);
    kssmi_contact_assert(($r = vjt_add_contact_event([
        'event_id' => $oldIntentId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
    ]))['result'] === 'stored', 'old intent fixture inserts');
    kssmi_contact_assert(($r = vjt_add_contact_event([
        'event_id' => $oldInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
    ]))['result'] === 'stored', 'old inquiry fixture inserts');

    $setOccurredAt = $db->prepare('UPDATE contact_events SET occurred_at = ? WHERE event_id = ?');
    $setOccurredAt->execute([gmdate('Y-m-d H:i:s', time() - 100 * 86400), $oldIntentId]);
    $setOccurredAt->execute([gmdate('Y-m-d H:i:s', time() - 100 * 86400), 'vjtce_' . str_repeat('c', 32)]);
    $setOccurredAt->execute([gmdate('Y-m-d H:i:s', time() - 800 * 86400), $oldInquiryId]);

    vjt_save_settings([
        'session_timeout' => 30,
        'retention_days' => 90,
        'contact_intent_retention_days' => 90,
        'contact_inquiry_retention_days' => 730,
        'enable_geo' => true,
        'heartbeat_seconds' => 45,
        'excluded_ips' => '',
    ]);
    vjt_meta_set('last_cleanup', 0);
    vjt_auto_cleanup();

    $eventExists = $db->prepare('SELECT COUNT(*) FROM contact_events WHERE event_id = ?');
    $eventExists->execute([$oldIntentId]);
    kssmi_contact_assert((int)$eventExists->fetchColumn() === 0, 'intent older than short retention is deleted');
    $eventExists->execute(['vjtce_' . str_repeat('c', 32)]);
    kssmi_contact_assert((int)$eventExists->fetchColumn() === 1, 'inquiry older than Journey retention remains');
    $eventExists->execute([$oldInquiryId]);
    kssmi_contact_assert((int)$eventExists->fetchColumn() === 0, 'inquiry older than long retention is deleted');
    $eventExists->closeCursor();

    // ── Journey cleanup detaches (never deletes) expired Core linkage ──
    $oldAnalyticsAt = gmdate('Y-m-d H:i:s', time() - 120 * 86400);
    $db->prepare("INSERT INTO visitors
        (visitor_id, first_seen_at, last_seen_at)
        VALUES ('vjtv_cleanup', ?, ?)")->execute([$oldAnalyticsAt, $oldAnalyticsAt]);
    $db->prepare("INSERT INTO sessions
        (session_id, visitor_id, started_at, last_seen_at)
        VALUES ('vjts_cleanup', 'vjtv_cleanup', ?, ?)")->execute([$oldAnalyticsAt, $oldAnalyticsAt]);
    $db->exec("INSERT INTO pageviews
        (session_id, visitor_id, url, visited_at, step_order)
        VALUES ('vjts_cleanup', 'vjtv_cleanup', 'https://kssmi.com/contact/', '$oldAnalyticsAt', 2)");

    $manualInquiryId = 'vjtce_' . str_repeat('f', 32);
    kssmi_contact_assert(($r = vjt_add_contact_event([
        'event_id' => $manualInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'vjt_visitor_id' => 'vjtv_cleanup',
        'vjt_session_id' => 'vjts_cleanup',
    ]))['result'] === 'stored', 'manual cleanup inquiry fixture inserts');
    $setOccurredAt->execute([$oldAnalyticsAt, $manualInquiryId]);

    vjt_cleanup_old_data(90);
    $preserved = $db->query("SELECT vjt_visitor_id, vjt_session_id, journey_step
        FROM contact_events WHERE event_id = '$manualInquiryId'")->fetch();
    kssmi_contact_assert(is_array($preserved), 'Journey cleanup preserves long-retention inquiry row');
    kssmi_contact_assert(
        $preserved['vjt_visitor_id'] === ''
            && $preserved['vjt_session_id'] === ''
            && (int)$preserved['journey_step'] === 0,
        'Journey cleanup only detaches expired analytics linkage'
    );
    kssmi_contact_assert(
        (int)$db->query("SELECT COUNT(*) FROM visitors WHERE visitor_id = 'vjtv_cleanup'")->fetchColumn() === 0,
        'Journey cleanup still removes expired analytics visitor'
    );

    // ── CSV formula injection neutralization ──
    foreach (['=1+1', '+SUM(A1:A2)', '-2+3', '@IMPORTDATA("https://example.test")'] as $formula) {
        kssmi_contact_assert(
            vjt_csv_safe_cell($formula) === "'" . $formula,
            'CSV formula marker is neutralized: ' . $formula[0]
        );
    }
    kssmi_contact_assert(vjt_csv_safe_cell('plain text') === 'plain text', 'ordinary CSV text remains unchanged');
    kssmi_contact_assert(vjt_csv_safe_cell(42) === 42, 'numeric CSV values remain numeric');
    kssmi_contact_assert(
        vjt_csv_safe_row(['=cmd', 'safe', 7]) === ["'=cmd", 'safe', 7],
        'CSV row protection is applied to every cell'
    );

    // ── Leads: linked events group under the Visitor, unlinked stay separate ──
    $db->exec('DELETE FROM contact_events');
    $db->exec('DELETE FROM pageviews');
    $db->exec('DELETE FROM sessions');
    $db->exec('DELETE FROM visitors');

    $adminVisitorId = 'vjtv_' . str_repeat('g', 16);
    $adminSessionId = 'vjts_' . str_repeat('h', 16);
    $now = gmdate('Y-m-d H:i:s');
    $insertVisitor = $db->prepare('INSERT INTO visitors
        (visitor_id, first_seen_at, last_seen_at) VALUES (?, ?, ?)');
    $insertVisitor->execute([$adminVisitorId, $now, $now]);
    $insertSession = $db->prepare('INSERT INTO sessions
        (session_id, visitor_id, started_at, last_seen_at) VALUES (?, ?, ?, ?)');
    $insertSession->execute([$adminSessionId, $adminVisitorId, $now, $now]);
    $insertPage = $db->prepare('INSERT INTO pageviews
        (session_id, visitor_id, url, visited_at, step_order) VALUES (?, ?, ?, ?, ?)');
    $insertPage->execute([$adminSessionId, $adminVisitorId, 'https://kssmi.com/contact/', $now, 3]);

    $linkedIntentId = 'vjtce_' . str_repeat('g', 32);
    $linkedInquiryId = 'vjtce_' . str_repeat('h', 32);
    $unlinkedIntentId = 'vjtce_' . str_repeat('i', 32);
    $unlinkedInquiryId = 'vjtce_' . str_repeat('j', 32);
    foreach ([
        [$linkedIntentId, 'whatsapp', 'intent'],
        [$linkedInquiryId, 'inquiry', 'success'],
    ] as [$id, $channel, $status]) {
        $r = vjt_add_contact_event([
            'event_id' => $id,
            'channel' => $channel,
            'event_type' => $status === 'success' ? 'submission_success' : 'open_intent',
            'status' => $status,
            'page_path' => '/contact/',
            'vjt_visitor_id' => $adminVisitorId,
            'vjt_session_id' => $adminSessionId,
        ]);
        kssmi_contact_assert(($r['result'] ?? '') === 'stored', "linked {$channel}/{$status} management fixture inserts");
    }
    foreach ([
        [$unlinkedIntentId, 'mailto', 'intent'],
        [$unlinkedInquiryId, 'inquiry', 'success'],
    ] as [$id, $channel, $status]) {
        $r = vjt_add_contact_event([
            'event_id' => $id,
            'channel' => $channel,
            'event_type' => $status === 'success' ? 'submission_success' : 'open_intent',
            'status' => $status,
            'page_path' => '/contact/',
        ]);
        kssmi_contact_assert(($r['result'] ?? '') === 'stored', "unlinked {$channel}/{$status} management fixture inserts");
    }

    $leadResult = vjt_get_leads_list(['status' => 'contact', 'page' => 1, 'per_page' => 100]);
    kssmi_contact_assert((int)$leadResult['total'] === 3, 'linked events group while unlinked events remain separate Leads');
    $linkedLead = null;
    $unlinkedLeadKeys = [];
    foreach ($leadResult['items'] as $lead) {
        if (($lead['lead_key'] ?? '') === 'visitor:' . $adminVisitorId) {
            $linkedLead = $lead;
        } elseif (strpos((string)($lead['lead_key'] ?? ''), 'event:') === 0) {
            $unlinkedLeadKeys[] = $lead['lead_key'];
        }
    }
    kssmi_contact_assert(is_array($linkedLead), 'linked canonical Lead is keyed by Visitor');
    kssmi_contact_assert((int)$linkedLead['event_count'] === 2, 'linked canonical Lead contains both Core events');
    kssmi_contact_assert($linkedLead['display_status'] === 'success', 'successful inquiry wins canonical Lead status');
    kssmi_contact_assert(count($unlinkedLeadKeys) === 2, 'unlinked Core events are not merged');

    fwrite(STDOUT, "Contact Core runtime tests passed.\n");
} finally {
    kssmi_contact_remove_tree($testDirectory);
}
