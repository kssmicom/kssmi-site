// External availability monitor, run hourly by .github/workflows/site-monitor.yml
// from a GitHub-hosted runner (outside Cloudflare and outside the server).
//
// Checks:
// 1. Every site hosted on the server answers through Cloudflare with its known
//    status code.
// 2. The kssmi.com security-sensitive endpoints keep their expected answers
//    (admin pages behind Cloudflare Access, write endpoints closed to GET, ...)
//    and every fingerprinted runtime asset in the live manifest is served.
// 3. The edge TLS certificate of every host is valid for at least 14 more days.
// 4. The origin server (ORIGIN_HOST, from a repository secret) only accepts
//    SSH from the internet: web ports must not answer a non-Cloudflare client,
//    and panel, FTP, DNS and mail ports must stay closed. Port 22 is the control
//    that proves the origin was reachable at all.
//
// The origin address is never printed: this repository and its Actions logs
// are public. Any failed check exits 1, and GitHub then emails the failure.
import { appendFileSync } from 'node:fs';
import net from 'node:net';
import tls from 'node:tls';

const UA = 'Mozilla/5.0 (compatible; kssmi-site-monitor/1.0; +https://github.com/kssmicom/kssmi-site)';
const TIMEOUT_MS = 20000;
const CERT_MIN_DAYS = 14;

// Known answers of the sites on the server (baseline 2026-10-24).
// smallnm.com has answered 500 for months; it is listed as a known issue so the
// monitor still notices if it changes or stops answering at all.
const SITES = [
  ['aiidii.com', 200], ['crm.kssmi.com', 200], ['cweup.com', 301], ['definjector.com', 200],
  ['goboll.com', 200], ['hxhconnector.com', 200], ['jade-shipping.com', 200], ['jinriea.com', 301],
  ['kssmi.com', 200], ['ledconnectors.com', 200], ['moldpartsglobal.com', 200],
  ['pangaeacargoline.com', 200], ['smallnm.com', 500, 'known issue: answers 500'],
  ['switchgearmfg.com', 200], ['wise-shipping.cn', 200], ['xxoptics.com', 200], ['yeetian.com', 200],
];

const ACCESS_LOGIN = 'https://kssmi.cloudflareaccess.com/';
const KSSMI_ENDPOINTS = [
  ['https://kssmi.com/send-mail.php', 405],
  ['https://kssmi.com/api/vjt-config.php', 200],
  ['https://kssmi.com/api/short-links.php', 403],
  ['https://kssmi.com/email-logs.php', 302, ACCESS_LOGIN],
  ['https://kssmi.com/visitor-journey.php', 302, ACCESS_LOGIN],
  ['https://kssmi.com/short-links-admin.php', 302, ACCESS_LOGIN],
  ['https://panel.kssmi.com/', 302, ACCESS_LOGIN],
  ['https://kssmi.com/email-logs.json', 404],
];

const ORIGIN_OPEN = [22];
const ORIGIN_CLOSED = [80, 443, 7080, 8090, 21, 25, 53, 110, 143, 465, 587, 993, 995];

const results = [];
const record = (group, name, ok, detail) => results.push({ group, name, ok, detail });

async function request(url) {
  let lastError;
  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      return await fetch(url, { redirect: 'manual', headers: { 'user-agent': UA }, signal: AbortSignal.timeout(TIMEOUT_MS) });
    } catch (error) {
      lastError = error;
      if (attempt === 1) await new Promise((resolve) => setTimeout(resolve, 5000));
    }
  }
  throw lastError;
}

async function checkStatus(group, url, expected, locationPrefix, note) {
  try {
    const response = await request(url);
    const location = response.headers.get('location') || '';
    let ok = response.status === expected;
    if (ok && locationPrefix) ok = location.startsWith(locationPrefix);
    const where = locationPrefix ? ` → ${location.split('?')[0] || '(none)'}` : '';
    record(group, url.replace('https://', ''), ok, `${response.status} (expected ${expected}${locationPrefix ? ' to Access' : ''})${where}${note ? ` — ${note}` : ''}`);
    await response.body?.cancel();
  } catch (error) {
    record(group, url.replace('https://', ''), false, `no answer: ${error.cause?.code || error.name}`);
  }
}

