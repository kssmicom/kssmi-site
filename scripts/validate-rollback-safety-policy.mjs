import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const workflow = read('.github/workflows/deploy.yml');
const action = read('.github/actions/deploy-environment/action.yml');
const release = read('scripts/deploy-release.sh');
const docs = read('docs/rollback-drill.md');
const phpCi = read('.github/workflows/php-ci.yml');
const packageJson = JSON.parse(read('package.json'));

assert.doesNotMatch(workflow, /exercise_staging_rollback|deploy-staging:|rollback-drill/, 'Production workflow must not expose a fault-injection path.');
assert.doesNotMatch(action, /rollback-drill|staging/, 'Production action must not contain a dormant staging drill path.');
for (const marker of [
  'test "$DEPLOY_ENVIRONMENT" = production',
  'Rollback on failure',
  'if: failure()',
  'bash "$RELEASE_SCRIPT" rollback',
]) assert.ok(action.includes(marker), `Automatic rollback boundary missing: ${marker}`);

for (const marker of [
  'rollback_release()',
  'rollback_webroot',
  'restore_shared_private',
  'probe_email_log_integrity',
  'probe_vjt_integrity',
  'write_cutover_markers',
  'prove_cutover_barriers',
  'Release rollback completed:',
]) assert.ok(release.includes(marker), `Server rollback implementation missing: ${marker}`);

assert.equal(packageJson.scripts?.['validate:rollback-safety'], 'node scripts/validate-rollback-safety-policy.mjs');
for (const ciSource of [workflow, phpCi]) assert.ok(ciSource.includes('npm run validate:rollback-safety'));
for (const marker of ['production', '自动回滚', '不注入', 'staging', '人工恢复']) {
  assert.ok(docs.includes(marker), `Rollback documentation missing: ${marker}`);
}

console.log('Production automatic rollback policy validated; deliberate fault injection is disabled.');
