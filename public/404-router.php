<?php
/**
 * 404 Language Router
 * Reached via internal rewrite (see vhost.conf / .htaccess).
 * Detects language prefix from the ORIGINAL request URI and serves the matching
 * 404 page. Sets a real HTTP 404 status. Build format is "directory", so the
 * language pages live at /{lang}/404/index.html.
 */
$uri = $_SERVER['REQUEST_URI'];

// OpenLiteSpeed reaches this router for unmatched paths. Its per-directory
// rewrite handling can skip a preceding exact short-link rule, so make the
// six-character public namespace deterministic here as a final routing layer.
// No ordinary Astro route can match this exact namespace.
$requestPath = (string)(parse_url($uri, PHP_URL_PATH) ?? '');
if (preg_match('#^/([A-Z][A-Za-z0-9]{5})/?$#D', $requestPath, $shortLinkMatch) === 1) {
    $_GET['code'] = $shortLinkMatch[1];
    require __DIR__ . '/short-link-redirect.php';
    exit;
}

// English is the default locale and lives at the site root, so /en/<path> has
// no page of its own. The root .htaccess redirects /en and /en/, but for paths
// below the existing /en/ directory OpenLiteSpeed does not apply the root
// rules and the request lands here. Send it to the root page with 301 when
// that page exists (query string kept); otherwise fall through to the 404.
// Real files under /en/ (sitemaps, llms*.txt) are served directly and never
// reach this router. A redirect only goes to an existing file or page, which is
// served directly, so this cannot loop.
//
// The target must be a canonical site path: one or more segments of
// [A-Za-z0-9._~-] separated by single slashes, optional trailing slash, and no
// "." or ".." segment. Anything else (//, backslash, dot segments, percent
// encoding) gets the normal 404, so the Location header can never become a
// protocol-relative or off-site URL such as //faq/.
if (preg_match('#^/en/([A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*/?)$#D', $requestPath, $enMatch) === 1
    && preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $enMatch[1]) !== 1) {
    $target = '/' . $enMatch[1];
    $root = realpath(__DIR__);
    $resolved = realpath(__DIR__ . $target);
    $insideRoot = $root !== false && $resolved !== false
        && ($resolved === $root || strncmp($resolved, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0);
    $sitePath = strlen($target) > 1 && $target[0] === '/' && $target[1] !== '/' && strpos($target, '\\') === false;
    if ($sitePath && $insideRoot && (is_file($resolved) || is_file($resolved . DIRECTORY_SEPARATOR . 'index.html'))) {
        $query = (string)(parse_url($uri, PHP_URL_QUERY) ?? '');
        header('Location: ' . $target . ($query !== '' ? '?' . $query : ''), true, 301);
        header('Cache-Control: no-store, private', true);
        exit;
    }
}

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
