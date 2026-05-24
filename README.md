# Kssmi Eyewear — B2B Manufacturing Website

High-performance static website for a B2B eyewear manufacturer serving global optical retailers, boutique shops, and fashion brands. Built with Astro 5.x, 17 languages, 7000+ pages.

**Live:** [kssmi.com](https://kssmi.com)

## Tech Stack

- **Astro 5.x** — Static site generation with content collections
- **Tailwind CSS 3.4** — Utility-first styling
- **TypeScript** — Full type safety
- **Fuse.js + Pagefind** — Client-side + static search
- **17 Languages** — EN, IT, ES, FR, DE, PT, RU, JA, TR, AR, KO, ZH, HI, VI, JV, MS, TG
- **Cloudflare Turnstile** — Anti-spam for forms

## Quick Start

```bash
npm install
npm run dev        # http://localhost:4321
npm run build      # Production build → dist/
npm run preview    # Preview production build
npm run validate   # Check markdown frontmatter
```

## Project Structure

```
src/
├── content/
│   ├── products/         # Product markdown (17 languages per SKU)
│   ├── collection/       # Landing pages (about, contact, home)
│   └── blog/             # Blog posts
├── components/           # Astro components
├── pages/                # Route definitions
│   ├── product/          # English product routes
│   ├── blog/             # English blog routes
│   ├── eyewear/          # English feature/eyewear routes
│   └── [lang]/           # Multilingual routes
├── layouts/              # Page layouts
├── translations/         # 17-language translation hub
├── utils/                # Shared utilities
└── styles/               # Global CSS
public/
├── media/
│   ├── products/         # Product images by SKU
│   ├── certifications/   # ISO, FDA, CE badges
│   └── global/           # Logos, icons
└── .htaccess             # CSP, cache, security headers
scripts/                  # Build, validation, image optimization
```

## URL Structure

| Page | Pattern |
|------|---------|
| Home | `/` or `/{lang}/` |
| Product Listing | `/product/` or `/{lang}/product/` |
| Product Detail | `/product/{slug}` |
| Category | `/product/{category}` |
| Blog | `/blog/` |
| Feature/Eyewear | `/eyewear/` |
| Landing Pages | `/about-us/`, `/contact/`, `/quote/` |
| Search | `/search` |

## Deployment

Push to `main` → GitHub Actions → Hetzner VPS (OpenLiteSpeed / CyberPanel)

## License

Private — all rights reserved.
