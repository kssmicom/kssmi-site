<?php
declare(strict_types=1);

/*
 * Short-links account administration. This page must be covered by the same
 * Cloudflare Access application as the other administration paths. The origin
 * also requires a live short-links account carrying the admin role.
 */
require_once dirname(__DIR__) . '/private/short-links-tool.php';
kssmi_admin_require_trusted_proxy();
kssmi_short_links_session();
kssmi_admin_security_headers("default-src 'none'; base-uri 'none'; object-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'");

$principal = kssmi_short_links_session_principal();
if ($principal === null || $principal['admin'] !== true) {
    http_response_code(403);
    header('Cache-Control: no-store, private', true);
    echo 'Administrator access is required.';
    exit;
}
$csrf = kssmi_admin_csrf_token('short_links_tool_csrf');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Short-links accounts – KSSMI</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#333;margin:0;padding:28px}.wrap{max-width:900px;margin:auto;background:#fff;padding:24px;border-radius:10px}h1{margin-top:0;color:#5D4E37}form,.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0}input{padding:9px;border:1px solid #bbb;border-radius:4px}button,a.btn{padding:9px 12px;border:0;border-radius:4px;background:#8B7355;color:#fff;text-decoration:none;cursor:pointer}.danger{background:#b43b31}.muted{color:#666;font-size:13px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{padding:10px;border-bottom:1px solid #ddd;text-align:left}#message{min-height:22px}</style>
</head><body><main class="wrap"><h1>Short-links account administration</h1><p class="muted">Signed in as <?= htmlspecialchars($principal['email'], ENT_QUOTES, 'UTF-8') ?>. Removing an account or changing its password invalidates its old session on the next protected request.</p><p><a class="btn" href="/short-links">Back to short links</a></p>
<h2>Create account</h2><form id="create"><input id="newEmail" type="email" required placeholder="colleague@kssmi.com"><input id="newPassword" type="password" required minlength="10" maxlength="128" placeholder="Initial password (10+ chars)"><label><input id="newAdmin" type="checkbox"> Administrator</label><button>Create</button></form>
<p id="message" role="status"></p><h2>Accounts</h2><table><thead><tr><th>Email</th><th>Role</th><th>Actions</th></tr></thead><tbody id="accounts"></tbody></table>
</main><script>
const csrf=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>, message=document.getElementById('message'), tbody=document.getElementById('accounts');
async function api(payload){const r=await fetch('/api/short-links-tool.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf_token:csrf})});const d=await r.json();if(!r.ok)throw Error(d.error||'Request failed.');return d}
function note(text,bad=false){message.textContent=text;message.style.color=bad?'#b43b31':'#27734a'}
async function load(){try{const d=await api({action:'account-list'});tbody.replaceChildren(...d.accounts.map(a=>{const tr=document.createElement('tr');tr.innerHTML='<td></td><td></td><td></td>';tr.children[0].textContent=a.email;tr.children[1].textContent=a.admin?'Administrator':'Member';const actions=tr.children[2];const role=document.createElement('button');role.textContent=a.admin?'Make member':'Make admin';role.onclick=async()=>{try{await api({action:'account-role',email:a.email,admin:!a.admin});note('Role updated.');load()}catch(e){note(e.message,true)}};const reset=document.createElement('button');reset.textContent='Send reset email';reset.onclick=async()=>{try{const r=await fetch('/api/short-links-reset.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'request',email:a.email,csrf_token:csrf})});if(!r.ok)throw Error('Reset request failed.');note('Reset email requested.')}catch(e){note(e.message,true)}};const del=document.createElement('button');del.textContent='Delete';del.className='danger';del.onclick=async()=>{if(!confirm('Delete '+a.email+'?'))return;try{await api({action:'account-delete',email:a.email});note('Account deleted.');load()}catch(e){note(e.message,true)}};actions.append(role,' ',reset,' ',del);return tr}));}catch(e){note(e.message,true)}}
document.getElementById('create').onsubmit=async e=>{e.preventDefault();try{await api({action:'account-create',email:newEmail.value,password:newPassword.value,admin:newAdmin.checked});e.target.reset();note('Account created. Send its initial password securely, or use Send reset email.');load()}catch(e){note(e.message,true)}};load();
</script></body></html>
