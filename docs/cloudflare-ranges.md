# Cloudflare IP range maintenance

Kssmi stores a reviewed, versioned copy of Cloudflare's published proxy
networks in \`private/cloudflare-ip-ranges.json\`. The only permitted sources are:

- \`https://www.cloudflare.com/ips-v4/\`
- \`https://www.cloudflare.com/ips-v6/\`

The snapshot is validated offline for schema, official source URLs, canonical
CIDRs, duplicates, family, list size, future timestamps, and a maximum age of
45 days. Remote retrieval additionally requires TLS verification, \`text/plain\`,
a bounded response, and a final URL that remains the exact official endpoint.

## Stage boundary

Stage 4.1 provides the snapshot and Node.js maintenance tools only. Kssmi's PHP
runtime still uses its embedded range list until stage 4.2 installs the strict
snapshot consumer and release/rollback integration. Do not remove the embedded
PHP ranges before 4.2 is complete and tested.

## Offline validation

~~~text
npm run validate:cloudflare-ranges
npm run test:cloudflare-ranges
~~~

The offline suite rejects missing or malformed snapshots, duplicate or
non-canonical CIDRs, wrong IP families, future/stale timestamps, HTML and
oversized responses, non-HTTPS sources, and redirects outside the exact
official endpoints.

## Reviewing the current official ranges

To compare the committed snapshot without changing it:

~~~text
npm run validate:cloudflare-ranges -- --check-remote
~~~

This command fails when Cloudflare's current lists differ and prints every
added or removed CIDR.

## Updating the snapshot

When a reviewed difference is expected:

1. Start from a clean, current branch.
2. Run \`npm run update:cloudflare-ranges\`.
3. Inspect every added and removed CIDR in the Git diff.
4. Reject empty lists, HTML, unexpected large removals, or any non-official
   source/redirect.
5. Run both offline commands and the remote comparison again.
6. Commit the reviewed snapshot through the normal release process.

The updater writes a private temporary file and atomically renames it into
place. It never commits or deploys automatically and needs no Cloudflare API
token or dashboard change.
