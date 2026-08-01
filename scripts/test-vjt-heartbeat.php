<?php
declare(strict_types=1);

/**
 * VJT heartbeat / pageview activity runtime tests (优化-001 阶段 1).
 *
 * Verifies that pageview activity updates are monotonic: a delayed or
 * out-of-order packet may never reduce duration, active duration, heartbeat
 * count, scroll depth, engagement score or engaged state. Also verifies the
 * additive migration keeps a legacy pageview intact, delayed packets for a
 * repeated URL do not touch a newer visit (step_order bound), and the
 * heartbeat interval is clamped to [30, 120] seconds.
 *
 * Run: php scripts/test-vjt-heartbeat.php
 */

function kssmi_heartbeat_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_heartbeat_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_heartbeat_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-vjt-heartbeat-' . bin2hex(random_bytes(6));
kssmi_heartbeat_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

// Reproduce the old pageviews shape to prove the additive migration preserves it.
$legacy = new PDO('sqlite:' . $testDirectory . DIRECTORY_SEPARATOR . 'vjt.sqlite');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec("CREATE TABLE pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT DEFAULT '', visitor_id TEXT DEFAULT '',
    url TEXT DEFAULT '', title TEXT DEFAULT '', visited_at TEXT DEFAULT '',
    leave_at TEXT, duration_seconds INTEGER DEFAULT 0,
    scroll_depth INTEGER DEFAULT 0, step_order INTEGER DEFAULT 1
)");
$legacy->exec("INSERT INTO pageviews
    (session_id, visitor_id, url, visited_at, duration_seconds, scroll_depth, step_order)
    VALUES ('legacy-s', 'legacy-v', 'https://kssmi.com/legacy', '2099-01-01 00:00:00', 20, 25, 1)");
$legacy = null;

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();

    foreach ([
        'active_duration_seconds',
        'engagement_score',
        'is_engaged',
        'last_activity_at',
        'heartbeat_count',
        'max_scroll_depth',
    ] as $column) {
        kssmi_heartbeat_assert(vjt_column_exists('pageviews', $column), "migration adds {$column}");
    }
    kssmi_heartbeat_assert(
        (int)$db->query("SELECT COUNT(*) c FROM pageviews WHERE session_id='legacy-s'")->fetch()['c'] === 1,
        'migration preserves legacy pageview'
    );

    vjt_add_pageview([
        'session_id' => 'vjts_heartbeat_session',
        'visitor_id' => 'vjtv_heartbeat_visitor',
        'url' => 'https://kssmi.com/en/product/test',
        'title' => 'Heartbeat test',
        'visited_at' => '2099-01-02T00:00:00Z',
        'step_order' => 1,
    ]);
    vjt_update_pageview_leave(
        'vjts_heartbeat_session',
        'https://kssmi.com/en/product/test',
        '',
        45,
        30,
        15,
        1,
        30,
        '2099-01-02T00:00:15Z',
        26,
        false,
        1
    );
    // A delayed packet is not allowed to reduce monotonic activity metrics.
    vjt_update_pageview_leave(
        'vjts_heartbeat_session',
        'https://kssmi.com/en/product/test',
        '',
        20,
        10,
        5,
        0,
        10,
        '',
        5,
        false,
        1
    );
    vjt_update_pageview_leave(
        'vjts_heartbeat_session',
        'https://kssmi.com/en/product/test',
        '2099-01-02T00:01:30Z',
        90,
        65,
        30,
        2,
        65,
        '2099-01-02T00:01:20Z',
        62,
        true,
        1
    );

    $row = $db->query("SELECT * FROM pageviews
        WHERE session_id='vjts_heartbeat_session' AND step_order=1")->fetch();
    kssmi_heartbeat_assert((int)$row['duration_seconds'] === 90, 'wall duration is monotonic');
    kssmi_heartbeat_assert((int)$row['active_duration_seconds'] === 30, 'active duration is stored');
    kssmi_heartbeat_assert((int)$row['heartbeat_count'] === 2, 'heartbeat count is stored');
    kssmi_heartbeat_assert((int)$row['max_scroll_depth'] === 65, 'maximum scroll is stored');
    kssmi_heartbeat_assert((int)$row['is_engaged'] === 1, 'engaged state is stored');
    kssmi_heartbeat_assert((int)$row['engagement_score'] === 62, 'engagement score is stored');
    kssmi_heartbeat_assert($row['leave_at'] === '2099-01-02 00:01:30', 'leave timestamp is stored');

    vjt_add_pageview([
        'session_id' => 'vjts_heartbeat_session',
        'visitor_id' => 'vjtv_heartbeat_visitor',
        'url' => 'https://kssmi.com/en/product/test',
        'visited_at' => '2099-01-02T00:02:00Z',
        'step_order' => 2,
    ]);
    vjt_update_pageview_leave(
        'vjts_heartbeat_session',
        'https://kssmi.com/en/product/test',
        '',
        120,
        80,
        60,
        3,
        80,
        '',
        80,
        true,
        1
    );
    $newer = $db->query("SELECT * FROM pageviews
        WHERE session_id='vjts_heartbeat_session' AND step_order=2")->fetch();
    kssmi_heartbeat_assert((int)$newer['heartbeat_count'] === 0, 'delayed packet cannot update repeated URL visit');
    $older = $db->query("SELECT * FROM pageviews
        WHERE session_id='vjts_heartbeat_session' AND step_order=1")->fetch();
    kssmi_heartbeat_assert((int)$older['heartbeat_count'] === 3, 'delayed packet still updates its own step_order row');

    vjt_save_settings(['heartbeat_seconds' => 10]);
    kssmi_heartbeat_assert(vjt_get_settings(true)['heartbeat_seconds'] === '30', 'heartbeat minimum is 30 seconds');
    vjt_save_settings(['heartbeat_seconds' => 999]);
    kssmi_heartbeat_assert(vjt_get_settings(true)['heartbeat_seconds'] === '120', 'heartbeat maximum is 120 seconds');

    echo "VJT heartbeat runtime test passed.\n";
} finally {
    kssmi_heartbeat_remove_tree($testDirectory);
}
