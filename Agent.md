# Agent.md

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

**Kssmi** is a high-performance B2B eyewear manufacturing website built with Astro 5.x. It targets global optical retailers, boutique eyewear shops, and high-end fashion brands.

**Website:** https://kssmi.com
**Repository:** https://github.com/kssmicom/kssmi-site

## Tech Stack

- **Astro 5.17+** - Static site generator with content collections
- **Tailwind CSS 3.4+** - Utility-first styling
- **TypeScript** - Full type safety
- **Fuse.js** - Client-side fuzzy search
- **Pagefind** - Static site search (via astro-pagefind)
- **17 Languages** - EN, IT, ES, FR, DE, PT, RU, JA, TR, AR, KO, ZH, HI, VI, JV, MS, TG
- **Cloudflare Turnstile** - Anti-spam protection for forms

## Build Commands

All commands run from `kssmi-site/` directory:

```bash
# Development
npm run dev                 # Start dev server at localhost:4321

# Production
npm run build              # Full build with validation → outputs to dist/
# After significant route changes, clean caches first:
# rm -rf .astro dist && npm run build
# After significant route changes, clean caches first:
# rm -rf .astro dist && npm run build
npm run preview            # Preview production build locally

# Utilities
npm run validate           # Run content validation script only
npm run prebuild           # Run all pre-build scripts (validation, llms.txt, sitemap)
```

## Architecture Overview

### Content Collections (Astro 5)

Defined in `src/content/config.ts` with three collections:

| Collection | Location | Purpose |
|------------|----------|---------|
| `products` | `src/content/products/` | Product markdown files |
| `collection` | `src/content/collection/` | Landing pages, about, contact |
| `blog` | `src/content/blog/` | Blog posts |
| `feature` | `src/content/feature/` | Eyewear landing/feature pages |

### Hybrid Content Architecture

**Products** use flat markdown files per language:
```
src/content/products/
├── yto-001.en.md
├── yto-001.it.md
└── yto-001.{lang}.md
```

**Landing Pages** (about-us, contact, quote) use a split architecture:
- **English**: 3-file split (`{page}.en.md`, `top.en.md`, `bottom.en.md`)
- **Other languages**: Single file (`{page}.{lang}.md`)

### Internationalization (i18n)

Configured in `astro.config.mjs`:
- Default locale: `en` (no prefix)
- Other locales: prefixed (`/it/`, `/fr/`, etc.)
- RTL support: Arabic (`ar`) uses RTL layout
- Translations in: `src/translations/index.ts` (17 language objects)

### Locale Detection in English-Tree Pages (CRITICAL)

English-tree pages (those without `[lang]` prefix, e.g., `product/index.astro`) use a standard
locale detection block as a safety net when Astro routes non-English URLs to the English tree:

```js
let lang = 'en';
// Detect locale from URL — Astro may route prefixed URLs to English route tree
const LANGUAGES = ['it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg'];
const urlPath = Astro.url.pathname;
for (const l of LANGUAGES) {
  if (urlPath.startsWith('/' + l + '/') || urlPath === '/' + l) {
    lang = l;
    break;
  }
}
const currentPath = lang === 'en' ? '' : lang + '/';
```

**CRITICAL**: This must be placed OUTSIDE `getStaticPaths()` — `Astro.url` is not available inside `getStaticPaths`.

### Image Architecture (CRITICAL)

All images are stored in `public/media/` for stable SEO URLs:

```
public/media/
├── products/{sku}/          # Product images
├── blog/                    # Blog covers
├── pages/                   # Landing page images
└── global/                  # Logos, icons, banners
```

**Frontmatter format:**
```yaml
cover: "/media/products/kso-005/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-005-1.webp"
gallery:
  - "/media/products/kso-005/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-005-2.webp"
```

### Product Naming Convention

| Type | Prefix | Example |
|------|--------|---------|
| Optical Frames | KSO | KSO-005, KSO-006 |
| Sunglasses | KSS | KSS-011, KSS-012 |
| Metal Sunglasses | KMS | KMS-007, KMS-117 |
| Titanium Optical | KTO | KTO-001, KTO-002 |
| Titanium Sunglasses | KTS | KTS-020, KTS-206 |
| Rimless Optical | KRO | KRO-203 |
| Rimless Sunglasses | KRS | KRS-201, KRS-202 |

