#!/usr/bin/env node
/**
 * Read-only post-build validator for all multilingual product pages in dist/.
 *
 * Reconciles every product entry in src/content/products/**\/*.md against its
 * built HTML page (<dist>/product/<slug>/index.html or
 * <dist>/<lang>/product/<slug>/index.html), verifying:
 *   1. Complete multilingual route coverage without unexpected orphan product
 *      pages or redirect stubs masking product URLs.
 *   2. Exact KS ProductGallery DOM structure (`data-product-gallery`,
 *      `data-gallery-main-image`, `data-gallery-dialog-image`,
 *      `data-gallery-index`, `data-full`) parsed independently of attribute
 *      order or quoting style.
 *   3. Exact image set & sequence match with Markdown `[cover, ...gallery]`,
 *      legal empty-media handling, no duplicates, and no direct derived URLs.
 *   4. Every rendered full image and `-240w.webp` thumbnail asset exists as a
 *      regular file under `dist/media/` with exact disk filename casing.
 */

import { existsSync } from 'node:fs';
import { lstat, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import yaml from 'js-yaml';
import { DERIVED_NAME_RE, POSTER_NAME_RE } from '../src/config/image-variants.mjs';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DEFAULT_DIST_DIR = resolve(__dirname, '../dist');
const DEFAULT_PRODUCTS_CONTENT_DIR = resolve(__dirname, '../src/content/products');
const DEFAULT_CONCURRENCY = 16;

const VOID_HTML_TAGS = new Set([
  'area',
  'base',
  'br',
  'col',
  'embed',
  'hr',
  'img',
  'input',
  'link',
  'meta',
  'param',
  'source',
  'track',
  'wbr',
]);

function normalizeSlashes(p) {
  return p.replace(/\\/g, '/');
}

function decodeHtmlEntities(str) {
  return str.replace(
    /&(?:#x([0-9a-fA-F]+)|#(\d+)|(amp|quot|apos|lt|gt|nbsp));/gi,
    (full, hex, dec, named) => {
      if (hex) {
        const cp = Number.parseInt(hex, 16);
        return Number.isFinite(cp) && cp <= 0x10ffff ? String.fromCodePoint(cp) : full;
      }
      if (dec) {
        const cp = Number.parseInt(dec, 10);
        return Number.isFinite(cp) && cp <= 0x10ffff ? String.fromCodePoint(cp) : full;
      }
      switch (named.toLowerCase()) {
        case 'amp':
          return '&';
        case 'quot':
          return '"';
        case 'apos':
          return "'";
        case 'lt':
          return '<';
        case 'gt':
          return '>';
        case 'nbsp':
          return '\u00a0';
        default:
          return full;
      }
    }
  );
}

/**
 * Parses an HTML attribute list regardless of attribute order, quote style
 * ("...", '...', unquoted, boolean flags), or extra whitespace.
 */
function parseTagAttributes(rawAttrs) {
  const attrs = Object.create(null);
  const len = rawAttrs.length;
  let i = 0;

  while (i < len) {
    while (i < len && /\s/.test(rawAttrs[i])) i++;
    if (i >= len || rawAttrs[i] === '/') break;

    const nameStart = i;
    while (i < len && !/[\s=/>"']/.test(rawAttrs[i])) i++;
    const rawName = rawAttrs.slice(nameStart, i);
    if (!rawName) {
      i++;
      continue;
    }
    const name = rawName.toLowerCase();

    while (i < len && /\s/.test(rawAttrs[i])) i++;
    if (i >= len || rawAttrs[i] !== '=') {
      attrs[name] = '';
      continue;
    }

    i++; // consume '='
    while (i < len && /\s/.test(rawAttrs[i])) i++;

    let value = '';
    if (i < len && (rawAttrs[i] === '"' || rawAttrs[i] === "'")) {
      const quote = rawAttrs[i++];
      const valStart = i;
      while (i < len && rawAttrs[i] !== quote) i++;
      value = decodeHtmlEntities(rawAttrs.slice(valStart, i));
      if (i < len && rawAttrs[i] === quote) i++;
    } else {
      const valStart = i;
      while (i < len && !/[\s>]/.test(rawAttrs[i])) i++;
      let token = rawAttrs.slice(valStart, i);
      if (token.endsWith('/')) token = token.slice(0, -1);
      value = decodeHtmlEntities(token);
    }
    attrs[name] = value;
  }

  return attrs;
}

/**
 * Deterministic tokenizer for extracting KS ProductGallery DOM elements from HTML.
 * Ignores comments, <script>, and <style> contents and captures elements inside
 * `[data-product-gallery]` by tracking balanced nested <div>/<\/div> tags.
 */
export function extractProductGallery(html) {
  const len = html.length;
  let i = 0;

  let galleryCount = 0;
  let divDepth = 0;
  let mainImages = [];
  let dialogImages = [];
  let thumbs = [];
  let activeButton = null;

  function findRawTagEnd(tagName, fromIndex) {
    const closePattern = new RegExp(`</${tagName}\\s*>`, 'i');
    const slice = html.slice(fromIndex);
    const match = closePattern.exec(slice);
    return match ? fromIndex + match.index + match[0].length : len;
  }

  function scanTagClose(startIndex) {
    let k = startIndex;
    let quote = null;
    while (k < len) {
      const ch = html[k];
      if (quote) {
        if (ch === quote) quote = null;
      } else if (ch === '"' || ch === "'") {
        quote = ch;
      } else if (ch === '>') {
        return k;
      }
      k++;
    }
    return -1;
  }

  while (i < len) {
    const lt = html.indexOf('<', i);
    if (lt === -1) break;

    if (html.startsWith('<!--', lt)) {
      const endComment = html.indexOf('-->', lt + 4);
      i = endComment === -1 ? len : endComment + 3;
      continue;
    }

    const nextChar = html[lt + 1] || '';
    if (nextChar === '!' || nextChar === '?') {
      const endDecl = scanTagClose(lt + 2);
      i = endDecl === -1 ? len : endDecl + 1;
      continue;
    }

    if (nextChar === '/') {
      const endClose = scanTagClose(lt + 2);
      if (endClose === -1) break;
      const closeName = html
        .slice(lt + 2, endClose)
        .trim()
        .split(/\s+/)[0]
        .toLowerCase();
      if (divDepth > 0) {
        if (closeName === 'button' && activeButton) {
          thumbs.push(activeButton);
          activeButton = null;
        } else if (closeName === 'div') {
          divDepth--;
          if (divDepth === 0 && activeButton) {
            thumbs.push(activeButton);
            activeButton = null;
          }
        }
      }
      i = endClose + 1;
      continue;
    }

    if (!/[A-Za-z]/.test(nextChar)) {
      i = lt + 1;
      continue;
    }

    const endOpen = scanTagClose(lt + 1);
    if (endOpen === -1) break;

    const rawTagInner = html.slice(lt + 1, endOpen);
    const nameMatch = rawTagInner.match(/^([A-Za-z][A-Za-z0-9:-]*)/);
    if (!nameMatch) {
      i = endOpen + 1;
      continue;
    }

    const tagName = nameMatch[1].toLowerCase();
    const trimmedInner = rawTagInner.trimEnd();
    const selfClosing = trimmedInner.endsWith('/') || VOID_HTML_TAGS.has(tagName);
    const rawAttrs = trimmedInner
      .slice(nameMatch[1].length, trimmedInner.endsWith('/') ? -1 : undefined);
    const attrs = parseTagAttributes(rawAttrs);

    i = endOpen + 1;

    if (!selfClosing && (tagName === 'script' || tagName === 'style')) {
      i = findRawTagEnd(tagName, i);
      continue;
    }

    if (tagName === 'div') {
      if (Object.hasOwn(attrs, 'data-product-gallery')) {
        galleryCount++;
        if (galleryCount === 1 && !selfClosing) {
          divDepth = 1;
        } else if (divDepth > 0 && !selfClosing) {
          divDepth++;
        }
        continue;
      }
      if (divDepth > 0 && !selfClosing) {
        divDepth++;
      }
      continue;
    }

    if (divDepth <= 0) continue;

    if (tagName === 'img') {
      const src = Object.hasOwn(attrs, 'src') ? attrs.src : null;
      if (Object.hasOwn(attrs, 'data-gallery-main-image')) {
        mainImages.push({ src });
      }
      if (Object.hasOwn(attrs, 'data-gallery-dialog-image')) {
        dialogImages.push({ src });
      }
      if (activeButton) {
        activeButton.imgs.push({ src });
      }
    } else if (tagName === 'button') {
      if (Object.hasOwn(attrs, 'data-gallery-index') || Object.hasOwn(attrs, 'data-full')) {
        if (activeButton) {
          thumbs.push(activeButton);
        }
        const btn = {
          hasIndex: Object.hasOwn(attrs, 'data-gallery-index'),
          indexAttr: Object.hasOwn(attrs, 'data-gallery-index') ? attrs['data-gallery-index'] : null,
          hasFull: Object.hasOwn(attrs, 'data-full'),
          fullSrc: Object.hasOwn(attrs, 'data-full') ? attrs['data-full'] : null,
          imgs: [],
        };
        if (selfClosing) {
          thumbs.push(btn);
        } else {
          activeButton = btn;
        }
      }
    }
  }

  if (activeButton) {
    thumbs.push(activeButton);
  }

  return {
    galleryCount,
    mainImages,
    dialogImages,
    thumbs,
  };
}

function getDerivedThumbUrl(imagePath) {
  if (!imagePath || !/\.(webp|jpe?g|png)$/i.test(imagePath)) return null;
  if (DERIVED_NAME_RE.test(imagePath) || POSTER_NAME_RE.test(imagePath)) return null;
  return imagePath.replace(/\.(webp|jpe?g|png)$/i, '-240w.webp');
}

function isRedirectStub(html) {
  return !/<html\b/i.test(html) && /<meta\s+[^>]*http-equiv=["']?refresh["']?/i.test(html);
}

async function mapWithConcurrency(items, concurrency, worker) {
  if (items.length === 0) return;
  const limit = Math.max(1, Math.min(concurrency, items.length));
  let idx = 0;
  async function runner() {
    while (true) {
      const current = idx++;
      if (current >= items.length) break;
      await worker(items[current], current);
    }
  }
  await Promise.all(Array.from({ length: limit }, () => runner()));
}

async function indexDistMedia(distDir) {
  const issues = [];
  const exactSet = new Set();
  const lowerMap = new Map();
  const mediaRoot = join(distDir, 'media');

  let rootStat;
  try {
    rootStat = await lstat(mediaRoot);
  } catch (err) {
    if (err.code === 'ENOENT') {
      return { issues, exactSet, lowerMap };
    }
    issues.push({
      code: 'MEDIA_DIR_ERROR',
      page: 'media',
      slug: '',
      lang: '',
      message: `Cannot access built media directory ${mediaRoot}: ${err.message}`,
    });
    return { issues, exactSet, lowerMap };
  }

  if (rootStat.isSymbolicLink() || !rootStat.isDirectory()) {
    issues.push({
      code: rootStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'MEDIA_DIR_ERROR',
      page: 'media',
      slug: '',
      lang: '',
      message: `Built media root must be a regular non-symlink directory: ${mediaRoot}`,
    });
    return { issues, exactSet, lowerMap };
  }

  async function walk(dir) {
    let entries;
    try {
      entries = await readdir(dir, { withFileTypes: true });
    } catch (err) {
      issues.push({
        code: 'MEDIA_DIR_ERROR',
        page: normalizeSlashes(relative(distDir, dir)),
        slug: '',
        lang: '',
        message: `Failed reading built media directory ${dir}: ${err.message}`,
      });
      return;
    }

    entries.sort((a, b) => a.name.localeCompare(b.name));
    for (const dirent of entries) {
      const full = join(dir, dirent.name);
      let st;
      try {
        st = await lstat(full);
      } catch (err) {
        issues.push({
          code: 'MEDIA_DIR_ERROR',
          page: normalizeSlashes(relative(distDir, full)),
          slug: '',
          lang: '',
          message: `Failed inspecting ${full}: ${err.message}`,
        });
        continue;
      }

      if (st.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          page: normalizeSlashes(relative(distDir, full)),
          slug: '',
          lang: '',
          message: `Symlink is not allowed in built media output: ${full}`,
        });
        continue;
      }

      if (st.isDirectory()) {
        await walk(full);
      } else if (st.isFile()) {
        const url = `/${normalizeSlashes(relative(distDir, full))}`;
        exactSet.add(url);
        const lk = url.toLowerCase();
        if (!lowerMap.has(lk)) lowerMap.set(lk, []);
        lowerMap.get(lk).push(url);
      }
    }
  }

  await walk(mediaRoot);
  return { issues, exactSet, lowerMap };
}

async function loadExpectedProductRoutes(productsContentDir) {
  const issues = [];
  const entries = [];
  const routeMap = new Map();
  const languages = new Set();
  const slugs = new Set();

  let rootStat;
  try {
    rootStat = await lstat(productsContentDir);
  } catch (err) {
    issues.push({
      code: 'CONTENT_DIR_ERROR',
      page: productsContentDir,
      slug: '',
      lang: '',
      message: `Cannot inspect products content directory ${productsContentDir}: ${err.message}`,
    });
    return { issues, entries, routeMap, languages, slugs };
  }

  if (rootStat.isSymbolicLink() || !rootStat.isDirectory()) {
    issues.push({
      code: rootStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'CONTENT_DIR_ERROR',
      page: productsContentDir,
      slug: '',
      lang: '',
      message: `Products content path must be a regular non-symlink directory: ${productsContentDir}`,
    });
    return { issues, entries, routeMap, languages, slugs };
  }

  const mdFiles = [];
  async function walk(dir) {
    const dirEntries = await readdir(dir, { withFileTypes: true });
    dirEntries.sort((a, b) => a.name.localeCompare(b.name));
    for (const dirent of dirEntries) {
      const full = join(dir, dirent.name);
      const st = await lstat(full);
      if (st.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          page: full,
          slug: '',
          lang: '',
          message: `Symlink is not allowed under products content: ${full}`,
        });
        continue;
      }
      if (st.isDirectory()) {
        await walk(full);
      } else if (st.isFile() && dirent.name.endsWith('.md')) {
        mdFiles.push(full);
      }
    }
  }

  await walk(productsContentDir);
  if (mdFiles.length === 0) {
    issues.push({
      code: 'EMPTY_PRODUCTS_CONTENT',
      page: productsContentDir,
      slug: '',
      lang: '',
      message: `No product Markdown files found under ${productsContentDir}`,
    });
    return { issues, entries, routeMap, languages, slugs };
  }

  for (const mdFile of mdFiles) {
    const relFromProducts = normalizeSlashes(relative(productsContentDir, mdFile));
    const entryId = relFromProducts.replace(/\.md$/, '');
    const idParts = entryId.split('.');
    const lang = idParts.length > 1 ? idParts.pop() : 'en';

    let raw;
    try {
      raw = await readFile(mdFile, 'utf8');
    } catch (err) {
      issues.push({
        code: 'CONTENT_READ_ERROR',
        page: relFromProducts,
        slug: '',
        lang,
        message: `Failed reading ${relFromProducts}: ${err.message}`,
      });
      continue;
    }

    const match = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/);
    if (!match) {
      issues.push({
        code: 'CONTENT_PARSE_ERROR',
        page: relFromProducts,
        slug: '',
        lang,
        message: `Missing YAML frontmatter in ${relFromProducts}`,
      });
      continue;
    }

    let frontmatter;
    try {
      frontmatter = yaml.load(match[1]);
    } catch (err) {
      issues.push({
        code: 'CONTENT_PARSE_ERROR',
        page: relFromProducts,
        slug: '',
        lang,
        message: `Invalid YAML frontmatter in ${relFromProducts}: ${err.message}`,
      });
      continue;
    }

    if (!frontmatter || typeof frontmatter !== 'object' || Array.isArray(frontmatter)) {
      issues.push({
        code: 'CONTENT_PARSE_ERROR',
        page: relFromProducts,
        slug: '',
        lang,
        message: `Frontmatter in ${relFromProducts} must be an object`,
      });
      continue;
    }

    const rawSlug = typeof frontmatter.slug === 'string' ? frontmatter.slug.trim() : '';
    const slug = rawSlug.toLowerCase();
    if (!slug) {
      issues.push({
        code: 'MISSING_SLUG',
        page: relFromProducts,
        slug: '',
        lang,
        message: `Product Markdown ${relFromProducts} is missing required slug`,
      });
      continue;
    }

    const pageRel = lang === 'en' ? `product/${slug}/index.html` : `${lang}/product/${slug}/index.html`;
    if (routeMap.has(pageRel)) {
      const prior = routeMap.get(pageRel);
      issues.push({
        code: 'DUPLICATE_PRODUCT_ROUTE',
        page: pageRel,
        slug,
        lang,
        message: `Duplicate product route /${pageRel.replace(/index\.html$/, '')} from ${prior.relFromProducts} and ${relFromProducts}`,
      });
      continue;
    }

    const mdImages = [];
    if (typeof frontmatter.cover === 'string' && frontmatter.cover.trim()) {
      mdImages.push(frontmatter.cover.trim());
    }
    if (Array.isArray(frontmatter.gallery)) {
      for (const item of frontmatter.gallery) {
        if (typeof item === 'string' && item.trim()) {
          mdImages.push(item.trim());
        }
      }
    }

    languages.add(lang);
    slugs.add(slug);

    const record = {
      mdFile,
      relFromProducts,
      lang,
      slug,
      pageRel,
      mdImages,
    };
    routeMap.set(pageRel, record);
    entries.push(record);
  }

  return { issues, entries, routeMap, languages, slugs };
}

