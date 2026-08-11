<?php
declare(strict_types=1);

/**
 * VJT submission event-state runtime tests (优化-001 阶段 1).
 *
 * Verifies the submissions lifecycle model: browser conversion retries keep
 * ONE exact event ID, an 'attempt' can be promoted to a final 'success' or
 * 'error' by a server result, a late 'attempt' cannot downgrade a final state,
 * and a different lifecycle (visitor/session/plugin) cannot mutate the row.
 *
 * Also verifies the additive schema migration: a legacy submissions table
 * without event_id must gain the column and keep the pre-existing row.
 *
 * Run: php scripts/test-vjt-event-state.php
 */

function kssmi_state_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_state_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_state_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-vjt-state-' . bin2hex(random_bytes(6));
kssmi_state_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

// Simulate the currently deployed schema before event_id exists. The
// migration must add structure without rewriting or deleting the old row.
$legacy = new PDO('sqlite:' . $testDirectory . DIRECTORY_SEPARATOR . 'vjt.sqlite');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec("CREATE TABLE submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitor_id TEXT DEFAULT '', session_id TEXT DEFAULT '',
    form_plugin TEXT DEFAULT '', form_id TEXT DEFAULT '', form_name TEXT DEFAULT '',
    submit_page TEXT DEFAULT '', submit_title TEXT DEFAULT '', submitted_at TEXT DEFAULT '',
    status TEXT DEFAULT 'attempt', contact_url TEXT DEFAULT '', ip TEXT DEFAULT '',
    country TEXT DEFAULT '', city TEXT DEFAULT '', region TEXT DEFAULT '', calling_code TEXT DEFAULT ''
)");
$legacy->exec("INSERT INTO submissions
    (visitor_id, session_id, form_plugin, submitted_at, status)
    VALUES ('legacy-v', 'legacy-s', 'whatsapp', '2099-01-01 00:00:00', 'success')");
$legacy->exec("CREATE TABLE contact_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    channel TEXT NOT NULL,
    event_type TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    page_path TEXT DEFAULT '', placement TEXT DEFAULT '', product_sku TEXT DEFAULT '',
    site_language TEXT DEFAULT '', status TEXT NOT NULL,
    vjt_visitor_id TEXT DEFAULT '', vjt_session_id TEXT DEFAULT '',
    journey_step INTEGER DEFAULT 0, retention_class TEXT NOT NULL
)");
$legacy->exec("INSERT INTO contact_events
    (event_id, channel, event_type, occurred_at, status, retention_class) VALUES
    ('vjtce_legacyinquirysuccess', 'inquiry', 'submission_success', '2026-01-01 00:00:00', 'success', 'customer_inquiry'),
    ('vjtce_legacyinquiryerror', 'inquiry', 'submission_error', '2026-01-01 00:00:01', 'error', 'customer_inquiry'),
    ('vjtce_legacypublicintent', 'whatsapp', 'open_intent', '2026-01-01 00:00:02', 'intent', 'intent_short')");
$legacy = null;

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();

    kssmi_state_assert(vjt_column_exists('submissions', 'event_id'), 'migration adds event_id');
    kssmi_state_assert((int)$db->query("SELECT COUNT(*) c FROM submissions WHERE visitor_id='legacy-v'")->fetch()['c'] === 1, 'schema migration preserves existing row');
    kssmi_state_assert(
        (int)$db->query("SELECT server_verified FROM contact_events WHERE event_id='vjtce_legacyinquirysuccess'")->fetchColumn() === 1,
        'migration preserves historical server-side Inquiry successes as verified'
    );
    kssmi_state_assert(
        (int)$db->query("SELECT server_verified FROM contact_events WHERE event_id='vjtce_legacyinquiryerror'")->fetchColumn() === 1,
        'migration preserves historical server-side Inquiry errors as verified outcomes'
    );
    kssmi_state_assert(
        (int)$db->query("SELECT server_verified FROM contact_events WHERE event_id='vjtce_legacypublicintent'")->fetchColumn() === 0,
        'migration leaves historical public intent telemetry unverified'
    );
    kssmi_state_assert(
        (int)$db->query('SELECT COUNT(*) FROM canonical_contact_events')->fetchColumn() === 1,
        'only the migrated historical Inquiry success is canonical'
    );

    // F-01 ownership triggers require every new submission to reference an
    // existing session belonging to the same visitor. The legacy row above is
    // intentionally preserved, while all new lifecycle fixtures use a valid
    // server-owned pair.
    vjt_upsert_visitor([
        'visitor_id' => 'vjtv_new',
        'site_language' => 'EN',
    ]);
    vjt_upsert_session([
        'session_id' => 'vjts_new',
        'visitor_id' => 'vjtv_new',
        'landing_url' => 'https://kssmi.com/contact/',
    ]);

    $event = 'vjtev_' . str_repeat('a', 32);
    $base = [
        'event_id' => $event,
        'visitor_id' => 'vjtv_new',
        'session_id' => 'vjts_new',
        'form_plugin' => 'kssmi-inquiry',
        'form_id' => 'inquiry-form',
        'form_name' => 'Inquiry Form',
        'submit_page' => '/contact/',
    ];
    kssmi_state_assert(vjt_add_submission($base + ['status' => 'attempt']) === 'stored', 'attempt inserts');
    kssmi_state_assert(vjt_add_submission($base + ['status' => 'success', 'country' => 'US']) === 'updated', 'final success promotes attempt');
    kssmi_state_assert(vjt_add_submission($base + ['status' => 'attempt']) === 'duplicate', 'late attempt cannot downgrade final state');

    $row = $db->query("SELECT * FROM submissions WHERE event_id = " . $db->quote($event))->fetch();
    kssmi_state_assert($row['status'] === 'success', 'one row has final success state');
    kssmi_state_assert($row['country'] === 'US', 'final server metadata enriches attempt');
    kssmi_state_assert((int)$db->query("SELECT COUNT(*) c FROM submissions WHERE event_id = " . $db->quote($event))->fetch()['c'] === 1, 'attempt and success share one row');

    kssmi_state_assert(vjt_add_submission(array_merge($base, [
        'visitor_id' => 'vjtv_attacker',
        'status' => 'error',
    ])) === 'duplicate', 'event ID cannot update a different lifecycle');
    kssmi_state_assert($db->query("SELECT status FROM submissions WHERE event_id = " . $db->quote($event))->fetch()['status'] === 'success', 'conflicting lifecycle cannot mutate final state');

    $errorEvent = 'vjtev_' . str_repeat('b', 32);
    kssmi_state_assert(vjt_add_submission(array_merge($base, ['event_id' => $errorEvent, 'status' => 'attempt'])) === 'stored', 'second attempt inserts');
    kssmi_state_assert(vjt_add_submission(array_merge($base, ['event_id' => $errorEvent, 'status' => 'error'])) === 'updated', 'attempt promotes to error');

    $intentEvent = 'vjtev_' . str_repeat('c', 32);
    $intent = [
        'event_id' => $intentEvent,
        'visitor_id' => 'vjtv_new',
        'session_id' => 'vjts_new',
        'form_plugin' => 'whatsapp',
        'status' => 'intent',
    ];
    kssmi_state_assert(vjt_add_submission($intent) === 'stored', 'contact intent inserts');
    kssmi_state_assert(vjt_add_submission($intent) === 'duplicate', 'contact retry deduplicates');

    // KPI semantics match the production dashboard: a counted KPI is a
    // confirmed inquiry success or a WhatsApp/mailto contact intent; the
    // legacy whatsapp/success fixture row is neither.
    $kpi = (int)$db->query("SELECT COUNT(*) c FROM submissions WHERE
        (form_plugin = 'kssmi-inquiry' AND status = 'success')
        OR (form_plugin IN ('whatsapp', 'mailto') AND status = 'intent')")->fetch()['c'];
    kssmi_state_assert($kpi === 2, 'new KPI counts inquiry success plus contact intent only');
    $lifecycle = (int)$db->query("SELECT COUNT(*) c FROM submissions WHERE
        (form_plugin = 'kssmi-inquiry' AND status IN ('attempt', 'success', 'error'))
        OR (form_plugin IN ('whatsapp', 'mailto') AND status = 'intent')")->fetch()['c'];
    kssmi_state_assert($lifecycle === 3, 'new lifecycle includes final success, final error and intent');

    fwrite(STDOUT, "VJT event-state runtime tests passed.\n");
} finally {
    kssmi_state_remove_tree($testDirectory);
}
