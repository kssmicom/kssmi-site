import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import {
  compareRangeLists,
  fetchOfficialRanges,
  readSnapshot,
  serializeSnapshot,
  validateSnapshot,
} from './lib/cloudflare-ranges.mjs';

/*
 * Compare the repository snapshot with Cloudflare's official ranges.
 *
 *   (no argument)  Same ranges: rewrite verified_at to now in this checkout
 *                  (CI and the deploy build do this, so the shipped snapshot
 *                  is verified at build time). Never committed.
 *   --check-only   Only compare; write nothing.
 *
 * Exit codes: 0 same ranges, 3 Cloudflare changed its ranges (review and run
 * npm run update:cloudflare-ranges, then open a PR), 1 any other failure.
 * The repository copy may be older than the 45-day limit; the ranges, not its
 * date, are what is compared.
 */

const supportedArguments = new Set(['--check-only']);
const unknownArguments = process.argv.slice(2).filter((argument) => !supportedArguments.has(argument));
if (unknownArguments.length > 0) {
  console.error('Unknown argument(s): ' + unknownArguments.join(', '));
  process.exit(2);
}
const checkOnly = process.argv.includes('--check-only');

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const snapshotPath = process.env.KSSMI_STAMP_SNAPSHOT_PATH || path.join(root, 'private', 'cloudflare-ip-ranges.json');

try {
  const snapshot = readSnapshot(snapshotPath, { maxAgeDays: Number.POSITIVE_INFINITY });
  const official = process.env.KSSMI_STAMP_OFFICIAL_JSON
    ? JSON.parse(fs.readFileSync(process.env.KSSMI_STAMP_OFFICIAL_JSON, 'utf8'))
    : await fetchOfficialRanges();
  const differences = compareRangeLists(snapshot, official);
  if (differences.length > 0) {
    console.error('Cloudflare changed its published ranges:');
    for (const difference of differences) {
      console.error('- ' + difference.family + ' ' + difference.kind + ': ' + difference.cidr);
    }
    console.error('Review the change, run npm run update:cloudflare-ranges and open a PR.');
    process.exit(3);
  }
  if (checkOnly) {
    console.log('Repository ranges match the official Cloudflare ranges (verified_at ' + snapshot.verified_at + ').');
    process.exit(0);
  }
  const stamped = validateSnapshot({
    ...snapshot,
    verified_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  });
  fs.writeFileSync(snapshotPath, serializeSnapshot(stamped), 'utf8');
  console.log('Ranges match Cloudflare; verified_at stamped ' + stamped.verified_at + ' in this checkout only.');
} catch (error) {
  console.error('Cloudflare range stamp failed: ' + error.message);
  process.exit(1);
}
