import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const release = read('scripts/deploy-release.sh');
const action = read('.github/actions/deploy-environment/action.yml');
const prune = read('scripts/prune-old-releases.sh');
const functions = name => {
  const match = release.match(new RegExp(`^${name}\\(\\) \\{[\\s\\S]*?^\\}`, 'm'));
  assert.ok(match, `Missing function: ${name}`);
  return match[0];
};
const privilegedCalls = release.split('\n').filter(line => /^\s*run_root\s/.test(line));
assert.equal(privilegedCalls.length, 2, 'Only the two fixed worker controls may run as root');
assert.match(privilegedCalls[0], /run_root pkill -u "\$SITE_USER" -f lsphp/);
assert.match(privilegedCalls[1], /run_root \/usr\/local\/lsws\/bin\/lswsctrl restart/);
assert.match(functions('run_root'), /Refusing privileged deployment command/);
for (const helper of ['site_atomic_write', 'prepare_site_file', 'prepare_site_directory', 'materialize_release', 'atomic_install_file']) {
  assert.match(functions(helper), /run_as_site sh -c/, `${helper} must open and mutate as site`);
}
assert.doesNotMatch(release, /exec [0-9]+>/, 'Locks must not create or truncate');
for (const statement of ['exec 9<"$DEPLOY_LOCK"', 'exec 8<"$EMAIL_LOCK"', 'exec 9<"$LEGACY_EMAIL_LOG.lock"']) assert.ok(release.includes(statement));
assert.match(functions('main'), /prepare_site_file "\$DEPLOY_LOCK" 640[\s\S]*exec 9</);
assert.match(functions('state_read'), /run_as_site cat/);
assert.match(functions('main'), /\. "\$trusted_bundle\/scripts\/permission-policy.sh"/);
assert.doesNotMatch(release, /^\s*\. "\$PERMISSION_POLICY"/m);
assert.match(functions('main'), /Controller directory is writable by another account/);
assert.doesNotMatch(action, /(?:RELEASE_SCRIPT|PRUNE_SCRIPT)="\$PRIVATE_ROOT\/releases\//);
assert.equal((action.match(/RELEASE_SCRIPT="\/var\/lib\/kssmi-deploy\//g) || []).length, 4);
for (const marker of ['Prepare protected upload directory', 'mkdir -m 700 -- "/var/lib/kssmi-deploy/$RELEASE_ID"', 'chown -R root:root -- "$BUNDLE_ROOT"', 'tar_tmp_path: "/var/lib/kssmi-deploy"', 'Remove successful protected upload']) assert.ok(action.includes(marker), `Missing protected upload boundary: ${marker}`);
assert.ok(action.indexOf('chown -R root:root') < action.indexOf('chmod -R u+rwX,go+rX,go-w'));
assert.ok(!prune.includes('run_root'));
assert.match(prune, /exec sudo -u "\$SITE_USER"/);
for (const workflow of ['.github/workflows/deploy.yml', '.github/workflows/php-ci.yml']) {
  const source = read(workflow);
  for (const command of ['npm run validate:fs-safety', 'npm run test:deploy-fs-safety', 'sudo env F2_ROOT_TEST=1 bash scripts/test-deploy-fs-safety.sh']) assert.ok(source.includes(command), `${workflow} missing ${command}`);
}
console.log('Deployment root filesystem boundary policy validated.');
