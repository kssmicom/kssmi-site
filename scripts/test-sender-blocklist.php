<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/private/sender-blocklist.php';

// Worker mode for the concurrency cases: wait for the gate, then do one write.
if (in_array($argv[1] ?? '', ['--add-worker', '--hit-worker'], true)) {
    [, $mode, $target, $gate, $index] = $argv;
    $deadline = microtime(true) + 10;
    while (!file_exists($gate) && microtime(true) < $deadline) usleep(1000);
    if (!file_exists($gate)) exit(2);
    if ($mode === '--add-worker') {
        $outcome = kssmi_sender_blocklist_add("worker{$index}@example.com", false, '', $target);
        exit($outcome['status'] === 'added' ? 0 : 3);
    }
    $rule = ['id' => str_repeat('a', 16), 'label' => 'x@example.com'];
    exit(kssmi_sender_blocklist_record_hit($rule, ['email' => "hit{$index}@example.com"], $target) ? 0 : 3);
}

function sb_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function sb_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? sb_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

function sb_parallel(string $mode, string $target, string $dir, int $count): int {
    $gate = $dir . '/gate-' . $mode . '-' . bin2hex(random_bytes(3));
    $processes = [];
    for ($i = 0; $i < $count; $i++) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false && extension_loaded('mbstring')) {
            array_push($command, '-d', 'extension_dir=' . (string)ini_get('extension_dir'), '-d', 'extension=mbstring');
        }
        array_push($command, __FILE__, $mode, $target, $gate, (string)$i);
        $processes[] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
    touch($gate);
    $ok = 0;
    foreach ($processes as $process) {
        if (is_resource($process) && proc_close($process) === 0) $ok++;
    }
    return $ok;
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-blocklist-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
ini_set('error_log', $dir . '/php-error.log');   // expected failure messages stay out of the output
$rules = $dir . '/sender-blocklist.json';
$hitsPath = $dir . '/sender-blocklist-hits.json';

