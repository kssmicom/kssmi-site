import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [smoke, adminSmoke, smokeTest, security, httpTest, sendMail, rateLimit, rateLimitTest, packageJson, phpCi, deploy, environmentAction] = await Promise.all([
  readText('scripts/smoke-deployment.mjs'),
  readText('scripts/smoke-admin-security.mjs'),
  readText('scripts/test-smoke-deployment.mjs'),
  readText('private/http-security.php'),
  readText('scripts/test-http-security.php'),
  readText('public/send-mail.php'),
  readText('private/rate-limit.php'),
  readText('scripts/test-rate-limit.php'),
  readText('package.json'),
  readText('.github/workflows/php-ci.yml'),
  readText('.github/workflows/deploy.yml'),
  readText('.github/actions/deploy-environment/action.yml'),
]);

assert.match(smoke, /requireAdminSmokePolicy\(process\.env\)/, 'Smoke must require an explicit authenticated-admin policy.');
assert.match(smoke, /requireAdmin\s*\?\s*requireSmokeCredentials\(process\.env\)\s*:\s*null/, 'Required admin mode must fail on missing credentials.');
assert.match(smoke, /runAuthenticatedAdminSmoke\s*\(/, 'Production smoke must execute the full admin security flow.');
assert.doesNotMatch(smoke, /skipAdminSmoke|canReachAdmin/i, 'Smoke must not infer an admin skip from credential availability.');
assert.match(smoke, /SMOKE_REQUIRE_ADMIN=false \(local diagnostics only\)/, 'A local admin skip must be explicit and visible.');
assert.match(adminSmoke, /SMOKE_REQUIRE_ADMIN must be explicitly set to true or false/, 'Admin-smoke policy must fail closed when unset or invalid.');
assert.match(adminSmoke, /reflected a smoke credential in its response/, 'Authenticated smoke must reject credential reflection.');

for (const secret of ['SMOKE_ACCESS_CLIENT_ID', 'SMOKE_ACCESS_CLIENT_SECRET', 'SMOKE_ADMIN_PASSWORD']) {
  assert.match(adminSmoke, new RegExp(`missing\\.push\\(['\"]${secret}['\"]\\)`), `${secret} must be mandatory.`);
}
assert.match(adminSmoke, /invalid-smoke-token\.access/, 'Smoke must prove an invalid Access token cannot reach the login page.');
assert.match(adminSmoke, /assertHardenedCookie\(sessionCookie/, 'Smoke must inspect the initial session cookie.');
assert.match(adminSmoke, /assertHardenedCookie\(regeneratedSession/, 'Smoke must inspect the regenerated session cookie.');
assert.match(adminSmoke, /v1\\\.\[0-9\]\{10,11\}/, 'Smoke must require a signed vjt_admin marker shape.');
assert.match(adminSmoke, /missing-CSRF logout/, 'Smoke must reject logout without CSRF.');
assert.match(adminSmoke, /invalid-CSRF logout/, 'Smoke must reject logout with invalid CSRF.');
assert.match(adminSmoke, /email-logs\.php\?logout=1/, 'Smoke must prove GET logout is inert.');
assert.match(adminSmoke, /server-side admin session remained authenticated after logout/, 'Smoke must prove logout invalidates the server session.');
assert.match(adminSmoke, /Accepted Inquiries/, 'Smoke must verify the Email Logs storage health marker.');
assert.match(adminSmoke, /Core Event Stream/, 'Smoke must verify the VJT Core data health marker.');

for (const scenario of [
  'wrong admin password must fail the smoke',
  'wrong Access credentials must fail the smoke',
  'sensitive credentials reflected by an admin response must fail the smoke',
  'weak session cookie attributes must fail the smoke',
  'weak marker cookie attributes must fail the smoke',
  'logout without CSRF must fail the smoke',
  'logout with invalid CSRF must fail the smoke',
]) {
  assert.match(smokeTest, new RegExp(scenario), `Smoke runner self-test must cover: ${scenario}.`);
}

assert.match(security, /setcookie\(['\"]vjt_admin['\"][\s\S]*?'secure'\s*=>\s*true[\s\S]*?'httponly'\s*=>\s*true[\s\S]*?'samesite'\s*=>\s*'Strict'/, 'Marker cookie policy must remain hardened.');
assert.match(httpTest, /plaintext forged marker does not exclude/, 'PHP tests must prove a forged marker cannot suppress analytics.');

assert.match(rateLimit, /function kssmi_get_trusted_cloudflare_header\(/, 'Cloudflare headers must share a trusted-proxy resolver.');
assert.match(rateLimit, /function kssmi_get_trusted_cloudflare_country\(/, 'Cloudflare country must be ISO-code validated centrally.');
assert.match(sendMail, /kssmi_get_trusted_cloudflare_country\(\)/, 'Mail country lookup must use the trusted Cloudflare resolver.');
assert.doesNotMatch(sendMail, /\$_SERVER\['HTTP_CF_IPCOUNTRY'\]/, 'Mail country lookup must not read an untrusted header directly.');
assert.match(sendMail, /function kssmi_html_escape\(/, 'HTML email needs a shared output encoder.');
assert.match(sendMail, /ENT_QUOTES \| ENT_SUBSTITUTE,\s*'UTF-8'/, 'HTML email encoding must protect attribute and text contexts.');
assert.match(sendMail, /\$countryHtml = kssmi_html_escape\(\$country\)/, 'Country must be encoded at the HTML mail sink.');
assert.match(sendMail, /\$ipHtml = kssmi_html_escape\(\$ip\)/, 'IP must be encoded at the HTML mail sink.');
assert.match(rateLimitTest, /direct caller supplied a trusted Cloudflare country header/, 'PHP tests must cover forged country headers.');

// KS-04 (revised): every accepted inquiry is stored durably, answered at
// once, and gets its sales notification and automatic reply through the
// outbox. Abuse is stopped before acceptance; no budget may skip a reply.
const [autoReply, autoReplyTest, outbox, outboxTest, sendMailHttpTest, outboxWorker] = await Promise.all([
  readText('private/inquiry-autoreply.php'),
  readText('scripts/test-inquiry-autoreply.php'),
  readText('private/inquiry-outbox.php'),
  readText('scripts/test-inquiry-outbox.php'),
  readText('scripts/test-send-mail-outbox-http.php'),
  readText('private/inquiry-outbox-worker.php'),
]);
assert.ok(sendMail.includes("require_once dirname(__DIR__) . '/private/inquiry-outbox.php';"), 'send-mail.php must load the outbox.');
for (const forbidden of ['autoreply_budget', 'inquiry-autoreply-recipient', 'inquiry-autoreply-client']) {
  assert.ok(!sendMail.includes(forbidden) && !autoReply.includes(forbidden), `No budget may skip the reply of an accepted inquiry: ${forbidden}`);
}
assert.ok(sendMail.includes("'autoreply' => kssmi_inquiry_autoreply_build($formData)"), 'Every accepted inquiry must queue its automatic reply.');
assert.ok(autoReply.includes("$name = kssmi_inquiry_autoreply_name($data['name'] ?? '');"), 'The greeting must use only an accepted name.');
assert.ok(sendMail.includes("checkRateLimit('send-mail', 2, 60)"), 'The per-IP limit must stay in front of acceptance.');
const enqueueAt = sendMail.indexOf('$accepted = kssmi_outbox_enqueue(');
// The only success answer before acceptance is the blocked-sender branch: it
// runs after Turnstile, exits before attachments, and queues nothing.
const blocklistAt = sendMail.indexOf("$blocklistCheck = kssmi_sender_blocklist_check($formData['email']);");
const blocklistExitAt = sendMail.indexOf('exit;', blocklistAt);
const earlySuccessAt = sendMail.indexOf("'success' => true");
assert.ok(sendMail.includes("require_once dirname(__DIR__) . '/private/sender-blocklist.php';"), 'send-mail.php must load the sender blocklist.');
assert.ok(blocklistAt > sendMail.indexOf('verifyTurnstileDetailed($turnstileToken') && blocklistExitAt < sendMail.indexOf('kssmi_inquiry_validate_attachments('), 'The blocklist must be checked after Turnstile and before attachments.');
assert.ok(earlySuccessAt > blocklistAt && earlySuccessAt < blocklistExitAt, 'Only the blocked-sender branch may answer success before acceptance.');
assert.ok(!/kssmi_outbox_|logEmail\(|recordInquiryOutcome\(/.test(sendMail.slice(blocklistAt, blocklistExitAt)), 'A blocked sender must not be queued, logged or recorded as an inquiry.');
assert.ok(sendMail.slice(blocklistAt, blocklistExitAt).includes("kssmi_bump_submission_counter('blocklist_error')"), 'An unreadable blocklist must be counted.');
const successAt = sendMail.indexOf("'success' => true", blocklistExitAt);
const finishAt = sendMail.indexOf('kssmi_outbox_finish_response();');
const processAt = sendMail.indexOf('kssmi_outbox_process($accepted');
assert.ok(
  enqueueAt > sendMail.indexOf('verifyTurnstileDetailed($turnstileToken') && enqueueAt > sendMail.indexOf('kssmi_inquiry_validate_attachments('),
  'Turnstile and attachments must be checked before acceptance.'
);
assert.ok(enqueueAt > 0 && enqueueAt < successAt && successAt < finishAt && finishAt < processAt, 'Success may be answered only after the inquiry is stored, and mail is sent after the response.');
const notStored = sendMail.indexOf("if (!$accepted['ok']) {");
assert.ok(notStored > enqueueAt && sendMail.indexOf('http_response_code(503);', notStored) - notStored < 250, 'An inquiry that cannot be stored must not be reported as accepted.');
assert.ok(outbox.includes('fsync'), 'Outbox writes must be flushed to disk before acceptance.');
assert.ok(outbox.includes('KSSMI_OUTBOX_GIVE_UP_AFTER = 72 * 3600'), 'Failed mail must be retried for 72 hours.');
assert.ok(outbox.includes('KSSMI outbox ALERT'), 'Undeliverable mail must raise an alert.');
assert.ok(outboxWorker.includes("PHP_SAPI !== 'cli'"), 'The outbox worker must only run from the command line.');
for (const marker of ['A worker dying between the two messages', 'An uncertain send must be retried', 'Steps must be given up after 72 hours', 'A locked job must be skipped']) {
  assert.ok(outboxTest.includes(marker), `Outbox tests must cover: ${marker}`);
}
for (const marker of ['A second inquiry from the same mailbox must get its own notification and reply', 'An identical submission within two minutes must not send again', 'The worker must deliver the waiting job', 'A blocked sender must not cause any mail.', 'A damaged blocklist must not stop inquiries.']) {
  assert.ok(sendMailHttpTest.includes(marker), `Send-mail HTTP tests must cover: ${marker}`);
}
assert.ok(autoReplyTest.includes('No auto-reply budget may exist after acceptance.'), 'Auto-reply tests must prove there is no post-acceptance budget.');
const inquiryScripts = JSON.parse(packageJson).scripts ?? {};
for (const [script, file] of [
  ['test:inquiry-autoreply', 'test-inquiry-autoreply.php'],
  ['test:inquiry-outbox', 'test-inquiry-outbox.php'],
  ['test:send-mail-outbox-http', 'test-send-mail-outbox-http.php'],
  ['test:sender-blocklist', 'test-sender-blocklist.php'],
]) {
  assert.equal(inquiryScripts[script], `php scripts/${file}`, `package.json must expose ${script}.`);
  assert.ok((inquiryScripts['test:php'] ?? '').split(' && ').includes(`npm run ${script}`), `The full PHP suite must include ${script}.`);
  assert.ok(deploy.includes(`php scripts/${file}`), `Deploy must run ${file} before release.`);
}

const packageData = JSON.parse(packageJson);
const [shortLinkStore, shortLinkRedirect, shortLinkDashboard, shortLinkTest] = await Promise.all([
  readText('private/short-link-store.php'),
  readText('public/short-link-redirect.php'),
  readText('public/visitor-journey.php'),
  readText('scripts/test-short-links.php'),
]);
assert.match(shortLinkRedirect, /kssmi_check_rate_limit_identity\('short-link-open-event',\s*kssmi_get_client_ip\(\),\s*60,\s*3600,\s*1\)/, 'Public short-link analytics must enforce the strict 60-per-hour limit.');
assert.match(shortLinkStore, /KSSMI_SHORTLINK_EVENTS_PER_LINK/, 'Short-link storage must enforce a retained-event cap per link.');
assert.match(shortLinkStore, /KSSMI_SHORTLINK_EVENTS_TOTAL/, 'Short-link storage must enforce a retained-event cap globally.');
assert.match(shortLinkStore, /short_link_prune_events/, 'Short-link storage must automatically prune old detail events.');
assert.match(shortLinkStore, /total_opens = total_opens \+ excluded\.total_opens/, 'Short-link lifetime opens must survive detail pruning.');
assert.match(shortLinkStore, /short_link_begin_immediate\(\$db\)/, 'Short-link prune and insert decisions must be transactional.');
assert.match(shortLinkDashboard, /Older event details are automatically pruned/, 'The short-link dashboard must disclose automatic detail cleanup.');
assert.match(shortLinkTest, /sl_record_concurrently/, 'Short-link tests must exercise concurrent automatic cleanup.');

// KS-03: anonymous short-links sign-in and reset requests must not reveal
// accounts, and their budgets must not depend on the source IP alone.
const [shortLinksPage, shortLinksReset, shortLinksTool, shortLinksAuthTest, shortLinksHttpTest] = await Promise.all([
  readText('public/short-links.php'),
  readText('public/api/short-links-reset.php'),
  readText('private/short-links-tool.php'),
  readText('scripts/test-short-links-auth.php'),
  readText('scripts/test-short-links-http.php'),
]);
for (const message of ['This email is not authorized', 'Only @kssmi.com email addresses can sign in', "'Invalid password.'"]) {
  assert.ok(!shortLinksPage.includes(message), `Short-links sign-in must not reveal accounts: ${message}`);
}
assert.match(shortLinksPage, /kssmi_short_links_authenticate\(/, 'Short-links sign-in must use the budgeted, uniform check.');
assert.match(shortLinksTool, /password_verify\(\$password, \$known \? \$entry\['hash'\] : KSSMI_SHORT_LINKS_DUMMY_HASH\)/, 'Unknown addresses must run the same bcrypt check as accounts.');
assert.match(shortLinksTool, /kssmi_check_rate_limit_identity\('short-links-login-account', \$email,/, 'Sign-in must be budgeted per address without the IP.');
assert.match(shortLinksTool, /kssmi_check_rate_limit_identity\('short-links-reset-account', \$email,/, 'Reset mail must be budgeted per address without the IP.');
assert.match(shortLinksTool, /checkRateLimitGlobal\('short-links-reset-mail',/, 'Reset mail must have a global budget.');
assert.doesNotMatch(shortLinksTool, /Reset email failed: ' \. \$e->getMessage\(\)/, 'SMTP errors must not reach callers.');
assert.match(shortLinksReset, /kssmi_short_links_finish_response\(\)/, 'Reset requests must complete the response before account-dependent work.');
assert.ok(
  shortLinksReset.indexOf(`echo '{"ok":true}';`) < shortLinksReset.indexOf('kssmi_short_links_reset_request('),
  'Reset requests must be answered before the address is examined.'
);
assert.equal(
  shortLinksReset.split("json_encode(['error' => $error->getMessage()])").length - 1,
  1,
  'Only validation messages may be returned by the reset API.'
);
assert.match(shortLinksReset, /catch \(InvalidArgumentException \$error\) \{\s*http_response_code\(422\);\s*echo json_encode\(\['error' => \$error->getMessage\(\)\]\);/, 'Only validation messages may be returned by the reset API.');
assert.match(shortLinksHttpTest, /Failed sign-ins must produce identical pages/, 'HTTP tests must compare failed sign-in pages.');
assert.match(shortLinksHttpTest, /An address may receive at most 3 reset mails per hour/, 'HTTP tests must cover the per-address reset budget across IPs.');
assert.match(shortLinksAuthTest, /Concurrent reset requests exceeded the per-address budget/, 'PHP tests must cover concurrent reset budgets.');
for (const script of ['test:short-links-auth', 'test:short-links-http']) {
  assert.equal(packageData.scripts?.[script], `php scripts/${script.replace('test:', 'test-')}.php`, `package.json must expose ${script}.`);
  assert.match(packageData.scripts?.['test:php'] ?? '', new RegExp(`npm run ${script}(?:\\s|$)`), `The full PHP suite must include ${script}.`);
  assert.ok(deploy.includes(`php scripts/${script.replace('test:', 'test-')}.php`), `Deploy must run ${script} before release.`);
}
assert.equal(packageData.scripts?.['test:smoke'], 'node scripts/test-smoke-deployment.mjs', 'package.json must expose smoke self-tests.');
assert.equal(packageData.scripts?.['validate:security'], 'node scripts/validate-security-policy.mjs', 'package.json must expose the security policy validator.');

for (const [label, workflow] of [['PHP CI', phpCi], ['Deploy', deploy]]) {
  assert.match(workflow, /npm run validate:security/, `${label} must enforce the unified security policy.`);
  assert.match(workflow, /npm run test:smoke/, `${label} must execute offline smoke runner self-tests.`);
}

const activateIndex = environmentAction.indexOf('- name: Activate versioned release atomically');
const smokeIndex = environmentAction.indexOf('- name: Run authenticated deployment smoke');
const finalizeIndex = environmentAction.indexOf('- name: Finalize successful release');
assert.ok(activateIndex >= 0 && smokeIndex > activateIndex && finalizeIndex > smokeIndex, 'Authenticated environment smoke must run after activation and before finalize.');
const productionSmokeStep = environmentAction.slice(smokeIndex, finalizeIndex);
assert.match(productionSmokeStep, /SMOKE_REQUIRE_ADMIN:\s*['"]true['"]/, 'Every release environment must force authenticated admin smoke.');
assert.match(productionSmokeStep, /SMOKE_ACCESS_CLIENT_ID:\s*\$\{\{ inputs\.smoke-access-client-id \}\}/, 'Deploy smoke must receive its production Access client ID.');
assert.match(productionSmokeStep, /SMOKE_ACCESS_CLIENT_SECRET:\s*\$\{\{ inputs\.smoke-access-client-secret \}\}/, 'Deploy smoke must receive its production Access client secret.');
assert.match(productionSmokeStep, /SMOKE_ADMIN_PASSWORD:\s*\$\{\{ inputs\.smoke-admin-password \}\}/, 'Deploy smoke must receive its production admin password.');
assert.doesNotMatch(productionSmokeStep, /continue-on-error/, 'Production smoke must remain release-blocking.');
const rollbackIndex = environmentAction.indexOf('- name: Rollback on failure');
assert.ok(rollbackIndex > finalizeIndex, 'A failed required smoke must reach the environment rollback step.');
assert.match(environmentAction.slice(rollbackIndex), /if:\s*failure\(\)/, 'Environment rollback must run on smoke failure.');
for (const secret of [
  'SMOKE_ACCESS_CLIENT_ID',
  'SMOKE_ACCESS_CLIENT_SECRET',
  'SMOKE_ADMIN_PASSWORD',
]) {
  assert.ok(deploy.includes(`secrets.${secret}`), `Production promotion must pass ${secret}.`);
}

const sealStart = deploy.indexOf('- name: Seal immutable release artifact');
const sealEnd = deploy.indexOf('- name: Upload sealed release artifact', sealStart);
assert.ok(sealStart >= 0 && sealEnd > sealStart, 'Immutable artifact sealing step is missing.');
const sealStep = deploy.slice(sealStart, sealEnd);
assert.doesNotMatch(sealStep, /SMOKE_|secrets\./, 'Smoke credentials must never enter the release artifact.');

const adminSmokeDocs = await readText('docs/admin-smoke-policy.md');
for (const marker of ['SMOKE_REQUIRE_ADMIN=true', 'local diagnostics', 'repository-scoped', '不得写入 artifact', 'rotation']) {
  assert.ok(adminSmokeDocs.includes(marker), `Admin-smoke documentation missing: ${marker}`);
}

console.log('Unified admin security and mandatory production-smoke policy validated.');
