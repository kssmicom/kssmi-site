import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// stamp-cloudflare-ranges.mjs: same ranges -> stamp verified_at (exit 0),
// changed ranges -> exit 3 without writing, --check-only never writes.

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const script = path.join(root, 'scripts', 'stamp-cloudflare-ranges.mjs');
const repoSnapshot = JSON.parse(fs.readFileSync(path.join(root, 'private', 'cloudflare-ip-ranges.json'), 'utf8'));
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'kssmi-cf-stamp-'));
const snapshotPath = path.join(directory, 'snapshot.json');
const officialPath = path.join(directory, 'official.json');

const writeSnapshot = (verifiedAt) =>
  fs.writeFileSync(snapshotPath, JSON.stringify({ ...repoSnapshot, verified_at: verifiedAt }, null, 2) + '\n');
const writeOfficial = (ipv4, ipv6) => fs.writeFileSync(officialPath, JSON.stringify({ ipv4, ipv6 }));
const run = (...args) =>
  spawnSync(process.execPath, [script, ...args], {
    encoding: 'utf8',
    env: { ...process.env, KSSMI_STAMP_SNAPSHOT_PATH: snapshotPath, KSSMI_STAMP_OFFICIAL_JSON: officialPath },
  });

try {
  const stale = '2025-01-01T00:00:00Z';

  writeSnapshot(stale);
  writeOfficial(repoSnapshot.ipv4, repoSnapshot.ipv6);
  let result = run('--check-only');
  assert.equal(result.status, 0, 'unchanged ranges must pass --check-only even when the copy is old: ' + result.stderr);
  assert.equal(JSON.parse(fs.readFileSync(snapshotPath, 'utf8')).verified_at, stale, '--check-only must not write');

  result = run();
  assert.equal(result.status, 0, 'unchanged ranges must be stamped: ' + result.stderr);
  const stamped = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
  assert.ok(Math.abs(Date.parse(stamped.verified_at) - Date.now()) < 120_000, 'verified_at must become now');
  assert.deepEqual([stamped.ipv4, stamped.ipv6], [repoSnapshot.ipv4, repoSnapshot.ipv6], 'stamping must not change the ranges');

  writeSnapshot(stale);
  writeOfficial([...repoSnapshot.ipv4, '198.51.100.0/24'], repoSnapshot.ipv6.slice(1));
  for (const args of [[], ['--check-only']]) {
    result = run(...args);
    assert.equal(result.status, 3, 'changed ranges must exit 3 ' + JSON.stringify(args));
    assert.match(result.stderr, /ipv4 added: 198\.51\.100\.0\/24/);
    assert.match(result.stderr, /ipv6 removed: /);
    assert.equal(JSON.parse(fs.readFileSync(snapshotPath, 'utf8')).verified_at, stale, 'changed ranges must never be stamped');
  }

  fs.writeFileSync(snapshotPath, '{"schema_version": 1}');
  assert.equal(run().status, 1, 'an invalid snapshot must fail');
  assert.equal(run('--bogus').status, 2, 'unknown arguments must be rejected');

  console.log('Cloudflare stamp tests passed.');
} finally {
  fs.rmSync(directory, { recursive: true, force: true });
}
