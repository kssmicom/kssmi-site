import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const workflow = read('.github/workflows/deploy.yml');
const environmentAction = read('.github/actions/deploy-environment/action.yml');
const deployment = `${workflow}\n${environmentAction}`;
const phpCi = read('.github/workflows/php-ci.yml');
const release = read('scripts/deploy-release.sh');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex, message);
};

// ── Workflow: immutable promotion shape ──
for (const marker of [
  'cancel-in-progress: false',
  'Seal immutable release artifact',
  'Upload sealed release artifact',
  'deploy-production:',
  'needs: build-release',
  'scripts/prune-old-releases.sh',
]) {
  assert.ok(workflow.includes(marker), `Deploy workflow missing promotion marker: ${marker}`);
}

// ── Shared environment action: versioned-release shape ──
for (const marker of [
  'Upload immutable release bundle',
  'Check production disk space before upload',
  'source: "dist,private,scripts/deploy-release.sh,scripts/permission-policy.sh,scripts/runtime-capability-probe.php,scripts/prune-old-releases.sh"',
  'release-id:',
  'target: "${{ inputs.private-root }}/releases/${{ inputs.release-id }}"',
  'Activate versioned release atomically',
  'Verify deployed security controls',
  'Run authenticated deployment smoke',
  'Finalize successful release',
  'Prune old releases (keep newest)',
  'Rollback on failure',
  'if: failure()',
  'bash "$RELEASE_SCRIPT" activate',
  'bash "$RELEASE_SCRIPT" rollback',
  'bash "$RELEASE_SCRIPT" finalize',
]) {
  assert.ok(environmentAction.includes(marker), `Environment deploy action missing release marker: ${marker}`);
}
assert.ok(
  environmentAction.includes("RELEASE_ID='${{ inputs.release-id }}'"),
  'Environment deploy action must use the immutable release ID supplied by the sealed artifact.'
);
assert.doesNotMatch(
  deployment,
  /easingthemes\/ssh-deploy|TARGET:\s*["']?\/home\/kssmi\.com\/public_html|target:\s*["']?\/home\/kssmi\.com\/public_html|--delete/,
  'Deploy workflow must not synchronize files directly into the live webroot.'
);
requireBefore(environmentAction, 'Upload immutable release bundle', 'Activate versioned release atomically', 'Activation must follow immutable upload.');
requireBefore(environmentAction, 'Activate versioned release atomically', 'Verify deployed security controls', 'Security verification must follow activation.');
requireBefore(environmentAction, 'Verify deployed security controls', 'Run authenticated deployment smoke', 'Authenticated smoke must follow security verification.');
requireBefore(environmentAction, 'Run authenticated deployment smoke', 'Finalize successful release', 'Finalization must follow authenticated smoke.');

// ── Release manager: safety markers ──
for (const marker of [
  'set -Eeuo pipefail',
  'RELEASES_DIR="$PRIVATE_ROOT/releases"',
  'STATE_DIR="$STATE_ROOT/$RELEASE_ID"',
  'PRIVATE_BACKUP="$STATE_DIR/private-before"',
  'CLOUDFLARE_RANGES="$SHARED_PRIVATE/cloudflare-ip-ranges.json"',
  'PRIVATE_CONFIG="$PRIVATE_ROOT/private_config.php"',
  'flock -x 9',
  'backup_shared_private',
  'restore_shared_private',
  'validate_cloudflare_snapshot_pair',
  'write_cutover_markers',
  'prove_cutover_barriers',
  'Cache-Control: no-cache',
  'run_root mv -Tf "$next_link" "$LIVE_WEBROOT"',
  'rollback_webroot',
  'state_write private_installed_at',
  'state_write activated_at',
  'state_write finalized_at',
  'create_release_link "$RELEASE_DIR/private_config.php" "$PRIVATE_CONFIG"',
]) {
  assert.ok(release.includes(marker), `Release manager missing safety marker: ${marker}`);
}
assert.match(
  release,
  /PRIVATE_MODULES="[^"]*cloudflare-ip-ranges\.json rate-limit\.php[^"]*"/,
  'The verified Cloudflare snapshot must be installed before its PHP consumer.'
);
assert.doesNotMatch(
  release,
  /\brm\s+-(?:[A-Za-z]*r[A-Za-z]*|-[^\s]*recursive)\b/,
  'Release manager must not recursively delete release or runtime directories.'
);
assert.doesNotMatch(release, /chmod\s+755[^\n]*\.php/, 'PHP source files must not be made executable.');
assert.doesNotMatch(
  deployment,
  /find[^\n]*-name\s+["']?\*\.php["']?[^\n]*chmod\s+755/,
  'Deploy workflow must not make PHP source files executable.'
);
assert.match(
  release,
  /find "\$NEW_WEBROOT" -type d -exec chmod 755 \{\} \\;/,
  'Public release directories must use mode 0755.'
);
assert.match(
  release,
  /find "\$NEW_WEBROOT" -type f -exec chmod 644 \{\} \\;/,
  'Public release files, including PHP source, must use mode 0644.'
);
assert.match(
  release,
  /find "\$RELEASE_DIR\/private" -type f -exec chmod 640 \{\} \\;/,
  'Private release source files must use mode 0640.'
);
assert.match(
  release,
  /find "\$RELEASE_DIR\/scripts" -type f -name '\*\.sh' -exec chmod 750 \{\} \\;/,
  'Release and permission-policy shell scripts must use mode 0750.'
);
assert.match(
  release,
  /attempts=20[\s\S]*--connect-timeout 5 --max-time 10[\s\S]*sleep "\$delay_seconds"/,
  'Cutover barrier proof must retry long enough for PHP OPcache revalidation.'
);

// ── Release manager: ordered activation ──
const rollbackStart = release.indexOf('rollback_release() {');
const rollbackEnd = release.indexOf('\nactivate_release() {', rollbackStart);
assert.ok(rollbackStart >= 0 && rollbackEnd > rollbackStart, 'rollback_release function is missing.');
const rollbackBody = release.slice(rollbackStart, rollbackEnd);
requireBefore(
  rollbackBody,
  'write_cutover_markers',
  'prove_cutover_barriers',
  'Rollback cutover markers must be written before the application barrier is proven.'
);
assert.ok(
  rollbackBody.includes('restore_shared_private'),
  'Rollback must restore the shared PHP modules and Cloudflare snapshot backup.'
);

const installStart = release.indexOf('install_shared_private() {');
const installEnd = release.indexOf('\nrestore_shared_private() {', installStart);
assert.ok(installStart >= 0 && installEnd > installStart, 'install_shared_private function is missing.');
const installBody = release.slice(installStart, installEnd);
assert.ok(
  installBody.includes('run_as_site test -r "$SHARED_PRIVATE/$module"'),
  'Every installed private file must be readable by the real site account.'
);
assert.ok(
  installBody.includes('validate_cloudflare_snapshot_pair'),
  'The installed Cloudflare snapshot and PHP consumer must be validated together.'
);

const restoreStart = release.indexOf('restore_shared_private() {');
const restoreEnd = release.indexOf('\nwrite_cutover_markers() {', restoreStart);
assert.ok(restoreStart >= 0 && restoreEnd > restoreStart, 'restore_shared_private function is missing.');
const restoreBody = release.slice(restoreStart, restoreEnd);
assert.ok(
  restoreBody.includes('validate_cloudflare_snapshot_pair'),
  'Rollback must validate the restored Cloudflare snapshot and PHP consumer pair.'
);
requireBefore(
  rollbackBody,
  'prove_cutover_barriers',
  'rollback_webroot',
  'The shared application barrier must be proven before pointer rollback.'
);

const activationStart = release.indexOf('activate_release() {');
const activationEnd = release.indexOf('\nfinalize_release() {', activationStart);
assert.ok(activationStart >= 0 && activationEnd > activationStart, 'activate_release function is missing.');
const activationBody = release.slice(activationStart, activationEnd);
for (const [first, second, message] of [
  ['validate_cloudflare_snapshot_pair', 'backup_shared_private', 'The release snapshot must be validated before shared runtime backup or mutation.'],
  ['backup_shared_private', 'write_cutover_markers', 'Private backup must precede marker creation.'],
  ['write_cutover_markers', 'prove_cutover_barriers', 'Markers must be written before the barrier is proven.'],
  ['prove_cutover_barriers', 'install_shared_private', 'The application barrier must be proven before private modules change.'],
  ['install_shared_private', 'activate_webroot', 'Private modules and migrations must be ready before webroot activation.'],
  ['activate_webroot', 'clear_cutover_markers', 'Endpoints must remain blocked until activation completes.'],
]) {
  requireBefore(activationBody, first, second, message);
}

// ── Tooling: CI must gate the release policy itself ──
assert.equal(packageJson.scripts?.['validate:release'], 'node scripts/validate-release-policy.mjs');
assert.ok(workflow.includes('npm run validate:release'), 'Deploy must validate the release policy before build.');
assert.ok(workflow.includes('bash -n scripts/deploy-release.sh'), 'Deploy must syntax-check the release manager before build.');
assert.ok(phpCi.includes('bash -n scripts/deploy-release.sh'), 'php-ci must syntax-check the release manager.');
assert.ok(phpCi.includes('npm run validate:release'), 'php-ci must validate the release policy.');
for (const command of ['npm run validate:cloudflare-ranges', 'npm run test:cloudflare-ranges']) {
  assert.ok(workflow.includes(command), `Deploy must run the offline Cloudflare gate: ${command}`);
  assert.ok(phpCi.includes(command), `php-ci must run the offline Cloudflare gate: ${command}`);
}

console.log('Versioned release, atomic activation and rollback policy validated.');