### URL Structure

| Page Type | Pattern | Example |
|-----------|---------|---------|
| Product List | `/product/` or `/{lang}/product/` | `/product/`, `/it/product/` |
| Product Detail | `/product/{slug}` or `/{lang}/product/{slug}` | `/product/yto-001/` |
| Category | `/product/{category}` | `/product/optical-frames/` |
| Blog List | `/blog/` or `/{lang}/blog/` | `/blog/`, `/zh/blog/` |
| Blog Detail | `/blog/{slug}` or `/{lang}/blog/{slug}` | `/blog/manufacturing-tips/` |
| Blog Category | `/blog/{category}` | `/blog/material-science/` |
| Eyewear List | `/eyewear/` or `/{lang}/eyewear/` | `/eyewear/`, `/fr/eyewear/` |
| Eyewear Category | `/eyewear/{category}` | `/eyewear/titanium-sunglasses/` |
| Landing Page | `/{slug}` or `/{lang}/{slug}` | `/about-us/`, `/it/about-us/` |

## Empty Collection Guards

Collections without content (blog, feature) use guards in `getStaticPaths` to prevent empty page generation:

```js
// In getStaticPaths, add early return when collection is empty:
const allProducts = await getCollection('blog');
if (allProducts.length === 0) return [];  // generates 0 pages
```

This prevents ~800+ empty category pages when `src/content/blog/` or `src/content/feature/` have no `.md` files.
When content is added, the guard is skipped automatically — no code change needed.

Affected files: `blog/[category]/[...page].astro`, `blog/[page].astro`,
`eyewear/[category]/[...page].astro`, `eyewear/[page].astro`, and their `[lang]` counterparts.

Also applied in `scripts/generate-sitemap.js` to skip empty blog/feature sitemaps.

## Key Components

Located in `src/components/`:

- **Header.astro** - Navigation with language picker
- **Footer.astro** - Multi-column footer with translations
- **ProductCard.astro** - Product grid card
- **Search.astro** - Fuse.js search component
- **InquiryForm.astro** - Contact form with Turnstile
- **FormTurnstile.astro** - Turnstile widget + page-lifecycle controller for the inquiry and 404 forms
- **TurnstileApiLoader.astro** - Shared, retryable Turnstile API loader (in Layout `<head>`)
- **LanguagePicker.astro** - Language switcher
- **TechnicalSpecs.astro** - Product specifications display

## ClientRouter Scripts & Turnstile (CRITICAL)

`Layout.astro` enables Astro's `<ClientRouter />`, so most link clicks swap the
page body without a full reload (language switches and links to missing pages
included — the router swaps 404 responses too).

### How inline scripts behave on soft navigation
- Astro remembers every executed script by its **text** (inline) or **src** and
  skips it on later pages unless it has `data-astro-rerun`.
- `define:vars` puts the values into the script text, so a script with
  per-language vars runs once **per language** and each run's
  `astro:page-load` listener stays registered forever. With N languages
  visited, N listeners act on every page — the last one wins.
- Rule: a script that must (re)initialize each page keeps **identical text on
  every page**, reads per-page/per-language data from the DOM (e.g. a
  `data-i18n` JSON attribute), and binds via `astro:page-load` with a
  per-element guard (`el.dataset.submitBound`). Never clone/replace a form to
  drop listeners — it also replaces any widget inside it.
- Window `load` fires the first `astro:page-load`; `astro:before-swap` is the
  place to clean up the old page.

### Turnstile
- Load the API only through `window.__kssmiTurnstileApi()` (TurnstileApiLoader).
  It requests the API once per document and retries after a failure/timeout.
- Form widgets only via `<FormTurnstile lang={lang} />`. It keeps
  `window.turnstileToken` / `window.turnstileWidgetId` pointed at the current
  page's widget, shows a localized retry button if the API cannot load, and
  ignores stale render attempts. Do not add `onTurnstileLoad` or a second
  `api.js` `<script>`.
