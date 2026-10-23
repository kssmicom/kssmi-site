import assert from 'node:assert/strict';
import { resolveRequiredJobs, REQUIRED_JOB_ATTEMPTS, REQUIRED_JOB_DELAY_MS } from './lib/required-jobs.mjs';

const NAMES = ['Build and seal release artifact', 'Deploy and verify production'];
const job = (name, status, conclusion) => ({
  name, status, conclusion, html_url: `https://example.invalid/${encodeURIComponent(name)}`, completed_at: conclusion ? '2026-10-23T07:26:10Z' : null,
});

function harness(sequence) {
  const state = { fetches: 0, sleeps: [], logs: [] };
  return {
    state,
    options: {
      requiredNames: NAMES,
      fetchJobs: async () => sequence[Math.min(state.fetches++, sequence.length - 1)],
      sleep: async (milliseconds) => { state.sleeps.push(milliseconds); },
      log: (message) => state.logs.push(message),
    },
  };
}

// Everything finished: one read, no waiting.
{
  const { state, options } = harness([[job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'completed', 'success')]]);
  const result = await resolveRequiredJobs(options);
  assert.equal(result[NAMES[1]].conclusion, 'success');
  assert.equal(state.fetches, 1);
  assert.deepEqual(state.sleeps, []);
}

// The run 39914881002 case: the deploy job still reads as unfinished right
// after it ended, then reports success.
{
  const { state, options } = harness([
    [job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'in_progress', null)],
    [job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'in_progress', null)],
    [job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'completed', 'success')],
  ]);
  const result = await resolveRequiredJobs(options);
  assert.equal(result[NAMES[1]].conclusion, 'success');
  assert.equal(state.fetches, 3);
  assert.deepEqual(state.sleeps, [REQUIRED_JOB_DELAY_MS, REQUIRED_JOB_DELAY_MS]);
  assert.equal(state.logs.length, 2);
  assert.match(state.logs[0], /Deploy and verify production \(status=in_progress, conclusion=null\)/);
}

// A completed job without a conclusion is also treated as not yet readable.
{
  const { state, options } = harness([
    [job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'completed', null)],
    [job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'completed', 'success')],
  ]);
  assert.equal((await resolveRequiredJobs(options))[NAMES[1]].conclusion, 'success');
  assert.equal(state.fetches, 2);
}

// A real failure is returned at once and never retried or softened.
for (const conclusion of ['failure', 'cancelled', 'timed_out', 'skipped']) {
  const { state, options } = harness([[job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'completed', conclusion)]]);
  const result = await resolveRequiredJobs(options);
  assert.equal(result[NAMES[1]].conclusion, conclusion, `${conclusion} must be reported as it is`);
  assert.equal(state.fetches, 1);
  assert.deepEqual(state.sleeps, []);
}

// A failure that is already final stays a failure while another job finishes.
{
  const { state, options } = harness([
    [job(NAMES[0], 'completed', 'failure'), job(NAMES[1], 'in_progress', null)],
    [job(NAMES[0], 'completed', 'failure'), job(NAMES[1], 'completed', 'success')],
  ]);
  const result = await resolveRequiredJobs(options);
  assert.equal(result[NAMES[0]].conclusion, 'failure');
  assert.equal(result[NAMES[1]].conclusion, 'success');
  assert.equal(state.fetches, 2);
}

// A job that never finishes ends in an error after the bounded number of
// checks; null is never turned into a conclusion.
{
  const { state, options } = harness([[job(NAMES[0], 'completed', 'success'), job(NAMES[1], 'in_progress', null)]]);
  await assert.rejects(
    resolveRequiredJobs(options),
    (error) => /not completed after 18 checks over about 85s/.test(error.message)
      && /Deploy and verify production \(status=in_progress, conclusion=null\)/.test(error.message)
  );
  assert.equal(state.fetches, REQUIRED_JOB_ATTEMPTS);
  assert.equal(state.sleeps.length, REQUIRED_JOB_ATTEMPTS - 1);
  assert.ok((REQUIRED_JOB_ATTEMPTS - 1) * REQUIRED_JOB_DELAY_MS <= 120_000, 'the wait must stay well inside the 10 minute job timeout');
}

// A job that is not part of the run is missing evidence, not a transient state.
{
  const { state, options } = harness([[job(NAMES[0], 'completed', 'success')]]);
  await assert.rejects(resolveRequiredJobs(options), /Mandatory job is missing: Deploy and verify production/);
  assert.equal(state.fetches, 1);
  assert.deepEqual(state.sleeps, []);
}

// A failing API read is not swallowed.
await assert.rejects(
  resolveRequiredJobs({ requiredNames: NAMES, fetchJobs: async () => { throw new Error('GitHub API /jobs returned 502'); }, sleep: async () => {} }),
  /returned 502/
);

console.log('Required-job conclusion tests passed.');
