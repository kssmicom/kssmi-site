import test from 'node:test';
import assert from 'node:assert/strict';
import {
  CLOUDFLARE_AUDIT_MAX_AGE_DAYS,
  isFreshAuditTimestamp,
  resolveExternalWorkflowJob,
  selectLatestApplicableRun,
} from '../scripts/lib/external-workflow-run.mjs';

const SHA_TARGET = 'a'.repeat(40);
const SHA_OTHER = 'b'.repeat(40);

function makeRun({
  id = 100,
  run_number = 10,
  head_sha = SHA_TARGET,
  event = 'push',
  head_branch = 'main',
  status = 'completed',
  conclusion = 'success',
  created_at = '2026-11-24T01:00:00Z',
} = {}) {
  return {
    id,
    run_number,
    head_sha,
    event,
    head_branch,
    status,
    conclusion,
    created_at,
    html_url: `https://example.invalid/runs/${id}`,
  };
}

function makeJob({
  name = 'PHP 8.3 full backend suite',
  status = 'completed',
  conclusion = 'success',
  completed_at = '2026-11-24T01:05:00Z',
} = {}) {
  return {
    name,
    status,
    conclusion,
    completed_at,
    html_url: `https://example.invalid/jobs/${encodeURIComponent(name)}`,
  };
}

function makeHarness({ runsSequence, jobsByRunId = {}, stepMs = 10_000 } = {}) {
  let currentMs = 1_700_000_000_000;
  let runCall = 0;
  const sleeps = [];
  const logs = [];

  return {
    state: {
      sleeps,
      logs,
      get runCalls() { return runCall; },
    },
    clock: () => currentMs,
    sleep: async (ms) => {
      sleeps.push(ms);
      currentMs += Math.max(ms, stepMs);
    },
    log: (msg) => logs.push(msg),
    fetchRuns: async () => {
      const idx = Math.min(runCall++, runsSequence.length - 1);
      const value = runsSequence[idx];
      if (value instanceof Error) throw value;
      return value;
    },
    fetchJobs: async (runId) => {
      const value = jobsByRunId[runId];
      if (value instanceof Error) throw value;
      return value ?? [];
    },
  };
}

test('selectLatestApplicableRun filters by SHA, event, and branch and picks newest run', () => {
  const wrongSha = makeRun({ id: 999, head_sha: SHA_OTHER, created_at: '2026-11-24T09:00:00Z' });
  const wrongEvent = makeRun({ id: 998, event: 'pull_request', head_branch: 'main', created_at: '2026-11-24T08:00:00Z' });
  const wrongBranch = makeRun({ id: 997, event: 'push', head_branch: 'verify/test', created_at: '2026-11-24T07:00:00Z' });
  const olderGreen = makeRun({ id: 100, run_number: 5, created_at: '2026-11-24T02:00:00Z', conclusion: 'success' });
  const newestInProgress = makeRun({ id: 101, run_number: 6, created_at: '2026-11-24T03:00:00Z', status: 'in_progress', conclusion: null });

  const picked = selectLatestApplicableRun(
    [wrongSha, wrongEvent, wrongBranch, olderGreen, newestInProgress],
    { headSha: SHA_TARGET, allowedEvents: ['push', 'workflow_dispatch'], allowedBranches: ['main'] },
  );
  assert.equal(picked?.id, 101);
});

test('waits boundedly through queued -> in_progress -> success', async () => {
  const h = makeHarness({
    runsSequence: [
      [makeRun({ id: 201, status: 'queued', conclusion: null })],
      [makeRun({ id: 201, status: 'in_progress', conclusion: null })],
      [makeRun({ id: 201, status: 'completed', conclusion: 'success' })],
    ],
    jobsByRunId: {
      201: [makeJob({ name: 'PHP 8.3 full backend suite', conclusion: 'success' })],
    },
  });

  const result = await resolveExternalWorkflowJob({
    workflowFile: 'php-ci.yml',
    expectedJobName: 'PHP 8.3 full backend suite',
    headSha: SHA_TARGET,
    allowedEvents: ['push', 'workflow_dispatch'],
    allowedBranches: ['main'],
    fetchRuns: h.fetchRuns,
    fetchJobs: h.fetchJobs,
    sleep: h.sleep,
    now: h.clock,
    log: h.log,
  });

  assert.equal(result.workflow_run_id, 201);
  assert.equal(result.workflow_conclusion, 'success');
  assert.equal(result.job_conclusion, 'success');
  assert.equal(result.head_sha, SHA_TARGET);
  assert.equal(h.state.runCalls, 3);
  assert.deepEqual(h.state.sleeps, [10_000, 10_000]);
});

