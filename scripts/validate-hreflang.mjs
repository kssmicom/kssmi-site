#!/usr/bin/env node
/**
 * Post-build hreflang, canonical, and site-identity guard.
 *
 * Verifies every HTML file in `dist/`:
 *   - Skips internal lazy-loaded HTML fragments (`inquiry-form/<lang>/index.html`)
 *     only after confirming they remain `noindex` and emit no canonical/hreflang/og:url.
 *   - Validates Astro redirect stubs (`<meta http-equiv="refresh">`): target exists
 *     in dist, no self-loop, no wrong origin or localhost leak, matching canonical if
 *     present, and no hreflang alternates.
 *   - Enforces full `noindex` pages: zero hreflang alternates, exactly 1 canonical
 *     matching self URL (and KS origin, no double slash / localhost), and exactly 1
 *     `og:url` matching canonical.
 *   - Enforces full indexable pages: exact 1 self-pointing canonical, exact 1 `og:url`
 *     matching canonical, valid KS `og:image`/`twitter:image`, matching `<html lang>`,
 *     and complete 17-language + `x-default` reciprocal hreflang matrix.
 */

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const ORIGIN = 'https://kssmi.com';
export const LANGUAGES = [
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
];
export const REQUIRED_HREFLANGS = [...LANGUAGES, 'x-default'];

const LANGUAGE_SET = new Set(LANGUAGES);
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

function isInternalHtmlFragment(relativeFile) {
  return /^inquiry-form\/[^/]+\/index\.html$/.test(relativeFile);
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

    i++;
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
 * Deterministic tokenizer parsing `<html lang>`, `<meta>`, and `<link>` tags
 * independently of attribute order, quote style, whitespace, or HTML entities,
 * while ignoring comments, `<script>`, and `<style>` blocks.
 */
export function parseHtmlIdentity(html) {
  const len = html.length;
  let i = 0;

  let hasHtmlTag = false;
  let sourceLang = '';
  let noindex = false;
  const refreshTargets = [];
  const canonicals = [];
  const alternates = [];
  const ogUrls = [];
  const ogImages = [];
  const twitterImages = [];

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
    const rawAttrs = trimmedInner.slice(
      nameMatch[1].length,
      trimmedInner.endsWith('/') ? -1 : undefined
    );
    const attrs = parseTagAttributes(rawAttrs);

    i = endOpen + 1;

    if (!selfClosing && (tagName === 'script' || tagName === 'style')) {
      i = findRawTagEnd(tagName, i);
      continue;
    }

    if (tagName === 'html') {
      hasHtmlTag = true;
      if (!sourceLang && Object.hasOwn(attrs, 'lang')) {
        sourceLang = attrs.lang.trim().toLowerCase();
      }
      continue;
    }

    if (tagName === 'link') {
      const relTokens = (attrs.rel || '').toLowerCase().split(/\s+/).filter(Boolean);
      const href = Object.hasOwn(attrs, 'href') ? attrs.href.trim() : '';
      if (relTokens.includes('canonical')) {
        canonicals.push(href);
      }
      if (relTokens.includes('alternate') && attrs.hreflang && attrs.hreflang.trim()) {
        alternates.push({
          hreflang: attrs.hreflang.trim().toLowerCase(),
          href,
        });
      }
      continue;
    }

    if (tagName === 'meta') {
      const metaName = (attrs.name || '').trim().toLowerCase();
      const metaProp = (attrs.property || '').trim().toLowerCase();
      const httpEquiv = (attrs['http-equiv'] || '').trim().toLowerCase();
      const content = Object.hasOwn(attrs, 'content') ? attrs.content.trim() : '';

      if (metaName === 'robots') {
        const tokens = content.toLowerCase().split(/[\s,]+/).filter(Boolean);
        if (tokens.includes('noindex')) {
          noindex = true;
        }
      }

      if (httpEquiv === 'refresh') {
        const refreshMatch = content.match(/(?:^|[;\s])url\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s;]+))/i);
        const target = refreshMatch ? (refreshMatch[1] ?? refreshMatch[2] ?? refreshMatch[3] ?? '').trim() : '';
        if (target) {
          refreshTargets.push(target);
        }
      }

      if (metaProp === 'og:url' || metaName === 'og:url') {
        ogUrls.push(content);
      }
      if (metaProp === 'og:image') {
        ogImages.push(content);
      }
      if (metaName === 'twitter:image' || metaProp === 'twitter:image') {
        twitterImages.push(content);
      }
    }
  }

  return {
    hasHtmlTag,
    sourceLang,
    noindex,
    refreshTargets,
    canonicals,
    alternates,
    ogUrls,
    ogImages,
    twitterImages,
  };
}

