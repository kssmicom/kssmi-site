<?php
/**
 * VJT Data Setup
 * Initializes the SQLite database for the Visitor Journey Tracker.
 * Safe to run repeatedly. On first run it auto-migrates any existing
 * *.json flat-file data into vjt.sqlite.
 * Run once: php vjt-db-setup.php
 */

// Block web access — CLI only
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('X-Robots-Tag: noindex, nofollow');
    die('CLI only.');
}

if (!extension_loaded('pdo_sqlite')) {
    die("ERROR: PHP extension 'pdo_sqlite' is not installed. Enable it before using VJT.\n");
}

require_once __DIR__ . '/api/vjt-helpers.php';

if (!is_dir(VJT_DATA_DIR)) {
    if (!mkdir(VJT_DATA_DIR, 0755, true)) {
        die("ERROR: Cannot create directory: " . VJT_DATA_DIR . "\n");
    }
    echo "Created directory: " . VJT_DATA_DIR . "\n";
}

vjt_data_init();

echo "VJT SQLite store initialized successfully: " . VJT_DB_PATH . "\n";
echo "Tables: visitors, sessions, pageviews, submissions, geo_cache, geo_queue, settings, meta\n";
echo "(Any legacy *.json files were migrated and renamed to *.json.migrated.bak)\n";
