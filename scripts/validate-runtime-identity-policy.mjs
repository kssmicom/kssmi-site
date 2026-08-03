import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const release = read('scripts/deploy-release.sh');
const probe = read('scripts/runtime-capability-probe.php');
const workflow = read('.github/workflows/deploy.yml');
const phpCi = read('.github/workflows/php-ci.yml');
const docs = read('docs/runtime-identity.md');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex, message);
};

for (const marker of [
  'probe_real_runtime_capabilities',
  'cleanup_runtime_capability_probe',
  'X-Kssmi-Runtime-Probe',
  'X-Kssmi-Private-Root',
  "http://127.0.0.1/$RUNTIME_PROBE_NAME",
  'LSAPI runtime identity: uid=',
  'state_write runtime_capabilities',
  'private_modules_read password_hash_read gsc_read_if_present',
  'email_atomic_write rate_limit_atomic_write',
  'sqlite_transaction_rollback sqlite_wal_shm_modes',
]) {
  assert.ok(release.includes(marker), `Release runtime-identity gate missing: ${marker}`);
}
assert.ok(
  release.includes('run_root install -o "$SITE_USER" -g "$SITE_GROUP" -m 644'),
  'The temporary LSAPI probe must be installed non-executable.',
);
assert.doesNotMatch(
  release,
  /curl[^\n]*(?:-k|--insecure)/,
  'The runtime identity request must not weaken TLS policy elsewhere.',
);

const activationStart = release.indexOf('activate_release() {');
const activationEnd = release.indexOf('\nfinalize_release() {', activationStart);
assert.ok(activationStart >= 0 && activationEnd > activationStart, 'activate_release is missing.');
const activation = release.slice(activationStart, activationEnd);
requireBefore(
  activation,
  'verify_persistent_permission_policy',
  'probe_real_runtime_capabilities',
  'Filesystem modes must be verified before LSAPI capabilities are exercised.',
);
requireBefore(
  activation,
  'probe_real_runtime_capabilities',
  'activate_webroot',
  'The real LSAPI identity/capability gate must pass before public_html switches.',
);
assert.ok(
  activation.includes('cleanup_runtime_capability_probe'),
  'The activation failure trap must remove the temporary runtime probe.',
);

for (const marker of [
  "PHP_SAPI === 'cli'",
  "$_SERVER['REMOTE_ADDR']",
  "$_SERVER['HTTP_X_KSSMI_RUNTIME_PROBE']",
  "$_SERVER['HTTP_X_KSSMI_PRIVATE_ROOT']",
  "kssmi_probe_environment_config($privateRoot)",
  "$privateRoot . '/private_config.php'",
  "$privateRoot === '/home/.'",
  "$privateRoot === '/home/..'",
  "hash_equals($expectedRelease, $providedRelease)",
  "http_response_code(403)",
  "header('Cache-Control: no-store')",
  'posix_geteuid',
  "file_get_contents('/proc/self/status')",
  "function_exists('fsync')",
  'chmod($temporary, 0600)',
  'fsync($handle)',
  'rename($temporary, $moved)',
  "extension_loaded('pdo_sqlite')",
  "PRAGMA integrity_check",
  'beginTransaction()',
  'rollBack()',
  "'-wal'",
  "'-shm'",
]) {
  assert.ok(probe.includes(marker), `LSAPI probe missing capability/security marker: ${marker}`);
}
assert.doesNotMatch(
  probe,
  /echo\s+.*(?:password|service.account|file_get_contents)/i,
  'The LSAPI probe must never emit credential contents.',
);

assert.equal(
  packageJson.scripts?.['validate:runtime-identity'],
  'node scripts/validate-runtime-identity-policy.mjs',
);
assert.equal(
  packageJson.scripts?.['test:runtime-identity'],
  'php scripts/runtime-capability-probe.php --identity-self-test',
);
for (const ciSource of [workflow, phpCi]) {
  assert.ok(ciSource.includes('npm run validate:runtime-identity'));
  assert.ok(ciSource.includes('npm run test:runtime-identity'));
}
assert.ok(
  workflow.includes('scripts/runtime-capability-probe.php'),
  'The immutable release upload must include the LSAPI probe source.',
);
assert.ok(docs.includes('OpenLiteSpeed'), 'Runtime identity documentation must name the serving runtime.');
assert.ok(docs.includes('UID/GID'), 'Runtime identity documentation must define the identity evidence.');
assert.ok(docs.includes('private_config.php'), 'Runtime documentation must include the persistent config readability proof.');
assert.ok(docs.includes('before `public_html`'), 'Documentation must state the activation boundary.');

console.log('Real OpenLiteSpeed/LSAPI identity and capability policy validated.');
