<?php
declare(strict_types=1);

$temp = sys_get_temp_dir() . '/kssmi-short-links-' . bin2hex(random_bytes(6));
putenv('KSSMI_SHORTLINK_DATA_DIR=' . $temp);
putenv('KSSMI_SHORTLINK_ALLOWED_HOSTS=gumlet.io,*.gumlet.io,drive.google.com');
require_once dirname(__DIR__) . '/private/short-link-store.php';

function sl_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
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
    short_link_permanently_delete((int)$nextLink['id'], 'DELETE ' . $nextLink['code'], 'test');
    sl_assert(short_link_list() === [], 'Permanently deleted link is still shown in the normal list.');
    foreach (['http://video.gumlet.io/a', 'https://user:pass@video.gumlet.io/a', 'javascript:alert(1)'] as $invalid) {
        try { short_link_normalize_url($invalid); throw new RuntimeException('Invalid URL accepted: ' . $invalid); } catch (InvalidArgumentException $expected) {}
    }
    echo "Short-link tests: PASS\n";
} finally {
    foreach (glob($temp . '/*') ?: [] as $path) @unlink($path);
    @rmdir($temp);
}
