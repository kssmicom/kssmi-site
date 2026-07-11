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

// Per-field length caps applied to ingest payloads (track-pageview / track-submission).
function vjt_field_caps() {
    return [
        'url' => 2048, 'title' => 512, 'referrer' => 2048,
        'landing_url' => 2048, 'landing_title' => 512,
        'utm_source' => 256, 'utm_medium' => 256, 'utm_campaign' => 256, 'utm_content' => 256, 'utm_term' => 256,
        'screen_resolution' => 32, 'timezone' => 64, 'language' => 64, 'site_language' => 8,
        'form_plugin' => 64, 'form_id' => 256, 'form_name' => 256,
        'submit_page' => 2048, 'submit_title' => 512, 'contact_url' => 2048,
    ];
}

// ── Database connection ──────────────────────────────────────────────────────

function vjt_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(VJT_DATA_DIR)) {
        @mkdir(VJT_DATA_DIR, 0755, true);
    }
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
        @chmod(VJT_DB_PATH, 0666);
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
        scroll_depth     INTEGER DEFAULT 0,
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
        status        TEXT DEFAULT 'attempt',
        contact_url   TEXT DEFAULT '',
        ip            TEXT DEFAULT '',
        country       TEXT DEFAULT '',
        city          TEXT DEFAULT '',
        region        TEXT DEFAULT '',
        calling_code  TEXT DEFAULT ''
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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_visitor ON sessions(visitor_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_started ON sessions(started_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sess_seen    ON sessions(last_seen_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_seen     ON visitors(last_seen_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_country  ON visitors(country)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_vis_device   ON visitors(device_type)");
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
        if (!@mkdir(VJT_DATA_DIR, 0755, true)) {
            error_log("VJT ERROR: Failed to create data directory at " . VJT_DATA_DIR);
        }
    }
    vjt_create_schema();

    // Seed default settings if missing
    $defaults = ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'];
    foreach ($defaults as $k => $v) {
        $stmt = vjt_db()->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$k, $v]);
    }

    // One-time migration from the old JSON flat files
    vjt_migrate_json_if_needed();

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
    $cutoff = date('Y-m-d H:i:s', $now - ($retentionDays * 86400));

    try {
        $db = vjt_db();
        $db->prepare("DELETE FROM pageviews WHERE visited_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM submissions WHERE submitted_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM sessions WHERE last_seen_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM visitors WHERE last_seen_at < ?")->execute([$cutoff]);
        $db->prepare("DELETE FROM geo_cache WHERE cached_at < ?")->execute([$cutoff]);
        // Drop stale geo-queue entries (anything older than 2 days is unlikely to matter)
        $db->prepare("DELETE FROM geo_queue WHERE queued_at < ?")->execute([date('Y-m-d H:i:s', $now - 2 * 86400)]);
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
        utm_source, utm_medium, utm_campaign, utm_content, utm_term
        FROM sessions WHERE session_id = ?");
    $stmt->execute([$sid]);
    $row = $stmt->fetch();

    if ($row) {
        $sets = ['last_seen_at = :last_seen_at'];
        $params = [':last_seen_at' => $now, ':sid' => $sid];
        foreach (['ip', 'country', 'city', 'region', 'calling_code'] as $col) {
            if (!empty($data[$col])) { $sets[] = "$col = :$col"; $params[":$col"] = $data[$col]; }
        }
        // Late capture: only fill these if currently empty
        if (empty($row['referrer']) && !empty($data['referrer'])) {
            $sets[] = "referrer = :referrer"; $params[':referrer'] = $data['referrer'];
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
            }
        }
        $sql = "UPDATE sessions SET " . implode(', ', $sets) . " WHERE session_id = :sid";
        $db->prepare($sql)->execute($params);
    } else {
        $stmt = $db->prepare("INSERT INTO sessions
            (session_id, visitor_id, ip, country, city, region, calling_code, referrer,
             landing_url, landing_title, utm_source, utm_medium, utm_campaign, utm_content,
             utm_term, started_at, last_seen_at)
            VALUES (:session_id,:visitor_id,:ip,:country,:city,:region,:calling_code,:referrer,
             :landing_url,:landing_title,:utm_source,:utm_medium,:utm_campaign,:utm_content,
             :utm_term,:started_at,:last_seen_at)");
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
            ':started_at' => $now,
            ':last_seen_at' => $now,
        ]);
    }
}

