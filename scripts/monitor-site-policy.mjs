import { resolveCname } from 'node:dns/promises';

// Explicitly approved exception: this domain expired in November 2026.
// Resume HTTP and TLS checks automatically as soon as parking is removed.
const EXPIRY_PARKING = new Map([['smallnm.com', 'expired.hichina.com']]);

export async function partitionSites(sites, lookup = resolveCname) {
  const active = [];
  const parked = [];
  for (const site of sites) {
    const [host] = site;
    const expected = EXPIRY_PARKING.get(host);
    if (expected) {
      try {
        const aliases = await lookup(host);
        if (aliases.some((alias) => alias.toLowerCase().replace(/\.$/, '') === expected)) {
          parked.push({ host, target: expected });
          continue;
        }
      } catch {
        // Missing CNAME or DNS failure is not evidence of approved parking.
        // Keep the host active so ordinary checks still detect outages.
      }
    }
    active.push(site);
  }
  return { active, parked };
}
