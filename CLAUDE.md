# Yeetian Astro Project - Claude.ai Instructions

## Project Overview

**Goal:** Create a high-performance B2B eyewear website (yeetian.com) using Astro framework, replacing WordPress with a static site generator approach.

**Business:** Yeetian Technology Co., Ltd. - Premium B2B eyewear manufacturing since 2003. Target audience: Global optical retailers, boutique eyewear shops, high-end fashion brands.

**Tech Stack:**
- **Astro 5.1+** - Static site generator for performance
- **Tailwind CSS 4+** - Utility-first styling with Logical Properties for RTL
- **Content Collections** - Product data using Markdown (.md) ONLY
- **Central Media Library** - Images in `public/media/` (Permanent SEO string URLs)
- **10 Languages** - EN, IT, ES, FR, DE, PT, RU, JA, TR, AR (with RTL support)
- **Vanilla JavaScript** - Lightweight interactivity
- **Fuse.js** - Dynamic fuzzy search (auto-updates when products change)
- **Hetzer + PHP** - Form backend for inquiries

---

## Dynamic Search (Auto-Updating)

The site uses **Fuse.js** for fuzzy search that automatically updates when you add new products:

### How it works:
1. **No build required** - Product data is fetched fresh on each page request in dev mode
2. **Fuzzy matching** - Typo-tolerant search (e.g., "opical" matches "optical")
3. **Instant results** - Searches title, item number, description, categories, and materials
4. **Auto-refresh** - Just refresh the page after adding a product to see it in search

### Search Fields (Weighted):
| Field | Weight | Description |
|-------|--------|-------------|
| `title` | 40% | Product title |
| `itemNo` | 30% | Product code (e.g., YETO-LC002) |
| `excerpt` | 20% | SEO description |
| `categories` | 5% | Product categories |
| `materials` | 5% | Frame materials |

### To see new products in search:
1. Add product markdown file to `src/content/products/`
2. **Refresh the page** (F5 or Ctrl+R)
3. New product appears instantly in search results

---

## Product Naming Convention

| Type | Prefix | Example | Description |
|------|--------|---------|-------------|
| **Optical Frames** | YTO | YTO-001, YTO-002 | Optical glasses frames |
| **Sunglasses Frames** | YTS | YTS-001, YTS-002 | Sunglasses frames |

---

## Architecture Overview

### Directory Structure

```
yeetian-site/
├── src/
│   ├── content/
│   │   ├── products/                 # Content ONLY (Text files)
│   │   │   ├── yto-001.en.md        # English (Optical)
│   │   │   ├── yto-001.it.md        # Italian
│   │   │   └── yto-001.[lang].md    # Other 8 languages
│   │   ├── landing/                 # SEO landing pages
│   │   └── blog/                    # Blog posts
│   ├── components/                   # Astro components
│   ├── layouts/
│   │   └── Layout.astro
│   ├── pages/
│   │   ├── index.astro               # Root (English)
│   │   ├── product/                  # Product pages (singular)
│   │   │   ├── [slug].astro          # /product/yto-001/
│   │   │   ├── [category].astro      # /product/optical-frames/
│   │   │   └── index.astro           # /product/
│   │   └── [lang]/                   # Dynamic language routes
│   │       ├── index.astro
│   │       └── product/
│   │           ├── [slug].astro      # /it/product/yto-001/
│   │           └── index.astro
│   └── translations/
│       └── index.ts                  # Central translation hub
├── public/
│   ├── media/                        # THE MEDIA LIBRARY (Centralized & Public)
│   │   ├── products/                 # Product images (Permanent SEO URLs)
│   │   │   ├── yto-001/
│   │   │   │   ├── OEM-ODM-...-1.webp # Shared by all 10 languages!
│   │   │   │   └── OEM-ODM-...-2.webp
│   │   ├── blog/                     # Blog covers
│   │   ├── pages/                    # Other landing page images
│   │   └── global/                   # Logos, icons, banners
│   └── llms.txt                      # AI discovery sitemap
├── astro.config.mjs
├── tailwind.config.js
└── package.json
```

---

## URL Structure

| Page Type | URL Pattern | Example |
|-----------|-------------|---------|
| Product Listing | `/product/` or `/{lang}/product/` | `/product/` `/it/product/` |
| Product Detail | `/product/{slug}` or `/{lang}/product/{slug}` | `/product/yto-001/` `/it/product/yto-001/` |
| Category Page | `/product/{category}` or `/{lang}/product/{category}` | `/product/optical-frames/` |

---

## Image Path Rules (CRITICAL)