// ── Pageview ────────────────────────────────────────────────────────────────

function vjt_add_pageview($data) {
    $db = vjt_db();
    $stmt = $db->prepare("INSERT INTO pageviews
        (session_id, visitor_id, url, title, visited_at, leave_at, duration_seconds, scroll_depth, step_order)
        VALUES (:session_id,:visitor_id,:url,:title,:visited_at,:leave_at,:duration_seconds,:scroll_depth,:step_order)");
    $stmt->execute([
        ':session_id' => $data['session_id'],
        ':visitor_id' => $data['visitor_id'],
        ':url' => $data['url'] ?? '',
        ':title' => $data['title'] ?? '',
        ':visited_at' => vjt_to_beijing($data['visited_at'] ?? ''),
        ':leave_at' => !empty($data['leave_at']) ? vjt_to_beijing($data['leave_at']) : null,
        ':duration_seconds' => (int)($data['duration_seconds'] ?? 0),
        ':scroll_depth' => (int)($data['scroll_depth'] ?? 0),
        ':step_order' => (int)($data['step_order'] ?? 1),
    ]);
}

function vjt_update_pageview_leave($sessionId, $url, $leaveAt, $duration, $scrollDepth) {
    $db = vjt_db();
    // Most recent open pageview for this session+url
    $stmt = $db->prepare("SELECT id, scroll_depth FROM pageviews
        WHERE session_id = ? AND url = ? AND (leave_at IS NULL OR leave_at = '')
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$sessionId, $url]);
    $row = $stmt->fetch();
    if (!$row) return;

    $newScroll = max((int)$row['scroll_depth'], (int)$scrollDepth);
    $upd = $db->prepare("UPDATE pageviews
        SET leave_at = :leave_at, duration_seconds = :duration, scroll_depth = :scroll
        WHERE id = :id");
    $upd->execute([
        ':leave_at' => vjt_to_beijing($leaveAt),
        ':duration' => max(0, (int)$duration),
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
    $cutoff = date('Y-m-d H:i:s', strtotime('-10 minutes'));

    // Deduplication: same visitor+session+form+status within 10 minutes
    $stmt = $db->prepare("SELECT id FROM submissions
        WHERE visitor_id = ? AND session_id = ? AND form_plugin = ? AND status = ?
          AND submitted_at >= ? LIMIT 1");
    $stmt->execute([
        $data['visitor_id'],
        $data['session_id'],
        $data['form_plugin'] ?? '',
        $data['status'] ?? 'attempt',
        $cutoff,
    ]);
    if ($stmt->fetch()) return; // duplicate

    $ins = $db->prepare("INSERT INTO submissions
        (visitor_id, session_id, form_plugin, form_id, form_name, submit_page, submit_title,
         submitted_at, status, contact_url, ip, country, city, region, calling_code)
        VALUES (:visitor_id,:session_id,:form_plugin,:form_id,:form_name,:submit_page,:submit_title,
         :submitted_at,:status,:contact_url,:ip,:country,:city,:region,:calling_code)");
    $ins->execute([
        ':visitor_id' => $data['visitor_id'],
        ':session_id' => $data['session_id'],
        ':form_plugin' => $data['form_plugin'] ?? '',
        ':form_id' => $data['form_id'] ?? '',
        ':form_name' => $data['form_name'] ?? '',
        ':submit_page' => $data['submit_page'] ?? '',
        ':submit_title' => $data['submit_title'] ?? '',
        ':submitted_at' => $now,
        ':status' => $data['status'] ?? 'attempt',
        ':contact_url' => $data['contact_url'] ?? '',
        ':ip' => $data['ip'] ?? '',
        ':country' => $data['country'] ?? '',
        ':city' => $data['city'] ?? '',
        ':region' => $data['region'] ?? '',
        ':calling_code' => $data['calling_code'] ?? '',
    ]);
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
        'ahc/', 'node-fetch', 'axios/', 'guzzle', 'monitoring', 'preview', 'whatsapp',
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

function vjt_source_from_host($host) {
    $host = strtolower((string)$host);
    if ($host === '' || $host === '__internal__') return $host === '__internal__' ? 'internal' : 'direct';

    $aliases = [
        'google' => 'search', 'bing' => 'search', 'yahoo' => 'search', 'baidu' => 'search',
        'duckduckgo' => 'search', 'yandex' => 'search',
        'facebook' => 'social', 'instagram' => 'social', 'linkedin' => 'social',
        'twitter' => 'social', 'youtube' => 'social', 'tiktok' => 'social', 'whatsapp' => 'social',
        'chatgpt' => 'ai', 'openai' => 'ai', 'perplexity' => 'ai', 'claude' => 'ai',
        'gemini' => 'ai', 'grok' => 'ai', 'deepseek' => 'ai', 'doubao' => 'ai',
    ];
    if (isset($aliases[$host])) return $aliases[$host];

    $ai = ['chatgpt.com', 'openai.com', 'perplexity.ai', 'claude.ai', 'anthropic.com', 'gemini.google.com', 'x.ai', 'grok.com', 'copilot.microsoft.com', 'deepseek.com', 'doubao.com'];
    $search = ['google.', 'bing.', 'yahoo.', 'baidu.', 'duckduckgo.', 'yandex.', 'ask.', 'aol.'];
    $social = ['facebook.', 'instagram.', 'twitter.', 'x.com', 'linkedin.', 'youtube.', 'tiktok.', 'pinterest.', 'reddit.', 'weibo.', 't.co', 'fb.me', 'fb.com', 'whatsapp.com'];
    foreach ($ai as $needle) if ($host === $needle || substr($host, -strlen('.' . $needle)) === '.' . $needle) return 'ai';
    foreach ($search as $needle) if (strpos($host, $needle) !== false) return 'search';
    foreach ($social as $needle) if ($host === $needle || substr($host, -strlen('.' . $needle)) === '.' . $needle || strpos($host, $needle) !== false) return 'social';
    return 'other';
}

function vjt_classify_source($session) {
    $referrer = $session['referrer'] ?? '';
    $utmSource = strtolower(trim((string)($session['utm_source'] ?? '')));
    $utmMedium = strtolower(trim((string)($session['utm_medium'] ?? '')));

    // Explicit campaign tags win over browser referrer because they are the
    // publisher-controlled attribution signal.
    if (in_array($utmMedium, ['cpc', 'ppc', 'paid', 'paidads', 'display', 'retargeting', 'ads'], true)) return 'ads';
    if (in_array($utmMedium, ['social', 'social-media', 'social_network'], true)) return 'social';
    if (in_array($utmMedium, ['email', 'newsletter'], true)) return 'other';
    if ($utmSource !== '') {
        $utmHost = vjt_normalize_referrer_host($utmSource);
        $utmClass = vjt_source_from_host($utmHost ?: $utmSource);
        if ($utmClass !== 'other' || $utmMedium !== '') return $utmClass;
    }

    $host = vjt_normalize_referrer_host($referrer);
    return vjt_source_from_host($host);
}

// ── Settings ────────────────────────────────────────────────────────────────

function vjt_get_settings($forceReload = false) {
    static $cache = null;
    if ($cache !== null && !$forceReload) return $cache;
    $defaults = ['session_timeout' => '30', 'retention_days' => '90', 'enable_geo' => '1'];
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

    $subStmt = $db->prepare("SELECT
            COUNT(DISTINCT CASE WHEN status IN ('success','intent') THEN visitor_id END) total,
            COUNT(DISTINCT CASE WHEN status IN ('success','intent') THEN visitor_id END) success
        FROM submissions WHERE submitted_at >= ?");
    $subStmt->execute([$since]);
    $subRow = $subStmt->fetch();
    $totalSubmissions = (int)($subRow['total'] ?? 0);
    $successSubmissions = (int)($subRow['success'] ?? 0);

    $durStmt = $db->prepare("SELECT AVG(duration_seconds) a FROM pageviews
        WHERE visited_at >= ? AND duration_seconds > 0");
    $durStmt->execute([$since]);
    $durRow = $durStmt->fetch();
    $avgDuration = $durRow && $durRow['a'] !== null ? round($durRow['a']) : 0;
    $conversionRate = $totalSessions > 0 ? round(($successSubmissions / $totalSessions) * 100, 1) : 0;

    // Submission trend (30 days)
    $trend = [];
    for ($i = 29; $i >= 0; $i--) { $trend[date('Y-m-d', strtotime("-{$i} days"))] = 0; }
    $trendStmt = $db->prepare("SELECT substr(datetime(submitted_at, '+8 hours'),1,10) d,
            COUNT(DISTINCT visitor_id) c FROM submissions
        WHERE submitted_at >= ? AND status IN ('success','intent')
        GROUP BY d");
    $trendStmt->execute([vjt_admin_date_to_utc(date('Y-m-d', strtotime('-29 days')))]);
    $rows = $trendStmt->fetchAll();
    foreach ($rows as $r) { if (isset($trend[$r['d']])) $trend[$r['d']] = (int)$r['c']; }

    // Submission trend (12 months)
    $trendMonthly = [];
    for ($i = 11; $i >= 0; $i--) { $trendMonthly[date('Y-m', strtotime("-{$i} months"))] = 0; }
    $monthlyStmt = $db->prepare("SELECT substr(datetime(submitted_at, '+8 hours'),1,7) m,
            COUNT(DISTINCT visitor_id) c FROM submissions WHERE status IN ('success','intent') GROUP BY m");
    $monthlyStmt->execute();
    $rows = $monthlyStmt->fetchAll();
    foreach ($rows as $r) { if (isset($trendMonthly[$r['m']])) $trendMonthly[$r['m']] = (int)$r['c']; }

    // Submission trend (years)
    $trendYearly = [];
    $minYear = (int)date('Y');
    $yearlyStmt = $db->prepare("SELECT substr(datetime(submitted_at, '+8 hours'),1,4) y,
            COUNT(DISTINCT visitor_id) c FROM submissions WHERE status IN ('success','intent') GROUP BY y");
    $yearlyStmt->execute();
    $rows = $yearlyStmt->fetchAll();
    $yearCounts = [];
    foreach ($rows as $r) {
        $y = (int)$r['y'];
        if ($y > 0) { $yearCounts[(string)$r['y']] = (int)$r['c']; if ($y < $minYear) $minYear = $y; }
    }
    for ($y = $minYear; $y <= (int)date('Y'); $y++) {
        $trendYearly[(string)$y] = $yearCounts[(string)$y] ?? 0;
    }

    // Top referrers: count normalized external hosts, not raw URLs. Keep
    // sessions, visitors, and leads as separate measures.
    $refStats = [];
    $refStmt = $db->prepare("SELECT referrer, visitor_id FROM sessions WHERE started_at >= ?");
    $refStmt->execute([$since]);
    $refVisitors = [];
    while ($r = $refStmt->fetch()) {
        $host = vjt_normalize_referrer_host($r['referrer'] ?? '');
        $label = $host === '' ? 'Direct' : ($host === '__internal__' ? 'Internal' : $host);
        if (!isset($refStats[$label])) $refStats[$label] = ['sessions' => 0, 'visitors' => 0, 'leads' => 0];
        $refStats[$label]['sessions']++;
        $refVisitors[$label][$r['visitor_id']] = true;
    }
    foreach ($refVisitors as $label => $set) $refStats[$label]['visitors'] = count($set);

    $refLeadStmt = $db->prepare("SELECT s.visitor_id, sess.referrer
        FROM submissions s JOIN sessions sess ON sess.session_id = s.session_id
        WHERE s.submitted_at >= ? AND s.status IN ('success','intent')");
    $refLeadStmt->execute([$since]);
    $refLeads = [];
    while ($r = $refLeadStmt->fetch()) {
        $host = vjt_normalize_referrer_host($r['referrer'] ?? '');
        $label = $host === '' ? 'Direct' : ($host === '__internal__' ? 'Internal' : $host);
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
    $sourceLeadStmt = $db->prepare("SELECT s.visitor_id, sess.referrer, sess.utm_source, sess.utm_medium
        FROM submissions s JOIN sessions sess ON sess.session_id = s.session_id
        WHERE s.submitted_at >= ? AND s.status IN ('success','intent')");
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
        'totalSubmissions'   => $totalSubmissions,
        'successSubmissions' => $successSubmissions,
        'avgDuration'        => $avgDuration,
        'conversionRate'     => $conversionRate,
        'trend'              => $trend,
        'trendMonthly'       => $trendMonthly,
        'trendYearly'        => $trendYearly,
        'topReferrers'       => array_column($topReferrerStats, 'sessions'),
        'topReferrerStats'   => $topReferrerStats,
        'deviceCounts'       => $deviceCounts,
        'sourceCounts'       => $sourceCounts,
        'sourceVisitorCounts'=> $sourceVisitorCounts,
        'sourceLeadCounts'   => $sourceLeadCounts,
    ];
}

// ── Dashboard: Submissions List ──────────────────────────────────────────────

function vjt_get_submissions_list($filters) {
    $db = vjt_db();
    $where = [];
    $params = [];
    if (($filters['status'] ?? '') === 'contact') {
        $where[] = "status IN ('success','intent')";
    } elseif (!empty($filters['status'])) {
        $where[] = "status = ?";
        $params[] = $filters['status'];
    }
    if (!empty($filters['plugin']))  { $where[] = "form_plugin = ?";  $params[] = $filters['plugin']; }
    if (!empty($filters['date_from'])) {
        $utcFrom = vjt_admin_date_to_utc($filters['date_from']);
        if ($utcFrom) { $where[] = "submitted_at >= ?"; $params[] = $utcFrom; }
    }
    if (!empty($filters['date_to'])) {
        $utcTo = vjt_admin_date_to_utc($filters['date_to'], true);
        if ($utcTo) { $where[] = "submitted_at <= ?"; $params[] = $utcTo; }
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cnt = $db->prepare("SELECT COUNT(DISTINCT visitor_id) c FROM submissions $whereSql");
    $cnt->execute($params);
    $total = (int)$cnt->fetch()['c'];

    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, (int)($filters['per_page'] ?? 50));
    $offset = ($page - 1) * $perPage;

    // One row per Visitor ID. The latest event supplies the row's contact
    // details; the aggregate fields preserve first/last time and all channels.
    $sql = "SELECT latest.*, agg.first_submitted_at, agg.last_submitted_at,
                agg.event_count, agg.channels
            FROM submissions latest
            JOIN (
                SELECT visitor_id, MIN(submitted_at) first_submitted_at,
                    MAX(submitted_at) last_submitted_at, COUNT(*) event_count,
                    GROUP_CONCAT(DISTINCT form_plugin) channels,
                    MAX(CASE WHEN status='success' THEN 1 ELSE 0 END) has_success,
                    MAX(id) latest_id
                FROM submissions $whereSql
                GROUP BY visitor_id
            ) agg ON agg.latest_id = latest.id
            ORDER BY agg.last_submitted_at DESC, latest.id DESC LIMIT ? OFFSET ?";
    $stmt = $db->prepare($sql);
    // Bind positionally; LIMIT/OFFSET need explicit int types for SQLite
    $idx = 1;
    foreach ($params as $p) { $stmt->bindValue($idx++, $p); }
    $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $item['submitted_at'] = $item['last_submitted_at'] ?? $item['submitted_at'];
        $item['form_plugin'] = 'contact';
        $item['form_name'] = $item['channels'] ?? ($item['form_name'] ?? '');
        $item['status'] = !empty($item['has_success']) ? 'success' : ($item['status'] ?? 'intent');
        $item['display_status'] = $item['status'];
    }
    unset($item);
    return ['items' => $items, 'total' => $total];
}

// ── Dashboard: Visitors List ─────────────────────────────────────────────────

function vjt_get_visitors_list($filters) {
    $db = vjt_db();

    // Pre-aggregate per-visitor sessions, submissions, session time, source
    $visitorSessions = [];
    $visitorSubmissions = [];
    $visitorSource = [];
    $visitorSessionTime = [];

    // Stream rows (fetch one at a time) instead of fetchAll() so we never hold
    // the whole sessions table in memory alongside the aggregate maps.
    $sessStmt = $db->prepare("SELECT visitor_id, referrer, utm_source, utm_medium, started_at, last_seen_at FROM sessions ORDER BY started_at ASC");
    $sessStmt->execute();
    while ($s = $sessStmt->fetch()) {
        $vid = $s['visitor_id'];
        $visitorSessions[$vid] = ($visitorSessions[$vid] ?? 0) + 1;
        if (!isset($visitorSource[$vid])) $visitorSource[$vid] = vjt_classify_source($s);
        $st = strtotime($s['started_at'] ?? '');
        $ls = strtotime($s['last_seen_at'] ?? '');
        if ($st && $ls && $ls > $st) {
            $visitorSessionTime[$vid] = ($visitorSessionTime[$vid] ?? 0) + ($ls - $st);
        }
    }
    $subStmt = $db->prepare("SELECT visitor_id,
        MAX(CASE WHEN status IN ('success','intent') THEN 1 ELSE 0 END) c
        FROM submissions GROUP BY visitor_id");
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

    $p = $db->prepare("SELECT * FROM pageviews WHERE visitor_id = ? ORDER BY visited_at ASC, id ASC");
    $p->execute([$visitorId]);
    $pageviews = $p->fetchAll();

    $sub = $db->prepare("SELECT * FROM submissions WHERE visitor_id = ? ORDER BY submitted_at ASC");
    $sub->execute([$visitorId]);
    $submissions = $sub->fetchAll();

    return [
        'visitor'     => $visitor,
        'sessions'    => $sessions,
        'pageviews'   => $pageviews,
        'submissions' => $submissions,
    ];
}

// ── Dashboard: Data Cleanup ──────────────────────────────────────────────────

function vjt_cleanup_old_data($days) {
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    $db = vjt_db();
    $db->prepare("DELETE FROM visitors    WHERE last_seen_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM sessions    WHERE last_seen_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM pageviews   WHERE visited_at  < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM submissions WHERE submitted_at < ?")->execute([$cutoff]);
    $geoCutoff = gmdate('Y-m-d H:i:s', time() - (7 * 86400));
    $db->prepare("DELETE FROM geo_cache WHERE cached_at < ?")->execute([$geoCutoff]);
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}

function vjt_wipe_all_data() {
    $db = vjt_db();
    foreach (['visitors','sessions','pageviews','submissions','geo_cache','geo_queue'] as $t) {
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

    // Top pages (views, avg duration, max scroll) within window
    $topPagesStmt = $db->prepare("SELECT url,
            COUNT(*) views,
            AVG(CASE WHEN duration_seconds > 0 THEN duration_seconds END) avg_dur,
            MAX(scroll_depth) max_scroll
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
    // Submissions per page (only for the pages we show)
    if ($topPages) {
        $urls = array_column($topPages, 'url');
        $place = implode(',', array_fill(0, count($urls), '?'));
        $stmt = $db->prepare("SELECT submit_page, COUNT(DISTINCT visitor_id) c FROM submissions
            WHERE submitted_at >= ? AND status IN ('success','intent') AND submit_page IN ($place) GROUP BY submit_page");
        $stmt->execute(array_merge([$since], $urls));
        $subByPage = [];
        foreach ($stmt->fetchAll() as $r) { $subByPage[$r['submit_page']] = (int)$r['c']; }
        foreach ($topPages as &$tp) { $tp['submissions'] = $subByPage[$tp['url']] ?? 0; }
        unset($tp);
    }

    $uniqueUrlsStmt = $db->prepare("SELECT COUNT(DISTINCT url) c FROM pageviews WHERE visited_at >= ? AND url <> ''");
    $uniqueUrlsStmt->execute([$since]);
    $uniqueUrls = (int)$uniqueUrlsStmt->fetch()['c'];

    // Bounce rate: sessions with a single pageview within window
    $bounceStmt = $db->prepare("SELECT
            COUNT(*) total_sessions,
            SUM(CASE WHEN pv = 1 THEN 1 ELSE 0 END) bounces
        FROM (SELECT session_id, COUNT(*) pv FROM pageviews WHERE visited_at >= ? GROUP BY session_id)");
    $bounceStmt->execute([$since]);
    $bounceRow = $bounceStmt->fetch();
    $totalSessions = (int)($bounceRow['total_sessions'] ?? 0);
    $bounceSessions = (int)($bounceRow['bounces'] ?? 0);
    $bounceRate = $totalSessions > 0 ? round(($bounceSessions / $totalSessions) * 100) : 0;

    $dwellStmt = $db->prepare("SELECT AVG(duration_seconds) a FROM pageviews
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
    $cbStmt = $db->prepare("SELECT visitor_id FROM submissions WHERE status IN ('success','intent')");
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

function vjt_export_submissions_csv_start($filters) {
    $result = vjt_get_submissions_list($filters);
    $items = $result['items'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=vjt-submissions-' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Last Contact (Beijing)', 'First Contact (Beijing)', 'Visitor ID', 'Channels', 'Events', 'Page', 'IP', 'Country', 'Status']);
    foreach ($items as $sub) {
        fputcsv($output, [
            vjt_format_for_admin($sub['last_submitted_at'] ?? ($sub['submitted_at'] ?? '')),
            vjt_format_for_admin($sub['first_submitted_at'] ?? ''),
            $sub['visitor_id'] ?? '',
            $sub['channels'] ?? ($sub['form_name'] ?? ''),
            $sub['event_count'] ?? 1,
            $sub['submit_page'] ?? '',
            $sub['ip'] ?? '',
            $sub['country'] ?? '',
            $sub['status'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}
