<?php
/**
 * Inquiry sender blocklist.
 *
 * Two files under email_data/ (persistent across releases):
 *   sender-blocklist.json       rules; written only by the authenticated
 *                               Email Logs admin
 *   sender-blocklist-hits.json  recent blocked submissions and per-rule
 *                               counts; written by send-mail.php
 *
 * The public endpoint only reads the rules, so spam traffic can never damage
 * them. Both files use the Email Logs pattern: a .lock sidecar plus atomic
 * temp-file replacement.
 *
 * A rules file that exists but cannot be read is never treated as "empty" by
 * the admin (no overwrite). send-mail.php fails open on it: a broken blocklist
 * must not turn away real customers, so it logs loudly and counts instead.
 */

require_once __DIR__ . '/email-log-store.php';

const KSSMI_SENDER_BLOCKLIST_MAX_RULES = 500;
const KSSMI_SENDER_BLOCKLIST_MAX_HITS = 50;
const KSSMI_SENDER_BLOCKLIST_HIT_DAYS = 30;
const KSSMI_SENDER_BLOCKLIST_NOTE_MAX = 200;
const KSSMI_SENDER_BLOCKLIST_DETAILS_MAX = 500;

// A domain rule for one of these would block every customer using that
// provider (or our own domain), so they can only be blocked address by address.
const KSSMI_SENDER_BLOCKLIST_PUBLIC_DOMAINS = [
    'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
    'yahoo.com', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com',
    'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'mail.com', 'zoho.com',
    'yandex.com', 'yandex.ru', 'mail.ru', 'qq.com', 'foxmail.com', '163.com',
    '126.com', 'sina.com', 'sohu.com', 'naver.com', 'rediffmail.com',
    'kssmi.com',
];

function kssmi_sender_blocklist_path(): string {
    return dirname(__DIR__) . '/email_data/sender-blocklist.json';
}

function kssmi_sender_blocklist_hits_path(): string {
    return dirname(__DIR__) . '/email_data/sender-blocklist-hits.json';
}

/** Cut to at most $max characters of valid UTF-8 and drop control characters. */
function kssmi_sender_blocklist_clip($value, int $max): string {
    $text = is_scalar($value) ? (string)$value : '';
    if (function_exists('mb_scrub')) {
        $text = mb_scrub($text, 'UTF-8');
    } elseif (preg_match('//u', $text) !== 1) {
        $text = (string)iconv('UTF-8', 'UTF-8//IGNORE', $text);
    }
    $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
    return preg_match('/^.{0,' . $max . '}/us', $text, $match) === 1 ? $match[0] : '';
}

