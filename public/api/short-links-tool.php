<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/private/short-links-tool.php';
kssmi_admin_require_trusted_proxy(); kssmi_short_links_session();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !kssmi_short_links_origin_ok()) kssmi_short_links_json(['error'=>'Invalid request.'], 403);
$input = json_decode((string)file_get_contents('php://input'), true, 16);
if (!is_array($input) || !kssmi_admin_csrf_valid($input['csrf_token'] ?? null, 'short_links_tool_csrf')) kssmi_short_links_json(['error'=>'Security check failed.'], 403);
$who = kssmi_short_links_identity(); if (!$who) kssmi_short_links_json(['error'=>'Authentication required.'], 401);
$owner = $who['admin'] ? null : $who['email']; $action = (string)($input['action'] ?? '');
try {
    if ($action === 'list') kssmi_short_links_json(['rows'=>short_link_list((string)($input['search'] ?? ''), 250, 0, $owner)]);
    if ($action === 'tracking') kssmi_short_links_json(['tracking'=>short_link_tracking((int)($input['id'] ?? 0), 250, $owner)]);
    if ($action === 'destination') { $result=short_link_destination_create((string)($input['target_url'] ?? ''),$who['email']); if(!$result['created']) kssmi_short_links_json($result,409); kssmi_short_links_json($result); }
    if ($action === 'distribution') kssmi_short_links_json(['link'=>short_link_create_distribution((int)($input['destination_id'] ?? 0),$input,$who['email'])]);
    if (in_array($action, ['status','permanent-delete'], true)) { $row=short_link_get((int)($input['id'] ?? 0)); if(!$row) kssmi_short_links_json(['error'=>'Short link was not found.'],404); if(!$who['admin'] && !hash_equals((string)$row['created_by'],$who['email'])) kssmi_short_links_json(['error'=>'Forbidden.'],403); if($action==='status') short_link_set_status((int)$row['id'],(string)($input['status']??''),$who['email']); else short_link_permanently_delete((int)$row['id'],(string)($input['confirmation']??''),$who['email']); kssmi_short_links_json(['ok'=>true]); }
    if ($action === 'change-password') { $users=kssmi_short_links_users(); $row=$users[$who['email']]; if(!password_verify((string)($input['current_password']??''),$row['hash'])) kssmi_short_links_json(['error'=>'Current password is incorrect.'],422); $new=(string)($input['new_password']??''); if(strlen($new)<10||strlen($new)>128) kssmi_short_links_json(['error'=>'Password must be 10-128 characters.'],422); $row['hash']=password_hash($new,PASSWORD_DEFAULT); $users[$who['email']]=$row; if(!kssmi_short_links_write_users($users)) kssmi_short_links_json(['error'=>'Password could not be saved.'],500); kssmi_short_links_json(['ok'=>true]); }
    kssmi_short_links_json(['error'=>'Unknown action.'],400);
} catch (InvalidArgumentException $e) { kssmi_short_links_json(['error'=>$e->getMessage()],422); } catch (Throwable $e) { error_log('KSSMI short-links tool failure: '.$e->getMessage()); kssmi_short_links_json(['error'=>'Unable to process request.'],500); }