async function checkRuntimeAssets() {
  try {
    const response = await request('https://kssmi.com/assets/runtime/manifest.json');
    const manifest = await response.json();
    const assets = Object.entries(manifest.assets || {});
    if (assets.length === 0) return record('kssmi', 'runtime manifest', false, 'no assets listed');
    for (const [name, asset] of assets) await checkStatus('kssmi', `https://kssmi.com${asset.url}`, 200, null, `runtime asset ${name}`);
  } catch (error) {
    record('kssmi', 'runtime manifest', false, `unreadable: ${error.cause?.code || error.name}`);
  }
}

function certificateDays(host) {
  return new Promise((resolve, reject) => {
    const socket = tls.connect({ host, port: 443, servername: host, timeout: TIMEOUT_MS }, () => {
      const certificate = socket.getPeerCertificate();
      socket.end();
      if (!certificate?.valid_to) return reject(new Error('no certificate'));
      resolve(Math.floor((new Date(certificate.valid_to) - Date.now()) / 86400000));
    });
    socket.on('timeout', () => { socket.destroy(); reject(new Error('timeout')); });
    socket.on('error', reject);
  });
}

function probe(host, port) {
  return new Promise((resolve) => {
    const socket = net.connect({ host, port });
    const done = (state) => { socket.destroy(); resolve(state); };
    socket.setTimeout(6000, () => done('no answer'));
    socket.once('connect', () => done('open'));
    socket.once('error', (error) => done(error.code === 'ECONNREFUSED' ? 'refused' : 'no answer'));
  });
}

await Promise.all(SITES.map(([host, status, note]) => checkStatus('sites', `https://${host}/`, status, null, note)));
for (const [url, status, prefix] of KSSMI_ENDPOINTS) await checkStatus('kssmi', url, status, prefix);
await checkRuntimeAssets();

const certificateHosts = [...new Set([...SITES.map(([host]) => host), 'panel.kssmi.com'])];
await Promise.all(certificateHosts.map(async (host) => {
  try {
    const days = await certificateDays(host);
    record('certificates', host, days >= CERT_MIN_DAYS, `${days} days left`);
  } catch (error) {
    record('certificates', host, false, `unreadable: ${error.code || error.message}`);
  }
}));

const origin = (process.env.ORIGIN_HOST || '').trim();
if (process.env.MONITOR_SKIP_ORIGIN === '1') {
  record('origin', 'origin probe', true, 'skipped (MONITOR_SKIP_ORIGIN=1, local run)');
} else if (!origin) {
  record('origin', 'origin probe', false, 'ORIGIN_HOST is not set');
} else {
  const states = Object.fromEntries(await Promise.all(
    [...ORIGIN_OPEN, ...ORIGIN_CLOSED].map(async (port) => [port, await probe(origin, port)]),
  ));
  for (const port of ORIGIN_OPEN) record('origin', `port ${port}`, states[port] === 'open', `${states[port]} (expected open — control)`);
  const reachable = ORIGIN_OPEN.every((port) => states[port] === 'open');
  for (const port of ORIGIN_CLOSED) {
    const ok = reachable && states[port] !== 'open';
    record('origin', `port ${port}`, ok, reachable ? `${states[port]} (expected not to answer)` : 'inconclusive: control port did not answer');
  }
}

const failed = results.filter((result) => !result.ok);
const lines = [`# Site monitor: ${failed.length ? `${failed.length} FAILED` : 'all checks passed'} (${results.length} checks)`, ''];
for (const group of ['sites', 'kssmi', 'certificates', 'origin']) {
  lines.push(`## ${group}`, '', '| | Check | Result |', '|---|---|---|');
  for (const result of results.filter((item) => item.group === group)) lines.push(`| ${result.ok ? '✅' : '❌'} | ${result.name} | ${result.detail} |`);
  lines.push('');
}
const report = lines.join('\n');
console.log(report);
if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${report}\n`);
process.exit(failed.length ? 1 : 0);
