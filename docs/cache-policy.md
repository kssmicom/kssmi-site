# KSSMI cache policy

`public/.htaccess` is the only source of origin `Cache-Control` headers.
Do not add cache TTLs in the OpenLiteSpeed vhost, PHP files, the hosting panel,
or Cloudflare Browser Cache TTL overrides.

## Origin policy

| Response | Policy |
|---|---|
| Astro/content-fingerprinted assets | `public, max-age=31536000, immutable` |
| Stable-name images, fonts and video | `public, max-age=31536000` |
| Stable-name CSS/JS | `public, max-age=86400, must-revalidate` |
| HTML | `public, max-age=0, must-revalidate, s-maxage=600, stale-while-revalidate=60` |
| JSON/XML/TXT/manifests | `public, max-age=3600, must-revalidate` |
| PHP/API | `no-store, private` |
| `vjt-config.php` | `public, max-age=300, must-revalidate` |

The HTML policy is the unconditional fallback in `.htaccess`; later file-type
rules replace it for assets and PHP. This is required because OpenLiteSpeed may
not match a directory-index request such as `/` against `FilesMatch "\.html$"`.

Runtime asset fingerprints are derived from the first 12 hexadecimal characters
of the source file's SHA-256 digest after text line endings are normalized to LF,
matching the repository and Linux deployment representation. Run
`npm run validate:cache` after changing `cookie-banner.js`, `vjt-tracker.js`,
their references, or `.htaccess`.

## One-time OpenLiteSpeed cleanup

The old vhost `expires { ... }` block must be removed because it independently
generates a second cache header. On the server, review and run the updated
`scripts/ols-add-headers.py`; it creates a timestamped vhost backup, removes the
legacy expires block, preserves the security-header context, and then requires
an OpenLiteSpeed reload.

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
- the fingerprinted cookie banner (`immutable`);
- a tracking API endpoint (`no-store`).

If this check fails, inspect Cloudflare Response Header Rules, Browser Cache TTL,
the OLS vhost `expires` block, and the deployed `.htaccess`. Do not add another
override to hide the duplicate.
