# Temporary build-only dependency exception

On 2026-11-18 the repository owner explicitly approved a single-advisory
exception and the production release, superseding their earlier refusal.
It expires at **2026-12-02 00:00 Asia/Shanghai** and must not auto-renew.
This accepts residual build dependency risk; it does not patch picomatch.

## Evidence and scope

- https://github.com/advisories/GHSA-fc5q-39xc-vjpf (CVE-2026-112366) has no
  patched release as of the review. The lockfile has picomatch 4.0.7 and a
  nested 2.3.2 copy via anymatch. Astro, Vite, tinyglobby, fdir and internal
  helpers are other dependency paths. Transitive records are not separate CVEs.
- The vulnerable input is an attacker-chosen glob pattern. Content loaders in
  `src/content.config.ts` use fixed `**/*.md` / `*.md` patterns and local bases.
- `astro.config.mjs` selects static output. The release tar in
  `.github/workflows/deploy.yml` contains `dist`, PHP `private` and named
  deployment scripts; it does not contain node_modules or a Node server.
  No public production request path to this dependency was identified.
- Trusted configuration is still executable build input. Reassess before
  adding untrusted glob input, CMS-controlled patterns, SSR, a Node service,
  or public development/preview servers. This review is not a general claim
  that development and CI dependencies cannot be exploited.
- Astro was upgraded to 7.4.12 to fix its independent advisories. Those
  advisories are outside this exception and must not be ignored.

## Enforcement

`scripts/audit-dependencies.mjs` prints the complete raw npm audit report.
Only the exact high-severity GHSA/range and dependency records whose entire
advisory graph leads to it are eligible. Other high/critical roots, mixed
advisories, expired/unapproved decisions, malformed reports and audit failures
block release. SHA-256 evidence binds the decision to the reviewed lockfile,
Astro/content configuration and release workflow. Any change requires review.

The deployment audit runs the regression tests before evaluating the live
report. Quarterly security maintenance retains its unfiltered npm audit.
After a patched release becomes available, update every resolved copy, verify
compatibility/build output and remove this exception. Do not delete the
lockfile, force a major downgrade, or silently extend this decision.
