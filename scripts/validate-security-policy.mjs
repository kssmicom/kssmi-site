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

const packageData = JSON.parse(packageJson);
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
