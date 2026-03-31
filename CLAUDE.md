# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
cover: "/media/products/yto-001/OEM-ODM-Customize-Luxury-Optical-Glasses-YTO-001-1.webp"
gallery:
  - "/media/products/yto-001/OEM-ODM-Customize-Luxury-Optical-Glasses-YTO-001-2.webp"
```

### Product Naming Convention

| Type | Prefix | Example |
|------|--------|---------|
| Optical Frames | YTO | YTO-001, YTO-002 |
| Sunglasses Frames | YTS | YTS-001, YTS-002 |

### URL Structure

| Page Type | Pattern | Example |
|-----------|---------|---------|
| Product List | `/product/` or `/{lang}/product/` | `/product/`, `/it/product/` |
| Product Detail | `/product/{slug}` or `/{lang}/product/{slug}` | `/product/yto-001/` |
| Category | `/product/{category}` | `/product/optical-frames/` |
| Landing Page | `/{slug}` or `/{lang}/{slug}` | `/about-us/`, `/it/about-us/` |

## Key Components

Located in `src/components/`:

- **Header.astro** - Navigation with language picker
- **Footer.astro** - Multi-column footer with translations
- **ProductCard.astro** - Product grid card
- **Search.astro** - Fuse.js search component
- **InquiryForm.astro** - Contact form with Turnstile
- **LanguagePicker.astro** - Language switcher
- **TechnicalSpecs.astro** - Product specifications display

## Pre-Build Validation

The `scripts/validate-content.mjs` script runs before build to check:
- UTF-8 BOM markers
- YAML frontmatter syntax
- Duplicate keys
- Required fields (title)
- Array formatting

Run manually: `npm run validate`

## Dynamic Search (Fuse.js)

Search automatically updates when products change:
- No build required - data fetched fresh on each request in dev
- Searches: title, itemNo, excerpt, categories, materials
- Weights: title (40%), itemNo (30%), excerpt (20%)
- Refresh page after adding products to see them in search

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

## Critical Rules

1. **Images MUST use** `/media/...` paths (not relative paths)
2. **Never hardcode** product data in layout files or translations
3. **Product-specific content** belongs in markdown files only
4. **All products need** 17 language versions for full coverage
5. **Run validate** before committing to catch YAML errors
