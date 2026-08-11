<?php
declare(strict_types=1);

/** Regression coverage for F-02: bounded submission write amplification. */
function vjt_budget_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function vjt_budget_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) vjt_budget_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

function vjt_budget_pages(int $from, int $count): array {
    $pages = [];
    for ($index = $from; $index < $from + $count; $index++) {
        $pages[] = [
            'visitor_id' => 'vjtv_budgetvisitor',
            'url' => 'https://kssmi.com/budget/' . $index,
            'title' => 'Page ' . $index,
            'visited_at' => gmdate('c'),
        ];
    }
    return $pages;
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-vjt-write-budget-' . bin2hex(random_bytes(6));
vjt_budget_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();
    $visitorId = 'vjtv_budgetvisitor';
    $sessionId = 'vjts_budgetsession';
    vjt_upsert_visitor(['visitor_id' => $visitorId, 'site_language' => 'EN']);
    vjt_upsert_session(['session_id' => $sessionId, 'visitor_id' => $visitorId]);

    vjt_budget_assert(
        vjt_submission_write_cost(20) > vjt_submission_write_cost(0),
        'a 20-page snapshot does not cost more quota than an empty snapshot'
    );
    vjt_budget_assert(vjt_submission_write_cost(20) === vjt_submission_write_cost(8), 'snapshot cost is not bounded');

    $initialSnapshot = vjt_budget_pages(1, VJT_SUBMISSION_SNAPSHOT_MAX_ROWS);
    $budget = vjt_submission_write_budget($visitorId, $sessionId, $initialSnapshot);
    vjt_budget_assert(($budget['ok'] ?? false) === true, 'bounded first submission was rejected');
    vjt_budget_assert(($budget['new_pageviews'] ?? -1) === VJT_SUBMISSION_SNAPSHOT_MAX_ROWS, 'first submission row estimate is wrong');
    $db->beginTransaction();
    vjt_sync_pageview_snapshot($sessionId, $initialSnapshot);
    vjt_add_submission([
        'visitor_id' => $visitorId, 'session_id' => $sessionId,
        'event_id' => 'vjtev_budgetfirstsubmission', 'form_plugin' => 'generic', 'status' => 'attempt',
    ]);
    $db->commit();
    vjt_budget_assert((int)$db->query('SELECT COUNT(*) FROM pageviews WHERE session_id = ' . $db->quote($sessionId))->fetchColumn() === 8, 'first submission exceeded its snapshot row budget');

    // A session already near its pageview ceiling is refused before any part
    // of its snapshot or submission is stored.
    foreach (vjt_budget_pages(9, 12) as $index => $page) {
        vjt_add_pageview(array_merge($page, ['session_id' => $sessionId, 'step_order' => 9 + $index]));
    }
    $beforePages = (int)$db->query('SELECT COUNT(*) FROM pageviews WHERE session_id = ' . $db->quote($sessionId))->fetchColumn();
    $beforeSubmissions = (int)$db->query('SELECT COUNT(*) FROM submissions WHERE session_id = ' . $db->quote($sessionId))->fetchColumn();
    $db->beginTransaction();
    $overBudget = vjt_submission_write_budget($visitorId, $sessionId, vjt_budget_pages(21, 5));
    vjt_budget_assert(($overBudget['ok'] ?? true) === false && ($overBudget['reason'] ?? '') === 'session_pageview_budget', 'over-limit session was not rejected as a whole');
    $db->rollBack();
    vjt_budget_assert((int)$db->query('SELECT COUNT(*) FROM pageviews WHERE session_id = ' . $db->quote($sessionId))->fetchColumn() === $beforePages, 'rejected snapshot partially wrote pageviews');
    vjt_budget_assert((int)$db->query('SELECT COUNT(*) FROM submissions WHERE session_id = ' . $db->quote($sessionId))->fetchColumn() === $beforeSubmissions, 'rejected snapshot partially wrote a submission');

    // The SQLite trigger is the concurrency-safe backstop if another writer
    // races the preflight check.
    foreach (vjt_budget_pages(21, 4) as $index => $page) {
        vjt_add_pageview(array_merge($page, ['session_id' => $sessionId, 'step_order' => 21 + $index]));
    }
    $triggerRejected = false;
    try {
        $db->beginTransaction();
        vjt_add_pageview(array_merge(vjt_budget_pages(25, 1)[0], ['session_id' => $sessionId, 'step_order' => 25]));
        $db->commit();
    } catch (PDOException $error) {
        $triggerRejected = true;
        if ($db->inTransaction()) $db->rollBack();
    }
    vjt_budget_assert($triggerRejected, 'pageview trigger did not reject the session cap bypass');
    vjt_budget_assert((int)$db->query('SELECT COUNT(*) FROM pageviews WHERE session_id = ' . $db->quote($sessionId))->fetchColumn() === VJT_SESSION_PAGEVIEW_MAX_ROWS, 'trigger allowed rows beyond the session cap');

    $limits = vjt_submission_write_limits();
    $currentRows = (int)$db->query('SELECT (SELECT COUNT(*) FROM visitors) + (SELECT COUNT(*) FROM sessions) + (SELECT COUNT(*) FROM pageviews) + (SELECT COUNT(*) FROM submissions) + (SELECT COUNT(*) FROM contact_events) + (SELECT COUNT(*) FROM used_event_capabilities) + (SELECT COUNT(*) FROM geo_cache) + (SELECT COUNT(*) FROM geo_queue) + (SELECT COUNT(*) FROM anon_views) + (SELECT COUNT(*) FROM settings) + (SELECT COUNT(*) FROM meta)')->fetchColumn();
    $limits['sqlite_rows'] = $currentRows;
    $rowBudget = vjt_submission_write_budget($visitorId, $sessionId, [], $limits);
    vjt_budget_assert(($rowBudget['reason'] ?? '') === 'sqlite_row_budget', 'global SQLite row budget did not fail closed');
    $limits = vjt_submission_write_limits();
    $limits['main_bytes'] = PHP_INT_MAX;
    $limits['wal_bytes'] = 0;
    $limits['write_bytes'] = 1;
    $byteBudget = vjt_submission_write_budget($visitorId, $sessionId, [], $limits);
    vjt_budget_assert(($byteBudget['reason'] ?? '') === 'sqlite_byte_budget', 'global SQLite/WAL byte budget did not fail closed');

    $endpoint = file_get_contents(dirname(__DIR__) . '/public/api/track-submission.php');
    vjt_budget_assert(is_string($endpoint), 'read track-submission source');
    vjt_budget_assert(
        strpos($endpoint, 'checkRateLimitCost(\'track-sub-write\'') !== false
            && strpos($endpoint, 'vjt_submission_write_budget(') !== false
            && strpos($endpoint, 'vjt_log_submission_budget_rejection(') !== false,
        'endpoint does not wire the cost, capacity, and monitoring budgets'
    );
    vjt_budget_assert(
        strpos($endpoint, 'vjt_submission_write_budget(') < strpos($endpoint, 'vjt_upsert_visitor(['),
        'endpoint checks capacity after starting durable writes'
    );

    fwrite(STDOUT, "VJT write-budget tests passed.\n");
} finally {
    vjt_budget_remove_tree($testDirectory);
}
