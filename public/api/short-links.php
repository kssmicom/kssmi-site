<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/private/http-security.php';
kssmi_admin_require_trusted_proxy(); kssmi_admin_session_bootstrap();
require_once dirname(__DIR__, 2) . '/private/short-link-store.php';
require_once dirname(__DIR__) . '/api/vjt-helpers.php';
header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex, nofollow');
if (!kssmi_admin_session_authenticated(kssmi_admin_credential_version_path(dirname(__DIR__, 2) . '/.email_logs_password'))) { http_response_code(401); echo '{"error":"Authentication required."}'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"error":"POST required."}'; exit; }
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
if (!in_array($origin, ['https://kssmi.com', 'https://www.kssmi.com'], true) || !in_array($fetchSite, ['same-origin', 'same-site'], true)) { http_response_code(403); echo '{"error":"Invalid origin."}'; exit; }
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentType !== 'application/json' || $contentLength < 1 || $contentLength > 12288) { http_response_code(415); echo '{"error":"JSON request body required."}'; exit; }
$input = json_decode((string)file_get_contents('php://input'), true, 16);
if (!is_array($input)) { http_response_code(400); echo '{"error":"Invalid JSON body."}'; exit; }
if (!kssmi_admin_csrf_valid($input['csrf_token'] ?? null)) { http_response_code(403); echo '{"error":"Security check failed."}'; exit; }
require_once dirname(__DIR__, 2) . '/private/rate-limit.php';
if (!checkRateLimit('short-links-admin', 60, 300)) { http_response_code(429); echo '{"error":"Too many requests."}'; exit; }
try {
    $action = $input['action'] ?? ''; $admin = 'kssmi@kssmi.com';
    if ($action === 'destination') { $result = short_link_destination_create((string)($input['target_url'] ?? ''), $admin); if (!$result['created']) { http_response_code(409); } echo json_encode($result, JSON_THROW_ON_ERROR); exit; }
    if ($action === 'distribution') { $link = short_link_create_distribution((int)($input['destination_id'] ?? 0), $input, $admin); echo json_encode(['link'=>$link], JSON_THROW_ON_ERROR); exit; }
    if ($action === 'status') { short_link_set_status((int)($input['id'] ?? 0), (string)($input['status'] ?? ''), $admin); echo '{"ok":true}'; exit; }
    http_response_code(400); echo '{"error":"Unknown action."}';
} catch (InvalidArgumentException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); } catch (Throwable $e) { error_log('KSSMI short-link admin failure: '.$e->getMessage()); http_response_code(500); echo '{"error":"Unable to process short-link request."}'; }
