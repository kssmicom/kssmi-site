// The static HTML CSP has no 'unsafe-hashes', so the browser ignores every
// style="" attribute (and logs a violation). Fail the build if one appears in
// the built HTML or a runtime script; use a class or a stylesheet rule instead.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const distDir = resolve(process.argv[2] || 'dist');
const offenders = new Map();

const walk = (dir) => {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) walk(path);
    else if (name.endsWith('.html')) {
      // Inline <script> bodies may legitimately contain the text style="".
      const html = readFileSync(path, 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
      const found = html.match(/<[a-z][^>]*\sstyle="[^"]*"/gi);
      if (found) offenders.set(relative(distDir, path), found);
    }
  }
};
walk(distDir);

// Runtime scripts that inject markup (cookie-banner.js) are blocked the same way.
const runtimeDir = join(distDir, 'assets', 'runtime');
for (const name of readdirSync(runtimeDir)) {
  if (!name.endsWith('.js')) continue;
  const found = readFileSync(join(runtimeDir, name), 'utf8').match(/style="[^"]*"?/g);
  if (found) offenders.set(relative(distDir, join(runtimeDir, name)), found);
}

if (offenders.size > 0) {
  console.error(`Inline style attributes found in ${offenders.size} built files (blocked by the CSP):`);
  for (const [file, tags] of [...offenders].slice(0, 20)) console.error(`- ${file}: ${tags[0].slice(0, 120)}`);
  process.exit(1);
}
console.log('No inline style attributes in built HTML or runtime scripts.');
