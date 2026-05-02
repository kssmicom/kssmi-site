<?php
/**
 * VJT Data Setup
 * Initializes JSON data directory and files for Visitor Journey Tracker.
 * Run once: php vjt-db-setup.php
 */

require_once __DIR__ . '/api/vjt-helpers.php';

if (!is_dir(VJT_DATA_DIR)) {
    if (!mkdir(VJT_DATA_DIR, 0755, true)) {
        die("ERROR: Cannot create directory: " . VJT_DATA_DIR . "\n");
    }
    echo "Created directory: " . VJT_DATA_DIR . "\n";
}

vjt_data_init();

echo "VJT data initialized successfully: " . VJT_DATA_DIR . "\n";
echo "Files: visitors.json, sessions.json, pageviews.json, submissions.json, geo_cache.json, settings.json\n";
