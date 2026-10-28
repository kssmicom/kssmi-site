# Agent.md

This file provides guidance to coding agents when working with code in this repository.

## Scope and workspace rules

Read [the workspace guide](../Agent.md) and [the workspace rules](../../AGENTS.md) first. This is the independent website Git repository; the outer repository's website pointer is not the website working-tree status.

All local temporary documents, test scripts, fixtures, test databases, results, logs, screenshots, downloads, build output and caches belong under `D:\Desk\Desk\Tools\KS\Kssmi\tep\<task>\`. Inspect tool output paths before running commands; use output options, process-local temporary directories or an isolated copy under `tep` as necessary. Existing commands below are entry-point references, not permission to write to their default locations. Formal source, maintained tests and project configuration stay in the repository.

Use current source and configuration as the authority. Historical reports and deleted `fixing/tools/` utilities are not current validation instructions. This guide does not authorize commits, pushes, merges, deployments or production changes beyond the current conversation's authorization.

## Project Overview

**Kssmi** is a multilingual B2B eyewear manufacturing website with an Astro static frontend and PHP services. It targets optical retailers, boutique shops and eyewear brands. Dependency versions and runtime requirements are defined by the repository configuration below.

**Website:** https://kssmi.com
**Repository:** https://github.com/kssmicom/kssmi-site

## Tech Stack

- **Astro 7** - Static site generator with content loaders
- **Tailwind CSS 4** - Utility-first styling through the Vite plugin
- **TypeScript** - Full type safety
- **Fuse.js** - Client-side fuzzy search
- **Pagefind** - Static site search (via astro-pagefind)
- **17 Languages** - EN, IT, ES, FR, DE, PT, RU, JA, TR, AR, KO, ZH, HI, VI, JV, MS, TG
- **Cloudflare Turnstile** - Anti-spam protection for forms
- **Node.js 24+** - See `package.json`, `package-lock.json` and `.nvmrc` for exact requirements
- **PHP backend** - Inquiry delivery, short links and visitor analytics; static Astro output does not replace the PHP runtime

## Build Commands

Commands are defined in `package.json`. When running locally, arrange a compliant workspace and output paths under `tep` first; default dev/build/test commands can write outside it.

```bash
# Development
npm run dev                 # Start dev server at localhost:4321

# Production build entry point (default output is dist/; isolate/redirect before local use)
npm run build              # Full build with validation
npm run preview            # Preview production build locally

# Utilities
npm run validate           # Run content validation script only
npm run prebuild           # Run all pre-build scripts (validation, llms.txt, sitemap)
```

## Architecture Overview

### Content Collections

Defined in `src/content.config.ts` using content loaders:

| Collection | Location | Purpose |
|------------|----------|---------|
| `products` | `src/content/products/` | Product markdown files |
| `collection` | `src/content/collection/` | Landing pages, about, contact |
| `blog` | `src/content/blog/` | Blog posts |
| `feature` | `src/content/feature/` | Eyewear landing/feature pages |
| `category-product` | `src/content/category-product/` | One Markdown file per category with multilingual SEO fields |

### Hybrid Content Architecture

**Products** use product folders containing one Markdown file per language:
```
src/content/products/
└── kao-001-acetate-optical-frame/
    ├── kao-001-acetate-optical-frame.en.md
    ├── kao-001-acetate-optical-frame.it.md
    └── ...
```

**Landing pages** have page-specific metadata and section files under `src/content/collection/{page}/`. Inspect the existing page and schema instead of assuming an English-only three-file split.

**FAQ** is a hub with optional topic pages. Hub content lives at `src/content/collection/faq/index/faq.{lang}.md`; topic content uses `faq/{topic}/{topic}.{lang}.md`. See `src/components/collection/faq/faqData.ts`, `src/components/pages/FAQPage.astro` and `FAQTopicPage.astro`. Maintain translated content in Markdown, not a hardcoded Q&A array in a component.

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
├── blogs/                   # Blog covers
├── collection/              # Landing page images
├── certifications/          # Certification assets
├── icon/                    # Shared icon assets
└── global/                  # Logos, icons, banners
```

**Frontmatter format:**
```yaml
cover: "/media/products/kso-005/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-005-1.webp"
gallery:
  - "/media/products/kso-005/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-005-2.webp"
```

### Product Naming Convention

The following are existing naming families, not an exhaustive SKU schema.
Current folders also include names such as `kao-001-acetate-optical-frame`;
inspect the actual product before choosing a slug or moving assets.

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
| Product Detail | `/product/{slug}` or `/{lang}/product/{slug}` | Preserve the current product slug |
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

This prevents empty category pages when `src/content/blog/` or `src/content/feature/` have no matching content.
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
- **FAQPage.astro / FAQTopicPage.astro** - FAQ hub and topic pages in `src/components/pages/`

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
  expire both identity and contact HttpOnly cookies (max 5 attempts, at most
  one per page load, generation-checked).
- `vjt-tracker.js` stores its keys as `{c: cycle, v: value}` and checks
  `cycleIsCurrent()` before every send. `VJTTracker.enabled` is derived from
  `__kssmiConsent.trackingCycle()`.
- Lock order is `kssmi-vjt-identity` then `kssmi-consent-state`; never await
  the network while holding the state lock. Browsers without Web Locks do not
  run VJT at all.
- The "Cookie Settings" button belongs to the controller; `cookie-banner.js` is
  UI only and is loaded on demand (`window.__kssmiCookieBanner.openPreferences`).
