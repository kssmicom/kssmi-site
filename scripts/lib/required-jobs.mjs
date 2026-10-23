/**
 * Read the conclusions of the jobs this release needs before it can be signed
 * off.
 *
 * The signoff job starts the moment the deploy job ends, and GitHub's jobs API
 * can still report that job as unfinished (conclusion null) for a few seconds.
 * Run 39914881002 recorded `conclusion: null` three seconds after a successful
 * deploy and blocked a healthy release.
 *
 * Only that transient state is retried, for a bounded time: a job that is not
 * completed yet, or that completed without a conclusion. A completed job keeps
 * its conclusion at once, so a real failure is never retried or softened, and a
 * job that never completes ends in an error, which keeps the signoff BLOCKED.
 * A job missing from the run is an error immediately.
 */

export const REQUIRED_JOB_ATTEMPTS = 18;
export const REQUIRED_JOB_DELAY_MS = 5000;

const defaultSleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

/**
 * @param {object} options
 * @param {() => Promise<Array<object>>} options.fetchJobs Current jobs of the run.
 * @param {string[]} options.requiredNames
 * @returns {Promise<Record<string, {url: string, conclusion: string, completed_at: string}>>}
 */
export async function resolveRequiredJobs({
  fetchJobs,
  requiredNames,
  attempts = REQUIRED_JOB_ATTEMPTS,
  delayMs = REQUIRED_JOB_DELAY_MS,
  sleep = defaultSleep,
  log = () => {},
}) {
  let unfinished = [];
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const jobs = await fetchJobs();
    const resolved = {};
    unfinished = [];
    for (const name of requiredNames) {
      const job = jobs.find((candidate) => candidate.name === name);
      if (!job) throw new Error(`Mandatory job is missing: ${name}`);
      if (job.status !== 'completed' || typeof job.conclusion !== 'string' || job.conclusion === '') {
        unfinished.push(`${name} (status=${job.status}, conclusion=${job.conclusion})`);
        continue;
      }
      resolved[name] = { url: job.html_url, conclusion: job.conclusion, completed_at: job.completed_at };
    }
    if (unfinished.length === 0) return resolved;
    if (attempt < attempts) {
      log(`Waiting for job conclusions (check ${attempt}/${attempts}): ${unfinished.join('; ')}`);
      await sleep(delayMs);
    }
  }
  throw new Error(
    `Mandatory job(s) not completed after ${attempts} checks over about ${Math.round(((attempts - 1) * delayMs) / 1000)}s: ${unfinished.join('; ')}`
  );
}
