import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [auditWorkflow, deployWorkflow, phpWorkflow, packageJson, documentation] = await Promise.all([
  readText('.github/workflows/cloudflare-ranges.yml'),
  readText('.github/workflows/deploy.yml'),
  readText('.github/workflows/php-ci.yml'),
  readText('package.json'),
  readText('docs/cloudflare-ranges.md'),
]);

assert.match(
  auditWorkflow,
  /push:\s*[\s\S]*?branches:\s*\[\s*main\s*\]/,
  'Cloudflare range audit must run for every main-branch release candidate.'
);
assert.match(
  auditWorkflow,
  /schedule:\s*[\s\S]*?cron:\s*['"][^'"\n]+['"]/,
  'Cloudflare range audit must have a scheduled trigger.'
);
assert.match(
  auditWorkflow,
  /workflow_dispatch\s*:/,
  'Cloudflare range audit must support a manual run.'
);
assert.match(
  auditWorkflow,
  /permissions:\s*\n\s+contents:\s*read\s*(?:\n|$)/,
  'Cloudflare range audit must use read-only repository permissions.'
);
assert.doesNotMatch(
  auditWorkflow,
  /contents:\s*write|\bgit\s+(?:commit|push)\b|update:cloudflare-ranges/,
  'Cloudflare range audit must never update, commit, or push the snapshot.'
);
assert.match(
  auditWorkflow,
  /timeout-minutes:\s*5\b/,
  'Cloudflare range audit must stop after five minutes.'
);
assert.match(
  auditWorkflow,
  /npm run validate:cloudflare-ranges -- --check-remote/,
  'Cloudflare range audit must compare the snapshot with the official endpoints.'
);
assert.match(
  auditWorkflow,
  /npm run test:cloudflare-ranges/,
  'Cloudflare range audit must run the offline boundary suite first.'
);

const actionUses = [...auditWorkflow.matchAll(/^\s*uses:\s*([^\s#]+).*$/gm)].map((match) => match[1]);
assert.ok(actionUses.length >= 2, 'Cloudflare range audit must explicitly pin its actions.');
for (const action of actionUses) {
  assert.match(
    action,
    /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[0-9a-f]{40}$/,
    'GitHub Action must be pinned to a full commit SHA: ' + action
  );
}

const packageScripts = JSON.parse(packageJson).scripts ?? {};
assert.equal(
  packageScripts['validate:cloudflare-audit'],
  'node scripts/validate-cloudflare-audit-policy.mjs',
  'package.json must expose the Cloudflare audit policy validator.'
);
for (const [label, workflow] of [
  ['deployment', deployWorkflow],
  ['PHP CI', phpWorkflow],
]) {
  assert.match(
    workflow,
    /npm run validate:cloudflare-audit/,
    label + ' must prevent Cloudflare audit policy regressions.'
  );
}

for (const requiredProcedure of [
  'Add new Cloudflare CIDRs to the origin allowlist',
  'Deploy the reviewed repository snapshot',
  'Remove obsolete CIDRs from the origin allowlist',
  'Rollback',
]) {
  assert.ok(
    documentation.includes(requiredProcedure),
    'Cloudflare documentation is missing procedure: ' + requiredProcedure
  );
}

console.log('Cloudflare weekly audit and origin-boundary policy validated.');
