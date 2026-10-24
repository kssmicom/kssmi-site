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
 * Bring this checkout's snapshot in line with Cloudflare's official ranges.
 * CI, the deploy build and the weekly audit run it; nothing is committed.
 *
 *   Same ranges           verified_at becomes now (exit 0).
 *   Small change          the official ranges are adopted and verified_at
 *                         becomes now (exit 0); the change is printed as a
 *                         GitHub warning. Cloudflare changes a few ranges at a
 *                         time, so no PR is needed.
 *   Large change          nothing is written (exit 3): more than
 *                         MAX_AUTO_CHANGES_PER_FAMILY additions plus removals
 *                         in one family looks like a broken or tampered list
 *                         and needs a person.
 *   --check-only          compare and print only; exit 0 same, 3 any change.
 *
 * Exit 1 on any other failure. The repository copy may be older than the
 * 45-day limit; its ranges, not its date, are what is compared.
 * private/cloudflare-ranges-refresh.php applies the same limit on the server.
 */

export const MAX_AUTO_CHANGES_PER_FAMILY = 5;

const supportedArguments = new Set(['--check-only']);
const unknownArguments = process.argv.slice(2).filter((argument) => !supportedArguments.has(argument));
if (unknownArguments.length > 0) {
  console.error('Unknown argument(s): ' + unknownArguments.join(', '));
  process.exit(2);
}
const checkOnly = process.argv.includes('--check-only');

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const snapshotPath = process.env.KSSMI_STAMP_SNAPSHOT_PATH || path.join(root, 'private', 'cloudflare-ip-ranges.json');
const now = () => new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

try {
  const snapshot = readSnapshot(snapshotPath, { maxAgeDays: Number.POSITIVE_INFINITY });
  const official = process.env.KSSMI_STAMP_OFFICIAL_JSON
    ? JSON.parse(fs.readFileSync(process.env.KSSMI_STAMP_OFFICIAL_JSON, 'utf8'))
    : await fetchOfficialRanges();
  const differences = compareRangeLists(snapshot, official);

  if (differences.length === 0) {
    if (checkOnly) {
      console.log('Repository ranges match the official Cloudflare ranges.');
    } else {
      const stamped = validateSnapshot({ ...snapshot, verified_at: now() });
      fs.writeFileSync(snapshotPath, serializeSnapshot(stamped), 'utf8');
      console.log('Ranges match Cloudflare; verified_at stamped ' + stamped.verified_at + ' in this checkout only.');
    }
    process.exit(0);
  }

  const listing = differences.map((difference) => difference.family + ' ' + difference.kind + ': ' + difference.cidr);
  const perFamily = { ipv4: 0, ipv6: 0 };
  for (const difference of differences) perFamily[difference.family] += 1;
  const large = Object.values(perFamily).some((count) => count > MAX_AUTO_CHANGES_PER_FAMILY);

  if (checkOnly || large) {
    console.error('Cloudflare changed its published ranges:');
    for (const line of listing) console.error('- ' + line);
    if (large) {
      console.error(
        'More than ' + MAX_AUTO_CHANGES_PER_FAMILY + ' changes in one family: not adopted automatically. ' +
          'Check both official lists by hand; if they are genuine, run npm run update:cloudflare-ranges and commit.'
      );
    }
    process.exit(3);
  }

  const adopted = validateSnapshot({ ...snapshot, verified_at: now(), ipv4: official.ipv4, ipv6: official.ipv6 });
  fs.writeFileSync(snapshotPath, serializeSnapshot(adopted), 'utf8');
  console.log('::warning::Cloudflare changed its ranges; adopted in this checkout only: ' + listing.join('; '));
  console.log('Adopted the official Cloudflare ranges (' + adopted.ipv4.length + ' IPv4, ' + adopted.ipv6.length + ' IPv6), verified ' + adopted.verified_at + '.');
} catch (error) {
  console.error('Cloudflare range stamp failed: ' + error.message);
  process.exit(1);
}