test('in_progress -> failure fails closed immediately without retry', async () => {
  const h = makeHarness({
    runsSequence: [
      [makeRun({ id: 202, status: 'in_progress', conclusion: null })],
      [makeRun({ id: 202, status: 'completed', conclusion: 'failure' })],
    ],
  });

  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: h.fetchRuns,
      fetchJobs: h.fetchJobs,
      sleep: h.sleep,
      now: h.clock,
    }),
    /Latest matching php-ci\.yml run 202 concluded failure/,
  );
  assert.equal(h.state.runCalls, 2);
});

test('latest failure or in_progress NEVER falls back to older success run on the same SHA', async () => {
  const olderGreen = makeRun({ id: 100, run_number: 1, created_at: '2026-11-24T01:00:00Z', status: 'completed', conclusion: 'success' });

  // Case 1: latest run concluded failure, older run succeeded -> MUST fail closed
  const failedLatest = makeRun({ id: 200, run_number: 2, created_at: '2026-11-24T02:00:00Z', status: 'completed', conclusion: 'failure' });
  const h1 = makeHarness({
    runsSequence: [[olderGreen, failedLatest]],
    jobsByRunId: { 100: [makeJob()], 200: [makeJob({ conclusion: 'failure' })] },
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: h1.fetchRuns,
      fetchJobs: h1.fetchJobs,
      sleep: h1.sleep,
      now: h1.clock,
    }),
    /run 200 concluded failure/,
  );

  // Case 2: latest run is still in_progress while older run succeeded -> MUST wait on 201 and respect 201 outcome
  const inProgressLatest = makeRun({ id: 201, run_number: 2, created_at: '2026-11-24T02:00:00Z', status: 'in_progress', conclusion: null });
  const cancellingLatest = makeRun({ id: 201, run_number: 2, created_at: '2026-11-24T02:00:00Z', status: 'completed', conclusion: 'cancelled' });
  const h2 = makeHarness({
    runsSequence: [[olderGreen, inProgressLatest], [olderGreen, cancellingLatest]],
    jobsByRunId: { 100: [makeJob()] },
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: h2.fetchRuns,
      fetchJobs: h2.fetchJobs,
      sleep: h2.sleep,
      now: h2.clock,
    }),
    /run 201 concluded cancelled/,
  );
  assert.equal(h2.state.runCalls, 2);
});

test('non-success conclusions (cancelled, timed_out, skipped) fail closed', async () => {
  for (const conclusion of ['cancelled', 'timed_out', 'skipped']) {
    const h = makeHarness({
      runsSequence: [[makeRun({ id: 300, conclusion })]],
    });
    await assert.rejects(
      resolveExternalWorkflowJob({
        workflowFile: 'php-ci.yml',
        expectedJobName: 'PHP 8.3 full backend suite',
        headSha: SHA_TARGET,
        fetchRuns: h.fetchRuns,
        fetchJobs: h.fetchJobs,
        sleep: h.sleep,
        now: h.clock,
      }),
      new RegExp(`concluded ${conclusion}`),
    );
  }
});

test('whole-workflow failure blocks signoff even if PHP 8.3 job itself passed (e.g. content-regression failed)', async () => {
  const h = makeHarness({
    runsSequence: [[makeRun({ id: 400, status: 'completed', conclusion: 'failure' })]],
    jobsByRunId: {
      400: [
        makeJob({ name: 'PHP 8.3 full backend suite', conclusion: 'success' }),
        makeJob({ name: 'Content, media and SEO regression suite', conclusion: 'failure' }),
      ],
    },
  });

  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      requireSuccess: true,
      fetchRuns: h.fetchRuns,
      fetchJobs: h.fetchJobs,
      sleep: h.sleep,
      now: h.clock,
    }),
    /run 400 concluded failure/,
  );
});

