import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  CLOUDFLARE_SOURCES,
  fetchOfficialRanges,
  serializeSnapshot,
  SNAPSHOT_SCHEMA_VERSION,
  validateSnapshot,
} from './lib/cloudflare-ranges.mjs';

if (process.argv.length > 2) {
  console.error('This command does not accept arguments.');
  process.exit(2);
}

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const snapshotPath = path.join(root, 'private', 'cloudflare-ip-ranges.json');
const temporaryPath = snapshotPath + '.tmp-' + process.pid + '-' + Date.now();

try {
  const official = await fetchOfficialRanges();
  const snapshot = validateSnapshot({
    schema_version: SNAPSHOT_SCHEMA_VERSION,
    verified_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
    sources: { ...CLOUDFLARE_SOURCES },
    ipv4: official.ipv4,
    ipv6: official.ipv6,
  });
  fs.writeFileSync(temporaryPath, serializeSnapshot(snapshot), {
    encoding: 'utf8',
    flag: 'wx',
    mode: 0o640,
  });
  fs.renameSync(temporaryPath, snapshotPath);
  try {
    fs.chmodSync(snapshotPath, 0o640);
  } catch {
    // Windows does not implement Unix permission bits; deployment will enforce 0640 in 4.2.
  }
  console.log(
    'Updated ' + snapshotPath + ' with ' + snapshot.ipv4.length + ' IPv4 and ' +
      snapshot.ipv6.length + ' IPv6 ranges. Review the Git diff before committing.'
  );
} catch (error) {
  try {
    fs.unlinkSync(temporaryPath);
  } catch {
    // The temporary file may not have been created.
  }
  console.error('Cloudflare range update failed: ' + error.message);
  process.exit(1);
}
