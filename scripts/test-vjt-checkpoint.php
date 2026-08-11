<?php
declare(strict_types=1);

/**
 * VJT checkpoint regression test (Phase 0, 优化-001).
 *
 * Reproduces the SQLite lock failure from VJT-008: prepared statements kept
 * open (in scope) while vjt_cleanup_old_data() / vjt_auto_cleanup() /
 * vjt_wipe_all_data() run, so PRAGMA wal_checkpoint(TRUNCATE) faces active
 * statements on the same connection. Before the Phase 0 fix this produced a
 * fatal "database table is locked"; after the fix the checkpoint is
 * best-effort (logged, never fatal) and this suite must stay green.
 *
 * Asserts:
 *   1. No fatal under the VJT-008 open-statement scenario (5 iterations).
 *   2. No fatal with an ACTIVE (partially consumed) SELECT cursor in scope —
 *      the true VJT-008 trigger; completed INSERT statements release their
 *      read lock at SQLITE_DONE and never blocked TRUNCATE.
 *   3. PRAGMA integrity_check = ok after every cleanup/wipe.
 *   4. Layered retention: Contact Core customer_inquiry rows with expired
 *      analytics linkage survive vjt_cleanup_old_data() and vjt_auto_cleanup()
 *      with vjt_visitor_id='', vjt_session_id='', journey_step=0 (detach,
 *      not delete); the expired analytics visitor/session are removed.
 *   5. vjt_auto_cleanup() with a 0-day last_cleanup gap exercises the same
 *      safe path without fatal.
 *   6. A concurrent reader on another connection does not stall the
 *      request-path checkpoint for the full busy_timeout (~5s): the guard
 *      bounds the TRUNCATE wait and degrades to PASSIVE quickly.
 *   7. vjt_wipe_all_data() (admin destructive) also survives a busy WAL.
 *
 * Run: php scripts/test-vjt-checkpoint.php
 */