export function validateIdentityUrl(rawUrl, { origin = ORIGIN, allowQuery = false, allowHash = false } = {}) {
  if (!rawUrl || typeof rawUrl !== 'string' || !rawUrl.trim()) {
    return { ok: false, code: 'EMPTY_URL', reason: 'URL is empty' };
  }

  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    return { ok: false, code: 'MALFORMED_URL', reason: `malformed URL '${rawUrl}'` };
  }

  const host = parsed.hostname.toLowerCase();
  if (
    host === 'localhost' ||
    host === '127.0.0.1' ||
    host === '0.0.0.0' ||
    host === '::1' ||
    host === '[::1]' ||
    host.endsWith('.localhost') ||
    host.endsWith('.local')
  ) {
    return { ok: false, code: 'LOCALHOST_LEAK', reason: `local hostname leaked in URL '${rawUrl}'` };
  }

  if (parsed.origin !== origin) {
    return {
      ok: false,
      code: 'WRONG_ORIGIN',
      reason: `origin '${parsed.origin}' does not match '${origin}' in '${rawUrl}'`,
    };
  }

  const afterScheme = rawUrl.match(/^[a-zA-Z][a-zA-Z\d+.-]*:\/\/([^?#]*)/);
  const authAndPath = afterScheme ? afterScheme[1] : '';
  const firstSlash = authAndPath.indexOf('/');
  const rawPath = firstSlash === -1 ? '' : authAndPath.slice(firstSlash);
  if (rawPath.includes('//')) {
    return { ok: false, code: 'DOUBLE_SLASH_URL', reason: `double slash in URL path '${rawUrl}'` };
  }

  if (!allowQuery && parsed.search) {
    return { ok: false, code: 'UNEXPECTED_QUERY_OR_HASH', reason: `unexpected query string in '${rawUrl}'` };
  }
  if (!allowHash && parsed.hash) {
    return { ok: false, code: 'UNEXPECTED_QUERY_OR_HASH', reason: `unexpected hash fragment in '${rawUrl}'` };
  }

  return { ok: true, parsed };
}

export function expectedSelfCanonical(relativeFile, origin = ORIGIN) {
  const normalized = relativeFile.replace(/\\/g, '/');
  if (normalized === 'index.html') return `${origin}/`;
  if (normalized === '404.html') return `${origin}/404/`;
  if (normalized.endsWith('/index.html')) {
    const dirRel = normalized.slice(0, -'index.html'.length);
    return new URL(`/${dirRel}`, origin).href;
  }
  return new URL(`/${normalized}`, origin).href;
}

function expectedLangForFile(relativeFile) {
  const firstSeg = relativeFile.replace(/\\/g, '/').split('/')[0];
  return LANGUAGE_SET.has(firstSeg) && firstSeg !== 'en' ? firstSeg : 'en';
}

function resolveDistFileForUrl(distDir, url, origin = ORIGIN) {
  let parsed;
  try {
    parsed = new URL(url, origin);
  } catch {
    return null;
  }
  if (parsed.origin !== origin) return null;
  let relativePath;
  try {
    relativePath = decodeURIComponent(parsed.pathname).replace(/^\/+/, '');
  } catch {
    return null;
  }
  if (relativePath.split('/').includes('..')) return null;

  const candidates = [];
  if (relativePath.endsWith('.html')) {
    candidates.push(path.resolve(distDir, relativePath));
  } else {
    candidates.push(path.resolve(distDir, relativePath, 'index.html'));
    if (relativePath === '404' || relativePath === '404/') {
      candidates.push(path.resolve(distDir, '404.html'));
    }
  }

  for (const target of candidates) {
    const insideDist = target === distDir || target.startsWith(distDir + path.sep);
    if (!insideDist) continue;
    try {
      const st = fs.lstatSync(target);
      if (!st.isSymbolicLink() && st.isFile()) return target;
    } catch {
      // try next candidate
    }
  }
  return null;
}

function findHtmlFiles(distDir, issues) {
  const files = [];

  function walk(dir) {
    let entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (err) {
      issues.push({
        code: 'READ_ERROR',
        file: path.relative(distDir, dir).replace(/\\/g, '/') || '.',
        message: `Failed reading directory ${dir}: ${err.message}`,
      });
      return;
    }

    entries.sort((a, b) => a.name.localeCompare(b.name));
    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);
      let st;
      try {
        st = fs.lstatSync(fullPath);
      } catch (err) {
        issues.push({
          code: 'READ_ERROR',
          file: path.relative(distDir, fullPath).replace(/\\/g, '/'),
          message: `Failed inspecting ${fullPath}: ${err.message}`,
        });
        continue;
      }

      if (st.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          file: path.relative(distDir, fullPath).replace(/\\/g, '/'),
          message: `Symlink is not allowed in dist tree: ${fullPath}`,
        });
        continue;
      }

      if (st.isDirectory()) {
        walk(fullPath);
      } else if (st.isFile() && entry.name.endsWith('.html')) {
        files.push(fullPath);
      }
    }
  }

  walk(distDir);
  return files;
}

