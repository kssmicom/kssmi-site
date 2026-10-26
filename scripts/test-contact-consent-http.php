<?php
declare(strict_types=1);

// Real local PHP endpoints. No outbound requests or actual inquiries.
$repo = dirname(__DIR__);
$root = sys_get_temp_dir() . '/kssmi-contact-consent-' . bin2hex(random_bytes(6));
mkdir($root . '/public/api', 0700, true);
mkdir($root . '/private', 0700, true);
foreach (['vjt-capability.php','contact-intent.php','track-contact-intent.php','vjt-forget.php','vjt-helpers.php'] as $file) copy($repo . '/public/api/' . $file, $root . '/public/api/' . $file);
foreach (glob($repo . '/private/*.php') ?: [] as $file) copy($file, $root . '/private/' . basename($file));
copy($repo . '/private/cloudflare-ip-ranges.json', $root . '/private/cloudflare-ip-ranges.json');

function cc_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function cc_request(int $port, string $path, ?array $data = null, string $cookie = ''): array {
    $body = $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR);
    $socket = fsockopen('127.0.0.1', $port, $errno, $error, 5);
    if (!$socket) throw new RuntimeException('Could not connect to local PHP.');
    stream_set_timeout($socket, 15);
    fwrite($socket, implode("\r\n", [
        ($data === null ? 'GET' : 'POST') . ' ' . $path . ' HTTP/1.0',
        'Host: localhost:' . $port, 'Origin: http://localhost:4321',
        'Referer: http://localhost:4321/', 'Sec-Fetch-Site: same-origin', 'Sec-Fetch-Mode: navigate',
        'User-Agent: privacy-runtime-test', 'Content-Type: application/json',
        'Cookie: ' . $cookie, 'Content-Length: ' . strlen($body), 'Connection: close',
    ]) . "\r\n\r\n" . $body);
    $raw = (string)stream_get_contents($socket); fclose($socket);
    [$head, $content] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    preg_match('#^HTTP/\S+ (\d+)#', $head, $status);
    preg_match_all('/^Set-Cookie: ([^;\r\n]+)/mi', $head, $cookies);
    return ['status' => (int)($status[1] ?? 0), 'head' => $head, 'json' => json_decode($content, true), 'cookies' => $cookies[1]];
}

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($listener, false); fclose($listener);
$port = (int)substr($address, strrpos($address, ':') + 1);
$env = getenv(); if (!is_array($env)) $env = [];
$env['KSSMI_VJT_DATA_DIR'] = $root . '/vjt';
$env['KSSMI_VJT_EVENT_AUTH_SECRET'] = str_repeat('c', 64);
$pipes = [];
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public'], [0 => ['pipe','r'], 1 => ['file',$root . '/server.log','a'], 2 => ['file',$root . '/server.log','a']], $pipes, $root, $env);
try {
    cc_assert(is_resource($server), 'Start synthetic local PHP server.');
    $deadline = microtime(true) + 10;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    } while (microtime(true) < $deadline);
    $cycle = str_repeat('A', 22);
    $denied = cc_request($port, '/api/vjt-capability.php', ['mode'=>'contact','consent_cycle'=>$cycle]);
    cc_assert($denied['status'] === 403 && $denied['cookies'] === [], 'No affirmative measurement consent may issue a cookie.');
    $issued = cc_request($port, '/api/vjt-capability.php', ['mode'=>'contact','analytics'=>true,'consent_cycle'=>$cycle]);
    cc_assert($issued['status'] === 200 && count($issued['cookies']) === 1, 'Consented contact measurement may issue its cookie.');
    $cookie = $issued['cookies'][0];
    $capability = $issued['json']['capabilities']['contact_intent'][0];
    $base = '/api/contact-intent.php?channel=whatsapp&capability_token=' . rawurlencode($capability);
    $navigation = cc_request($port, $base, null, $cookie);
    cc_assert($navigation['status'] === 302 && str_contains($navigation['head'], 'https://wa.me/'), 'Contact navigation without consent must still work.');
    $measured = cc_request($port, $base . '&analytics=1&consent_cycle=' . $cycle, null, $cookie);
    cc_assert($measured['status'] === 302, 'Consented navigation must still redirect.');
    $dbFile = glob($root . '/vjt/*.sqlite') ?: [];
    cc_assert(count($dbFile) === 1, 'Synthetic VJT database exists.');
    $db = new PDO('sqlite:' . $dbFile[0]);
    cc_assert((int)$db->query('SELECT COUNT(*) FROM contact_events')->fetchColumn() === 1, 'Only the consented navigation may record an event.');
    $event = ['channel'=>'whatsapp','event_id'=>'vjtce_' . str_repeat('a', 32),'capability_token'=>$issued['json']['capabilities']['contact_intent'][1]];
    cc_assert(cc_request($port, '/api/track-contact-intent.php', $event, $cookie)['status'] === 403, 'Old cookie plus capability cannot bypass absence of consent.');
    $event['analytics'] = true; $event['consent_cycle'] = str_repeat('B',22);
    cc_assert(cc_request($port, '/api/track-contact-intent.php', $event, $cookie)['status'] === 403, 'A capability must not cross consent cycles.');
    $regrant = cc_request($port, '/api/vjt-capability.php', ['mode'=>'contact','analytics'=>true,'consent_cycle'=>str_repeat('B',22)], $cookie);
    cc_assert($regrant['status'] === 200 && $regrant['cookies'][0] !== $cookie, 'Regrant must replace an older cycle cookie.');
    $decodeContact = static function(string $cookie): array {
        $value = explode('=', $cookie, 2)[1];
        return json_decode(base64_decode(strtr(explode('.', $value)[0], '-_', '+/')), true);
    };
    cc_assert($decodeContact($regrant['cookies'][0])['contact_id'] !== $decodeContact($cookie)['contact_id'], 'Regrant must generate a new contact ID.');
    cc_assert(cc_request($port, '/api/track-contact-intent.php', $event, $regrant['cookies'][0])['status'] === 403, 'An earlier capability cannot operate under a later grant.');
    $forgotten = cc_request($port, '/api/vjt-forget.php', [], $cookie);
    cc_assert($forgotten['status'] === 204 && count($forgotten['cookies']) === 2, 'Withdrawal must expire both HttpOnly analytics and contact cookies.');
    cc_assert(str_contains($forgotten['head'], 'KSSMI_VJT_CONTACT=') && str_contains($forgotten['head'], 'KSSMI_VJT_IDENTITY='), 'Both identity names must be expired.');
    echo "Contact consent HTTP tests passed: no-consent rejection, redirect, storage gate, cycle isolation and withdrawal.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unset($db);
    // Synthetic SQLite fixture is retained; no production path is referenced.
    echo 'Synthetic contact fixture: ', $root, "\n";
}
