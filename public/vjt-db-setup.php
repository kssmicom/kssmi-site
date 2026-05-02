<?php
/**
 * VJT Database Setup
 * Creates SQLite database and tables for Visitor Journey Tracker.
 * Run once: php vjt-db-setup.php
 */

require_once __DIR__ . '/api/vjt-helpers.php';

if (!is_dir(VJT_DB_DIR)) {
    if (!mkdir(VJT_DB_DIR, 0755, true)) {
        die("ERROR: Cannot create directory: " . VJT_DB_DIR . "\n");
    }
    echo "Created directory: " . VJT_DB_DIR . "\n";
}

if (file_exists(VJT_DB_PATH)) {
    echo "Database already exists: " . VJT_DB_PATH . "\n";
    echo "Delete it first to recreate, or run this script on a fresh deploy.\n";
    exit(0);
}

try {
    $db = new PDO('sqlite:' . VJT_DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    vjt_db_setup($db);
    echo "Database created successfully: " . VJT_DB_PATH . "\n";
    echo "Tables: visitors, sessions, pageviews, submissions, geo_cache, settings\n";
} catch (PDOException $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}
