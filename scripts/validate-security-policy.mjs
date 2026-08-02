import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [smoke, adminSmoke, smokeTest, security, httpTest, packageJson, phpCi, deploy] = await Promise.all([
  readText('scripts/smoke-deployment.mjs'),
  readText('scripts/smoke-admin-security.mjs'),
  readText('scripts/test-smoke-deployment.mjs'),
  readText('private/http-security.php'),
  readText('scripts/test-http-security.php'),
  readText('package.json'),
  readText('.github/workflows/php-ci.yml'),
  readText('.github/workflows/deploy.yml'),
]);

assert.match(smoke, /requireSmokeCredentials\(process\.env\)/, 'Production smoke must require its admin credentials.');
assert.match(smoke, /runAuthenticatedAdminSmoke\s*\(/, 'Production smoke must execute the full admin security flow.');
assert.doesNotMatch(smoke, /skipAdminSmoke|canReachAdmin|skipping authenticated admin/i, 'Production smoke must never silently skip admin checks.');

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
assert.match(adminSmoke, /Core Events/, 'Smoke must verify the VJT Core data health marker.');

for (const scenario of [
  'wrong admin password must fail the smoke',
  'wrong Access credentials must fail the smoke',
  'weak session cookie attributes must fail the smoke',
  'weak marker cookie attributes must fail the smoke',
  'logout without CSRF must fail the smoke',
  'logout with invalid CSRF must fail the smoke',
]) {
  assert.match(smokeTest, new RegExp(scenario), `Smoke runner self-test must cover: ${scenario}.`);
}

assert.match(security, /setcookie\(['\"]vjt_admin['\"][\s\S]*?'secure'\s*=>\s*true[\s\S]*?'httponly'\s*=>\s*true[\s\S]*?'samesite'\s*=>\s*'Strict'/, 'Marker cookie policy must remain hardened.');
assert.match(httpTest, /plaintext forged marker does not exclude/, 'PHP tests must prove a forged marker cannot suppress analytics.');

const packageData = JSON.parse(packageJson);
assert.equal(packageData.scripts?.['test:smoke'], 'node scripts/test-smoke-deployment.mjs', 'package.json must expose smoke self-tests.');
assert.equal(packageData.scripts?.['validate:security'], 'node scripts/validate-security-policy.mjs', 'package.json must expose the security policy validator.');

for (const [label, workflow] of [['PHP CI', phpCi], ['Deploy', deploy]]) {
  assert.match(workflow, /npm run validate:security/, `${label} must enforce the unified security policy.`);
  assert.match(workflow, /npm run test:smoke/, `${label} must execute offline smoke runner self-tests.`);
}

const activateIndex = deploy.indexOf('- name: Activate versioned release atomically');
const smokeIndex = deploy.indexOf('- name: Run production deployment smoke');
const finalizeIndex = deploy.indexOf('- name: Finalize successful release');
assert.ok(activateIndex >= 0 && smokeIndex > activateIndex && finalizeIndex > smokeIndex, 'Production smoke must run after activation and before finalize.');
const productionSmokeStep = deploy.slice(smokeIndex, finalizeIndex);
assert.match(productionSmokeStep, /SMOKE_ACCESS_CLIENT_ID:\s*\$\{\{ secrets\.SMOKE_ACCESS_CLIENT_ID \}\}/, 'Deploy smoke must receive its Access client ID secret.');
assert.match(productionSmokeStep, /SMOKE_ACCESS_CLIENT_SECRET:\s*\$\{\{ secrets\.SMOKE_ACCESS_CLIENT_SECRET \}\}/, 'Deploy smoke must receive its Access client secret.');
assert.match(productionSmokeStep, /SMOKE_ADMIN_PASSWORD:\s*\$\{\{ secrets\.SMOKE_ADMIN_PASSWORD \}\}/, 'Deploy smoke must receive its admin password secret.');
assert.doesNotMatch(productionSmokeStep, /continue-on-error/, 'Production smoke must remain release-blocking.');

console.log('Unified admin security and mandatory production-smoke policy validated.');