- Contact navigation remains usable when analytics is refused, but contact
  credentials and events must follow the current analytics consent cycle.
  Preserve the identity lock and in-flight request protections in
  `public/js/vjt-contact-core.js`.
- Inquiry privacy consent is separate from optional analytics/marketing
  consent. Preserve server-side notice-version checks and consent receipts;
  a required front-end checkbox alone is not sufficient.
- Current regression coverage lives in `scripts/test-contact-consent.mjs`,
  `scripts/test-contact-consent-http.php`, `scripts/test-inquiry-privacy.php`
  and `tests/browser/`. The old `fixing/tools/r6-test/` tree has been removed.

### Build and test side effects
- Build and dev commands normally write `dist/`, `.astro/`, generated public
  resources and other caches. Prebuild also generates sitemap and llms files.
  Check all outputs before local execution and isolate or redirect them to
  the task directory under `tep` as required by the workspace rules.
- Do not blindly restore generated files: compare before/after state and
  preserve existing user changes. `Build-Site.bat` has an automatic sitemap
  restore step, so it is not a safe shortcut around this review.
- PHP tests, Playwright and browsers can write databases, results, traces and
  caches outside their working directory. Inspect the relevant scripts and
  configure output and process-local temporary paths; setting TEMP alone
  does not redirect every tool.
- Do not freeze a historical build duration into the work plan. Choose
  validation appropriate to the actual change; documentation-only edits do
  not require a full build.

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
- Search index served via `/api/search-index/{lang}.json` (static, rebuilt on each deploy)
- Fuse.js loaded on first search input focus (lazy import)
- Do not reintroduce the full search index as inline JSON in page HTML
- Rebuild required after product changes

## Development Server Features

The Vite plugin in `astro.config.mjs` watches `src/content/products/` for new folders and auto-restarts the server when new products are added.

## Content Workflow

### Adding a Product

1. Inspect a current product folder and the schema in `src/content.config.ts`.
2. Add stable image assets under `public/media/products/`, following the
   current product's naming and URL conventions.
3. Create the matching content folder under `src/content/products/` and
   maintain all 17 language entries, product facts and SEO metadata.
4. Validate content, language routing, image URLs and search output in the
   compliant local workspace; a dev-server refresh alone is not acceptance.

### Adding a Landing Page or FAQ Topic

1. Inspect the same type of existing page, its schema, loader and components.
2. Follow its current metadata/section layout under `src/content/collection/`
   rather than imposing an English/other-language split.
3. For FAQ topics, follow `faqData.ts`: the hub declares topics and UI labels;
   existing topic pages receive links and other topics use hub anchors.
4. Preserve language routes, stable slugs, canonical/hreflang relationships
   and shared components. Do not hardcode product facts or translated Q&A
   into layouts to bypass the content system.

## Deployment

- **Server:** Hetzner VPS
- **Panel:** CyberPanel
- **Path:** `/home/kssmi.com/public_html`
- **Auto-deploy:** Push to `main` or manual dispatch starts `.github/workflows/deploy.yml`:
  sealed build artifact → production deployment and verification → release
  evidence and enforced final signoff.
- A successful build is not proof of a successful production release. Match
  the commit SHA to its run and final signoff. Read-only status checks must
  not dispatch/rerun workflows or execute production smoke tests.
- Deployment details and recovery policy live in `docs/permission-policy.md`,
  `docs/promotion-policy.md`, `docs/deploy-fs-safety.md`,
  `docs/runtime-identity.md`, `docs/release-evidence.md` and
  `docs/rollback-drill.md`. Historical server backups are not live evidence.

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
  `npm run test:deploy-credential-transition`. Shell deployment/permission
  tests require a suitable Linux environment; inspect each test's runtime
  requirements rather than treating all PHP tests as Linux-only.

### Short-links sign-in and reset (KS-03) (CRITICAL)
- `/short-links` and `/api/short-links-reset.php` are outside Cloudflare
  Access by design. An anonymous caller must get the same answer whether or
  not an address has an account: one sign-in error ("Invalid email or
  password."), a dummy bcrypt check for unknown addresses, and every reset
  request answered `{"ok":true}` before the address is examined
  (`kssmi_short_links_finish_response()`). Budget, token and SMTP failures go
  to `error_log` only.
- Budgets live in `private/short-links-tool.php`. Sign-in: 30/15 min per IP,
  and per submitted address (no IP in the identity) 10/15 min and 40/day. A
  browser with a device cookie for the account (`__Secure-kssmi_sl_device`,
  HMAC keyed by the account's bcrypt hash, 30 days, `Path=/short-links`) uses
  its own 10/15 min instead, so a flood cannot lock a colleague out of a known
  device. Reset: 10/h per IP; 3/h and 6/day per address; 20/h and 50/day for
  all reset mail (it shares the sales@ Gmail quota with inquiries).
- A colleague throttled on a new device uses "Forgot password?": completing
  the reset marks that browser as known. Budgets expire on their own.
- Tests: `npm run test:short-links-auth`, `npm run test:short-links-http`.

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
7. **Icons and text**: use the existing SVG icon system rather than emoji icons;
   check meaningful text contrast against its actual background (at least 4.5:1).
8. **GTM and consent**: preserve the current delayed GTM loading and Consent Mode
   initialization in `Layout.astro`; do not move third-party analytics ahead
   of its consent defaults or replace the current interaction/timer policy.