- VisitorTracker's `vjt_capability` widget is separate (own instance and proof
  queue) and only shares the loader. It uses its **own Invisible-mode key
  pair**: `PUBLIC_VJT_TURNSTILE_SITE_KEY` (GitHub secret → build) and
  `vjt_turnstile_secret` (server `private_config.php`). Forms keep
  `PUBLIC_TURNSTILE_SITE_KEY` / `turnstile_secret`. Never fall back from one
  pair to the other; a missing VJT key or secret only disables statistics.
- A VJT proof must never ask the visitor to interact. On
  `before-interactive-callback`, error, unsupported, timeout callbacks or the
  15 s timeout the attempt is ended (widget removed, late callbacks ignored)
  and the tab stops requesting proofs for 30 minutes (sessionStorage
  `kssmi_vjt_proof_cooldown_until`).
- `astro dev` disables Turnstile entirely; verify with a build. Use Cloudflare
  test sitekeys (`1x00000000000000000000AA` always passes) on localhost — the
  production key fails there with error 110200.
- Pre-build policy scripts check form source text (`newForm`,
  `window.turnstile.reset(window.turnstileWidgetId);`, `const result = await
  response.json();`). Keep those names when editing the forms.

### Consent record and VJT consent cycles (ConsentController)
- `src/components/ConsentController.astro` (in Layout `<head>`, before
  VisitorTracker) is the **only writer** of `localStorage['cookie-consent']`.
  Every write goes through `mutateConsent()`, which reads, computes and writes
  inside the Web Lock `kssmi-consent-state`. Never call
  `localStorage.setItem('cookie-consent', …)` elsewhere; `validate:dom` fails
  the build if you do. UI code calls `window.__kssmiConsent.set({analytics, ads})`.
- Record: `{v:2, analytics, ads, rev, cycle?, cleanup?}`. Each analytics grant
  gets a new random `cycle`; `vjt-capability.php` requires `consent_cycle` and
  only continues an identity whose signed `cyc` claim matches. Withdrawal
  creates `cleanup {id, attempts, status}` and calls `/api/vjt-forget.php` to
  expire the HttpOnly `KSSMI_VJT_IDENTITY` (max 5 attempts, at most one per
  page load, generation-checked).
- `vjt-tracker.js` stores its keys as `{c: cycle, v: value}` and checks
  `cycleIsCurrent()` before every send. `VJTTracker.enabled` is derived from
  `__kssmiConsent.trackingCycle()`.
- Lock order is `kssmi-vjt-identity` then `kssmi-consent-state`; never await
  the network while holding the state lock. Browsers without Web Locks do not
  run VJT at all.
- The "Cookie Settings" button belongs to the controller; `cookie-banner.js` is
  UI only and is loaded on demand (`window.__kssmiCookieBanner.openPreferences`).
- Local tests: `Kssmi/fixing/tools/r6-test/` (isolated tree, PHP workers behind
  a proxy on 4325, headless Edge scenarios V1–V23).

### Build side effects
- A full `npm run build` takes 30+ minutes and recreates `dist/`.
- The prebuild step rewrites `<lastmod>` in `public/**/sitemap*.xml`; restore
  those files before committing unrelated changes.

## Pre-Build Validation

The `scripts/validate-content.mjs` script runs before build to check:
- UTF-8 BOM markers
- YAML frontmatter syntax
- Duplicate keys
- Required fields (title)
- Array formatting

Run manually: `npm run validate`

## Dynamic Search (Fuse.js)

Search uses lazy-loading from pre-built JSON endpoint:
- Search index served via  (static, rebuilt on each deploy)
- Fuse.js loaded on first search input focus (lazy import)
- No inline JSON in page HTML — reduces page weight ~50KB
- Rebuild required after product changes

## Development Server Features

The Vite plugin in `astro.config.mjs` watches `src/content/products/` for new folders and auto-restarts the server when new products are added.

## Content Workflow

### Adding a Product

