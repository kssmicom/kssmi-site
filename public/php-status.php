<?php
// KSSMI PHP Status Check — permanently disabled in production.
// Source is preserved only in the Git history; do not re-enable on public site.
http_response_code(403);
header('Content-Type: text/plain');
die('403 Forbidden');
