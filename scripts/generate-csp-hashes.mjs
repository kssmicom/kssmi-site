import { createHash } from 'node:crypto';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const distDir = path.resolve(projectRoot, process.argv[2] || 'dist');
const writeSource = process.argv.includes('--write-source');

const sha256 = (value) => `sha256-${createHash('sha256').update(value, 'utf8').digest('base64')}`;

async function htmlFiles(dir) {
  const entries = await readdir(dir, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) files.push(...await htmlFiles(fullPath));
    else if (entry.isFile() && entry.name.endsWith('.html')) files.push(fullPath);
  }
  return files;
}

const scriptHashes = new Set();
const styleHashes = new Set();

for (const file of await htmlFiles(distDir)) {
  const html = await readFile(file, 'utf8');

  for (const match of html.matchAll(/<script(?![^>]*\bsrc\s*=)([^>]*)>([\s\S]*?)<\/script>/gi)) {
    // JSON-LD is structured data, not executable JavaScript. Hashing every
    // product's dynamic JSON-LD would create thousands of needless CSP hashes
    // and can exceed web-server response-header limits.
    if (/\btype\s*=\s*["']application\/(?:ld\+json|json)["']/i.test(match[1])) continue;
    scriptHashes.add(sha256(match[2]));
  }
  for (const match of html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)) {
    styleHashes.add(sha256(match[1]));
  }
  for (const match of html.matchAll(/\s+on[a-z]+\s*=\s*(["'])([\s\S]*?)\1/gi)) {
    scriptHashes.add(sha256(match[2]));
  }
}

const policy = [
  "default-src 'self'",
  "base-uri 'self'",
  "object-src 'none'",
  ["script-src 'self' 'unsafe-hashes'", ...[...scriptHashes].sort(),
    'https://static.cloudflareinsights.com', 'https://challenges.cloudflare.com',
    'https://www.googletagmanager.com', 'https://www.google-analytics.com'].join(' '),
  ["style-src 'self'", ...[...styleHashes].sort(), 'https://fonts.googleapis.com'].join(' '),
  "font-src 'self' https://fonts.gstatic.com",
  "img-src 'self' data: https:",
  "media-src 'self' blob: https://video.gumlet.io https://*.gumlet.io",
  "connect-src 'self' https://ipapi.co https://cloudflareinsights.com https://www.google-analytics.com https://video.gumlet.io https://*.gumlet.io",
  "frame-src 'self' https://challenges.cloudflare.com https://www.googletagmanager.com",
  "frame-ancestors 'self'",
  "form-action 'self'",
  'upgrade-insecure-requests',
].join('; ');

const updateFile = async (file) => {
  let text = await readFile(file, 'utf8');
  const marker = /(Static HTML CSP BEGIN[\s\S]*?Header always set Content-Security-Policy\s+")[^"]*(")/m;
  if (!marker.test(text)) throw new Error(`CSP marker not found in ${file}`);
  text = text.replace(marker, `$1${policy}$2`);
  await writeFile(file, text, 'utf8');
};

const cspMeta = (value) => `<meta data-kssmi-static-csp="1" http-equiv="Content-Security-Policy" content="${value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')}">`;

async function updateHtmlPolicy(file) {
  let html = await readFile(file, 'utf8');
  // This marker makes regeneration idempotent and avoids accepting a stale
  // policy when OpenLiteSpeed ignores Apache Header directives in .htaccess.
  html = html.replace(/\s*<meta\s+data-kssmi-static-csp\s*=\s*(["'])1\1[^>]*>\s*/gi, '\n');
  if (!/<\/head\s*>/i.test(html)) throw new Error(`Closing head tag not found in ${file}`);
  html = html.replace(/<\/head\s*>/i, `  ${cspMeta(policy)}\n</head>`);
  await writeFile(file, html, 'utf8');
}

await updateFile(path.join(distDir, '.htaccess'));
if (writeSource) await updateFile(path.join(projectRoot, 'public', '.htaccess'));
await Promise.all((await htmlFiles(distDir)).map(updateHtmlPolicy));

console.log(`Generated static CSP: ${scriptHashes.size} script/event hashes, ${styleHashes.size} style hashes.`);
