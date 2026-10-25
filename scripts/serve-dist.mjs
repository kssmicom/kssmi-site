// Minimal static server for browser tests: serves a built dist/ directory the
// way the site resolves paths (directory index.html, extensionless .html) and
// answers unknown paths with dist/404.html and status 404. No PHP, no
// .htaccess: tests that need server rewrites run against production instead.
// Usage: node scripts/serve-dist.mjs [distDir=dist] [port=4321]
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';

const root = resolve(process.argv[2] || 'dist');
const port = Number(process.argv[3] || 4321);
const types = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.svg': 'image/svg+xml', '.webp': 'image/webp',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.xml': 'application/xml',
  '.txt': 'text/plain; charset=utf-8', '.wasm': 'application/wasm', '.pf_fragment': 'application/octet-stream',
  '.pf_index': 'application/octet-stream', '.pf_meta': 'application/octet-stream', '.pagefind': 'application/octet-stream',
};

async function resolveFile(urlPath) {
  const clean = normalize(decodeURIComponent(urlPath.split('?')[0])).replace(/^([/\\])+/, '');
  const candidate = join(root, clean);
  if (!candidate.startsWith(root)) return null;
  for (const path of [candidate, join(candidate, 'index.html'), `${candidate}.html`]) {
    try { if ((await stat(path)).isFile()) return path; } catch { /* try the next form */ }
  }
  return null;
}

createServer(async (req, res) => {
  const file = await resolveFile(req.url);
  if (!file) {
    const body = await readFile(join(root, '404.html')).catch(() => Buffer.from('not found'));
    res.writeHead(404, { 'Content-Type': types['.html'], 'Cache-Control': 'no-store' });
    return res.end(body);
  }
  res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
  res.end(await readFile(file));
}).listen(port, '127.0.0.1', () => console.log(`serving ${root} on http://127.0.0.1:${port}`));
