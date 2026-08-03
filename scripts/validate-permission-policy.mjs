import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const policy = read('scripts/permission-policy.sh');
const release = read('scripts/deploy-release.sh');
const tests = read('scripts/test-permission-policy.sh');
const workflow = read('.github/workflows/deploy.yml');
const environmentAction = read('.github/actions/deploy-environment/action.yml');
const phpCi = read('.github/workflows/php-ci.yml');
const docs = read('docs/permission-policy.md');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex, message);
};

for (const marker of [
  'kssmi_policy_assert_path',
  'kssmi_policy_assert_tree',
  'kssmi_policy_reject_world_writable',
  'kssmi_policy_reject_executable_php',
  'kssmi_policy_reject_world_readable_files',
  'kssmi_policy_assert_symlink_allowlist',
  "stat -c '%a'",
  "stat -c '%u'",
  "stat -c '%g'",
  'readlink -f',
]) {
  assert.ok(policy.includes(marker), `Runtime permission policy missing: ${marker}`);
}

for (const marker of [
  'PERMISSION_POLICY="$RELEASE_DIR/scripts/permission-policy.sh"',
  '. "$PERMISSION_POLICY"',
  'verify_release_permission_policy',
  'verify_persistent_permission_policy',
  'kssmi_policy_assert_tree "$NEW_WEBROOT" 755 644',
  'kssmi_policy_assert_tree "$RELEASE_DIR/private" 750 640',
  'kssmi_policy_assert_path "$RELEASE_DIR/scripts/deploy-release.sh" file 750',
  'kssmi_policy_assert_path "$RUNTIME_PROBE_SOURCE" file 640',
  'kssmi_policy_assert_tree "$RATE_LIMIT_DIR" 750 600',
  'kssmi_policy_assert_tree "$VJT_DATA_DIR" 750 600',
  'kssmi_policy_assert_path "$GSC_JSON" file 600',
  'kssmi_policy_assert_sensitive_file "$PRIVATE_CONFIG"',
  'create_release_link "$RELEASE_DIR/private_config.php" "$PRIVATE_CONFIG"',
  'kssmi_policy_assert_symlink_allowlist "$RELEASE_DIR"',
  'install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 751 "$RELEASES_DIR"',
  'install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_ROOT"',
]) {
  assert.ok(release.includes(marker), `Release permission enforcement missing: ${marker}`);
}

assert.doesNotMatch(release, /chmod\s+(?:666|777)\b/, 'Deployment must never grant world write.');
assert.doesNotMatch(release, /-m\s+(?:666|777)\b/, 'Deployment must never install world-writable paths.');

const activationStart = release.indexOf('activate_release() {');
const activationEnd = release.indexOf('\nfinalize_release() {', activationStart);
assert.ok(activationStart >= 0 && activationEnd > activationStart, 'activate_release is missing.');
const activation = release.slice(activationStart, activationEnd);
requireBefore(
  activation,
  'prepare_release_layout',
  'verify_release_permission_policy',
  'The immutable release must be normalized before permission verification.',
);
requireBefore(
  activation,
  'verify_release_permission_policy',
  'validate_cloudflare_snapshot_pair',
  'Release permission verification must finish before deployment mutates shared runtime state.',
);
requireBefore(
  activation,
  'probe_vjt_integrity',
  'verify_persistent_permission_policy',
  'VJT files must be normalized and checked before persistent policy verification.',
);
requireBefore(
  activation,
  'verify_persistent_permission_policy',
  'activate_webroot',
  'Persistent permission verification must pass before the live webroot switches.',
);

for (const negativeCase of [
  'world-writable public file',
  'wrong public file mode',
  'executable PHP source',
  'world-readable private file',
  'wrong private file mode',
  'wrong owner contract',
  'unexpected out-of-bound symlink',
]) {
  assert.ok(tests.includes(negativeCase), `Permission tests missing negative case: ${negativeCase}`);
}

assert.ok(docs.includes('0755 / 0644'), 'Permission documentation must state the public contract.');
assert.ok(docs.includes('0750 / 0640'), 'Permission documentation must state the private contract.');
assert.ok(docs.includes('0751'), 'Permission documentation must explain the traverse exception.');
assert.ok(docs.includes('symlink'), 'Permission documentation must define symlink boundaries.');
assert.ok(docs.includes('private_config.php'), 'Permission documentation must protect the persistent application config.');

assert.equal(packageJson.scripts?.['validate:permissions'], 'node scripts/validate-permission-policy.mjs');
assert.equal(packageJson.scripts?.['test:permissions'], 'bash scripts/test-permission-policy.sh');
for (const ciSource of [workflow, phpCi]) {
  assert.ok(ciSource.includes('bash -n scripts/permission-policy.sh'));
  assert.ok(ciSource.includes('bash -n scripts/test-permission-policy.sh'));
  assert.ok(ciSource.includes('npm run validate:permissions'));
  assert.ok(ciSource.includes('npm run test:permissions'));
}
assert.ok(
  environmentAction.includes('source: "dist,private,scripts/deploy-release.sh,scripts/permission-policy.sh,scripts/runtime-capability-probe.php"'),
  'The runtime policy must ship in every immutable release.',
);

console.log('Permission and ownership policy-as-code validated.');
