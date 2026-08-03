# Cloudflare IP range and origin-boundary maintenance

Kssmi stores a reviewed, versioned copy of Cloudflare's published proxy
networks in `private/cloudflare-ip-ranges.json`. The only permitted sources are:

- `https://www.cloudflare.com/ips-v4/`
- `https://www.cloudflare.com/ips-v6/`

The snapshot is validated offline for schema, official source URLs, canonical
CIDRs, duplicates, family, list size, future timestamps, and a maximum age of
45 days. Remote retrieval additionally requires TLS verification, `text/plain`,
a bounded response, and a final URL that remains the exact official endpoint.

## Runtime boundary

The deployed PHP consumer reads the private snapshot and trusts forwarded
client headers only when `REMOTE_ADDR` belongs to a validated Cloudflare range.
The two administration endpoints invoke the strict trusted-proxy gate before
starting a session. A missing, malformed, or stale snapshot therefore causes
the administration request to fail closed; a forgeable `CF-*` header alone is
never evidence that a request passed through Cloudflare.

Cloudflare Access remains the identity gate at the edge. The PHP gate is the
independent origin boundary for these administration endpoints. If a host-level
firewall, cloud security group, or OpenLiteSpeed listener allowlist is added,
it is an additional layer and must follow the synchronization procedure below.

After every origin-boundary change, verify both protected endpoints:

1. Normal browser and service-token requests through Cloudflare Access work.
2. Direct requests to the origin return `403`.
3. Direct requests with forged `CF-Ray` and `CF-Connecting-IP` headers still
   return `403`.

Do not publish the origin IP or put it in CI logs. Run direct-origin checks from
an authorized administrator shell and record only the status codes.

## Offline validation

```text
npm run validate:cloudflare-ranges
npm run test:cloudflare-ranges
npm run validate:cloudflare-audit
```

The offline suite rejects missing or malformed snapshots, duplicate or
non-canonical CIDRs, wrong IP families, future/stale timestamps, HTML and
oversized responses, non-HTTPS sources, and redirects outside the exact
official endpoints.

## Candidate-bound and weekly read-only audit

`.github/workflows/cloudflare-ranges.yml` runs for every push to `main`, every
Monday, and can also be started manually. The push trigger gives each release
candidate an audit run whose `head_sha` is the exact candidate commit. It has
read-only repository permissions, a five-minute timeout, and actions pinned to
complete commit SHAs. It compares the committed snapshot with the current
official HTTPS endpoints but never edits, commits, pushes, or deploys anything.

To perform the same comparison locally without changing the snapshot:

```text
npm run validate:cloudflare-ranges -- --check-remote
```

A difference makes the audit fail, lists every added or removed CIDR, and
prints the manual update command `npm run update:cloudflare-ranges`.

## Safe synchronization and update order

Never remove an old range from a host-level allowlist before a compatible PHP
snapshot is live. For an official range change, use this expansion-first order:

1. Review the audit output against both official Cloudflare HTTPS endpoints.
2. **Add new Cloudflare CIDRs to the origin allowlist** (firewall, security
   group, or OpenLiteSpeed), without removing old CIDRs.
3. Run `npm run update:cloudflare-ranges`, inspect the complete Git diff, then
   run the offline validation and remote comparison.
4. **Deploy the reviewed repository snapshot** with the PHP consumer as one
   release unit. Confirm Access traffic works and both forged direct-origin
   checks return `403`.
5. **Remove obsolete CIDRs from the origin allowlist** only after the new
   release and production checks have succeeded.

Reject empty lists, HTML responses, unexpected large removals, non-official
sources, or redirects. The updater writes a private temporary file and
atomically renames it into place. It never commits or deploys automatically and
needs no Cloudflare API token or dashboard change.

## Rollback

The release manager backs up and restores the PHP modules and Cloudflare JSON
snapshot together. During a range transition, keep the origin allowlist as the
union of the old and new ranges until the new release is proven healthy.

If verification fails, **Rollback** the application release first so the old
PHP consumer and old snapshot return as a compatible unit. Keep the union
allowlist while rollback is tested. Remove newly added ranges only after normal
Access traffic and direct-origin rejection have both been revalidated. This
order prevents a rollback from accidentally cutting off legitimate Cloudflare
traffic.
