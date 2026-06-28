<?php
/**
 * 404 Language Router
 * Detects language prefix from request URI and serves the matching 404 page.
 * Returns HTTP 404 status code.
 */
header('HTTP/1.1 404 Not Found');
header('X-Robots-Tag: noindex, nofollow');

$uri = $_SERVER['REQUEST_URI'];
$langs = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];

// Extract language prefix: /zh/eyewear/xxx → zh
$matched = null;
foreach ($langs as $l) {
    if (preg_match('#^/' . $l . '(?:/|$)#', $uri)) {
        $matched = $l;
        break;
    }
}

if ($matched && file_exists(__DIR__ . '/' . $matched . '/404.html')) {
    readfile(__DIR__ . '/' . $matched . '/404.html');
} else {
    readfile(__DIR__ . '/404.html');
}
exit;
