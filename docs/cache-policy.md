# KSSMI cache policy

OpenLiteSpeed native contexts are the source of origin `Cache-Control` headers
for the homepage and explicitly allowlisted fingerprinted runtime assets.
Dynamic PHP endpoints send one explicit policy in application code.
OpenLiteSpeed only processes rewrite rules from `.htaccess`; Apache `Header`,
`FilesMatch`, and `Expires` cache directives must not be used there.

## Origin policy

| Response | Policy |
|---|---|
| Fingerprinted runtime assets under `/assets/runtime/` | `public, max-age=31536000, immutable` |
| Other static assets | no explicit managed origin policy; Cloudflare may apply its reviewed static-asset rule |
| Stable-name CSS/JS | no explicit managed policy; use fingerprinted URLs for long caching |
| Homepage `/` | `public, max-age=0, must-revalidate, s-maxage=600, stale-while-revalidate=60` |
| Other HTML and data files | no broad OLS context; preserve routing and security rewrites |
| PHP/API | `no-store, private` |
| `vjt-config.php` | `public, max-age=300, must-revalidate` |

The native vhost configuration includes an exact `/` context mapped to
`index.html`. This is required because a directory-index request is not reliably
matched by an extension-based context. PHP is deliberately excluded from every
static context to preserve the LSAPI handler.

Do not add broad OLS regex contexts by file extension. Native static contexts
can take precedence over `.htaccess` rewrite denials. A former catch-all JSON
context caused `/email-logs.json` to bypass its `[F,L]` rule and return HTTP 200.
Only explicit, reviewed public paths belong in the managed context block.

Runtime asset fingerprints are derived from the first 12 hexadecimal characters
of the source file's SHA-256 digest after text line endings are normalized to LF,
matching the repository and Linux deployment representation. Astro templates and
the post-Astro materializer use the same `scripts/lib/runtime-assets.mjs` generator,
so no fingerprint is copied manually between source files. The build writes:

- physical fingerprinted JavaScript files under `dist/assets/runtime/`;
- `dist/assets/runtime/manifest.json`, containing each logical name, URL, full
  SHA-256 digest, SRI value and byte count;
- exact runtime rewrite rules in `dist/.htaccess`, generated between the marker
  lines in the source template.

A dedicated, allowlisted OLS regex context maps those physical JS files with
`$DOC_ROOT/$0` and owns their immutable policy. Before changing the vhost,
`ols-add-headers.py` validates the deployed manifest and every referenced file.
OpenLiteSpeed 1.7.19 did not apply header operations from a plain directory
context in production, so the working regex form is intentional. Run
`npm run validate:cache`, `npm run validate:runtime-assets` and
`npm run test:runtime-assets` after changing a runtime source, consumer,
`.htaccess`, materializer, manifest library, or the OLS helper.

## One-time OpenLiteSpeed installation

Upload `scripts/ols-add-headers.py` to `/home/kssmi.com/ols-add-headers.py`, then
review and run it as root. It creates a timestamped vhost backup, replaces only
its marker-delimited cache contexts, removes a legacy `expires { ... }` block if
present, and installs only the exact homepage and runtime asset contexts. Validate the configuration
before restarting OpenLiteSpeed:

```sh
sudo python3 /home/kssmi.com/ols-add-headers.py
sudo /usr/local/lsws/bin/openlitespeed -t
sudo systemctl restart lsws
```

Do not run this automatically from application deployment without first
checking the current vhost file and confirming sudo/reload access.

## Cloudflare settings

Cloudflare should cache, not author, the browser policy:

1. Browser Cache TTL: **Respect Existing Headers**.
2. Remove Transform Rules or Response Header Rules that set `Cache-Control` or
   `Expires`.
3. Bypass cache for `*.php`, `/api/*`, admin pages and form endpoints.
4. Static assets: eligible for cache, Edge TTL respects origin.
5. HTML: Cache Everything may be enabled only after the deploy workflow has
   valid `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` secrets. The origin
   `s-maxage=600` then controls the edge TTL.
6. The API token only needs Zone / Cache Purge permission for the production
   zone. Do not use the Global API Key.

The deploy workflow purges Cloudflare only when both secrets exist, then warms
all 17 language homepages. Without those secrets it prints a warning and skips
the purge; do not enable HTML edge caching in that state because cached HTML can
reference hashed assets removed by a later deployment.

## Deployment assertions

After deployment, the workflow requires exactly one `Cache-Control` response
header for:

- the homepage (`s-maxage=600`);
- every runtime asset enumerated by the release manifest (`immutable`);
- a tracking API endpoint (`no-store`).

The production Node smoke independently reads the same release manifest and
requires every runtime asset to return HTTP 200, a JavaScript MIME type, one
immutable cache policy and bytes matching the manifest's complete SHA-256
digest. A source edit therefore needs only the normal build; page references,
generated rewrites and online checks all move to the new URL automatically.

The removed legacy `/email-logs.json` webroot path may return either `403`
(explicitly forbidden) or `404` (not present). Both satisfy the security
requirement; HTTP `200` does not.

If this check fails, compare a direct-origin request made with `curl --resolve`
against the public Cloudflare response. Inspect Cloudflare Response Header Rules,
Browser Cache TTL, the OLS managed cache contexts, and each PHP endpoint's own
header. Do not add another override to hide a duplicate.
