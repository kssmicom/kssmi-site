<?php
/**
 * 404 Language Router
 * Reached via internal rewrite (see vhost.conf / .htaccess).
 * Detects language prefix from the ORIGINAL request URI and serves the matching
 * 404 page. Sets a real HTTP 404 status. Build format is "directory", so the
 * language pages live at /{lang}/404/index.html.
 */
$uri = $_SERVER['REQUEST_URI'];
$langs = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];

// Extract language prefix: /zh/eyewear/xxx -> zh
$matched = null;
foreach ($langs as $l) {
    if (preg_match('#^/' . $l . '(?:/|$)#', $uri)) {
        $matched = $l;
        break;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private', true);

// Try language page (directory format first, then flat), then English fallback.
$candidates = [];
if ($matched) {
    $candidates[] = __DIR__ . '/' . $matched . '/404/index.html';
    $candidates[] = __DIR__ . '/' . $matched . '/404.html';
}
$candidates[] = __DIR__ . '/404.html';

foreach ($candidates as $file) {
    if (is_file($file)) {
        readfile($file);
        exit;
    }
}

// Last-resort inline fallback (should never be reached on a complete deploy).
echo '<!doctype html><meta charset="utf-8"><title>404 Not Found</title><h1>404 Not Found</h1>';
exit;
