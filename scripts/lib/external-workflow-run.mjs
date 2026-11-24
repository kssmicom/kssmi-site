/**
 * Deterministic selection and bounded polling for external GitHub Actions runs
 * required by final release evidence (e.g., php-ci.yml and cloudflare-ranges.yml).
 *
 * Key guarantees:
 * - Strictly selects the latest applicable workflow run matching commit SHA,
 *   allowed event(s), and allowed branch(es).
 * - Never skips past an in-progress or failed latest run to an older green run.
 * - Fails closed on completion errors, timeouts, missing runs/jobs, or API errors.
 */

export const EXTERNAL_RUN_APPEAR_TIMEOUT_MS = 60_000;
export const EXTERNAL_RUN_COMPLETE_TIMEOUT_MS = 300_000;
export const EXTERNAL_RUN_POLL_INTERVAL_MS = 10_000;
export const CLOUDFLARE_AUDIT_MAX_AGE_DAYS = 8;

const defaultSleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function runTimestampMs(run) {
  const created = typeof run?.created_at === 'string' ? Date.parse(run.created_at) : Number.NaN;
  return Number.isFinite(created) ? created : 0;
}

/**
 * Select the single newest applicable workflow run for a commit SHA.
 * Returns null if no run matches the commit + event/branch filters.
 */
export function selectLatestApplicableRun(
  runs,
  { headSha, allowedEvents = null, allowedBranches = null } = {},
) {
  if (!Array.isArray(runs) || !headSha) return null;

  const eventFilter = Array.isArray(allowedEvents) && allowedEvents.length > 0
    ? new Set(allowedEvents)
    : null;
  const branchFilter = Array.isArray(allowedBranches) && allowedBranches.length > 0
    ? new Set(allowedBranches)
    : null;

  const candidates = runs.filter((run) => {
    if (!run || run.head_sha !== headSha) return false;
    if (eventFilter && !eventFilter.has(run.event)) return false;
    if (branchFilter && !branchFilter.has(run.head_branch)) return false;
    return true;
  });

  if (candidates.length === 0) return null;

  candidates.sort((left, right) => {
    const timeDiff = runTimestampMs(right) - runTimestampMs(left);
    if (timeDiff !== 0) return timeDiff;
    const numberDiff = Number(right.run_number || 0) - Number(left.run_number || 0);
    if (numberDiff !== 0) return numberDiff;
    return Number(right.id || 0) - Number(left.id || 0);
  });

  return candidates[0];
}

/**
 * Verify that an ISO timestamp exists and is no older than `maxAgeDays`.
 */
export function isFreshAuditTimestamp(
  completedAt,
  { nowMs = Date.now(), maxAgeDays = CLOUDFLARE_AUDIT_MAX_AGE_DAYS } = {},
) {
  if (typeof completedAt !== 'string' || !completedAt.trim()) return false;
  const parsedMs = Date.parse(completedAt);
  if (!Number.isFinite(parsedMs)) return false;
  const ageMs = nowMs - parsedMs;
  return ageMs <= maxAgeDays * 86_400_000;
}

function isCompletedWithConclusion(entry) {
  return Boolean(
    entry
    && entry.status === 'completed'
    && typeof entry.conclusion === 'string'
    && entry.conclusion !== '',
  );
}

/**
 * Resolve the latest applicable run and target job for an external workflow with
 * bounded waiting for queued/in-progress states and strict fail-closed semantics.
 */
