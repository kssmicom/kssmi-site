<?php
declare(strict_types=1);

// KS-03 end to end: the real short-links page and APIs run under PHP's
// built-in server in a temporary tree laid out like a release. The test
// Cloudflare snapshot adds loopback, so the origin gate accepts the local peer
// and CF-Connecting-IP stands in for many client addresses. PHPMailer is a
// stub that writes to an outbox, so nothing leaves the machine.

$repo = dirname(__DIR__);
$tree = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-short-links-http-' . bin2hex(random_bytes(6));

const SLH_DEVICE_COOKIE = '__Secure-kssmi_sl_device';
const SLH_PASSWORDS = [
    'alice@kssmi.com' => 'alice correct horse',
    'boss@kssmi.com' => 'boss correct horse',
    'chief@kssmi.com' => 'chief correct horse',
    'broken@kssmi.com' => 'broken correct horse',
];

function slh_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function slh_write(string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) throw new RuntimeException('Unable to create ' . dirname($path));
    if (file_put_contents($path, $contents) !== strlen($contents)) throw new RuntimeException('Unable to write ' . $path);
}

function slh_remove_tree(string $path): void {
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $entry = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($entry) ? slh_remove_tree($entry) : @unlink($entry);
    }
    @rmdir($path);
}

function slh_request(int $port, string $method, string $path, array $headers, string $body): array {
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 5);
    if ($socket === false) throw new RuntimeException("Connection failed: {$errorText}");
    stream_set_timeout($socket, 60);
    $lines = ["{$method} {$path} HTTP/1.0", 'Host: kssmi.com', 'Connection: close'];
    foreach ($headers as $name => $value) $lines[] = "{$name}: {$value}";
    if ($method === 'POST') $lines[] = 'Content-Length: ' . strlen($body);
    fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n" . $body);
    $raw = (string)stream_get_contents($socket);
    fclose($socket);
    [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $headerLines = explode("\r\n", $head);
    $statusLine = (string)array_shift($headerLines);
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $match) === 1 ? (int)$match[1] : 0;
    $responseHeaders = [];
    foreach ($headerLines as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))][] = trim($value);
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
}

final class SlhBrowser {
    public array $cookies = [];

    public function __construct(private int $port, private string $clientIp) {}