1. Create folder: `public/media/products/{sku}/`
2. Add images with SEO-friendly names
3. Copy existing product markdown files: `yto-001.{lang}.md`
4. Rename to `{sku}.{lang}.md` and edit content
5. Refresh browser to see in search

### Adding a Landing Page (English)

1. Create `src/content/collection/{page}/`
2. Create `meta.en.md` (SEO metadata)
3. Create `top.en.md` (sections S01-S10)
4. Create `bottom.en.md` (sections S11-S20)
5. Create page component in `src/pages/`

### Adding a Landing Page (Other Languages)

1. Create single file: `{page}.{lang}.md` in `src/content/collection/{page}/`
2. Include all content sections in one file

## Deployment

- **Server:** Hetzner VPS
- **Panel:** CyberPanel
- **Path:** `/home/kssmi.com/public_html`
- **Auto-deploy:** Push to `main` → GitHub Actions → Live site
- **Status URL:** https://kssmi.com/php-status.php

### Admin credentials (KS-05) (CRITICAL)
- The admin password hash, reset tokens and credential generation live only
  in `/home/kssmi.com` (`.email_logs_password`, `.email_reset_tokens.json`,
  `.admin_credential_generation`), each `0600` with a `<file>.lock` beside it.
  A release holds exact links to them, and its root is `0551`.
- In PHP, write or lock a credential only through `kssmi_admin_atomic_write()`
  and `kssmi_admin_file_lock()`, which resolve the link first
  (`kssmi_admin_storage_path()`). Never `rename()` onto a credential path or
  create files in the release root.
- Sessions and reset tokens are bound to the random credential generation; a
  password change or reset writes a new one. There is no integer version.
- `deploy-release.sh` records its rollback target before it changes anything,
  then freezes the outgoing release root, checks it and migrates the
  generation (`prepare_admin_credential_transition`). Rollback never writes a
  credential. Never run credential functions or these steps as root.
- After an admin password change, update the `SMOKE_ADMIN_PASSWORD` secret at
  once: the deployment smoke logs in with it. Manual recovery when a
  deployment stops is in `docs/permission-policy.md` and needs an explicit
  decision.
- Tests: `npm run test:permissions`, `npm run test:credential-persistence`,
  `npm run test:deploy-credential-transition` (Linux only).

## Layout Width Standards (CRITICAL — DO NOT DEVIATE)

All pages and components use a unified responsive width system:

| Context | Pattern |
|---------|---------|
| **Main content** (product, blog, landing, listing) | `w-[95%] lg:w-[92%] 2xl:w-[87%] max-w-[1860px] mx-auto px-4` |
| **Header / Footer** | `w-full max-w-[1860px] mx-auto px-4 sm:px-6 lg:px-8` |
| **Technical specs** | `w-[95%] lg:w-[90%] 2xl:w-[84%] max-w-[1660px] mx-auto px-4 lg:px-0` |
| **Narrow forms** (quote, 404) | `w-[95%] lg:w-[92%] 2xl:w-[87%] max-w-[1240px] mx-auto px-4` |
| **Very narrow** (thank-you, blog detail) | `w-[95%] lg:w-[90%] max-w-[820px] mx-auto` |
| **Full-width sections** | `w-full` on `<section>`, inner uses main pattern |

### Forbidden values
- **Never** `max-w-[1920px]` → use `1860px`
- **Never** `max-w-[1800px]` → use `1860px`
- **Never** `w-[94%]` → use `95%`
- **Never** `100vw` or `w-screen` or width ≥ 99%
- `<html>` has `overflow-x: hidden` — do not override

### Section wrapper template
```html
<section class="w-full py-12 lg:py-20 bg-white">
  <div class="w-[95%] lg:w-[92%] 2xl:w-[87%] max-w-[1860px] mx-auto">
    <!-- content -->
  </div>
</section>
```

## Critical Rules

1. **Images MUST use** `/media/...` paths (not relative paths)
2. **Never hardcode** product data in layout files or translations
3. **Product-specific content** belongs in markdown files only
4. **All products need** 17 language versions for full coverage
5. **Run validate** before committing to catch YAML errors
6. **Layout widths** MUST follow the unified standards above — never use old patterns
