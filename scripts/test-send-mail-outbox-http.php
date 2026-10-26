<?php
declare(strict_types=1);

// End to end: the real send-mail.php under PHP's built-in server, in a
// temporary tree laid out like a release. Only the Turnstile siteverify call
// is replaced (Cloudflare's test secret reports hostname example.com, which
// send-mail.php rightly rejects). PHPMailer is a stub that writes each message
// to mail-out/ or fails while smtp-down exists. Nothing leaves the machine.

$repo = dirname(__DIR__);
$tree = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-send-mail-' . bin2hex(random_bytes(6));

function sm_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function sm_write(string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('Unable to create ' . dirname($path));
    if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Unable to write ' . $path);
}

function sm_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? sm_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

function sm_post(int $port, string $clientIp, array $fields, array $files = []): array {
    $boundary = 'kssmi' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    foreach ($files as [$name, $filename, $type, $contents]) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\nContent-Type: {$type}\r\n\r\n{$contents}\r\n";
    }
    $body .= "--{$boundary}--\r\n";
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 5);
    if ($socket === false) throw new RuntimeException("Connection failed: {$errorText}");
    stream_set_timeout($socket, 60);
    $started = microtime(true);
    fwrite($socket, implode("\r\n", [
        'POST /send-mail.php HTTP/1.0',
        'Host: kssmi.com',
        'Origin: https://kssmi.com',
        'CF-Connecting-IP: ' . $clientIp,
        // Cloudflare adds the country in production; without it send-mail.php
        // falls back to a network lookup.
        'CF-IPCountry: US',
        'User-Agent: kssmi-send-mail-test',
        "Content-Type: multipart/form-data; boundary={$boundary}",
        'Content-Length: ' . strlen($body),
        'Connection: close',
    ]) . "\r\n\r\n" . $body);
    $raw = (string)stream_get_contents($socket);
    fclose($socket);
    [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $match) === 1 ? (int)$match[1] : 0;
    return ['status' => $status, 'json' => json_decode($responseBody, true), 'body' => $responseBody, 'ms' => (int)round((microtime(true) - $started) * 1000)];
}

function sm_mail(string $tree): array {
    $files = glob($tree . '/mail-out/*.json') ?: [];
    sort($files, SORT_STRING);
    return array_map(static fn(string $file): array => json_decode((string)file_get_contents($file), true), $files);
}

function sm_inquiry(string $email, string $details, string $name = 'Test Verify'): array {
    return ['name' => $name, 'email' => $email, 'details' => $details, 'language' => 'en', 'source' => 'quote-page', 'cf-turnstile-response' => 'test-token', 'inquiry_privacy_consent' => '1', 'inquiry_notice_id' => 'inquiry-privacy-2026-10-26-v3'];
}

