import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const workflow = read('.github/workflows/deploy.yml');
const phpCi = read('.github/workflows/php-ci.yml');
const cloudflareWorkflow = read('.github/workflows/cloudflare-ranges.yml');
const action = read('.github/actions/deploy-environment/action.yml');
const release = read('scripts/deploy-release.sh');
const docs = read('docs/promotion-policy.md');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const a = source.indexOf(first);
  const b = source.indexOf(second);
  assert.ok(a >= 0 && b >= 0 && a < b, message);
};

for (const marker of [
  'name: Deploy KSSMI Site',
  'build-release:',
  'deploy-production:',
  'needs: build-release',
  'Seal immutable release artifact',
  'sha256sum kssmi-release.tar',
  'sha256sum -c kssmi-release.tar.sha256',
  '${GITHUB_SHA}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}',
  'Download exact sealed release artifact',
  'Deploy verified artifact to production',
  'Require current official Cloudflare ranges',
]) assert.ok(workflow.includes(marker), `Production deployment workflow missing: ${marker}`);
requireBefore(workflow, 'build-release:', 'deploy-production:', 'Production must use the sealed build output.');
assert.doesNotMatch(workflow, /deploy-staging:|STAGING_|PRODUCTION_|vars\./, 'Production-only workflow must not depend on staging or GitHub Environment values.');

const production = workflow.slice(workflow.indexOf('  deploy-production:'));
for (const marker of [
  'site-url: https://kssmi.com',
  'site-host: kssmi.com',
  'private-root: /home/kssmi.com',
  'site-user: kssmi4374',
  'site-group: kssmi4374',
  'secrets.REMOTE_HOST',
  'secrets.REMOTE_USER',
  'secrets.SSH_PRIVATE_KEY',
  'secrets.SMOKE_ACCESS_CLIENT_ID',
  'secrets.SMOKE_ACCESS_CLIENT_SECRET',
  'secrets.SMOKE_ADMIN_PASSWORD',
]) assert.ok(production.includes(marker), `Production deployment input missing: ${marker}`);

const automationSources = [workflow, phpCi, cloudflareWorkflow, action];
const externalUses = [...automationSources.join('\n').matchAll(/^\s*uses:\s*([^\s#]+).*$/gm)]
  .map((match) => match[1])
  .filter((value) => !value.startsWith('./'));
for (const externalAction of externalUses) {
  assert.match(externalAction, /^[^@]+@[0-9a-f]{40}$/, `External action must use a full commit SHA: ${externalAction}`);
}
for (const source of automationSources) assert.ok(source.includes("node-version: '24'"), 'Every CI/deploy Node.js runtime must be version 24.');
assert.equal(packageJson.engines?.node, '>=24.0.0', 'The project Node.js contract must require Node.js 24.');
assert.equal(
  (workflow.match(/actions\/download-artifact@37930b1c2abaa49bbe596cd826c3c89aef350131/g) || []).length,
  3,
  'Production deploy and its two evidence inputs must use the pinned artifact action.',
);

for (const marker of [
  'test "$DEPLOY_ENVIRONMENT" = production',
  'test "$SITE_URL" = https://kssmi.com',
  'test "$PRIVATE_ROOT" = /home/kssmi.com',
  'if: failure()',
]) assert.ok(action.includes(marker), `Production action boundary missing: ${marker}`);
assert.doesNotMatch(action, /rollback-drill|staging/, 'Production deployment action must not contain staging fault-injection support.');

for (const marker of [
  'DEPLOY_ENVIRONMENT="${DEPLOY_ENVIRONMENT:-production}"',
  'This deployment manager is production-only.',
  'Production SITE_URL must be https://kssmi.com.',
  'PRIVATE_ROOT must resolve below /home, not to /home or filesystem root.',
  'is_supported_release_id',
  'legacy SHA/attempt markers remain supported',
]) assert.ok(release.includes(marker), `Production release manager boundary missing: ${marker}`);

assert.equal(packageJson.scripts?.['validate:promotion'], 'node scripts/validate-promotion-policy.mjs');
assert.ok(workflow.includes('npm run validate:promotion'));
for (const marker of ['仅 production', 'repository Secrets', '自动回滚', '同一个 artifact', '不需要 staging']) {
  assert.ok(docs.includes(marker), `Production-only deployment documentation missing: ${marker}`);
}

console.log('Production-only immutable deployment policy validated.');