function summarizeIssuesByCode(issues) {
  const counts = {};
  for (const issue of issues) {
    counts[issue.code] = (counts[issue.code] || 0) + 1;
  }
  return counts;
}

export function validateHreflangAndIdentity({
  distDir = path.resolve('dist'),
  reportOut = null,
  quiet = false,
  verbose = false,
} = {}) {
  const resolvedDistDir = path.resolve(distDir);
  const issues = [];

  let distStat;
  try {
    distStat = fs.lstatSync(resolvedDistDir);
  } catch (err) {
    issues.push({
      code: 'INVALID_DIST_DIR',
      file: resolvedDistDir,
      message: `dist directory not found: ${resolvedDistDir} (${err.message})`,
    });
    return finishValidation({
      resolvedDistDir,
      reportOut,
      quiet,
      verbose,
      issues,
      summaryCounts: { totalHtmlFiles: 0, indexablePages: 0, noindexPages: 0, internalFragments: 0, redirectStubs: 0 },
    });
  }

  if (distStat.isSymbolicLink() || !distStat.isDirectory()) {
    issues.push({
      code: distStat.isSymbolicLink() ? 'SYMLINK_DISALLOWED' : 'INVALID_DIST_DIR',
      file: resolvedDistDir,
      message: `dist path must be a regular non-symlink directory: ${resolvedDistDir}`,
    });
    return finishValidation({
      resolvedDistDir,
      reportOut,
      quiet,
      verbose,
      issues,
      summaryCounts: { totalHtmlFiles: 0, indexablePages: 0, noindexPages: 0, internalFragments: 0, redirectStubs: 0 },
    });
  }

  const files = findHtmlFiles(resolvedDistDir, issues);
  if (files.length === 0 && issues.length === 0) {
    issues.push({
      code: 'EMPTY_DIST',
      file: resolvedDistDir,
      message: `No HTML files found under ${resolvedDistDir}`,
    });
  }

  const documentCache = new Map();
  function readDocument(file) {
    if (!documentCache.has(file)) {
      const html = fs.readFileSync(file, 'utf8');
      documentCache.set(file, parseHtmlIdentity(html));
    }
    return documentCache.get(file);
  }

  const summaryCounts = {
    totalHtmlFiles: files.length,
    indexablePages: 0,
    noindexPages: 0,
    internalFragments: 0,
    redirectStubs: 0,
  };

  for (const [fileIndex, file] of files.entries()) {
    const relativeFile = path.relative(resolvedDistDir, file).replace(/\\/g, '/');
    let doc;
    try {
      doc = readDocument(file);
    } catch (err) {
      issues.push({
        code: 'READ_ERROR',
        file: relativeFile,
        message: `${relativeFile}: failed reading HTML (${err.message})`,
      });
      continue;
    }

    if (!quiet && (fileIndex + 1) % 1000 === 0) {
      console.log(`hreflang & identity validation progress: ${fileIndex + 1}/${files.length}`);
    }

    // 1. Internal HTML fragments (InquiryFormLazy)
    if (isInternalHtmlFragment(relativeFile)) {
      summaryCounts.internalFragments++;
      if (!doc.noindex) {
        issues.push({
          code: 'FRAGMENT_MISSING_NOINDEX',
          file: relativeFile,
          message: `${relativeFile}: internal HTML fragment must remain robots noindex`,
        });
      }
      if (doc.alternates.length > 0) {
        issues.push({
          code: 'FRAGMENT_HAS_HREFLANG',
          file: relativeFile,
          message: `${relativeFile}: internal HTML fragment must not emit hreflang alternates`,
        });
      }
      if (doc.canonicals.length > 0) {
        issues.push({
          code: 'FRAGMENT_HAS_CANONICAL',
          file: relativeFile,
          message: `${relativeFile}: internal HTML fragment must not emit canonical`,
        });
      }
      if (doc.ogUrls.length > 0) {
        issues.push({
          code: 'FRAGMENT_HAS_OG_URL',
          file: relativeFile,
          message: `${relativeFile}: internal HTML fragment must not emit og:url`,
        });
      }
      continue;
    }

    // 2. Astro redirect stubs (<!doctype html><meta http-equiv="refresh" ...>)
    if (!doc.hasHtmlTag && doc.refreshTargets.length > 0) {
      summaryCounts.redirectStubs++;
      if (doc.alternates.length > 0) {
        issues.push({
          code: 'REDIRECT_HAS_HREFLANG',
          file: relativeFile,
          message: `${relativeFile}: redirect stub must not emit hreflang alternates`,
        });
      }
      if (doc.canonicals.length > 1) {
        issues.push({
          code: 'DUPLICATE_CANONICAL',
          file: relativeFile,
          message: `${relativeFile}: redirect stub must not emit ${doc.canonicals.length} canonical links`,
        });
      }

      const rawTarget = doc.refreshTargets[0];
      let resolvedTargetUrl = null;
      if (/^[a-zA-Z][a-zA-Z\d+.-]*:/.test(rawTarget)) {
        const check = validateIdentityUrl(rawTarget);
        if (!check.ok) {
          issues.push({
            code: check.code,
            file: relativeFile,
            message: `${relativeFile}: redirect refresh target error: ${check.reason}`,
          });
        } else {
          resolvedTargetUrl = check.parsed.href;
        }
      } else {
        if (rawTarget.includes('//')) {
          issues.push({
            code: 'DOUBLE_SLASH_URL',
            file: relativeFile,
            message: `${relativeFile}: redirect refresh target '${rawTarget}' contains a double slash`,
          });
        }
        try {
          const parsed = new URL(rawTarget, ORIGIN);
          if (parsed.origin !== ORIGIN) {
            issues.push({
              code: 'WRONG_ORIGIN',
              file: relativeFile,
              message: `${relativeFile}: redirect refresh target '${rawTarget}' resolves outside ${ORIGIN}`,
            });
          } else {
            resolvedTargetUrl = parsed.href;
          }
        } catch {
          issues.push({
            code: 'MALFORMED_URL',
            file: relativeFile,
            message: `${relativeFile}: malformed redirect refresh target '${rawTarget}'`,
          });
        }
      }

      if (resolvedTargetUrl) {
        const selfUrl = expectedSelfCanonical(relativeFile);
        if (resolvedTargetUrl === selfUrl) {
          issues.push({
            code: 'REDIRECT_SELF_LOOP',
            file: relativeFile,
            message: `${relativeFile}: redirect stub points to itself (${resolvedTargetUrl})`,
          });
        }
        const targetDistFile = resolveDistFileForUrl(resolvedDistDir, resolvedTargetUrl);
        if (!targetDistFile) {
          issues.push({
            code: 'REDIRECT_TARGET_MISSING',
            file: relativeFile,
            message: `${relativeFile}: redirect target missing from dist: ${resolvedTargetUrl}`,
          });
        }
      }

      if (doc.canonicals.length === 1) {
        const canonCheck = validateIdentityUrl(doc.canonicals[0]);
        if (!canonCheck.ok) {
          issues.push({
            code: canonCheck.code,
            file: relativeFile,
            message: `${relativeFile}: redirect canonical error: ${canonCheck.reason}`,
          });
        } else if (resolvedTargetUrl && canonCheck.parsed.href !== resolvedTargetUrl) {
          issues.push({
            code: 'REDIRECT_CANONICAL_MISMATCH',
            file: relativeFile,
            message: `${relativeFile}: redirect canonical '${canonCheck.parsed.href}' disagrees with refresh target '${resolvedTargetUrl}'`,
          });
        }
      }

      for (const ogUrl of doc.ogUrls) {
        const ogCheck = validateIdentityUrl(ogUrl);
        if (!ogCheck.ok) {
          issues.push({
            code: ogCheck.code,
            file: relativeFile,
            message: `${relativeFile}: redirect og:url error: ${ogCheck.reason}`,
          });
        }
      }
      continue;
    }

    // 3. Malformed HTML document (no <html> and not redirect/fragment)
    if (!doc.hasHtmlTag) {
      issues.push({
        code: 'MALFORMED_HTML_DOCUMENT',
        file: relativeFile,
        message: `${relativeFile}: missing <html> root element (not a recognized fragment or redirect stub)`,
      });
      continue;
    }

    const expectedSelf = expectedSelfCanonical(relativeFile);
    let canonical = '';

    // Validate canonical count + identity + self-path on all full documents (indexable and noindex)
    if (doc.canonicals.length === 0) {
      issues.push({
        code: 'MISSING_CANONICAL',
        file: relativeFile,
        message: `${relativeFile}: missing canonical link`,
      });
    } else if (doc.canonicals.length > 1) {
      issues.push({
        code: 'DUPLICATE_CANONICAL',
        file: relativeFile,
        message: `${relativeFile}: found ${doc.canonicals.length} canonical links (expected exactly 1)`,
      });
      canonical = doc.canonicals[0];
      for (const c of doc.canonicals) {
        const cCheck = validateIdentityUrl(c);
        if (!cCheck.ok) {
          issues.push({
            code: cCheck.code,
            file: relativeFile,
            message: `${relativeFile}: canonical URL error: ${cCheck.reason}`,
          });
        }
      }
    } else {
      canonical = doc.canonicals[0];
      const canonCheck = validateIdentityUrl(canonical);
      if (!canonCheck.ok) {
        issues.push({
          code: canonCheck.code,
          file: relativeFile,
          message: `${relativeFile}: canonical URL error: ${canonCheck.reason}`,
        });
      } else if (canonical !== expectedSelf) {
        issues.push({
          code: 'CANONICAL_NOT_SELF',
          file: relativeFile,
          message: `${relativeFile}: canonical '${canonical}' does not match expected self URL '${expectedSelf}'`,
        });
      }
    }

    // Validate og:url count + identity + canonical parity
    if (doc.ogUrls.length === 0) {
      issues.push({
        code: 'MISSING_OG_URL',
        file: relativeFile,
        message: `${relativeFile}: missing <meta property="og:url">`,
      });
    } else if (doc.ogUrls.length > 1) {
      issues.push({
        code: 'DUPLICATE_OG_URL',
        file: relativeFile,
        message: `${relativeFile}: found ${doc.ogUrls.length} <meta property="og:url"> tags (expected exactly 1)`,
      });
      for (const og of doc.ogUrls) {
        const ogCheck = validateIdentityUrl(og);
        if (!ogCheck.ok) {
          issues.push({
            code: ogCheck.code,
            file: relativeFile,
            message: `${relativeFile}: og:url error: ${ogCheck.reason}`,
          });
        }
      }
    } else {
      const ogUrl = doc.ogUrls[0];
      const ogCheck = validateIdentityUrl(ogUrl);
      if (!ogCheck.ok) {
        issues.push({
          code: ogCheck.code,
          file: relativeFile,
          message: `${relativeFile}: og:url error: ${ogCheck.reason}`,
        });
      } else if (canonical && ogUrl !== canonical) {
        issues.push({
          code: 'OG_URL_MISMATCH',
          file: relativeFile,
          message: `${relativeFile}: og:url '${ogUrl}' disagrees with canonical '${canonical}'`,
        });
      }
    }

    // Validate og:image and twitter:image host identity when present
    for (const imgUrl of doc.ogImages) {
      const check = validateIdentityUrl(imgUrl);
      if (!check.ok) {
        issues.push({
          code: check.code,
          file: relativeFile,
          message: `${relativeFile}: og:image identity error: ${check.reason}`,
        });
      }
    }
    for (const imgUrl of doc.twitterImages) {
      const check = validateIdentityUrl(imgUrl);
      if (!check.ok) {
        issues.push({
          code: check.code,
          file: relativeFile,
          message: `${relativeFile}: twitter:image identity error: ${check.reason}`,
        });
      }
    }

    // 4. Full noindex pages
    if (doc.noindex) {
      summaryCounts.noindexPages++;
      if (doc.alternates.length > 0) {
        issues.push({
          code: 'NOINDEX_HAS_HREFLANG',
          file: relativeFile,
          message: `${relativeFile}: noindex page must not emit hreflang alternates`,
        });
      }
      continue;
    }

    // 5. Full indexable pages: language & complete reciprocal hreflang matrix
    summaryCounts.indexablePages++;

    if (!LANGUAGE_SET.has(doc.sourceLang)) {
      issues.push({
        code: 'UNSUPPORTED_HTML_LANG',
        file: relativeFile,
        message: `${relativeFile}: unsupported or missing <html lang>: '${doc.sourceLang || '(empty)'}'`,
      });
    } else {
      const expectedLang = expectedLangForFile(relativeFile);
      if (doc.sourceLang !== expectedLang) {
        issues.push({
          code: 'LANG_PATH_MISMATCH',
          file: relativeFile,
          message: `${relativeFile}: <html lang="${doc.sourceLang}"> disagrees with route locale '${expectedLang}'`,
        });
      }
    }

    const seen = new Map();
    for (const alternate of doc.alternates) {
      if (seen.has(alternate.hreflang)) {
        issues.push({
          code: 'DUPLICATE_HREFLANG',
          file: relativeFile,
          message: `${relativeFile}: duplicate hreflang=${alternate.hreflang}`,
        });
      } else {
        seen.set(alternate.hreflang, alternate.href);
      }

      const altCheck = validateIdentityUrl(alternate.href);
      if (!altCheck.ok) {
        issues.push({
          code: altCheck.code,
          file: relativeFile,
          message: `${relativeFile}: hreflang=${alternate.hreflang} error: ${altCheck.reason}`,
        });
      }
    }

    for (const hreflang of REQUIRED_HREFLANGS) {
      if (!seen.has(hreflang)) {
        issues.push({
          code: 'MISSING_HREFLANG',
          file: relativeFile,
          message: `${relativeFile}: missing hreflang=${hreflang}`,
        });
      }
    }

    if (doc.sourceLang && canonical && seen.has(doc.sourceLang) && seen.get(doc.sourceLang) !== canonical) {
      issues.push({
        code: 'SELF_HREFLANG_MISMATCH',
        file: relativeFile,
        message: `${relativeFile}: self hreflang=${doc.sourceLang} ('${seen.get(doc.sourceLang)}') must equal canonical ('${canonical}')`,
      });
    }

    if (seen.has('x-default') && seen.has('en') && seen.get('x-default') !== seen.get('en')) {
      issues.push({
        code: 'X_DEFAULT_MISMATCH',
        file: relativeFile,
        message: `${relativeFile}: x-default ('${seen.get('x-default')}') must equal en ('${seen.get('en')}')`,
      });
    }

    for (const alternate of doc.alternates.filter(item => LANGUAGE_SET.has(item.hreflang))) {
      const altCheck = validateIdentityUrl(alternate.href);
      if (!altCheck.ok) continue;

      const targetFile = resolveDistFileForUrl(resolvedDistDir, alternate.href);
      if (!targetFile) {
        issues.push({
          code: 'HREFLANG_TARGET_MISSING',
          file: relativeFile,
          message: `${relativeFile}: hreflang=${alternate.hreflang} target is missing from dist: ${alternate.href}`,
        });
        continue;
      }

      const targetDoc = readDocument(targetFile);
      if (!targetDoc.hasHtmlTag || targetDoc.noindex) {
        issues.push({
          code: 'HREFLANG_TARGET_NOINDEX',
          file: relativeFile,
          message: `${relativeFile}: hreflang=${alternate.hreflang} points to a non-indexable target: ${alternate.href}`,
        });
        continue;
      }

      if (doc.sourceLang && canonical) {
        const reciprocalOk = targetDoc.alternates.some(
          item => item.hreflang === doc.sourceLang && item.href === canonical
        );
        if (!reciprocalOk) {
          issues.push({
            code: 'HREFLANG_NOT_RECIPROCAL',
            file: relativeFile,
            message: `${relativeFile}: ${alternate.hreflang} target (${alternate.href}) does not reciprocally reference ${doc.sourceLang} -> ${canonical}`,
          });
        }
      }
    }
  }

  return finishValidation({
    resolvedDistDir,
    reportOut,
    quiet,
    verbose,
    issues,
    summaryCounts,
  });
}

