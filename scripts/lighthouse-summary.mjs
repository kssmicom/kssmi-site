// Turns the Lighthouse JSON reports written by .github/workflows/lighthouse.yml
// into a Markdown table (median of the valid runs per page and form factor).
// Exits 1 when a page and form factor has no valid run, or when fewer page and
// form-factor groups than expected wrote a report at all.
// Usage: node scripts/lighthouse-summary.mjs <reports-dir> [expected-groups]
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';

const dir = process.argv[2] || 'lighthouse-reports';
const expectedGroups = Number(process.argv[3] || 0);
const groups = new Map();
const runErrors = [];
for (const file of (await readdir(dir).catch(() => [])).sort()) {
  const match = file.match(/^(.+)-(mobile|desktop)-\d+\.report\.json$/);
  if (!match) continue;
  const report = JSON.parse(await readFile(join(dir, file), 'utf8'));
  const key = `${match[1]} (${match[2]})`;
  if (!groups.has(key)) groups.set(key, { url: report.finalDisplayedUrl || report.requestedUrl, runs: [] });
  if (report.runtimeError) {
    runErrors.push(`${file}: ${report.runtimeError.code || 'runtime error'}`);
    continue;
  }
  const audit = (id) => report.audits?.[id]?.numericValue;
  const score = (id) => report.categories?.[id]?.score;
  groups.get(key).runs.push({
    performance: score('performance'),
    accessibility: score('accessibility'),
    bestPractices: score('best-practices'),
    seo: score('seo'),
    lcp: audit('largest-contentful-paint'),
    cls: audit('cumulative-layout-shift'),
    tbt: audit('total-blocking-time'),
    fcp: audit('first-contentful-paint'),
    bytes: audit('total-byte-weight'),
  });
}

function median(values) {
  const sorted = values.filter((value) => typeof value === 'number').sort((a, b) => a - b);
  return sorted.length ? sorted[Math.floor(sorted.length / 2)] : null;
}
const pct = (value) => (value === null ? '–' : String(Math.round(value * 100)));
const ms = (value) => (value === null ? '–' : `${Math.round(value)} ms`);

console.log('## Lighthouse lab results (median)\n');
if (groups.size === 0) {
  console.log('No Lighthouse reports were produced.');
  process.exit(1);
}
console.log('| Page | Perf | A11y | Best pr. | SEO | LCP | CLS | TBT | FCP | Weight | Runs |');
console.log('|---|---|---|---|---|---|---|---|---|---|---|');
for (const [key, { url, runs }] of groups) {
  const m = (field) => median(runs.map((run) => run[field]));
  const cls = m('cls');
  const bytes = m('bytes');
  console.log(
    `| [${key}](${url}) | ${pct(m('performance'))} | ${pct(m('accessibility'))} | ${pct(m('bestPractices'))} | ${pct(m('seo'))}`
      + ` | ${ms(m('lcp'))} | ${cls === null ? '–' : cls.toFixed(3)} | ${ms(m('tbt'))} | ${ms(m('fcp'))}`
      + ` | ${bytes === null ? '–' : `${Math.round(bytes / 1024)} KB`} | ${runs.length} |`
  );
}
if (runErrors.length) {
  console.log(`\nRuns with a Lighthouse error (left out of the medians): ${runErrors.join('; ')}`);
}
console.log('\nLab data from a GitHub-hosted runner (simulated throttling); compare trends week to week rather than absolute values.');

const missing = [...groups].filter(([, { runs }]) => runs.length === 0).map(([key]) => key);
if (missing.length) {
  console.log(`\n**No valid run for:** ${missing.join(', ')}`);
  process.exit(1);
}
if (groups.size < expectedGroups) {
  console.log(`\n**Only ${groups.size} of ${expectedGroups} page and form-factor groups wrote a report.**`);
  process.exit(1);
}
