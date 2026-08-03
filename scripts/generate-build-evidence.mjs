import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const outputDirectory = path.resolve(process.argv[2] ?? 'release-evidence/build');
const requiredEnv = (name) => {
  const value = process.env[name]?.trim();
  if (!value) throw new Error(`${name} is required`);
  return value;
};
const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

const commitSha = requiredEnv('GITHUB_SHA');
const runId = requiredEnv('GITHUB_RUN_ID');
const runAttempt = requiredEnv('GITHUB_RUN_ATTEMPT');
const releaseId = `${commitSha}-${runId}-${runAttempt}`;
if (!/^[0-9a-f]{40}-[1-9][0-9]*-[1-9][0-9]*$/.test(releaseId)) throw new Error('Invalid release id');

const snapshotBytes = await readFile('private/cloudflare-ip-ranges.json');
const snapshot = JSON.parse(snapshotBytes.toString('utf8'));
const manifestBytes = await readFile('dist/assets/runtime/manifest.json');
JSON.parse(manifestBytes.toString('utf8'));
const phpVersion = execFileSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }).trim();
const generatedAt = new Date().toISOString();

const evidence = {
  schema_version: 1,
  evidence_type: 'kssmi_release_build',
  status: 'PASS',
  commit_sha: commitSha,
  run_id: runId,
  run_attempt: Number(runAttempt),
  workflow_run_url: `${requiredEnv('GITHUB_SERVER_URL')}/${requiredEnv('GITHUB_REPOSITORY')}/actions/runs/${runId}`,
  release_id: releaseId,
  built_at: generatedAt,
  runtimes: { node: process.version, php: phpVersion },
  cloudflare_snapshot: {
    verified_at: snapshot.verified_at,
    sha256: sha256(snapshotBytes),
  },
  runtime_asset_manifest: {
    path: 'dist/assets/runtime/manifest.json',
    sha256: sha256(manifestBytes),
  },
  secret_material_included: false,
};

if (!/^\d{4}-\d{2}-\d{2}T/.test(String(snapshot.verified_at ?? ''))) {
  throw new Error('Cloudflare snapshot verified_at is missing or invalid');
}

await mkdir(outputDirectory, { recursive: true });
await writeFile(path.join(outputDirectory, 'build.json'), `${JSON.stringify(evidence, null, 2)}\n`);
await writeFile(path.join(outputDirectory, 'build.md'), `# Kssmi release build evidence

- status: PASS
- release_id: ${releaseId}
- commit_sha: ${commitSha}
- run_attempt: ${runAttempt}
- built_at: ${generatedAt}
- Node.js: ${process.version}
- PHP: ${phpVersion}
- Cloudflare snapshot verified_at: ${snapshot.verified_at}
- Cloudflare snapshot SHA-256: ${evidence.cloudflare_snapshot.sha256}
- runtime asset manifest SHA-256: ${evidence.runtime_asset_manifest.sha256}
- workflow: ${evidence.workflow_run_url}
- secret material included: NO
`);

console.log(`Build evidence generated for ${releaseId}`);
