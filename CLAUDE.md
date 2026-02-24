# KSSMI Astro Project - Claude.ai Instructions

## Project Overview

**Goal:** Create a high-performance B2B eyewear website (kssmi.com) using Astro framework, replacing WordPress with a static site generator approach.

**Business:** KSSMI Technology Co., Ltd. - Premium B2B eyewear manufacturing since 2003. Target audience: Global optical retailers, boutique eyewear shops, high-end fashion brands.

**Tech Stack:**
- **Astro 5.1+** - Static site generator for performance
- **Tailwind CSS 4+** - Utility-first styling with Logical Properties for RTL
- **Content Collections** - Product data using Markdown (.md) ONLY
- **Central Media Library** - Images in `public/media/` (Permanent SEO string URLs)
- **10 Languages** - EN, IT, ES, FR, DE, PT, RU, JA, TR, AR (with RTL support)
- **Vanilla JavaScript** - Lightweight interactivity
- **Pagefind** - Static search library
- **Hetzer + PHP** - Form backend for inquiries

---

## Product Naming Convention

| Type | Prefix | Example | Description |
|------|--------|---------|-------------|
| **Optical Frames** | KSO | KSO-001, KSO-002 | Optical glasses frames |
| **Sunglasses Frames** | KSS | KSS-001, KSS-002 | Sunglasses frames |

---

## Architecture Overview

### Directory Structure

```
kssmi-site/
├── src/
│   ├── content/
│   │   ├── products/                 # Content ONLY (Text files)
│   │   │   ├── kso-001.en.md        # English (Optical)
│   │   │   ├── kso-001.it.md        # Italian
│   │   │   └── kso-001.[lang].md    # Other 8 languages
│   │   ├── landing/                 # SEO landing pages
│   │   └── blog/                    # Blog posts
│   ├── components/                   # Astro components
│   ├── layouts/
│   │   └── Layout.astro
│   ├── pages/
│   │   ├── index.astro               # Root (English)
│   │   ├── product/                  # Product pages (singular)
│   │   │   ├── [slug].astro          # /product/kso-001/
│   │   │   ├── [category].astro      # /product/optical-frames/
│   │   │   └── index.astro           # /product/
│   │   └── [lang]/                   # Dynamic language routes
│   │       ├── index.astro
│   │       └── product/
│   │           ├── [slug].astro      # /it/product/kso-001/
│   │           └── index.astro
│   └── translations/
│       └── index.ts                  # Central translation hub
├── public/
│   ├── media/                        # THE MEDIA LIBRARY (Centralized & Public)
│   │   ├── products/                 # Product images (Permanent SEO URLs)
│   │   │   ├── kso-001/
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
| Product Detail | `/product/{slug}` or `/{lang}/product/{slug}` | `/product/kso-001/` `/it/product/kso-001/` |
| Category Page | `/product/{category}` or `/{lang}/product/{category}` | `/product/optical-frames/` |

---

## Image Path Rules (CRITICAL)

### ✅ CORRECT (Stable Public SEO URLs)
All images (Products, Blogs, Landing Pages) MUST be stored directly in `public/media/...` with permanent, SEO-friendly names. Never duplicate images. Reference them dynamically via an absolute path:
```yaml
cover: "/media/products/kso-001/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-001-1.webp"
gallery:
  - "/media/products/kso-001/OEM-ODM-Customize-Luxury-Optical-Glasses-KSO-001-2.webp"
```
Because they live directly in `/public/`, Astro serves them exactly at this path without hashing. This ensures Pagefind Search and all localized pages can use the EXACT same stable URL.

### ❌ WRONG (Causes missing dev images + broken SEO)
```yaml
cover: "../../assets/media/products/kso-001/main.webp" # Astro image() helper creates hashes
cover: "src/assets/media/..."  # Breaks completely in prod
```

---

## Content Workflow (CLONE & EDIT)

### Adding a NEW Optical Product (KSO-002)

1. **Images:** Drag photos into `public/media/products/kso-002/` and rename them with rich SEO keywords. Example:
   - `OEM-ODM-Customize-Metal-Optical-Glasses-KSO-002-1.webp` - Primary image
   - `OEM-ODM-Customize-Metal-Optical-Glasses-KSO-002-2.webp` - Side view

2. **Text:** Go to `src/content/products/` and **COPY** files `kso-001.*.md`
   - Rename copies to `kso-002.en.md`, `kso-002.it.md`, etc.

3. **Edit English content:**
   ```yaml
   ---
   title: "Customize Metal Optical Glasses Frames"
   slug: "kso-002"
   itemNo: "KSO-002"
   cover: "/media/products/kso-002/OEM-ODM-Customize-Metal-Optical-Glasses-KSO-002-1.webp"
   gallery:
     - "/media/products/kso-002/OEM-ODM-Customize-Metal-Optical-Glasses-KSO-002-2.webp"
   materials: ["Metal", "Titanium"]
   colors: ["Black", "Gold"]
   moq: 300
   categories: ["Optical Frames", "Metal"]
   ---
   ```

### Adding a NEW Sunglasses Product (KSS-001)

Same process, but use `kss-001` prefix and categorize as "Sunglasses":
   ```yaml
   itemNo: "KSS-001"
   categories: ["Sunglasses", "Fashion"]
   ```

---

## Build & Development

### Development Server
```bash
cd kssmi-site
npm run dev
# Opens at http://localhost:4321
```

### Production Build
```bash
cd kssmi-site
npm run build
# Output: dist/ folder
```

---

## Current Status (Updated: 2026-02-22)

### ✅ COMPLETED
- Core Configuration (tsconfig, astro.config, tailwind)
- Translation System (all 10 languages)
- Layout & Components (Header, Footer, ProductCard, etc.)
- Sample Product Content (KSO-001 in all 10 languages)
- Dynamic Routes (product pages)
- Build System Working (82 pages generated)
- Product URLs working: /product/kso-001/, /it/product/kso-001/, /ar/product/kso-001/
- **Contact Form** with AJAX submission
- **SMTP Email** via Gmail Workspace (kssmi@kssmi.com)
- **Cloudflare Turnstile** anti-spam protection
- **Email Logging** system (like WordPress SMTP plugins)
- **GitHub Repository** https://github.com/kssmicom/kssmi-site
- **Auto-Deploy** via GitHub Actions to Hetzner server
- **Live Website** https://kssmi.com

### Deployment Info
- **Server:** Hetzner VPS (5.78.127.6)
- **Panel:** CyberPanel
- **Path:** /home/kssmi.com/public_html
- **GitHub:** https://github.com/kssmicom/kssmi-site
- **Auto-Deploy:** Push to main branch → GitHub Actions → kssmi.com

### How to Update Website
```bash
cd "D:\001 Tools\004 Desk\Desk\Tools\Kssmi\kssmi-site"
git add .
git commit -m "Your description"
git push
# Wait 2-3 minutes → kssmi.com updates automatically
```
Or double-click: `update.bat`

### Important URLs
| Purpose | URL |
|---------|-----|
| Website | https://kssmi.com |
| PHP Status | https://kssmi.com/php-status.php |
| Email Logs | https://kssmi.com/email-logs.php (Password: kssmi2024) |
| GitHub Actions | https://github.com/kssmicom/kssmi-site/actions |

### Next Steps
- Add more products (KSO-002, KSS-001, etc.)
- Create Gumlet video component
- Change email-logs.php password for security
