import fs from 'node:fs';
import net from 'node:net';
import process from 'node:process';

export const SNAPSHOT_SCHEMA_VERSION = 1;
export const SNAPSHOT_MAX_AGE_DAYS = 45;
export const MAXIMUM_RESPONSE_BYTES = 64 * 1024;
export const CLOUDFLARE_SOURCES = Object.freeze({
  ipv4: 'https://www.cloudflare.com/ips-v4/',
  ipv6: 'https://www.cloudflare.com/ips-v6/',
});

const MINIMUM_RANGE_COUNTS = Object.freeze({ ipv4: 10, ipv6: 5 });
const MAXIMUM_RANGE_COUNT = 100;
const MAXIMUM_FUTURE_SKEW_MS = 5 * 60 * 1000;
const SNAPSHOT_KEYS = ['ipv4', 'ipv6', 'schema_version', 'sources', 'verified_at'];
const SOURCE_KEYS = ['ipv4', 'ipv6'];

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function hasExactKeys(value, expectedKeys) {
  return (
    value &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    Object.keys(value).sort().join('\n') === [...expectedKeys].sort().join('\n')
  );
}

function addressBytes(address, version) {
  if (version === 4) {
    return Buffer.from(address.split('.').map((part) => Number(part)));
  }

  let normalized = address.toLowerCase();
  if (normalized.includes('.')) {
    const lastColon = normalized.lastIndexOf(':');
    const ipv4 = normalized.slice(lastColon + 1).split('.').map((part) => Number(part));
    normalized =
      normalized.slice(0, lastColon) + ':' +
      ((ipv4[0] << 8) | ipv4[1]).toString(16) + ':' +
      ((ipv4[2] << 8) | ipv4[3]).toString(16);
  }

  const halves = normalized.split('::');
  assert(halves.length <= 2, 'invalid IPv6 address: ' + address);
  const left = halves[0] === '' ? [] : halves[0].split(':');
  const right = halves.length === 1 || halves[1] === '' ? [] : halves[1].split(':');
  const missing = 8 - left.length - right.length;
  assert(
    (halves.length === 2 && missing >= 1) || (halves.length === 1 && missing === 0),
    'invalid IPv6 address: ' + address
  );

  const groups = [...left, ...Array(missing).fill('0'), ...right];
  const bytes = Buffer.alloc(16);
  groups.forEach((group, index) => {
    const value = Number.parseInt(group, 16);
    bytes[index * 2] = value >> 8;
    bytes[index * 2 + 1] = value & 0xff;
  });
  return bytes;
}

function isCanonicalNetwork(address, prefix, version) {
  const bytes = addressBytes(address, version);
  const fullBytes = Math.floor(prefix / 8);
  const remainingBits = prefix % 8;
  let hostStart = fullBytes;

  if (remainingBits > 0) {
    const hostMask = (1 << (8 - remainingBits)) - 1;
    if ((bytes[fullBytes] & hostMask) !== 0) return false;
    hostStart += 1;
  }

  for (let index = hostStart; index < bytes.length; index += 1) {
    if (bytes[index] !== 0) return false;
  }
  return true;
}

function validateRangeList(value, family) {
  assert(Array.isArray(value), family + ' must be an array');
  assert(
    value.length >= MINIMUM_RANGE_COUNTS[family] &&
      value.length <= MAXIMUM_RANGE_COUNT,
    family + ' count must be between ' + MINIMUM_RANGE_COUNTS[family] +
      ' and ' + MAXIMUM_RANGE_COUNT
  );

  const expectedVersion = family === 'ipv4' ? 4 : 6;
  const seen = new Set();
  for (const cidr of value) {
    assert(typeof cidr === 'string', family + ' entries must be strings');
    const match = cidr.match(/^([^/\s]+)\/([0-9]{1,3})$/);
    assert(match, 'invalid ' + family + ' CIDR: ' + cidr);
    const version = net.isIP(match[1]);
    const prefix = Number(match[2]);
    const maximumPrefix = expectedVersion === 4 ? 32 : 128;
    assert(version === expectedVersion, cidr + ' is not ' + family);
    assert(prefix >= 0 && prefix <= maximumPrefix, 'invalid prefix in ' + cidr);
    assert(isCanonicalNetwork(match[1], prefix, version), cidr + ' is not a canonical network');
    assert(!seen.has(cidr), 'duplicate ' + family + ' CIDR: ' + cidr);
    seen.add(cidr);
  }
  return [...value];
}

