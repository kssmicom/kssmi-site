import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  compareRangeLists,
  fetchOfficialRanges,
  MAXIMUM_RESPONSE_BYTES,
  parseOfficialRangeList,
  readSnapshot,
  validateOfficialResponseUrl,
  validateSnapshot,
} from './lib/cloudflare-ranges.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const snapshot = readSnapshot(
  path.join(root, 'private', 'cloudflare-ip-ranges.json'),
  { now: new Date('2026-08-03T00:00:00Z') }
);

assert.ok(snapshot.ipv4.length >= 10, 'IPv4 snapshot is unexpectedly small');
assert.ok(snapshot.ipv6.length >= 5, 'IPv6 snapshot is unexpectedly small');
assert.deepEqual(
  parseOfficialRangeList(snapshot.ipv4.join('\n') + '\n', 'ipv4'),
  snapshot.ipv4,
  'official IPv4 text parser changed the ranges'
);
assert.deepEqual(
  parseOfficialRangeList(snapshot.ipv6.join('\r\n') + '\r\n', 'ipv6'),
  snapshot.ipv6,
  'official IPv6 text parser changed the ranges'
);

assert.throws(
  () => readSnapshot(path.join(root, 'private', 'missing-cloudflare-ranges.json')),
  /cannot read Cloudflare snapshot/,
  'missing snapshot was accepted'
);

const temporaryDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'kssmi-cf-ranges-'));
try {
  const malformedPath = path.join(temporaryDirectory, 'malformed.json');
  fs.writeFileSync(malformedPath, '{"schema_version":', 'utf8');
  assert.throws(
    () => readSnapshot(malformedPath),
    /cannot read Cloudflare snapshot/,
    'malformed snapshot JSON was accepted'
  );
} finally {
  fs.rmSync(temporaryDirectory, { recursive: true, force: true });
}

assert.throws(
  () => parseOfficialRangeList('', 'ipv4'),
  /count/,
  'empty response was accepted'
);
assert.throws(
  () => parseOfficialRangeList('<html><body>gateway error</body></html>', 'ipv4'),
  /HTML|count/,
  'HTML response was accepted as a range list'
);
assert.throws(
  () => parseOfficialRangeList('x'.repeat(MAXIMUM_RESPONSE_BYTES + 1), 'ipv4'),
  /too large/,
  'oversized response was accepted'
);
assert.throws(
  () => parseOfficialRangeList(snapshot.ipv4.join('\n') + '\n' + snapshot.ipv4[0], 'ipv4'),
  /duplicate/,
  'duplicate CIDR was accepted'
);

const nonCanonicalIpv4 = [...snapshot.ipv4];
nonCanonicalIpv4[0] = '173.245.48.1/20';
assert.throws(
  () => parseOfficialRangeList(nonCanonicalIpv4.join('\n'), 'ipv4'),
  /canonical network/,
  'non-canonical CIDR was accepted'
);
assert.throws(
  () => parseOfficialRangeList(snapshot.ipv6.join('\n'), 'ipv4'),
  /not ipv4|count/,
  'IPv6 ranges were accepted as IPv4'
);

const futureSnapshot = { ...snapshot, verified_at: '2026-08-03T00:06:00Z' };
assert.throws(
  () => validateSnapshot(futureSnapshot, { now: new Date('2026-08-03T00:00:00Z') }),
  /future/,
  'future-dated snapshot was accepted'
);
assert.throws(
  () => validateSnapshot(snapshot, { now: new Date('2026-09-17T06:54:51Z') }),
  /older than 45 days/,
  'stale snapshot was accepted'
);
assert.throws(
  () => validateSnapshot(
    { ...snapshot, unexpected: true },
    { now: new Date('2026-08-03T00:00:00Z') }
  ),
  /unexpected fields/,
  'snapshot with unknown fields was accepted'
);
assert.throws(
  () => validateSnapshot(
    { ...snapshot, sources: { ...snapshot.sources, ipv4: 'http://www.cloudflare.com/ips-v4/' } },
    { now: new Date('2026-08-03T00:00:00Z') }
  ),
  /official Cloudflare HTTPS/,
  'non-HTTPS source was accepted'
);
assert.throws(
  () => validateOfficialResponseUrl('https://example.com/ips-v4/', 'ipv4'),
  /redirected outside/,
  'redirect to a non-Cloudflare host was accepted'
);
assert.throws(
  () => validateOfficialResponseUrl('https://www.cloudflare.com/ips-v6/', 'ipv4'),
  /redirected outside/,
  'redirect to the wrong Cloudflare endpoint was accepted'
);

const originalTlsSetting = process.env.NODE_TLS_REJECT_UNAUTHORIZED;
try {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
  await assert.rejects(
    fetchOfficialRanges(),
    /TLS certificate verification is disabled/,
    'remote fetch was allowed with TLS verification disabled'
  );
} finally {
  if (originalTlsSetting === undefined) {
    delete process.env.NODE_TLS_REJECT_UNAUTHORIZED;
  } else {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = originalTlsSetting;
  }
}

assert.deepEqual(compareRangeLists(snapshot, snapshot), [], 'equal range sets produced a difference');
const changed = {
  ipv4: snapshot.ipv4.slice(1),
  ipv6: [...snapshot.ipv6, '2001:db8::/32'],
};
assert.deepEqual(compareRangeLists(snapshot, changed), [
  { family: 'ipv4', kind: 'removed', cidr: snapshot.ipv4[0] },
  { family: 'ipv6', kind: 'added', cidr: '2001:db8::/32' },
]);

console.log('Cloudflare range snapshot, parser, boundary and comparison tests passed.');
