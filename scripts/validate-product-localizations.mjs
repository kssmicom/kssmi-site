#!/usr/bin/env node
/**
 * Read-only multilingual product structure and localization validator for KS.
 *
 * Field ownership is grounded in KS templates (`src/content.config.ts`,
 * `src/pages/product/[slug].astro`, `src/pages/[lang]/product/[slug].astro`,
 * `TechnicalSpecs.astro`, `RelatedProducts.astro`) and `src/translations/index.ts`:
 *   - Language coverage: all 17 declared KS languages (`en` baseline + 16 locales)
 *   - Confirmed machine fields (must match `en` identically):
 *       slug, itemNo, date, productType, material, series, customizable, featured
 *   - Media sequence fields (must match `en` exact URL & order; no derived suffixes):
 *       cover, gallery
 *   - Allowed localizable display/SEO & optional family spec/legacy fields:
 *       title, seoTitle, seoDescription, seoKeywords, colors, materials,
 *       serviceMode, moq, size, frameMaterial, lensMaterial, designStyle,
 *       nosePads, hinge, logo, service, acetateFeature, electroplating,
 *       carbonFiberFeature, centerBeam, temple, specService, price, categories
 *       (key-path parity enforced against `en`; values may localize cleanly,
 *        including legacy `categories`, without locking to English)
 *   - Review advisories (non-blocking warnings for human QA):
 *       directory vs slug case drift, numeric/unit drift in size/moq/hinge,
 *       body length ratio outliers, heading count drift, bullet count drift.
 *
 * Note: structural & completeness validation is bookkeeping only; a clean exit 0
 * does not substitute for native-speaker semantic or technical fact review.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import yaml from 'js-yaml';
import { DERIVED_NAME_RE, POSTER_NAME_RE } from '../src/config/image-variants.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_PRODUCTS_DIR = path.resolve(__dirname, '../src/content/products');

export const LANGUAGES = Object.freeze([
  'en',
  'it',
  'es',
  'fr',
  'de',
  'pt',
  'ru',
  'ja',
  'tr',
  'ar',
  'ko',
  'zh',
  'hi',
  'vi',
  'jv',
  'ms',
  'tg',
]);

export const CONFIRMED_MACHINE_FIELDS = Object.freeze([
  'slug',
  'itemNo',
  'date',
  'productType',
  'material',
  'series',
  'customizable',
  'featured',
]);

export const MEDIA_SEQUENCE_FIELDS = Object.freeze(['cover', 'gallery']);

export const LOCALIZABLE_DISPLAY_FIELDS = Object.freeze([
  'title',
  'seoTitle',
  'seoDescription',
  'seoKeywords',
  'colors',
  'materials',
  'serviceMode',
  'moq',
  'size',
  'frameMaterial',
  'lensMaterial',
  'designStyle',
  'nosePads',
  'hinge',
  'logo',
  'service',
]);

export const OPTIONAL_SPEC_AND_LEGACY_FIELDS = Object.freeze([
  'acetateFeature',
  'electroplating',
  'carbonFiberFeature',
  'centerBeam',
  'temple',
  'specService',
  'price',
  'categories',
]);

const LANGUAGE_SET = new Set(LANGUAGES);

const TARGET_SCRIPT_PATTERNS = {
  zh: /[\u4E00-\u9FFF]/,
  ja: /[\u3040-\u309F\u30A0-\u30FF\u4E00-\u9FFF]/,
  ko: /[\uAC00-\uD7AF\u1100-\u11FF]/,
  ar: /[\u0600-\u06FF\u0750-\u077F]/,
  ru: /[\u0400-\u04FF]/,
  hi: /[\u0900-\u097F]/,
  tg: /[\u0400-\u04FF]/,
};

const CASE_SENSITIVE_PLACEHOLDER_RE = /\b(?:TODO|TBD|FIXME)\b/;
const CASE_INSENSITIVE_PLACEHOLDER_RE = /\b(?:PLACEHOLDER|LOREM IPSUM)\b/i;

function isPlainObject(val) {
  return Boolean(val && typeof val === 'object' && !Array.isArray(val) && !(val instanceof Date));
}

export function collectKeyPaths(value, prefix = '') {
  const paths = new Set();
  if (!isPlainObject(value)) return paths;
  for (const key of Object.keys(value)) {
    const fullKey = prefix ? `${prefix}.${key}` : key;
    paths.add(fullKey);
    const child = value[key];
    if (isPlainObject(child)) {
      for (const sub of collectKeyPaths(child, fullKey)) {
        paths.add(sub);
      }
    }
  }
  return paths;
}

function collectStringLeaves(value, prefix = '', out = []) {
  if (typeof value === 'string') {
    out.push({ path: prefix, value });
    return out;
  }
  if (Array.isArray(value)) {
    value.forEach((item, idx) => collectStringLeaves(item, `${prefix}[${idx}]`, out));
    return out;
  }
  if (isPlainObject(value)) {
    for (const [k, v] of Object.entries(value)) {
      collectStringLeaves(v, prefix ? `${prefix}.${k}` : k, out);
    }
  }
  return out;
}

function normalizeDigits(str) {
  return str
    .replace(/[\u0660-\u0669]/g, c => String(c.charCodeAt(0) - 0x0660))
    .replace(/[\u06F0-\u06F9]/g, c => String(c.charCodeAt(0) - 0x06f0))
    .replace(/[\u0966-\u096F]/g, c => String(c.charCodeAt(0) - 0x0966));
}

function extractNumbers(str) {
  if (typeof str !== 'string') return [];
  const matches = normalizeDigits(str).match(/\d+(?:[.,]\d+)?/g);
  return matches || [];
}

function hasMillimeterUnit(str) {
  if (typeof str !== 'string') return false;
  return /(?:\d\s*mm\b|\bmm\b|毫米|мм|ミリ|밀리|مم|ملم|міл|मिमी)/i.test(normalizeDigits(str));
}

function countHeadings(body) {
  const matches = body.match(/^#{1,6}\s+.+/gm);
  return matches ? matches.length : 0;
}

function countBullets(body) {
  const matches = body.match(/^\s*[-*+]\s+.+/gm);
  return matches ? matches.length : 0;
}

function canonicalMediaList(frontmatter) {
  const out = [];
  if (typeof frontmatter?.cover === 'string' && frontmatter.cover.trim()) {
    out.push(frontmatter.cover.trim());
  }
  if (Array.isArray(frontmatter?.gallery)) {
    for (const item of frontmatter.gallery) {
      if (typeof item === 'string' && item.trim()) {
        out.push(item.trim());
      }
    }
  }
  return out;
}

function summarizeByCode(items) {
  const counts = {};
  for (const item of items) {
    counts[item.code] = (counts[item.code] || 0) + 1;
  }
  return counts;
}

function readProductMarkdown(filePath, relFile, sku, lang, errors) {
  let st;
  try {
    st = fs.lstatSync(filePath);
  } catch (err) {
    errors.push({
      code: 'READ_ERROR',
      sku,
      lang,
      file: relFile,
      message: `Failed inspecting ${relFile}: ${err.message}`,
    });
    return null;
  }

  if (st.isSymbolicLink() || !st.isFile()) {
    errors.push({
      code: st.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'READ_ERROR',
      sku,
      lang,
      file: relFile,
      message: `Product Markdown path must be a regular non-symlink file: ${relFile}`,
    });
    return null;
  }

  let buf;
  try {
    buf = fs.readFileSync(filePath);
  } catch (err) {
    errors.push({
      code: 'READ_ERROR',
      sku,
      lang,
      file: relFile,
      message: `Failed reading ${relFile}: ${err.message}`,
    });
    return null;
  }

  if (buf.length >= 3 && buf[0] === 0xef && buf[1] === 0xbb && buf[2] === 0xbf) {
    errors.push({
      code: 'UTF8_BOM',
      sku,
      lang,
      file: relFile,
      message: `${relFile} starts with a UTF-8 BOM (0xEF 0xBB 0xBF); save as UTF-8 without BOM`,
    });
  }

  const raw = buf.toString('utf8').replace(/^\uFEFF/, '');
  const fmMatch = raw.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);
  if (!fmMatch) {
    errors.push({
      code: 'CONTENT_PARSE_ERROR',
      sku,
      lang,
      file: relFile,
      message: `${relFile} is missing valid --- YAML frontmatter delimiters`,
    });
    return null;
  }

  let data;
  try {
    data = yaml.load(fmMatch[1]);
  } catch (err) {
    errors.push({
      code: 'CONTENT_PARSE_ERROR',
      sku,
      lang,
      file: relFile,
      message: `${relFile} has invalid YAML frontmatter: ${err.message}`,
    });
    return null;
  }

  if (!isPlainObject(data)) {
    errors.push({
      code: 'CONTENT_PARSE_ERROR',
      sku,
      lang,
      file: relFile,
      message: `${relFile} frontmatter must parse to a mapping object`,
    });
    return null;
  }

  return {
    file: relFile,
    lang,
    data,
    body: fmMatch[2].trim(),
    keyPaths: collectKeyPaths(data),
    media: canonicalMediaList(data),
  };
}

function validateSkuFolder(skuDirName, skuDirPath, errors, advisories) {
  let entries;
  try {
    entries = fs.readdirSync(skuDirPath, { withFileTypes: true });
  } catch (err) {
    errors.push({
      code: 'READ_ERROR',
      sku: skuDirName,
      lang: '',
      file: skuDirName,
      message: `Failed reading SKU directory ${skuDirName}: ${err.message}`,
    });
    return { filesChecked: 0, complete17Langs: false };
  }

  entries.sort((a, b) => a.name.localeCompare(b.name));
  const byLang = new Map();
  let filesChecked = 0;

  for (const entry of entries) {
    const fullPath = path.join(skuDirPath, entry.name);
    const relFile = `${skuDirName}/${entry.name}`;
    let st;
    try {
      st = fs.lstatSync(fullPath);
    } catch (err) {
      errors.push({
        code: 'READ_ERROR',
        sku: skuDirName,
        lang: '',
        file: relFile,
        message: `Failed inspecting ${relFile}: ${err.message}`,
      });
      continue;
    }

    if (st.isSymbolicLink()) {
      errors.push({
        code: 'SYMLINK_DISALLOWED',
        sku: skuDirName,
        lang: '',
        file: relFile,
        message: `Symlink is not allowed under products directory: ${relFile}`,
      });
      continue;
    }

    if (!st.isFile() || !entry.name.endsWith('.md')) {
      errors.push({
        code: 'UNEXPECTED_FILE',
        sku: skuDirName,
        lang: '',
        file: relFile,
        message: `Unexpected non-product-Markdown entry inside SKU folder: ${relFile}`,
      });
      continue;
    }

    const nameMatch = entry.name.match(/^(.+)\.([A-Za-z-]+)\.md$/);
    if (!nameMatch) {
      errors.push({
        code: 'UNKNOWN_LANGUAGE_FILE',
        sku: skuDirName,
        lang: '',
        file: relFile,
        message: `Product Markdown filename '${entry.name}' does not match <slug>.<lang>.md`,
      });
      continue;
    }

    const [, fileStem, langRaw] = nameMatch;
    const lang = langRaw.toLowerCase();
    if (!LANGUAGE_SET.has(lang)) {
      errors.push({
        code: 'UNKNOWN_LANGUAGE_FILE',
        sku: skuDirName,
        lang,
        file: relFile,
        message: `Unsupported language code '${langRaw}' in ${relFile}`,
      });
      continue;
    }

    if (fileStem.toLowerCase() !== skuDirName.toLowerCase()) {
      errors.push({
        code: 'FILENAME_SLUG_MISMATCH',
        sku: skuDirName,
        lang,
        file: relFile,
        message: `Filename stem '${fileStem}' does not match SKU directory '${skuDirName}'`,
      });
    }

    if (byLang.has(lang)) {
      errors.push({
        code: 'DUPLICATE_LANGUAGE_FILE',
        sku: skuDirName,
        lang,
        file: relFile,
        message: `Duplicate language '${lang}' file in ${skuDirName}: ${entry.name}`,
      });
      continue;
    }

    filesChecked++;
    const parsed = readProductMarkdown(fullPath, relFile, skuDirName, lang, errors);
    if (parsed) {
      byLang.set(lang, parsed);
    }
  }

  for (const lang of LANGUAGES) {
    if (!byLang.has(lang)) {
      errors.push({
        code: lang === 'en' ? 'MISSING_ENGLISH_BASELINE' : 'MISSING_LANGUAGE_FILE',
        sku: skuDirName,
        lang,
        file: `${skuDirName}/${skuDirName.toLowerCase()}.${lang}.md`,
        message: `SKU '${skuDirName}' is missing language file for '${lang}'`,
      });
    }
  }

  const en = byLang.get('en');
  if (!en) {
    return { filesChecked, complete17Langs: false };
  }

  if (typeof en.data.slug === 'string' && en.data.slug !== skuDirName) {
    if (en.data.slug.toLowerCase() === skuDirName.toLowerCase()) {
      advisories.push({
        code: 'DIR_SLUG_CASE_MISMATCH',
        sku: skuDirName,
        lang: 'en',
        file: en.file,
        message: `Directory '${skuDirName}' differs in casing from English slug '${en.data.slug}'`,
      });
    } else {
      errors.push({
        code: 'MACHINE_FIELD_MISMATCH',
        sku: skuDirName,
        lang: 'en',
        file: en.file,
        message: `English slug '${en.data.slug}' does not match SKU directory '${skuDirName}'`,
      });
    }
  }

  // Validate per-file invariants common to all languages including `en`
  for (const [lang, doc] of byLang.entries()) {
    for (const imgUrl of doc.media) {
      if (DERIVED_NAME_RE.test(imgUrl) || POSTER_NAME_RE.test(imgUrl)) {
        errors.push({
          code: 'DERIVED_MEDIA_REF',
          sku: skuDirName,
          lang,
          file: doc.file,
          message: `${doc.file} references derived/poster image '${imgUrl}' in cover/gallery instead of original`,
        });
      }
    }

    const leaves = collectStringLeaves(doc.data);
    for (const { path: fieldPath, value } of leaves) {
      if (/\\n/.test(value)) {
        errors.push({
          code: 'LITERAL_ESCAPED_NEWLINE',
          sku: skuDirName,
          lang,
          file: doc.file,
          message: `${doc.file} frontmatter '${fieldPath}' contains literal '\\n' sequence`,
        });
      }
      if (CASE_SENSITIVE_PLACEHOLDER_RE.test(value) || CASE_INSENSITIVE_PLACEHOLDER_RE.test(value)) {
        errors.push({
          code: 'PLACEHOLDER_TOKEN',
          sku: skuDirName,
          lang,
          file: doc.file,
          message: `${doc.file} frontmatter '${fieldPath}' contains placeholder token`,
        });
      }
    }

    if (/\\n/.test(doc.body)) {
      errors.push({
        code: 'LITERAL_ESCAPED_NEWLINE',
        sku: skuDirName,
        lang,
        file: doc.file,
        message: `${doc.file} body contains literal '\\n' sequence`,
      });
    }
    if (CASE_SENSITIVE_PLACEHOLDER_RE.test(doc.body) || CASE_INSENSITIVE_PLACEHOLDER_RE.test(doc.body)) {
      errors.push({
        code: 'PLACEHOLDER_TOKEN',
        sku: skuDirName,
        lang,
        file: doc.file,
        message: `${doc.file} body contains placeholder token`,
      });
    }
  }

  const enHeadings = countHeadings(en.body);
  const enBullets = countBullets(en.body);
  const enTitle = typeof en.data.title === 'string' ? en.data.title.trim() : '';
  const enSeoTitle = typeof en.data.seoTitle === 'string' ? en.data.seoTitle.trim() : '';
  const enSeoDesc = typeof en.data.seoDescription === 'string' ? en.data.seoDescription.trim() : '';

  for (const lang of LANGUAGES) {
    if (lang === 'en') continue;
    const loc = byLang.get(lang);
    if (!loc) continue;

    // 1. Key-path parity against `en` baseline
    for (const k of en.keyPaths) {
      if (!loc.keyPaths.has(k)) {
        errors.push({
          code: 'MISSING_KEY',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} is missing English baseline key '${k}'`,
        });
      }
    }
    for (const k of loc.keyPaths) {
      if (!en.keyPaths.has(k)) {
        errors.push({
          code: 'EXTRA_KEY',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} defines unexpected key '${k}' absent from English baseline (check for translated key names or schema drift)`,
        });
      }
    }

    // 2. Confirmed machine fields must match `en` identically
    for (const field of CONFIRMED_MACHINE_FIELDS) {
      if (!(field in en.data) || !(field in loc.data)) continue;
      if (JSON.stringify(loc.data[field]) !== JSON.stringify(en.data[field])) {
        errors.push({
          code: 'MACHINE_FIELD_MISMATCH',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} machine field '${field}' (${JSON.stringify(loc.data[field])}) differs from English (${JSON.stringify(en.data[field])})`,
        });
      }
    }

    // 3. Media sequence & URL match vs `en`
    if (JSON.stringify(loc.media) !== JSON.stringify(en.media)) {
      errors.push({
        code: 'MEDIA_SEQUENCE_MISMATCH',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} cover/gallery (${loc.media.length} item(s)) differs from English baseline (${en.media.length} item(s))`,
      });
    }

    // 4. Missing/empty/untranslated hard checks
    if (en.body.length > 0 && loc.body.length === 0) {
      errors.push({
        code: 'EMPTY_BODY',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} has empty Markdown body while English baseline is non-empty`,
      });
    } else if (en.body.length > 0 && /[A-Za-z]{2,}/.test(en.body) && loc.body === en.body) {
      errors.push({
        code: 'UNTRANSLATED_BODY',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} Markdown body is identical to English`,
      });
    }

    for (const [k, enVal] of Object.entries(en.data)) {
      if (typeof enVal !== 'string' || !enVal.trim()) continue;
      if (!Object.hasOwn(loc.data, k)) continue;
      const locVal = loc.data[k];
      if (typeof locVal !== 'string' || !locVal.trim()) {
        errors.push({
          code: 'EMPTY_LOCALIZED_FIELD',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} field '${k}' is empty while English baseline is non-empty`,
        });
      }
    }

    const locTitle = typeof loc.data.title === 'string' ? loc.data.title.trim() : '';
    const locSeoTitle = typeof loc.data.seoTitle === 'string' ? loc.data.seoTitle.trim() : '';
    const locSeoDesc = typeof loc.data.seoDescription === 'string' ? loc.data.seoDescription.trim() : '';

    if (enTitle && locTitle && /[A-Za-z]{2,}/.test(enTitle) && locTitle === enTitle) {
      errors.push({
        code: 'UNTRANSLATED_TITLE',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} title '${locTitle}' is left verbatim in English`,
      });
    }
    if (enSeoTitle && locSeoTitle && /[A-Za-z]{2,}/.test(enSeoTitle) && locSeoTitle === enSeoTitle) {
      errors.push({
        code: 'UNTRANSLATED_SEO_FIELD',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} seoTitle is left verbatim in English`,
      });
    }
    if (enSeoDesc && locSeoDesc && /[A-Za-z]{2,}/.test(enSeoDesc) && locSeoDesc === enSeoDesc) {
      errors.push({
        code: 'UNTRANSLATED_SEO_FIELD',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} seoDescription is left verbatim in English`,
      });
    }

    const scriptRe = TARGET_SCRIPT_PATTERNS[lang];
    if (scriptRe) {
      const checks = [
        ['title', locTitle],
        ['seoTitle', locSeoTitle],
        ['seoDescription', locSeoDesc],
        ['body', loc.body],
      ];
      for (const [label, text] of checks) {
        if (text && !scriptRe.test(text)) {
          errors.push({
            code: 'MISSING_TARGET_SCRIPT',
            sku: skuDirName,
            lang,
            file: loc.file,
            message: `${loc.file} ${label} lacks expected ${lang} script characters`,
          });
        }
      }
    }

    // 5. Non-blocking review advisories
    for (const numField of ['size', 'moq', 'hinge']) {
      if (typeof en.data[numField] === 'string' && typeof loc.data[numField] === 'string') {
        const enNums = extractNumbers(en.data[numField]);
        const locNums = extractNumbers(loc.data[numField]);
        if (JSON.stringify(enNums) !== JSON.stringify(locNums)) {
          advisories.push({
            code: 'NUMERIC_TOKEN_DRIFT',
            sku: skuDirName,
            lang,
            file: loc.file,
            message: `${loc.file} '${numField}' numbers [${locNums.join(', ')}] differ from English [${enNums.join(', ')}]`,
          });
        }
      }
    }

    if (typeof en.data.size === 'string' && typeof loc.data.size === 'string') {
      if (hasMillimeterUnit(en.data.size) && !hasMillimeterUnit(loc.data.size)) {
        advisories.push({
          code: 'UNIT_TOKEN_DRIFT',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} 'size' may be missing millimeter unit ('${loc.data.size}')`,
        });
      }
    }

    if (en.body.length > 100 && loc.body.length > 0) {
      const ratio = loc.body.length / en.body.length;
      const minRatio = lang === 'zh' || lang === 'ja' || lang === 'ko' ? 0.22 : 0.45;
      if (ratio < minRatio || ratio > 2.5) {
        advisories.push({
          code: 'BODY_LENGTH_RATIO_ADVISORY',
          sku: skuDirName,
          lang,
          file: loc.file,
          message: `${loc.file} body length ratio ${ratio.toFixed(2)} (${loc.body.length}/${en.body.length}) is outside review range [${minRatio}, 2.50]`,
        });
      }
    }

    const locHeadings = countHeadings(loc.body);
    if (locHeadings !== enHeadings) {
      advisories.push({
        code: 'HEADING_COUNT_DRIFT',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} Markdown heading count (${locHeadings}) differs from English (${enHeadings})`,
      });
    }

    const locBullets = countBullets(loc.body);
    if ((enBullets > 0 && locBullets === 0) || Math.abs(locBullets - enBullets) >= 3) {
      advisories.push({
        code: 'BULLET_COUNT_DRIFT',
        sku: skuDirName,
        lang,
        file: loc.file,
        message: `${loc.file} bullet item count (${locBullets}) differs from English (${enBullets})`,
      });
    }
  }

  const complete17Langs = LANGUAGES.every(l => byLang.has(l));
  return { filesChecked, complete17Langs };
}

export function validateProductLocalizations({
  slug = null,
  all = false,
  productsDir = DEFAULT_PRODUCTS_DIR,
  reportOut = null,
  quiet = false,
  verbose = false,
} = {}) {
  if (!all && !slug) {
    throw new Error('Specify a product <slug> or pass --all for full catalog validation.');
  }
  if (all && slug) {
    throw new Error('Specify either a single <slug> or --all, not both.');
  }

  const resolvedProductsDir = path.resolve(productsDir);
  const errors = [];
  const advisories = [];

  let rootStat;
  try {
    rootStat = fs.lstatSync(resolvedProductsDir);
  } catch (err) {
    errors.push({
      code: 'PRODUCTS_DIR_ERROR',
      sku: '',
      lang: '',
      file: resolvedProductsDir,
      message: `Products directory not found: ${resolvedProductsDir} (${err.message})`,
    });
    return finishValidation({
      mode: all ? 'all' : 'single',
      targetSlug: slug,
      resolvedProductsDir,
      reportOut,
      quiet,
      verbose,
      errors,
      advisories,
      skusChecked: 0,
      skusWithAll17Languages: 0,
      filesChecked: 0,
    });
  }

  if (rootStat.isSymbolicLink() || !rootStat.isDirectory()) {
    errors.push({
      code: rootStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'PRODUCTS_DIR_ERROR',
      sku: '',
      lang: '',
      file: resolvedProductsDir,
      message: `Products directory must be a regular non-symlink directory: ${resolvedProductsDir}`,
    });
    return finishValidation({
      mode: all ? 'all' : 'single',
      targetSlug: slug,
      resolvedProductsDir,
      reportOut,
      quiet,
      verbose,
      errors,
      advisories,
      skusChecked: 0,
      skusWithAll17Languages: 0,
      filesChecked: 0,
    });
  }

  let dirEntries;
  try {
    dirEntries = fs.readdirSync(resolvedProductsDir, { withFileTypes: true });
  } catch (err) {
    errors.push({
      code: 'PRODUCTS_DIR_ERROR',
      sku: '',
      lang: '',
      file: resolvedProductsDir,
      message: `Failed reading products directory ${resolvedProductsDir}: ${err.message}`,
    });
    return finishValidation({
      mode: all ? 'all' : 'single',
      targetSlug: slug,
      resolvedProductsDir,
      reportOut,
      quiet,
      verbose,
      errors,
      advisories,
      skusChecked: 0,
      skusWithAll17Languages: 0,
      filesChecked: 0,
    });
  }

  dirEntries.sort((a, b) => a.name.localeCompare(b.name));
  const skuDirs = [];

  for (const entry of dirEntries) {
    const full = path.join(resolvedProductsDir, entry.name);
    let st;
    try {
      st = fs.lstatSync(full);
    } catch (err) {
      errors.push({
        code: 'READ_ERROR',
        sku: entry.name,
        lang: '',
        file: entry.name,
        message: `Failed inspecting ${full}: ${err.message}`,
      });
      continue;
    }

    if (st.isSymbolicLink()) {
      errors.push({
        code: 'SYMLINK_DISALLOWED',
        sku: entry.name,
        lang: '',
        file: entry.name,
        message: `Symlink is not allowed under products directory: ${full}`,
      });
      continue;
    }

    if (st.isDirectory()) {
      skuDirs.push({ name: entry.name, path: full });
    } else if (all) {
      errors.push({
        code: 'UNEXPECTED_LOOSE_FILE',
        sku: '',
        lang: '',
        file: entry.name,
        message: `Unexpected loose non-directory file under products root: ${entry.name}`,
      });
    }
  }

  if (skuDirs.length === 0) {
    errors.push({
      code: 'EMPTY_PRODUCTS_DIR',
      sku: '',
      lang: '',
      file: resolvedProductsDir,
      message: `No SKU directories found under ${resolvedProductsDir}`,
    });
    return finishValidation({
      mode: all ? 'all' : 'single',
      targetSlug: slug,
      resolvedProductsDir,
      reportOut,
      quiet,
      verbose,
      errors,
      advisories,
      skusChecked: 0,
      skusWithAll17Languages: 0,
      filesChecked: 0,
    });
  }

  let selectedSkus = skuDirs;
  if (!all && slug) {
    const exactMatch = skuDirs.find(d => d.name === slug);
    const ciMatch = exactMatch || skuDirs.find(d => d.name.toLowerCase() === slug.toLowerCase());
    if (!ciMatch) {
      errors.push({
        code: 'UNKNOWN_SKU',
        sku: slug,
        lang: '',
        file: slug,
        message: `SKU directory '${slug}' not found under ${resolvedProductsDir}`,
      });
      return finishValidation({
        mode: 'single',
        targetSlug: slug,
        resolvedProductsDir,
        reportOut,
        quiet,
        verbose,
        errors,
        advisories,
        skusChecked: 0,
        skusWithAll17Languages: 0,
        filesChecked: 0,
      });
    }
    selectedSkus = [ciMatch];
  }

  let skusChecked = 0;
  let skusWithAll17Languages = 0;
  let filesChecked = 0;

  for (const skuDir of selectedSkus) {
    skusChecked++;
    const res = validateSkuFolder(skuDir.name, skuDir.path, errors, advisories);
    filesChecked += res.filesChecked;
    if (res.complete17Langs) skusWithAll17Languages++;
  }

  return finishValidation({
    mode: all ? 'all' : 'single',
    targetSlug: slug,
    resolvedProductsDir,
    reportOut,
    quiet,
    verbose,
    errors,
    advisories,
    skusChecked,
    skusWithAll17Languages,
    filesChecked,
  });
}

function finishValidation({
  mode,
  targetSlug,
  resolvedProductsDir,
  reportOut,
  quiet,
  verbose,
  errors,
  advisories,
  skusChecked,
  skusWithAll17Languages,
  filesChecked,
}) {
  const errorsByCode = summarizeByCode(errors);
  const advisoriesByCode = summarizeByCode(advisories);
  const summary = {
    mode,
    targetSlug,
    skusChecked,
    skusWithAll17Languages,
    filesChecked,
    languagesExpected: LANGUAGES.length,
    errorCount: errors.length,
    advisoryCount: advisories.length,
    errorsByCode,
    advisoriesByCode,
  };

  if (reportOut) {
    const resolvedReportOut = path.resolve(reportOut);
    fs.mkdirSync(path.dirname(resolvedReportOut), { recursive: true });
    fs.writeFileSync(
      resolvedReportOut,
      `${JSON.stringify(
        {
          generatedAt: new Date().toISOString(),
          mode,
          targetSlug,
          productsDir: resolvedProductsDir,
          fieldOwnership: {
            confirmedMachineFields: CONFIRMED_MACHINE_FIELDS,
            mediaSequenceFields: MEDIA_SEQUENCE_FIELDS,
            localizableDisplayFields: LOCALIZABLE_DISPLAY_FIELDS,
            optionalSpecAndLegacyFields: OPTIONAL_SPEC_AND_LEGACY_FIELDS,
          },
          summary,
          errorsByCode,
          advisoriesByCode,
          errors,
          advisories,
        },
        null,
        2
      )}\n`,
      'utf8'
    );
  }

  const ok = errors.length === 0;
  if (!quiet) {
    const scopeLabel = mode === 'all' ? 'full catalog (--all)' : `SKU '${targetSlug}'`;
    if (ok) {
      console.log(
        `✅ Product localization structural check passed [${scopeLabel}]: ` +
          `${skusChecked} SKU(s) (${skusWithAll17Languages}/${skusChecked} complete across ${LANGUAGES.length} languages), ` +
          `${filesChecked} file(s), 0 hard errors, ${advisories.length} review advisory(ies).`
      );
    } else {
      console.error(
        `❌ Product localization structural check failed [${scopeLabel}] with ${errors.length} error(s) ` +
          `and ${advisories.length} review advisory(ies) across ${skusChecked} SKU(s) / ${filesChecked} file(s):`
      );
      for (const [code, count] of Object.entries(errorsByCode)) {
        console.error(`   [ERROR ${code}] ${count}`);
      }
      const maxErr = verbose || errors.length <= 50 ? errors.length : 25;
      for (const err of errors.slice(0, maxErr)) {
        console.error(`   - [${err.code}] ${err.file}: ${err.message}`);
      }
      if (errors.length > maxErr) {
        console.error(`   … and ${errors.length - maxErr} more error(s). Pass --verbose or --report-out <file>.`);
      }
    }

    if (advisories.length > 0) {
      console.log(`ℹ️  Review advisories (non-blocking, ${advisories.length}):`);
      for (const [code, count] of Object.entries(advisoriesByCode)) {
        console.log(`   [ADVISORY ${code}] ${count}`);
      }
      const maxAdv = verbose ? advisories.length : Math.min(10, advisories.length);
      for (const adv of advisories.slice(0, maxAdv)) {
        console.log(`   - [${adv.code}] ${adv.file}: ${adv.message}`);
      }
      if (advisories.length > maxAdv) {
        console.log(`   … and ${advisories.length - maxAdv} more advisory(ies). Pass --verbose or --report-out <file>.`);
      }
    }

    console.log('ℹ️  Note: Structural checks verify coverage, key paths, and machine/media invariants only; they do not verify human semantic translation quality.');
  }

  return { ok, summary, errors, advisories, errorsByCode, advisoriesByCode };
}

export function parseCliArgs(argv) {
  const options = {
    slug: null,
    all: false,
    productsDir: DEFAULT_PRODUCTS_DIR,
    reportOut: null,
    verbose: false,
    quiet: false,
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--all') {
      options.all = true;
    } else if (arg === '--products-dir') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --products-dir');
      options.productsDir = path.resolve(value);
      i++;
    } else if (arg === '--report-out') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --report-out');
      options.reportOut = path.resolve(value);
      i++;
    } else if (arg === '--verbose') {
      options.verbose = true;
    } else if (arg === '--quiet') {
      options.quiet = true;
    } else if (arg.startsWith('-')) {
      throw new Error(`Unknown option: ${arg}`);
    } else if (options.slug !== null) {
      throw new Error(`Unexpected extra positional argument: ${arg}`);
    } else if (!arg.trim()) {
      throw new Error('SKU slug argument cannot be empty');
    } else {
      options.slug = arg.trim();
    }
  }

  if (!options.all && !options.slug) {
    throw new Error('Usage: node scripts/validate-product-localizations.mjs <slug> | --all [--products-dir <dir>] [--report-out <file>] [--verbose]');
  }
  if (options.all && options.slug) {
    throw new Error('Specify either a single <slug> or --all, not both.');
  }

  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    const cliOptions = parseCliArgs(process.argv.slice(2));
    const result = validateProductLocalizations(cliOptions);
    if (!result.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(`❌ ${err.message}`);
    process.exitCode = 1;
  }
}
