# Kssmi Eyewear — B2B Manufacturing Website

Multilingual website for a B2B eyewear manufacturer serving optical retailers, boutique shops and eyewear brands. Astro generates the static frontend; PHP handles inquiries, short links and analytics.

**Live:** [kssmi.com](https://kssmi.com)

## Tech Stack

- **Astro 7** — Static site generation with content loaders
- **Tailwind CSS 4** — Utility-first styling through Vite
- **TypeScript** — Full type safety
- **Fuse.js + Pagefind** — Client-side + static search
- **17 Languages** — EN, IT, ES, FR, DE, PT, RU, JA, TR, AR, KO, ZH, HI, VI, JV, MS, TG
- **Cloudflare Turnstile** — Anti-spam for forms

## Quick Start

On Windows, use `Local-Start.vbs` for local preview, `Local-Stop.bat` to stop
that preview and `Local-Start-Fresh.bat` to archive known untracked caches
and restart. Astro serves and watches the original project; standard caches
stay there. Auxiliary logs and control files stay under `../tep/`.
`start-servers.bat` also starts PHP on loopback port 4325 when its dependencies
are available. The frontend uses port 4321.

Use `Build-Site.bat` to build the original project into `dist/`.
`Cleanup-Logs.bat` reviews known logs and completed temporary tasks before
moving selected items to the Recycle Bin; it preserves normal build/cache
directories. `push-main.bat` reviews selected files before committing and
pushing main, which triggers production deployment; it does not itself
run tests or confirm deployment success.

Read `Agent.md` for workspace rules. Standard outputs stay in the project;
only genuine one-off materials and auxiliary logs go under `../tep/`.
Exact dependency requirements are in `package.json` (Node.js 24+).

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