    public function send(string $method, string $path, array $headers = [], string $body = ''): array {
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) $pairs[] = $name . '=' . $value;
            $headers['Cookie'] = implode('; ', $pairs);
        }
        $headers['CF-Connecting-IP'] = $this->clientIp;
        $response = slh_request($this->port, $method, $path, $headers, $body);
        foreach ($response['headers']['set-cookie'] ?? [] as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $expired = preg_match('/;\s*expires=([^;]+)/i', $line, $match) === 1 && strtotime($match[1]) < time();
            if ($value === '' || $value === 'deleted' || $expired) unset($this->cookies[$name]);
            else $this->cookies[$name] = $value;
        }
        return $response;
    }

    public function csrf(): string {
        $page = $this->send('GET', '/short-links');
        slh_assert($page['status'] === 200, 'The short-links page did not load: HTTP ' . $page['status']);
        slh_assert(preg_match('/id="sl_tool_csrf" value="([a-f0-9]{64})"/', $page['body'], $match) === 1, 'No tool CSRF token on the page.');
        return $match[1];
    }

    public function login(string $email, string $password): array {
        $body = http_build_query(['csrf_token' => $this->csrf(), 'email' => $email, 'password' => $password]);
        return $this->send('POST', '/short-links', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);
    }

    public function json(string $path, array $payload): array {
        $payload['csrf_token'] = $this->csrf();
        return $this->send('POST', $path, [
            'Content-Type' => 'application/json',
            'Origin' => 'https://kssmi.com',
            'Sec-Fetch-Site' => 'same-origin',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}

function slh_difference(string $left, string $right): string {
    $length = min(strlen($left), strlen($right));
    for ($offset = 0; $offset < $length && $left[$offset] === $right[$offset]; $offset++);
    if ($offset === $length && strlen($left) === strlen($right)) return 'identical';
    return 'first difference at byte ' . $offset . ': ' . json_encode(substr($left, max(0, $offset - 40), 120)) . ' vs ' . json_encode(substr($right, max(0, $offset - 40), 120));
}

function slh_set_cookie_line(array $response, string $name): ?string {
    foreach ($response['headers']['set-cookie'] ?? [] as $line) {
        if (str_starts_with($line, $name . '=')) return $line;
    }
    return null;
}

function slh_signed_in(array $response, string $email): bool {
    return $response['status'] === 200 && str_contains($response['body'], 'name="logout"') && str_contains($response['body'], $email);
}

function slh_rejected(array $response): bool {
    return $response['status'] === 200 && str_contains($response['body'], 'Invalid email or password.') && !str_contains($response['body'], 'name="logout"');
}

function slh_throttled(array $response): bool {
    return $response['status'] === 429 && str_contains($response['body'], 'Too many sign-in attempts.');
}

function slh_mail(string $tree): array {
    $mail = [];
    $files = glob($tree . '/outbox/*.json') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) $mail[] = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
    return $mail;
}

function slh_mail_to(string $tree, string $email): array {
    return array_values(array_filter(slh_mail($tree), static fn(array $message): bool => in_array($email, $message['to'], true)));
}

$server = null;
try {
    // A release-like tree: <tree>/public is the webroot, <tree>/private the modules.
    foreach (['short-links.php', 'short-links/index.php', 'api/short-links-reset.php', 'api/short-links-tool.php', 'api/vjt-helpers.php'] as $file) {
        slh_write("{$tree}/public/{$file}", (string)file_get_contents("{$repo}/public/{$file}"));
    }
    foreach (glob("{$repo}/private/*.php") ?: [] as $file) {
        slh_write("{$tree}/private/" . basename($file), (string)file_get_contents($file));
    }
    $snapshot = json_decode((string)file_get_contents("{$repo}/private/cloudflare-ip-ranges.json"), true, 32, JSON_THROW_ON_ERROR);
    $snapshot['verified_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $snapshot['ipv4'][] = '127.0.0.0/8';
    $snapshot['ipv6'][] = '::1/128';
    slh_write("{$tree}/private/cloudflare-ip-ranges.json", json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    slh_write("{$tree}/private_config.php", "<?php\nreturn ['smtp_pass' => 'not-a-real-password'];\n");
    $users = ['# email bcrypt-hash [admin]'];
    foreach (SLH_PASSWORDS as $email => $password) {
        $users[] = $email . ' ' . password_hash($password, PASSWORD_DEFAULT) . (in_array($email, ['boss@kssmi.com', 'chief@kssmi.com'], true) ? ' admin' : '');
    }
    slh_write("{$tree}/short-links-users.txt", implode("\n", $users) . "\n");
    slh_write("{$tree}/public/vendor/autoload.php", <<<'PHP'
<?php
namespace PHPMailer\PHPMailer;

class Exception extends \Exception {}

class PHPMailer {
    public const ENCRYPTION_STARTTLS = 'tls';
    public $Host = ''; public $SMTPAuth = false; public $Username = ''; public $Password = '';
    public $SMTPSecure = ''; public $Port = 0; public $CharSet = ''; public $Subject = ''; public $Body = '';
    private array $to = [];
    public function __construct($exceptions = null) {}
    public function isSMTP(): void {}
    public function setFrom($address, $name = ''): bool { return true; }
    public function addAddress($address, $name = ''): bool { $this->to[] = $address; return true; }
    public function send(): bool {
        if (in_array('broken@kssmi.com', $this->to, true)) {
            throw new Exception('SMTP Error: Could not authenticate. relay-detail-7f3a');
        }
        $name = sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents(dirname(__DIR__, 2) . '/outbox/' . $name, json_encode(['to' => $this->to, 'subject' => $this->Subject, 'body' => $this->Body]));
        return true;
    }
}
PHP);
    slh_write("{$tree}/router.php", <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/short-links' || $path === '/short-links/') {
    require __DIR__ . '/public/short-links.php';
    return true;
}
return false;
PHP);
    foreach (['outbox', 'rate_limit', 'sessions', 'links-data'] as $dir) {
        if (!mkdir("{$tree}/{$dir}", 0700, true)) throw new RuntimeException("Unable to create {$dir}");
    }

    $probe = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($probe === false) throw new RuntimeException("No free port: {$errorText}");
    $address = (string)stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int)substr($address, strrpos($address, ':') + 1);

    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) {
        foreach (['pdo_sqlite', 'mbstring'] as $extension) {
            if (!extension_loaded($extension)) continue;
            $command[] = '-d'; $command[] = 'extension_dir=' . (string)ini_get('extension_dir');
            $command[] = '-d'; $command[] = 'extension=' . $extension;
        }
    }
    array_push(
        $command,
        '-d', 'session.save_path=' . $tree . '/sessions',
        '-d', 'display_errors=0',
        '-d', 'log_errors=1',
        '-S', "127.0.0.1:{$port}",
        '-t', "{$tree}/public",
        "{$tree}/router.php"
    );
    $environment = getenv();
    if (!is_array($environment)) $environment = [];
    $environment['KSSMI_SHORTLINK_DATA_DIR'] = "{$tree}/links-data";
    $pipes = [];
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', "{$tree}/server.out", 'a'], 2 => ['file', "{$tree}/server.log", 'a']], $pipes, $tree, $environment);
    slh_assert(is_resource($server), 'Unable to start the PHP built-in server.');
    $deadline = microtime(true) + 15;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.2);
        if ($socket !== false) { fclose($socket); break; }
        usleep(100000);
    } while (microtime(true) < $deadline);
    slh_assert($socket !== false, 'The PHP built-in server did not start.');

    $alice = 'alice@kssmi.com';
    $alicePassword = SLH_PASSWORDS[$alice];

    // 1. Every kind of failed sign-in gets the same status and page.
    $anonymous = new SlhBrowser($port, '203.0.113.10');
    $failures = [
        $anonymous->login('ghost@kssmi.com', 'some password 1'),
        $anonymous->login('alice@example.com', $alicePassword),
        $anonymous->login($alice, 'wrong password 2'),
    ];
    foreach ($failures as $failure) slh_assert(slh_rejected($failure), 'A failed sign-in did not get the uniform answer.');
    // An anonymous page view issues a fresh random CSRF token every time
    // (kssmi_short_links_session_principal() clears the anonymous tool
    // state), so only that value may differ.
    $page = static fn(array $response): string => (string)preg_replace('/(name="csrf_token" value="|id="sl_tool_csrf" value=")[a-f0-9]{64}"/', '$1<csrf>"', $response['body']);
    foreach ([1, 2] as $index) {
        slh_assert($page($failures[0]) === $page($failures[$index]), 'Failed sign-ins must produce identical pages; ' . slh_difference($page($failures[0]), $page($failures[$index])));
    }

    // 2. A successful sign-in issues a hardened device cookie.
    $aliceBrowser = new SlhBrowser($port, '203.0.113.11');
    $signedIn = $aliceBrowser->login($alice, $alicePassword);
    slh_assert(slh_signed_in($signedIn, $alice), 'The correct password must sign in.');
    $deviceLine = slh_set_cookie_line($signedIn, SLH_DEVICE_COOKIE);
    slh_assert($deviceLine !== null && preg_match('/^' . preg_quote(SLH_DEVICE_COOKIE, '/') . '=v1\.[0-9]{10,11}\.[a-f0-9]{32}\.[a-f0-9]{64};/', $deviceLine) === 1, 'Sign-in must issue a signed device cookie.');
    foreach (['/;\s*path=\/short-links(;|$)/i', '/;\s*secure(;|$)/i', '/;\s*httponly(;|$)/i', '/;\s*samesite=strict(;|$)/i', '/;\s*expires=/i'] as $attribute) {
        slh_assert(preg_match($attribute, $deviceLine) === 1, 'Device cookie is missing ' . $attribute);
    }
    $aliceDevice = $aliceBrowser->cookies[SLH_DEVICE_COOKIE];

    // 3. Guessing spread over many IPs is throttled per account, even with
    // the right password, and a made-up address behaves the same way.
    foreach (['boss@kssmi.com', 'nobody@kssmi.com'] as $target) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $guesser = new SlhBrowser($port, '198.51.100.' . (10 + $attempt));
            slh_assert(slh_rejected($guesser->login($target, "guess number {$attempt}")), "Guess {$attempt} for {$target} was not rejected uniformly.");
        }
        $last = new SlhBrowser($port, '198.51.100.99');
        $password = SLH_PASSWORDS[$target] ?? 'any password at all';
        slh_assert(slh_throttled($last->login($target, $password)), "The 11th attempt for {$target} must be throttled.");
    }

    // 4. A browser that signed in before keeps working for that account.
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $guesser = new SlhBrowser($port, '192.0.2.' . (10 + $attempt));
        slh_assert(slh_rejected($guesser->login($alice, "alice guess {$attempt}")), 'A guess for alice was not rejected uniformly.');
    }
    $stranger = new SlhBrowser($port, '192.0.2.99');
    slh_assert(slh_throttled($stranger->login($alice, $alicePassword)), 'An unknown browser must be throttled once the account budget is used up.');
    $known = new SlhBrowser($port, '192.0.2.98');
    $known->cookies[SLH_DEVICE_COOKIE] = $aliceDevice;
    slh_assert(slh_signed_in($known->login($alice, $alicePassword), $alice), 'A known device must still sign in.');

    // 5. Reset requests: one answer for every address; only a real account
    // gets mail; SMTP detail goes to the server log only.
    $resetter = new SlhBrowser($port, '203.0.113.20');
    $answers = [];
    foreach ([$alice, 'ghost@kssmi.com', 'alice@example.com', 'broken@kssmi.com'] as $email) {
        $answers[$email] = $resetter->json('/api/short-links-reset.php', ['action' => 'request', 'email' => $email]);
    }
    foreach ($answers as $email => $answer) {
        slh_assert($answer['status'] === 200 && $answer['body'] === '{"ok":true}', "Reset request for {$email} did not get the uniform answer: HTTP {$answer['status']} {$answer['body']}");
    }
    $outbox = slh_mail($tree);
    slh_assert(count($outbox) === 1 && $outbox[0]['to'] === [$alice], 'Only the registered address may be mailed.');
    slh_assert(str_contains((string)file_get_contents("{$tree}/server.log"), 'relay-detail-7f3a'), 'The SMTP failure must be logged on the server.');

    // 6. The per-address budget holds across source IPs.
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $other = new SlhBrowser($port, '192.0.2.' . (100 + $attempt));
        $answer = $other->json('/api/short-links-reset.php', ['action' => 'request', 'email' => $alice]);
        slh_assert($answer['status'] === 200 && $answer['body'] === '{"ok":true}', 'A reset request over budget must still get the uniform answer.');
    }
    $aliceMail = slh_mail_to($tree, $alice);
    slh_assert(count($aliceMail) === 3, 'An address may receive at most 3 reset mails per hour; got ' . count($aliceMail));

    // 7. Completing a reset sets a device cookie, so the colleague can sign in
    // while strangers are still throttled. The old cookie and password stop
    // working and the link is single-use.
    slh_assert(preg_match('/reset=([a-f0-9]{64})/', end($aliceMail)['body'], $match) === 1, 'The reset mail has no link.');
    $token = $match[1];
    $alicePassword = 'alice brand new password';
    $recovering = new SlhBrowser($port, '203.0.113.30');
    $confirmed = $recovering->json('/api/short-links-reset.php', ['action' => 'confirm', 'token' => $token, 'new_password' => $alicePassword]);
    slh_assert($confirmed['status'] === 200 && $confirmed['body'] === '{"ok":true}', 'Reset confirmation failed: ' . $confirmed['body']);
    slh_assert(slh_set_cookie_line($confirmed, SLH_DEVICE_COOKIE) !== null, 'A completed reset must issue a device cookie.');
    $reused = $recovering->json('/api/short-links-reset.php', ['action' => 'confirm', 'token' => $token, 'new_password' => 'another new password']);
    slh_assert($reused['status'] === 422, 'A reset link must be single-use.');
    $oldDevice = new SlhBrowser($port, '203.0.113.31');
    $oldDevice->cookies[SLH_DEVICE_COOKIE] = $aliceDevice;
    slh_assert(slh_throttled($oldDevice->login($alice, $alicePassword)), 'A device cookie from before the reset must stop counting as known.');
    slh_assert(slh_rejected($recovering->login($alice, SLH_PASSWORDS[$alice])), 'The old password must stop working.');
    $member = $recovering;
    slh_assert(slh_signed_in($member->login($alice, $alicePassword), $alice), 'The reset browser must sign in with the new password.');

    // 8. Role changes, deletion and password changes reach live sessions.
    $chief = new SlhBrowser($port, '203.0.113.40');
    slh_assert(slh_signed_in($chief->login('chief@kssmi.com', SLH_PASSWORDS['chief@kssmi.com']), 'chief@kssmi.com'), 'The administrator must sign in.');
    $list = static fn(SlhBrowser $browser): int => $browser->json('/api/short-links-tool.php', ['action' => 'account-list'])['status'];
    slh_assert($list($member) === 403, 'A member must not list accounts.');
    slh_assert($chief->json('/api/short-links-tool.php', ['action' => 'account-role', 'email' => $alice, 'admin' => true])['status'] === 200, 'Promotion failed.');
    slh_assert($list($member) === 200, 'A promotion must apply to the live session.');
    slh_assert($chief->json('/api/short-links-tool.php', ['action' => 'account-role', 'email' => $alice, 'admin' => false])['status'] === 200, 'Demotion failed.');
    slh_assert($list($member) === 403, 'A demotion must apply to the live session.');
    slh_assert($chief->json('/api/short-links-tool.php', ['action' => 'account-delete', 'email' => $alice])['status'] === 200, 'Deletion failed.');
    slh_assert($list($member) === 401, 'Deleting an account must end its session.');
    $changed = $chief->json('/api/short-links-tool.php', ['action' => 'change-password', 'current_password' => SLH_PASSWORDS['chief@kssmi.com'], 'new_password' => 'chief brand new password']);
    slh_assert($changed['status'] === 200 && slh_set_cookie_line($changed, SLH_DEVICE_COOKIE) !== null, 'A password change must succeed and keep the browser a known device.');
    slh_assert($list($chief) === 401, 'A password change must end the current session.');
    slh_assert(slh_signed_in($chief->login('chief@kssmi.com', 'chief brand new password'), 'chief@kssmi.com'), 'The new password must sign in.');

    // 9. The per-IP reset budget answers with a generic 429.
    $flooder = new SlhBrowser($port, '203.0.113.50');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        slh_assert($flooder->json('/api/short-links-reset.php', ['action' => 'request', 'email' => "flood{$attempt}@kssmi.com"])['status'] === 200, 'A reset request within the IP budget failed.');
    }
    $limited = $flooder->json('/api/short-links-reset.php', ['action' => 'request', 'email' => 'flood-last@kssmi.com']);
    slh_assert($limited['status'] === 429 && str_contains($limited['body'], 'Too many requests'), 'The IP budget must answer 429.');

    $log = (string)file_get_contents("{$tree}/server.log");
    slh_assert(preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $log) !== 1, "PHP reported problems:\n" . $log);
    echo "Short-links HTTP tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_dir($tree)) slh_remove_tree($tree);
}
