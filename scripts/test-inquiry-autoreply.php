<?php
declare(strict_types=1);

// KS-04: inquiry auto-reply budgets and greeting. Uses the file rate-limit
// backend in a temporary directory; nothing is sent.

if (($argv[1] ?? '') === '--budget-worker') {
    putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
    putenv('KSSMI_RATE_LIMIT_DIR=' . $argv[2]);
    ini_set('error_log', $argv[2] . DIRECTORY_SEPARATOR . 'worker-error.log');
    require_once dirname(__DIR__) . '/private/inquiry-autoreply.php';
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . (int)$argv[4];
    $deadline = microtime(true) + 10;
    while (!file_exists($argv[3]) && microtime(true) < $deadline) usleep(1000);
    if (!file_exists($argv[3])) exit(2);
    echo kssmi_inquiry_autoreply_budget('target@example.com') ?? 'send';
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-inquiry-autoreply-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    fwrite(STDERR, "Inquiry auto-reply test failed: unable to create a temporary directory\n");
    exit(1);
}
putenv('KSSMI_FORCE_FILE_RATE_LIMIT=1');
putenv('KSSMI_RATE_LIMIT_DIR=' . $root);
ini_set('error_log', $root . DIRECTORY_SEPARATOR . 'php-error.log');
require_once dirname(__DIR__) . '/private/inquiry-autoreply.php';