function kssmi_sender_blocklist_normalize_domain($domain): ?string {
    $domain = strtolower(trim(is_string($domain) ? $domain : ''));
    $domain = rtrim($domain, '.');
    if ($domain === '' || strlen($domain) > 253) return null;
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    return preg_match('/^(?:' . $label . '\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $domain) === 1 ? $domain : null;
}

/**
 * Canonical form used for matching. Gmail ignores dots and "+tag" in the
 * local part, so those variants collapse to one address; other providers are
 * matched exactly (case-insensitively), because "+" or dots can be
 * significant there.
 */
function kssmi_sender_blocklist_normalize_email($email): ?string {
    $email = strtolower(trim(is_string($email) ? $email : ''));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return null;
    $at = strrpos($email, '@');
    $local = substr($email, 0, $at);
    $domain = kssmi_sender_blocklist_normalize_domain(substr($email, $at + 1));
    if ($local === '' || $domain === null) return null;
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $plus = strpos($local, '+');
        if ($plus !== false) $local = substr($local, 0, $plus);
        $local = str_replace('.', '', $local);
        if ($local === '') return null;
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

function kssmi_sender_blocklist_is_public_domain(string $domain): bool {
    return in_array($domain, KSSMI_SENDER_BLOCKLIST_PUBLIC_DOMAINS, true);
}

/**
 * Turn admin input into a rule: "name@example.com" is an address rule;
 * "@example.com" or "example.com" is a domain rule.
 */
function kssmi_sender_blocklist_parse_input($input, bool $includeSubdomains): array {
    $raw = strtolower(trim(is_string($input) ? $input : ''));
    if ($raw === '') return ['ok' => false, 'error' => 'Enter an email address or @domain.'];
    if (strlen($raw) > 254) return ['ok' => false, 'error' => 'The value is too long.'];

    $at = strrpos($raw, '@');
    if ($at !== false && $at > 0) {
        $value = kssmi_sender_blocklist_normalize_email($raw);
        if ($value === null) return ['ok' => false, 'error' => 'That is not a valid email address.'];
        return ['ok' => true, 'type' => 'email', 'value' => $value, 'label' => $raw, 'include_subdomains' => false];
    }

    $domain = kssmi_sender_blocklist_normalize_domain($at === 0 ? substr($raw, 1) : $raw);
    if ($domain === null) return ['ok' => false, 'error' => 'That is not a valid domain.'];
    if ($domain === 'googlemail.com') $domain = 'gmail.com';
    return ['ok' => true, 'type' => 'domain', 'value' => $domain, 'label' => '@' . $domain, 'include_subdomains' => $includeSubdomains];
}

function kssmi_sender_blocklist_valid_rule($rule): bool {
    if (!is_array($rule)) return false;
    if (!is_string($rule['id'] ?? null) || preg_match('/^[a-f0-9]{16}$/', $rule['id']) !== 1) return false;
    if (!is_string($rule['value'] ?? null) || !is_bool($rule['include_subdomains'] ?? null)) return false;
    foreach (['label', 'note', 'created_at'] as $key) {
        if (!is_string($rule[$key] ?? null)) return false;
    }
    if (strlen($rule['label']) > 255 || $rule['note'] !== kssmi_sender_blocklist_clip($rule['note'], KSSMI_SENDER_BLOCKLIST_NOTE_MAX)) return false;
    // The value must already be in the canonical form the matcher compares,
    // or the rule would silently never match.
    if (($rule['type'] ?? null) === 'email') {
        return $rule['include_subdomains'] === false && kssmi_sender_blocklist_normalize_email($rule['value']) === $rule['value'];
    }
    if (($rule['type'] ?? null) === 'domain') {
        return $rule['value'] !== 'googlemail.com' && kssmi_sender_blocklist_normalize_domain($rule['value']) === $rule['value'];
    }
    return false;
}

/** Strict decode: anything unexpected is an error, never an empty list. */
function kssmi_sender_blocklist_decode($raw, &$error): ?array {
    $error = null;
    if (!is_string($raw) || trim($raw) === '') {
        $error = 'empty_existing_file';
        return null;
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'invalid_json';
        return null;
    }
    if (!is_array($data) || ($data['version'] ?? null) !== 1
        || !is_array($data['rules'] ?? null) || !kssmi_email_logs_array_is_list($data['rules'])) {
        $error = 'invalid_schema';
        return null;
    }
    if (count($data['rules']) > KSSMI_SENDER_BLOCKLIST_MAX_RULES) {
        $error = 'too_many_rules';
        return null;
    }
    $ids = [];
    $values = [];
    foreach ($data['rules'] as $rule) {
        if (!kssmi_sender_blocklist_valid_rule($rule)) {
            $error = 'invalid_rule';
            return null;
        }
        $key = $rule['type'] . ':' . $rule['value'];
        if (isset($ids[$rule['id']]) || isset($values[$key])) {
            $error = isset($ids[$rule['id']]) ? 'duplicate_rule_id' : 'duplicate_rule';
            return null;
        }
        $ids[$rule['id']] = true;
        $values[$key] = true;
    }
    return $data['rules'];
}

function kssmi_sender_blocklist_encode(array $rules): string {
    return json_encode(['version' => 1, 'rules' => array_values($rules)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Read the rules under a shared lock. A missing file is an empty list. */
function kssmi_sender_blocklist_load(?string $path = null): array {
    $path = $path ?? kssmi_sender_blocklist_path();
    if (!file_exists($path) && !is_link($path)) return ['ok' => true, 'rules' => [], 'error' => null];

    $lock = kssmi_email_logs_lock($path, LOCK_SH);
    if (!$lock['ok']) return ['ok' => false, 'rules' => [], 'error' => $lock['error']];
    try {
        if (!file_exists($path)) return ['ok' => true, 'rules' => [], 'error' => null];
        $raw = @file_get_contents($path);
        if ($raw === false) return ['ok' => false, 'rules' => [], 'error' => 'read_failed'];
        $decodeError = null;
        $rules = kssmi_sender_blocklist_decode($raw, $decodeError);
        if ($decodeError !== null) return ['ok' => false, 'rules' => [], 'error' => $decodeError];
        return ['ok' => true, 'rules' => $rules, 'error' => null];
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

/**
 * Change the rules under an exclusive lock. The mutator receives the current
 * rules and returns [newRules|null, result]; null means "do not write". An
 * unreadable or corrupt file is left untouched.
 */
function kssmi_sender_blocklist_mutate(string $path, callable $mutator): array {
    $lock = kssmi_email_logs_lock($path, LOCK_EX);
    if (!$lock['ok']) return ['ok' => false, 'error' => $lock['error'], 'result' => null];
    try {
        kssmi_email_logs_cleanup_temp_files($path);
        $rules = [];
        if (file_exists($path) || is_link($path)) {
            $raw = @file_get_contents($path);
            if ($raw === false) return ['ok' => false, 'error' => 'read_failed', 'result' => null];
            $decodeError = null;
            $rules = kssmi_sender_blocklist_decode($raw, $decodeError);
            if ($decodeError !== null) return ['ok' => false, 'error' => $decodeError, 'result' => null];
        }
        [$newRules, $result] = $mutator($rules);
        if ($newRules !== null && !kssmi_email_logs_atomic_write($path, kssmi_sender_blocklist_encode($newRules), 0640)) {
            return ['ok' => false, 'error' => 'write_failed', 'result' => null];
        }
        return ['ok' => true, 'error' => null, 'result' => $result];
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

/**
 * Status: added | updated | exists | invalid | public_domain | full | unavailable.
 * Adding a domain that is already listed with the other subdomain setting
 * changes that setting ("updated") instead of reporting a duplicate.
 */
function kssmi_sender_blocklist_add($input, bool $includeSubdomains, $note, ?string $path = null, ?int $now = null): array {
    $parsed = kssmi_sender_blocklist_parse_input($input, $includeSubdomains);
    if (!$parsed['ok']) return ['status' => 'invalid', 'message' => $parsed['error']];
    if ($parsed['type'] === 'domain' && kssmi_sender_blocklist_is_public_domain($parsed['value'])) {
        return [
            'status' => 'public_domain',
            'message' => $parsed['label'] . ' is shared by many customers and cannot be blocked as a whole. Enter the full email address instead.',
        ];
    }

    $rule = [
        'id' => bin2hex(random_bytes(8)),
        'type' => $parsed['type'],
        'value' => $parsed['value'],
        'label' => $parsed['label'],
        'include_subdomains' => $parsed['include_subdomains'],
        'note' => kssmi_sender_blocklist_clip($note, KSSMI_SENDER_BLOCKLIST_NOTE_MAX),
        'created_at' => date('Y-m-d H:i:s', $now ?? time()),
    ];
    $mutation = kssmi_sender_blocklist_mutate($path ?? kssmi_sender_blocklist_path(), function (array $rules) use ($rule) {
        foreach ($rules as $index => $existing) {
            if ($existing['type'] !== $rule['type'] || $existing['value'] !== $rule['value']) continue;
            if ($existing['include_subdomains'] === $rule['include_subdomains']) return [null, 'exists'];
            $rules[$index]['include_subdomains'] = $rule['include_subdomains'];
            return [$rules, 'updated'];
        }
        if (count($rules) >= KSSMI_SENDER_BLOCKLIST_MAX_RULES) return [null, 'full'];
        $rules[] = $rule;
        return [$rules, 'added'];
    });
    if (!$mutation['ok']) {
        error_log('KSSMI sender blocklist: add refused, rules file unavailable: ' . $mutation['error']);
        return ['status' => 'unavailable', 'message' => 'The blocklist file could not be read or written safely, so nothing was changed. Check the server error log.'];
    }
    $messages = [
        'added' => 'Blocked ' . $rule['label'] . '.',
        'updated' => 'Updated ' . $rule['label'] . ': ' . ($rule['include_subdomains'] ? 'now also blocks its subdomains.' : 'now blocks only the domain itself, not its subdomains.'),
        'exists' => $rule['label'] . ' is already on the blocklist with these settings.',
        'full' => 'The blocklist is full (' . KSSMI_SENDER_BLOCKLIST_MAX_RULES . ' rules). Remove old rules first.',
    ];
    return ['status' => $mutation['result'], 'message' => $messages[$mutation['result']], 'rule' => $rule];
}

/** Status: removed | not_found | unavailable. */
function kssmi_sender_blocklist_remove($id, ?string $path = null): array {
    $id = is_string($id) ? $id : '';
    $removed = null;
    $mutation = kssmi_sender_blocklist_mutate($path ?? kssmi_sender_blocklist_path(), function (array $rules) use ($id, &$removed) {
        foreach ($rules as $index => $rule) {
            if (hash_equals($rule['id'], $id)) {
                $removed = $rule;
                unset($rules[$index]);
                return [array_values($rules), 'removed'];
            }
        }
        return [null, 'not_found'];
    });
    if (!$mutation['ok']) {
        error_log('KSSMI sender blocklist: remove refused, rules file unavailable: ' . $mutation['error']);
        return ['status' => 'unavailable', 'message' => 'The blocklist file could not be read or written safely, so nothing was changed. Check the server error log.'];
    }
    if ($mutation['result'] === 'not_found') return ['status' => 'not_found', 'message' => 'That rule no longer exists.'];
    return ['status' => 'removed', 'message' => 'Unblocked ' . $removed['label'] . '.', 'rule' => $removed];
}

/** Edit a domain rule's subdomain setting. Status: updated | not_found | unavailable. */
function kssmi_sender_blocklist_set_subdomains($id, bool $includeSubdomains, ?string $path = null): array {
    $id = is_string($id) ? $id : '';
    $changed = null;
    $mutation = kssmi_sender_blocklist_mutate($path ?? kssmi_sender_blocklist_path(), function (array $rules) use ($id, $includeSubdomains, &$changed) {
        foreach ($rules as $index => $rule) {
            if ($rule['type'] !== 'domain' || !hash_equals($rule['id'], $id)) continue;
            $changed = $rule;
            if ($rule['include_subdomains'] === $includeSubdomains) return [null, 'updated'];
            $rules[$index]['include_subdomains'] = $includeSubdomains;
            return [$rules, 'updated'];
        }
        return [null, 'not_found'];
    });
    if (!$mutation['ok']) {
        error_log('KSSMI sender blocklist: edit refused, rules file unavailable: ' . $mutation['error']);
        return ['status' => 'unavailable', 'message' => 'The blocklist file could not be read or written safely, so nothing was changed. Check the server error log.'];
    }
    if ($mutation['result'] === 'not_found') return ['status' => 'not_found', 'message' => 'That domain rule no longer exists.'];
    return ['status' => 'updated', 'message' => 'Updated ' . $changed['label'] . ': ' . ($includeSubdomains ? 'now also blocks its subdomains.' : 'now blocks only the domain itself, not its subdomains.')];
}

/** Return the first rule matching $email, or null. */
function kssmi_sender_blocklist_match(array $rules, $email): ?array {
    $normalized = kssmi_sender_blocklist_normalize_email($email);
    if ($normalized === null) return null;
    $domain = substr($normalized, strrpos($normalized, '@') + 1);
    foreach ($rules as $rule) {
        if ($rule['type'] === 'email' && $rule['value'] === $normalized) return $rule;
        if ($rule['type'] === 'domain') {
            if ($domain === $rule['value']) return $rule;
            if ($rule['include_subdomains'] && substr($domain, -strlen('.' . $rule['value'])) === '.' . $rule['value']) return $rule;
        }
    }
    return null;
}

/**
 * Decision for one submission: clear | blocked | unavailable. The caller
 * treats "unavailable" as clear (fail open) after this has logged it.
 */
function kssmi_sender_blocklist_check($email, ?string $path = null): array {
    $loaded = kssmi_sender_blocklist_load($path);
    if (!$loaded['ok']) {
        error_log('KSSMI sender blocklist UNAVAILABLE: ' . $loaded['error'] . '; inquiry accepted without the blocklist check');
        return ['state' => 'unavailable', 'rule' => null, 'error' => $loaded['error']];
    }
    $rule = kssmi_sender_blocklist_match($loaded['rules'], $email);
    return ['state' => $rule === null ? 'clear' : 'blocked', 'rule' => $rule, 'error' => null];
}

function kssmi_sender_blocklist_empty_hits(): array {
    return ['version' => 1, 'hits' => [], 'rule_stats' => []];
}

function kssmi_sender_blocklist_decode_hits($raw, &$error): ?array {
    $error = null;
    $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($data) || ($data['version'] ?? null) !== 1
        || !is_array($data['hits'] ?? null) || !kssmi_email_logs_array_is_list($data['hits'])
        || !is_array($data['rule_stats'] ?? null)) {
        $error = 'invalid_hits_file';
        return null;
    }
    foreach ($data['hits'] as $hit) {
        if (!is_array($hit) || !is_int($hit['unix'] ?? null)) {
            $error = 'invalid_hits_file';
            return null;
        }
    }
    return $data;
}

/** Drop hits older than the retention window, then keep the newest N. */
function kssmi_sender_blocklist_prune_hits(array $hits, int $now): array {
    $cutoff = $now - KSSMI_SENDER_BLOCKLIST_HIT_DAYS * 86400;
    $hits = array_values(array_filter($hits, static fn(array $hit): bool => $hit['unix'] >= $cutoff));
    usort($hits, static fn(array $a, array $b): int => $b['unix'] <=> $a['unix']);
    return array_slice($hits, 0, KSSMI_SENDER_BLOCKLIST_MAX_HITS);
}

/**
 * Record one blocked submission. A corrupt hits file is kept aside as
 * .corrupt-* and recording starts over: hits are a review aid, the rules are
 * the authority. Returns false if nothing could be written.
 */
function kssmi_sender_blocklist_record_hit(array $rule, array $submission, ?string $path = null, ?int $now = null): bool {
    $path = $path ?? kssmi_sender_blocklist_hits_path();
    $now = $now ?? time();
    $lock = kssmi_email_logs_lock($path, LOCK_EX);
    if (!$lock['ok']) {
        error_log('KSSMI sender blocklist: blocked hit not recorded: ' . $lock['error']);
        return false;
    }
    try {
        kssmi_email_logs_cleanup_temp_files($path);
        $data = kssmi_sender_blocklist_empty_hits();
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            $decodeError = null;
            $decoded = $raw === false ? null : kssmi_sender_blocklist_decode_hits($raw, $decodeError);
            if ($decoded !== null) {
                $data = $decoded;
            } else {
                $backup = $raw === false ? false : kssmi_email_logs_preserve_corrupt($path, $raw);
                error_log('KSSMI sender blocklist: hits file unreadable (' . ($raw === false ? 'read_failed' : $decodeError) . '); '
                    . ($backup === false ? 'could not keep a copy; ' : 'kept as ' . basename($backup) . '; ') . 'starting a new record');
            }
        }

        $files = (int)($submission['attachments'] ?? 0);
        array_unshift($data['hits'], [
            'unix' => $now,
            'time' => date('Y-m-d H:i:s', $now),
            'rule_id' => $rule['id'],
            'rule' => $rule['label'],
            'email' => kssmi_sender_blocklist_clip($submission['email'] ?? '', 254),
            'name' => kssmi_sender_blocklist_clip($submission['name'] ?? '', 120),
            'ip' => kssmi_sender_blocklist_clip($submission['ip'] ?? '', 64),
            'country' => kssmi_sender_blocklist_clip($submission['country'] ?? '', 8),
            'page' => kssmi_sender_blocklist_clip($submission['page'] ?? '', 256),
            'details' => kssmi_sender_blocklist_clip($submission['details'] ?? '', KSSMI_SENDER_BLOCKLIST_DETAILS_MAX),
            'attachments' => max(0, min(99, $files)),
        ]);
        $data['hits'] = kssmi_sender_blocklist_prune_hits($data['hits'], $now);
        $stats = is_array($data['rule_stats'][$rule['id']] ?? null) ? $data['rule_stats'][$rule['id']] : [];
        $data['rule_stats'][$rule['id']] = [
            'count' => (int)($stats['count'] ?? 0) + 1,
            'last_at' => date('Y-m-d H:i:s', $now),
        ];
        // Stats of removed rules are never read again; keep the map bounded.
        if (count($data['rule_stats']) > KSSMI_SENDER_BLOCKLIST_MAX_RULES * 2) {
            uasort($data['rule_stats'], static fn($a, $b): int => strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? '')));
            $data['rule_stats'] = array_slice($data['rule_stats'], 0, KSSMI_SENDER_BLOCKLIST_MAX_RULES * 2, true);
        }

        $written = kssmi_email_logs_atomic_write($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0640);
        if (!$written) error_log('KSSMI sender blocklist: blocked hit not recorded: write_failed');
        return $written;
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

/** Read hits for display; never writes. */
function kssmi_sender_blocklist_read_hits(?string $path = null, ?int $now = null): array {
    $path = $path ?? kssmi_sender_blocklist_hits_path();
    $result = ['ok' => true, 'hits' => [], 'rule_stats' => [], 'error' => null,
        'recovered' => glob($path . '.corrupt-*') ? true : false];
    if (!file_exists($path)) return $result;
    $lock = kssmi_email_logs_lock($path, LOCK_SH);
    if (!$lock['ok']) return array_merge($result, ['ok' => false, 'error' => $lock['error']]);
    try {
        $raw = @file_get_contents($path);
        $decodeError = null;
        $data = $raw === false ? null : kssmi_sender_blocklist_decode_hits($raw, $decodeError);
        if ($data === null) return array_merge($result, ['ok' => false, 'error' => $raw === false ? 'read_failed' : $decodeError]);
        $result['hits'] = kssmi_sender_blocklist_prune_hits($data['hits'], $now ?? time());
        $result['rule_stats'] = $data['rule_stats'];
        return $result;
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}

/**
 * Enforce the retention window on disk, not only on display. Run by the
 * outbox worker (cron, once a minute) and on every Email Logs page view:
 *   - hits older than 30 days are removed from the hits file;
 *   - .corrupt-* copies of a damaged hits file are deleted after 30 days
 *     (at most 5 are ever kept, see kssmi_email_logs_preserve_corrupt);
 *   - a damaged hits file untouched for 30 days holds only expired data and
 *     is deleted.
 * Returns counts of what was removed; never throws.
 */
function kssmi_sender_blocklist_purge_expired(?string $path = null, ?int $now = null): array {
    $path = $path ?? kssmi_sender_blocklist_hits_path();
    $now = $now ?? time();
    $cutoff = $now - KSSMI_SENDER_BLOCKLIST_HIT_DAYS * 86400;
    $summary = ['ok' => true, 'removed_hits' => 0, 'removed_backups' => 0, 'removed_damaged' => false];

    foreach (glob($path . '.corrupt-*') ?: [] as $backup) {
        if (strpos(basename($backup), '.tmp-') !== false) continue;
        $mtime = @filemtime($backup);
        if ($mtime !== false && $mtime < $cutoff && @unlink($backup)) $summary['removed_backups']++;
    }
    if (!file_exists($path)) return $summary;

    $lock = kssmi_email_logs_lock($path, LOCK_EX);
    if (!$lock['ok']) {
        error_log('KSSMI sender blocklist: retention purge skipped: ' . $lock['error']);
        return array_merge($summary, ['ok' => false]);
    }
    try {
        kssmi_email_logs_cleanup_temp_files($path);
        $raw = @file_get_contents($path);
        $decodeError = null;
        $data = $raw === false ? null : kssmi_sender_blocklist_decode_hits($raw, $decodeError);
        if ($data === null) {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $summary['removed_damaged'] = true;
                error_log('KSSMI sender blocklist: deleted a damaged hits file older than the retention window');
            }
            return $summary;
        }
        $kept = kssmi_sender_blocklist_prune_hits($data['hits'], $now);
        $summary['removed_hits'] = count($data['hits']) - count($kept);
        if ($summary['removed_hits'] === 0) return $summary;
        $data['hits'] = $kept;
        if (!kssmi_email_logs_atomic_write($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0640)) {
            error_log('KSSMI sender blocklist: retention purge could not rewrite the hits file');
            return array_merge($summary, ['ok' => false, 'removed_hits' => 0]);
        }
        return $summary;
    } finally {
        kssmi_email_logs_unlock($lock);
    }
}
