import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

const ROOT = fileURLToPath(new URL('../', import.meta.url));
const ADVISORY = 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf';
const LEVELS = ['info', 'low', 'moderate', 'high', 'critical'];
export const EVIDENCE_FILES = ['package-lock.json', 'astro.config.mjs', 'src/content.config.ts', '.github/workflows/deploy.yml'];

/** Assess every root advisory, including through npm's transitive records.
 * A package with any other root finding must still fail the release gate. */
export function assessAudit(report, policy, now = new Date()) {
  if (!report || report.error || report.auditReportVersion !== 2 ||
      !report.vulnerabilities || Array.isArray(report.vulnerabilities) ||
      !Number.isInteger(report.metadata?.vulnerabilities?.total)) {
    throw new Error('Invalid or unavailable npm audit report');
  }
  const entries = Object.entries(report.vulnerabilities);
  if (entries.length !== report.metadata.vulnerabilities.total) {
    throw new Error('Audit summary does not match vulnerability records');
  }
  const start = Date.parse(policy?.reviewedAt);
  const end = Date.parse(policy?.expiresAt);
  const approved = policy?.approved === true && policy.advisory === ADVISORY &&
    typeof policy.decision === 'string' && policy.decision.trim().length > 0 &&
    Number.isFinite(start) && Number.isFinite(end) && end > start &&
    end - start <= 14 * 86400_000 && now.getTime() >= start && now.getTime() < end;

  function onlyApprovedRoot(name, visited = new Set()) {
    if (!approved || visited.has(name)) return false;
    const item = report.vulnerabilities[name];
    if (!item || item.severity !== 'high' || !Array.isArray(item.via) || !item.via.length) return false;
    const next = new Set(visited).add(name);
    return item.via.every((via) => {
      if (typeof via === 'string') return onlyApprovedRoot(via, next);
      return name === 'picomatch' && via?.name === 'picomatch' &&
        via.url === ADVISORY && via.range === '<=4.0.7' && via.severity === 'high';
    });
  }

  const blocked = [], excepted = [];
  for (const [name, item] of entries) {
    if (!LEVELS.includes(item?.severity) || !Array.isArray(item.via)) throw new Error(`Invalid audit record: ${name}`);
    if (LEVELS.indexOf(item.severity) < LEVELS.indexOf('high')) continue;
    (onlyApprovedRoot(name) ? excepted : blocked).push(name);
  }
  return { blocked, excepted };
}

export function assertEvidence(policy, read = (path) => readFileSync(resolve(ROOT, path))) {
  for (const path of EVIDENCE_FILES) {
    const actual = createHash('sha256').update(read(path)).digest('hex');
    if (policy.evidenceSha256?.[path] !== actual) throw new Error(`Dependency exception requires fresh review: ${path} changed`);
  }
}

function main() {
  const policy = JSON.parse(readFileSync(resolve(ROOT, 'docs/dependency-audit-exception.json'), 'utf8'));
  const result = spawnSync('npm', ['audit', '--json', '--audit-level=high'], {
    cwd: ROOT, encoding: 'utf8', timeout: 120_000, maxBuffer: 10 * 1024 * 1024,
  });
  // Retain the unfiltered audit findings in CI, even for an accepted exception.
  if (result.stderr) process.stderr.write(result.stderr);
  if (result.stdout) process.stdout.write(result.stdout + '\n');
  if (result.error || result.signal || ![0, 1].includes(result.status)) {
    throw new Error(`npm audit failed: ${result.error?.message ?? result.signal ?? result.status}`);
  }
  const { blocked, excepted } = assessAudit(JSON.parse(result.stdout), policy);
  if (excepted.length) {
    assertEvidence(policy);
    console.warn(`OWNER-APPROVED BUILD-ONLY EXCEPTION: ${ADVISORY}; expires ${policy.expiresAt}`);
    console.warn(`Affected dependency records: ${excepted.join(', ')}. This is risk acceptance, not a picomatch fix.`);
  }
  if (blocked.length) throw new Error(`Unaccepted high/critical dependency findings: ${blocked.join(', ')}`);
  console.log('Dependency audit policy passed; raw findings remain visible above.');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try { main(); } catch (error) { console.error(error.message); process.exitCode = 1; }
}