function kssmi_vjt_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_vjt_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_vjt_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-vjt-checkpoint-' . bin2hex(random_bytes(6));
kssmi_vjt_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();

    $now = gmdate('Y-m-d H:i:s');
    $old = gmdate('Y-m-d H:i:s', time() - 120 * 86400); // expired for a 90-day Journey cleanup

    // ── Fixtures: realistic analytics + Contact Core layered retention ──
    // Statements are prepared ONCE and deliberately kept open (never
    // closeCursor / never unset) for the whole suite, mirroring the VJT-008
    // call-site condition that made wal_checkpoint(TRUNCATE) fatal.
    $insertVisitor = $db->prepare('INSERT INTO visitors (visitor_id, first_seen_at, last_seen_at) VALUES (?, ?, ?)');
    $insertSession = $db->prepare('INSERT INTO sessions (session_id, visitor_id, started_at, last_seen_at) VALUES (?, ?, ?, ?)');
    $insertPage = $db->prepare('INSERT INTO pageviews (session_id, visitor_id, url, visited_at, step_order) VALUES (?, ?, ?, ?, ?)');
    $insertVisitor->execute(['vjtv_kssmi_fresh', $now, $now]);
    $insertVisitor->execute(['vjtv_kssmi_old', $old, $old]);
    $insertSession->execute(['vjts_kssmi_fresh', 'vjtv_kssmi_fresh', $now, $now]);
    $insertSession->execute(['vjts_kssmi_old', 'vjtv_kssmi_old', $old, $old]);
    $insertPage->execute(['vjts_kssmi_fresh', 'vjtv_kssmi_fresh', 'https://kssmi.com/contact/', $now, 1]);
    $insertPage->execute(['vjts_kssmi_old', 'vjtv_kssmi_old', 'https://kssmi.com/contact/', $old, 2]);

    // Long-retention business event (customer_inquiry) linked to the OLD
    // analytics identity: Journey cleanup must detach the linkage, keep the row.
    $inquiryId = 'vjtcv_kssmiphase0inquiry';
    $addResult = vjt_add_verified_contact_event([
        'event_id' => $inquiryId,
        'channel' => 'inquiry',
        'event_type' => 'submission_success',
        'status' => 'success',
        'page_path' => '/contact/',
        'vjt_visitor_id' => 'vjtv_kssmi_old',
        'vjt_session_id' => 'vjts_kssmi_old',
        'journey_step' => 2,
    ]);
    kssmi_vjt_assert(is_array($addResult) && ($addResult['result'] ?? '') === 'stored', 'long-retention inquiry fixture inserts');
    $setOccurredAt = $db->prepare('UPDATE contact_events SET occurred_at = ? WHERE event_id = ?');
    $setOccurredAt->execute([$old, $inquiryId]);
    kssmi_vjt_assert((int)$db->query("SELECT COUNT(*) FROM contact_events WHERE event_id = " . $db->quote($inquiryId))->fetchColumn() === 1, 'fixture row is queryable');

    // ── VJT-008 reproduction: open statements stay in scope during cleanup ──
    for ($i = 0; $i < 5; $i++) {
        vjt_cleanup_old_data(90);

        kssmi_vjt_assert($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'integrity_check ok after vjt_cleanup_old_data (iter ' . ($i + 1) . ')');

        $preserved = $db->query("SELECT vjt_visitor_id, vjt_session_id, journey_step
            FROM contact_events WHERE event_id = " . $db->quote($inquiryId))->fetch();
        kssmi_vjt_assert(is_array($preserved), 'Journey cleanup preserves long-retention inquiry row (iter ' . ($i + 1) . ')');
        kssmi_vjt_assert(
            $preserved['vjt_visitor_id'] === ''
                && $preserved['vjt_session_id'] === ''
                && (int)$preserved['journey_step'] === 0,
            'Journey cleanup only detaches expired analytics linkage (iter ' . ($i + 1) . ')'
        );
        kssmi_vjt_assert((int)$db->query("SELECT COUNT(*) FROM visitors WHERE visitor_id = 'vjtv_kssmi_old'")->fetchColumn() === 0, 'expired analytics visitor removed (iter ' . ($i + 1) . ')');
        kssmi_vjt_assert((int)$db->query("SELECT COUNT(*) FROM visitors WHERE visitor_id = 'vjtv_kssmi_fresh'")->fetchColumn() === 1, 'fresh visitor survives (iter ' . ($i + 1) . ')');

        // Re-seed the expired identity + re-link so each iteration exercises a
        // real detach+delete cycle (keeps the open statements active, too).
        $db->prepare('INSERT OR IGNORE INTO visitors (visitor_id, first_seen_at, last_seen_at) VALUES (?, ?, ?)')->execute(['vjtv_kssmi_old', $old, $old]);
        $db->prepare('INSERT OR IGNORE INTO sessions (session_id, visitor_id, started_at, last_seen_at) VALUES (?, ?, ?, ?)')->execute(['vjts_kssmi_old', 'vjtv_kssmi_old', $old, $old]);
        $db->prepare('UPDATE contact_events SET vjt_visitor_id=?, vjt_session_id=?, journey_step=2 WHERE event_id = ?')->execute(['vjtv_kssmi_old', 'vjts_kssmi_old', $inquiryId]);
    }

    // ── VJT-008 true trigger: ACTIVE (partially consumed) SELECT cursor ──
    // The INSERTs above run to completion (SQLITE_DONE), which releases their
    // read lock — they never actually blocked TRUNCATE. The lock VJT-008 hit
    // comes from a SELECT cursor that has been stepped but not fully consumed.
    // Hold one in scope across a cleanup so the guard's exception path is
    // genuinely exercised (old code fatals here with 'database table is
    // locked').
    $activeCursor = $db->query("SELECT visitor_id FROM visitors WHERE visitor_id = 'vjtv_kssmi_old'");
    kssmi_vjt_assert(is_array($activeCursor->fetch()), 'active cursor returns a row before cleanup');
    vjt_cleanup_old_data(90); // cursor still open: TRUNCATE must not fatal
    kssmi_vjt_assert($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'integrity_check ok with active cursor in scope');
    $activeCursor->closeCursor();

    // ── vjt_auto_cleanup() with a 0-day last_cleanup gap ──
    vjt_meta_set('last_cleanup', 0);
    vjt_auto_cleanup(); // open statements still in scope
    kssmi_vjt_assert($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'integrity_check ok after vjt_auto_cleanup');
    kssmi_vjt_assert((int)vjt_meta_get('last_cleanup', 0) > 0, 'auto cleanup recorded last_cleanup');
    $preserved = $db->query("SELECT vjt_visitor_id FROM contact_events WHERE event_id = " . $db->quote($inquiryId))->fetch();
    kssmi_vjt_assert(is_array($preserved) && $preserved['vjt_visitor_id'] === '', 'auto cleanup preserves customer_inquiry row without linkage');

    // ── Concurrent reader must not stall the request-path checkpoint ──
    // vjt_auto_cleanup() runs from public tracking endpoints; with the default
    // 5s busy_timeout a reader on another connection made TRUNCATE block ~5s
    // before the PASSIVE fallback. The guard now bounds that wait.
    $reader = new PDO('sqlite:' . VJT_DB_PATH);
    $reader->exec('PRAGMA journal_mode = WAL');
    $reader->beginTransaction();
    $reader->query('SELECT COUNT(*) FROM visitors')->fetchAll(); // hold read txn
    $t0 = microtime(true);
    vjt_cleanup_old_data(90);
    $elapsedMs = (microtime(true) - $t0) * 1000;
    $reader->rollBack();
    $reader = null; // release the second connection so Windows can clean the temp dir
    kssmi_vjt_assert($elapsedMs < 2000, 'cleanup with concurrent reader completes quickly (' . round($elapsedMs) . 'ms, not ~5s)');
    kssmi_vjt_assert((int)$db->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'busy_timeout restored to 5000 after guarded checkpoint');
    kssmi_vjt_assert($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'integrity_check ok after concurrent-reader cleanup');

    // ── vjt_wipe_all_data() (admin destructive) must not fatal on checkpoint ──
    vjt_wipe_all_data();
    kssmi_vjt_assert($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'integrity_check ok after vjt_wipe_all_data');
    kssmi_vjt_assert((int)$db->query('SELECT COUNT(*) FROM visitors')->fetchColumn() === 0, 'wipe empties visitors');
    kssmi_vjt_assert((int)$db->query('SELECT COUNT(*) FROM contact_events')->fetchColumn() === 0, 'wipe empties contact_events');

    fwrite(STDOUT, "VJT checkpoint regression tests passed.\n");
} finally {
    kssmi_vjt_remove_tree($testDirectory);
}