export async function resolveExternalWorkflowJob({
  workflowFile,
  expectedJobName,
  headSha,
  allowedEvents = null,
  allowedBranches = null,
  requireSuccess = true,
  fetchRuns,
  fetchJobs,
  sleep = defaultSleep,
  now = Date.now,
  log = () => {},
  appearTimeoutMs = EXTERNAL_RUN_APPEAR_TIMEOUT_MS,
  completeTimeoutMs = EXTERNAL_RUN_COMPLETE_TIMEOUT_MS,
  pollIntervalMs = EXTERNAL_RUN_POLL_INTERVAL_MS,
}) {
  if (!workflowFile || !expectedJobName || !headSha) {
    throw new Error('workflowFile, expectedJobName, and headSha are required');
  }
  if (typeof fetchRuns !== 'function' || typeof fetchJobs !== 'function') {
    throw new Error('fetchRuns and fetchJobs must be functions');
  }

  const startedAt = now();
  let syntheticElapsedMs = 0;
  const elapsedSinceStart = () => Math.max(0, now() - startedAt, syntheticElapsedMs);

  async function waitBeforeRetry(waitMs) {
    const before = now();
    await sleep(waitMs);
    const after = now();
    if (after <= before) {
      syntheticElapsedMs += Math.max(1, waitMs);
    }
  }

  while (true) {
    const runs = await fetchRuns();
    const run = selectLatestApplicableRun(runs, { headSha, allowedEvents, allowedBranches });
    const elapsedMs = elapsedSinceStart();

    if (!run) {
      if (elapsedMs >= appearTimeoutMs) {
        throw new Error(
          `${workflowFile} has no matching run for ${headSha} after waiting ${Math.round(elapsedMs / 1000)}s`,
        );
      }
      const waitMs = Math.min(pollIntervalMs, appearTimeoutMs - elapsedMs);
      log(`Waiting for ${workflowFile} run for ${headSha} to appear (${Math.round(elapsedMs / 1000)}s elapsed)...`);
      await waitBeforeRetry(waitMs);
      continue;
    }

    if (!isCompletedWithConclusion(run)) {
      if (elapsedMs >= completeTimeoutMs) {
        throw new Error(
          `Latest matching ${workflowFile} run ${run.id} did not complete within ${Math.round(elapsedMs / 1000)}s (status=${run.status}, conclusion=${run.conclusion})`,
        );
      }
      const waitMs = Math.min(pollIntervalMs, completeTimeoutMs - elapsedMs);
      log(`Waiting for latest ${workflowFile} run ${run.id} (status=${run.status}, conclusion=${run.conclusion})...`);
      await waitBeforeRetry(waitMs);
      continue;
    }

    // Latest applicable run is completed: never retry and never fall back to older runs.
    if (requireSuccess && run.conclusion !== 'success') {
      throw new Error(`Latest matching ${workflowFile} run ${run.id} concluded ${run.conclusion}`);
    }

    const jobs = await fetchJobs(run.id);
    const job = Array.isArray(jobs)
      ? jobs.find((candidate) => candidate && candidate.name === expectedJobName)
      : null;

    if (!job) {
      throw new Error(`${expectedJobName} was not found in ${workflowFile} run ${run.id}`);
    }

    if (!isCompletedWithConclusion(job)) {
      const currentElapsedMs = elapsedSinceStart();
      if (currentElapsedMs >= completeTimeoutMs) {
        throw new Error(
          `${expectedJobName} in ${workflowFile} run ${run.id} did not complete within ${Math.round(currentElapsedMs / 1000)}s (status=${job.status}, conclusion=${job.conclusion})`,
        );
      }
      const waitMs = Math.min(pollIntervalMs, completeTimeoutMs - currentElapsedMs);
      log(`Waiting for ${workflowFile} job "${expectedJobName}" in run ${run.id} (status=${job.status}, conclusion=${job.conclusion})...`);
      await waitBeforeRetry(waitMs);
      continue;
    }

    return {
      workflow_run_id: run.id,
      workflow_url: run.html_url,
      workflow_conclusion: run.conclusion,
      workflow_event: run.event,
      workflow_branch: run.head_branch,
      job_name: job.name,
      job_url: job.html_url,
      job_conclusion: job.conclusion,
      completed_at: job.completed_at,
      head_sha: run.head_sha,
    };
  }
}