async function scanNonProductRoutesAndOrphans(distDir, routeMap) {
  const issues = [];
  const excluded = {
    catalogIndexPages: 0,
    categoryListingPages: 0,
    paginationPages: 0,
    redirectStubs: 0,
  };

  const productRoots = [];
  const rootProductDir = join(distDir, 'product');
  if (existsSync(rootProductDir)) {
    productRoots.push({ prefix: '', dir: rootProductDir });
  }

  const distEntries = await readdir(distDir, { withFileTypes: true });
  distEntries.sort((a, b) => a.name.localeCompare(b.name));
  for (const dirent of distEntries) {
    if (!dirent.isDirectory()) continue;
    if (dirent.name === 'product' || dirent.name === 'media' || dirent.name === '_astro') continue;
    const langProductDir = join(distDir, dirent.name, 'product');
    if (existsSync(langProductDir)) {
      productRoots.push({ prefix: `${dirent.name}/`, dir: langProductDir });
    }
  }

  for (const { prefix, dir } of productRoots) {
    const dirStat = await lstat(dir);
    if (dirStat.isSymbolicLink() || !dirStat.isDirectory()) {
      issues.push({
        code: 'INVALID_DIST_DIR',
        page: `${prefix}product`,
        slug: '',
        lang: prefix ? prefix.slice(0, -1) : 'en',
        message: `Built product directory must be a non-symlink directory: ${dir}`,
      });
      continue;
    }

    const indexHtml = join(dir, 'index.html');
    if (existsSync(indexHtml)) {
      excluded.catalogIndexPages++;
    }

    const subEntries = await readdir(dir, { withFileTypes: true });
    subEntries.sort((a, b) => a.name.localeCompare(b.name));
    for (const sub of subEntries) {
      if (!sub.isDirectory()) continue;
      const pageRel = `${prefix}product/${sub.name}/index.html`;
      if (routeMap.has(pageRel)) continue;

      const htmlPath = join(dir, sub.name, 'index.html');
      if (!existsSync(htmlPath)) continue;

      const htmlStat = await lstat(htmlPath);
      if (htmlStat.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          page: pageRel,
          slug: sub.name,
          lang: prefix ? prefix.slice(0, -1) : 'en',
          message: `Symlink HTML file is not allowed under dist: ${htmlPath}`,
        });
        continue;
      }
      if (!htmlStat.isFile()) continue;

      const html = await readFile(htmlPath, 'utf8');
      if (isRedirectStub(html)) {
        excluded.redirectStubs++;
      } else if (html.includes('data-product-gallery')) {
        issues.push({
          code: 'UNEXPECTED_ORPHAN_PRODUCT_PAGE',
          page: pageRel,
          slug: sub.name,
          lang: prefix ? prefix.slice(0, -1) : 'en',
          message: `Built product detail page ${pageRel} has [data-product-gallery] but no matching Markdown entry`,
        });
      } else if (/^\d+$/.test(sub.name)) {
        excluded.paginationPages++;
      } else {
        excluded.categoryListingPages++;
      }
    }
  }

  return { issues, excluded };
}

