import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [issuer, auth, tracker, visitorTracker, packageJson, deployWorkflow, formTurnstile] = await Promise.all([
  readText('public/api/vjt-capability.php'),
  readText('private/vjt-event-auth.php'),
  readText('public/js/vjt-tracker.js'),
  // The proof provider and Contact Core (capability issuance) are runtime assets.
  Promise.all([
    readText('src/components/VisitorTracker.astro'),
    readText('public/js/vjt-capability-proof.js'),
    readText('public/js/vjt-contact-core.js'),
  ]).then((parts) => parts.join('\n')),
  readText('package.json'),
  readText('.github/workflows/deploy.yml'),
  readText('src/components/FormTurnstile.astro'),
]);

const proofCheck = issuer.indexOf('kssmi_vjt_verify_capability_turnstile(');
const firstIdentityWrite = issuer.indexOf('kssmi_vjt_bootstrap_identity(');
const firstCapabilityStateWrite = issuer.indexOf('vjt_data_init(');
assert.ok(proofCheck >= 0, 'Capability issuer must verify a server-side Turnstile proof.');
assert.ok(firstCapabilityStateWrite > proofCheck, 'Capability proof must be verified before any VJT state is initialized.');
assert.ok(firstIdentityWrite > proofCheck, 'Capability proof must be verified before analytics identity issuance.');
assert.match(issuer, /turnstile_token/, 'Capability issuer must read the browser proof from its request body.');
assert.match(issuer, /private_config\.php/, 'Capability issuer must load the non-public Turnstile secret.');
assert.match(issuer, /kssmi_vjt_is_local_development_request\(\)/, 'Only a TCP-bound local development request may skip Turnstile.');

assert.match(auth, /function kssmi_vjt_verify_capability_turnstile\(/, 'Shared VJT proof verifier must exist.');
assert.match(auth, /turnstile\/v0\/siteverify/, 'VJT proof verifier must call Turnstile Siteverify.');
assert.match(auth, /hash_equals\('vjt_capability'/, 'VJT proof verifier must bind the expected Turnstile action.');
assert.match(auth, /\['kssmi\.com', 'www\.kssmi\.com'\]/, 'VJT proof verifier must bind the production hostname.');
assert.match(auth, /REMOTE_ADDR/, 'Local bypass must inspect the TCP peer, not only request headers.');

assert.match(visitorTracker, /action:\s*'vjt_capability'/, 'Browser widget must request the VJT-specific Turnstile action.');
assert.match(visitorTracker, /execution:\s*'execute'/, 'Browser widget must create a fresh proof per capability request.');
assert.match(visitorTracker, /KSSMI_VJT_CAPABILITY_PROOF/, 'Browser must expose the proof provider to both contact and analytics flows.');
assert.match(visitorTracker, /var proofQueue = Promise\.resolve\(\)/, 'Concurrent capability requests must be queued, not share a single-use proof.');
assert.match(visitorTracker, /var next = proofQueue\.then\(obtainSingleProof\)/, 'Each queued capability request must obtain its own proof.');
assert.match(visitorTracker, /turnstile_token: token/, 'Contact Core capability issuance must submit the fresh proof.');
assert.match(tracker, /function requestCapabilityProof\(/, 'Analytics tracker must require the browser proof.');
assert.match(tracker, /turnstile_token: turnstileToken/, 'Analytics capability issuance must submit the fresh proof.');

// VJT proofs use their own Invisible widget: separate site key and secret,
// never the inquiry form's Managed pair (which can show a checkbox).
assert.ok(visitorTracker.includes('import.meta.env.PUBLIC_VJT_TURNSTILE_SITE_KEY'), 'VJT proof widget must use the dedicated VJT site key.');
assert.ok(!visitorTracker.includes('PUBLIC_TURNSTILE_SITE_KEY'), 'VJT proof widget must not fall back to the form site key.');
assert.ok(issuer.includes("['vjt_turnstile_secret']"), 'Capability issuer must verify with the dedicated VJT secret.');
assert.ok(!issuer.includes("['turnstile_secret']"), 'Capability issuer must not fall back to the form secret.');
assert.ok(deployWorkflow.includes('PUBLIC_VJT_TURNSTILE_SITE_KEY: ${{ secrets.PUBLIC_VJT_TURNSTILE_SITE_KEY }}'), 'Deploy build must pass the VJT site key.');
assert.ok(formTurnstile.includes('import.meta.env.PUBLIC_TURNSTILE_SITE_KEY'), 'Forms keep their own site key.');

// A statistics proof must never wait on visitor interaction.
for (const callback of ['before-interactive-callback', 'error-callback', 'unsupported-callback', 'timeout-callback']) {
  assert.ok(visitorTracker.includes("'" + callback + "'"), 'VJT proof widget must end the attempt on ' + callback + '.');
}
assert.match(visitorTracker, /function destroyAttemptWidget\(proofAttempt\)[\s\S]*?turnstile\.remove\(proofAttempt\.widgetId\)/, 'Ending an attempt must remove its own widget, not just hide it.');
assert.ok(visitorTracker.includes('navGeneration += 1;'), 'Navigation must void attempts from the previous page.');
assert.ok(visitorTracker.includes('COOLDOWN_MS = 30 * 60 * 1000'), 'A failed proof must start the 30-minute cooldown.');

const packageData = JSON.parse(packageJson);
assert.equal(packageData.scripts?.['test:vjt-capability-proof'], 'node scripts/test-vjt-capability-proof-policy.mjs', 'Package must expose the VJT proof regression check.');
assert.match(packageData.scripts?.['test:php'] ?? '', /test:vjt-capability-proof/, 'Full backend suite must include the VJT proof regression check.');

console.log('VJT capability proof policy validated.');
