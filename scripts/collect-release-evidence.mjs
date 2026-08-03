import { readFile, readdir, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const inputRoot = path.resolve(process.argv[2] ?? 'evidence-input');
const outputRoot = path.resolve(process.argv[3] ?? 'release-evidence/final');
const repository = process.env.GITHUB_REPOSITORY;
const token = process.env.GITHUB_TOKEN;
const commitSha = process.env.GITHUB_SHA;
const runId = process.env.GITHUB_RUN_ID;
const runAttempt = process.env.GITHUB_RUN_ATTEMPT;
const releaseId = `${commitSha}-${runId}-${runAttempt}`;
const supportedReleaseId = /^[0-9a-f]{40}-[1-9][0-9]*(-[1-9][0-9]*)?$/;
if (!/^[0-9a-f]{40}-[1-9][0-9]*-[1-9][0-9]*$/.test(releaseId)) {
  throw new Error('GitHub release identity is invalid');
}
const apiBase = process.env.GITHUB_API_URL || 'https://api.github.com';
const errors = [];

const requestJson = async (apiPath) => {
  if (!token) throw new Error('GITHUB_TOKEN is unavailable');
  const response = await fetch(`${apiBase}/repos/${repository}${apiPath}`, {
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: `Bearer ${token}`,
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'kssmi-release-evidence',
    },
  });
  if (!response.ok) throw new Error(`GitHub API ${apiPath} returned ${response.status}`);
  return response.json();
};

const requestPages = async (apiPath, collectionKey = null, maximumPages = 10) => {
  const items = [];
  for (let page = 1; page <= maximumPages; page += 1) {
    const separator = apiPath.includes('?') ? '&' : '?';
    const response = await requestJson(`${apiPath}${separator}per_page=100&page=${page}`);
    const batch = collectionKey ? response[collectionKey] : response;
    if (!Array.isArray(batch)) throw new Error(`GitHub API pagination shape is invalid for ${apiPath}`);
    items.push(...batch);
    if (batch.length < 100) return items;
  }
  throw new Error(`GitHub API pagination exceeded ${maximumPages} pages for ${apiPath}`);
};

const findFile = async (directory, fileName) => {
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries) {
    const candidate = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      const nested = await findFile(candidate, fileName).catch(() => null);
      if (nested) return nested;
    } else if (entry.name === fileName) return candidate;
  }
  throw new Error(`${fileName} was not found in ${directory}`);
};

const loadEvidence = async (name, fileName) => {
  try {
    const file = await findFile(path.join(inputRoot, name), fileName);
    return JSON.parse(await readFile(file, 'utf8'));
  } catch (error) {
    errors.push(`${name}: ${error.message}`);
    return null;
  }
};

const findWorkflowJob = async (workflowFile, expectedName, options = {}) => {
  const query = new URLSearchParams({ per_page: '30', ...(options.headSha ? { head_sha: options.headSha } : {}) });
  const runs = await requestJson(`/actions/workflows/${encodeURIComponent(workflowFile)}/runs?${query}`);
  const run = runs.workflow_runs.find((candidate) => candidate.status === 'completed');
  if (!run) throw new Error(`${workflowFile} has no matching completed run`);
  if (options.requireSuccess && run.conclusion !== 'success') {
    throw new Error(`Latest matching ${workflowFile} run concluded ${run.conclusion}`);
  }
  const jobs = await requestJson(`/actions/runs/${run.id}/jobs?per_page=100`);
  const job = jobs.jobs.find((candidate) => candidate.name === expectedName);
  if (!job) throw new Error(`${expectedName} was not found in ${workflowFile} run ${run.id}`);
  return {
    workflow_run_id: run.id,
    workflow_url: run.html_url,
    workflow_conclusion: run.conclusion,
    job_name: job.name,
    job_url: job.html_url,
    job_conclusion: job.conclusion,
    completed_at: job.completed_at,
    head_sha: run.head_sha,
  };
};

const record = async (label, operation) => {
  try { return await operation(); }
  catch (error) { errors.push(`${label}: ${error.message}`); return null; }
};

const build = await loadEvidence('build', 'build.json');
const production = await loadEvidence('production', 'production.json');

const currentJobs = await record('current workflow jobs', async () => {
  const response = await requestJson(`/actions/runs/${runId}/jobs?per_page=100`);
  const requiredNames = [
    'Build and seal release artifact',
    'Deploy and verify production',
  ];
  return Object.fromEntries(requiredNames.map((name) => {
    const job = response.jobs.find((candidate) => candidate.name === name);
    if (!job) throw new Error(`Mandatory job is missing: ${name}`);
    return [name, { url: job.html_url, conclusion: job.conclusion, completed_at: job.completed_at }];
  }));
});

