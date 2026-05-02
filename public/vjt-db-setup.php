<?php
/**
 * VJT Database Setup
 * Creates SQLite database and tables for Visitor Journey Tracker.
 * Run once: php vjt-db-setup.php
 */

$dbDir  = dirname(__DIR__) . '/vjt';
$dbPath = $dbDir . '/tracker.sqlite';

if (!is_dir($dbDir)) {
    if (!mkdir($dbDir, 0755, true)) {
        die("ERROR: Cannot create directory: {$dbDir}\n");
    }
    echo "Created directory: {$dbDir}\n";
}

if (file_exists($dbPath)) {
    echo "Database already exists: {$dbPath}\n";
    echo "Delete it first to recreate, or run this script on a fresh deploy.\n";
    exit(0);
}

try {
    $db = new PDO("sqlite:{$dbPath}");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');

    $db->exec("
        CREATE TABLE visitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_id TEXT NOT NULL UNIQUE,
            first_ip TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            user_agent TEXT,
            browser TEXT NOT NULL DEFAULT '',
            device_type TEXT NOT NULL DEFAULT '',
            screen_resolution TEXT NOT NULL DEFAULT '',
            timezone TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT '',
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("CREATE INDEX idx_visitors_last_seen ON visitors(last_seen_at)");

    $db->exec("
        CREATE TABLE sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL UNIQUE,
            visitor_id TEXT NOT NULL,
            ip TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            region TEXT NOT NULL DEFAULT '',
            calling_code TEXT NOT NULL DEFAULT '',
            referrer TEXT,
            landing_url TEXT,
            landing_title TEXT NOT NULL DEFAULT '',
            utm_source TEXT NOT NULL DEFAULT '',
            utm_medium TEXT NOT NULL DEFAULT '',
            utm_campaign TEXT NOT NULL DEFAULT '',
            utm_content TEXT NOT NULL DEFAULT '',
            utm_term TEXT NOT NULL DEFAULT '',
            started_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("CREATE INDEX idx_sessions_visitor ON sessions(visitor_id)");
    $db->exec("CREATE INDEX idx_sessions_last_seen ON sessions(last_seen_at)");

    $db->exec("
        CREATE TABLE pageviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL,
            visitor_id TEXT NOT NULL,
            url TEXT,
            title TEXT NOT NULL DEFAULT '',
            visited_at TEXT NOT NULL,
            leave_at TEXT,
            duration_seconds INTEGER UNSIGNED NOT NULL DEFAULT 0,
            scroll_depth INTEGER UNSIGNED NOT NULL DEFAULT 0,
            step_order INTEGER UNSIGNED NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("CREATE INDEX idx_pageviews_session ON pageviews(session_id)");
    $db->exec("CREATE INDEX idx_pageviews_visitor ON pageviews(visitor_id)");
    $db->exec("CREATE INDEX idx_pageviews_visited ON pageviews(visited_at)");

    $db->exec("
        CREATE TABLE submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_id TEXT NOT NULL,
            session_id TEXT NOT NULL,
            form_plugin TEXT NOT NULL DEFAULT '',
            form_id TEXT NOT NULL DEFAULT '',
            form_name TEXT NOT NULL DEFAULT '',
            submit_page TEXT,
            submit_title TEXT NOT NULL DEFAULT '',
            submitted_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'attempt',
            contact_url TEXT,
            ip TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            region TEXT NOT NULL DEFAULT '',
            calling_code TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $db->exec("CREATE INDEX idx_submissions_visitor ON submissions(visitor_id)");
    $db->exec("CREATE INDEX idx_submissions_session ON submissions(session_id)");
    $db->exec("CREATE INDEX idx_submissions_submitted ON submissions(submitted_at)");
    $db->exec("CREATE INDEX idx_submissions_status ON submissions(status)");

    // Geo cache table for ip-api.com responses
    $db->exec("
        CREATE TABLE geo_cache (
            ip TEXT PRIMARY KEY,
            country TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            region TEXT NOT NULL DEFAULT '',
            calling_code TEXT NOT NULL DEFAULT '',
            cached_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // Settings table
    $db->exec("
        CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )
    ");

    // Insert defaults
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute(['session_timeout', '30']);
    $stmt->execute(['retention_days', '90']);
    $stmt->execute(['enable_geo', '1']);

    echo "Database created successfully: {$dbPath}\n";
    echo "Tables: visitors, sessions, pageviews, submissions, geo_cache, settings\n";

} catch (PDOException $e) {
    die("ERROR: " . $e->getMessage() . "\n");
}