$server = null;
try {
    // Release-like tree.
    foreach (['send-mail.php', 'api/vjt-helpers.php'] as $file) {
        sm_write("{$tree}/public/{$file}", (string)file_get_contents("{$repo}/public/{$file}"));
    }
    foreach (glob("{$repo}/private/*.php") ?: [] as $file) {
        sm_write("{$tree}/private/" . basename($file), (string)file_get_contents($file));
    }
    sm_write("{$tree}/private/inquiry-privacy-notice.json", (string)file_get_contents("{$repo}/private/inquiry-privacy-notice.json"));
    $sendMail = (string)file_get_contents("{$tree}/public/send-mail.php");
    $call = "\$turnstileResult = verifyTurnstileDetailed(\$turnstileToken, \$config['turnstile_secret']);";
    sm_assert(substr_count($sendMail, $call) === 1, 'The Turnstile call to replace was not found exactly once.');
    sm_write("{$tree}/public/send-mail.php", str_replace($call, "\$turnstileResult = ['ok' => \$turnstileToken === 'test-token', 'service_error' => false, 'reason' => 'test'];", $sendMail));
    $snapshot = json_decode((string)file_get_contents("{$repo}/private/cloudflare-ip-ranges.json"), true, 32, JSON_THROW_ON_ERROR);
    $snapshot['verified_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $snapshot['ipv4'][] = '127.0.0.0/8';
    $snapshot['ipv6'][] = '::1/128';
    sm_write("{$tree}/private/cloudflare-ip-ranges.json", json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    sm_write("{$tree}/private_config.php", "<?php\nreturn ['smtp_pass' => 'not-a-real-password', 'turnstile_secret' => 'not-a-real-secret'];\n");
    $stubDir = "{$tree}/public/vendor/phpmailer/phpmailer/src";
    sm_write("{$stubDir}/Exception.php", "<?php\nnamespace PHPMailer\\PHPMailer;\nclass Exception extends \\Exception {}\n");
    sm_write("{$stubDir}/SMTP.php", "<?php\nnamespace PHPMailer\\PHPMailer;\nclass SMTP {}\n");
    sm_write("{$stubDir}/PHPMailer.php", <<<'PHP'
<?php
namespace PHPMailer\PHPMailer;

class PHPMailer {
    public const ENCRYPTION_STARTTLS = 'tls';
    public $Host = ''; public $SMTPAuth = false; public $Username = ''; public $Password = ''; public $SMTPSecure = '';
    public $Port = 0; public $CharSet = ''; public $Timeout = 0; public $Subject = ''; public $Body = ''; public $AltBody = '';
    private array $to = []; private array $cc = []; private array $replyTo = []; private array $files = []; private array $headers = []; private bool $html = false;
    public function __construct($exceptions = null) {}
    public function isSMTP(): void {}
    public function isHTML($html = true): void { $this->html = (bool)$html; }
    public function setFrom($address, $name = ''): bool { return true; }
    public function addAddress($address, $name = ''): bool { $this->to[] = [$address, $name]; return true; }
    public function addCC($address, $name = ''): bool { $this->cc[] = $address; return true; }
    public function addReplyTo($address, $name = ''): bool { $this->replyTo[] = $address; return true; }
    public function addCustomHeader($name, $value = null): void { $this->headers[] = $name; }
    public function addAttachment($path, $name = ''): bool {
        if (!is_file($path)) throw new Exception('Could not access file: ' . $name);
        $this->files[$name] = (string)file_get_contents($path);
        return true;
    }
    public function send(): bool {
        $root = dirname(__DIR__, 5);
        if (file_exists($root . '/smtp-down')) throw new Exception('SMTP Error: Could not connect to SMTP host. connect() failed.');
        $record = ['to' => $this->to, 'cc' => $this->cc, 'reply_to' => $this->replyTo, 'subject' => $this->Subject, 'html' => $this->html,
            'body' => $this->Body, 'alt' => $this->AltBody, 'files' => array_map('strlen', $this->files), 'headers' => $this->headers];
        file_put_contents($root . '/mail-out/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($record));
        return true;
    }
}
PHP);
    sm_write("{$tree}/router.php", "<?php\nreturn false;\n");
    foreach (['mail-out', 'email_data', 'rate_limit', 'vjt', 'sessions'] as $dir) {
        if (!mkdir("{$tree}/{$dir}", 0700, true)) throw new RuntimeException("Unable to create {$dir}");
    }

    $probe = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($probe === false) throw new RuntimeException("No free port: {$errorText}");
    $address = (string)stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int)substr($address, strrpos($address, ':') + 1);
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) {
        foreach (['pdo_sqlite', 'mbstring', 'fileinfo'] as $extension) {
            if (!extension_loaded($extension)) continue;
            array_push($command, '-d', 'extension_dir=' . (string)ini_get('extension_dir'), '-d', 'extension=' . $extension);
        }
    }
    array_push($command, '-d', 'session.save_path=' . $tree . '/sessions', '-d', 'display_errors=0', '-d', 'log_errors=1',
        '-S', "127.0.0.1:{$port}", '-t', "{$tree}/public", "{$tree}/router.php");
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['KSSMI_VJT_DATA_DIR'] = "{$tree}/vjt";
    $environment['KSSMI_SHORTLINK_DATA_DIR'] = "{$tree}/shortlinks";
    $environment['KSSMI_VJT_EVENT_AUTH_SECRET'] = str_repeat('a', 64);
    $pipes = [];
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', "{$tree}/server.out", 'a'], 2 => ['file', "{$tree}/server.log", 'a']], $pipes, $tree, $environment);
    sm_assert(is_resource($server), 'Unable to start the PHP built-in server.');
    $deadline = microtime(true) + 15;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.2);
        if ($socket !== false) { fclose($socket); break; }
        usleep(100000);
    } while (microtime(true) < $deadline);
    sm_assert($socket !== false, 'The PHP built-in server did not start.');

    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    $missingConsent = sm_inquiry('consent@example.com', 'No consent');
    unset($missingConsent['inquiry_privacy_consent']);
    $rejected = sm_post($port, '198.51.100.30', $missingConsent);
    sm_assert($rejected['status'] === 422 && sm_mail($tree) === [], 'Missing inquiry consent must not send mail.');
    $staleNotice = sm_inquiry('consent@example.com', 'Stale notice');
    $staleNotice['inquiry_notice_id'] = 'old-notice';
    sm_assert(sm_post($port, '198.51.100.31', $staleNotice)['status'] === 422, 'Stale notice must be rejected.');

    // 1. A normal inquiry with an attachment: accepted, both messages sent.
    $first = sm_post($port, '198.51.100.1', sm_inquiry('jack@kssmi.com', 'Acceptance test one'), [['attachments[]', 'drawing.pdf', 'application/pdf', $pdf]]);
    sm_assert($first['status'] === 200 && ($first['json']['success'] ?? false) === true && ($first['json']['redirect'] ?? '') === '/thank-you/', 'The inquiry must be accepted: HTTP ' . $first['status'] . ' ' . $first['body']);
    $mail = sm_mail($tree);
    sm_assert(count($mail) === 2, 'Two messages must be sent for one inquiry; got ' . count($mail));
    [$sales, $reply] = $mail;
    sm_assert($sales['to'][0][0] === 'sales@kssmi.com' && $sales['reply_to'] === ['jack@kssmi.com'] && $sales['html'] === true && ($sales['files']['drawing.pdf'] ?? 0) === strlen($pdf), 'The sales notification must carry the inquiry and its attachment.');
    sm_assert(str_contains($sales['subject'], 'Test Verify - Kssmi Eyewear - '), 'The sales subject must be unchanged.');
    sm_assert($reply['to'][0][0] === 'jack@kssmi.com' && $reply['cc'] === ['sales@kssmi.com'] && str_starts_with($reply['body'], "Hello Test Verify,\n") && in_array('Auto-Submitted', $reply['headers'], true), 'The auto-reply must greet the submitter and copy sales.');
    sm_assert(glob("{$tree}/email_data/outbox/[0-9]*") === [], 'A delivered job must leave the outbox.');
    $logs = json_decode((string)file_get_contents("{$tree}/email_data/email-logs.json"), true);
    sm_assert(($logs[0]['status'] ?? '') === 'success' && ($logs[0]['form_data']['email'] ?? '') === 'jack@kssmi.com' && ($logs[0]['user_agent'] ?? '') === 'kssmi-send-mail-test', 'Email Logs must record the delivered inquiry with its user agent.');

    // 2. The same mailbox again with a different inquiry: replied again.
    $second = sm_post($port, '198.51.100.2', sm_inquiry('jack@kssmi.com', 'Acceptance test two'));
    sm_assert(($second['json']['success'] ?? false) === true && count(sm_mail($tree)) === 4, 'A second inquiry from the same mailbox must get its own notification and reply.');
    sm_assert(str_starts_with(sm_mail($tree)[3]['body'], "Hello Test Verify,\n") && sm_mail($tree)[3]['to'][0][0] === 'jack@kssmi.com', 'The second reply must reach the same mailbox.');

    // 3. A retry of the same submission (new request, lost response) is not queued twice.
    $retry = sm_post($port, '198.51.100.3', sm_inquiry('jack@kssmi.com', 'Acceptance test two'));
    sm_assert(($retry['json']['success'] ?? false) === true && count(sm_mail($tree)) === 4, 'An identical submission within two minutes must not send again.');

    // 4. A name that is not a name gets the generic greeting.
    sm_post($port, '198.51.100.4', sm_inquiry('mralwayslucky@gmail.com', 'Acceptance test three', 'Visit evil.com now'));
    $generic = sm_mail($tree)[5];
    sm_assert($generic['to'][0][0] === 'mralwayslucky@gmail.com' && str_starts_with($generic['body'], "Hello,\n") && !str_contains($generic['body'], 'evil'), 'A non-name must get the generic greeting.');

    // 5. SMTP down: the visitor still gets success, the job waits on disk, and
    // the worker sends both messages once SMTP is back.
    touch("{$tree}/smtp-down");
    $outage = sm_post($port, '198.51.100.5', sm_inquiry('buyer@example.com', 'Sent during an outage'));
    sm_assert(($outage['json']['success'] ?? false) === true && count(sm_mail($tree)) === 6, 'An accepted inquiry must be answered even if SMTP is down.');
    $jobs = glob("{$tree}/email_data/outbox/[0-9]*") ?: [];
    sm_assert(count($jobs) === 1, 'The undelivered inquiry must stay in the outbox.');
    $job = json_decode((string)file_get_contents($jobs[0] . '/job.json'), true);
    $consent = $job['record']['form']['inquiry_consent'] ?? [];
    sm_assert(($consent['granted'] ?? false) === true && ($consent['policy_version'] ?? '') === '1.1'
        && strlen($consent['notice_sha256'] ?? '') === 64 && ($consent['received_at'] ?? '') !== '', 'Server-side consent evidence must survive queueing.');
    sm_assert($job['steps']['sales']['state'] === 'pending' && $job['steps']['autoreply']['state'] === 'pending' && $job['next_attempt_at'] > time(), 'Both steps must be pending with a backoff.');
    unlink("{$tree}/smtp-down");
    $job['next_attempt_at'] = time() - 1;   // as if the minute had passed
    file_put_contents($jobs[0] . '/job.json', json_encode($job));
    $worker = proc_open([...array_slice($command, 0, array_search('-d', $command, true) ?: 1), "{$tree}/private/inquiry-outbox-worker.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, $tree, $environment);
    $workerOut = (string)stream_get_contents($workerPipes[1]);
    $workerErr = (string)stream_get_contents($workerPipes[2]);
    sm_assert(proc_close($worker) === 0 && str_contains($workerOut, ' done'), "The worker must deliver the waiting job: {$workerOut} {$workerErr}");
    $mail = sm_mail($tree);
    sm_assert(count($mail) === 8 && $mail[6]['to'][0][0] === 'sales@kssmi.com' && $mail[7]['to'][0][0] === 'buyer@example.com', 'The worker must send the notification and the reply.');
    sm_assert(glob("{$tree}/email_data/outbox/[0-9]*") === [] && str_contains($workerOut, '"pending":0'), 'The outbox must be empty afterwards.');
    $receipts = glob("{$tree}/email_data/inquiry-consents/*.json") ?: [];
    sm_assert(count($receipts) === 4, 'Each distinct accepted inquiry must retain independent consent evidence after queue deletion.');
    $receipt = json_decode((string)file_get_contents($receipts[0]), true);
    sm_assert(($receipt['consent']['granted'] ?? false) === true && strlen($receipt['subject_sha256'] ?? '') === 64
        && !isset($receipt['email']) && !isset($receipt['message']), 'Receipt must retain minimal evidence, without raw email or message.');

    // Configuration failure must fail closed before mail or queue acceptance.
    $noticePath = "{$tree}/private/inquiry-privacy-notice.json";
    $noticeBytes = (string)file_get_contents($noticePath);
    file_put_contents($noticePath, '{"id":"broken"}');
    $brokenNotice = sm_post($port, '198.51.100.32', sm_inquiry('config@example.com', 'Broken notice'));
    file_put_contents($noticePath, $noticeBytes);
    sm_assert($brokenNotice['status'] === 503 && count(sm_mail($tree)) === 8
        && glob("{$tree}/email_data/outbox/[0-9]*") === [], 'Invalid server notice must return 503 without accepting or mailing an inquiry.');

    // 6. Acceptance time is logged and excludes SMTP; no PHP warnings anywhere.
    $log = (string)file_get_contents("{$tree}/server.log");
    sm_assert(preg_match_all('/KSSMI inquiry \S+ accepted in (\d+) ms/', $log, $timings) === 5, 'Every request must log its acceptance time.');
    sm_assert(str_contains($log, 'repeat of a submission from the last 2 minutes'), 'The retry must be logged as a repeat.');
    sm_assert(preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $log . $workerErr) !== 1, "PHP reported problems:\n" . $log . $workerErr);

    // 7. Sender blocklist: a blocked sender (Gmail dot/+tag variant) gets the
    // normal success answer, but nothing is queued, mailed or logged.
    require_once "{$tree}/private/sender-blocklist.php";
    $rulesPath = "{$tree}/email_data/sender-blocklist.json";
    $hitsPath = "{$tree}/email_data/sender-blocklist-hits.json";
    sm_assert(kssmi_sender_blocklist_add('patelamisha2706@gmail.com', false, 'spam', $rulesPath)['status'] === 'added', 'The test rule must be added.');
    $mailBefore = count(sm_mail($tree));
    $logsBefore = count(json_decode((string)file_get_contents("{$tree}/email_data/email-logs.json"), true));
    $blocked = sm_post($port, '198.51.100.7', sm_inquiry('Patel.Amisha2706+promo@googlemail.com', str_repeat('Spam offer. ', 100), "Amisha O'Neil & Co"), [['attachments[]', 'offer.pdf', 'application/pdf', $pdf]]);
    sm_assert($blocked['status'] === 200 && ($blocked['json']['success'] ?? false) === true && ($blocked['json']['redirect'] ?? '') === '/thank-you/' && preg_match('/^#[0-9A-F]{4}$/', (string)($blocked['json']['inquiry_id'] ?? '')) === 1, 'A blocked sender must get the normal success answer: ' . $blocked['body']);
    sm_assert(count(sm_mail($tree)) === $mailBefore, 'A blocked sender must not cause any mail.');
    sm_assert(count(json_decode((string)file_get_contents("{$tree}/email_data/email-logs.json"), true)) === $logsBefore, 'A blocked sender must not appear in Email Logs.');
    sm_assert(glob("{$tree}/email_data/outbox/[0-9]*") === [], 'A blocked sender must not be queued.');
    $hits = kssmi_sender_blocklist_read_hits($hitsPath);
    $hit = $hits['hits'][0] ?? [];
    sm_assert($hits['ok'] && count($hits['hits']) === 1 && ($hit['email'] ?? '') === 'Patel.Amisha2706+promo@googlemail.com' && ($hit['ip'] ?? '') === '198.51.100.7' && ($hit['country'] ?? '') === 'US', 'The blocked submission must be recorded.');
    sm_assert(mb_strlen($hit['details'], 'UTF-8') === KSSMI_SENDER_BLOCKLIST_DETAILS_MAX && ($hit['attachments'] ?? null) === 1 && !isset($hit['files']), 'The record must clip the message and keep only the attachment count.');
    sm_assert(($hit['name'] ?? '') === "Amisha O'Neil & Co", 'The record must store plain text, not HTML-encoded form values: ' . ($hit['name'] ?? ''));
    $counters = json_decode((string)file_get_contents("{$tree}/email_data/submission-counters.json"), true);
    sm_assert((int)($counters[date('Y-m-d')]['blocked'] ?? 0) === 1, 'The blocked submission must be counted.');

    // 8. An unreadable blocklist fails open: the inquiry is accepted and
    // mailed, the failure is logged and counted, and the file is untouched.
    $corrupt = '{"version":1,"rules":[{"id":"broken"';
    file_put_contents($rulesPath, $corrupt);
    $failOpen = sm_post($port, '198.51.100.8', sm_inquiry('patelamisha2706@gmail.com', 'Sent while the blocklist is damaged'));
    sm_assert(($failOpen['json']['success'] ?? false) === true && count(sm_mail($tree)) === $mailBefore + 2, 'A damaged blocklist must not stop inquiries.');
    sm_assert(file_get_contents($rulesPath) === $corrupt, 'A damaged blocklist must be left untouched.');
    sm_assert(str_contains((string)file_get_contents("{$tree}/server.log"), 'KSSMI sender blocklist UNAVAILABLE: invalid_json'), 'A damaged blocklist must be logged.');
    $counters = json_decode((string)file_get_contents("{$tree}/email_data/submission-counters.json"), true);
    sm_assert((int)($counters[date('Y-m-d')]['blocklist_error'] ?? 0) === 1, 'A damaged blocklist must be counted.');
    sm_assert(preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', (string)file_get_contents("{$tree}/server.log")) !== 1, 'The blocklist paths must not raise PHP warnings.');

    // 9. The outbox worker (cron) enforces the 30-day retention on disk.
    $hitsData = json_decode((string)file_get_contents($hitsPath), true);
    $hitsData['hits'][] = ['unix' => time() - 31 * 86400, 'time' => 'old', 'email' => 'expired@example.com'];
    file_put_contents($hitsPath, json_encode($hitsData));
    $worker = proc_open([...array_slice($command, 0, array_search('-d', $command, true) ?: 1), "{$tree}/private/inquiry-outbox-worker.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, $tree, $environment);
    $workerOut = (string)stream_get_contents($workerPipes[1]);
    $workerErr = (string)stream_get_contents($workerPipes[2]);
    sm_assert(proc_close($worker) === 0 && str_contains($workerOut, 'blocklist retention {"ok":true,"removed_hits":1'), "The worker must purge expired blocked submissions: {$workerOut} {$workerErr}");
    $hitsData = json_decode((string)file_get_contents($hitsPath), true);
    sm_assert(count($hitsData['hits']) === 1 && !str_contains((string)file_get_contents($hitsPath), 'expired@example.com'), 'The expired record must be gone from the file.');

    // A broken statistics store must report maintenance failure without
    // preventing delivery of an already accepted inquiry.
    file_put_contents($rulesPath, json_encode(['version' => 1, 'rules' => []]));
    file_put_contents("{$tree}/smtp-down", 'synthetic failure');
    $beforeFault = count(sm_mail($tree));
    $queuedFault = sm_post($port, '198.51.100.40', sm_inquiry('maintenance@example.com', 'Drain despite retention failure'));
    sm_assert(($queuedFault['json']['success'] ?? false) === true, 'The fault fixture must be accepted.');
    unlink("{$tree}/smtp-down");
    foreach (glob("{$tree}/email_data/outbox/[0-9]*") ?: [] as $jobPath) {
        $pending = json_decode((string)file_get_contents($jobPath . '/job.json'), true);
        $pending['next_attempt_at'] = time() - 1;
        file_put_contents($jobPath . '/job.json', json_encode($pending));
    }
    sm_write("{$tree}/shortlinks/shortlinks.sqlite", 'not a SQLite database');
    $worker = proc_open([...array_slice($command, 0, array_search('-d', $command, true) ?: 1), "{$tree}/private/inquiry-outbox-worker.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $workerPipes, $tree, $environment);
    $faultOut = (string)stream_get_contents($workerPipes[1]);
    $faultErr = (string)stream_get_contents($workerPipes[2]);
    sm_assert(proc_close($worker) === 0 && str_contains($faultErr, 'mail drain continues'), 'The worker must report retention failure and continue: ' . $faultOut . $faultErr);
    sm_assert(count(sm_mail($tree)) === $beforeFault + 2 && glob("{$tree}/email_data/outbox/[0-9]*") === [], 'Maintenance failure must not strand accepted mail.');

    echo 'Send-mail outbox HTTP tests passed (server-side acceptance ' . implode(', ', $timings[1]) . " ms).\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($tree)) sm_remove_tree($tree);
}