function verifyAssetInDist(url, label, record, distMediaExact, distMediaLower, issues) {
  if (!url || typeof url !== 'string') {
    issues.push({
      code: 'INVALID_ASSET_URL',
      page: record.pageRel,
      slug: record.slug,
      lang: record.lang,
      message: `${label} is missing an image src URL`,
    });
    return false;
  }

  if (!url.startsWith('/media/') || url.includes('..') || url.includes('?') || url.includes('#')) {
    issues.push({
      code: 'INVALID_ASSET_URL',
      page: record.pageRel,
      slug: record.slug,
      lang: record.lang,
      message: `${label} uses unsupported non-/media/ URL '${url}'`,
    });
    return false;
  }

  if (distMediaExact.has(url)) {
    return true;
  }

  const lowerMatches = distMediaLower.get(url.toLowerCase());
  if (lowerMatches && lowerMatches.length > 0) {
    issues.push({
      code: 'ASSET_CASE_MISMATCH',
      page: record.pageRel,
      slug: record.slug,
      lang: record.lang,
      message: `${label} case mismatch: HTML references '${url}', dist has '${lowerMatches[0]}'`,
    });
    return false;
  }

  issues.push({
    code: 'ASSET_MISSING_IN_DIST',
    page: record.pageRel,
    slug: record.slug,
    lang: record.lang,
    message: `${label} references missing asset '${url}' (not found in dist/media)`,
  });
  return false;
}