function iar_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// The same escaping send-mail.php's sanitize() applies to form fields.
function iar_form_value(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function iar_use_dir(string $root, string $name): void {
    $dir = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Unable to create ' . $dir);
    putenv('KSSMI_RATE_LIMIT_DIR=' . $dir);
}

function iar_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? iar_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

try {
    // One budget identity per mailbox.
    foreach ([
        ['Victim@Example.com', 'victim@example.com'],
        ['victim+promo@example.com', 'victim@example.com'],
        ['V.i.c.t.i.m+x@GoogleMail.com', 'victim@gmail.com'],
        ['first.last@example.com', 'first.last@example.com'],
    ] as [$address, $key]) {
        iar_assert(kssmi_inquiry_autoreply_recipient_key($address) === $key, "Recipient key for {$address} must be {$key}.");
    }

    // Names that read as names are greeted; anything else gets the generic greeting.
    foreach (['Maria Garcia', "O'Brien", 'Jean-Pierre Dupont', '张伟', 'José Álvarez', 'محمد علي', 'Nguyễn Văn An', 'Dr. Smith', 'Anna Maria del Carmen López'] as $name) {
        iar_assert(kssmi_inquiry_autoreply_name(iar_form_value($name)) === $name, "The name {$name} must be accepted as plain text.");
    }
    foreach ([
        '',
        'Visit evil.com now',
        'http://evil.example',
        'Call 555 0100 today',
        'win@example.com',
        str_repeat('a', 61),
        'one two three four five six seven',
        '-Bob',
        "Bob\r\nBcc: someone@example.com",
        'Bob & Co',
    ] as $name) {
        iar_assert(kssmi_inquiry_autoreply_name(iar_form_value($name)) === '', 'The name ' . json_encode($name) . ' must be rejected.');
    }
    iar_assert(kssmi_inquiry_autoreply_name(null) === '' && kssmi_inquiry_autoreply_name(['x']) === '', 'A non-string name must be rejected.');

    $languages = ['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];
    foreach ($languages as $language) {
        $named = kssmi_inquiry_autoreply_build(['name' => iar_form_value("O'Brien"), 'language' => $language]);
        $greeting = strtok($named['body'], "\n");
        iar_assert(str_contains($greeting, "O'Brien") && $named['recipient_name'] === "O'Brien", "The {$language} greeting must use an accepted name as plain text.");
        $generic = kssmi_inquiry_autoreply_build(['name' => iar_form_value('Visit evil.com now'), 'language' => $language]);
        iar_assert($generic['recipient_name'] === '', "A rejected name must not become the {$language} display name.");
        iar_assert(!str_contains($generic['body'], 'evil') && !str_contains($generic['body'], '{name}') && !str_contains($generic['body'], 'there'), "The {$language} generic greeting must not carry the rejected name or a placeholder.");
    }
    $fallback = kssmi_inquiry_autoreply_build(['name' => 'Maria', 'language' => 'xx']);
    iar_assert(str_starts_with($fallback['body'], "Hello Maria,\n"), 'An unknown language must fall back to English.');
    iar_assert(str_starts_with(kssmi_inquiry_autoreply_build(['name' => '', 'language' => 'zh'])['body'], "您好：\n"), 'An empty name must get the generic greeting.');

    // One confirmation per mailbox per day, however the address is written.
    iar_use_dir($root, 'recipient');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    iar_assert(kssmi_inquiry_autoreply_budget('victim@example.com') === null, 'The first confirmation must be allowed.');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
    iar_assert(kssmi_inquiry_autoreply_budget('Victim+again@Example.com') === 'recipient', 'A second confirmation to the same mailbox must be skipped, from any client.');

    // A refused mailbox charges neither the client nor the site budget.
    iar_use_dir($root, 'client');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    iar_assert(kssmi_inquiry_autoreply_budget('first@example.com') === null, 'The first confirmation from a client must be allowed.');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        iar_assert(kssmi_inquiry_autoreply_budget('first@example.com') === 'recipient', 'Repeats to one mailbox must be skipped.');
    }
    for ($index = 2; $index <= 5; $index++) {
        iar_assert(kssmi_inquiry_autoreply_budget("person{$index}@example.com") === null, "Confirmation {$index} from one client must be allowed.");
    }
    iar_assert(kssmi_inquiry_autoreply_budget('person6@example.com') === 'client', 'The sixth confirmation from one client in a day must be skipped.');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.8';
    iar_assert(kssmi_inquiry_autoreply_budget('person7@example.com') === null, 'Another client must keep its own budget.');

    // The whole site sends at most 20 confirmations an hour and 100 a day.
    iar_use_dir($root, 'global-hour');
    $outcomes = [];
    for ($index = 0; $index < 25; $index++) {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . (10 + $index);
        $outcomes[] = kssmi_inquiry_autoreply_budget("customer{$index}@example.com") ?? 'send';
    }
    $outcomeCounts = array_count_values($outcomes);
    ksort($outcomeCounts);
    iar_assert(
        $outcomeCounts === ['global' => 5, 'send' => 20] && array_slice($outcomes, 0, 20) === array_fill(0, 20, 'send'),
        'The hourly site budget must allow exactly the first 20 confirmations: ' . json_encode($outcomeCounts)
    );
    iar_use_dir($root, 'global-day');
    for ($unit = 0; $unit < 100; $unit++) checkRateLimitGlobal('inquiry-autoreply-day', 100, 86400);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.200';
    iar_assert(kssmi_inquiry_autoreply_budget('late@example.com') === 'global', 'The daily site budget must apply.');

    // Concurrent submissions for one mailbox get a single confirmation.
    $workerDir = $root . DIRECTORY_SEPARATOR . 'concurrent';
    iar_use_dir($root, 'concurrent');
    $gate = $root . DIRECTORY_SEPARATOR . '.gate';
    $workers = [];
    for ($index = 0; $index < 8; $index++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--budget-worker', $workerDir, $gate, (string)(50 + $index)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        iar_assert(is_resource($process), 'Unable to start a budget worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    touch($gate);
    $concurrent = [];
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        iar_assert(proc_close($process) === 0, 'Budget worker failed: ' . trim((string)$stderr));
        $concurrent[] = trim((string)$stdout);
    }
    $concurrentCounts = array_count_values($concurrent);
    ksort($concurrentCounts);
    iar_assert(
        $concurrentCounts === ['recipient' => 7, 'send' => 1],
        'Concurrent submissions must yield one confirmation: ' . implode(',', $concurrent)
    );

    echo "Inquiry auto-reply budget and greeting tests passed.\n";
} finally {
    iar_remove_tree($root);
}