### ✅ CORRECT (Stable Public SEO URLs)
All images (Products, Blogs, Landing Pages) MUST be stored directly in `public/media/...` with permanent, SEO-friendly names. Never duplicate images. Reference them dynamically via an absolute path:
```yaml
cover: "/media/products/yto-001/OEM-ODM-Customize-Luxury-Optical-Glasses-YTO-001-1.webp"
gallery:
  - "/media/products/yto-001/OEM-ODM-Customize-Luxury-Optical-Glasses-YTO-001-2.webp"
```
Because they live directly in `/public/`, Astro serves them exactly at this path without hashing. This ensures Pagefind Search and all localized pages can use the EXACT same stable URL.

### ❌ WRONG (Causes missing dev images + broken SEO)
```yaml
cover: "../../assets/media/products/yto-001/main.webp" # Astro image() helper creates hashes
cover: "src/assets/media/..."  # Breaks completely in prod
```

---

## Content Workflow (CLONE & EDIT)

### Adding a NEW Optical Product (YTO-002)

1. **Images:** Drag photos into `public/media/products/yto-002/` and rename them with rich SEO keywords. Example:
   - `OEM-ODM-Customize-Metal-Optical-Glasses-YTO-002-1.webp` - Primary image
   - `OEM-ODM-Customize-Metal-Optical-Glasses-YTO-002-2.webp` - Side view

2. **Text:** Go to `src/content/products/` and **COPY** files `yto-001.*.md`
   - Rename copies to `yto-002.en.md`, `yto-002.it.md`, etc.

3. **Edit English content:**
   ```yaml
   ---
   title: "Customize Metal Optical Glasses Frames"
   slug: "yto-002"
   itemNo: "YTO-002"
   cover: "/media/products/yto-002/OEM-ODM-Customize-Metal-Optical-Glasses-YTO-002-1.webp"
   gallery:
     - "/media/products/yto-002/OEM-ODM-Customize-Metal-Optical-Glasses-YTO-002-2.webp"
   materials: ["Metal", "Titanium"]
   colors: ["Black", "Gold"]
   moq: 300
   categories: ["Optical Frames", "Metal"]
   ---
   ```

### Adding a NEW Sunglasses Product (YTS-001)

Same process, but use `yts-001` prefix and categorize as "Sunglasses":
   ```yaml
   itemNo: "YTS-001"
   categories: ["Sunglasses", "Fashion"]
   ```

---

## Build & Development

### Development Server
```bash
cd yeetian-site
npm run dev
# Opens at http://localhost:4321
```

### Production Build
```bash
cd yeetian-site
npm run build
# Output: dist/ folder
```

---

## Current Status (Updated: 2026-02-26)

### ✅ COMPLETED
- Core Configuration (tsconfig, astro.config, tailwind)
- Translation System (all 10 languages)
- Layout & Components (Header, Footer, ProductCard, etc.)
- Sample Product Content (YTO-001 in all 10 languages)
- Dynamic Routes (product pages)
- Build System Working (82 pages generated)
- Product URLs working: /product/yto-001/, /it/product/yto-001/, /ar/product/yto-001/
- **Contact Form** with AJAX submission
- **SMTP Email** (info@yeetian.com)
- **Cloudflare Turnstile** anti-spam protection
- **Email Logging** system (like WordPress SMTP plugins)
- **GitHub Repository** https://github.com/yeetiancom/yeetian-site
- **Auto-Deploy** via GitHub Actions to Hetzner server
- **Live Website** https://yeetian.com

### Deployment Info
- **Server:** Hetzner VPS (5.78.127.6)
- **Panel:** CyberPanel
- **Path:** /home/yeetian.com/public_html
- **GitHub:** https://github.com/yeetiancom/yeetian-site
- **Auto-Deploy:** Push to main branch → GitHub Actions → yeetian.com

### How to Update Website
```bash
cd "D:\003 Desk\Desk\Tools\Yeetian\yeetian-site"
git add .
git commit -m "Your description"
git push
# Wait 2-3 minutes → yeetian.com updates automatically
```
Or double-click: `update.bat`

### Important URLs
| Purpose | URL |
|---------|-----|
| Website | https://yeetian.com |
| PHP Status | https://yeetian.com/php-status.php |
| Email Logs | https://yeetian.com/email-logs.php (Password: yeetian2024) |
| GitHub Actions | https://github.com/yeetiancom/yeetian-site/actions |

### Next Steps
- Add more products (YTO-002, YTS-001, etc.)
- Create Gumlet video component
- Change email-logs.php password for security
