<?php
declare(strict_types=1);

// Tests the /en/<path> redirect in public/404-router.php over HTTP, with PHP's
// built-in server standing in for OpenLiteSpeed: existing files are served
// directly and every other path reaches the 404 router.

$root = dirname(__DIR__);
$tree = sys_get_temp_dir() . '/kssmi-404-router-en-' . bin2hex(random_bytes(4));

function r404_write(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
}

function r404_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

// Returns [status, location] without following redirects.
function r404_get(int $port, string $path): array
{
    $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $headers = $http_response_header ?? [];
    if ($body === false && $headers === []) {
        r404_fail("no response for {$path}");
    }
    preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0] ?? '', $statusMatch);
    $location = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return [(int)($statusMatch[1] ?? 0), $location];
}

r404_write("{$tree}/404-router.php", (string)file_get_contents("{$root}/public/404-router.php"));
r404_write("{$tree}/index.html", '<!doctype html><title>Home</title>');
r404_write("{$tree}/404.html", '<!doctype html><title>Page Not Found</title>');
r404_write("{$tree}/faq/index.html", '<!doctype html><title>FAQ</title>');
r404_write("{$tree}/product/krs-210/index.html", '<!doctype html><title>KRS-210</title>');
r404_write("{$tree}/en/sitemap.xml", '<?xml version="1.0"?><urlset/>');
r404_write("{$tree}/en/llms.txt", 'llms');
// Stand-in for the web server: real files directly, everything else to the router.
r404_write("{$tree}/server-router.php", <<<'PHP'
<?php
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
// Only canonical paths map to files; //, backslashes and dot segments go to
// the router, as they would on the real server.
$canonical = preg_match('#//|\\\\|(^|/)\.{1,2}(/|$)#', $path) !== 1;
if (!$canonical) {
    require __DIR__ . '/404-router.php';
    return true;
}
if ($path !== '/' && is_file($file)) {
    return false;
}
if (is_file(rtrim($file, '/') . '/index.html')) {
    readfile(rtrim($file, '/') . '/index.html');
    return true;
}
require __DIR__ . '/404-router.php';
PHP);

$port = 0;
$probe = stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
if ($port === 0) {
    r404_fail('could not reserve a port');
}

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $tree, "{$tree}/server-router.php"],
    [0 => ['pipe', 'r'], 1 => ['file', "{$tree}/server.out", 'a'], 2 => ['file', "{$tree}/server.log", 'a']],
    $pipes,
    $tree,
);
if (!is_resource($server)) {
    r404_fail('could not start the PHP built-in server');
}

try {
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    $cases = [
        // path, expected status, expected Location ('' = none)
        ['/en/faq/', 301, '/faq/'],
        ['/en/faq/?utm_source=x&b=1', 301, '/faq/?utm_source=x&b=1'],
        ['/en/product/krs-210/', 301, '/product/krs-210/'],
        ['/en/does-not-exist/', 404, ''],
        ['/en/..%2F..%2Fetc%2Fpasswd', 404, ''],
        ['/en/sitemap.xml', 200, ''],
        ['/en/llms.txt', 200, ''],
        ['/en/en/sitemap.xml', 301, '/en/sitemap.xml'],
        ['/faq/', 200, ''],
        ['/zh/missing/', 404, ''],
        // Non-canonical targets must never redirect (no protocol-relative //faq/).
        ['/en//faq/', 404, ''],
        ['/en//faq', 404, ''],
        ['/en/faq//', 404, ''],
        ['/en/%2Ffaq/', 404, ''],
        ['/en/%5Cfaq/', 404, ''],
        ['/en/%2E%2E/faq/', 404, ''],
        ['/en/faq/%2E%2E/', 404, ''],
    ];
    foreach ($cases as [$path, $status, $location]) {
        [$gotStatus, $gotLocation] = r404_get($port, $path);
        if ($gotStatus !== $status || $gotLocation !== $location) {
            r404_fail("{$path}: expected {$status} {$location}, got {$gotStatus} {$gotLocation}");
        }
    }
    // Dot segments and raw backslashes: a server may normalize or reject them,
    // so accept 404 (or 400) or a redirect to exactly /faq/, never anything else.
    foreach (['/en/./faq/', '/en/../faq/', '/en/x/../faq/', '/en/\faq/', '/en/faq\x/', '/en/\\evil.example/'] as $path) {
        [$gotStatus, $gotLocation] = r404_get($port, $path);
        $acceptable = in_array($gotStatus, [400, 404], true) || ($gotStatus === 301 && $gotLocation === '/faq/');
        if (!$acceptable) {
            r404_fail("{$path}: got {$gotStatus} {$gotLocation}");
        }
    }
    // Invariant for every request above: a Location is a same-site absolute
    // path, never protocol-relative and never containing a backslash.
    foreach (array_merge(array_column($cases, 0), ['/en//faq/', '/en/\\evil.example/', '/en/\/evil.example/']) as $path) {
        [, $gotLocation] = r404_get($port, $path);
        if ($gotLocation !== '' && preg_match('#^/(?!/)[^\\\\]*$#D', $gotLocation) !== 1) {
            r404_fail("{$path}: unsafe Location {$gotLocation}");
        }
    }
    // Following the chain from any /en/ path must end without a loop.
    foreach (['/en/en/sitemap.xml', '/en/faq/'] as $start) {
        $path = $start;
        for ($hops = 0; $hops < 5; $hops++) {
            [$status, $location] = r404_get($port, $path);
            if ($status !== 301) {
                break;
            }
            $path = $location;
        }
        if ($hops >= 2) {
            r404_fail("{$start}: {$hops} redirects");
        }
    }
    echo "404 router /en redirect tests passed.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tree);
}
