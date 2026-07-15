#!/usr/bin/env node
/**
 * Post-build hreflang guard.
 * Fails when an indexable page has a broken language matrix, a non-reciprocal
 * alternate, a missing target HTML file, or a // canonical URL.
 */
import fs from 'node:fs';
import path from 'node:path';

const distDir = path.resolve(process.argv[2] || 'dist');
const origin = 'https://kssmi.com';
const languages = ['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];
const requiredHreflangs = [...languages, 'x-default'];
const htmlCache = new Map();
const documentCache = new Map();
const errors = [];

// These files are fetched and inserted into an existing page by
// InquiryFormLazy.astro. They are HTML fragments, not standalone documents,
// so they intentionally have no <html>, canonical URL, or hreflang matrix.
function isInternalHtmlFragment(relativeFile) {
  return /^inquiry-form\/[^/]+\/index\.html$/.test(relativeFile);
}

function findHtmlFiles(dir) {
  const files = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) files.push(...findHtmlFiles(fullPath));
    else if (entry.name.endsWith('.html')) files.push(fullPath);
  }
  return files;
}

function attr(tag, name) {
  const match = tag.match(new RegExp('\\b' + name + '\\s*=\\s*(["\\x27])(.*?)\\1', 'i'));
  return match ? match[2] : '';
}

function linkTags(html) {
  return [...html.matchAll(/<link\b[^>]*>/gi)].map((match) => match[0]);
}

function getHreflangLinks(html) {
  return linkTags(html)
    .filter((tag) => attr(tag, 'rel').toLowerCase().split(/\s+/).includes('alternate') && attr(tag, 'hreflang'))
    .map((tag) => ({ hreflang: attr(tag, 'hreflang').toLowerCase(), href: attr(tag, 'href') }));
}

function canonicalUrl(html) {
  const tag = linkTags(html).find((candidate) => attr(candidate, 'rel').toLowerCase().split(/\s+/).includes('canonical'));
  return tag ? attr(tag, 'href') : '';
}

function htmlLang(html) {
  const match = html.match(/<html\b[^>]*\blang\s*=\s*(["'])(.*?)\1/i);
  return match ? match[2].toLowerCase() : '';
}

function isNoindex(html) {
  return /<meta\b[^>]*\bname\s*=\s*(["'])robots\1[^>]*\bcontent\s*=\s*(["'])[^"']*\bnoindex\b/i.test(html)
    || /<meta\b[^>]*\bcontent\s*=\s*(["'])[^"']*\bnoindex\b[^"']*\1[^>]*\bname\s*=\s*(["'])robots\2/i.test(html);
}

function outputFileFor(url) {
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return null;
  }
  if (parsed.origin !== origin) return null;
  let relative;
  try {
    relative = decodeURIComponent(parsed.pathname).replace(/^\/+/, '');
  } catch {
    return null;
  }
  if (relative.split('/').includes('..')) return null;
  const target = path.resolve(distDir, relative, 'index.html');
  const insideDist = target === distDir || target.startsWith(distDir + path.sep);
  return insideDist ? target : null;
}

function readHtml(file) {
  if (!htmlCache.has(file)) htmlCache.set(file, fs.readFileSync(file, 'utf8'));
  return htmlCache.get(file);
}

// A target can be referenced by thousands of pages. Parse each document only
// once instead of rescanning its complete HTML for every reciprocal link.
function readDocument(file) {
  if (!documentCache.has(file)) {
    const html = readHtml(file);
    documentCache.set(file, {
      alternates: getHreflangLinks(html),
      canonical: canonicalUrl(html),
      sourceLang: htmlLang(html),
      noindex: isNoindex(html),
    });
  }
  return documentCache.get(file);
}

if (!fs.existsSync(distDir)) {
  console.error('hreflang validation failed: dist directory not found: ' + distDir);
  process.exit(1);
}

const files = findHtmlFiles(distDir);
for (const [fileIndex, file] of files.entries()) {
  const relativeFile = path.relative(distDir, file).replace(/\\/g, '/');
  if (isInternalHtmlFragment(relativeFile)) continue;

  const { alternates, canonical, sourceLang, noindex } = readDocument(file);

  if ((fileIndex + 1) % 1000 === 0) {
    console.log(`hreflang validation progress: ${fileIndex + 1}/${files.length}`);
  }

  if (noindex) {
    if (alternates.length) errors.push(relativeFile + ': noindex page must not emit hreflang alternates');
    continue;
  }

  if (!canonical) errors.push(relativeFile + ': missing canonical URL');
  if (canonical.startsWith(origin + '//')) errors.push(relativeFile + ': canonical contains a double slash after the host: ' + canonical);
  if (!languages.includes(sourceLang)) errors.push(relativeFile + ': unsupported or missing html lang: ' + (sourceLang || '(empty)'));

  const seen = new Map();
  for (const alternate of alternates) {
    if (seen.has(alternate.hreflang)) errors.push(relativeFile + ': duplicate hreflang=' + alternate.hreflang);
    seen.set(alternate.hreflang, alternate.href);
  }
  for (const hreflang of requiredHreflangs) {
    if (!seen.has(hreflang)) errors.push(relativeFile + ': missing hreflang=' + hreflang);
  }
  if (sourceLang && canonical && seen.get(sourceLang) !== canonical) {
    errors.push(relativeFile + ': self hreflang=' + sourceLang + ' must equal canonical');
  }
  if (seen.get('x-default') !== seen.get('en')) {
    errors.push(relativeFile + ': x-default must point to the English equivalent');
  }

  for (const alternate of alternates.filter((item) => languages.includes(item.hreflang))) {
    if (alternate.href.startsWith(origin + '//')) {
      errors.push(relativeFile + ': hreflang=' + alternate.hreflang + ' contains a double slash after the host');
      continue;
    }
    const targetFile = outputFileFor(alternate.href);
    if (!targetFile || !fs.existsSync(targetFile)) {
      errors.push(relativeFile + ': hreflang=' + alternate.hreflang + ' target is missing from dist: ' + alternate.href);
      continue;
    }
    const targetLinks = readDocument(targetFile).alternates;
    if (!targetLinks.some((item) => item.hreflang === sourceLang && item.href === canonical)) {
      errors.push(relativeFile + ': ' + alternate.hreflang + ' target does not reciprocally reference ' + sourceLang);
    }
  }
}

if (errors.length) {
  console.error('hreflang validation failed with ' + errors.length + ' issue(s):');
  for (const error of errors) console.error('- ' + error);
  process.exit(1);
}

console.log('hreflang validation passed for ' + files.length + ' HTML files.');
