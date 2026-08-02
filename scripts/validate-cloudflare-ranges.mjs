import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  compareRangeLists,
  fetchOfficialRanges,
  readSnapshot,
} from './lib/cloudflare-ranges.mjs';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const snapshotPath = path.join(root, 'private', 'cloudflare-ip-ranges.json');
const supportedArguments = new Set(['--check-remote']);
const unknownArguments = process.argv.slice(2).filter((argument) => !supportedArguments.has(argument));

if (unknownArguments.length > 0) {
  console.error('Unknown argument(s): ' + unknownArguments.join(', '));
  process.exit(2);
}

try {
  const snapshot = readSnapshot(snapshotPath);
  console.log(
    'Cloudflare snapshot is valid: ' + snapshot.ipv4.length + ' IPv4, ' +
      snapshot.ipv6.length + ' IPv6, verified ' + snapshot.verified_at
  );

  if (process.argv.includes('--check-remote')) {
    const official = await fetchOfficialRanges();
    const differences = compareRangeLists(snapshot, official);
    if (differences.length > 0) {
      console.error('Cloudflare official ranges differ from the repository snapshot:');
      for (const difference of differences) {
        console.error('- ' + difference.family + ' ' + difference.kind + ': ' + difference.cidr);
      }
      console.error('Review the change, then run: npm run update:cloudflare-ranges');
      process.exit(1);
    }
    console.log('Repository snapshot matches the current official Cloudflare ranges.');
  }
} catch (error) {
  console.error('Cloudflare range validation failed: ' + error.message);
  process.exit(1);
}
