import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const workflow = read('.github/workflows/deploy.yml');
const phpCi = read('.github/workflows/php-ci.yml');
const release = read('scripts/deploy-release.sh');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex, message);
};

// ── Workflow: versioned-release shape ──
for (const marker of [
  'cancel-in-progress: false',
  'Upload immutable release bundle',
  'source: "dist,private,scripts/deploy-release.sh"',
  'target: "/home/kssmi.com/releases/${{ github.sha }}-${{ github.run_attempt }}"',
  'Activate versioned release atomically',
  'Verify deployed security controls',
  'Finalize successful release',
  'Rollback on failure',
  'if: failure()',
  'bash "$RELEASE_SCRIPT" activate',
  'bash "$RELEASE_SCRIPT" rollback',
  'bash "$RELEASE_SCRIPT" finalize',
]) {
  assert.ok(workflow.includes(marker), `Deploy workflow missing release marker: ${marker}`);
}
assert.doesNotMatch(
  workflow,
  /easingthemes\/ssh-deploy|TARGET:\s*["']?\/home\/kssmi\.com\/public_html|target:\s*["']?\/home\/kssmi\.com\/public_html|--delete/,
  'Deploy workflow must not synchronize files directly into the live webroot.'
);
requireBefore(workflow, 'Upload immutable release bundle', 'Activate versioned release atomically', 'Activation must follow immutable upload.');
requireBefore(workflow, 'Activate versioned release atomically', 'Verify deployed security controls', 'Smoke must follow activation.');
requireBefore(workflow, 'Verify deployed security controls', 'Finalize successful release', 'Finalization must follow smoke.');

// ── Release manager: safety markers ──
for (const marker of [
  'set -Eeuo pipefail',
  'RELEASES_DIR="$PRIVATE_ROOT/releases"',
  'STATE_DIR="$STATE_ROOT/$RELEASE_ID"',
  'PRIVATE_BACKUP="$STATE_DIR/private-before"',
  'flock -x 9',
  'backup_shared_private',
  'restore_shared_private',
  'write_cutover_markers',
  'prove_cutover_barriers',
  'Cache-Control: no-cache',
  'run_root mv -Tf "$next_link" "$LIVE_WEBROOT"',
  'rollback_webroot',
  'state_write private_installed_at',
  'state_write activated_at',
  'state_write finalized_at',
]) {
  assert.ok(release.includes(marker), `Release manager missing safety marker: ${marker}`);
}
assert.doesNotMatch(
  release,
  /\brm\s+-(?:[A-Za-z]*r[A-Za-z]*|-[^\s]*recursive)\b/,
  'Release manager must not recursively delete release or runtime directories.'
);
assert.doesNotMatch(release, /chmod\s+755[^\n]*\.php/, 'PHP source files must not be made executable.');
assert.doesNotMatch(
  workflow,
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
  /chmod 750 "\$RELEASE_DIR\/scripts\/deploy-release\.sh"/,
  'Only the release manager needs an executable release-file mode.'
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

console.log('Versioned release, atomic activation and rollback policy validated.');
