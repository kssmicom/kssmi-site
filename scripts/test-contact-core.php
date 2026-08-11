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
 *   - public click telemetry remains unverified while authoritative Inquiry
 *     success is written through the server-only verified wrapper;
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
    kssmi_contact_assert((int)$row['server_verified'] === 0, 'public contact intent is never server verified');

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

    // ── Verified Inquiry survives without optional Journey enrichment ──
    $verifiedInquiryId = 'vjtcv_' . str_repeat('c', 64);
    $r = vjt_add_verified_contact_event([
        'event_id' => $verifiedInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'vjt_visitor_id' => 'vjtv_missinguser01',
        'vjt_session_id' => 'vjts_missingsession1',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'verified event survives invalid optional analytics link');
    $unlinked = $db->query("SELECT vjt_session_id, server_verified FROM contact_events WHERE event_id = " .
        $db->quote($verifiedInquiryId))->fetch();
    kssmi_contact_assert($unlinked['vjt_session_id'] === '', 'nonexistent analytics link is discarded');
    kssmi_contact_assert((int)$unlinked['server_verified'] === 1, 'server Inquiry outcome is marked verified');
    $inquiryClass = $db->query("SELECT retention_class FROM contact_events WHERE event_id = " .
        $db->quote($verifiedInquiryId))->fetch();
    kssmi_contact_assert($inquiryClass['retention_class'] === 'customer_inquiry', 'confirmed inquiry uses long retention');

    $list = vjt_get_contact_events_list(['page' => 1, 'per_page' => 100]);
    kssmi_contact_assert((int)$list['total'] === 3, 'deduplicated event list total');

    // ── Layered retention: intent short vs inquiry long ──
    $oldIntentId = 'vjtce_' . str_repeat('d', 32);
    $oldInquiryId = 'vjtcv_' . str_repeat('e', 64);
    kssmi_contact_assert(($r = vjt_add_contact_event([
        'event_id' => $oldIntentId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
    ]))['result'] === 'stored', 'old intent fixture inserts');
    kssmi_contact_assert(($r = vjt_add_verified_contact_event([
        'event_id' => $oldInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
    ]))['result'] === 'stored', 'old inquiry fixture inserts');

    $setOccurredAt = $db->prepare('UPDATE contact_events SET occurred_at = ? WHERE event_id = ?');
    $setOccurredAt->execute([gmdate('Y-m-d H:i:s', time() - 100 * 86400), $oldIntentId]);
    $setOccurredAt->execute([gmdate('Y-m-d H:i:s', time() - 100 * 86400), $verifiedInquiryId]);
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
    $eventExists->execute([$verifiedInquiryId]);
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

    $manualInquiryId = 'vjtcv_' . str_repeat('f', 64);
    kssmi_contact_assert(($r = vjt_add_verified_contact_event([
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

    // ── Leads: only server-verified Inquiry success is canonical ──
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
    $linkedInquiryId = 'vjtcv_' . str_repeat('h', 64);
    $unlinkedIntentId = 'vjtce_' . str_repeat('i', 32);
    $unlinkedInquiryId = 'vjtcv_' . str_repeat('j', 64);

    $r = vjt_add_contact_event([
        'event_id' => $linkedIntentId,
        'channel' => 'whatsapp',
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => '/contact/',
        'vjt_visitor_id' => $adminVisitorId,
        'vjt_session_id' => $adminSessionId,
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'linked public intent fixture inserts');
    $r = vjt_add_verified_contact_event([
        'event_id' => $linkedInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'vjt_visitor_id' => $adminVisitorId,
        'vjt_session_id' => $adminSessionId,
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'linked verified Inquiry fixture inserts');
    $r = vjt_add_contact_event([
        'event_id' => $unlinkedIntentId,
        'channel' => 'mailto',
        'event_type' => 'open_intent',
        'status' => 'intent',
        'page_path' => '/contact/',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'unlinked public intent fixture inserts');
    $r = vjt_add_verified_contact_event([
        'event_id' => $unlinkedInquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
    ]);
    kssmi_contact_assert(($r['result'] ?? '') === 'stored', 'unlinked verified Inquiry fixture inserts');

    $rawResult = vjt_get_contact_events_list(['page' => 1, 'per_page' => 100]);
    kssmi_contact_assert((int)$rawResult['total'] === 4, 'raw Core list retains public telemetry and verified outcomes');
    kssmi_contact_assert(
        (int)$db->query('SELECT COUNT(*) FROM canonical_contact_events')->fetchColumn() === 2,
        'canonical view contains only the two verified Inquiry successes'
    );

    $leadResult = vjt_get_leads_list(['status' => 'contact', 'page' => 1, 'per_page' => 100]);
    kssmi_contact_assert((int)$leadResult['total'] === 2, 'verified linked and unlinked Inquiry outcomes form canonical Leads');
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
    kssmi_contact_assert((int)$linkedLead['event_count'] === 1, 'public linked intent is excluded from the canonical Lead');
    kssmi_contact_assert($linkedLead['display_status'] === 'success', 'verified Inquiry determines canonical Lead status');
    kssmi_contact_assert(count($unlinkedLeadKeys) === 1, 'only unlinked verified Inquiry remains a standalone Lead');

    fwrite(STDOUT, "Contact Core runtime tests passed.\n");
} finally {
    kssmi_contact_remove_tree($testDirectory);
}
