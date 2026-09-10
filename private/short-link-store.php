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
    $db->exec("CREATE TABLE IF NOT EXISTS short_link_event_counts (
        short_link_id INTEGER PRIMARY KEY REFERENCES short_links(id) ON DELETE CASCADE,
        event_count INTEGER NOT NULL DEFAULT 0 CHECK(event_count >= 0),
        total_opens INTEGER NOT NULL DEFAULT 0 CHECK(total_opens >= 0),
        total_bots INTEGER NOT NULL DEFAULT 0 CHECK(total_bots >= 0),
        last_opened TEXT,
        pruned_events INTEGER NOT NULL DEFAULT 0 CHECK(pruned_events >= 0)
    )");
    short_link_add_event_count_column($db, 'total_opens', 'INTEGER NOT NULL DEFAULT 0');
    short_link_add_event_count_column($db, 'total_bots', 'INTEGER NOT NULL DEFAULT 0');
    short_link_add_event_count_column($db, 'last_opened', 'TEXT');
    short_link_add_event_count_column($db, 'pruned_events', 'INTEGER NOT NULL DEFAULT 0');
    $schemaVersion = (int)$db->query('SELECT version FROM short_link_schema LIMIT 1')->fetchColumn();
    if ($schemaVersion < 3) {
        $db->exec("INSERT OR IGNORE INTO short_link_event_counts(short_link_id, event_count, total_opens, total_bots, last_opened)
            SELECT short_link_id,
                COUNT(*),
                SUM(CASE WHEN event_kind = 'server_count' THEN 1 ELSE 0 END),
                SUM(CASE WHEN event_kind = 'bot' THEN 1 ELSE 0 END),
                MAX(opened_at)
            FROM short_link_events GROUP BY short_link_id");
        $db->exec("UPDATE short_link_event_counts
            SET event_count = COALESCE((SELECT COUNT(*) FROM short_link_events e WHERE e.short_link_id = short_link_event_counts.short_link_id), 0),
                total_opens = COALESCE((SELECT SUM(CASE WHEN event_kind = 'server_count' THEN 1 ELSE 0 END) FROM short_link_events e WHERE e.short_link_id = short_link_event_counts.short_link_id), 0),
                total_bots = COALESCE((SELECT SUM(CASE WHEN event_kind = 'bot' THEN 1 ELSE 0 END) FROM short_link_events e WHERE e.short_link_id = short_link_event_counts.short_link_id), 0),
                last_opened = (SELECT MAX(opened_at) FROM short_link_events e WHERE e.short_link_id = short_link_event_counts.short_link_id)");
        $db->exec('UPDATE short_link_schema SET version = 3');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_short_links_status_created ON short_links(status, created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_short_link_events_link_opened ON short_link_events(short_link_id, opened_at DESC)');
}

function short_link_add_event_count_column(PDO $db, string $name, string $definition): void {
    $columns = $db->query('PRAGMA table_info(short_link_event_counts)')->fetchAll();
    foreach ($columns as $column) if (($column['name'] ?? '') === $name) return;
    $db->exec('ALTER TABLE short_link_event_counts ADD COLUMN ' . $name . ' ' . $definition);
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
    $hosts = $configured === false || trim($configured) === '' ? [] : explode(',', $configured);
    return array_values(array_filter(array_map(static fn($host) => strtolower(trim($host)), $hosts)));
}

function short_link_host_allowed(string $host): bool {
    $allowedHosts = short_link_allowed_hosts();
    if ($allowedHosts === []) return true;
    foreach ($allowedHosts as $allowed) {
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
        return ['created' => true, 'destination' => short_link_destination_get($id), 'distribution_count' => 0];
    } catch (PDOException $error) {
        short_link_rollback($db);
        if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
            $stmt = $db->prepare('SELECT * FROM short_link_destinations WHERE normalized_url = ?');
            $stmt->execute([$normalized['normalized_url']]);
            $destination = $stmt->fetch() ?: null;
            return ['created' => false, 'destination' => $destination, 'distribution_count' => $destination ? short_link_destination_distribution_count((int)$destination['id']) : 0];
        }
        throw $error;
    }
}

function short_link_destination_get(int $id): ?array {
    $stmt = short_link_db()->prepare('SELECT * FROM short_link_destinations WHERE id = ?'); $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
function short_link_destination_distribution_count(int $destinationId): int {
    $stmt = short_link_db()->prepare("SELECT COUNT(*) FROM short_links WHERE destination_id = ? AND status != 'deleted'");
    $stmt->execute([$destinationId]);
    return (int)$stmt->fetchColumn();
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
function short_link_event_limit(string $environmentName, int $default, int $hardMaximum): int {
    $configured = getenv($environmentName);
    if (!is_string($configured) || preg_match('/^[1-9][0-9]*$/D', $configured) !== 1) return $default;
    return min((int)$configured, $hardMaximum);
}
function short_link_event_prune_batch(int $limit): int { return max(1, intdiv($limit, 10)); }
function short_link_prune_events(PDO $db, ?int $id, int $limit): int {
    $where = $id === null ? '' : ' WHERE short_link_id = ?';
    $params = $id === null ? [$limit] : [$id, $limit];
    $group = $db->prepare("SELECT short_link_id, COUNT(*) AS removed FROM (
        SELECT short_link_id FROM short_link_events" . $where . " ORDER BY opened_at ASC, id ASC LIMIT ?
    ) GROUP BY short_link_id");
    $group->execute($params);
    $removedByLink = $group->fetchAll();
    if ($removedByLink === []) return 0;
    $delete = $db->prepare('DELETE FROM short_link_events WHERE id IN (SELECT id FROM short_link_events' . $where . ' ORDER BY opened_at ASC, id ASC LIMIT ?)');
    $delete->execute($params);
    $update = $db->prepare('UPDATE short_link_event_counts SET event_count = MAX(event_count - ?, 0), pruned_events = pruned_events + ? WHERE short_link_id = ?');
    foreach ($removedByLink as $row) {
        $removed = (int)$row['removed'];
        $update->execute([$removed, $removed, (int)$row['short_link_id']]);
    }
    return $delete->rowCount();
}
function short_link_record_open(int $id, string $recipient, bool $bot, string $country = ''): bool {
    $country = strtoupper(trim($country));
    if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) $country = '';
    $perLinkLimit = short_link_event_limit('KSSMI_SHORTLINK_EVENTS_PER_LINK', 100000, 250000);
    $globalLimit = short_link_event_limit('KSSMI_SHORTLINK_EVENTS_TOTAL', 500000, 1000000);
    $db = short_link_db(); short_link_begin_immediate($db);
    try {
        $globalCount = (int)$db->query('SELECT COALESCE(SUM(event_count), 0) FROM short_link_event_counts')->fetchColumn();
        if ($globalCount >= $globalLimit) short_link_prune_events($db, null, max(short_link_event_prune_batch($globalLimit), $globalCount - $globalLimit + 1));
        $perLink = $db->prepare('SELECT event_count FROM short_link_event_counts WHERE short_link_id = ?');
        $perLink->execute([$id]);
        $perLinkCount = (int)($perLink->fetchColumn() ?: 0);
        $perLink->closeCursor();
        if ($perLinkCount >= $perLinkLimit) short_link_prune_events($db, $id, max(short_link_event_prune_batch($perLinkLimit), $perLinkCount - $perLinkLimit + 1));
        $openedAt = short_link_now();
        $db->prepare('INSERT INTO short_link_events(short_link_id,opened_at,event_kind,recipient_ref_snapshot,country) VALUES(?,?,?,?,?)')
            ->execute([$id, $openedAt, $bot ? 'bot' : 'server_count', $recipient, $country]);
        $db->prepare("INSERT INTO short_link_event_counts(short_link_id,event_count,total_opens,total_bots,last_opened)
            VALUES(?,1,?,?,?) ON CONFLICT(short_link_id) DO UPDATE SET
                event_count = event_count + 1,
                total_opens = total_opens + excluded.total_opens,
                total_bots = total_bots + excluded.total_bots,
                last_opened = excluded.last_opened")
            ->execute([$id, $bot ? 0 : 1, $bot ? 1 : 0, $openedAt]);
        short_link_commit($db); return true;
    } catch (Throwable $error) { short_link_rollback($db); throw $error; }
}
function short_link_event_capacity(?int $id = null): array {
    $db = short_link_db();
    $globalLimit = short_link_event_limit('KSSMI_SHORTLINK_EVENTS_TOTAL', 500000, 1000000);
    $perLinkLimit = short_link_event_limit('KSSMI_SHORTLINK_EVENTS_PER_LINK', 100000, 250000);
    $globalCount = (int)$db->query('SELECT COALESCE(SUM(event_count), 0) FROM short_link_event_counts')->fetchColumn();
    $globalTotal = (int)$db->query('SELECT COALESCE(SUM(total_opens + total_bots), 0) FROM short_link_event_counts')->fetchColumn();
    $row = [];
    if ($id !== null) { $stmt = $db->prepare('SELECT event_count,total_opens,total_bots,pruned_events FROM short_link_event_counts WHERE short_link_id = ?'); $stmt->execute([$id]); $row = $stmt->fetch() ?: []; }
    return ['global_count'=>$globalCount, 'global_total'=>$globalTotal, 'global_limit'=>$globalLimit,
        'link_count'=>$id === null ? null : (int)($row['event_count'] ?? 0),
        'link_total'=>$id === null ? null : (int)($row['total_opens'] ?? 0) + (int)($row['total_bots'] ?? 0),
        'link_limit'=>$perLinkLimit, 'pruned_events'=>$id === null ? null : (int)($row['pruned_events'] ?? 0)];
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
        $db->prepare('DELETE FROM short_link_event_counts WHERE short_link_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM short_links WHERE id = ?')->execute([$id]);
        short_link_commit($db);
    } catch (Throwable $error) { short_link_rollback($db); throw $error; }
}
function short_link_count(string $search = '', ?string $createdBy = null): int {
    $search = short_link_text($search, 256);
    $sql = "SELECT COUNT(*) FROM short_links l JOIN short_link_destinations d ON d.id=l.destination_id WHERE l.status != 'deleted'";
    $params = [];
    if ($createdBy !== null) { $sql .= ' AND l.created_by = ?'; $params[] = $createdBy; }
    if ($search !== '') { $sql .= ' AND (l.code LIKE ? OR d.target_url LIKE ? OR l.label LIKE ? OR l.campaign LIKE ? OR l.recipient_ref LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like,$like); }
    $stmt = short_link_db()->prepare($sql); $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}
function short_link_list(string $search = '', int $limit = 100, int $offset = 0, ?string $createdBy = null): array {
    $search = short_link_text($search, 256); $limit = max(1, min(250, $limit)); $offset = max(0, $offset);
    $sql = "SELECT l.*,d.target_url, COALESCE(c.total_opens,0) AS opens, COALESCE(c.total_bots,0) AS bots, c.last_opened FROM short_links l JOIN short_link_destinations d ON d.id=l.destination_id LEFT JOIN short_link_event_counts c ON c.short_link_id=l.id";
    // Hide any legacy soft-deleted rows; all new user-facing deletions use the
    // permanent-delete operation above and remove their rows altogether.
    $sql .= " WHERE l.status != 'deleted'";
    $params = []; if ($createdBy !== null) { $sql .= ' AND l.created_by = ?'; $params[] = $createdBy; } if ($search !== '') { $sql .= ' AND (l.code LIKE ? OR d.target_url LIKE ? OR l.label LIKE ? OR l.campaign LIKE ? OR l.recipient_ref LIKE ?)'; $like='%'.$search.'%'; array_push($params,$like,$like,$like,$like,$like); }
    $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset; $stmt=short_link_db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
}
function short_link_tracking_neighbors(int $id, string $search = '', ?string $createdBy = null): array {
    $search = short_link_text($search, 256);
    $currentSql = 'SELECT id,created_at FROM short_links WHERE id = ? AND status != \'deleted\'' . ($createdBy !== null ? ' AND created_by = ?' : '');
    $current = short_link_db()->prepare($currentSql);
    $current->execute($createdBy === null ? [$id] : [$id, $createdBy]);
    $row = $current->fetch();
    if (!$row) return ['previous' => null, 'next' => null];

    $where = " FROM short_links l JOIN short_link_destinations d ON d.id = l.destination_id WHERE l.status != 'deleted'";
    $params = [];
    if ($createdBy !== null) { $where .= ' AND l.created_by = ?'; $params[] = $createdBy; }
    if ($search !== '') {
        $where .= ' AND (l.code LIKE ? OR d.target_url LIKE ? OR l.label LIKE ? OR l.campaign LIKE ? OR l.recipient_ref LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like];
    }
    $select = 'SELECT l.id,l.code';
    $newer = short_link_db()->prepare($select . $where . ' AND (l.created_at > ? OR (l.created_at = ? AND l.id > ?)) ORDER BY l.created_at ASC, l.id ASC LIMIT 1');
    $newer->execute(array_merge($params, [$row['created_at'], $row['created_at'], $row['id']]));
    $older = short_link_db()->prepare($select . $where . ' AND (l.created_at < ? OR (l.created_at = ? AND l.id < ?)) ORDER BY l.created_at DESC, l.id DESC LIMIT 1');
    $older->execute(array_merge($params, [$row['created_at'], $row['created_at'], $row['id']]));
    return ['previous' => $newer->fetch() ?: null, 'next' => $older->fetch() ?: null];
}
function short_link_tracking(int $id, int $limit = 250, ?string $createdBy = null): ?array {
    $link = short_link_get($id);
    if (!$link) return null;
    if ($createdBy !== null && !hash_equals((string)$link['created_by'], $createdBy)) return null;
    $limit = max(1, min(500, $limit));
    $summary = short_link_db()->prepare("SELECT COALESCE(total_opens,0) AS opens, COALESCE(total_bots,0) AS bots, last_opened FROM short_link_event_counts WHERE short_link_id = ?");
    $summary->execute([$id]);
    // The event list contains confirmed opens only. Bot checks remain in the
    // summary, while a recipient reference (when one was assigned) gets its
    // own open total without treating anonymous visitors as identifiable.
    $events = short_link_db()->prepare("SELECT e.opened_at,e.recipient_ref_snapshot,e.country,
        CASE WHEN e.recipient_ref_snapshot != '' THEN (
            SELECT COUNT(*) FROM short_link_events counted
            WHERE counted.short_link_id = e.short_link_id
              AND counted.event_kind = 'server_count'
              AND counted.recipient_ref_snapshot = e.recipient_ref_snapshot
        ) ELSE NULL END AS recipient_opens
        FROM short_link_events e
        WHERE e.short_link_id = ? AND e.event_kind = 'server_count'
        ORDER BY e.opened_at DESC, e.id DESC LIMIT " . $limit);
    $events->execute([$id]);
    $locations = short_link_db()->prepare("SELECT country, COUNT(*) AS opens, MAX(opened_at) AS last_opened FROM short_link_events WHERE short_link_id = ? AND event_kind = 'server_count' AND country != '' GROUP BY country ORDER BY opens DESC, country ASC");
    $locations->execute([$id]);
    return ['link' => $link, 'summary' => $summary->fetch() ?: ['opens' => 0, 'bots' => 0, 'last_opened' => null], 'events' => $events->fetchAll(), 'locations' => $locations->fetchAll()];
}