export function validateSnapshot(
  value,
  { now = new Date(), maxAgeDays = SNAPSHOT_MAX_AGE_DAYS } = {}
) {
  assert(hasExactKeys(value, SNAPSHOT_KEYS), 'snapshot has missing or unexpected fields');
  assert(
    value.schema_version === SNAPSHOT_SCHEMA_VERSION,
    'schema_version must be ' + SNAPSHOT_SCHEMA_VERSION
  );
  assert(hasExactKeys(value.sources, SOURCE_KEYS), 'snapshot sources have missing or unexpected fields');
  assert(
    value.sources.ipv4 === CLOUDFLARE_SOURCES.ipv4 &&
      value.sources.ipv6 === CLOUDFLARE_SOURCES.ipv6,
    'snapshot sources must be the official Cloudflare HTTPS endpoints'
  );
  assert(
    typeof value.verified_at === 'string' &&
      /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value.verified_at),
    'verified_at must be an exact UTC timestamp without milliseconds'
  );

  const verifiedAt = new Date(value.verified_at);
  const currentTime = now instanceof Date ? now : new Date(now);
  assert(!Number.isNaN(verifiedAt.getTime()), 'verified_at is not a real timestamp');
  assert(
    verifiedAt.toISOString().replace(/\.\d{3}Z$/, 'Z') === value.verified_at,
    'verified_at is not a real UTC calendar timestamp'
  );
  assert(!Number.isNaN(currentTime.getTime()), 'current time is invalid');
  assert(
    verifiedAt.getTime() <= currentTime.getTime() + MAXIMUM_FUTURE_SKEW_MS,
    'verified_at is unreasonably far in the future'
  );
  assert(
    currentTime.getTime() - verifiedAt.getTime() <= maxAgeDays * 86_400_000,
    'snapshot is older than ' + maxAgeDays + ' days'
  );

  return {
    schema_version: SNAPSHOT_SCHEMA_VERSION,
    verified_at: value.verified_at,
    sources: { ...CLOUDFLARE_SOURCES },
    ipv4: validateRangeList(value.ipv4, 'ipv4'),
    ipv6: validateRangeList(value.ipv6, 'ipv6'),
  };
}

export function readSnapshot(filePath, options) {
  let decoded;
  try {
    decoded = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    throw new Error('cannot read Cloudflare snapshot ' + filePath + ': ' + error.message);
  }
  return validateSnapshot(decoded, options);
}

export function parseOfficialRangeList(body, family) {
  assert(family === 'ipv4' || family === 'ipv6', 'family must be ipv4 or ipv6');
  assert(typeof body === 'string', family + ' response must be text');
  assert(Buffer.byteLength(body, 'utf8') <= MAXIMUM_RESPONSE_BYTES, family + ' response is too large');
  assert(!/<(?:!doctype|html|body)\b/i.test(body), family + ' response looks like HTML');

  const ranges = body
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  return validateRangeList(ranges, family);
}

export function validateOfficialResponseUrl(responseUrl, family) {
  assert(family === 'ipv4' || family === 'ipv6', 'family must be ipv4 or ipv6');
  let parsed;
  try {
    parsed = new URL(responseUrl);
  } catch {
    throw new Error(family + ' source returned an invalid final URL');
  }
  assert(
    parsed.href === CLOUDFLARE_SOURCES[family],
    family + ' source redirected outside its official Cloudflare HTTPS endpoint'
  );
}

async function fetchOfficialText(url, family) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15_000);
  try {
    const response = await fetch(url, {
      headers: {
        accept: 'text/plain',
        'user-agent': 'KSSMI-Cloudflare-Range-Audit/1.0',
      },
      redirect: 'follow',
      signal: controller.signal,
    });
    assert(response.ok, family + ' source returned HTTP ' + response.status);
    validateOfficialResponseUrl(response.url, family);

    const contentType = (response.headers.get('content-type') || '').split(';', 1)[0].trim();
    assert(contentType === 'text/plain', family + ' source did not return text/plain');
    const declaredLength = Number(response.headers.get('content-length'));
    assert(
      !Number.isFinite(declaredLength) || declaredLength <= MAXIMUM_RESPONSE_BYTES,
      family + ' response is too large'
    );
    assert(response.body, family + ' source returned an empty response stream');

    const reader = response.body.getReader();
    const chunks = [];
    let totalBytes = 0;
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      totalBytes += value.byteLength;
      if (totalBytes > MAXIMUM_RESPONSE_BYTES) {
        await reader.cancel();
        throw new Error(family + ' response is too large');
      }
      chunks.push(Buffer.from(value));
    }
    return Buffer.concat(chunks, totalBytes).toString('utf8');
  } catch (error) {
    if (error?.name === 'AbortError') throw new Error(family + ' source timed out');
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

export async function fetchOfficialRanges() {
  assert(
    process.env.NODE_TLS_REJECT_UNAUTHORIZED !== '0',
    'refusing to fetch Cloudflare ranges while TLS certificate verification is disabled'
  );
  const [ipv4Body, ipv6Body] = await Promise.all([
    fetchOfficialText(CLOUDFLARE_SOURCES.ipv4, 'ipv4'),
    fetchOfficialText(CLOUDFLARE_SOURCES.ipv6, 'ipv6'),
  ]);
  return {
    ipv4: parseOfficialRangeList(ipv4Body, 'ipv4'),
    ipv6: parseOfficialRangeList(ipv6Body, 'ipv6'),
  };
}

export function compareRangeLists(snapshot, official) {
  const differences = [];
  for (const family of ['ipv4', 'ipv6']) {
    const current = new Set(snapshot[family]);
    const remote = new Set(official[family]);
    for (const cidr of official[family]) {
      if (!current.has(cidr)) differences.push({ family, kind: 'added', cidr });
    }
    for (const cidr of snapshot[family]) {
      if (!remote.has(cidr)) differences.push({ family, kind: 'removed', cidr });
    }
  }
  return differences;
}

export function serializeSnapshot(snapshot) {
  return JSON.stringify(snapshot, null, 2) + '\n';
}
