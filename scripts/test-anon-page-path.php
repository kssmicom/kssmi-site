<?php
declare(strict_types=1);

/**
 * Regression test for anonymous page-view route validation.
 * Run: php scripts/test-anon-page-path.php
 */
function kssmi_anon_path_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function kssmi_anon_path_remove_tree(string $directory): void {
    if (!is_dir($directory)) return;
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path)) kssmi_anon_path_remove_tree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kssmi-anon-path-' . bin2hex(random_bytes(6));
kssmi_anon_path_assert(mkdir($testDirectory, 0700, true), 'create test directory');

try {
    require_once dirname(__DIR__) . '/public/api/vjt-helpers.php';
    $publicRoot = $testDirectory . DIRECTORY_SEPARATOR . 'public';
    kssmi_anon_path_assert(mkdir($publicRoot . DIRECTORY_SEPARATOR . 'product' . DIRECTORY_SEPARATOR . 'valid', 0700, true), 'create nested page fixture');
    kssmi_anon_path_assert(file_put_contents($publicRoot . DIRECTORY_SEPARATOR . 'index.html', '<!doctype html>') !== false, 'write root page fixture');
    kssmi_anon_path_assert(file_put_contents($publicRoot . DIRECTORY_SEPARATOR . 'product' . DIRECTORY_SEPARATOR . 'valid' . DIRECTORY_SEPARATOR . 'index.html', '<!doctype html>') !== false, 'write nested page fixture');

    kssmi_anon_path_assert(vjt_public_anon_page_path('/', $publicRoot) === '/', 'root generated page is countable');
    kssmi_anon_path_assert(vjt_public_anon_page_path('/product/valid', $publicRoot) === '/product/valid/', 'clean URL without trailing slash canonicalizes');
    kssmi_anon_path_assert(vjt_public_anon_page_path('/product/valid/', $publicRoot) === '/product/valid/', 'clean URL with trailing slash is countable');
    kssmi_anon_path_assert(vjt_public_anon_page_path('/not-a-page/', $publicRoot) === '', 'nonexistent path is rejected before counting');
    kssmi_anon_path_assert(vjt_public_anon_page_path('/product/%76alid/', $publicRoot) === '', 'encoded path alias is rejected before counting');
    kssmi_anon_path_assert(vjt_public_anon_page_path('/product/valid/../other/', $publicRoot) === '', 'path traversal is rejected before filesystem resolution');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = 'localhost:4325';
    kssmi_anon_path_assert(vjt_public_anon_page_path('/dev-route') === '/dev-route/', 'local development route remains countable');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
    kssmi_anon_path_assert(vjt_public_anon_page_path('/dev-route') === '', 'nonlocal request cannot use the development fallback');

    fwrite(STDOUT, "Anonymous page-path test passed.\n");
} finally {
    kssmi_anon_path_remove_tree($testDirectory);
}