function finishValidation({
  resolvedDistDir,
  reportOut,
  quiet,
  verbose,
  issues,
  summaryCounts,
}) {
  const issuesByCode = summarizeIssuesByCode(issues);
  const summary = {
    ...summaryCounts,
    issueCount: issues.length,
    issuesByCode,
  };

  if (reportOut) {
    const resolvedReportOut = path.resolve(reportOut);
    fs.mkdirSync(path.dirname(resolvedReportOut), { recursive: true });
    fs.writeFileSync(
      resolvedReportOut,
      `${JSON.stringify(
        {
          generatedAt: new Date().toISOString(),
          distDir: resolvedDistDir,
          summary,
          issuesByCode,
          issues,
        },
        null,
        2
      )}\n`,
      'utf8'
    );
  }

  const ok = issues.length === 0;
  if (!quiet) {
    if (ok) {
      console.log(
        `hreflang & identity validation passed for ${summaryCounts.totalHtmlFiles} HTML files ` +
          `(${summaryCounts.indexablePages} indexable, ${summaryCounts.noindexPages} noindex, ` +
          `${summaryCounts.internalFragments} internal fragments, ${summaryCounts.redirectStubs} redirect stubs).`
      );
    } else {
      console.error(`hreflang & identity validation failed with ${issues.length} issue(s):`);
      for (const [code, count] of Object.entries(issuesByCode)) {
        console.error(`   [${code}] ${count}`);
      }
      const maxToPrint = verbose || issues.length <= 50 ? issues.length : 25;
      for (const issue of issues.slice(0, maxToPrint)) {
        console.error(`- [${issue.code}] ${issue.message}`);
      }
      if (issues.length > maxToPrint) {
        console.error(
          `... and ${issues.length - maxToPrint} more issue(s). Pass --verbose or --report-out <file> for full breakdown.`
        );
      }
    }
  }

  return { ok, summary, issues, issuesByCode };
}

export function parseCliArgs(argv) {
  const options = {
    distDir: path.resolve('dist'),
    reportOut: null,
    verbose: false,
    quiet: false,
  };

  let positionalSet = false;
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--report-out') {
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
    } else if (positionalSet) {
      throw new Error(`Unexpected extra positional argument: ${arg}`);
    } else if (!arg.trim()) {
      throw new Error('distDir argument cannot be empty');
    } else {
      options.distDir = path.resolve(arg);
      positionalSet = true;
    }
  }

  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    const cliOptions = parseCliArgs(process.argv.slice(2));
    const result = validateHreflangAndIdentity(cliOptions);
    if (!result.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(err.message);
    process.exitCode = 1;
  }
}
