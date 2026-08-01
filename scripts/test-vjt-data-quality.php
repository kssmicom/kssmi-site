<?php
declare(strict_types=1);

/**
 * VJT data-quality runtime tests (优化-001 阶段 1).
 *
 * Verifies time normalization (ISO ingest stores UTC, admin displays Beijing,
 * visitor display uses IANA timezone, admin date filter converts to UTC), IP
 * exclusion rules (single IPv4, IPv4 CIDR, single IPv6), geo resolution
 * behavior (uncached IPs are queued off-path, cached IPs return directly,
 * documented IPv4-only CIDR matching), and source attribution snapshots.
 *
 * Note: Cloudflare header trust is intentionally NOT tested here — that is
 * 阶段 4 (可信代理) work; vjt_resolve_geo() currently does not trust CF headers.
 *
 * Run: php scripts/test-vjt-data-quality.php
 */

function kssmi_quality_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_quality_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_quality_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR .
    'kssmi-vjt-quality-' . bin2hex(random_bytes(6));
kssmi_quality_assert(mkdir($testDirectory, 0700, true), 'create test directory');
putenv('KSSMI_VJT_DATA_DIR=' . $testDirectory);

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    vjt_data_init();
    $db = vjt_db();

    // ── Time normalization ──
    kssmi_quality_assert(vjt_to_beijing('2026-07-30T02:15:00.000Z') === '2026-07-30 02:15:00', 'ISO ingest stores UTC');
    kssmi_quality_assert(vjt_format_for_admin('2026-07-30 02:15:00') === '2026-07-30 10:15:00', 'admin display uses Beijing time');
    kssmi_quality_assert(vjt_format_for_visitor('2026-07-30 02:15:00', 'America/New_York') === '2026-07-29 22:15:00', 'visitor display uses IANA timezone');
    kssmi_quality_assert(vjt_admin_date_to_utc('2026-07-30') === '2026-07-29 16:00:00', 'admin date lower boundary converts to UTC');

    // ── IP exclusion rules ──
    vjt_save_settings([
        'enable_geo' => true,
        'excluded_ips' => "203.0.113.10\n198.51.100.0/24\n2001:db8::5",
    ]);
    kssmi_quality_assert(vjt_ip_is_excluded('203.0.113.10'), 'single IPv4 exclusion');
    kssmi_quality_assert(vjt_ip_is_excluded('198.51.100.42'), 'IPv4 CIDR exclusion');
    kssmi_quality_assert(!vjt_ip_is_excluded('198.51.101.42'), 'IPv4 outside CIDR');
    kssmi_quality_assert(vjt_ip_is_excluded('2001:db8::5'), 'single IPv6 exclusion');
    kssmi_quality_assert(!vjt_ip_is_excluded('2001:db8::6'), 'IPv6 outside the explicit list');

    // ── Geo resolution: uncached → queued off-path; cached → direct return ──
    $uncached = vjt_resolve_geo('8.8.8.8');
    kssmi_quality_assert($uncached['country'] === '', 'uncached IP returns empty geo');
    $queued = (int)$db->query("SELECT COUNT(*) FROM geo_queue WHERE ip='8.8.8.8'")->fetchColumn();
    kssmi_quality_assert($queued === 1, 'uncached IP is queued for off-path lookup');
    $db->exec("INSERT OR REPLACE INTO geo_cache (ip, country, city, region, cached_at)
        VALUES ('8.8.8.8', 'US', 'Mountain View', 'California', '2026-01-01 00:00:00')");
    $cached = vjt_resolve_geo('8.8.8.8');
    kssmi_quality_assert($cached['country'] === 'US', 'cached geo is returned directly');
    kssmi_quality_assert($cached['city'] === 'Mountain View', 'cached city is returned');
    $queuedAfter = (int)$db->query("SELECT COUNT(*) FROM geo_queue WHERE ip='8.8.8.8'")->fetchColumn();
    kssmi_quality_assert($queuedAfter === 1, 'cached IP is not re-queued');

    // ── Source attribution classification ──
    kssmi_quality_assert(vjt_classify_source(['referrer' => 'https://kssmi.com/en/product/test']) === 'internal', 'Kssmi self-referral');
    kssmi_quality_assert(vjt_classify_source(['referrer' => 'https://www.google.com/search?q=optical+lenses']) === 'search', 'organic search');
    kssmi_quality_assert(vjt_classify_source(['referrer' => 'https://www.bing.com/chat?q=lens+supplier']) === 'ai', 'Bing chat AI referral');
    kssmi_quality_assert(vjt_classify_source(['referrer' => 'https://www.linkedin.com/feed/']) === 'social', 'owned social channel');
    kssmi_quality_assert(vjt_classify_source(['utm_source' => 'linkedin', 'utm_medium' => 'paid-social']) === 'ads', 'paid campaign overrides referrer');
    kssmi_quality_assert(vjt_classify_source(['referrer' => 'https://supplier-directory.example/listing']) === 'other', 'unknown referral remains explicit');

    vjt_upsert_session([
        'session_id' => 'vjts_source_snapshot',
        'visitor_id' => 'vjtv_source_snapshot',
        'referrer' => 'https://chatgpt.com/c/example',
        'landing_url' => 'https://kssmi.com/en/',
    ]);
    $source = $db->query("SELECT source_slug, source_host, source_type, source_model_version
        FROM sessions WHERE session_id='vjts_source_snapshot'")->fetch();
    kssmi_quality_assert($source['source_slug'] === 'chatgpt', 'source slug is normalized');
    kssmi_quality_assert($source['source_host'] === 'chatgpt.com', 'source host is normalized');
    kssmi_quality_assert($source['source_type'] === 'ai', 'source type is persisted');
    kssmi_quality_assert((int)$source['source_model_version'] === VJT_SOURCE_MODEL_VERSION, 'source rule version is persisted');

    echo "VJT UTC/IP/source runtime test passed.\n";
} finally {
    kssmi_quality_remove_tree($testDirectory);
}