test('waits boundedly for run to appear and fails closed if missing or timed out', async () => {
  // Appears on second poll and passes
  const appearOk = makeHarness({
    runsSequence: [
      [],
      [makeRun({ id: 500, status: 'completed', conclusion: 'success' })],
    ],
    jobsByRunId: {
      500: [makeJob()],
    },
  });
  const okResult = await resolveExternalWorkflowJob({
    workflowFile: 'php-ci.yml',
    expectedJobName: 'PHP 8.3 full backend suite',
    headSha: SHA_TARGET,
    fetchRuns: appearOk.fetchRuns,
    fetchJobs: appearOk.fetchJobs,
    sleep: appearOk.sleep,
    now: appearOk.clock,
    appearTimeoutMs: 25_000,
  });
  assert.equal(okResult.workflow_run_id, 500);
  assert.equal(appearOk.state.sleeps.length, 1);

  // Never appears -> fails after appearTimeoutMs (non-matching SHA/event/branch treated as missing)
  const neverAppears = makeHarness({
    runsSequence: [[makeRun({ id: 501, head_sha: SHA_OTHER })]],
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      allowedEvents: ['push'],
      allowedBranches: ['main'],
      fetchRuns: neverAppears.fetchRuns,
      fetchJobs: neverAppears.fetchJobs,
      sleep: neverAppears.sleep,
      now: neverAppears.clock,
      appearTimeoutMs: 20_000,
      pollIntervalMs: 10_000,
    }),
    /has no matching run/,
  );

  // Stuck in_progress -> fails after completeTimeoutMs
  const stuck = makeHarness({
    runsSequence: [[makeRun({ id: 502, status: 'in_progress', conclusion: null })]],
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: stuck.fetchRuns,
      fetchJobs: stuck.fetchJobs,
      sleep: stuck.sleep,
      now: stuck.clock,
      completeTimeoutMs: 20_000,
      pollIntervalMs: 10_000,
    }),
    /did not complete within 20s/,
  );
});

test('API errors and missing job names fail closed immediately', async () => {
  const apiFail = makeHarness({
    runsSequence: [new Error('GitHub API /runs returned 502')],
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: apiFail.fetchRuns,
      fetchJobs: apiFail.fetchJobs,
      sleep: apiFail.sleep,
      now: apiFail.clock,
    }),
    /returned 502/,
  );

  const missingJob = makeHarness({
    runsSequence: [[makeRun({ id: 600, status: 'completed', conclusion: 'success' })]],
    jobsByRunId: { 600: [makeJob({ name: 'Other job' })] },
  });
  await assert.rejects(
    resolveExternalWorkflowJob({
      workflowFile: 'php-ci.yml',
      expectedJobName: 'PHP 8.3 full backend suite',
      headSha: SHA_TARGET,
      fetchRuns: missingJob.fetchRuns,
      fetchJobs: missingJob.fetchJobs,
      sleep: missingJob.sleep,
      now: missingJob.clock,
    }),
    /was not found in php-ci\.yml run 600/,
  );
});

test('Cloudflare audit supports push/workflow_dispatch/schedule and enforces 8-day freshness', async () => {
  const h = makeHarness({
    runsSequence: [[makeRun({ id: 700, event: 'schedule', head_branch: 'main', status: 'completed', conclusion: 'success' })]],
    jobsByRunId: {
      700: [makeJob({ name: 'Compare repository snapshot with Cloudflare', completed_at: '2026-11-23T03:17:00Z' })],
    },
  });

  const audit = await resolveExternalWorkflowJob({
    workflowFile: 'cloudflare-ranges.yml',
    expectedJobName: 'Compare repository snapshot with Cloudflare',
    headSha: SHA_TARGET,
    allowedEvents: ['push', 'workflow_dispatch', 'schedule'],
    allowedBranches: ['main'],
    fetchRuns: h.fetchRuns,
    fetchJobs: h.fetchJobs,
    sleep: h.sleep,
    now: h.clock,
  });

  assert.equal(audit.workflow_event, 'schedule');
  const nowMs = Date.parse('2026-11-24T00:00:00Z');
  assert.equal(CLOUDFLARE_AUDIT_MAX_AGE_DAYS, 8);
  assert.equal(isFreshAuditTimestamp(audit.completed_at, { nowMs }), true);
  assert.equal(isFreshAuditTimestamp('2026-11-16T00:00:00Z', { nowMs }), true);
  assert.equal(isFreshAuditTimestamp('2026-11-15T23:59:59Z', { nowMs }), false);
  assert.equal(isFreshAuditTimestamp('', { nowMs }), false);
  assert.equal(isFreshAuditTimestamp('not-a-date', { nowMs }), false);
});
