<?php
/**
 * VJT Data Helper
 * SQLite storage (PDO). Drop-in replacement for the former JSON flat-file store.
 *
 * All public vjt_* functions keep their original signatures and return shapes,
 * so the ingest endpoints and dashboard need no structural changes.
 *
 * On first run it auto-migrates any existing *.json data into vjt.sqlite.
 */

date_default_timezone_set('Asia/Shanghai');

// ── Time helpers ─────────────────────────────────────────────────────────────
// All datetime values are stored as real UTC Y-m-d H:i:s, using gmdate().
// This eliminates timezone ambiguity regardless of server PHP configuration.
//
// Display: vjt_format_for_admin() converts UTC → Beijing (+8h).
//          vjt_format_for_visitor() converts UTC → the visitor's IANA tz.
//
// Ingest:  vjt_to_beijing() converts JS ISO-8601 UTC → UTC Y-m-d H:i:s.

// Convert a JS ISO-8601 UTC string (e.g. "2026-07-08T07:36:36.000Z")
// to UTC Y-m-d H:i:s for storage.
function vjt_to_beijing($isoStr) {
    if (empty($isoStr)) return '';
    $ts = strtotime($isoStr);
    if ($ts === false || $ts <= 0) return $isoStr;
    return gmdate('Y-m-d H:i:s', $ts);
}

// Format a stored UTC time for the admin dashboard (admin is in China).
// Stored values are real UTC Y-m-d H:i:s; always display as Beijing time (+8h).
function vjt_format_for_admin($timeStr) {
    if (empty($timeStr)) return '-';
    try {
        // ISO 8601 from JS already carries its timezone. Legacy database values
        // are UTC strings without an offset, so parse those explicitly as UTC.
        $dt = (strpos($timeStr, 'T') !== false || strpos($timeStr, 'Z') !== false)
            ? new DateTimeImmutable($timeStr)
            : new DateTimeImmutable($timeStr, new DateTimeZone('UTC'));
        $dt = $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $timeStr;
    }
}

// Convert an admin-entered Beijing calendar date into the UTC boundary used by
// SQLite. This keeps date filters aligned with the dashboard label.
function vjt_admin_date_to_utc($date, $endOfDay = false) {
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    try {
        $suffix = $endOfDay ? ' 23:59:59' : ' 00:00:00';
        $dt = new DateTimeImmutable($date . $suffix, new DateTimeZone('Asia/Shanghai'));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

function vjt_utc_since_seconds($seconds) {
    return gmdate('Y-m-d H:i:s', time() - max(0, (int)$seconds));
}

// Format a stored UTC time for the visitor's local timezone.
// Falls back to Beijing time if no timezone is provided.
function vjt_format_for_visitor($timeStr, $tz) {
    if (empty($timeStr)) return '-';
    if (empty($tz)) return vjt_format_for_admin($timeStr);
    try {
        $dt = new DateTime($timeStr, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return vjt_format_for_admin($timeStr);
    }
}

// Store data OUTSIDE public_html so the SQLite file cannot be downloaded
// directly even if the .htaccess F rule gets bypassed / AllowOverride is off.
// Lives at /home/<domain>/vjt_data/ on the Hetzner VPS.
define('VJT_DATA_DIR', '/home/kssmi.com/vjt_data');
define('VJT_DB_PATH', VJT_DATA_DIR . '/vjt.sqlite');
define('VJT_SOURCE_MODEL_VERSION', 2);

// ── Country mapping (single source of truth) ─────────────────────────────────
// alpha-2 (stored in DB, from ip-api countryCode) => [alpha-3, full English name]
// Used by both display (alpha-3) and search/sort (full name) so "what you see"
// always equals "what you can search". See vjt_country_name / vjt_country_alpha3.
function vjt_country_map() {
    static $map = [
        'AF' => ['AFG', 'Afghanistan'], 'AL' => ['ALB', 'Albania'], 'DZ' => ['DZA', 'Algeria'],
        'AD' => ['AND', 'Andorra'], 'AO' => ['AGO', 'Angola'], 'AG' => ['ATG', 'Antigua and Barbuda'],
        'AR' => ['ARG', 'Argentina'], 'AM' => ['ARM', 'Armenia'], 'AU' => ['AUS', 'Australia'],
        'AT' => ['AUT', 'Austria'], 'AZ' => ['AZE', 'Azerbaijan'], 'BS' => ['BHS', 'Bahamas'],
        'BH' => ['BHR', 'Bahrain'], 'BD' => ['BGD', 'Bangladesh'], 'BB' => ['BRB', 'Barbados'],
        'BY' => ['BLR', 'Belarus'], 'BE' => ['BEL', 'Belgium'], 'BZ' => ['BLZ', 'Belize'],
        'BJ' => ['BEN', 'Benin'], 'BT' => ['BTN', 'Bhutan'], 'BO' => ['BOL', 'Bolivia'],
        'BA' => ['BIH', 'Bosnia and Herzegovina'], 'BW' => ['BWA', 'Botswana'], 'BR' => ['BRA', 'Brazil'],
        'BN' => ['BRN', 'Brunei'], 'BG' => ['BGR', 'Bulgaria'], 'BF' => ['BFA', 'Burkina Faso'],
        'BI' => ['BDI', 'Burundi'], 'CV' => ['CPV', 'Cape Verde'], 'KH' => ['KHM', 'Cambodia'],
        'CM' => ['CMR', 'Cameroon'], 'CA' => ['CAN', 'Canada'], 'CF' => ['CAF', 'Central African Republic'],
        'TD' => ['TCD', 'Chad'], 'CL' => ['CHL', 'Chile'], 'CN' => ['CHN', 'China'],
        'CO' => ['COL', 'Colombia'], 'KM' => ['COM', 'Comoros'], 'CG' => ['COG', 'Congo'],
        'CR' => ['CRI', 'Costa Rica'], 'CI' => ['CIV', "Côte d'Ivoire"], 'HR' => ['HRV', 'Croatia'],
        'CU' => ['CUB', 'Cuba'], 'CY' => ['CYP', 'Cyprus'], 'CZ' => ['CZE', 'Czechia'],
        'CD' => ['COD', 'DR Congo'], 'DK' => ['DNK', 'Denmark'], 'DJ' => ['DJI', 'Djibouti'],
        'DM' => ['DMA', 'Dominica'], 'DO' => ['DOM', 'Dominican Republic'], 'EC' => ['ECU', 'Ecuador'],
        'EG' => ['EGY', 'Egypt'], 'SV' => ['SLV', 'El Salvador'], 'GQ' => ['GNQ', 'Equatorial Guinea'],
        'ER' => ['ERI', 'Eritrea'], 'EE' => ['EST', 'Estonia'], 'SZ' => ['SWZ', 'Eswatini'],
        'ET' => ['ETH', 'Ethiopia'], 'FJ' => ['FJI', 'Fiji'], 'FI' => ['FIN', 'Finland'],
        'FR' => ['FRA', 'France'], 'GA' => ['GAB', 'Gabon'], 'GM' => ['GMB', 'Gambia'],
        'GE' => ['GEO', 'Georgia'], 'DE' => ['DEU', 'Germany'], 'GH' => ['GHA', 'Ghana'],
        'GR' => ['GRC', 'Greece'], 'GD' => ['GRD', 'Grenada'], 'GT' => ['GTM', 'Guatemala'],
        'GN' => ['GIN', 'Guinea'], 'GW' => ['GNB', 'Guinea-Bissau'], 'GY' => ['GUY', 'Guyana'],
        'HT' => ['HTI', 'Haiti'], 'HN' => ['HND', 'Honduras'], 'HU' => ['HUN', 'Hungary'],
        'IS' => ['ISL', 'Iceland'], 'IN' => ['IND', 'India'], 'ID' => ['IDN', 'Indonesia'],
        'IR' => ['IRN', 'Iran'], 'IQ' => ['IRQ', 'Iraq'], 'IE' => ['IRL', 'Ireland'],
        'IL' => ['ISR', 'Israel'], 'IT' => ['ITA', 'Italy'], 'JM' => ['JAM', 'Jamaica'],
        'JP' => ['JPN', 'Japan'], 'JO' => ['JOR', 'Jordan'], 'KZ' => ['KAZ', 'Kazakhstan'],
        'KE' => ['KEN', 'Kenya'], 'KI' => ['KIR', 'Kiribati'], 'KP' => ['PRK', 'North Korea'],
        'KR' => ['KOR', 'South Korea'], 'KW' => ['KWT', 'Kuwait'], 'KG' => ['KGZ', 'Kyrgyzstan'],
        'LA' => ['LAO', 'Laos'], 'LV' => ['LVA', 'Latvia'], 'LB' => ['LBN', 'Lebanon'],
        'LS' => ['LSO', 'Lesotho'], 'LR' => ['LBR', 'Liberia'], 'LY' => ['LBY', 'Libya'],
        'LI' => ['LIE', 'Liechtenstein'], 'LT' => ['LTU', 'Lithuania'], 'LU' => ['LUX', 'Luxembourg'],
        'MG' => ['MDG', 'Madagascar'], 'MW' => ['MWI', 'Malawi'], 'MY' => ['MYS', 'Malaysia'],
        'MV' => ['MDV', 'Maldives'], 'ML' => ['MLI', 'Mali'], 'MT' => ['MLT', 'Malta'],
        'MH' => ['MHL', 'Marshall Islands'], 'MR' => ['MRT', 'Mauritania'], 'MU' => ['MUS', 'Mauritius'],
        'MX' => ['MEX', 'Mexico'], 'FM' => ['FSM', 'Micronesia'], 'MD' => ['MDA', 'Moldova'],
        'MC' => ['MCO', 'Monaco'], 'MN' => ['MNG', 'Mongolia'], 'ME' => ['MNE', 'Montenegro'],
        'MA' => ['MAR', 'Morocco'], 'MZ' => ['MOZ', 'Mozambique'], 'MM' => ['MMR', 'Myanmar'],
        'NA' => ['NAM', 'Namibia'], 'NR' => ['NRU', 'Nauru'], 'NP' => ['NPL', 'Nepal'],
        'NL' => ['NLD', 'Netherlands'], 'NZ' => ['NZL', 'New Zealand'], 'NI' => ['NIC', 'Nicaragua'],
        'NE' => ['NER', 'Niger'], 'NG' => ['NGA', 'Nigeria'], 'MK' => ['MKD', 'North Macedonia'],
        'NO' => ['NOR', 'Norway'], 'OM' => ['OMN', 'Oman'], 'PK' => ['PAK', 'Pakistan'],
        'PW' => ['PLW', 'Palau'], 'PS' => ['PSE', 'Palestine'], 'PA' => ['PAN', 'Panama'],
        'PG' => ['PNG', 'Papua New Guinea'], 'PY' => ['PRY', 'Paraguay'], 'PE' => ['PER', 'Peru'],
        'PH' => ['PHL', 'Philippines'], 'PL' => ['POL', 'Poland'], 'PT' => ['PRT', 'Portugal'],
        'QA' => ['QAT', 'Qatar'], 'RO' => ['ROU', 'Romania'], 'RU' => ['RUS', 'Russia'],
        'RW' => ['RWA', 'Rwanda'], 'KN' => ['KNA', 'Saint Kitts and Nevis'], 'LC' => ['LCA', 'Saint Lucia'],
        'VC' => ['VCT', 'Saint Vincent and the Grenadines'], 'WS' => ['WSM', 'Samoa'], 'SM' => ['SMR', 'San Marino'],
        'ST' => ['STP', 'Sao Tome and Principe'], 'SA' => ['SAU', 'Saudi Arabia'], 'SN' => ['SEN', 'Senegal'],
        'RS' => ['SRB', 'Serbia'], 'SC' => ['SYC', 'Seychelles'], 'SL' => ['SLE', 'Sierra Leone'],
        'SG' => ['SGP', 'Singapore'], 'SK' => ['SVK', 'Slovakia'], 'SI' => ['SVN', 'Slovenia'],
        'SB' => ['SLB', 'Solomon Islands'], 'SO' => ['SOM', 'Somalia'], 'ZA' => ['ZAF', 'South Africa'],
        'SS' => ['SSD', 'South Sudan'], 'ES' => ['ESP', 'Spain'], 'LK' => ['LKA', 'Sri Lanka'],
        'SD' => ['SDN', 'Sudan'], 'SR' => ['SUR', 'Suriname'], 'SE' => ['SWE', 'Sweden'],
        'CH' => ['CHE', 'Switzerland'], 'SY' => ['SYR', 'Syria'], 'TW' => ['TWN', 'Taiwan'],
        'TJ' => ['TJK', 'Tajikistan'], 'TZ' => ['TZA', 'Tanzania'], 'TH' => ['THA', 'Thailand'],
        'TL' => ['TLS', 'Timor-Leste'], 'TG' => ['TGO', 'Togo'], 'TO' => ['TON', 'Tonga'],
        'TT' => ['TTO', 'Trinidad and Tobago'], 'TN' => ['TUN', 'Tunisia'], 'TR' => ['TUR', 'Turkey'],
        'TM' => ['TKM', 'Turkmenistan'], 'TV' => ['TUV', 'Tuvalu'], 'UG' => ['UGA', 'Uganda'],
        'UA' => ['UKR', 'Ukraine'], 'AE' => ['ARE', 'United Arab Emirates'], 'GB' => ['GBR', 'United Kingdom'],
        'US' => ['USA', 'United States'], 'UY' => ['URY', 'Uruguay'], 'UZ' => ['UZB', 'Uzbekistan'],
        'VU' => ['VUT', 'Vanuatu'], 'VA' => ['VAT', 'Vatican City'], 'VE' => ['VEN', 'Venezuela'],
        'VN' => ['VNM', 'Vietnam'], 'YE' => ['YEM', 'Yemen'], 'ZM' => ['ZMB', 'Zambia'],
        'ZW' => ['ZWE', 'Zimbabwe'],
        'LOCAL' => ['Local', 'Local/Testing'], 'UNKNOWN' => ['Unknown', 'Unknown'],
    ];
    return $map;
}

// Country code → full English name (e.g. 'US' => 'United States'). Falls back to the raw code.
function vjt_country_name($code) {
    $map = vjt_country_map();
    $code = strtoupper((string)$code);
    return isset($map[$code]) ? $map[$code][1] : $code;
}

// Country code → ISO 3166-1 alpha-3 (e.g. 'US' => 'USA'). Falls back to the raw code.
function vjt_country_alpha3($code) {
    $map = vjt_country_map();
    $code = strtoupper((string)$code);
    return isset($map[$code]) ? $map[$code][0] : $code;
}

// Clip overly long strings before storage (anti-bloat / abuse guard on ingest).
function vjt_clip($v, $max) {
    if (!is_string($v)) return $v;
    if (function_exists('mb_substr')) {
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
    }
    return strlen($v) > $max ? substr($v, 0, $max) : $v;
}

// Accept only absolute HTTP(S) URLs from browser-controlled analytics fields.
// HTML escaping alone is not sufficient for href values because javascript:
// and data: URLs would still be executable when an admin clicks them.
function vjt_safe_http_url($value) {
    if (!is_scalar($value)) return '';
    $url = trim((string)$value);
    $url = vjt_clip($url, 2048);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';
    $parts = @parse_url($url);
    if (!is_array($parts)) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) return '';
    if (isset($parts['user']) || isset($parts['pass'])) return '';
    return $url;
}

// Per-field length caps applied to ingest payloads (track-pageview / track-submission).
function vjt_field_caps() {
    return [
        'url' => 2048, 'title' => 512, 'referrer' => 2048,
        'landing_url' => 2048, 'landing_title' => 512,
        'utm_source' => 256, 'utm_medium' => 256, 'utm_campaign' => 256, 'utm_content' => 256, 'utm_term' => 256,
        'screen_resolution' => 32, 'timezone' => 64, 'language' => 64, 'site_language' => 8,
        'form_plugin' => 64, 'form_id' => 256, 'form_name' => 256, 'event_id' => 96,
        'submit_page' => 2048, 'submit_title' => 512, 'contact_url' => 2048,
    ];
}

// ── Database connection ──────────────────────────────────────────────────────

function vjt_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(VJT_DATA_DIR)) {
        @mkdir(VJT_DATA_DIR, 0700, true);
    }
    @chmod(VJT_DATA_DIR, 0700);
    // Block web access to the data dir (sqlite file + WAL/SHM)
    $htaccess = VJT_DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }

    try {
        $pdo = new PDO('sqlite:' . VJT_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // WAL = concurrent reads while writing; far better than the old global flock()
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = OFF');
        // Analytics data and cached GSC access tokens must never be world-readable
        // or world-writable. The PHP worker that creates the DB remains its owner.
        @chmod(VJT_DB_PATH, 0600);
    } catch (Exception $e) {
        error_log('VJT ERROR: cannot open SQLite DB: ' . $e->getMessage());
        throw $e;
    }
    return $pdo;
}

