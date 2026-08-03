import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const environment = process.env.DEPLOY_ENVIRONMENT?.trim();
if (environment !== 'production') throw new Error('DEPLOY_ENVIRONMENT must be production');
const outputDirectory = path.resolve(process.argv[2] ?? `release-evidence/${environment}`);
const remote = process.env.REMOTE_EVIDENCE ?? '';
const parsed = Object.fromEntries(remote.split(/\r?\n/).flatMap((line) => {
  const index = line.indexOf('=');
  return index > 0 ? [[line.slice(0, index), line.slice(index + 1)]] : [];
}));
const outcome = (name) => process.env[name]?.trim() || 'skipped';
const expectedReleaseId = `${process.env.GITHUB_SHA}-${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`;
if (!/^[0-9a-f]{40}-[1-9][0-9]*-[1-9][0-9]*$/.test(expectedReleaseId)) {
  throw new Error('GitHub release identity is invalid');
}
const capabilities = [
  'private_modules_read', 'password_hash_read', 'gsc_read_if_present',
  'email_atomic_write', 'rate_limit_atomic_write',
  'sqlite_transaction_rollback', 'sqlite_wal_shm_modes',
];
const checks = {
  activation: outcome('ACTIVATE_OUTCOME') === 'success',
  authenticated_admin_smoke: outcome('SMOKE_OUTCOME') === 'success' && parsed.authenticated_admin_smoke === 'PASS',
  finalization: outcome('FINALIZE_OUTCOME') === 'success',
  remote_proof: outcome('PROOF_OUTCOME') === 'success' && remote.split(/\r?\n/).includes('KSSMI_DEPLOYMENT_EVIDENCE_V1'),
  environment_match: parsed.environment === environment,
  release_match: parsed.release_id === expectedReleaseId && parsed.current_release_id === expectedReleaseId,
  permission_policy: parsed.permission_policy === 'PASS',
  runtime_identity: /^\d+$/.test(parsed.runtime_uid ?? '') && /^\d+$/.test(parsed.runtime_gid ?? ''),
  runtime_capabilities: capabilities.every((name) => parsed[name] === 'PASS'),
  final_health: parsed.final_health === 'PASS',
};
const status = Object.values(checks).every(Boolean) ? 'PASS' : 'FAIL';
const evidence = {
  schema_version: 1,
  evidence_type: 'kssmi_environment_deployment',
  status,
  environment,
  release_id: expectedReleaseId,
  commit_sha: process.env.GITHUB_SHA,
  run_id: process.env.GITHUB_RUN_ID,
  run_attempt: Number(process.env.GITHUB_RUN_ATTEMPT),
  workflow_run_url: `${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID}`,
  generated_at: new Date().toISOString(),
  step_outcomes: {
    activation: outcome('ACTIVATE_OUTCOME'),
    authenticated_admin_smoke: outcome('SMOKE_OUTCOME'),
    finalization: outcome('FINALIZE_OUTCOME'),
    remote_proof: outcome('PROOF_OUTCOME'),
  },
  checks,
  deployment: {
    previous_release_id: parsed.previous_release_id ?? null,
    current_release_id: parsed.current_release_id ?? null,
    before_symlink_target: parsed.before_symlink_target ?? null,
    after_symlink_target: parsed.after_symlink_target ?? null,
    activated_at: parsed.activated_at ?? null,
    finalized_at: parsed.finalized_at ?? null,
    final_health: parsed.final_health ?? null,
  },
  runtime: {
    site_user: parsed.site_user ?? null,
    site_group: parsed.site_group ?? null,
    uid: parsed.runtime_uid ?? null,
    gid: parsed.runtime_gid ?? null,
    permission_policy: parsed.permission_policy ?? null,
    capabilities: Object.fromEntries(capabilities.map((name) => [name, parsed[name] ?? null])),
    capabilities_sha256: parsed.runtime_capabilities_sha256 ?? null,
  },
  authenticated_admin_smoke: parsed.authenticated_admin_smoke ?? null,
  secret_material_included: false,
};

await mkdir(outputDirectory, { recursive: true });
await writeFile(path.join(outputDirectory, `${environment}.json`), `${JSON.stringify(evidence, null, 2)}\n`);
await writeFile(path.join(outputDirectory, `${environment}.md`), `# Kssmi ${environment} deployment evidence

- status: ${status}
- release_id: ${expectedReleaseId}
- previous_release_id: ${evidence.deployment.previous_release_id ?? 'MISSING'}
- current_release_id: ${evidence.deployment.current_release_id ?? 'MISSING'}
- authenticated admin smoke: ${evidence.authenticated_admin_smoke ?? 'MISSING'}
- permission policy: ${evidence.runtime.permission_policy ?? 'MISSING'}
- runtime UID/GID: ${evidence.runtime.uid ?? 'MISSING'}/${evidence.runtime.gid ?? 'MISSING'}
- final health: ${evidence.deployment.final_health ?? 'MISSING'}
- workflow: ${evidence.workflow_run_url}
- secret material included: NO

## Checks

${Object.entries(checks).map(([name, passed]) => `- ${name}: ${passed ? 'PASS' : 'FAIL'}`).join('\n')}
`);
console.log(`${environment} deployment evidence: ${status}`);
