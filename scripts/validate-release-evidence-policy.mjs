import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const workflow = read('.github/workflows/deploy.yml');
const action = read('.github/actions/deploy-environment/action.yml');
const release = read('scripts/deploy-release.sh');
const collector = read('scripts/collect-release-evidence.mjs');
const buildGenerator = read('scripts/generate-build-evidence.mjs');
const environmentGenerator = read('scripts/generate-environment-evidence.mjs');
const docs = read('docs/release-evidence.md');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const a = source.indexOf(first);
  const b = source.indexOf(second);
  assert.ok(a >= 0 && b >= 0 && a < b, message);
};

for (const marker of [
  'actions: read', 'issues: read',
  'Generate secret-free build evidence',
  'kssmi-build-evidence-${{ github.sha }}-${{ github.run_id }}-${{ github.run_attempt }}',
  'release-evidence:',
  'needs: [build-release, deploy-production]',
  'Publish release evidence and final signoff',
  'Download build evidence', 'Download production evidence',
  'collect-release-evidence.mjs',
  'kssmi-release-evidence-${{ github.sha }}-${{ github.run_id }}-${{ github.run_attempt }}',
  'retention-days: 90',
  'Enforce final release signoff',
]) assert.ok(workflow.includes(marker), `Release evidence workflow missing: ${marker}`);
assert.doesNotMatch(workflow, /Download staging evidence|evidence-input\/staging/, 'Production evidence must not require staging evidence.');
requireBefore(workflow, 'Generate secret-free build evidence', 'Seal immutable release artifact', 'Build evidence must precede sealing.');
requireBefore(workflow, 'Upload final evidence package', 'Enforce final release signoff', 'A blocked evidence package must remain downloadable.');

for (const marker of [
  'AUTHENTICATED_SMOKE_VERIFIED',
  'Authenticated admin smoke proof is required before finalization.',
  'KSSMI_DEPLOYMENT_EVIDENCE_V1',
  'previous_release_id=', 'runtime_uid=', 'runtime_gid=',
  'permission_policy=PASS', 'authenticated_admin_smoke=PASS',
  'runtime_capabilities_sha256=', 'final_health=PASS',
]) assert.ok(release.includes(marker), `Server evidence proof missing: ${marker}`);

for (const marker of [
  'id: activate', 'id: deployment-smoke', 'id: finalize',
  'AUTHENTICATED_SMOKE_VERIFIED=true',
  'Capture finalized environment evidence',
  "if: always() && steps.activate.outcome == 'success' && steps.deployment-smoke.outcome == 'success' && steps.finalize.outcome == 'success'",
  'Assemble environment deployment evidence',
  'Upload environment deployment evidence',
  'Enforce environment evidence gate',
]) assert.ok(action.includes(marker), `Production evidence action missing: ${marker}`);

for (const marker of [
  'cloudflare_snapshot', 'runtime_asset_manifest', 'built_at',
  'runtimes: { node:', 'secret_material_included: false',
  '`${commitSha}-${runId}-${runAttempt}`',
]) assert.ok(buildGenerator.includes(marker), `Build evidence field missing: ${marker}`);
for (const marker of [
  "environment !== 'production'",
  'authenticated_admin_smoke', 'permission_policy', 'runtime_identity',
  'runtime_capabilities', 'previous_release_id', 'final_health',
  'secret_material_included: false',
]) assert.ok(environmentGenerator.includes(marker), `Production evidence field missing: ${marker}`);
for (const marker of [
  'PHP 8.3 full backend suite',
  'Compare repository snapshot with Cloudflare',
  'Deploy and verify production',
  'no_unresolved_p1',
  'all_mandatory_deploy_jobs_green',
  'cloudflare_audit_same_commit_green_and_recent',
  'production_permission_and_runtime_identity_proved',
  "approved ? 'APPROVED' : 'BLOCKED'",
  'previous_accepted_release_id',
  'environments: { production }',
  'secret_material_included: false',
]) assert.ok(collector.includes(marker), `Final evidence collector missing: ${marker}`);
assert.doesNotMatch(collector, /rollbackDrill|staging_evidence|environments:\s*\{\s*staging/, 'Final signoff must not depend on staging or a rollback drill.');
assert.doesNotMatch(`${buildGenerator}\n${environmentGenerator}\n${collector}`, /SMOKE_(?:ACCESS_CLIENT_SECRET|ADMIN_PASSWORD)|SSH_PRIVATE_KEY/, 'Evidence scripts must not read deployment secrets.');

assert.equal(packageJson.scripts?.['validate:release-evidence'], 'node scripts/validate-release-evidence-policy.mjs');
for (const marker of ['APPROVED', 'BLOCKED', 'P1', 'Cloudflare', '自动回滚', '90', 'JSON', 'Markdown', 'production']) {
  assert.ok(docs.includes(marker), `Release evidence documentation missing: ${marker}`);
}

console.log('Production release evidence package and final signoff policy validated.');