function vjt_create_schema() {
    $db = vjt_db();
    $db->exec("CREATE TABLE IF NOT EXISTS visitors (
        visitor_id        TEXT PRIMARY KEY,
        first_ip          TEXT DEFAULT '',
        country           TEXT DEFAULT '',
        city              TEXT DEFAULT '',
        user_agent        TEXT DEFAULT '',
        browser           TEXT DEFAULT 'Unknown',
        device_type       TEXT DEFAULT 'Unknown',
        screen_resolution TEXT DEFAULT '',
        timezone          TEXT DEFAULT '',
        language          TEXT DEFAULT '',
        site_language     TEXT DEFAULT 'EN',
        first_seen_at     TEXT DEFAULT '',
        last_seen_at      TEXT DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS sessions (
        session_id    TEXT PRIMARY KEY,
        visitor_id    TEXT DEFAULT '',
        ip            TEXT DEFAULT '',
        country       TEXT DEFAULT '',
        city          TEXT DEFAULT '',
        region        TEXT DEFAULT '',
        calling_code  TEXT DEFAULT '',
        referrer      TEXT DEFAULT '',
        landing_url   TEXT DEFAULT '',
        landing_title TEXT DEFAULT '',
        utm_source    TEXT DEFAULT '',
        utm_medium    TEXT DEFAULT '',
        utm_campaign  TEXT DEFAULT '',
        utm_content   TEXT DEFAULT '',
        utm_term      TEXT DEFAULT '',
        source_slug   TEXT DEFAULT '',
        source_host   TEXT DEFAULT '',
        source_type   TEXT DEFAULT '',
        source_model_version INTEGER DEFAULT 0,
        started_at    TEXT DEFAULT '',
        last_seen_at  TEXT DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS pageviews (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id       TEXT DEFAULT '',
        visitor_id       TEXT DEFAULT '',
        url              TEXT DEFAULT '',
        title            TEXT DEFAULT '',
        visited_at       TEXT DEFAULT '',
        leave_at         TEXT,
        duration_seconds INTEGER DEFAULT 0,
        active_duration_seconds INTEGER DEFAULT 0,
        engagement_score INTEGER DEFAULT 0,
        is_engaged       INTEGER DEFAULT 0,
        last_activity_at TEXT DEFAULT '',
        heartbeat_count  INTEGER DEFAULT 0,
        scroll_depth     INTEGER DEFAULT 0,
        max_scroll_depth INTEGER DEFAULT 0,
        step_order       INTEGER DEFAULT 1
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS submissions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        visitor_id    TEXT DEFAULT '',
        session_id    TEXT DEFAULT '',
        form_plugin   TEXT DEFAULT '',
        form_id       TEXT DEFAULT '',
        form_name     TEXT DEFAULT '',
        submit_page   TEXT DEFAULT '',
        submit_title  TEXT DEFAULT '',
        submitted_at  TEXT DEFAULT '',
        event_id      TEXT DEFAULT '',
        status        TEXT DEFAULT 'attempt',
        contact_url   TEXT DEFAULT '',
        ip            TEXT DEFAULT '',
        country       TEXT DEFAULT '',
        city          TEXT DEFAULT '',
        region        TEXT DEFAULT '',
        calling_code  TEXT DEFAULT ''
    )");
    // Contact Core is deliberately independent from Analytics Journey. Records
    // may exist without visitor/session IDs and never contain IP, UA, referrer,
    // UTM data or a path snapshot.
    $db->exec("CREATE TABLE IF NOT EXISTS contact_events (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id        TEXT NOT NULL UNIQUE,
        channel         TEXT NOT NULL,
        event_type      TEXT NOT NULL,
        occurred_at     TEXT NOT NULL,
        page_path       TEXT DEFAULT '',
        placement       TEXT DEFAULT '',
        product_sku     TEXT DEFAULT '',
        site_language   TEXT DEFAULT '',
        status          TEXT NOT NULL,
        vjt_visitor_id  TEXT DEFAULT '',
        vjt_session_id  TEXT DEFAULT '',
        retention_class TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS geo_cache (
        ip           TEXT PRIMARY KEY,
        country      TEXT DEFAULT '',
        city         TEXT DEFAULT '',
        region       TEXT DEFAULT '',
        calling_code TEXT DEFAULT '',
        cached_at    TEXT DEFAULT ''
    )");
    // Item 6: pending IPs awaiting geo resolution (resolved off the ingest path)
    $db->exec("CREATE TABLE IF NOT EXISTS geo_queue (
        ip        TEXT PRIMARY KEY,
        queued_at TEXT DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT DEFAULT ''
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS meta (
        key   TEXT PRIMARY KEY,
        value TEXT DEFAULT ''
    )");

    // Indexes — these are what make growth a non-issue
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_visited   ON pageviews(visited_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_session   ON pageviews(session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_visitor   ON pageviews(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pv_url       ON pageviews(url)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_time     ON submissions(submitted_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_visitor  ON submissions(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_session  ON submissions(session_id)");
    // Older production databases receive event_id in the migration below.
    // Do not create its index until the column actually exists.
    if (vjt_column_exists('submissions', 'event_id')) {
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_event_id ON submissions(event_id) WHERE event_id <> ''");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_time ON contact_events(occurred_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_channel ON contact_events(channel)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_status ON contact_events(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_visitor ON contact_events(vjt_visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_session ON contact_events(vjt_session_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contact_status_visitor ON contact_events(status, vjt_visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_visitor ON sessions(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_started ON sessions(started_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_seen    ON sessions(last_seen_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_seen     ON visitors(last_seen_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_country  ON visitors(country)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_device   ON visitors(device_type)");
}

function vjt_column_exists($table, $column) {
    $stmt = vjt_db()->query('PRAGMA table_info(' . preg_replace('/[^a-z_]/', '', $table) . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) return true;
    }
    return false;
}

function vjt_add_column_if_missing($table, $definition) {
    $column = strtolower(strtok(trim($definition), ' '));
    if (!vjt_column_exists($table, $column)) {
        vjt_db()->exec('ALTER TABLE ' . preg_replace('/[^a-z_]/', '', $table) . ' ADD COLUMN ' . $definition);
    }
}

function vjt_run_schema_migrations() {
    $db = vjt_db();
    $current = (int)vjt_meta_get('schema_version', 0);
    if ($current >= 5) return;

    try {
        $db->beginTransaction();
        // v1: activity metrics. Keep raw fields so scoring can evolve later.
        vjt_add_column_if_missing('pageviews', 'active_duration_seconds INTEGER DEFAULT 0');
        vjt_add_column_if_missing('pageviews', 'engagement_score INTEGER DEFAULT 0');
        vjt_add_column_if_missing('pageviews', 'is_engaged INTEGER DEFAULT 0');
        vjt_add_column_if_missing('pageviews', 'last_activity_at TEXT DEFAULT \'\'');
        vjt_add_column_if_missing('pageviews', 'heartbeat_count INTEGER DEFAULT 0');
        vjt_add_column_if_missing('pageviews', 'max_scroll_depth INTEGER DEFAULT 0');
        // v2: session-level normalized attribution snapshot.
        vjt_add_column_if_missing('sessions', 'source_slug TEXT DEFAULT \'\'');
        vjt_add_column_if_missing('sessions', 'source_host TEXT DEFAULT \'\'');
        vjt_add_column_if_missing('sessions', 'source_type TEXT DEFAULT \'\'');
        vjt_add_column_if_missing('sessions', 'source_model_version INTEGER DEFAULT 0');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sess_source_slug ON sessions(source_slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sess_source_host ON sessions(source_host)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_sess_source_type ON sessions(source_type)');
        // v4: browser conversion retries use one exact event ID.
        vjt_add_column_if_missing('submissions', 'event_id TEXT DEFAULT \'\'');
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sub_event_id ON submissions(event_id) WHERE event_id <> ''");
        // v5: consent-independent Contact Core. The table is created by the
        // idempotent schema pass above; the version records the architecture.
        vjt_meta_set('schema_version', 5);
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('VJT schema migration error: ' . $e->getMessage());
        throw $e;
    }
}

function vjt_meta_get($key, $default = null) {
    try {
        $stmt = vjt_db()->prepare("SELECT value FROM meta WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (Exception $e) { return $default; }
}

function vjt_meta_set($key, $value) {
    try {
        $stmt = vjt_db()->prepare("INSERT INTO meta (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $stmt->execute([$key, (string)$value]);
    } catch (Exception $e) {}
}

function vjt_data_init() {
    if (!is_dir(VJT_DATA_DIR)) {
        if (!@mkdir(VJT_DATA_DIR, 0700, true)) {
            error_log("VJT ERROR: Failed to create data directory at " . VJT_DATA_DIR);
        }
    }
    vjt_create_schema();
    vjt_run_schema_migrations();

    // Seed default settings if missing
    $defaults = [
        'session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1',
        'excluded_ips' => '', 'heartbeat_seconds' => '45', 'enable_email_summary' => '1',
        'contact_intent_retention_days' => '90', 'contact_inquiry_retention_days' => '730'
    ];
    foreach ($defaults as $k => $v) {
        $stmt = vjt_db()->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$k, $v]);
    }

    // One-time migration from the old JSON flat files
    vjt_migrate_json_if_needed();

    // Backfill attribution snapshots gradually so a large legacy database never
    // receives a long blocking migration during a public tracking request.
    if ((int)vjt_meta_get('source_backfill_at', 0) < time() - 3600) {
        vjt_backfill_source_metadata(500);
        vjt_meta_set('source_backfill_at', time());
    }

    // Run auto-cleanup once per day
    vjt_auto_cleanup();
}

// ── One-time JSON → SQLite migration ─────────────────────────────────────────

function vjt_read_legacy_json($filename) {
    $path = VJT_DATA_DIR . '/' . $filename;
    if (!file_exists($path)) return null;
    $content = @file_get_contents($path);
    if ($content === false || $content === '') return null;
    return json_decode($content, true);
}

function vjt_migrate_json_if_needed() {
    if (vjt_meta_get('json_migrated') === '1') return;

    $db = vjt_db();
    // Only migrate if legacy files exist
    $hasLegacy = file_exists(VJT_DATA_DIR . '/pageviews.json')
        || file_exists(VJT_DATA_DIR . '/visitors.json')
        || file_exists(VJT_DATA_DIR . '/submissions.json');
    if (!$hasLegacy) {
        vjt_meta_set('json_migrated', '1');
        return;
    }

    try {
        $db->beginTransaction();

        $visitors = vjt_read_legacy_json('visitors.json');
        if (is_array($visitors)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO visitors
                (visitor_id, first_ip, country, city, user_agent, browser, device_type,
                 screen_resolution, timezone, language, site_language, first_seen_at, last_seen_at)
                VALUES (:visitor_id,:first_ip,:country,:city,:user_agent,:browser,:device_type,
                 :screen_resolution,:timezone,:language,:site_language,:first_seen_at,:last_seen_at)");
            foreach ($visitors as $vid => $v) {
                if (!is_array($v)) continue;
                $stmt->execute([
                    ':visitor_id' => $v['visitor_id'] ?? $vid,
                    ':first_ip' => $v['first_ip'] ?? '',
                    ':country' => $v['country'] ?? '',
                    ':city' => $v['city'] ?? '',
                    ':user_agent' => $v['user_agent'] ?? '',
                    ':browser' => $v['browser'] ?? 'Unknown',
                    ':device_type' => $v['device_type'] ?? 'Unknown',
                    ':screen_resolution' => $v['screen_resolution'] ?? '',
                    ':timezone' => $v['timezone'] ?? '',
                    ':language' => $v['language'] ?? '',
                    ':site_language' => $v['site_language'] ?? 'EN',
                    ':first_seen_at' => $v['first_seen_at'] ?? '',
                    ':last_seen_at' => $v['last_seen_at'] ?? '',
                ]);
            }
        }

        $sessions = vjt_read_legacy_json('sessions.json');
        if (is_array($sessions)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO sessions
                (session_id, visitor_id, ip, country, city, region, calling_code, referrer,
                 landing_url, landing_title, utm_source, utm_medium, utm_campaign, utm_content,
                 utm_term, started_at, last_seen_at)
                VALUES (:session_id,:visitor_id,:ip,:country,:city,:region,:calling_code,:referrer,
                 :landing_url,:landing_title,:utm_source,:utm_medium,:utm_campaign,:utm_content,
                 :utm_term,:started_at,:last_seen_at)");
            foreach ($sessions as $sid => $s) {
                if (!is_array($s)) continue;
                $stmt->execute([
                    ':session_id' => $s['session_id'] ?? $sid,
                    ':visitor_id' => $s['visitor_id'] ?? '',
                    ':ip' => $s['ip'] ?? '',
                    ':country' => $s['country'] ?? '',
                    ':city' => $s['city'] ?? '',
                    ':region' => $s['region'] ?? '',
                    ':calling_code' => $s['calling_code'] ?? '',
                    ':referrer' => $s['referrer'] ?? '',
                    ':landing_url' => $s['landing_url'] ?? '',
                    ':landing_title' => $s['landing_title'] ?? '',
                    ':utm_source' => $s['utm_source'] ?? '',
                    ':utm_medium' => $s['utm_medium'] ?? '',
                    ':utm_campaign' => $s['utm_campaign'] ?? '',
                    ':utm_content' => $s['utm_content'] ?? '',
                    ':utm_term' => $s['utm_term'] ?? '',
                    ':started_at' => $s['started_at'] ?? '',
                    ':last_seen_at' => $s['last_seen_at'] ?? '',
                ]);
            }
        }

        $pageviews = vjt_read_legacy_json('pageviews.json');
        if (is_array($pageviews)) {
            $stmt = $db->prepare("INSERT INTO pageviews
                (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, scroll_depth, step_order)
                VALUES (:session_id,:visitor_id,:url,:title,:visited_at,:leave_at,:duration_seconds,:scroll_depth,:step_order)");
            foreach ($pageviews as $pv) {
                if (!is_array($pv)) continue;
                $stmt->execute([
                    ':session_id' => $pv['session_id'] ?? '',
                    ':visitor_id' => $pv['visitor_id'] ?? '',
                    ':url' => $pv['url'] ?? '',
                    ':title' => $pv['title'] ?? '',
                    ':visited_at' => $pv['visited_at'] ?? '',
                    ':leave_at' => $pv['leave_at'] ?? null,
                    ':duration_seconds' => (int)($pv['duration_seconds'] ?? 0),
                    ':scroll_depth' => (int)($pv['scroll_depth'] ?? 0),
                    ':step_order' => (int)($pv['step_order'] ?? 1),
                ]);
            }
        }

        $submissions = vjt_read_legacy_json('submissions.json');
        if (is_array($submissions)) {
            $stmt = $db->prepare("INSERT INTO submissions
                (visitor_id, session_id, form_plugin, form_id, form_name, submit_page, submit_title,
                 submitted_at, status, contact_url, ip, country, city, region, calling_code)
                VALUES (:visitor_id,:session_id,:form_plugin,:form_id,:form_name,:submit_page,:submit_title,
                 :submitted_at,:status,:contact_url,:ip,:country,:city,:region,:calling_code)");
            foreach ($submissions as $sub) {
                if (!is_array($sub)) continue;
                $stmt->execute([
                    ':visitor_id' => $sub['visitor_id'] ?? '',
                    ':session_id' => $sub['session_id'] ?? '',
                    ':form_plugin' => $sub['form_plugin'] ?? '',
                    ':form_id' => $sub['form_id'] ?? '',
                    ':form_name' => $sub['form_name'] ?? '',
                    ':submit_page' => $sub['submit_page'] ?? '',
                    ':submit_title' => $sub['submit_title'] ?? '',
                    ':submitted_at' => $sub['submitted_at'] ?? '',
                    ':status' => $sub['status'] ?? 'attempt',
                    ':contact_url' => $sub['contact_url'] ?? '',
                    ':ip' => $sub['ip'] ?? '',
                    ':country' => $sub['country'] ?? '',
                    ':city' => $sub['city'] ?? '',
                    ':region' => $sub['region'] ?? '',
                    ':calling_code' => $sub['calling_code'] ?? '',
                ]);
            }
        }

        $geo = vjt_read_legacy_json('geo_cache.json');
        if (is_array($geo)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO geo_cache
                (ip, country, city, region, calling_code, cached_at)
                VALUES (:ip,:country,:city,:region,:calling_code,:cached_at)");
            foreach ($geo as $ip => $entry) {
                if (!is_array($entry)) continue;
                $stmt->execute([
                    ':ip' => $ip,
                    ':country' => $entry['country'] ?? '',
                    ':city' => $entry['city'] ?? '',
                    ':region' => $entry['region'] ?? '',
                    ':calling_code' => $entry['calling_code'] ?? '',
                    ':cached_at' => $entry['cached_at'] ?? '',
                ]);
            }
        }

        $legacySettings = vjt_read_legacy_json('settings.json');
        if (is_array($legacySettings)) {
            foreach ($legacySettings as $k => $v) {
                if (!is_scalar($v)) continue;
                $st = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                    ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                $st->execute([$k, (string)$v]);
            }
        }

        $db->commit();
        vjt_meta_set('json_migrated', '1');

        // Archive legacy files so they are not re-read (keep as .bak backup)
        foreach (['visitors.json','sessions.json','pageviews.json','submissions.json','geo_cache.json','settings.json'] as $f) {
            $p = VJT_DATA_DIR . '/' . $f;
            if (file_exists($p)) @rename($p, $p . '.migrated.bak');
        }
        error_log('VJT: migrated legacy JSON data into SQLite.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('VJT migration error: ' . $e->getMessage());
    }
}

/**
 * Prune data older than retention_days. Runs at most once per 24h.
 * SQLite makes this a few indexed DELETEs instead of full-file rewrites.
 */
function vjt_auto_cleanup() {
    $now = time();
    $last = (int)vjt_meta_get('last_cleanup', 0);
    if ($last > 0 && ($now - $last) < 86400) return;

    $settings = vjt_get_settings();
    $retentionDays = (int)($settings['retention_days'] ?? 90);
    if ($retentionDays < 1) $retentionDays = 90;
    $cutoff = gmdate('Y-m-d H:i:s', $now - ($retentionDays * 86400));

    try {
        $db = vjt_db();
        $db->prepare("DELETE FROM pageviews WHERE visited_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM submissions WHERE submitted_at < ?")->execute([$cutoff]);
        $intentDays = max(1, (int)($settings['contact_intent_retention_days'] ?? 90));
        $inquiryDays = max(1, (int)($settings['contact_inquiry_retention_days'] ?? 730));
        $intentCutoff = gmdate('Y-m-d H:i:s', $now - ($intentDays * 86400));
        $inquiryCutoff = gmdate('Y-m-d H:i:s', $now - ($inquiryDays * 86400));
        $db->prepare("DELETE FROM contact_events WHERE retention_class = 'intent_short' AND occurred_at < ?")->execute([$intentCutoff]);
        $db->prepare("DELETE FROM contact_events WHERE retention_class = 'customer_inquiry' AND occurred_at < ?")->execute([$inquiryCutoff]);
        $db->prepare("DELETE FROM sessions WHERE last_seen_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM visitors WHERE last_seen_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM geo_cache WHERE cached_at < ?")->execute([$cutoff]);
        // Drop stale geo-queue entries (anything older than 2 days is unlikely to matter)
        $db->prepare("DELETE FROM geo_queue WHERE queued_at < ?")->execute([gmdate('Y-m-d H:i:s', $now - 2 * 86400)]);
        vjt_meta_set('last_cleanup', $now);
        // Reclaim space periodically (cheap on a bounded DB)
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Exception $e) {
        error_log('VJT cleanup error: ' . $e->getMessage());
    }
}

// ── UUID generation ─────────────────────────────────────────────────────────

function vjt_uuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── Visitor ─────────────────────────────────────────────────────────────────

function vjt_upsert_visitor($data) {
    $db = vjt_db();
    $vid = $data['visitor_id'];
    $now = gmdate('Y-m-d H:i:s');

    $stmt = $db->prepare("SELECT visitor_id FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$vid]);
    $exists = (bool)$stmt->fetch();

    if ($exists) {
        // Build a dynamic update that only overwrites meaningful new values
        $sets = ['last_seen_at = :last_seen_at'];
        $params = [':last_seen_at' => $now, ':vid' => $vid];
        $maybe = [
            'country' => $data['country'] ?? '',
            'city' => $data['city'] ?? '',
            'screen_resolution' => $data['screen_resolution'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'language' => $data['language'] ?? '',
            'site_language' => $data['site_language'] ?? '',
            'user_agent' => $data['user_agent'] ?? '',
        ];
        foreach ($maybe as $col => $val) {
            if ($val !== '') { $sets[] = "$col = :$col"; $params[":$col"] = $val; }
        }
        // browser/device only overwrite when not Unknown
        if (!empty($data['browser']) && $data['browser'] !== 'Unknown') {
            $sets[] = "browser = :browser"; $params[':browser'] = $data['browser'];
        }
        if (!empty($data['device_type']) && $data['device_type'] !== 'Unknown') {
            $sets[] = "device_type = :device_type"; $params[':device_type'] = $data['device_type'];
        }
        $sql = "UPDATE visitors SET " . implode(', ', $sets) . " WHERE visitor_id = :vid";
        $db->prepare($sql)->execute($params);
    } else {
        $stmt = $db->prepare("INSERT INTO visitors
            (visitor_id, first_ip, country, city, user_agent, browser, device_type,
             screen_resolution, timezone, language, site_language, first_seen_at, last_seen_at)
            VALUES (:visitor_id,:first_ip,:country,:city,:user_agent,:browser,:device_type,
             :screen_resolution,:timezone,:language,:site_language,:first_seen_at,:last_seen_at)");
        $stmt->execute([
            ':visitor_id' => $vid,
            ':first_ip' => $data['first_ip'] ?? '',
            ':country' => $data['country'] ?? '',
            ':city' => $data['city'] ?? '',
            ':user_agent' => $data['user_agent'] ?? '',
            ':browser' => $data['browser'] ?? 'Unknown',
            ':device_type' => $data['device_type'] ?? 'Unknown',
            ':screen_resolution' => $data['screen_resolution'] ?? '',
            ':timezone' => $data['timezone'] ?? '',
            ':language' => $data['language'] ?? '',
            ':site_language' => $data['site_language'] ?? 'EN',
            ':first_seen_at' => $now,
            ':last_seen_at' => $now,
        ]);
    }
}

// ── Session ─────────────────────────────────────────────────────────────────

function vjt_upsert_session($data) {
    $db = vjt_db();
    $sid = $data['session_id'];
    $now = gmdate('Y-m-d H:i:s');

    $stmt = $db->prepare("SELECT referrer, landing_url, landing_title,
        utm_source, utm_medium, utm_campaign, utm_content, utm_term,
        source_slug, source_host, source_type, source_model_version
        FROM sessions WHERE session_id = ?");
    $stmt->execute([$sid]);
    $row = $stmt->fetch();

    if ($row) {
        $sets = ['last_seen_at = :last_seen_at'];
        $params = [':last_seen_at' => $now, ':sid' => $sid];
        $attributionChanged = false;
        foreach (['ip', 'country', 'city', 'region', 'calling_code'] as $col) {
            if (!empty($data[$col])) { $sets[] = "$col = :$col"; $params[":$col"] = $data[$col]; }
        }
        // Late capture: only fill these if currently empty
        if (empty($row['referrer']) && !empty($data['referrer'])) {
            $sets[] = "referrer = :referrer"; $params[':referrer'] = $data['referrer'];
            $attributionChanged = true;
        }
        if (empty($row['landing_url']) && !empty($data['landing_url'])) {
            $sets[] = "landing_url = :landing_url"; $params[':landing_url'] = $data['landing_url'];
        }
        if (empty($row['landing_title']) && !empty($data['landing_title'])) {
            $sets[] = "landing_title = :landing_title"; $params[':landing_title'] = $data['landing_title'];
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $col) {
            if (empty($row[$col]) && !empty($data[$col])) {
                $sets[] = "$col = :$col"; $params[":$col"] = vjt_clip($data[$col], 255);
                $attributionChanged = true;
            }
        }
        $sourceInput = $row;
        foreach (['referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            if (!empty($data[$field])) $sourceInput[$field] = $data[$field];
        }
        $source = vjt_source_metadata($sourceInput);
        if ($attributionChanged || (int)($row['source_model_version'] ?? 0) < VJT_SOURCE_MODEL_VERSION || empty($row['source_slug'])) {
            foreach (['source_slug', 'source_host', 'source_type'] as $col) {
                $sets[] = "$col = :$col";
                $params[":$col"] = $source[$col];
            }
            $sets[] = 'source_model_version = :source_model_version';
            $params[':source_model_version'] = VJT_SOURCE_MODEL_VERSION;
        }
        $sql = "UPDATE sessions SET " . implode(', ', $sets) . " WHERE session_id = :sid";
        $db->prepare($sql)->execute($params);
    } else {
        $source = vjt_source_metadata($data);
        $stmt = $db->prepare("INSERT INTO sessions
            (session_id, visitor_id, ip, country, city, region, calling_code, referrer,
             landing_url, landing_title, utm_source, utm_medium, utm_campaign, utm_content,
             utm_term, source_slug, source_host, source_type, source_model_version, started_at, last_seen_at)
            VALUES (:session_id,:visitor_id,:ip,:country,:city,:region,:calling_code,:referrer,
             :landing_url,:landing_title,:utm_source,:utm_medium,:utm_campaign,:utm_content,
             :utm_term,:source_slug,:source_host,:source_type,:source_model_version,:started_at,:last_seen_at)");
        $stmt->execute([
            ':session_id' => $sid,
            ':visitor_id' => $data['visitor_id'] ?? '',
            ':ip' => $data['ip'] ?? '',
            ':country' => $data['country'] ?? '',
            ':city' => $data['city'] ?? '',
            ':region' => $data['region'] ?? '',
            ':calling_code' => $data['calling_code'] ?? '',
            ':referrer' => $data['referrer'] ?? '',
            ':landing_url' => $data['landing_url'] ?? '',
            ':landing_title' => $data['landing_title'] ?? '',
            ':utm_source' => $data['utm_source'] ?? '',
            ':utm_medium' => $data['utm_medium'] ?? '',
            ':utm_campaign' => $data['utm_campaign'] ?? '',
            ':utm_content' => $data['utm_content'] ?? '',
            ':utm_term' => $data['utm_term'] ?? '',
            ':source_slug' => $source['source_slug'],
            ':source_host' => $source['source_host'],
            ':source_type' => $source['source_type'],
            ':source_model_version' => VJT_SOURCE_MODEL_VERSION,
            ':started_at' => $now,
            ':last_seen_at' => $now,
        ]);
    }
}

// ── Pageview ────────────────────────────────────────────────────────────────

function vjt_add_pageview($data) {
    $db = vjt_db();
    $stmt = $db->prepare("INSERT INTO pageviews
        (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, active_duration_seconds,
         engagement_score, is_engaged, last_activity_at, heartbeat_count, scroll_depth, max_scroll_depth, step_order)
        VALUES (:session_id,:visitor_id,:url,:title,:visited_at,:leave_at,:duration_seconds,:active_duration_seconds,
         :engagement_score,:is_engaged,:last_activity_at,:heartbeat_count,:scroll_depth,:max_scroll_depth,:step_order)");
    $stmt->execute([
        ':session_id' => $data['session_id'],
        ':visitor_id' => $data['visitor_id'],
        ':url' => $data['url'] ?? '',
        ':title' => $data['title'] ?? '',
        ':visited_at' => vjt_to_beijing($data['visited_at'] ?? ''),
        ':leave_at' => !empty($data['leave_at']) ? vjt_to_beijing($data['leave_at']) : null,
        ':duration_seconds' => (int)($data['duration_seconds'] ?? 0),
        ':active_duration_seconds' => max(0, (int)($data['active_duration_seconds'] ?? 0)),
        ':engagement_score' => min(100, max(0, (int)($data['engagement_score'] ?? 0))),
        ':is_engaged' => !empty($data['is_engaged']) ? 1 : 0,
        ':last_activity_at' => !empty($data['last_activity_at']) ? vjt_to_beijing($data['last_activity_at']) : '',
        ':heartbeat_count' => max(0, (int)($data['heartbeat_count'] ?? 0)),
        ':scroll_depth' => (int)($data['scroll_depth'] ?? 0),
        ':max_scroll_depth' => max(0, min(100, (int)($data['max_scroll_depth'] ?? ($data['scroll_depth'] ?? 0)))),
        ':step_order' => (int)($data['step_order'] ?? 1),
    ]);
}

function vjt_update_pageview_leave($sessionId, $url, $leaveAt, $duration, $scrollDepth, $activeDuration = 0, $heartbeatCount = 0, $maxScroll = 0, $lastActivityAt = '', $engagementScore = 0, $isEngaged = false, $stepOrder = 0) {
    $db = vjt_db();
    // Visibility changes may temporarily close a pageview and later resume it.
    // Prefer the immutable step_order so delayed packets for a repeated URL do
    // not update a newer visit to that same URL.
    $where = 'session_id = ? AND url = ?';
    $params = [$sessionId, $url];
    if ((int)$stepOrder > 0) {
        $where .= ' AND step_order = ?';
        $params[] = (int)$stepOrder;
    }
    $stmt = $db->prepare("SELECT id, scroll_depth, max_scroll_depth, duration_seconds, active_duration_seconds, heartbeat_count, engagement_score, is_engaged FROM pageviews
        WHERE $where ORDER BY id DESC LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) return;

    $newScroll = max((int)$row['scroll_depth'], (int)$scrollDepth, (int)$maxScroll);
    $upd = $db->prepare("UPDATE pageviews
        SET leave_at = CASE WHEN :leave_at <> '' THEN :leave_at ELSE leave_at END,
            duration_seconds = MAX(duration_seconds, :duration),
            active_duration_seconds = MAX(active_duration_seconds, :active_duration),
            heartbeat_count = MAX(heartbeat_count, :heartbeat_count),
            engagement_score = MAX(engagement_score, :engagement_score),
            is_engaged = MAX(is_engaged, :is_engaged),
            last_activity_at = CASE WHEN :last_activity_at <> '' THEN :last_activity_at ELSE last_activity_at END,
            scroll_depth = :scroll, max_scroll_depth = MAX(max_scroll_depth, :scroll)
        WHERE id = :id");
    $upd->execute([
        ':leave_at' => vjt_to_beijing($leaveAt),
        ':duration' => max(0, (int)$duration),
        ':active_duration' => max(0, (int)$activeDuration),
        ':heartbeat_count' => max(0, (int)$heartbeatCount),
        ':engagement_score' => min(100, max(0, (int)$engagementScore)),
        ':is_engaged' => $isEngaged ? 1 : 0,
        ':last_activity_at' => !empty($lastActivityAt) ? vjt_to_beijing($lastActivityAt) : '',
        ':scroll' => $newScroll,
        ':id' => $row['id'],
    ]);
}

function vjt_sync_pageview_snapshot($sessionId, $pages) {
    if (empty($pages)) return;
    $db = vjt_db();

    // Existing URLs for this session
    $stmt = $db->prepare("SELECT url FROM pageviews WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $existing = [];
    foreach ($stmt->fetchAll() as $r) { $existing[$r['url']] = true; }
    $step = count($existing);

    $ins = $db->prepare("INSERT INTO pageviews
        (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, scroll_depth, step_order)
        VALUES (:session_id,:visitor_id,:url,:title,:visited_at,NULL,0,0,:step_order)");
    foreach ($pages as $page) {
        $url = $page['url'] ?? '';
        if ($url === '' || isset($existing[$url])) continue;
        $ins->execute([
            ':session_id' => $sessionId,
            ':visitor_id' => $page['visitor_id'] ?? '',
            ':url' => $url,
            ':title' => $page['title'] ?? '',
            ':visited_at' => vjt_to_beijing($page['visited_at'] ?? ''),
            ':step_order' => ++$step,
        ]);
        $existing[$url] = true;
    }
}

// ── Submission ──────────────────────────────────────────────────────────────

function vjt_add_submission($data) {
    $db = vjt_db();
    $now = gmdate('Y-m-d H:i:s');
    $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
    $eventId = trim((string)($data['event_id'] ?? ''));

    // Retries retain one event ID, so a transient network failure is safe to
    // resend without suppressing a separate intentional contact click.
    if ($eventId !== '') {
        $stmt = $db->prepare('SELECT id FROM submissions WHERE event_id = ? LIMIT 1');
        $stmt->execute([$eventId]);
        if ($stmt->fetch()) return 'duplicate';
    }

    // Backward-compatible fallback for server-originated records that do not
    // have an event ID (for example, a confirmed SMTP Inquiry result).
    $stmt = $db->prepare("SELECT id FROM submissions
        WHERE visitor_id = ? AND session_id = ? AND form_plugin = ? AND status = ?
          AND submitted_at >= ? LIMIT 1");
    if ($eventId === '') {
        $stmt->execute([
            $data['visitor_id'],
            $data['session_id'],
            $data['form_plugin'] ?? '',
            $data['status'] ?? 'attempt',
            $cutoff,
        ]);
        if ($stmt->fetch()) return 'duplicate';
    }

    $ins = $db->prepare("INSERT OR IGNORE INTO submissions
        (visitor_id, session_id, form_plugin, form_id, form_name, submit_page, submit_title,
         submitted_at, event_id, status, contact_url, ip, country, city, region, calling_code)
        VALUES (:visitor_id,:session_id,:form_plugin,:form_id,:form_name,:submit_page,:submit_title,
         :submitted_at,:event_id,:status,:contact_url,:ip,:country,:city,:region,:calling_code)");
    $ins->execute([
        ':visitor_id' => $data['visitor_id'],
        ':session_id' => $data['session_id'],
        ':form_plugin' => $data['form_plugin'] ?? '',
        ':form_id' => $data['form_id'] ?? '',
        ':form_name' => $data['form_name'] ?? '',
        ':submit_page' => $data['submit_page'] ?? '',
        ':submit_title' => $data['submit_title'] ?? '',
        ':submitted_at' => $now,
        ':event_id' => $eventId,
        ':status' => $data['status'] ?? 'attempt',
        ':contact_url' => $data['contact_url'] ?? '',
        ':ip' => $data['ip'] ?? '',
        ':country' => $data['country'] ?? '',
        ':city' => $data['city'] ?? '',
        ':region' => $data['region'] ?? '',
        ':calling_code' => $data['calling_code'] ?? '',
    ]);
    return $ins->rowCount() > 0 ? 'stored' : 'duplicate';
}

// ── Contact Core ────────────────────────────────────────────────────────────

function vjt_contact_page_path($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path[0] !== '/') return '';
    return vjt_clip($path, 512);
}

function vjt_contact_token($value, $max = 64) {
    $value = strtolower(trim((string)$value));
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,' . max(0, $max - 1) . '}$/', $value) ? $value : '';
}

/**
 * Store one minimal business-contact event. Analytics identifiers are optional
 * enrichment and are discarded unless both values match VJT's ID format.
 */
function vjt_add_contact_event($data) {
    $channel = strtolower(trim((string)($data['channel'] ?? '')));
    $allowedChannels = ['whatsapp', 'mailto', 'inquiry'];
    if (!in_array($channel, $allowedChannels, true)) {
        throw new InvalidArgumentException('Invalid contact channel');
    }

    $eventType = strtolower(trim((string)($data['event_type'] ?? '')));
    $allowedTypes = ['open_intent', 'submission_success', 'submission_error'];
    if (!in_array($eventType, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid contact event type');
    }

    $status = strtolower(trim((string)($data['status'] ?? '')));
    if (!in_array($status, ['intent', 'success', 'error'], true)) {
        throw new InvalidArgumentException('Invalid contact status');
    }

    $visitorId = trim((string)($data['vjt_visitor_id'] ?? ''));
    $sessionId = trim((string)($data['vjt_session_id'] ?? ''));
    if (!preg_match('/^vjtv_[A-Za-z0-9_-]{8,60}$/', $visitorId)
        || !preg_match('/^vjts_[A-Za-z0-9_-]{8,60}$/', $sessionId)) {
        $visitorId = '';
        $sessionId = '';
    }

    $eventId = trim((string)($data['event_id'] ?? ''));
    if (!preg_match('/^vjtce_[A-Za-z0-9_-]{8,80}$/', $eventId)) {
        $eventId = 'vjtce_' . vjt_uuid();
    }

    $retentionClass = $channel === 'inquiry' ? 'customer_inquiry' : 'intent_short';
    $siteLanguage = strtoupper(vjt_contact_token($data['site_language'] ?? '', 3));
    $placement = vjt_contact_token($data['placement'] ?? '', 64);
    $productSku = vjt_contact_token($data['product_sku'] ?? '', 80);

    $stmt = vjt_db()->prepare("INSERT OR IGNORE INTO contact_events
        (event_id, channel, event_type, occurred_at, page_path, placement,
         product_sku, site_language, status, vjt_visitor_id, vjt_session_id, retention_class)
        VALUES (:event_id,:channel,:event_type,:occurred_at,:page_path,:placement,
         :product_sku,:site_language,:status,:vjt_visitor_id,:vjt_session_id,:retention_class)");
    $stmt->execute([
        ':event_id' => $eventId,
        ':channel' => $channel,
        ':event_type' => $eventType,
        ':occurred_at' => gmdate('Y-m-d H:i:s'),
        ':page_path' => vjt_contact_page_path($data['page_path'] ?? ''),
        ':placement' => $placement,
        ':product_sku' => $productSku,
        ':site_language' => $siteLanguage,
        ':status' => $status,
        ':vjt_visitor_id' => $visitorId,
        ':vjt_session_id' => $sessionId,
        ':retention_class' => $retentionClass,
    ]);

    return ['result' => $stmt->rowCount() > 0 ? 'stored' : 'duplicate', 'event_id' => $eventId];
}

function vjt_get_contact_events_list($filters) {
    $where = [];
    $params = [];
    $channel = strtolower(trim((string)($filters['channel'] ?? '')));
    $status = strtolower(trim((string)($filters['status'] ?? '')));
    if (in_array($channel, ['whatsapp', 'mailto', 'inquiry'], true)) {
        $where[] = 'channel = ?';
        $params[] = $channel;
    }
    if (in_array($status, ['intent', 'success', 'error'], true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'occurred_at >= ?';
        $params[] = vjt_admin_date_to_utc($filters['date_from']);
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'occurred_at <= ?';
        $params[] = vjt_admin_date_to_utc($filters['date_to'], true);
    }

    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $db = vjt_db();
    $total = null;
    if (empty($filters['skip_count'])) {
        $countStmt = $db->prepare('SELECT COUNT(*) AS c FROM contact_events' . $sqlWhere);
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['c'] ?? 0);
    }

    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = min(10000, max(1, (int)($filters['per_page'] ?? 100)));
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare('SELECT * FROM contact_events' . $sqlWhere . ' ORDER BY occurred_at DESC, id DESC LIMIT ? OFFSET ?');
    $index = 1;
    foreach ($params as $value) $stmt->bindValue($index++, $value, PDO::PARAM_STR);
    $stmt->bindValue($index++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($index, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return ['items' => $stmt->fetchAll(), 'total' => $total];
}

// ── Geo (item 6: non-blocking) ───────────────────────────────────────────────

/**
 * On the ingest path this NEVER makes an external HTTP call.
 * It returns cached geo if present, otherwise queues the IP for later
 * batch resolution (vjt_process_geo_queue, run from the dashboard) and
 * returns empty geo immediately.
 */
function vjt_resolve_geo($ip) {
    if (in_array($ip, ['127.0.0.1', '::1', '']) ||
        !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    }

    // Respect the geo toggle
    $settings = vjt_get_settings();
    if (($settings['enable_geo'] ?? '1') !== '1') {
        return ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
    }

    try {
        $db = vjt_db();
        $stmt = $db->prepare("SELECT country, city, region, calling_code FROM geo_cache WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'country' => $row['country'] ?? '',
                'city' => $row['city'] ?? '',
                'region' => $row['region'] ?? '',
                'calling_code' => $row['calling_code'] ?? '',
            ];
        }
        // Not cached → queue for off-path resolution (no blocking HTTP here)
        $q = $db->prepare("INSERT OR IGNORE INTO geo_queue (ip, queued_at) VALUES (?, ?)");
        $q->execute([$ip, gmdate('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        error_log('VJT geo enqueue error: ' . $e->getMessage());
    }

    return ['country' => '', 'city' => '', 'region' => '', 'calling_code' => ''];
}

/**
 * Resolve queued IPs in batch (ip-api.com /batch, up to 100 per call).
 * Called from the dashboard, off the visitor ingest path. Backfills the
 * country/city of sessions, visitors and submissions that used the IP.
 */
function vjt_process_geo_queue($limit = 100) {
    $settings = vjt_get_settings();
    if (($settings['enable_geo'] ?? '1') !== '1') return 0;

    $db = vjt_db();
    try {
        $stmt = $db->prepare("SELECT ip FROM geo_queue ORDER BY queued_at ASC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $ips = array_column($stmt->fetchAll(), 'ip');
    } catch (Exception $e) {
        return 0;
    }
    if (empty($ips)) return 0;

    $resolved = 0;
    // Switched from ip-api.com /batch to ipapi.co per-IP HTTPS lookups because:
    //   1. ip-api.com requires the paid Pro plan for HTTPS (free tier is HTTP-only)
    //   2. ipapi.co's free tier supports HTTPS /json/{ip} single-IP lookups
    //   3. We already cache results in geo_cache, so repeat IPs don't re-query
    // Per-IP cost: 1 local MaxMind mmdb read, ~1ms each. No rate limits,
    // no external API, no network egress. Reads only — safe to share one
    // reader instance across the whole process via static.
    //
    // Pre-P2-2-P3-2-hotfix this called http://ip-api.com/batch.
    // P2-2 changed to https://ipapi.co/... (then got RateLimited).
    // Now: local mmdb file, no rate limits, ~1ms lookup.
    foreach ($ips as $ip) {
        // Reuse the same reader across all IPs in this run.
        $record = vjt_mmdb_lookup($ip);
        if (!is_array($record)) continue; // skip on error, leave in queue for retry

        $now = gmdate('Y-m-d H:i:s');
        // MaxMind GeoLite2 City response fields:
        //   $record['country']['iso_code']         — "US" (ISO-2)
        //   $record['country']['calling_code']     — "1" (no "+", just digits)
        //   $record['subdivisions'][0]['iso_code']  — "CA" (state, ISO-2)
        //   $record['city']['names']['en']          — "Mountain View"
        $ip_returned = $ip;
        $country = isset($record['country']['iso_code']) ? strtoupper($record['country']['iso_code']) : '';
        $city    = isset($record['city']['names']['en']) ? $record['city']['names']['en'] : '';
        $region  = isset($record['subdivisions'][0]['iso_code']) ? $record['subdivisions'][0]['iso_code'] : '';
        $calling = isset($record['country']['calling_code']) ? (string)$record['country']['calling_code'] : '';

        // P3-4: ip-api.com fallback to fill city gaps in GeoLite2 (mostly IPv6).
        // Free tier: 45 req/min, supports HTTPS, no API key. Only called when
        // mmdb returns no city — not for every IP.
        if ($city === '') {
            $fallback = vjt_ipapi_fallback_city($ip);
            if ($fallback['city'] !== '')   $city   = $fallback['city'];
            if ($fallback['region'] !== '') $region = $fallback['region'];
        }

        try {
            $db->beginTransaction();
            // Cache (even empty results, to avoid re-querying dead IPs for a day)
            $c = $db->prepare("INSERT INTO geo_cache (ip, country, city, region, calling_code, cached_at)
                VALUES (:ip,:country,:city,:region,:calling_code,:cached_at)
                ON CONFLICT(ip) DO UPDATE SET country=excluded.country, city=excluded.city,
                    region=excluded.region, calling_code=excluded.calling_code, cached_at=excluded.cached_at");
            $c->execute([
                ':ip' => $ip_returned, ':country' => $country, ':city' => $city,
                ':region' => $region, ':calling_code' => $calling, ':cached_at' => $now,
            ]);

            if ($country !== '') {
                // Backfill rows that have this IP but no country yet
                $u1 = $db->prepare("UPDATE sessions SET country=:country, city=:city, region=:region, calling_code=:calling
                    WHERE ip = :ip AND (country IS NULL OR country = '')");
                $u1->execute([':country'=>$country, ':city'=>$city, ':region'=>$region, ':calling'=>$calling, ':ip'=>$ip_returned]);

                $u2 = $db->prepare("UPDATE visitors SET country=:country, city=:city
                    WHERE first_ip = :ip AND (country IS NULL OR country = '')");
                $u2->execute([':country'=>$country, ':city'=>$city, ':ip'=>$ip_returned]);

                $u3 = $db->prepare("UPDATE submissions SET country=:country, city=:city, region=:region, calling_code=:calling
                    WHERE ip = :ip AND (country IS NULL OR country = '')");
                $u3->execute([':country'=>$country, ':city'=>$city, ':region'=>$region, ':calling'=>$calling, ':ip'=>$ip_returned]);
            }

            $db->prepare("DELETE FROM geo_queue WHERE ip = ?")->execute([$ip_returned]);
            $db->commit();
            $resolved++;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('VJT geo backfill error: ' . $e->getMessage());
        }
    }
    return $resolved;
}

// ── Utility ─────────────────────────────────────────────────────────────────

/**
 * P3-4: ip-api.com fallback for missing city data in MaxMind GeoLite2.
 * GeoLite2 (free) has sparse city data for IPv6; ip-api.com fills the gap.
 *
 * Free tier: 45 req/min, HTTPS supported, no API key required.
 * Called only when mmdb returns no city — not for every IP.
 *
 * @param  string $ip   Valid public IP (IPv4 or IPv6)
 * @return array        ['city' => '', 'region' => '']
 */
function vjt_ipapi_fallback_city($ip) {
    $result = ['city' => '', 'region' => ''];
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return $result;

    $url = 'https://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,city,region';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return $result; // timeout / network error → skip

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') return $result;

    $result['city']   = (string)($data['city'] ?? '');
    $result['region'] = (string)($data['region'] ?? '');
    return $result;
}

/**
 * Lookup an IP in the local MaxMind GeoLite2 City mmdb file.
 * Returns the raw record array on success, or null if:
 *   - the file doesn't exist
 *   - the PHP maxminddb extension is not loaded
 *   - the lookup throws (invalid IP format, corrupt mmdb, etc.)
 *
 * The reader is opened once per request (mmdb files are read-only — safe
 * to share across all IPs in a single vjt_process_geo_queue run).
 */
function vjt_mmdb_lookup($ip) {
    static $reader = null;
    static $checked = false;
    static $available = false;

    // Skip the (expensive) mmdb lookup for localhost / empty / private IPs
    // (GeoLite2 has no useful data for them anyway).
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') return null;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    // One-time: try to open the reader
    if (!$checked) {
        $checked = true;
        $mmdb = '/home/kssmi.com/private/geoip/GeoLite2-City.mmdb';
        if (!file_exists($mmdb)) {
            error_log('VJT geo: mmdb not found at ' . $mmdb);
        } elseif (!extension_loaded('maxminddb')) {
            error_log('VJT geo: maxminddb PHP extension not loaded');
        } elseif (!class_exists('\\MaxMind\\Db\\Reader')) {
            error_log('VJT geo: MaxMind\\Db\\Reader class not found');
        } else {
            try {
                $reader = new \MaxMind\Db\Reader($mmdb);
                $available = true;
            } catch (Exception $e) {
                error_log('VJT geo: mmdb open failed: ' . $e->getMessage());
            }
        }
    }

    if (!$available) return null;

    try {
        $record = $reader->get($ip);
    } catch (Exception $e) {
        error_log('VJT geo: mmdb lookup failed for ' . $ip . ': ' . $e->getMessage());
        return null;
    }
    return is_array($record) ? $record : null;
}

function vjt_get_client_ip() {
    // track-pageview.php and track-submission.php load private/rate-limit.php
    // first, which provides the trusted Cloudflare proxy/IP-chain resolver.
    if (function_exists('kssmi_get_client_ip')) {
        $ip = kssmi_get_client_ip();
        return $ip === 'unknown' ? '' : $ip;
    }

    // Safe fallback for any internal caller that did not load the shared helper:
    // never trust client-supplied forwarding headers.
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

// Item 7: detect bots/crawlers so we don't store (or geo-resolve) their hits
function vjt_is_bot($ua) {
    if ($ua === null || trim($ua) === '') return true; // empty UA = almost always a bot/script
    $ua = strtolower($ua);
    static $needles = [
        'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'adsbot', 'apis-google',
        'feedfetcher', 'bingpreview', 'facebookexternalhit', 'facebot', 'ia_archiver',
        'archive.org', 'semrush', 'ahrefs', 'mj12', 'dotbot', 'petalbot', 'yandex',
        'baiduspider', 'sogou', 'exabot', 'gigabot', 'python-requests', 'python-urllib',
        'curl/', 'wget', 'go-http-client', 'java/', 'okhttp', 'headlesschrome',
        'phantomjs', 'puppeteer', 'playwright', 'scrapy', 'httpclient', 'lighthouse',
        'gtmetrix', 'pingdom', 'uptimerobot', 'statuscake', 'censys', 'masscan', 'zgrab',
        'ahc/', 'node-fetch', 'axios/', 'guzzle', 'monitoring', 'preview',
    ];
    foreach ($needles as $n) {
        if (strpos($ua, $n) !== false) return true;
    }
    return false;
}

function vjt_detect_browser($ua) {
    $ua = strtolower($ua);
    if (strpos($ua, 'edg/') !== false) return 'Edge';
    if (strpos($ua, 'chrome/') !== false && strpos($ua, 'edg/') === false) return 'Chrome';
    if (strpos($ua, 'firefox/') !== false) return 'Firefox';
    if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) return 'Safari';
    if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) return 'Opera';
    return 'Unknown';
}

function vjt_detect_device($ua) {
    $ua = strtolower($ua);
    if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) return 'tablet';
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'iphone') !== false || strpos($ua, 'android') !== false) return 'mobile';
    return 'desktop';
}

function vjt_normalize_referrer_host($referrer) {
    $referrer = trim((string)$referrer);
    if ($referrer === '' || strtolower($referrer) === 'direct') return '';

    $candidate = $referrer;
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }
    $host = parse_url($candidate, PHP_URL_HOST);
    if (!$host) return '';
    $host = strtolower(trim($host, " .\t\n\r\0\x0B"));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === 'kssmi.com' || substr($host, -strlen('.kssmi.com')) === '.kssmi.com') {
        return '__internal__';
    }
    return $host;
}

function vjt_source_host_matches($host, $domains) {
    $host = strtolower(trim((string)$host, " .\t\n\r\0\x0B"));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '') return false;

    foreach ($domains as $domain) {
        $domain = strtolower(trim((string)$domain, " .\t\n\r\0\x0B"));
        $domain = preg_replace('/^www\./', '', $domain);
        if ($domain === '') continue;
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) return true;
    }
    return false;
}

function vjt_source_catalog() {
    return [
        'ai' => [
            'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'gemini.google.com', 'bard.google.com',
            'copilot.microsoft.com', 'copilot.cloud.microsoft.com', 'claude.ai', 'grok.com', 'x.ai',
            'chat.deepseek.com', 'deepseek.com', 'doubao.com',
        ],
        'search' => [
            'google.com', 'google.co.uk', 'google.ca', 'google.com.au', 'google.de', 'google.fr',
            'google.co.jp', 'google.com.br', 'google.co.in', 'google.com.hk', 'google.com.sg',
            'google.com.tw', 'google.es', 'google.it', 'google.nl', 'google.com.mx', 'google.co.kr',
            'google.co.nz', 'google.co.za', 'google.ae', 'google.sa', 'google.pl', 'google.pt',
            'google.se', 'google.no', 'google.dk', 'google.fi', 'google.be', 'google.ch', 'google.at',
            'google.cz', 'google.gr', 'google.ie', 'google.co.id', 'google.co.th', 'google.com.tr',
            'google.com.vn', 'google.com.ar', 'google.cl', 'google.com.co', 'bing.com',
            'baidu.com', 'yahoo.com', 'search.yahoo.com', 'yahoo.co.jp', 'duckduckgo.com',
            'yandex.com', 'yandex.ru', 'naver.com', 'sogou.com', 'so.com', '360.cn',
            'search.brave.com', 'ask.com', 'aol.com',
        ],
        'social' => [
            'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'linkedin.com', 'x.com', 't.co',
            'twitter.com', 'youtube.com', 'youtu.be', 'tiktok.com', 'reddit.com', 'pinterest.com',
            'pin.it', 'weibo.com', 'weixin.qq.com', 'mp.weixin.qq.com', 'whatsapp.com',
        ],
    ];
}

function vjt_source_from_host($host) {
    $host = strtolower(trim((string)$host));
    if ($host === '' || $host === '__internal__') return $host === '__internal__' ? 'internal' : 'direct';

    $aliases = [
        'google' => 'search', 'bing' => 'search', 'yahoo' => 'search', 'baidu' => 'search',
        'duckduckgo' => 'search', 'yandex' => 'search', 'naver' => 'search', 'sogou' => 'search',
        'facebook' => 'social', 'instagram' => 'social', 'linkedin' => 'social',
        'twitter' => 'social', 'x' => 'social', 'youtube' => 'social', 'tiktok' => 'social',
        'whatsapp' => 'social', 'reddit' => 'social', 'pinterest' => 'social', 'wechat' => 'social',
        'chatgpt' => 'ai', 'perplexity' => 'ai', 'claude' => 'ai', 'gemini' => 'ai',
        'copilot' => 'ai', 'grok' => 'ai', 'deepseek' => 'ai', 'doubao' => 'ai',
    ];
    if (isset($aliases[$host])) return $aliases[$host];

    foreach (vjt_source_catalog() as $channel => $domains) {
        if (vjt_source_host_matches($host, $domains)) return $channel;
    }
    return 'other';
}

function vjt_classify_source($session) {
    $referrer = $session['referrer'] ?? '';
    $utmSource = strtolower(trim((string)($session['utm_source'] ?? '')));
    $utmMedium = strtolower(trim((string)($session['utm_medium'] ?? '')));

    // Explicit campaign tags win over browser referrer because they are the
    // publisher-controlled attribution signal.
    if (in_array($utmMedium, ['cpc', 'ppc', 'paid', 'paid-search', 'paid_search', 'paid-social', 'paid_social', 'paidads', 'display', 'retargeting', 'remarketing', 'ads'], true)) return 'ads';
    if (in_array($utmMedium, ['social', 'social-media', 'social_network'], true)) return 'social';
    if (in_array($utmMedium, ['email', 'newsletter'], true)) return 'other';
    if ($utmSource !== '') {
        $utmHost = vjt_normalize_referrer_host($utmSource);
        $utmClass = vjt_source_from_host($utmHost ?: $utmSource);
        if ($utmClass !== 'other' || $utmMedium !== '') return $utmClass;
    }

    $host = vjt_normalize_referrer_host($referrer);
    $referrerPath = strtolower((string)parse_url((string)$referrer, PHP_URL_PATH));
    if (vjt_source_host_matches($host, ['bing.com']) && preg_match('#^/chat(?:/|$)#', $referrerPath)) return 'ai';
    return vjt_source_from_host($host);
}

function vjt_source_label($session) {
    $utmSource = trim((string)($session['utm_source'] ?? ''));
    $utmMedium = trim((string)($session['utm_medium'] ?? ''));
    if ($utmSource !== '' || $utmMedium !== '') {
        $source = strtolower(preg_replace('/[\x00-\x1F\x7F]/', '', $utmSource));
        $source = function_exists('mb_substr') ? mb_substr($source, 0, 120, 'UTF-8') : substr($source, 0, 120);
        $medium = strtolower(preg_replace('/[\x00-\x1F\x7F]/', '', $utmMedium));
        $medium = function_exists('mb_substr') ? mb_substr($medium, 0, 60, 'UTF-8') : substr($medium, 0, 60);
        return 'UTM: ' . ($source !== '' ? $source : '(source not set)') . ($medium !== '' ? ' / ' . $medium : '');
    }

    $host = vjt_normalize_referrer_host($session['referrer'] ?? '');
    if ($host === '__internal__') return 'Internal';
    return $host === '' ? 'Direct' : $host;
}

function vjt_source_metadata($session) {
    $host = vjt_normalize_referrer_host($session['referrer'] ?? '');
    $type = vjt_classify_source($session);
    $utmSource = strtolower(trim((string)($session['utm_source'] ?? '')));
    $slug = $utmSource !== '' ? preg_replace('/[^a-z0-9._-]+/', '-', $utmSource) : $host;
    if ($slug === '' || $slug === '__internal__') $slug = $type;
    // Separate Bing chat from normal Bing search without treating all Bing as AI.
    $path = strtolower((string)parse_url((string)($session['referrer'] ?? ''), PHP_URL_PATH));
    if ($type === 'ai' && vjt_source_host_matches($host, ['bing.com']) && preg_match('#^/chat(?:/|$)#', $path)) $slug = 'copilot';
    foreach (['chatgpt', 'perplexity', 'gemini', 'copilot', 'claude', 'grok', 'deepseek', 'doubao'] as $ai) {
        if ($type === 'ai' && ($utmSource === $ai || strpos($host, $ai) !== false)) { $slug = $ai; break; }
    }
    return [
        'source_slug' => vjt_clip($slug, 64),
        'source_host' => vjt_clip($host === '__internal__' ? '' : $host, 190),
        'source_type' => vjt_clip($type, 32),
    ];
}

function vjt_is_google_organic_session($session) {
    if (vjt_classify_source($session) === 'ads') return false;
    $utmSource = strtolower(trim((string)($session['utm_source'] ?? '')));
    if ($utmSource === 'google' || strpos($utmSource, 'google.') === 0) return true;

    $host = vjt_normalize_referrer_host($session['referrer'] ?? '');
    foreach (vjt_source_catalog()['search'] as $domain) {
        if (strpos($domain, 'google.') === 0 && vjt_source_host_matches($host, [$domain])) return true;
    }
    return false;
}

function vjt_backfill_source_metadata($limit = 500) {
    $db = vjt_db();
    $stmt = $db->prepare("SELECT session_id, referrer, utm_source, utm_medium, source_model_version
        FROM sessions WHERE source_model_version < ? OR source_slug = '' LIMIT ?");
    $stmt->execute([VJT_SOURCE_MODEL_VERSION, max(1, min(5000, (int)$limit))]);
    $upd = $db->prepare('UPDATE sessions SET source_slug=?, source_host=?, source_type=?, source_model_version=? WHERE session_id=?');
    foreach ($stmt->fetchAll() as $row) {
        $meta = vjt_source_metadata($row);
        $upd->execute([$meta['source_slug'], $meta['source_host'], $meta['source_type'], VJT_SOURCE_MODEL_VERSION, $row['session_id']]);
    }
}

function vjt_ip_is_excluded($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') return false;
    $rules = preg_split('/[\r\n,]+/', (string)(vjt_get_settings()['excluded_ips'] ?? ''));
    foreach ($rules as $rule) {
        $rule = trim($rule);
        if ($rule === '') continue;
        if (strpos($rule, '/') === false && hash_equals($rule, $ip)) return true;
        if (strpos($rule, '/') !== false) {
            [$network, $bits] = array_pad(explode('/', $rule, 2), 2, '');
            if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ctype_digit($bits) && (int)$bits >= 0 && (int)$bits <= 32) {
                $mask = (int)$bits === 0 ? 0 : (-1 << (32 - (int)$bits));
                if ((ip2long($ip) & $mask) === (ip2long($network) & $mask)) return true;
            }
        }
    }
    return false;
}

// ── Settings ────────────────────────────────────────────────────────────────

function vjt_get_settings($forceReload = false) {
    static $cache = null;
    if ($cache !== null && !$forceReload) return $cache;
    $defaults = [
        'session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1',
        'excluded_ips' => '', 'heartbeat_seconds' => '45', 'enable_email_summary' => '1',
        'contact_intent_retention_days' => '90', 'contact_inquiry_retention_days' => '730',
    ];
    try {
        $settingsStmt = vjt_db()->prepare("SELECT key, value FROM settings");
        $settingsStmt->execute();
        $rows = $settingsStmt->fetchAll();
        $out = $defaults;
        foreach ($rows as $r) { $out[$r['key']] = $r['value']; }
        $cache = $out;
        return $out;
    } catch (Exception $e) {
        return $defaults;
    }
}

function vjt_save_settings($data) {
    $db = vjt_db();
    $settings = [
        'session_timeout' => (string)max(5, (int)($data['session_timeout'] ?? 30)),
        'retention_days'  => (string)max(1, (int)($data['retention_days'] ?? 90)),
        'enable_geo'      => !empty($data['enable_geo']) ? '1' : '0',
        'excluded_ips'    => vjt_clip(trim((string)($data['excluded_ips'] ?? '')), 4096),
        'heartbeat_seconds'=> (string)min(120, max(30, (int)($data['heartbeat_seconds'] ?? 45))),
        'enable_email_summary' => !empty($data['enable_email_summary']) ? '1' : '0',
        'contact_intent_retention_days' => (string)min(365, max(1, (int)($data['contact_intent_retention_days'] ?? 90))),
        'contact_inquiry_retention_days' => (string)min(3650, max(1, (int)($data['contact_inquiry_retention_days'] ?? 730))),
    ];
    $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    // Refresh the in-request static cache so the dashboard shows new values immediately
    vjt_get_settings(true);
}

// ── Dashboard: Overview Stats ───────────────────────────────────────────────

function vjt_get_overview($since) {
    $db = vjt_db();

    $totalVisitorsStmt = $db->prepare("SELECT COUNT(*) c FROM visitors WHERE last_seen_at >= ?");
    $totalVisitorsStmt->execute([$since]);
    $totalVisitors = (int)$totalVisitorsStmt->fetch()['c'];
    $totalSessionsStmt = $db->prepare("SELECT COUNT(*) c FROM sessions WHERE started_at >= ?");
    $totalSessionsStmt->execute([$since]);
    $totalSessions = (int)$totalSessionsStmt->fetch()['c'];

    // Contact Core is the canonical Lead ledger. L counts only Core contacts
    // with a consented Journey linkage; unlinked contacts remain visible in C
    // and in the Leads list, but cannot be attributed to a unique visitor.
    $leadStmt = $db->prepare("SELECT
            COUNT(DISTINCT CASE WHEN status IN ('success','intent') AND vjt_visitor_id <> '' THEN vjt_visitor_id END) total,
            COUNT(DISTINCT CASE WHEN status = 'success' AND vjt_visitor_id <> '' THEN vjt_visitor_id END) success
        FROM contact_events WHERE occurred_at >= ?");
    $leadStmt->execute([$since]);
    $leadRow = $leadStmt->fetch();
    $totalLeads = (int)($leadRow['total'] ?? 0);
    $successLeads = (int)($leadRow['success'] ?? 0);

    // C follows the same selected Overview period as Visitors, Sessions and L.
    // It counts valid Core events; L remains the attributed unique-visitor count.
    $totalCoreStmt = $db->prepare("SELECT COUNT(*) c FROM contact_events
        WHERE occurred_at >= ? AND status IN ('success','intent')");
    $totalCoreStmt->execute([$since]);
    $totalCore = (int)$totalCoreStmt->fetch()['c'];

    $durStmt = $db->prepare("SELECT AVG(CASE WHEN active_duration_seconds > 0 THEN active_duration_seconds ELSE duration_seconds END) a FROM pageviews
        WHERE visited_at >= ? AND duration_seconds > 0");
    $durStmt->execute([$since]);
    $durRow = $durStmt->fetch();
    $avgDuration = $durRow && $durRow['a'] !== null ? round($durRow['a']) : 0;
    $conversionRate = $totalSessions > 0 ? round(($totalLeads / $totalSessions) * 100, 1) : 0;

    // Journey-attributed unique Lead trend (30 days)
    $trend = [];
    for ($i = 29; $i >= 0; $i--) { $trend[date('Y-m-d', strtotime("-{$i} days"))] = 0; }
    $trendStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,10) d,
            COUNT(DISTINCT vjt_visitor_id) c FROM contact_events
        WHERE occurred_at >= ? AND status IN ('success','intent') AND vjt_visitor_id <> ''
        GROUP BY d");
    $trendStmt->execute([vjt_admin_date_to_utc(date('Y-m-d', strtotime('-29 days')))]);
    $rows = $trendStmt->fetchAll();
    foreach ($rows as $r) { if (isset($trend[$r['d']])) $trend[$r['d']] = (int)$r['c']; }

    $coreTrend = array_fill_keys(array_keys($trend), 0);
    $coreTrendStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,10) d,
            COUNT(*) c FROM contact_events
        WHERE occurred_at >= ? AND status IN ('success','intent')
        GROUP BY d");
    $coreTrendStmt->execute([vjt_admin_date_to_utc(date('Y-m-d', strtotime('-29 days')))]);
    foreach ($coreTrendStmt->fetchAll() as $r) {
        if (isset($coreTrend[$r['d']])) $coreTrend[$r['d']] = (int)$r['c'];
    }

    // Journey-attributed unique Lead trend (12 months)
    $trendMonthly = [];
    for ($i = 11; $i >= 0; $i--) { $trendMonthly[date('Y-m', strtotime("-{$i} months"))] = 0; }
    $monthlyStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,7) m,
            COUNT(DISTINCT vjt_visitor_id) c FROM contact_events
        WHERE status IN ('success','intent') AND vjt_visitor_id <> '' GROUP BY m");
    $monthlyStmt->execute();
    $rows = $monthlyStmt->fetchAll();
    foreach ($rows as $r) { if (isset($trendMonthly[$r['m']])) $trendMonthly[$r['m']] = (int)$r['c']; }

    $coreTrendMonthly = array_fill_keys(array_keys($trendMonthly), 0);
    $coreMonthlyStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,7) m,
            COUNT(*) c FROM contact_events WHERE status IN ('success','intent') GROUP BY m");
    $coreMonthlyStmt->execute();
    foreach ($coreMonthlyStmt->fetchAll() as $r) {
        if (isset($coreTrendMonthly[$r['m']])) $coreTrendMonthly[$r['m']] = (int)$r['c'];
    }

    // Journey-attributed unique Lead trend (years)
    $trendYearly = [];
    $minYear = (int)date('Y');
    $yearlyStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,4) y,
            COUNT(DISTINCT vjt_visitor_id) c FROM contact_events
        WHERE status IN ('success','intent') AND vjt_visitor_id <> '' GROUP BY y");
    $yearlyStmt->execute();
    $rows = $yearlyStmt->fetchAll();
    $yearCounts = [];
    foreach ($rows as $r) {
        $y = (int)$r['y'];
        if ($y > 0) { $yearCounts[(string)$r['y']] = (int)$r['c']; if ($y < $minYear) $minYear = $y; }
    }

    $coreYearlyStmt = $db->prepare("SELECT substr(datetime(occurred_at, '+8 hours'),1,4) y,
            COUNT(*) c FROM contact_events WHERE status IN ('success','intent') GROUP BY y");
    $coreYearlyStmt->execute();
    $coreYearCounts = [];
    foreach ($coreYearlyStmt->fetchAll() as $r) {
        $y = (int)$r['y'];
        if ($y > 0) { $coreYearCounts[(string)$r['y']] = (int)$r['c']; if ($y < $minYear) $minYear = $y; }
    }

    $coreTrendYearly = [];
    for ($y = $minYear; $y <= (int)date('Y'); $y++) {
        $trendYearly[(string)$y] = $yearCounts[(string)$y] ?? 0;
        $coreTrendYearly[(string)$y] = $coreYearCounts[(string)$y] ?? 0;
    }

    // Top sources: explicit UTM attribution wins; otherwise use a normalized
    // referrer host. Keep sessions, visitors, and leads as separate measures.
    $refStats = [];
    $refStmt = $db->prepare("SELECT referrer, utm_source, utm_medium, visitor_id FROM sessions WHERE started_at >= ?");
    $refStmt->execute([$since]);
    $refVisitors = [];
    while ($r = $refStmt->fetch()) {
        $label = vjt_source_label($r);
        if (!isset($refStats[$label])) $refStats[$label] = ['sessions' => 0, 'visitors' => 0, 'leads' => 0];
        $refStats[$label]['sessions']++;
        $refVisitors[$label][$r['visitor_id']] = true;
    }
    foreach ($refVisitors as $label => $set) $refStats[$label]['visitors'] = count($set);

    $refLeadStmt = $db->prepare("SELECT c.vjt_visitor_id AS visitor_id, sess.referrer, sess.utm_source, sess.utm_medium
        FROM contact_events c JOIN sessions sess ON sess.session_id = c.vjt_session_id
        WHERE c.occurred_at >= ? AND c.status IN ('success','intent') AND c.vjt_visitor_id <> ''");
    $refLeadStmt->execute([$since]);
    $refLeads = [];
    while ($r = $refLeadStmt->fetch()) {
        $label = vjt_source_label($r);
        $refLeads[$label][$r['visitor_id']] = true;
    }
    foreach ($refLeads as $label => $set) {
        if (!isset($refStats[$label])) $refStats[$label] = ['sessions' => 0, 'visitors' => 0, 'leads' => 0];
        $refStats[$label]['leads'] = count($set);
    }
    uasort($refStats, function ($a, $b) { return $b['sessions'] <=> $a['sessions']; });
    $topReferrerStats = array_slice($refStats, 0, 8, true);

    // Device breakdown
    $deviceCounts = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'Unknown' => 0];
    $devStmt = $db->prepare("SELECT device_type, COUNT(*) c FROM visitors
        WHERE last_seen_at >= ? GROUP BY device_type");
    $devStmt->execute([$since]);
    $rows = $devStmt->fetchAll();
    foreach ($rows as $r) {
        $d = $r['device_type'] ?: 'Unknown';
        $deviceCounts[$d] = ($deviceCounts[$d] ?? 0) + (int)$r['c'];
    }

    // Source breakdown. The same session can contribute to Sessions only once,
    // while visitor and lead sets prevent those measures from being inflated.
    $sourceCounts = ['direct' => 0, 'internal' => 0, 'search' => 0, 'social' => 0, 'ads' => 0, 'ai' => 0, 'other' => 0];
    $sourceVisitorSets = [];
    $utmStmt = $db->prepare("SELECT referrer, utm_source, utm_medium, visitor_id FROM sessions WHERE started_at >= ?");
    $utmStmt->execute([$since]);
    while ($s = $utmStmt->fetch()) {
        $src = vjt_classify_source($s);
        $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
        $sourceVisitorSets[$src][$s['visitor_id']] = true;
    }
    $sourceVisitorCounts = [];
    foreach ($sourceVisitorSets as $src => $set) $sourceVisitorCounts[$src] = count($set);

    $sourceLeadSets = [];
    $sourceLeadStmt = $db->prepare("SELECT c.vjt_visitor_id AS visitor_id, sess.referrer, sess.utm_source, sess.utm_medium
        FROM contact_events c JOIN sessions sess ON sess.session_id = c.vjt_session_id
        WHERE c.occurred_at >= ? AND c.status IN ('success','intent') AND c.vjt_visitor_id <> ''");
    $sourceLeadStmt->execute([$since]);
    while ($s = $sourceLeadStmt->fetch()) {
        $src = vjt_classify_source($s);
        $sourceLeadSets[$src][$s['visitor_id']] = true;
    }
    $sourceLeadCounts = [];
    foreach ($sourceLeadSets as $src => $set) $sourceLeadCounts[$src] = count($set);

    return [
        'totalVisitors'      => $totalVisitors,
        'totalSessions'      => $totalSessions,
        'totalLeads'         => $totalLeads,
        'successLeads'       => $successLeads,
        // Compatibility aliases for any external dashboard consumers that still
        // read the pre-Contact-Core response names.
        'totalSubmissions'   => $totalLeads,
        'successSubmissions' => $successLeads,
        'totalCore'          => $totalCore,
        'avgDuration'        => $avgDuration,
        'conversionRate'     => $conversionRate,
        'trend'              => $trend,
        'coreTrend'          => $coreTrend,
        'trendMonthly'       => $trendMonthly,
        'coreTrendMonthly'   => $coreTrendMonthly,
        'trendYearly'        => $trendYearly,
        'coreTrendYearly'    => $coreTrendYearly,
        'topReferrers'       => array_column($topReferrerStats, 'sessions'),
        'topReferrerStats'   => $topReferrerStats,
        'deviceCounts'       => $deviceCounts,
        'sourceCounts'       => $sourceCounts,
        'sourceVisitorCounts'=> $sourceVisitorCounts,
        'sourceLeadCounts'   => $sourceLeadCounts,
    ];
}

function vjt_get_ai_referrals($since) {
    $db = vjt_db();
    $stmt = $db->prepare("SELECT session_id, visitor_id, source_slug, source_host, landing_url
        FROM sessions WHERE started_at >= ? AND source_type = 'ai'");
    $stmt->execute([$since]);
    $rows = [];
    $leadStmt = $db->prepare("SELECT 1 FROM contact_events
        WHERE vjt_session_id = ? AND status IN ('success','intent') LIMIT 1");
    foreach ($stmt->fetchAll() as $session) {
        $platform = $session['source_slug'] ?: 'other-ai';
        if (!isset($rows[$platform])) $rows[$platform] = ['platform'=>$platform, 'sessions'=>0, 'visitors'=>[], 'leads'=>[], 'pages'=>[]];
        $rows[$platform]['sessions']++;
        $rows[$platform]['visitors'][$session['visitor_id']] = true;
        if ($session['landing_url']) $rows[$platform]['pages'][$session['landing_url']] = true;
        $leadStmt->execute([$session['session_id']]);
        if ($leadStmt->fetch()) $rows[$platform]['leads'][$session['visitor_id']] = true;
    }
    $out = [];
    foreach ($rows as $row) {
        $visitors = count($row['visitors']);
        $leads = count($row['leads']);
        $out[] = ['platform'=>$row['platform'], 'sessions'=>$row['sessions'], 'visitors'=>$visitors, 'leads'=>$leads, 'pages'=>count($row['pages']), 'conversion_rate'=>$visitors ? round($leads / $visitors * 100, 1) : 0];
    }
    usort($out, function($a, $b) { return $b['visitors'] <=> $a['visitors']; });
    return $out;
}

// ── Dashboard: Canonical Leads List ──────────────────────────────────────────

function vjt_get_leads_list($filters) {
    $db = vjt_db();
    $where = [];
    $params = [];
    if (($filters['status'] ?? '') === 'contact') {
        $where[] = "status IN ('success','intent')";
    } elseif (in_array($filters['status'] ?? '', ['success', 'intent', 'error'], true)) {
        $where[] = "status = ?";
        $params[] = $filters['status'];
    }
    $channel = strtolower(trim((string)($filters['channel'] ?? ($filters['plugin'] ?? ''))));
    if ($channel === 'kssmi-inquiry') $channel = 'inquiry';
    if (in_array($channel, ['whatsapp', 'mailto', 'inquiry'], true)) {
        $where[] = "channel = ?";
        $params[] = $channel;
    }
    if (!empty($filters['date_from'])) {
        $utcFrom = vjt_admin_date_to_utc($filters['date_from']);
        if ($utcFrom) { $where[] = "occurred_at >= ?"; $params[] = $utcFrom; }
    }
    if (!empty($filters['date_to'])) {
        $utcTo = vjt_admin_date_to_utc($filters['date_to'], true);
        if ($utcTo) { $where[] = "occurred_at <= ?"; $params[] = $utcTo; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $groupExpr = "CASE WHEN vjt_visitor_id <> '' THEN 'visitor:' || vjt_visitor_id ELSE 'event:' || event_id END";

    $total = null;
    if (empty($filters['skip_count'])) {
        $cnt = $db->prepare("SELECT COUNT(*) c FROM (
            SELECT 1 FROM contact_events $whereSql GROUP BY $groupExpr
        ) grouped_leads");
        $cnt->execute($params);
        $total = (int)$cnt->fetch()['c'];
    }

    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 50));
    $offset = ($page - 1) * $perPage;

    // Linked Core events are grouped by Visitor ID. Without Analytics linkage
    // there is no lawful persistent visitor identity, so each event remains its
    // own Lead row, keyed by its stable Contact Core event ID.
    $sql = "SELECT latest.*, agg.lead_key, agg.first_contact_at, agg.last_contact_at,
                agg.event_count, agg.channels, agg.has_success, agg.has_intent
            FROM contact_events latest
            JOIN (
                SELECT $groupExpr lead_key, MIN(occurred_at) first_contact_at,
                    MAX(occurred_at) last_contact_at, COUNT(*) event_count,
                    GROUP_CONCAT(DISTINCT channel) channels,
                    MAX(CASE WHEN status='success' THEN 1 ELSE 0 END) has_success,
                    MAX(CASE WHEN status='intent' THEN 1 ELSE 0 END) has_intent,
                    MAX(id) latest_id
                FROM contact_events $whereSql
                GROUP BY $groupExpr
            ) agg ON agg.latest_id = latest.id
            ORDER BY agg.last_contact_at DESC, latest.id DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    // Bind positionally; LIMIT/OFFSET need explicit int types for SQLite
    $idx = 1;
    foreach ($params as $p) { $stmt->bindValue($idx++, $p); }
    $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $item['visitor_id'] = $item['vjt_visitor_id'] ?? '';
        $item['session_id'] = $item['vjt_session_id'] ?? '';
        $item['submitted_at'] = $item['last_contact_at'] ?? ($item['occurred_at'] ?? '');
        $item['first_submitted_at'] = $item['first_contact_at'] ?? '';
        $item['last_submitted_at'] = $item['last_contact_at'] ?? ($item['occurred_at'] ?? '');
        $item['form_plugin'] = $item['channel'] ?? '';
        $item['form_name'] = $item['channels'] ?? ($item['channel'] ?? '');
        $item['submit_page'] = $item['page_path'] ?? '';
        $item['status'] = !empty($item['has_success']) ? 'success'
            : (!empty($item['has_intent']) ? 'intent' : ($item['status'] ?? 'error'));
        $item['display_status'] = $item['status'];
    }
    unset($item);
    return ['items' => $items, 'total' => $total];
}

// Backward-compatible name for callers/bookmarks retained during the Contact
// Core migration. The returned rows now come exclusively from contact_events.
function vjt_get_submissions_list($filters) {
    return vjt_get_leads_list($filters);
}

// ── Dashboard: Visitors List ─────────────────────────────────────────────────

function vjt_get_visitors_list($filters) {
    $db = vjt_db();

    // Pre-aggregate per-visitor sessions, canonical Core Leads, session time, source
    $visitorSessions = [];
    $visitorSubmissions = [];
    $visitorSource = [];
    $visitorSourceRank = [];
    $visitorSessionTime = [];

    // Stream rows (fetch one at a time) instead of fetchAll() so we never hold
    // the whole sessions table in memory alongside the aggregate maps.
    $sessStmt = $db->prepare("SELECT visitor_id, referrer, utm_source, utm_medium, started_at, last_seen_at FROM sessions ORDER BY started_at ASC");
    $sessStmt->execute();
    while ($s = $sessStmt->fetch()) {
        $vid = $s['visitor_id'];
        $visitorSessions[$vid] = ($visitorSessions[$vid] ?? 0) + 1;
        $candidateSource = vjt_classify_source($s);
        $candidateRank = !in_array($candidateSource, ['direct', 'internal'], true) ? 2 : ($candidateSource === 'direct' ? 1 : 0);
        // Match WP-VJT 8.0.2's useful attribution rule: prefer the earliest
        // identifiable non-direct source instead of permanently locking a
        // visitor to an initial Direct/Internal session.
        if (!isset($visitorSource[$vid]) || $candidateRank > $visitorSourceRank[$vid]) {
            $visitorSource[$vid] = $candidateSource;
            $visitorSourceRank[$vid] = $candidateRank;
        }
        $st = strtotime($s['started_at'] ?? '');
        $ls = strtotime($s['last_seen_at'] ?? '');
        if ($st && $ls && $ls > $st) {
            $visitorSessionTime[$vid] = ($visitorSessionTime[$vid] ?? 0) + ($ls - $st);
        }
    }
    $subStmt = $db->prepare("SELECT vjt_visitor_id AS visitor_id,
        MAX(CASE WHEN status IN ('success','intent') THEN 1 ELSE 0 END) c
        FROM contact_events WHERE vjt_visitor_id <> '' GROUP BY vjt_visitor_id");
    $subStmt->execute();
    while ($r = $subStmt->fetch()) { $visitorSubmissions[$r['visitor_id']] = (int)$r['c']; }

    $search         = trim($filters['search'] ?? '');
    $device         = $filters['device'] ?? '';
    $source         = $filters['source'] ?? '';
    $sessionsMin    = $filters['sessions_min'] ?? '';
    $sessionsMax    = $filters['sessions_max'] ?? '';
    $submissionsMin = $filters['submissions_min'] ?? '';
    $submissionsMax = $filters['submissions_max'] ?? '';
    $sessionTimeMin = $filters['session_time_min'] ?? '';
    $countryExact   = strtoupper(trim($filters['country'] ?? '')); // exact alpha-2 match (Countries tab drill-down)
    $productSku     = strtoupper(trim($filters['product_sku'] ?? '')); // visitors who viewed this product (Products tab drill-down)
    $dateFrom       = $filters['date_from'] ?? '';
    $dateTo         = $filters['date_to'] ?? '';

    // Build the set of visitors who viewed the given product SKU (same regex as vjt_get_products).
    // Only runs when the product drill-down is active, so the common path pays nothing.
    $productVisitorSet = null;
    if ($productSku !== '') {
        $productVisitorSet = [];
        $pvStmt = $db->prepare("SELECT DISTINCT visitor_id, url FROM pageviews WHERE url <> '' AND url LIKE '%/product/%'");
        $pvStmt->execute();
        while ($pv = $pvStmt->fetch()) {
            if (preg_match('#/product/(k[\w-]+)/?#i', $pv['url'], $m) && strtoupper($m[1]) === $productSku) {
                $productVisitorSet[$pv['visitor_id']] = true;
            }
        }
    }

    // Narrow visitors at the DB level where we can
    $where = [];
    $params = [];
    if ($device)   { $where[] = "device_type = ?"; $params[] = $device; }
    if ($dateFrom) {
        $utcFrom = vjt_admin_date_to_utc($dateFrom);
        if ($utcFrom) { $where[] = "first_seen_at >= ?"; $params[] = $utcFrom; }
    }
    if ($dateTo) {
        $utcTo = vjt_admin_date_to_utc($dateTo, true);
        if ($utcTo) { $where[] = "first_seen_at <= ?"; $params[] = $utcTo; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $stmt = $db->prepare("SELECT * FROM visitors $whereSql");
    $stmt->execute($params);

    // Stream visitor rows so the full table is never materialised at once;
    // only the post-filter $filtered list is kept.
    $filtered = [];
    while ($v = $stmt->fetch()) {
        $vid = $v['visitor_id'];
        if ($countryExact !== '' && strtoupper($v['country'] ?? '') !== $countryExact) continue;
        if ($productVisitorSet !== null && !isset($productVisitorSet[$vid])) continue;
        if ($search) {
            $matchIp = stripos($v['first_ip'] ?? '', $search) !== false;
            $matchBrowser = stripos($v['browser'] ?? '', $search) !== false;
            $matchVid = stripos($vid, $search) !== false;
            $cc = strtoupper($v['country'] ?? '');
            $matchCountry =
                   stripos(vjt_country_name($cc), $search) !== false      // full name: United States
                || stripos(vjt_country_alpha3($cc), $search) !== false     // alpha-3: USA / SGP
                || stripos($cc, $search) !== false;                       // alpha-2: US / SG
            if (!$matchIp && !$matchBrowser && !$matchVid && !$matchCountry) continue;
        }
        $vSource = $visitorSource[$vid] ?? 'direct';
        if ($source && $vSource !== $source) continue;
        $vSessions = $visitorSessions[$vid] ?? 0;
        if ($sessionsMin !== '' && $vSessions < (int)$sessionsMin) continue;
        if ($sessionsMax !== '' && $vSessions > (int)$sessionsMax) continue;
        $vSubmissions = $visitorSubmissions[$vid] ?? 0;
        if ($submissionsMin !== '' && $vSubmissions < (int)$submissionsMin) continue;
        if ($submissionsMax !== '' && $vSubmissions > (int)$submissionsMax) continue;
        $vSessionTime = $visitorSessionTime[$vid] ?? 0;
        if ($sessionTimeMin !== '' && $vSessionTime < (int)$sessionTimeMin) continue;
        $filtered[] = [
            'visitor_id'    => $vid,
            'first_ip'      => $v['first_ip'] ?? '',
            'country'       => $v['country'] ?? '',
            'city'          => $v['city'] ?? '',
            'browser'       => $v['browser'] ?? 'Unknown',
            'device_type'   => $v['device_type'] ?? 'Unknown',
            'screen_resolution' => $v['screen_resolution'] ?? '',
            'timezone'      => $v['timezone'] ?? '',
            'language'      => $v['language'] ?? '',
            'first_seen_at' => $v['first_seen_at'] ?? '',
            'last_seen_at'  => $v['last_seen_at'] ?? '',
            'user_agent'    => $v['user_agent'] ?? '',
            'sessions'      => $vSessions,
            'submissions'   => $vSubmissions,
            'session_time'  => $vSessionTime,
            'source'        => $vSource,
        ];
    }

    // Sort
    $allowedSorts = ['visitor_id', 'first_seen_at', 'last_seen_at', 'country', 'device_type', 'browser', 'sessions', 'submissions', 'session_time', 'source'];
    $sortBy    = in_array($filters['sort_by'] ?? '', $allowedSorts) ? $filters['sort_by'] : 'last_seen_at';
    $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $numericCols = ['sessions', 'submissions', 'session_time'];
    usort($filtered, function($a, $b) use ($sortBy, $sortOrder, $numericCols) {
        $va = $a[$sortBy] ?? '';
        $vb = $b[$sortBy] ?? '';
        if ($sortBy === 'country') { $va = vjt_country_name($va); $vb = vjt_country_name($vb); }
        if (in_array($sortBy, $numericCols)) $cmp = (int)$va <=> (int)$vb;
        else $cmp = strcasecmp((string)$va, (string)$vb);
        return $sortOrder === 'asc' ? $cmp : -$cmp;
    });

    $total = count($filtered);
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 50));
    $offset = ($page - 1) * $perPage;

    return ['items' => array_slice($filtered, $offset, $perPage), 'total' => $total];
}

// ── Dashboard: Journey Detail ────────────────────────────────────────────────

function vjt_get_journey($visitorId) {
    $db = vjt_db();
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    $visitor = $stmt->fetch();
    if (!$visitor) return null;

    $s = $db->prepare("SELECT * FROM sessions WHERE visitor_id = ? ORDER BY started_at ASC");
    $s->execute([$visitorId]);
    $sessions = $s->fetchAll();

    // GSC data is delayed and aggregate, so query only the most recent eligible
    // historical Google Organic session. This caps an uncached journey request
    // at one external API call and never presents the terms as visitor-level data.
    if (vjt_gsc_config()) {
        for ($i = count($sessions) - 1; $i >= 0; $i--) {
            if (!vjt_is_google_organic_session($sessions[$i])) continue;
            $startedTs = strtotime((string)($sessions[$i]['started_at'] ?? ''));
            if ($startedTs === false || gmdate('Y-m-d', $startedTs) > gmdate('Y-m-d', time() - 2 * 86400)) continue;
            $sessions[$i]['gsc_keywords'] = vjt_gsc_keywords_for_landing(
                $sessions[$i]['landing_url'] ?? '',
                $sessions[$i]['started_at'] ?? '',
                5
            );
            break;
        }
    }

    $p = $db->prepare("SELECT * FROM pageviews WHERE visitor_id = ? ORDER BY visited_at ASC, id ASC");
    $p->execute([$visitorId]);
    $pageviews = $p->fetchAll();

    $sub = $db->prepare("SELECT * FROM submissions WHERE visitor_id = ? ORDER BY submitted_at ASC");
    $sub->execute([$visitorId]);
    $submissions = $sub->fetchAll();

    $contact = $db->prepare("SELECT * FROM contact_events WHERE vjt_visitor_id = ? ORDER BY occurred_at ASC, id ASC");
    $contact->execute([$visitorId]);
    $contactEvents = $contact->fetchAll();

    return [
        'visitor'     => $visitor,
        'sessions'    => $sessions,
        'pageviews'   => $pageviews,
        'submissions' => $submissions,
        'contact_events' => $contactEvents,
    ];
}

// ── Dashboard: Data Cleanup ──────────────────────────────────────────────────

function vjt_gsc_base64url($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

function vjt_gsc_runtime_value($name) {
    $values = [getenv($name)];
    if (function_exists('apache_getenv')) $values[] = @apache_getenv($name);
    $values[] = $_SERVER[$name] ?? '';
    $values[] = $_ENV[$name] ?? '';
    $values[] = $_SERVER['REDIRECT_' . $name] ?? '';
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return '';
}

function vjt_gsc_environment() {
    $credentials = vjt_gsc_runtime_value('VJT_GSC_SERVICE_ACCOUNT_JSON');
    $siteUrl = vjt_gsc_runtime_value('VJT_GSC_SITE_URL');
    $credentialsSource = $credentials !== '' ? 'server environment' : 'application fallback';
    $siteSource = $siteUrl !== '' ? 'server environment' : 'application fallback';

    // OpenLiteSpeed can apply SetEnv for request routing without exposing it to
    // PHP's getenv()/$_SERVER. These site-specific values contain no secret; the
    // JSON contents remain outside public_html and protected by Unix permissions.
    if ($credentials === '') $credentials = '/home/kssmi.com/private/gsc/google-service-account.json';
    if ($siteUrl === '') $siteUrl = 'sc-domain:kssmi.com';

    return [
        'credentials' => $credentials,
        'site_url' => $siteUrl,
        'credentials_source' => $credentialsSource,
        'site_source' => $siteSource,
    ];
}

function vjt_gsc_config() {
    $env = vjt_gsc_environment();
    return ($env['credentials'] !== '' && $env['site_url'] !== '' && is_file($env['credentials']) && is_readable($env['credentials'])) ? $env : null;
}

function vjt_gsc_error_message($data, $fallback) {
    if (is_array($data)) {
        if (!empty($data['error']['message'])) return vjt_clip((string)$data['error']['message'], 300);
        if (!empty($data['error_description'])) return vjt_clip((string)$data['error_description'], 300);
        if (!empty($data['error']) && is_string($data['error'])) return vjt_clip((string)$data['error'], 300);
    }
    return $fallback;
}

function vjt_gsc_http_json($url, $method = 'GET', $headers = [], $body = '', $timeout = 8) {
    $headers = array_values($headers);
    $status = 0;
    $response = false;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) $transportError = (string)curl_error($ch);
        curl_close($ch);
    } elseif ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        if ($response === false) $transportError = 'HTTP request failed.';
    } else {
        $transportError = 'Neither cURL nor allow_url_fopen is available.';
    }

    $data = is_string($response) ? json_decode($response, true) : null;
    $ok = $status >= 200 && $status < 300 && is_array($data);
    $fallback = $transportError !== '' ? $transportError : ($status ? 'Google API returned HTTP ' . $status . '.' : 'Google API request failed.');
    if ($response !== false && $response !== '' && !is_array($data) && $status >= 200 && $status < 300) {
        $fallback = 'Google API returned invalid JSON.';
    }
    return ['ok'=>$ok, 'status'=>$status, 'data'=>$data, 'error'=>$ok ? '' : vjt_gsc_error_message($data, $fallback)];
}

function vjt_gsc_access_token_result($config, $forceRefresh = false) {
    $cached = json_decode((string)vjt_meta_get('gsc_access_token', ''), true);
    if (!$forceRefresh && is_array($cached) && !empty($cached['token']) && (int)($cached['expires_at'] ?? 0) > time() + 60) {
        return ['ok'=>true, 'token'=>$cached['token'], 'error'=>'', 'status'=>200];
    }
    if (!function_exists('openssl_sign')) return ['ok'=>false, 'token'=>'', 'error'=>'PHP OpenSSL is unavailable.', 'status'=>0];
    if (empty($config['credentials']) || !is_file($config['credentials'])) return ['ok'=>false, 'token'=>'', 'error'=>'Service-account JSON file was not found.', 'status'=>0];
    if (!is_readable($config['credentials'])) return ['ok'=>false, 'token'=>'', 'error'=>'Service-account JSON file is not readable by PHP.', 'status'=>0];
    $credentials = json_decode((string)@file_get_contents($config['credentials']), true);
    if (!is_array($credentials)) return ['ok'=>false, 'token'=>'', 'error'=>'Service-account JSON is invalid.', 'status'=>0];
    if (empty($credentials['client_email']) || empty($credentials['private_key'])) return ['ok'=>false, 'token'=>'', 'error'=>'Service-account JSON is missing client_email or private_key.', 'status'=>0];
    $now = time();
    $header = vjt_gsc_base64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $claims = vjt_gsc_base64url(json_encode(['iss'=>$credentials['client_email'], 'scope'=>'https://www.googleapis.com/auth/webmasters.readonly', 'aud'=>'https://oauth2.googleapis.com/token', 'iat'=>$now, 'exp'=>$now + 3600]));
    $input = $header . '.' . $claims;
    if (!@openssl_sign($input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) return ['ok'=>false, 'token'=>'', 'error'=>'Could not sign the Google OAuth request. Check the private key.', 'status'=>0];
    $body = http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion'=>$input . '.' . vjt_gsc_base64url($signature)]);
    $response = vjt_gsc_http_json('https://oauth2.googleapis.com/token', 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Content-Length: ' . strlen($body)], $body, 8);
    $data = $response['data'];
    if (!$response['ok'] || empty($data['access_token'])) return ['ok'=>false, 'token'=>'', 'error'=>$response['error'] ?: 'Google OAuth did not return an access token.', 'status'=>$response['status']];
    vjt_meta_set('gsc_access_token', json_encode(['token'=>$data['access_token'], 'expires_at'=>$now + max(60, (int)($data['expires_in'] ?? 3600) - 60)]));
    return ['ok'=>true, 'token'=>$data['access_token'], 'error'=>'', 'status'=>$response['status']];
}

function vjt_gsc_access_token($config) {
    $result = vjt_gsc_access_token_result($config);
    return $result['ok'] ? $result['token'] : '';
}

function vjt_gsc_search_analytics($payload, $forceTokenRefresh = false) {
    $config = vjt_gsc_config();
    if (!$config) return ['ok'=>false, 'rows'=>[], 'error'=>'GSC environment variables or readable credentials are missing.', 'status'=>0];
    $token = vjt_gsc_access_token_result($config, $forceTokenRefresh);
    if (!$token['ok']) return ['ok'=>false, 'rows'=>[], 'error'=>$token['error'], 'status'=>$token['status']];
    $body = json_encode($payload);
    if ($body === false) return ['ok'=>false, 'rows'=>[], 'error'=>'Could not encode the GSC request.', 'status'=>0];
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($config['site_url']) . '/searchAnalytics/query';
    $response = vjt_gsc_http_json($url, 'POST', ['Content-Type: application/json', 'Authorization: Bearer ' . $token['token'], 'Content-Length: ' . strlen($body)], $body, 10);
    return ['ok'=>$response['ok'], 'rows'=>$response['ok'] ? ($response['data']['rows'] ?? []) : [], 'error'=>$response['error'], 'status'=>$response['status']];
}

function vjt_gsc_diagnostics($testConnection = false) {
    $env = vjt_gsc_environment();
    $raw = ($env['credentials'] !== '' && is_readable($env['credentials'])) ? @file_get_contents($env['credentials']) : false;
    $credentials = is_string($raw) ? json_decode($raw, true) : null;
    $diagnostics = [
        'path_configured' => $env['credentials'] !== '',
        'site_configured' => $env['site_url'] !== '',
        'credentials_path' => $env['credentials'],
        'site_url' => $env['site_url'],
        'credentials_source' => $env['credentials_source'],
        'site_source' => $env['site_source'],
        'file_exists' => $env['credentials'] !== '' && is_file($env['credentials']),
        'file_readable' => $raw !== false,
        'json_valid' => is_array($credentials) && !empty($credentials['client_email']) && !empty($credentials['private_key']),
        'service_account' => is_array($credentials) ? (string)($credentials['client_email'] ?? '') : '',
        'openssl' => function_exists('openssl_sign'),
        'http_transport' => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'last_test' => json_decode((string)vjt_meta_get('gsc_last_test', ''), true),
    ];
    $diagnostics['ready'] = $diagnostics['path_configured'] && $diagnostics['site_configured'] && $diagnostics['file_exists'] && $diagnostics['file_readable'] && $diagnostics['json_valid'] && $diagnostics['openssl'] && $diagnostics['http_transport'];

    if ($testConnection) {
        if (!$diagnostics['ready']) {
            $result = ['ok'=>false, 'tested_at'=>gmdate('Y-m-d H:i:s') . ' UTC', 'message'=>'Local GSC configuration is incomplete.'];
        } else {
            $end = gmdate('Y-m-d', time() - 2 * 86400);
            $start = gmdate('Y-m-d', strtotime($end . ' -6 days'));
            $probe = vjt_gsc_search_analytics(['startDate'=>$start, 'endDate'=>$end, 'dimensions'=>['query'], 'rowLimit'=>1], true);
            $result = [
                'ok' => $probe['ok'],
                'tested_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                'message' => $probe['ok'] ? 'Google OAuth and Search Console property access succeeded.' : $probe['error'],
                'http_status' => $probe['status'],
            ];
        }
        vjt_meta_set('gsc_last_test', json_encode($result));
        $diagnostics['last_test'] = $result;
    }
    return $diagnostics;
}

function vjt_gsc_query_period($days = 28) {
    $days = in_array((int)$days, [7, 28, 90], true) ? (int)$days : 28;
    $end = gmdate('Y-m-d', time() - 2 * 86400);
    $start = gmdate('Y-m-d', strtotime($end . ' -' . ($days - 1) . ' days'));
    return ['days'=>$days, 'start_date'=>$start, 'end_date'=>$end];
}

function vjt_gsc_normalize_query_row($row) {
    if (empty($row['keys'][0])) return null;
    return [
        'query' => vjt_clip((string)$row['keys'][0], 240),
        'clicks' => (float)($row['clicks'] ?? 0),
        'impressions' => (float)($row['impressions'] ?? 0),
        'ctr' => (float)($row['ctr'] ?? 0),
        'position' => (float)($row['position'] ?? 0),
    ];
}

function vjt_gsc_query_page($days = 28, $page = 1, $perPage = 100) {
    $period = vjt_gsc_query_period($days);
    $days = $period['days'];
    $start = $period['start_date'];
    $end = $period['end_date'];
    $page = min(1000, max(1, (int)$page));
    $perPage = min(250, max(1, (int)$perPage));
    $startRow = ($page - 1) * $perPage;
    $cacheKey = 'gsc_query_page_' . $days . '_' . $page . '_' . $perPage;
    $cached = json_decode((string)vjt_meta_get($cacheKey, ''), true);
    if (is_array($cached) && !empty($cached['ok']) && (int)($cached['cached_at'] ?? 0) > time() - 3600) {
        $cached['cached'] = true;
        return $cached;
    }

    // Fetch one look-ahead row so pagination can expose Next without loading
    // every Search Console row just to calculate a total page count.
    $response = vjt_gsc_search_analytics([
        'startDate' => $start,
        'endDate' => $end,
        'dimensions' => ['query'],
        'rowLimit' => $perPage + 1,
        'startRow' => $startRow,
    ]);
    $report = [
        'ok'=>$response['ok'], 'error'=>$response['error'], 'status'=>$response['status'],
        'start_date'=>$start, 'end_date'=>$end, 'days'=>$days,
        'page'=>$page, 'per_page'=>$perPage, 'row_offset'=>$startRow,
        'rows'=>[], 'has_next'=>false, 'cached_at'=>time(), 'cached'=>false,
    ];
    if ($response['ok']) {
        $rawRows = $response['rows'] ?? [];
        $report['has_next'] = count($rawRows) > $perPage;
        foreach (array_slice($rawRows, 0, $perPage) as $row) {
            $normalized = vjt_gsc_normalize_query_row($row);
            if ($normalized !== null) $report['rows'][] = $normalized;
        }
        vjt_meta_set($cacheKey, json_encode($report));
    }
    return $report;
}

function vjt_gsc_keywords_for_landing($landingUrl, $startedAt, $limit = 5) {
    $config = vjt_gsc_config();
    if (!$config || !$landingUrl) return [];
    $startedTs = strtotime((string)$startedAt);
    if ($startedTs === false || $startedTs <= 0) return [];
    $date = gmdate('Y-m-d', $startedTs);
    // GSC typically has delayed data; this remains aggregate page/day data.
    if ($date > gmdate('Y-m-d', time() - 2 * 86400)) return [];

    // GSC's page dimension does not normally include campaign query strings.
    $landingUrl = vjt_safe_http_url($landingUrl);
    if ($landingUrl === '') return [];
    $parts = parse_url($landingUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) return [];
    $landingUrl = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . ($parts['path'] ?? '/');

    $key = 'gsc_keywords_' . sha1($date . '|' . $landingUrl);
    $cached = json_decode((string)vjt_meta_get($key, ''), true);
    if (is_array($cached) && isset($cached['keywords'])) {
        $ttl = !empty($cached['keywords']) ? 7 * 86400 : 12 * 3600;
        if ((int)($cached['cached_at'] ?? 0) > time() - $ttl) return $cached['keywords'];
    }
    $response = vjt_gsc_search_analytics(['startDate'=>$date, 'endDate'=>$date, 'dimensions'=>['query'], 'rowLimit'=>max(1, min(10, (int)$limit)), 'dimensionFilterGroups'=>[['filters'=>[['dimension'=>'page','operator'=>'equals','expression'=>$landingUrl]]]]]);
    if (!$response['ok']) return [];
    $keywords = [];
    foreach ($response['rows'] as $row) if (!empty($row['keys'][0])) $keywords[] = vjt_clip((string)$row['keys'][0], 160);
    vjt_meta_set($key, json_encode(['keywords'=>$keywords, 'cached_at'=>time()]));
    return $keywords;
}

function vjt_build_email_summary($visitorId, $sessionId) {
    $settings = vjt_get_settings();
    if (($settings['enable_email_summary'] ?? '1') !== '1') return '';
    $db = vjt_db();
    $sessionStmt = $db->prepare('SELECT * FROM sessions WHERE session_id = ? AND visitor_id = ? LIMIT 1');
    $sessionStmt->execute([$sessionId, $visitorId]);
    $session = $sessionStmt->fetch();
    if (!$session) return '';

    $pageStmt = $db->prepare('SELECT url, title, duration_seconds, active_duration_seconds, max_scroll_depth, scroll_depth FROM pageviews WHERE session_id = ? ORDER BY step_order ASC, id ASC LIMIT 20');
    $pageStmt->execute([$sessionId]);
    $pages = $pageStmt->fetchAll();
    $active = 0;
    $maxScroll = 0;
    foreach ($pages as $page) {
        $active += (int)($page['active_duration_seconds'] ?: $page['duration_seconds']);
        $maxScroll = max($maxScroll, (int)($page['max_scroll_depth'] ?: $page['scroll_depth']));
    }
    $lines = ['VJT ATTRIBUTION', 'Attributed source: ' . vjt_source_label($session), 'Landing page: ' . ($session['landing_url'] ?: '-')];
    if (!empty($session['utm_source']) || !empty($session['utm_medium']) || !empty($session['utm_campaign'])) {
        $lines[] = 'Campaign: ' . trim(($session['utm_source'] ?: '-') . ' / ' . ($session['utm_medium'] ?: '-') . ' / ' . ($session['utm_campaign'] ?: '-'));
    }
    if (vjt_is_google_organic_session($session) && ($keywords = vjt_gsc_keywords_for_landing($session['landing_url'] ?? '', $session['started_at'] ?? '', 5))) {
        $lines[] = 'Related GSC queries (aggregate page/day, not this visitor): ' . implode(', ', $keywords);
    }
    $lines[] = 'Journey: ' . count($pages) . ' page(s), active time ' . $active . 's, max scroll ' . $maxScroll . '%';
    foreach (array_slice($pages, 0, 6) as $index => $page) $lines[] = ($index + 1) . '. ' . ($page['title'] ?: $page['url']);
    return implode("\n", $lines);
}

function vjt_cleanup_old_data($days) {
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    $db = vjt_db();
    $db->prepare("DELETE FROM visitors    WHERE last_seen_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM sessions    WHERE last_seen_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM pageviews   WHERE visited_at  < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM submissions WHERE submitted_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM contact_events WHERE occurred_at < ?")->execute([$cutoff]);
    $geoCutoff = gmdate('Y-m-d H:i:s', time() - (7 * 86400));
    $db->prepare("DELETE FROM geo_cache WHERE cached_at < ?")->execute([$geoCutoff]);
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}

function vjt_wipe_all_data() {
    $db = vjt_db();
    foreach (['visitors','sessions','pageviews','submissions','contact_events','geo_cache','geo_queue'] as $t) {
        $db->exec("DELETE FROM $t");
    }
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}

// ── Dashboard: Traffic Performance ──────────────────────────────────────────

function vjt_get_traffic_data($since) {
    $db = vjt_db();

    $totalPageviewsStmt = $db->prepare("SELECT COUNT(*) c FROM pageviews WHERE visited_at >= ?");
    $totalPageviewsStmt->execute([$since]);
    $totalPageviews = (int)$totalPageviewsStmt->fetch()['c'];

    // Daily trend (30 days)
    $dailyTrend = [];
    for ($i = 29; $i >= 0; $i--) { $dailyTrend[date('Y-m-d', strtotime("-{$i} days"))] = 0; }
    $dailyTrendStmt = $db->prepare("SELECT substr(datetime(visited_at, '+8 hours'),1,10) d, COUNT(*) c FROM pageviews
        WHERE visited_at >= ? GROUP BY d");
    $dailyTrendStmt->execute([vjt_admin_date_to_utc(date('Y-m-d', strtotime('-29 days')))]);
    $rows = $dailyTrendStmt->fetchAll();
    foreach ($rows as $r) { if (isset($dailyTrend[$r['d']])) $dailyTrend[$r['d']] = (int)$r['c']; }

    // Monthly trend (12 months)
    $monthlyTrend = [];
    for ($i = 11; $i >= 0; $i--) { $monthlyTrend[date('Y-m', strtotime("-{$i} months"))] = 0; }
    $monthlyTrendStmt = $db->prepare("SELECT substr(datetime(visited_at, '+8 hours'),1,7) m, COUNT(*) c FROM pageviews GROUP BY m");
    $monthlyTrendStmt->execute();
    $rows = $monthlyTrendStmt->fetchAll();
    foreach ($rows as $r) { if (isset($monthlyTrend[$r['m']])) $monthlyTrend[$r['m']] = (int)$r['c']; }

    // Yearly trend
    $yearlyTrend = [];
    $minYear = (int)date('Y');
    $yearlyTrendStmt = $db->prepare("SELECT substr(datetime(visited_at, '+8 hours'),1,4) y, COUNT(*) c FROM pageviews GROUP BY y");
    $yearlyTrendStmt->execute();
    $rows = $yearlyTrendStmt->fetchAll();
    $yearCounts = [];
    foreach ($rows as $r) {
        $y = (int)$r['y'];
        if ($y > 0) { $yearCounts[(string)$r['y']] = (int)$r['c']; if ($y < $minYear) $minYear = $y; }
    }
    for ($y = $minYear; $y <= (int)date('Y'); $y++) { $yearlyTrend[(string)$y] = $yearCounts[(string)$y] ?? 0; }

    // Top pages (views, active-time-first average, max scroll) within window
    $topPagesStmt = $db->prepare("SELECT url,
            COUNT(*) views,
            AVG(CASE WHEN active_duration_seconds > 0 THEN active_duration_seconds WHEN duration_seconds > 0 THEN duration_seconds END) avg_dur,
            MAX(CASE WHEN max_scroll_depth > 0 THEN max_scroll_depth ELSE scroll_depth END) max_scroll
        FROM pageviews WHERE visited_at >= ? AND url <> ''
        GROUP BY url ORDER BY views DESC LIMIT 20");
    $topPagesStmt->execute([$since]);
    $rows = $topPagesStmt->fetchAll();
    $topPages = [];
    foreach ($rows as $r) {
        $topPages[] = [
            'url'          => $r['url'],
            'views'        => (int)$r['views'],
            'avg_duration' => $r['avg_dur'] !== null ? round($r['avg_dur']) : 0,
            'avg_scroll'   => (int)($r['max_scroll'] ?? 0),
        ];
    }
    // Journey-attributed canonical Leads per page (only for pages we show).
    // Core stores a path rather than a full analytics URL, by design.
    if ($topPages) {
        $pathIndexes = [];
        foreach ($topPages as $index => &$tp) {
            $tp['submissions'] = 0;
            $path = vjt_contact_page_path($tp['url']);
            if ($path !== '') $pathIndexes[$path][] = $index;
        }
        unset($tp);
        if ($pathIndexes) {
            $paths = array_keys($pathIndexes);
            $place = implode(',', array_fill(0, count($paths), '?'));
            $stmt = $db->prepare("SELECT page_path, COUNT(DISTINCT vjt_visitor_id) c FROM contact_events
                WHERE occurred_at >= ? AND status IN ('success','intent') AND vjt_visitor_id <> ''
                  AND page_path IN ($place) GROUP BY page_path");
            $stmt->execute(array_merge([$since], $paths));
            foreach ($stmt->fetchAll() as $r) {
                foreach ($pathIndexes[$r['page_path']] ?? [] as $index) {
                    $topPages[$index]['submissions'] = (int)$r['c'];
                }
            }
        }
    }

    $uniqueUrlsStmt = $db->prepare("SELECT COUNT(DISTINCT url) c FROM pageviews WHERE visited_at >= ? AND url <> ''");
    $uniqueUrlsStmt->execute([$since]);
    $uniqueUrls = (int)$uniqueUrlsStmt->fetch()['c'];

    // Bounce rate: single-page sessions without an engagement signal or Lead.
    // Old rows fall back to duration/scroll because they predate is_engaged.
    $bounceStmt = $db->prepare("SELECT
            COUNT(*) total_sessions,
            SUM(CASE WHEN pv = 1 AND engaged = 0 AND has_lead = 0 THEN 1 ELSE 0 END) bounces
        FROM (
            SELECT p.session_id, COUNT(*) pv,
                MAX(CASE WHEN p.is_engaged = 1 OR p.active_duration_seconds >= 10
                    OR (p.active_duration_seconds = 0 AND p.duration_seconds >= 10)
                    OR p.max_scroll_depth >= 50 OR p.scroll_depth >= 50 THEN 1 ELSE 0 END) engaged,
                CASE WHEN EXISTS (
                    SELECT 1 FROM contact_events c WHERE c.vjt_session_id = p.session_id
                    AND c.status IN ('success','intent')
                ) THEN 1 ELSE 0 END has_lead
            FROM pageviews p WHERE p.visited_at >= ? GROUP BY p.session_id
        )");
    $bounceStmt->execute([$since]);
    $bounceRow = $bounceStmt->fetch();
    $totalSessions = (int)($bounceRow['total_sessions'] ?? 0);
    $bounceSessions = (int)($bounceRow['bounces'] ?? 0);
    $bounceRate = $totalSessions > 0 ? round(($bounceSessions / $totalSessions) * 100) : 0;

    $dwellStmt = $db->prepare("SELECT AVG(CASE WHEN active_duration_seconds > 0 THEN active_duration_seconds ELSE duration_seconds END) a FROM pageviews
        WHERE visited_at >= ? AND duration_seconds > 0");
    $dwellStmt->execute([$since]);
    $dwellRow = $dwellStmt->fetch();
    $avgDwellAll = $dwellRow && $dwellRow['a'] !== null ? round($dwellRow['a']) : 0;

    return [
        'totalPageviews' => $totalPageviews,
        'dailyTrend'     => $dailyTrend,
        'monthlyTrend'   => $monthlyTrend,
        'yearlyTrend'    => $yearlyTrend,
        'topPages'       => $topPages,
        'uniqueUrls'     => $uniqueUrls,
        'bounceRate'     => $bounceRate,
        'totalSessions'  => $totalSessions,
        'avgDwellAll'    => $avgDwellAll,
    ];
}

// ── Dashboard: Countries ────────────────────────────────────────────────────

function vjt_get_countries() {
    $db = vjt_db();
    $countries = [];

    $countryRowsStmt = $db->prepare("SELECT CASE WHEN country IS NULL OR country = '' THEN 'UNKNOWN' ELSE upper(country) END cc,
        COUNT(*) c FROM visitors GROUP BY cc");
    $countryRowsStmt->execute();
    $rows = $countryRowsStmt->fetchAll();
    foreach ($rows as $r) {
        $cc = $r['cc'];
        $countries[$cc] = ['code' => $cc, 'visitors' => (int)$r['c'], 'sessions' => 0, 'submissions' => 0];
    }

    // visitor → country map (stream rows to keep peak memory low)
    $visitorCountry = [];
    $vcStmt = $db->prepare("SELECT visitor_id, country FROM visitors");
    $vcStmt->execute();
    while ($v = $vcStmt->fetch()) {
        $visitorCountry[$v['visitor_id']] = !empty($v['country']) ? strtoupper($v['country']) : 'UNKNOWN';
    }
    $csStmt = $db->prepare("SELECT visitor_id FROM sessions");
    $csStmt->execute();
    while ($s = $csStmt->fetch()) {
        $cc = $visitorCountry[$s['visitor_id']] ?? 'UNKNOWN';
        if (isset($countries[$cc])) $countries[$cc]['sessions']++;
    }
    $cbStmt = $db->prepare("SELECT vjt_visitor_id AS visitor_id FROM contact_events
        WHERE status IN ('success','intent') AND vjt_visitor_id <> ''");
    $cbStmt->execute();
    $countryLeadVisitors = [];
    while ($sub = $cbStmt->fetch()) {
        $cc = $visitorCountry[$sub['visitor_id']] ?? 'UNKNOWN';
        $countryLeadVisitors[$cc][$sub['visitor_id']] = true;
    }
    foreach ($countryLeadVisitors as $cc => $set) {
        if (isset($countries[$cc])) $countries[$cc]['submissions'] = count($set);
    }

    uasort($countries, function ($a, $b) { return $b['visitors'] - $a['visitors']; });
    return array_values($countries);
}

// ── Dashboard: Products ─────────────────────────────────────────────────────

function vjt_get_products($dateFrom = '', $dateTo = '') {
    $db = vjt_db();
    $where = ["url <> ''"];
    $params = [];
    if ($dateFrom) {
        $utcFrom = vjt_admin_date_to_utc($dateFrom);
        if ($utcFrom) { $where[] = "visited_at >= ?"; $params[] = $utcFrom; }
    }
    if ($dateTo) {
        $utcTo = vjt_admin_date_to_utc($dateTo, true);
        if ($utcTo) { $where[] = "visited_at <= ?"; $params[] = $utcTo; }
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare("SELECT url, visitor_id FROM pageviews $whereSql");
    $stmt->execute($params);

    // Stream pageviews — this can be the largest table; never materialise it all.
    $products = [];
    while ($pv = $stmt->fetch()) {
        $url = $pv['url'] ?? '';
        if (!preg_match('#/product/(k[\w-]+)/?#i', $url, $m)) continue;
        $sku = strtoupper($m[1]);
        if (!isset($products[$sku])) {
            $products[$sku] = ['sku' => $sku, 'views' => 0, 'visitors' => 0, 'visitor_set' => [], 'url_counts' => []];
        }
        $products[$sku]['views']++;
        $vid = $pv['visitor_id'] ?? '';
        if ($vid && !isset($products[$sku]['visitor_set'][$vid])) {
            $products[$sku]['visitor_set'][$vid] = true;
            $products[$sku]['visitors']++;
        }
        $cleanUrl = preg_replace('/\?.*$/', '', $url);
        $products[$sku]['url_counts'][$cleanUrl] = ($products[$sku]['url_counts'][$cleanUrl] ?? 0) + 1;
    }

    uasort($products, function ($a, $b) { return $b['views'] - $a['views']; });

    return array_map(function ($p) {
        arsort($p['url_counts']);
        $p['url'] = count($p['url_counts']) > 0 ? array_key_first($p['url_counts']) : ('/product/' . strtolower($p['sku']) . '/');
        unset($p['visitor_set'], $p['url_counts']);
        return $p;
    }, array_values($products));
}

// ── Dashboard: CSV Export ────────────────────────────────────────────────────

// Prevent spreadsheet formula execution when an exported value begins with a
// formula marker. Values originate from public analytics endpoints and therefore
// must remain data when opened in Excel/LibreOffice.
function vjt_csv_safe_cell($value) {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value) || is_float($value)) return $value;
    $value = (string)$value;
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
}

function vjt_csv_safe_row($row) {
    return array_map('vjt_csv_safe_cell', $row);
}

function vjt_export_leads_csv_start($filters) {
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename=vjt-leads-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Last Contact (Beijing)', 'First Contact (Beijing)', 'Lead Key', 'Visitor ID', 'Attribution', 'Channels', 'Events', 'Page Path', 'Placement', 'Event Type', 'Status', 'Product', 'Language', 'Retention Class', 'Latest Event ID']);

    $page = 1;
    $batchSize = 1000;
    do {
        $result = vjt_get_leads_list(array_merge($filters, [
            'page' => $page,
            'per_page' => $batchSize,
            'skip_count' => true,
        ]));
        $items = $result['items'];
        foreach ($items as $lead) {
            fputcsv($output, vjt_csv_safe_row([
                vjt_format_for_admin($lead['last_contact_at'] ?? ($lead['occurred_at'] ?? '')),
                vjt_format_for_admin($lead['first_contact_at'] ?? ''),
                $lead['lead_key'] ?? '',
                $lead['vjt_visitor_id'] ?? '',
                !empty($lead['vjt_visitor_id']) ? 'Consented journey linked' : 'Unattributed / no analytics linkage',
                $lead['channels'] ?? ($lead['channel'] ?? ''),
                $lead['event_count'] ?? 1,
                $lead['page_path'] ?? '',
                $lead['placement'] ?? '',
                $lead['event_type'] ?? '',
                $lead['status'] ?? '',
                $lead['product_sku'] ?? '',
                $lead['site_language'] ?? '',
                $lead['retention_class'] ?? '',
                $lead['event_id'] ?? '',
            ]));
        }
        fflush($output);
        $page++;
    } while (count($items) === $batchSize);

    fclose($output);
    exit;
}

function vjt_export_submissions_csv_start($filters) {
    vjt_export_leads_csv_start($filters);
}

function vjt_export_contact_events_csv_start($filters) {
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename=contact-events-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time (Beijing)', 'Channel', 'Event Type', 'Status', 'Page Path', 'Placement', 'Product', 'Language', 'Attribution', 'Visitor ID', 'Session ID', 'Retention Class', 'Event ID']);

    $page = 1;
    $batchSize = 1000;
    do {
        $result = vjt_get_contact_events_list(array_merge($filters, [
            'page' => $page,
            'per_page' => $batchSize,
            'skip_count' => true,
        ]));
        $items = $result['items'];
        foreach ($items as $event) {
            fputcsv($output, vjt_csv_safe_row([
                vjt_format_for_admin($event['occurred_at'] ?? ''),
                $event['channel'] ?? '',
                $event['event_type'] ?? '',
                $event['status'] ?? '',
                $event['page_path'] ?? '',
                $event['placement'] ?? '',
                $event['product_sku'] ?? '',
                $event['site_language'] ?? '',
                !empty($event['vjt_visitor_id']) ? 'Consented journey linked' : 'Unattributed / no analytics linkage',
                $event['vjt_visitor_id'] ?? '',
                $event['vjt_session_id'] ?? '',
                $event['retention_class'] ?? '',
                $event['event_id'] ?? '',
            ]));
        }
        fflush($output);
        $page++;
    } while (count($items) === $batchSize);

    fclose($output);
    exit;
}

function vjt_export_gsc_keywords_csv_start($days = 28) {
    $period = vjt_gsc_query_period($days);
    $days = $period['days'];
    $start = $period['start_date'];
    $end = $period['end_date'];
    $batchSize = 5000;
    $startRow = 0;
    $response = vjt_gsc_search_analytics([
        'startDate' => $start,
        'endDate' => $end,
        'dimensions' => ['query'],
        'rowLimit' => $batchSize,
        'startRow' => $startRow,
    ]);

    if (empty($response['ok'])) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo 'Google Search Console export is temporarily unavailable.';
        exit;
    }

    // Build the paginated Google export in a disk-backed temporary stream.
    // This keeps memory bounded and prevents a mid-export API failure from
    // delivering a silently truncated CSV to the administrator.
    $output = tmpfile();
    if ($output === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo 'Could not create a temporary export file.';
        exit;
    }
    fputcsv($output, ['Start Date', 'End Date', 'Query', 'Clicks', 'Impressions', 'CTR', 'Average Position']);

    $complete = true;
    while (true) {
        $rawRows = $response['rows'] ?? [];
        foreach ($rawRows as $rawRow) {
            $row = vjt_gsc_normalize_query_row($rawRow);
            if ($row === null) continue;
            fputcsv($output, vjt_csv_safe_row([
                $start,
                $end,
                $row['query'],
                $row['clicks'],
                $row['impressions'],
                round((float)$row['ctr'] * 100, 4) . '%',
                round((float)$row['position'], 2),
            ]));
        }
        fflush($output);

        $rowCount = count($rawRows);
        if ($rowCount < $batchSize) break;
        $startRow += $rowCount;
        $response = vjt_gsc_search_analytics([
            'startDate' => $start,
            'endDate' => $end,
            'dimensions' => ['query'],
            'rowLimit' => $batchSize,
            'startRow' => $startRow,
        ]);
        if (empty($response['ok'])) {
            error_log('VJT GSC CSV export stopped after row ' . $startRow . ': ' . ($response['error'] ?? 'Unknown Google API error.'));
            $complete = false;
            break;
        }
    }

    if (!$complete) {
        fclose($output);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo 'Google Search Console export was interrupted. Please try again.';
        exit;
    }

    rewind($output);
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename=gsc-keywords-' . $days . 'd-' . date('Y-m-d') . '.csv');
    fpassthru($output);
    fclose($output);
    exit;
}

function vjt_export_visitors_csv_start($filters) {
    $result = vjt_get_visitors_list(array_merge($filters, ['page'=>1, 'per_page'=>100000]));
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename=vjt-visitors-' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Visitor ID','First Seen (Beijing)','Last Seen (Beijing)','Country','Device','Browser','Source','Sessions','Leads','Session Time (s)']);
    foreach ($result['items'] as $v) fputcsv($out, vjt_csv_safe_row([$v['visitor_id'], vjt_format_for_admin($v['first_seen_at']), vjt_format_for_admin($v['last_seen_at']), vjt_country_name($v['country']), $v['device_type'], $v['browser'], $v['source'], $v['sessions'], $v['submissions'], $v['session_time']]));
    fclose($out); exit;
}

function vjt_export_journey_csv_start($visitorId) {
    $journey = vjt_get_journey($visitorId);
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename=vjt-journey-' . preg_replace('/[^A-Za-z0-9_-]/', '', $visitorId) . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Session','Visited (Beijing)','URL','Title','Duration (s)','Active Duration (s)','Max Scroll (%)','Source']);
    $sources = [];
    foreach ($journey['sessions'] as $s) $sources[$s['session_id']] = vjt_source_label($s);
    foreach ($journey['pageviews'] as $pv) fputcsv($out, vjt_csv_safe_row([$pv['session_id'], vjt_format_for_admin($pv['visited_at']), $pv['url'], $pv['title'], $pv['duration_seconds'], $pv['active_duration_seconds'] ?? 0, $pv['max_scroll_depth'] ?? ($pv['scroll_depth'] ?? 0), $sources[$pv['session_id']] ?? '']));
    fclose($out); exit;
}