function summarizeIssuesByCode(issues) {
  const counts = {};
  const samples = {};
  for (const issue of issues) {
    counts[issue.code] = (counts[issue.code] || 0) + 1;
    if (!samples[issue.code]) samples[issue.code] = [];
    if (samples[issue.code].length < 25) {
      samples[issue.code].push(issue);
    }
  }
  return { counts, samples };
}

export async function validateProductRenderedMedia({
  distDir = DEFAULT_DIST_DIR,
  productsContentDir = DEFAULT_PRODUCTS_CONTENT_DIR,
  reportOut = null,
  concurrency = DEFAULT_CONCURRENCY,
  quiet = false,
  verbose = false,
} = {}) {
  const resolvedDistDir = resolve(distDir);
  const resolvedContentDir = resolve(productsContentDir);
  const issues = [];

  let distStat;
  try {
    distStat = await lstat(resolvedDistDir);
  } catch (err) {
    issues.push({
      code: 'INVALID_DIST_DIR',
      page: resolvedDistDir,
      slug: '',
      lang: '',
      message: `Built dist directory not found: ${resolvedDistDir} (${err.message})`,
    });
  }

  if (distStat && (distStat.isSymbolicLink() || !distStat.isDirectory())) {
    issues.push({
      code: distStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'INVALID_DIST_DIR',
      page: resolvedDistDir,
      slug: '',
      lang: '',
      message: `dist path must be a regular non-symlink directory: ${resolvedDistDir}`,
    });
  } else if (distStat) {
    const rootIndex = join(resolvedDistDir, 'index.html');
    try {
      const idxStat = await lstat(rootIndex);
      if (idxStat.isSymbolicLink() || !idxStat.isFile()) {
        throw new Error('dist/index.html must be a regular non-symlink file');
      }
    } catch (err) {
      issues.push({
        code: 'INVALID_DIST_DIR',
        page: rootIndex,
        slug: '',
        lang: '',
        message: `${resolvedDistDir} does not contain a valid built site index.html (${err.message})`,
      });
    }
  }

  if (issues.length > 0) {
    return finishValidation({
      resolvedDistDir,
      resolvedContentDir,
      reportOut,
      quiet,
      verbose,
      issues,
      pagesChecked: 0,
      uniqueSlugs: 0,
      languages: [],
      emptyMediaPages: 0,
      nonEmptyMediaPages: 0,
      distMediaFilesCount: 0,
      excluded: { catalogIndexPages: 0, categoryListingPages: 0, paginationPages: 0, redirectStubs: 0 },
    });
  }

  const [mediaRes, contentRes] = await Promise.all([
    indexDistMedia(resolvedDistDir),
    loadExpectedProductRoutes(resolvedContentDir),
  ]);

  issues.push(...mediaRes.issues, ...contentRes.issues);

  const reverseRes = await scanNonProductRoutesAndOrphans(resolvedDistDir, contentRes.routeMap);
  issues.push(...reverseRes.issues);

  let pagesChecked = 0;
  let emptyMediaPages = 0;
  let nonEmptyMediaPages = 0;

  await mapWithConcurrency(contentRes.entries, concurrency, async record => {
    const htmlPath = join(resolvedDistDir, record.pageRel);
    let pageStat;
    try {
      pageStat = await lstat(htmlPath);
    } catch (err) {
      issues.push({
        code: 'MISSING_PRODUCT_PAGE',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Built product HTML missing for ${record.relFromProducts}: expected ${record.pageRel} (${err.message})`,
      });
      return;
    }

    if (pageStat.isSymbolicLink() || !pageStat.isFile()) {
      issues.push({
        code: pageStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'MISSING_PRODUCT_PAGE',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Built product page must be a regular non-symlink file: ${record.pageRel}`,
      });
      return;
    }

    let html;
    try {
      html = await readFile(htmlPath, 'utf8');
    } catch (err) {
      issues.push({
        code: 'HTML_READ_ERROR',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Failed reading ${record.pageRel}: ${err.message}`,
      });
      return;
    }

    pagesChecked++;

    if (isRedirectStub(html)) {
      issues.push({
        code: 'REDIRECT_STUB_FOR_PRODUCT',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Product route ${record.pageRel} emitted a redirect stub instead of a product page`,
      });
      return;
    }

    const mdSeen = new Set();
    let mdHasDuplicates = false;
    for (const img of record.mdImages) {
      if (mdSeen.has(img)) {
        mdHasDuplicates = true;
        issues.push({
          code: 'DUPLICATE_IMAGE_IN_MARKDOWN',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Markdown ${record.relFromProducts} declares duplicate image '${img}' across cover/gallery`,
        });
      }
      mdSeen.add(img);

      if (DERIVED_NAME_RE.test(img) || POSTER_NAME_RE.test(img)) {
        issues.push({
          code: 'DERIVED_IMAGE_IN_MARKDOWN',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Markdown ${record.relFromProducts} references derived/poster image '${img}' instead of original`,
        });
      }
    }

    const gallery = extractProductGallery(html);
    if (gallery.galleryCount === 0) {
      issues.push({
        code: 'MISSING_GALLERY_CONTAINER',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered HTML ${record.pageRel} is missing [data-product-gallery] container`,
      });
      return;
    }
    if (gallery.galleryCount > 1) {
      issues.push({
        code: 'DUPLICATE_GALLERY_CONTAINER',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered HTML ${record.pageRel} contains ${gallery.galleryCount} [data-product-gallery] containers (expected 1)`,
      });
    }

    if (record.mdImages.length === 0) {
      emptyMediaPages++;
      if (gallery.mainImages.length > 0 || gallery.dialogImages.length > 0 || gallery.thumbs.length > 0) {
        issues.push({
          code: 'UNEXPECTED_MEDIA_FOR_EMPTY_PRODUCT',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Markdown ${record.relFromProducts} declares no cover/gallery, but rendered ${gallery.mainImages.length} main, ${gallery.dialogImages.length} dialog, ${gallery.thumbs.length} thumb images`,
        });
      }
      return;
    }

    nonEmptyMediaPages++;
    const expectedCover = record.mdImages[0];

    // Main image check
    if (gallery.mainImages.length === 0) {
      issues.push({
        code: 'MISSING_MAIN_IMAGE',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered HTML ${record.pageRel} is missing [data-gallery-main-image]`,
      });
    } else {
      if (gallery.mainImages.length > 1) {
        issues.push({
          code: 'DUPLICATE_MAIN_IMAGE',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Rendered HTML ${record.pageRel} has ${gallery.mainImages.length} [data-gallery-main-image] elements`,
        });
      }
      const mainSrc = gallery.mainImages[0].src;
      if (mainSrc && (DERIVED_NAME_RE.test(mainSrc) || POSTER_NAME_RE.test(mainSrc))) {
        issues.push({
          code: 'MAIN_IMAGE_IS_DERIVED',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Main image src uses derived/poster path '${mainSrc}' instead of original`,
        });
      }
      if (mainSrc !== expectedCover) {
        issues.push({
          code: 'MAIN_IMAGE_MISMATCH',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Main image src mismatch: expected '${expectedCover}', rendered '${mainSrc}'`,
        });
      }
      verifyAssetInDist(mainSrc, 'Main image src', record, mediaRes.exactSet, mediaRes.lowerMap, issues);
    }

    // Dialog image check
    if (gallery.dialogImages.length === 0) {
      issues.push({
        code: 'MISSING_DIALOG_IMAGE',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered HTML ${record.pageRel} is missing [data-gallery-dialog-image]`,
      });
    } else {
      if (gallery.dialogImages.length > 1) {
        issues.push({
          code: 'DUPLICATE_DIALOG_IMAGE',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Rendered HTML ${record.pageRel} has ${gallery.dialogImages.length} [data-gallery-dialog-image] elements`,
        });
      }
      const dialogSrc = gallery.dialogImages[0].src;
      if (dialogSrc !== expectedCover) {
        issues.push({
          code: 'DIALOG_IMAGE_MISMATCH',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Dialog image src mismatch: expected '${expectedCover}', rendered '${dialogSrc}'`,
        });
      }
    }

    // Thumbnail buttons & data-full sequence reconciliation
    if (gallery.thumbs.length === 0) {
      issues.push({
        code: 'MISSING_THUMBNAILS',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered HTML ${record.pageRel} has no thumbnail buttons for ${record.mdImages.length} declared image(s)`,
      });
      return;
    }

    const renderedFulls = [];
    const seenRendered = new Set();
    let htmlHasDuplicates = false;

    gallery.thumbs.forEach((thumb, idx) => {
      if (!thumb.hasIndex || thumb.indexAttr !== String(idx)) {
        issues.push({
          code: 'THUMBNAIL_INDEX_MISMATCH',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Thumbnail #${idx} has data-gallery-index='${thumb.indexAttr}' (expected '${idx}')`,
        });
      }

      if (!thumb.hasFull || !thumb.fullSrc) {
        issues.push({
          code: 'MISSING_DATA_FULL',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Thumbnail #${idx} is missing data-full attribute`,
        });
      } else {
        renderedFulls.push(thumb.fullSrc);
        if (seenRendered.has(thumb.fullSrc)) {
          htmlHasDuplicates = true;
          issues.push({
            code: 'DUPLICATE_RENDERED_IMAGE',
            page: record.pageRel,
            slug: record.slug,
            lang: record.lang,
            message: `Rendered gallery duplicates data-full '${thumb.fullSrc}'`,
          });
        }
        seenRendered.add(thumb.fullSrc);

        if (DERIVED_NAME_RE.test(thumb.fullSrc) || POSTER_NAME_RE.test(thumb.fullSrc)) {
          issues.push({
            code: 'DERIVED_DATA_FULL',
            page: record.pageRel,
            slug: record.slug,
            lang: record.lang,
            message: `Thumbnail #${idx} data-full points to derived/poster path '${thumb.fullSrc}'`,
          });
        }

        verifyAssetInDist(
          thumb.fullSrc,
          `Thumbnail #${idx} data-full`,
          record,
          mediaRes.exactSet,
          mediaRes.lowerMap,
          issues
        );
      }

      if (thumb.imgs.length === 0) {
        issues.push({
          code: 'MISSING_THUMB_IMG',
          page: record.pageRel,
          slug: record.slug,
          lang: record.lang,
          message: `Thumbnail button #${idx} contains no <img> element`,
        });
      } else {
        if (thumb.imgs.length > 1) {
          issues.push({
            code: 'DUPLICATE_THUMB_IMG',
            page: record.pageRel,
            slug: record.slug,
            lang: record.lang,
            message: `Thumbnail button #${idx} contains ${thumb.imgs.length} <img> elements (expected 1)`,
          });
        }
        const thumbImgSrc = thumb.imgs[0].src;
        verifyAssetInDist(
          thumbImgSrc,
          `Thumbnail #${idx} <img> src`,
          record,
          mediaRes.exactSet,
          mediaRes.lowerMap,
          issues
        );

        if (thumb.fullSrc) {
          const expectedSidecar = getDerivedThumbUrl(thumb.fullSrc);
          if (expectedSidecar) {
            if (thumb.fullSrc.startsWith('/media/products/') || mediaRes.exactSet.has(expectedSidecar)) {
              if (thumbImgSrc !== expectedSidecar) {
                issues.push({
                  code: 'THUMB_SRC_MISMATCH',
                  page: record.pageRel,
                  slug: record.slug,
                  lang: record.lang,
                  message: `Thumbnail #${idx} <img> src '${thumbImgSrc}' does not match expected -240w sidecar '${expectedSidecar}'`,
                });
              }
            } else if (thumbImgSrc !== thumb.fullSrc) {
              issues.push({
                code: 'THUMB_SRC_MISMATCH',
                page: record.pageRel,
                slug: record.slug,
                lang: record.lang,
                message: `Thumbnail #${idx} <img> src '${thumbImgSrc}' matches neither '${expectedSidecar}' nor fallback '${thumb.fullSrc}'`,
              });
            }
          }
        }
      }
    });

    const missingFromHtml = record.mdImages.filter(u => !seenRendered.has(u));
    const extraInHtml = renderedFulls.filter(u => !mdSeen.has(u));

    if (missingFromHtml.length > 0) {
      issues.push({
        code: 'GALLERY_MISSING_IMAGES',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered gallery is missing ${missingFromHtml.length} Markdown image(s): ${missingFromHtml.join(', ')}`,
      });
    }
    if (extraInHtml.length > 0) {
      issues.push({
        code: 'GALLERY_EXTRA_IMAGES',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered gallery contains ${extraInHtml.length} image(s) not declared in Markdown (${record.relFromProducts}): ${extraInHtml.join(', ')}`,
      });
    }
    if (
      missingFromHtml.length === 0 &&
      extraInHtml.length === 0 &&
      !mdHasDuplicates &&
      !htmlHasDuplicates &&
      renderedFulls.some((url, i) => url !== record.mdImages[i])
    ) {
      issues.push({
        code: 'GALLERY_ORDER_MISMATCH',
        page: record.pageRel,
        slug: record.slug,
        lang: record.lang,
        message: `Rendered thumbnail data-full sequence differs from Markdown cover/gallery order`,
      });
    }
  });

  return finishValidation({
    resolvedDistDir,
    resolvedContentDir,
    reportOut,
    quiet,
    verbose,
    issues,
    pagesChecked,
    uniqueSlugs: contentRes.slugs.size,
    languages: [...contentRes.languages].sort(),
    emptyMediaPages,
    nonEmptyMediaPages,
    distMediaFilesCount: mediaRes.exactSet.size,
    excluded: reverseRes.excluded,
  });
}

