<?php
declare(strict_types=1);

// Durable inquiry outbox: acceptance, duplicates, retries, crashes, give-up,
// locking. Runs in a temporary directory; nothing is sent.

if (in_array($argv[1] ?? '', ['--crash-after-sales', '--hold-lock'], true)) {
    putenv('KSSMI_OUTBOX_DIR=' . $argv[2]);
    require_once dirname(__DIR__) . '/private/inquiry-outbox.php';
    if ($argv[1] === '--hold-lock') {
        $lock = fopen($argv[2] . '/' . $argv[3] . '/.lock', 'c');
        flock($lock, LOCK_EX);
        touch($argv[4] . '.held');
        $deadline = microtime(true) + 20;
        while (file_exists($argv[4]) && microtime(true) < $deadline) usleep(20000);
        exit(0);
    }
    // Send the sales notification, then die before the reply is sent.
    kssmi_outbox_process($argv[3], static function (string $step): array {
        if ($step === 'autoreply') exit(3);
        return ['state' => 'sent', 'error' => null, 'ms' => 1];
    }, static function (): void {}, (int)$argv[4]);
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-outbox-' . bin2hex(random_bytes(6));
$outbox = $root . DIRECTORY_SEPARATOR . 'outbox';
if (!mkdir($root, 0700, true)) {
    fwrite(STDERR, "Outbox test failed: unable to create a temporary directory\n");
    exit(1);
}
putenv('KSSMI_OUTBOX_DIR=' . $outbox);
ini_set('error_log', $root . DIRECTORY_SEPARATOR . 'php-error.log');
require_once dirname(__DIR__) . '/private/inquiry-outbox.php';

function ob_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function ob_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? ob_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

function ob_messages(string $email): array {
    return [
        'sales' => ['to' => ['email' => 'sales@kssmi.com', 'name' => 'Sales'], 'reply_to' => ['email' => $email, 'name' => 'Test'], 'subject' => 'Test', 'html' => '<p>x</p>', 'text' => 'x'],
        'autoreply' => ['to' => $email, 'recipient_name' => 'Test', 'subject' => 'We received your inquiry', 'body' => "Hello Test,\n"],
    ];
}

function ob_form(string $email, string $details): array {
    return ['email' => $email, 'name' => 'Test Verify', 'details' => $details, 'product_url' => '', 'product_sku' => ''];
}

function ob_enqueue(string $email, string $details, array $attachments = [], ?int $now = null): array {
    $form = ob_form($email, $details);
    return kssmi_outbox_enqueue(ob_messages($email), ['inquiry_id' => 'KS-' . substr(md5($details), 0, 6), 'form' => $form], $attachments, kssmi_outbox_fingerprint($form, $attachments), $now);
}

/** A recording sender whose behaviour per step can be scripted. */
final class ObSender {
    public array $sent = [];
    public array $plan = [];
    public function __invoke(string $step, array $message, string $jobDir, array $job): array {
        $state = empty($this->plan[$step]) ? 'sent' : array_shift($this->plan[$step]);
        if ($state === 'sent') {
            $files = [];
            foreach ($message['attachments'] ?? [] as $file) $files[$file['name']] = (string)file_get_contents($jobDir . '/' . $file['file']);
            $this->sent[] = ['step' => $step, 'to' => $step === 'sales' ? $message['to']['email'] : $message['to'], 'files' => $files, 'inquiry' => $job['inquiry_id']];
        }
        return ['state' => $state, 'error' => $state === 'sent' ? null : "simulated {$state}", 'ms' => 1];
    }
}

try {
    $now = 1_800_000_000;
    $upload = $root . DIRECTORY_SEPARATOR . 'upload.pdf';
    file_put_contents($upload, "%PDF-1.4 test attachment\n");

    // 1. Acceptance writes the job and a copy of the attachment, then the
    // upload temp file can disappear without affecting delivery.
    $first = ob_enqueue('jack@kssmi.com', 'First inquiry', [['path' => $upload, 'name' => 'drawing.pdf', 'size' => filesize($upload)]], $now);
    ob_assert($first['ok'] && !$first['duplicate'] && is_string($first['id']), 'An inquiry must be accepted.');
    unlink($upload);
    $jobDir = $outbox . DIRECTORY_SEPARATOR . $first['id'];
    $job = kssmi_outbox_load_job($jobDir);
    ob_assert($job !== null && $job['steps']['sales']['state'] === 'pending' && $job['steps']['autoreply']['state'] === 'pending', 'Both messages must be pending after acceptance.');
    ob_assert(is_file($jobDir . '/att-0') && !is_dir($outbox . '/.tmp-' . $first['id']), 'The attachment must be stored and no temporary directory left.');
    if (DIRECTORY_SEPARATOR === '/') {
        ob_assert((fileperms($outbox) & 0777) === 0700 && (fileperms($jobDir . '/job.json') & 0777) === 0600 && (fileperms($jobDir . '/att-0') & 0777) === 0600, 'Outbox entries must be private to the site account.');
    }

    // 2. The same submission again within two minutes is a retry, not a second inquiry.
    $repeat = ob_enqueue('jack@kssmi.com', 'First inquiry', [['path' => $jobDir . '/att-0', 'name' => 'drawing.pdf', 'size' => filesize($jobDir . '/att-0')]], $now + 60);
    ob_assert($repeat['ok'] && $repeat['duplicate'] && $repeat['id'] === $first['id'] && count(kssmi_outbox_job_ids()) === 1, 'A repeat within two minutes must not be queued again.');

    // 3. A different inquiry from the same mailbox, and the same text after the
    // window, are separate inquiries with their own replies.
    $second = ob_enqueue('jack@kssmi.com', 'Second inquiry', [], $now + 61);
    $later = ob_enqueue('jack@kssmi.com', 'First inquiry', [], $now + 200);
    ob_assert($second['ok'] && !$second['duplicate'] && $later['ok'] && !$later['duplicate'] && count(kssmi_outbox_job_ids()) === 3, 'Each distinct inquiry must be queued.');

    // 4. Normal delivery: both messages once, final hooks run, the job and its
    // files are removed.
    $sender = new ObSender();
    $finals = [];
    $onFinal = static function (string $step, string $state) use (&$finals): void { $finals[] = "{$step}:{$state}"; };
    ob_assert(kssmi_outbox_process($first['id'], $sender, $onFinal, $now + 1) === 'done', 'A job must complete.');
    ob_assert(count($sender->sent) === 2 && $sender->sent[0]['step'] === 'sales' && $sender->sent[1]['to'] === 'jack@kssmi.com', 'Sales notification and reply must both be sent.');
    ob_assert(($sender->sent[0]['files']['drawing.pdf'] ?? '') === "%PDF-1.4 test attachment\n", 'The stored attachment must reach the sales notification.');
    ob_assert($finals === ['sales:sent', 'autoreply:sent'] && !is_dir($jobDir), 'Final hooks must run once and the job must be removed.');

    // 5. Sales fails, the reply is sent; the retry sends only the sales
    // notification, after the backoff.
    $sender = new ObSender();
    $sender->plan = ['sales' => ['failed']];
    $finals = [];
    ob_assert(kssmi_outbox_process($second['id'], $sender, $onFinal, $now + 70) === 'retry', 'A failed step must be retried.');
    ob_assert(array_column($sender->sent, 'step') === ['autoreply'], 'The reply must not wait for the sales notification.');
    $job = kssmi_outbox_load_job($outbox . '/' . $second['id']);
    ob_assert($job['next_attempt_at'] === $now + 70 + 60 && $job['steps']['sales']['last_error'] === 'simulated failed', 'The retry must wait one minute and keep the error.');
    ob_assert(kssmi_outbox_process($second['id'], $sender, $onFinal, $now + 100) === 'not_due', 'A job must not be retried early.');
    ob_assert(kssmi_outbox_process($second['id'], $sender, $onFinal, $now + 131) === 'done', 'The retry must complete.');
    ob_assert(array_column($sender->sent, 'step') === ['autoreply', 'sales'], 'Nothing may be sent twice after a definite failure.');

    // 6. A worker dying between the two messages: the next run sends only the reply.
    $crash = proc_open([PHP_BINARY, __FILE__, '--crash-after-sales', $outbox, $later['id'], (string)($now + 250)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    ob_assert(proc_close($crash) === 3, 'The simulated crash must happen.');
    $job = kssmi_outbox_load_job($outbox . '/' . $later['id']);
    ob_assert($job['steps']['sales']['state'] === 'sent' && $job['steps']['autoreply']['state'] === 'pending', 'Progress before the crash must be on disk.');
    $sender = new ObSender();
    ob_assert(kssmi_outbox_process($later['id'], $sender, $onFinal, $now + 300) === 'done' && array_column($sender->sent, 'step') === ['autoreply'], 'Recovery must send only what is missing.');

    // 7. An uncertain result is retried (a duplicate is possible, a loss is not).
    $uncertain = ob_enqueue('buyer@example.com', 'Uncertain', [], $now + 400);
    $sender = new ObSender();
    $sender->plan = ['autoreply' => ['uncertain']];
    ob_assert(kssmi_outbox_process($uncertain['id'], $sender, $onFinal, $now + 400) === 'retry', 'An uncertain send must be retried.');
    ob_assert(kssmi_outbox_load_job($outbox . '/' . $uncertain['id'])['steps']['autoreply']['uncertain'] === true, 'The uncertain flag must be kept.');
    ob_assert(kssmi_outbox_process($uncertain['id'], $sender, $onFinal, $now + 461) === 'done', 'The uncertain step must be retried and completed.');

    // 8. Quota or outage for three days: the step is given up loudly and the
    // job is kept with its files.
    $stuck = ob_enqueue('stuck@example.com', 'Stuck', [], $now + 500);
    $sender = new ObSender();
    $sender->plan = ['sales' => array_fill(0, 20, 'failed'), 'autoreply' => array_fill(0, 20, 'failed')];
    $finals = [];
    $result = kssmi_outbox_process($stuck['id'], $sender, $onFinal, $now + 500);
    for ($attempt = 0; $attempt < 8 && $result === 'retry'; $attempt++) {
        $job = kssmi_outbox_load_job($outbox . '/' . $stuck['id']);
        $result = kssmi_outbox_process($stuck['id'], $sender, $onFinal, max($job['next_attempt_at'], $now + 500 + ($attempt === 7 ? KSSMI_OUTBOX_GIVE_UP_AFTER : 0)));
    }
    $result = kssmi_outbox_process($stuck['id'], $sender, $onFinal, $now + 500 + KSSMI_OUTBOX_GIVE_UP_AFTER + 7200);
    ob_assert($result === 'dead' && $finals === ['sales:dead', 'autoreply:dead'], 'Steps must be given up after 72 hours and reported once.');
    ob_assert(is_dir($outbox . '/' . $stuck['id']) && kssmi_outbox_summary()['dead'] === 1, 'A dead job must be kept and counted.');
    ob_assert(str_contains((string)file_get_contents($root . '/php-error.log'), 'KSSMI outbox ALERT'), 'Giving up must raise an alert in the log.');
    ob_assert(kssmi_outbox_process($stuck['id'], $sender, $onFinal, $now + 999999) === 'dead', 'A dead job must not be retried.');

    // 9. Two processes never work on the same job at once.
    $busy = ob_enqueue('busy@example.com', 'Busy', [], $now + 600);
    $gate = $root . DIRECTORY_SEPARATOR . 'gate';
    touch($gate);
    $holder = proc_open([PHP_BINARY, __FILE__, '--hold-lock', $outbox, $busy['id'], $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $deadline = microtime(true) + 10;
    while (!file_exists($gate . '.held') && microtime(true) < $deadline) usleep(20000);
    $sender = new ObSender();
    ob_assert(kssmi_outbox_process($busy['id'], $sender, $onFinal, $now + 600) === 'busy' && $sender->sent === [], 'A locked job must be skipped.');
    unlink($gate);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($holder);
    ob_assert(kssmi_outbox_process($busy['id'], $sender, $onFinal, $now + 600) === 'done', 'The job must be sent once the lock is free.');

    // 10. Drain: oldest first, within the limit, skipping the job just handled.
    $a = ob_enqueue('a@example.com', 'A', [], $now + 700);
    $b = ob_enqueue('b@example.com', 'B', [], $now + 701);
    $c = ob_enqueue('c@example.com', 'C', [], $now + 702);
    $sender = new ObSender();
    $drained = kssmi_outbox_drain($sender, $onFinal, 1, 45, $a['id'], $now + 800);
    ob_assert(array_keys($drained) === [$b['id']] && array_values($drained) === ['done'], 'Drain must take the oldest due job, skip the given one and respect the limit.');
    kssmi_outbox_drain($sender, $onFinal, 5, 45, null, $now + 800);
    ob_assert(kssmi_outbox_summary()['pending'] === 0 && kssmi_outbox_summary()['dead'] === 1, 'Every due job must be sent by draining.');

    // 11. Without a writable outbox nothing is accepted.
    putenv('KSSMI_OUTBOX_DIR=' . $root . DIRECTORY_SEPARATOR . 'upload-blocker');
    file_put_contents($root . DIRECTORY_SEPARATOR . 'upload-blocker', 'not a directory');
    ob_assert(ob_enqueue('x@example.com', 'X', [], $now)['ok'] === false, 'An inquiry must not be reported accepted if it cannot be stored.');

    echo "Inquiry outbox tests passed.\n";
} finally {
    ob_remove_tree($root);
}