try {
    // Normalization: Gmail ignores dots and +tags; other providers are exact.
    sb_assert(kssmi_sender_blocklist_normalize_email(' Patel.Amisha2706+promo@GoogleMail.com ') === 'patelamisha2706@gmail.com', 'Gmail variants must collapse to one address.');
    sb_assert(kssmi_sender_blocklist_normalize_email('john.doe+x@example.com') === 'john.doe+x@example.com', 'Non-Gmail addresses must keep dots and +tags.');
    sb_assert(kssmi_sender_blocklist_normalize_email('not-an-email') === null, 'Invalid addresses must not normalize.');
    sb_assert(kssmi_sender_blocklist_normalize_email('+tag@gmail.com') === null, 'An empty Gmail local part must be rejected.');

    // Input parsing.
    $parsed = kssmi_sender_blocklist_parse_input('@Example.COM.', true);
    sb_assert($parsed['ok'] && $parsed['type'] === 'domain' && $parsed['value'] === 'example.com' && $parsed['include_subdomains'] === true, '@domain must become a domain rule.');
    $parsed = kssmi_sender_blocklist_parse_input('example.org', false);
    sb_assert($parsed['ok'] && $parsed['type'] === 'domain' && $parsed['label'] === '@example.org', 'A bare domain must become a domain rule.');
    foreach (['', 'a@', '@', '@-bad-.com', '@localhost', str_repeat('a', 250) . '@x.com', 'two@@example.com'] as $bad) {
        sb_assert(!kssmi_sender_blocklist_parse_input($bad, false)['ok'], 'Invalid input must be refused: ' . $bad);
    }

    // Add: validation, public domains refused, duplicates, note clipping.
    sb_assert(kssmi_sender_blocklist_add('nope', false, '', $rules)['status'] === 'invalid', 'Invalid input must not be added.');
    sb_assert(!file_exists($rules), 'A refused add must not create the file.');
    foreach (['@gmail.com', '@GoogleMail.com', 'outlook.com', '@kssmi.com'] as $public) {
        $refused = kssmi_sender_blocklist_add($public, true, '', $rules);
        sb_assert($refused['status'] === 'public_domain' && str_contains($refused['message'], 'full email address') && !file_exists($rules), 'A public or own mail domain must be refused as a whole: ' . $public);
    }
    $added = kssmi_sender_blocklist_add('patelamisha2706@gmail.com', false, str_repeat('é', 300), $rules);
    sb_assert($added['status'] === 'added' && mb_strlen($added['rule']['note'], 'UTF-8') === KSSMI_SENDER_BLOCKLIST_NOTE_MAX, 'The address must be added with a clipped note.');
    sb_assert(kssmi_sender_blocklist_add('Patel.Amisha2706@gmail.com', false, '', $rules)['status'] === 'exists', 'A Gmail variant of an existing rule must be reported as existing.');
    sb_assert(kssmi_sender_blocklist_add('@spam.example', true, 'whole domain', $rules)['status'] === 'added', 'A domain rule must be added.');
    sb_assert(kssmi_sender_blocklist_add('@exact.example', false, '', $rules)['status'] === 'added', 'An exact domain rule must be added.');
    sb_assert(kssmi_sender_blocklist_add('someone@outlook.com', false, '', $rules)['status'] === 'added' && kssmi_sender_blocklist_remove(kssmi_sender_blocklist_load($rules)['rules'][3]['id'], $rules)['status'] === 'removed', 'A single address at a public domain can still be blocked.');
    $stat = stat($rules);
    sb_assert(DIRECTORY_SEPARATOR === '\\' || ($stat['mode'] & 0777) === 0640, 'The rules file must be 0640.');

    // Matching.
    $loaded = kssmi_sender_blocklist_load($rules);
    sb_assert($loaded['ok'] && count($loaded['rules']) === 3, 'Three rules must be stored.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'p.a.t.e.l.amisha2706+x@googlemail.com')['label'] === 'patelamisha2706@gmail.com', 'Gmail variants must match the address rule.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'someone@SPAM.example')['type'] === 'domain', 'The domain must match case-insensitively.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'someone@mx.spam.example') !== null, 'Subdomains must match when included.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'someone@notspam.example') === null, 'A domain suffix that is not a subdomain must not match.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'someone@mx.exact.example') === null, 'Subdomains must not match unless included.');
    sb_assert(kssmi_sender_blocklist_match($loaded['rules'], 'customer@gmail.com') === null, 'Other Gmail users must not match.');
    sb_assert(kssmi_sender_blocklist_check('customer@example.net', $rules)['state'] === 'clear', 'An unlisted sender must be clear.');
    sb_assert(kssmi_sender_blocklist_check('patelamisha2706@gmail.com', $rules)['state'] === 'blocked', 'A listed sender must be blocked.');
    sb_assert(kssmi_sender_blocklist_check('x@example.net', $dir . '/missing.json')['state'] === 'clear', 'A missing rules file must mean an empty list.');

    // Remove.
    $removed = kssmi_sender_blocklist_remove($added['rule']['id'], $rules);
    sb_assert($removed['status'] === 'removed' && kssmi_sender_blocklist_check('patelamisha2706@gmail.com', $rules)['state'] === 'clear', 'Unblocking must take effect.');
    sb_assert(kssmi_sender_blocklist_remove($added['rule']['id'], $rules)['status'] === 'not_found', 'Removing twice must report not found.');
    sb_assert(kssmi_sender_blocklist_remove(['array'], $rules)['status'] === 'not_found', 'A non-string id must not match.');

    // Damaged rules file: check fails open (unavailable), admin writes are
    // refused and the file is left byte for byte.
    foreach (['', 'not json', '{"version":2,"rules":[]}', '{"version":1,"rules":{"a":1}}', '{"version":1,"rules":[{"id":"x"}]}'] as $damaged) {
        file_put_contents($rules, $damaged);
        $check = kssmi_sender_blocklist_check('patelamisha2706@gmail.com', $rules);
        sb_assert($check['state'] === 'unavailable' && $check['error'] !== null, 'A damaged rules file must be reported unavailable: ' . $damaged);
        sb_assert(kssmi_sender_blocklist_add('new@example.com', false, '', $rules)['status'] === 'unavailable', 'Add must refuse a damaged file.');
        sb_assert(kssmi_sender_blocklist_remove(str_repeat('a', 16), $rules)['status'] === 'unavailable', 'Remove must refuse a damaged file.');
        sb_assert(file_get_contents($rules) === $damaged, 'A damaged rules file must not be overwritten.');
    }
    unlink($rules);

    // A rules file that parses but holds values the matcher could never use
    // (or duplicates, or too many rules) is damaged too, not a valid list.
    $good = ['id' => str_repeat('c', 16), 'type' => 'email', 'value' => 'spam@example.com', 'label' => 'spam@example.com', 'include_subdomains' => false, 'note' => '', 'created_at' => ''];
    $tooMany = [];
    for ($i = 0; $i <= KSSMI_SENDER_BLOCKLIST_MAX_RULES; $i++) {
        $tooMany[] = array_merge($good, ['id' => sprintf('%016x', $i), 'value' => "t{$i}@example.com", 'label' => "t{$i}@example.com"]);
    }
    $structurallyBad = [
        'value not an email' => [array_merge($good, ['value' => 'NOT AN EMAIL'])],
        'email not lower-case' => [array_merge($good, ['value' => 'Spam@Example.com'])],
        'gmail not normalized' => [array_merge($good, ['value' => 'p.atel+x@gmail.com'])],
        'email with subdomain flag' => [array_merge($good, ['include_subdomains' => true])],
        'domain not normalized' => [array_merge($good, ['type' => 'domain', 'value' => 'Example.com.'])],
        'domain not a domain' => [array_merge($good, ['type' => 'domain', 'value' => 'not a domain'])],
        'googlemail domain' => [array_merge($good, ['type' => 'domain', 'value' => 'googlemail.com'])],
        'unknown type' => [array_merge($good, ['type' => 'ip'])],
        'note too long' => [array_merge($good, ['note' => str_repeat('n', KSSMI_SENDER_BLOCKLIST_NOTE_MAX + 1)])],
        'duplicate id' => [$good, array_merge($good, ['value' => 'other@example.com'])],
        'duplicate rule' => [$good, array_merge($good, ['id' => str_repeat('d', 16)])],
        'too many rules' => $tooMany,
    ];
    $expectedErrors = ['duplicate id' => 'duplicate_rule_id', 'duplicate rule' => 'duplicate_rule', 'too many rules' => 'too_many_rules'];
    foreach ($structurallyBad as $case => $list) {
        $raw = kssmi_sender_blocklist_encode($list);
        file_put_contents($rules, $raw);
        $check = kssmi_sender_blocklist_check('spam@example.com', $rules);
        sb_assert($check['state'] === 'unavailable' && $check['error'] === ($expectedErrors[$case] ?? 'invalid_rule'), "Rules file must be rejected ({$case}): " . json_encode($check));
        sb_assert(kssmi_sender_blocklist_add('new@example.com', false, '', $rules)['status'] === 'unavailable' && file_get_contents($rules) === $raw, "A rejected rules file must not be rewritten ({$case}).");
    }
    file_put_contents($rules, kssmi_sender_blocklist_encode([$good]));
    sb_assert(kssmi_sender_blocklist_check('spam@example.com', $rules)['state'] === 'blocked', 'A well-formed single rule must load.');
    unlink($rules);

    // Changing a domain rule's subdomain setting: by adding again or by edit.
    $domain = kssmi_sender_blocklist_add('@edit.example', false, '', $rules);
    sb_assert(kssmi_sender_blocklist_check('a@mx.edit.example', $rules)['state'] === 'clear', 'An exact domain rule must not match subdomains.');
    $again = kssmi_sender_blocklist_add('@edit.example', true, '', $rules);
    sb_assert($again['status'] === 'updated' && str_contains($again['message'], 'subdomains') && count(kssmi_sender_blocklist_load($rules)['rules']) === 1, 'Adding the domain with subdomains must update the rule, not duplicate it.');
    sb_assert(kssmi_sender_blocklist_check('a@mx.edit.example', $rules)['state'] === 'blocked', 'The updated rule must match subdomains.');
    sb_assert(kssmi_sender_blocklist_add('@edit.example', true, '', $rules)['status'] === 'exists', 'The same setting again must report exists.');
    sb_assert(kssmi_sender_blocklist_set_subdomains($domain['rule']['id'], false, $rules)['status'] === 'updated' && kssmi_sender_blocklist_check('a@mx.edit.example', $rules)['state'] === 'clear', 'The edit must switch subdomains off.');
    $address = kssmi_sender_blocklist_add('only@edit.example', false, '', $rules);
    sb_assert(kssmi_sender_blocklist_set_subdomains($address['rule']['id'], true, $rules)['status'] === 'not_found', 'Address rules have no subdomain setting.');
    sb_assert(kssmi_sender_blocklist_set_subdomains(str_repeat('f', 16), true, $rules)['status'] === 'not_found', 'Editing a missing rule must report not found.');
    unlink($rules);

    // Rule cap.
    $many = [];
    for ($i = 0; $i < KSSMI_SENDER_BLOCKLIST_MAX_RULES; $i++) {
        $many[] = ['id' => sprintf('%016x', $i), 'type' => 'email', 'value' => "u{$i}@example.com", 'label' => "u{$i}@example.com", 'include_subdomains' => false, 'note' => '', 'created_at' => ''];
    }
    file_put_contents($rules, kssmi_sender_blocklist_encode($many));
    sb_assert(kssmi_sender_blocklist_add('one-more@example.com', false, '', $rules)['status'] === 'full', 'The rule cap must be enforced.');
    unlink($rules);

    // Hits: clipping, count only for attachments, per-rule stats.
    $rule = ['id' => 'b' . str_repeat('0', 15), 'label' => 'spam@example.com'];
    $now = 1_800_000_000;
    sb_assert(kssmi_sender_blocklist_record_hit($rule, [
        'email' => 'spam@example.com', 'name' => str_repeat('N', 500), 'ip' => '203.0.113.9', 'country' => 'IN',
        'page' => '/products/x/', 'details' => str_repeat('中', 2000) . "\x00\x07", 'attachments' => 3,
    ], $hitsPath, $now), 'A hit must be recorded.');
    $read = kssmi_sender_blocklist_read_hits($hitsPath, $now);
    $hit = $read['hits'][0];
    sb_assert($read['ok'] && mb_strlen($hit['details'], 'UTF-8') === KSSMI_SENDER_BLOCKLIST_DETAILS_MAX && mb_strlen($hit['name'], 'UTF-8') === 120, 'Hit fields must be clipped.');
    sb_assert($hit['attachments'] === 3 && count($hit) === 11, 'A hit must keep only its fixed fields (attachment count, no files).');
    sb_assert($read['rule_stats'][$rule['id']]['count'] === 1, 'Per-rule stats must count the hit.');
    $invalidUtf8 = "bad \xC3\x28 bytes";
    kssmi_sender_blocklist_record_hit($rule, ['details' => $invalidUtf8], $hitsPath, $now + 1);
    sb_assert(preg_match('//u', kssmi_sender_blocklist_read_hits($hitsPath, $now + 1)['hits'][0]['details']) === 1, 'Stored text must be valid UTF-8.');

    // Retention: 30 days and 50 entries.
    for ($i = 0; $i < 60; $i++) {
        kssmi_sender_blocklist_record_hit($rule, ['email' => "n{$i}@example.com"], $hitsPath, $now + 10 + $i);
    }
    $read = kssmi_sender_blocklist_read_hits($hitsPath, $now + 100);
    sb_assert(count($read['hits']) === KSSMI_SENDER_BLOCKLIST_MAX_HITS && $read['hits'][0]['email'] === 'n59@example.com', 'Only the newest 50 hits must be kept.');
    sb_assert($read['rule_stats'][$rule['id']]['count'] === 62, 'Per-rule stats must keep counting beyond the hit cap.');
    $later = $now + 69 + KSSMI_SENDER_BLOCKLIST_HIT_DAYS * 86400 + 1;
    sb_assert(count(kssmi_sender_blocklist_read_hits($hitsPath, $later)['hits']) === 0, 'Hits older than 30 days must not be shown.');
    kssmi_sender_blocklist_record_hit($rule, ['email' => 'fresh@example.com'], $hitsPath, $later);
    $raw = json_decode((string)file_get_contents($hitsPath), true);
    sb_assert(count($raw['hits']) === 1 && $raw['hits'][0]['email'] === 'fresh@example.com', 'Expired hits must be removed from the file on the next write.');

    // Damaged hits file: kept aside, recording starts over, rules unaffected.
    file_put_contents($hitsPath, '{broken');
    $read = kssmi_sender_blocklist_read_hits($hitsPath, $later);
    sb_assert(!$read['ok'] && $read['error'] === 'invalid_hits_file', 'A damaged hits file must be reported.');
    sb_assert(kssmi_sender_blocklist_record_hit($rule, ['email' => 'after@example.com'], $hitsPath, $later + 1), 'Recording must recover from a damaged hits file.');
    $read = kssmi_sender_blocklist_read_hits($hitsPath, $later + 1);
    $backups = glob($hitsPath . '.corrupt-*') ?: [];
    sb_assert($read['ok'] && count($read['hits']) === 1 && $read['recovered'] && count($backups) === 1 && file_get_contents($backups[0]) === '{broken', 'The damaged file must be kept aside and a new record started.');

    // Retention on disk: the purge removes expired hits from the file itself,
    // deletes old .corrupt-* copies, and deletes a damaged file left 30 days.
    $purgeNow = 1_900_000_000;
    $day = 86400;
    file_put_contents($hitsPath, json_encode(['version' => 1, 'rule_stats' => [], 'hits' => [
        ['unix' => $purgeNow - 2 * $day, 'email' => 'recent@example.com'],
        ['unix' => $purgeNow - 31 * $day, 'email' => 'old@example.com'],
    ]]));
    foreach (glob($hitsPath . '.corrupt-*') ?: [] as $stale) unlink($stale);
    file_put_contents($hitsPath . '.corrupt-old', 'x');
    touch($hitsPath . '.corrupt-old', $purgeNow - 31 * $day);
    file_put_contents($hitsPath . '.corrupt-new', 'x');
    touch($hitsPath . '.corrupt-new', $purgeNow - 2 * $day);
    $purge = kssmi_sender_blocklist_purge_expired($hitsPath, $purgeNow);
    $onDisk = json_decode((string)file_get_contents($hitsPath), true);
    sb_assert($purge['ok'] && $purge['removed_hits'] === 1 && count($onDisk['hits']) === 1 && $onDisk['hits'][0]['email'] === 'recent@example.com', 'Expired hits must be removed from the file.');
    sb_assert($purge['removed_backups'] === 1 && !file_exists($hitsPath . '.corrupt-old') && file_exists($hitsPath . '.corrupt-new'), 'Only damaged-file copies older than 30 days must be deleted.');
    $before = file_get_contents($hitsPath);
    sb_assert(kssmi_sender_blocklist_purge_expired($hitsPath, $purgeNow)['removed_hits'] === 0 && file_get_contents($hitsPath) === $before, 'A purge with nothing expired must not rewrite the file.');
    file_put_contents($hitsPath, '{damaged');
    touch($hitsPath, $purgeNow - 2 * $day);
    sb_assert(!kssmi_sender_blocklist_purge_expired($hitsPath, $purgeNow)['removed_damaged'] && file_exists($hitsPath), 'A recently damaged hits file must be kept for review.');
    touch($hitsPath, $purgeNow - 31 * $day);
    sb_assert(kssmi_sender_blocklist_purge_expired($hitsPath, $purgeNow)['removed_damaged'] && !file_exists($hitsPath), 'A damaged hits file untouched for 30 days must be deleted.');
    unlink($hitsPath . '.corrupt-new');

    // Concurrency: parallel adds and hits lose nothing.
    sb_assert(sb_parallel('--add-worker', $rules, $dir, 8) === 8, 'A parallel add failed.');
    sb_assert(count(kssmi_sender_blocklist_load($rules)['rules']) === 8, 'Parallel adds lost a rule.');
    if (file_exists($hitsPath)) unlink($hitsPath);
    sb_assert(sb_parallel('--hit-worker', $hitsPath, $dir, 8) === 8, 'A parallel hit failed.');
    $read = kssmi_sender_blocklist_read_hits($hitsPath);
    sb_assert(count($read['hits']) === 8 && $read['rule_stats'][str_repeat('a', 16)]['count'] === 8, 'Parallel hits lost a record.');

    echo "Sender blocklist tests passed.\n";
} finally {
    sb_remove_tree($dir);
}
