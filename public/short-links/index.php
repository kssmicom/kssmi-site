<?php
declare(strict_types=1);

// OpenLiteSpeed directory-index fallback for /short-links when the root
// .htaccess rewrite is bypassed by the hosting layer.
require dirname(__DIR__) . '/short-links.php';
