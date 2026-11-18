import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { assessAudit, assertEvidence, EVIDENCE_FILES } from '../scripts/audit-dependencies.mjs';

const root = { name: 'picomatch', severity: 'high', url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf', range: '<=4.0.7' };
const policy = { approved: true, advisory: root.url, decision: 'Synthetic fixture only', reviewedAt: '2026-11-18T00:00:00+08:00', expiresAt: '2026-12-02T00:00:00+08:00' };
const now = new Date('2026-11-18T01:00:00+08:00');
const report = (vulnerabilities) => ({ auditReportVersion: 2, vulnerabilities, metadata: { vulnerabilities: { total: Object.keys(vulnerabilities).length } } });
const item = (via, severity = 'high') => ({ severity, via });

test('only the approved root and its transitive records pass', () => {
  assert.deepEqual(assessAudit(report({ picomatch: item([root]), vite: item(['picomatch']), astro: item(['vite']) }), policy, now),
    { blocked: [], excepted: ['picomatch', 'vite', 'astro'] });
});
test('unapproved, expired, future or overlong decisions cannot bypass', () => {
  const r = report({ picomatch: item([root]) });
  for (const p of [{ ...policy, approved: false }, { ...policy, decision: '' }, { ...policy, expiresAt: '2026-12-03T00:00:00+08:00' }]) {
    assert.deepEqual(assessAudit(r, p, now).blocked, ['picomatch']);
  }
  for (const date of ['2026-11-17T00:00:00Z', policy.expiresAt]) assert.deepEqual(assessAudit(r, policy, new Date(date)).blocked, ['picomatch']);
});
test('new, changed, critical and mixed advisories remain blocked', () => {
  const other = { ...root, url: 'https://github.com/advisories/GHSA-other' };
  assert.deepEqual(assessAudit(report({ picomatch: item([root]), astro: item(['picomatch', other]) }), policy, now).blocked, ['astro']);
  for (const changed of [{ ...root, range: '*' }, { ...root, severity: 'critical' }, other]) {
    assert.deepEqual(assessAudit(report({ picomatch: item([changed]) }), policy, now).blocked, ['picomatch']);
  }
  assert.deepEqual(assessAudit(report({ picomatch: item([root], 'critical') }), policy, now).blocked, ['picomatch']);
});
test('cycles, missing links and malformed/error reports fail closed', () => {
  assert.deepEqual(assessAudit(report({ a: item(['b']), b: item(['a']), c: item(['missing']) }), policy, now).blocked, ['a', 'b', 'c']);
  for (const r of [{}, { ...report({}), error: { message: 'network failed' } }, report({ a: item([], 'unknown') }),
    { ...report({}), metadata: { vulnerabilities: { total: 1 } } }]) assert.throws(() => assessAudit(r, policy, now));
});
test('evidence changes and missing hashes invalidate acceptance', () => {
  const hashes = Object.fromEntries(EVIDENCE_FILES.map((p) => [p, createHash('sha256').update(p).digest('hex')]));
  assertEvidence({ evidenceSha256: hashes }, (p) => Buffer.from(p));
  for (const changed of EVIDENCE_FILES) {
    assert.throws(() => assertEvidence({ evidenceSha256: hashes }, (p) => Buffer.from(p === changed ? 'changed' : p)));
  }
  assert.throws(() => assertEvidence({}, (p) => Buffer.from(p)));
});
test('clean audit passes without an exception', () => {
  assert.deepEqual(assessAudit(report({}), { approved: false }, now), { blocked: [], excepted: [] });
});
