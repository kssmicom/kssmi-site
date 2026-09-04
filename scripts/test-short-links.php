<?php
declare(strict_types=1);

$temp = sys_get_temp_dir() . '/kssmi-short-links-' . bin2hex(random_bytes(6));
putenv('KSSMI_SHORTLINK_DATA_DIR=' . $temp);
putenv('KSSMI_SHORTLINK_ALLOWED_HOSTS=gumlet.io,*.gumlet.io');
require_once dirname(__DIR__) . '/private/short-link-store.php';

function sl_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
try {
    $first = short_link_destination_create(' https://VIDEO.GUMLET.IO:443/a.mp4#fragment ', 'test');
    sl_assert($first['created'] === true, 'First destination should be created.');
    sl_assert($first['destination']['normalized_url'] === 'https://video.gumlet.io/a.mp4', 'URL was not normalized.');
    $duplicate = short_link_destination_create('https://video.gumlet.io/a.mp4', 'test');
    sl_assert($duplicate['created'] === false && $duplicate['destination']['id'] === $first['destination']['id'], 'Duplicate destination was accepted.');
    $link = short_link_create_distribution((int)$first['destination']['id'], ['label'=>'A', 'campaign'=>'Launch', 'recipient_ref'=>'CRM-1'], 'test');
    sl_assert(preg_match('/^[A-Z][A-Za-z0-9]{5}$/D', $link['code']) === 1, 'Generated code has the wrong format.');
    sl_assert(short_link_find_active($link['code']) !== null, 'Active link cannot be found.');
    short_link_record_open((int)$link['id'], 'CRM-1', false);
    sl_assert((int)short_link_list()[0]['opens'] === 1, 'Open was not counted.');
    short_link_set_status((int)$link['id'], 'archived', 'test');
    sl_assert(short_link_find_active($link['code']) === null, 'Archived link is still public.');
    foreach (['http://video.gumlet.io/a', 'https://user:pass@video.gumlet.io/a', 'javascript:alert(1)'] as $invalid) {
        try { short_link_normalize_url($invalid); throw new RuntimeException('Invalid URL accepted: ' . $invalid); } catch (InvalidArgumentException $expected) {}
    }
    echo "Short-link tests: PASS\n";
} finally {
    foreach (glob($temp . '/*') ?: [] as $path) @unlink($path);
    @rmdir($temp);
}
