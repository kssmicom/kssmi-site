<?php
declare(strict_types=1);

if (($argv[1] ?? '') === '--event-worker') {
    require_once dirname(__DIR__) . '/private/short-link-store.php';
    echo json_encode(['recorded' => short_link_record_open((int)($argv[2] ?? 0), '', false, 'US')], JSON_THROW_ON_ERROR);
    exit;
}

$temp = sys_get_temp_dir() . '/kssmi-short-links-' . bin2hex(random_bytes(6));
putenv('KSSMI_SHORTLINK_DATA_DIR=' . $temp);
putenv('KSSMI_SHORTLINK_ALLOWED_HOSTS=gumlet.io,*.gumlet.io,drive.google.com');
require_once dirname(__DIR__) . '/private/short-link-store.php';

function sl_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function sl_event_worker_command(int $linkId): array {
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false && extension_loaded('pdo_sqlite')) {
        $command[] = '-d'; $command[] = 'extension_dir=' . (string)ini_get('extension_dir');
        $command[] = '-d'; $command[] = 'extension=pdo_sqlite';
    }
    return [...$command, __FILE__, '--event-worker', (string)$linkId];
}
function sl_record_concurrently(int $linkId, int $workerCount): array {
    $environment = getenv(); if (!is_array($environment)) $environment = [];
    foreach (['KSSMI_SHORTLINK_DATA_DIR','KSSMI_SHORTLINK_ALLOWED_HOSTS','KSSMI_SHORTLINK_EVENTS_PER_LINK','KSSMI_SHORTLINK_EVENTS_TOTAL'] as $name) $environment[$name] = (string)getenv($name);
    $workers = [];
    for ($worker = 0; $worker < $workerCount; $worker++) {
        $pipes = []; $process = proc_open(sl_event_worker_command($linkId), [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $environment, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start event worker.');
        $workers[] = ['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
    }
    $rows = [];
    foreach ($workers as $worker) {
        $stdout = stream_get_contents($worker['stdout']); $stderr = stream_get_contents($worker['stderr']);
        fclose($worker['stdout']); fclose($worker['stderr']);
        if (proc_close($worker['process']) !== 0) throw new RuntimeException('Event worker failed: ' . trim((string)$stderr));
        $rows[] = json_decode((string)$stdout, true, 8, JSON_THROW_ON_ERROR);
    }
    return $rows;
}
try {
    $first = short_link_destination_create(' https://VIDEO.GUMLET.IO:443/a.mp4#fragment ', 'test');
    sl_assert($first['created'] === true, 'First destination should be created.');
    sl_assert($first['destination']['normalized_url'] === 'https://video.gumlet.io/a.mp4', 'URL was not normalized.');
    $duplicate = short_link_destination_create('https://video.gumlet.io/a.mp4', 'test');
    sl_assert($duplicate['created'] === false && $duplicate['destination']['id'] === $first['destination']['id'] && (int)$duplicate['distribution_count'] === 0, 'Duplicate destination was accepted.');
    $driveFolder = short_link_destination_create('https://drive.google.com/drive/folders/1V-rHxJrk9P9zzs1Vrmt4KA1YMu7qsDbR', 'test');
    sl_assert($driveFolder['created'] === true && $driveFolder['destination']['normalized_url'] === 'https://drive.google.com/drive/folders/1V-rHxJrk9P9zzs1Vrmt4KA1YMu7qsDbR', 'Google Drive folder was not accepted.');
    $link = short_link_create_distribution((int)$first['destination']['id'], ['label'=>'A', 'campaign'=>'Launch', 'recipient_ref'=>'CRM-1'], 'test');
    sl_assert(preg_match('/^[A-Z][A-Za-z0-9]{5}$/D', $link['code']) === 1, 'Generated code has the wrong format.');
    sl_assert(short_link_find_active($link['code']) !== null, 'Active link cannot be found.');
    $existing = short_link_destination_create('https://video.gumlet.io/a.mp4', 'test');
    sl_assert((int)$existing['distribution_count'] === 1, 'Existing destination distribution count is incorrect.');
    $nextLink = short_link_create_distribution((int)$first['destination']['id'], ['label'=>'B', 'campaign'=>'Launch', 'recipient_ref'=>'CRM-2'], 'test');
    sl_assert(short_link_count() === 2, 'Short-link count is incorrect.');
    $newestPage = short_link_list('', 1, 0);
    $oldestPage = short_link_list('', 1, 1);
    sl_assert((int)$newestPage[0]['id'] === (int)$nextLink['id'] && (int)$oldestPage[0]['id'] === (int)$link['id'], 'Short-link pagination order is incorrect.');
    $neighbors = short_link_tracking_neighbors((int)$link['id']);
    sl_assert((int)$neighbors['previous']['id'] === (int)$nextLink['id'] && $neighbors['next'] === null, 'Older link navigation is incorrect.');
    $neighbors = short_link_tracking_neighbors((int)$nextLink['id']);
    sl_assert($neighbors['previous'] === null && (int)$neighbors['next']['id'] === (int)$link['id'], 'Newer link navigation is incorrect.');
    short_link_record_open((int)$link['id'], 'CRM-1', false, 'US');
    short_link_record_open((int)$link['id'], 'CRM-1', false, 'US');
    short_link_record_open((int)$link['id'], 'CRM-2', true, 'CA');
    $listedLink = array_values(array_filter(short_link_list(), fn($candidate) => (int)$candidate['id'] === (int)$link['id']))[0] ?? null;
    sl_assert($listedLink !== null && (int)$listedLink['opens'] === 2, 'Open was not counted.');
    $tracking = short_link_tracking((int)$link['id']);
    sl_assert($tracking !== null && (int)$tracking['summary']['opens'] === 2 && (int)$tracking['summary']['bots'] === 1, 'Tracking summary is incorrect.');
    sl_assert(count($tracking['events']) === 2 && $tracking['events'][0]['recipient_ref_snapshot'] === 'CRM-1' && $tracking['events'][0]['country'] === 'US' && (int)$tracking['events'][0]['recipient_opens'] === 2, 'Tracking event was not returned with its recipient open total.');
    sl_assert(count($tracking['locations']) === 1 && $tracking['locations'][0]['country'] === 'US' && (int)$tracking['locations'][0]['opens'] === 2, 'Tracking location was not aggregated.');
    short_link_set_status((int)$link['id'], 'archived', 'test');
    sl_assert(short_link_find_active($link['code']) === null, 'Archived link is still public.');
    short_link_permanently_delete((int)$link['id'], 'DELETE ' . $link['code'], 'test');
    sl_assert(short_link_get((int)$link['id']) === null, 'Permanently deleted link row still exists.');
    sl_assert(count(short_link_list()) === 1, 'Permanently deleted link is still shown in the normal list.');
    $tombstone = short_link_db()->prepare('SELECT 1 FROM short_link_code_tombstones WHERE code = ?');
    $tombstone->execute([$link['code']]);
    sl_assert((bool)$tombstone->fetchColumn(), 'Soft-deleted code was not permanently reserved.');
    $tombstone->closeCursor();
    unset($tombstone);
    short_link_permanently_delete((int)$nextLink['id'], 'DELETE ' . $nextLink['code'], 'test');
    sl_assert(short_link_list() === [], 'Permanently deleted link is still shown in the normal list.');

    putenv('KSSMI_SHORTLINK_EVENTS_PER_LINK=3');
    putenv('KSSMI_SHORTLINK_EVENTS_TOTAL=4');
    $cappedDestination = short_link_destination_create('https://video.gumlet.io/capped', 'test');
    $cappedLink = short_link_create_distribution((int)$cappedDestination['destination']['id'], ['label'=>'Capped A','campaign'=>'Cap test','recipient_ref'=>''], 'test');
    $workers = sl_record_concurrently((int)$cappedLink['id'], 8);
    sl_assert(count(array_filter($workers, fn($row) => $row['recorded'] === true)) === 8, 'Automatic cleanup rejected a concurrent event.');
    $secondCappedLink = short_link_create_distribution((int)$cappedDestination['destination']['id'], ['label'=>'Capped B','campaign'=>'Cap test','recipient_ref'=>''], 'test');
    sl_assert(short_link_record_open((int)$secondCappedLink['id'], '', false, 'US'), 'Global cleanup rejected the first valid event.');
    sl_assert(short_link_record_open((int)$secondCappedLink['id'], '', false, 'US'), 'Global cleanup rejected the second valid event.');
    sl_assert((int)short_link_db()->query('SELECT COUNT(*) FROM short_link_events')->fetchColumn() <= 4, 'Automatic cleanup exceeded the global retained-event cap.');
    $capacity = short_link_event_capacity((int)$cappedLink['id']);
    sl_assert($capacity['link_count'] <= 3 && $capacity['global_count'] <= 4 && $capacity['link_total'] === 8 && $capacity['global_total'] === 10 && $capacity['pruned_events'] > 0, 'Lifetime totals or automatic cleanup state is incorrect.');
    foreach ([$cappedLink,$secondCappedLink] as $cappedRow) short_link_permanently_delete((int)$cappedRow['id'], 'DELETE ' . $cappedRow['code'], 'test');

    putenv('KSSMI_SHORTLINK_EVENTS_PER_LINK=10'); putenv('KSSMI_SHORTLINK_EVENTS_TOTAL=20');
    $loweredLink = short_link_create_distribution((int)$cappedDestination['destination']['id'], ['label'=>'Lowered cap','campaign'=>'Cap test','recipient_ref'=>''], 'test');
    for ($attempt = 0; $attempt < 10; $attempt++) sl_assert(short_link_record_open((int)$loweredLink['id'], '', false, 'US'), 'Pre-lowering event was rejected.');
    putenv('KSSMI_SHORTLINK_EVENTS_PER_LINK=3'); putenv('KSSMI_SHORTLINK_EVENTS_TOTAL=4');
    sl_assert(short_link_record_open((int)$loweredLink['id'], '', false, 'US'), 'Lowered-limit cleanup rejected a valid event.');
    $loweredCapacity = short_link_event_capacity((int)$loweredLink['id']);
    sl_assert($loweredCapacity['link_count'] <= 3 && $loweredCapacity['global_count'] <= 4 && $loweredCapacity['link_total'] === 11, 'Lowering event limits did not catch retained detail history up to the new cap.');
    short_link_permanently_delete((int)$loweredLink['id'], 'DELETE ' . $loweredLink['code'], 'test');
    putenv('KSSMI_SHORTLINK_EVENTS_PER_LINK'); putenv('KSSMI_SHORTLINK_EVENTS_TOTAL');
    foreach (['http://video.gumlet.io/a', 'https://user:pass@video.gumlet.io/a', 'javascript:alert(1)'] as $invalid) {
        try { short_link_normalize_url($invalid); throw new RuntimeException('Invalid URL accepted: ' . $invalid); } catch (InvalidArgumentException $expected) {}
    }
    echo "Short-link tests: PASS\n";
} finally {
    foreach (glob($temp . '/*') ?: [] as $path) @unlink($path);
    @rmdir($temp);
}