async function finishValidation({
  resolvedDistDir,
  resolvedContentDir,
  reportOut,
  quiet,
  verbose,
  issues,
  pagesChecked,
  uniqueSlugs,
  languages,
  emptyMediaPages,
  nonEmptyMediaPages,
  distMediaFilesCount,
  excluded,
}) {
  const { counts: issuesByCode } = summarizeIssuesByCode(issues);
  const summary = {
    pagesChecked,
    uniqueSlugs,
    languagesCount: languages.length,
    languages,
    emptyMediaPages,
    nonEmptyMediaPages,
    distMediaFilesCount,
    excluded,
    issueCount: issues.length,
    issuesByCode,
  };

  if (reportOut) {
    const resolvedReportOut = resolve(reportOut);
    await mkdir(dirname(resolvedReportOut), { recursive: true });
    const payload = {
      generatedAt: new Date().toISOString(),
      distDir: resolvedDistDir,
      productsContentDir: resolvedContentDir,
      summary,
      issuesByCode,
      issues,
    };
    await writeFile(resolvedReportOut, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  }

  const ok = issues.length === 0;
  if (!quiet) {
    const excludedText =
      `excluded non-product routes: ${excluded.catalogIndexPages} catalog index, ` +
      `${excluded.categoryListingPages} category listing, ` +
      `${excluded.paginationPages} pagination, ${excluded.redirectStubs} redirect stub(s)`;

    if (ok) {
      console.log(
        `✅ Rendered product media gate passed: ${pagesChecked} product page(s) across ${languages.length} language(s) ` +
          `(${uniqueSlugs} SKU(s), ${emptyMediaPages} empty-media page(s); ${excludedText}).`
      );
    } else {
      console.error(
        `❌ Rendered product media gate failed with ${issues.length} issue(s) across ` +
          `${pagesChecked} product page(s) in ${languages.length} language(s) (${uniqueSlugs} SKU(s); ${excludedText}):`
      );
      for (const [code, count] of Object.entries(issuesByCode)) {
        console.error(`   [${code}] ${count}`);
      }
      const maxToPrint = verbose || issues.length <= 50 ? issues.length : 25;
      console.error('');
      for (const issue of issues.slice(0, maxToPrint)) {
        console.error(`   - [${issue.code}] ${issue.page}: ${issue.message}`);
      }
      if (issues.length > maxToPrint) {
        console.error(
          `   … and ${issues.length - maxToPrint} more issue(s). Pass --verbose or --report-out <file> for full breakdown.`
        );
      }
    }
  }

  return { ok, summary, issues, issuesByCode };
}

export function parseCliArgs(argv) {
  const options = {
    distDir: DEFAULT_DIST_DIR,
    productsContentDir: DEFAULT_PRODUCTS_CONTENT_DIR,
    reportOut: null,
    verbose: false,
  };

  let positionalSet = false;

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--products-content') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --products-content');
      options.productsContentDir = resolve(value);
      i++;
    } else if (arg === '--report-out') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --report-out');
      options.reportOut = resolve(value);
      i++;
    } else if (arg === '--verbose') {
      options.verbose = true;
    } else if (arg.startsWith('-')) {
      throw new Error(`Unknown option: ${arg}`);
    } else if (positionalSet) {
      throw new Error(`Unexpected extra positional argument: ${arg}`);
    } else if (!arg.trim()) {
      throw new Error('distDir argument cannot be empty');
    } else {
      options.distDir = resolve(arg);
      positionalSet = true;
    }
  }

  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const cliOptions = parseCliArgs(process.argv.slice(2));
    const result = await validateProductRenderedMedia(cliOptions);
    if (!result.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(`❌ ${err.message}`);
    process.exitCode = 1;
  }
}