const phpCi = await record('PHP CI', () => findWorkflowJob(
  'php-ci.yml', 'PHP 8.3 full backend suite', { headSha: commitSha, requireSuccess: true },
));
const cloudflareAudit = await record('Cloudflare audit', () => findWorkflowJob(
  'cloudflare-ranges.yml', 'Compare repository snapshot with Cloudflare', { headSha: commitSha, requireSuccess: true },
));

const unresolvedP1 = await record('P1 issue audit', async () => {
  const response = await requestPages('/issues?state=open');
  const acceptedLabels = new Set(['p1', 'priority:p1', 'priority/p1', 'severity:p1']);
  return response.filter((issue) => !issue.pull_request && issue.labels.some((label) => acceptedLabels.has(String(label.name).toLowerCase())))
    .map((issue) => ({ number: issue.number, title: issue.title, url: issue.html_url }));
});

const ageDays = (date) => date ? (Date.now() - Date.parse(date)) / 86_400_000 : Number.POSITIVE_INFINITY;
const checks = {
  build_evidence_pass: build?.status === 'PASS' && build?.release_id === releaseId,
  production_evidence_pass: production?.status === 'PASS' && production?.release_id === releaseId,
  all_mandatory_deploy_jobs_green: currentJobs !== null && Object.values(currentJobs).every((job) => job.conclusion === 'success'),
  php_ci_same_commit_green: phpCi?.job_conclusion === 'success' && phpCi?.head_sha === commitSha,
  cloudflare_audit_same_commit_green_and_recent: cloudflareAudit?.job_conclusion === 'success' && cloudflareAudit?.head_sha === commitSha && ageDays(cloudflareAudit?.completed_at) <= 8,
  production_authenticated_admin_smoke: production?.authenticated_admin_smoke === 'PASS',
  production_permission_and_runtime_identity_proved: Boolean(production?.checks?.permission_policy && production?.checks?.runtime_identity && production?.checks?.runtime_capabilities),
  production_before_release_available: supportedReleaseId.test(production?.deployment?.previous_release_id ?? ''),
  production_after_release_matches: production?.deployment?.current_release_id === releaseId && production?.deployment?.final_health === 'PASS',
  no_unresolved_p1: Array.isArray(unresolvedP1) && unresolvedP1.length === 0,
  no_collection_errors: errors.length === 0,
};
const approved = Object.values(checks).every(Boolean);
const generatedAt = new Date().toISOString();
const evidence = {
  schema_version: 1,
  evidence_type: 'kssmi_release_signoff',
  release_id: releaseId,
  commit_sha: commitSha,
  run_id: runId,
  run_attempt: Number(runAttempt),
  generated_at: generatedAt,
  workflow_run_url: `${process.env.GITHUB_SERVER_URL}/${repository}/actions/runs/${runId}`,
  signoff: { status: approved ? 'APPROVED' : 'BLOCKED', checks, errors },
  build,
  environments: { production },
  github_checks: { deploy_jobs: currentJobs, php_ci: phpCi, cloudflare_audit: cloudflareAudit },
  unresolved_p1: unresolvedP1,
  rollback_readiness: {
    previous_accepted_release_id: production?.deployment?.previous_release_id ?? null,
    active_release_id: production?.deployment?.current_release_id ?? null,
    status: checks.production_before_release_available && checks.production_after_release_matches ? 'PASS' : 'FAIL',
  },
  secret_material_included: false,
};

await mkdir(outputRoot, { recursive: true });
await writeFile(path.join(outputRoot, 'release-evidence.json'), `${JSON.stringify(evidence, null, 2)}\n`);
await writeFile(path.join(outputRoot, 'release-evidence.md'), `# Kssmi release evidence and final signoff

- signoff: **${evidence.signoff.status}**
- release_id: ${releaseId}
- commit_sha: ${commitSha}
- run_attempt: ${runAttempt}
- generated_at: ${generatedAt}
- workflow: ${evidence.workflow_run_url}
- previous accepted production release: ${evidence.rollback_readiness.previous_accepted_release_id ?? 'MISSING'}
- active production release: ${evidence.rollback_readiness.active_release_id ?? 'MISSING'}
- automatic rollback: armed for activation, smoke and finalization failures
- secret material included: NO

## Acceptance checks

${Object.entries(checks).map(([name, passed]) => `- ${name}: ${passed ? 'PASS' : 'FAIL'}`).join('\n')}

## Collection errors

${errors.length ? errors.map((error) => `- ${error}`).join('\n') : '- none'}
`);
console.log(`Release evidence signoff: ${evidence.signoff.status}`);
