<?php
declare(strict_types=1);

/*
 * Private short-link persistence.  This file deliberately contains no HTTP
 * handling: public endpoints validate their own request context and use these
 * small, parameterised operations only.
 */

function short_link_data_dir(): string {
    $override = getenv('KSSMI_SHORTLINK_DATA_DIR');
    return is_string($override) && $override !== '' ? $override : '/home/kssmi.com/vjt_data';
}

function short_link_db(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $dir = short_link_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Short-link data directory is unavailable.');
    }
    @chmod($dir, 0750);
    $db = new PDO('sqlite:' . $dir . '/shortlinks.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    short_link_migrate($db);
    @chmod($dir . '/shortlinks.sqlite', 0600);
    return $db;
}

function short_link_migrate(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS short_link_schema (version INTEGER NOT NULL)');
    if ($db->query('SELECT COUNT(*) FROM short_link_schema')->fetchColumn() == 0) {
        $db->exec('INSERT INTO short_link_schema(version) VALUES (1)');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS short_link_destinations (
        id INTEGER PRIMARY KEY, target_url TEXT NOT NULL, normalized_url TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL, created_by TEXT NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS short_links (
        id INTEGER PRIMARY KEY, destination_id INTEGER NOT NULL REFERENCES short_link_destinations(id),
        code TEXT NOT NULL UNIQUE COLLATE BINARY, label TEXT NOT NULL DEFAULT '',
        campaign TEXT NOT NULL DEFAULT '', recipient_ref TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL CHECK(status IN ('active','archived','deleted')),
        created_at TEXT NOT NULL, created_by TEXT NOT NULL, deleted_at TEXT, deleted_by TEXT
    )");
    $db->exec('CREATE TABLE IF NOT EXISTS short_link_code_tombstones (code TEXT PRIMARY KEY COLLATE BINARY, retired_at TEXT NOT NULL)');
    $db->exec("CREATE TABLE IF NOT EXISTS short_link_events (
        id INTEGER PRIMARY KEY, short_link_id INTEGER NOT NULL REFERENCES short_links(id),
        opened_at TEXT NOT NULL, event_kind TEXT NOT NULL,
        recipient_ref_snapshot TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT ''
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_short_links_status_created ON short_links(status, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_short_link_events_link_opened ON short_link_events(short_link_id, opened_at DESC)');
}

function short_link_now(): string { return gmdate('Y-m-d H:i:s'); }

// PDO does not track transactions started with SQLite's BEGIN IMMEDIATE.
// Keep all three operations in SQLite SQL so the busy-lock acquisition and
// transaction lifecycle use the same mechanism.
function short_link_begin_immediate(PDO $db): void { $db->exec('BEGIN IMMEDIATE'); }
function short_link_commit(PDO $db): void { $db->exec('COMMIT'); }
function short_link_rollback(PDO $db): void { try { $db->exec('ROLLBACK'); } catch (PDOException $ignored) {} }

function short_link_text($value, int $max): string {
    $value = trim((string)$value);
    if (strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new InvalidArgumentException('Invalid text field.');
    return $value;
}

function short_link_allowed_hosts(): array {
    $configured = getenv('KSSMI_SHORTLINK_ALLOWED_HOSTS');
    $hosts = $configured === false || trim($configured) === ''
        ? ['gumlet.io', '*.gumlet.io', 'kssmi.com', '*.kssmi.com'] : explode(',', $configured);
    return array_values(array_filter(array_map(static fn($host) => strtolower(trim($host)), $hosts)));
}

function short_link_host_allowed(string $host): bool {
    foreach (short_link_allowed_hosts() as $allowed) {
        if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) return true;
    }
    return false;
}

function short_link_host_resolves_private(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) return short_link_is_private_ip($host);
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if ($records === false || $records === []) return true;
    foreach ($records as $record) {
        $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
        if (is_string($ip) && short_link_is_private_ip($ip)) return true;
    }
    return false;
}

function short_link_is_private_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function short_link_normalize_url(string $input): array {
    if (strlen($input) === 0 || strlen($input) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $input)) throw new InvalidArgumentException('Target URL is invalid.');
    $url = trim($input);
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') throw new InvalidArgumentException('A complete HTTPS URL is required.');
    if (strtolower((string)$parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Only HTTPS URLs without username or password are allowed.');
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || short_link_host_resolves_private($host)) throw new InvalidArgumentException('Private, local, or unresolvable targets are not allowed.');
    if (!short_link_host_allowed($host)) throw new InvalidArgumentException('This target domain is not on the approved list.');
    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && ($port < 1 || $port > 65535)) throw new InvalidArgumentException('Target port is invalid.');
    $authority = $host . ($port !== null && $port !== 443 ? ':' . $port : '');
    $path = $parts['path'] ?? '/';
    if ($path === '') $path = '/';
    $normalized = 'https://' . $authority . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return ['target_url' => $normalized, 'normalized_url' => $normalized];
}

function short_link_destination_create(string $url, string $admin): array {
    $normalized = short_link_normalize_url($url);
    $db = short_link_db();
    short_link_begin_immediate($db);
    try {
        $stmt = $db->prepare('INSERT INTO short_link_destinations(target_url, normalized_url, created_at, created_by) VALUES(?,?,?,?)');
        $stmt->execute([$normalized['target_url'], $normalized['normalized_url'], short_link_now(), $admin]);
        $id = (int)$db->lastInsertId();
        short_link_commit($db);
        return ['created' => true, 'destination' => short_link_destination_get($id)];
    } catch (PDOException $error) {
        short_link_rollback($db);
        if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
            $stmt = $db->prepare('SELECT * FROM short_link_destinations WHERE normalized_url = ?');
            $stmt->execute([$normalized['normalized_url']]);
            return ['created' => false, 'destination' => $stmt->fetch() ?: null];
        }
        throw $error;
    }
}

function short_link_destination_get(int $id): ?array {
    $stmt = short_link_db()->prepare('SELECT * FROM short_link_destinations WHERE id = ?'); $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function short_link_code(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $code = chr(random_int(65, 90));
    for ($i = 0; $i < 5; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

function short_link_create_distribution(int $destinationId, array $fields, string $admin): array {
    if (!short_link_destination_get($destinationId)) throw new InvalidArgumentException('Destination was not found.');
    $label = short_link_text($fields['label'] ?? '', 256); $campaign = short_link_text($fields['campaign'] ?? '', 256); $recipient = short_link_text($fields['recipient_ref'] ?? '', 256);
    $db = short_link_db();
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = short_link_code();
        try {
            short_link_begin_immediate($db);
            $reserved = $db->prepare('SELECT 1 FROM short_link_code_tombstones WHERE code = ?'); $reserved->execute([$code]);
            if ($reserved->fetchColumn()) { short_link_rollback($db); continue; }
            $stmt = $db->prepare("INSERT INTO short_links(destination_id,code,label,campaign,recipient_ref,status,created_at,created_by) VALUES(?,?,?,?,?,'active',?,?)");
            $stmt->execute([$destinationId, $code, $label, $campaign, $recipient, short_link_now(), $admin]);
            $id = (int)$db->lastInsertId(); short_link_commit($db);
            return short_link_get($id) ?? throw new RuntimeException('Created link could not be read.');
        } catch (PDOException $error) {
            short_link_rollback($db);
            if (!str_contains($error->getMessage(), 'UNIQUE constraint failed')) throw $error;
        }
    }
    throw new RuntimeException('Could not allocate a unique short code.');
}

function short_link_get(int $id): ?array {
    $stmt = short_link_db()->prepare('SELECT l.*, d.target_url FROM short_links l JOIN short_link_destinations d ON d.id=l.destination_id WHERE l.id=?'); $stmt->execute([$id]); return $stmt->fetch() ?: null;
}
function short_link_find_active(string $code): ?array {
    if (preg_match('/^[A-Z][A-Za-z0-9]{5}$/D', $code) !== 1) return null;
    $stmt = short_link_db()->prepare("SELECT l.*,d.target_url FROM short_links l JOIN short_link_destinations d ON d.id=l.destination_id WHERE l.code=? AND l.status='active'"); $stmt->execute([$code]); return $stmt->fetch() ?: null;
}
function short_link_record_open(int $id, string $recipient, bool $bot): void {
    $stmt = short_link_db()->prepare('INSERT INTO short_link_events(short_link_id,opened_at,event_kind,recipient_ref_snapshot) VALUES(?,?,?,?)');
    $stmt->execute([$id, short_link_now(), $bot ? 'bot' : 'server_count', $recipient]);
}
function short_link_is_bot(string $ua): bool { return $ua !== '' && preg_match('/bot|spider|crawler|preview|facebookexternalhit|slackbot|whatsapp/i', $ua) === 1; }
function short_link_set_status(int $id, string $status, string $admin): void {
    if (!in_array($status, ['active','archived','deleted'], true)) throw new InvalidArgumentException('Invalid status.');
    $db = short_link_db(); short_link_begin_immediate($db);
    try {
        $row = short_link_get($id); if (!$row) throw new InvalidArgumentException('Short link was not found.');
        if ($status === 'deleted') { $db->prepare('INSERT OR IGNORE INTO short_link_code_tombstones(code,retired_at) VALUES(?,?)')->execute([$row['code'], short_link_now()]); }
        $db->prepare('UPDATE short_links SET status=?, deleted_at=?, deleted_by=? WHERE id=?')->execute([$status, $status === 'deleted' ? short_link_now() : null, $status === 'deleted' ? $admin : null, $id]);
        short_link_commit($db);
    } catch (Throwable $error) { short_link_rollback($db); throw $error; }
}
function short_link_permanently_delete(int $id, string $confirmation, string $admin): void {
    $db = short_link_db(); short_link_begin_immediate($db);
    try {
        $row = short_link_get($id); if (!$row) throw new InvalidArgumentException('Short link was not found.');
        if (!hash_equals('DELETE ' . $row['code'], $confirmation)) throw new InvalidArgumentException('Type DELETE followed by the short code to confirm permanent deletion.');
        // Retain only the code tombstone. The link row and all per-open events
        // are physically removed, while an old email can never acquire a new
        // destination if this six-character code is generated again.
        $db->prepare('INSERT OR IGNORE INTO short_link_code_tombstones(code,retired_at) VALUES(?,?)')->execute([$row['code'], short_link_now()]);
        $db->prepare('DELETE FROM short_link_events WHERE short_link_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM short_links WHERE id = ?')->execute([$id]);
        short_link_commit($db);
    } catch (Throwable $error) { short_link_rollback($db); throw $error; }
}
function short_link_list(string $search = '', int $limit = 100): array {
    $search = short_link_text($search, 256); $limit = max(1, min(250, $limit));
    $sql = "SELECT l.*,d.target_url, SUM(CASE WHEN e.event_kind='server_count' THEN 1 ELSE 0 END) AS opens, SUM(CASE WHEN e.event_kind='bot' THEN 1 ELSE 0 END) AS bots, MAX(e.opened_at) AS last_opened FROM short_links l JOIN short_link_destinations d ON d.id=l.destination_id LEFT JOIN short_link_events e ON e.short_link_id=l.id";
    // Hide any legacy soft-deleted rows; all new user-facing deletions use the
    // permanent-delete operation above and remove their rows altogether.
    $sql .= " WHERE l.status != 'deleted'";
    $params = []; if ($search !== '') { $sql .= ' AND (l.code LIKE ? OR d.target_url LIKE ? OR l.label LIKE ? OR l.campaign LIKE ? OR l.recipient_ref LIKE ?)'; $like='%'.$search.'%'; $params=[$like,$like,$like,$like,$like]; }
    $sql .= ' GROUP BY l.id ORDER BY l.created_at DESC LIMIT ' . $limit; $stmt=short_link_db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
