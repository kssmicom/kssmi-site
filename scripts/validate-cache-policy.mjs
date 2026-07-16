import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (path) => readFile(resolve(root, path));
const readText = async (path) => (await read(path)).toString('utf8');

const assets = [
  {
    source: 'public/cookie-banner.js',
    reference: 'src/layouts/Layout.astro',
    publicName: 'cookie-banner',
  },
  {
    source: 'public/js/vjt-tracker.js',
    reference: 'src/components/VisitorTracker.astro',
    publicName: 'vjt-tracker',
  },
];

const htaccess = await readText('public/.htaccess');
const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

for (const asset of assets) {
  const bytes = await read(asset.source);
  const hash = createHash('sha256').update(bytes).digest('hex').slice(0, 12);
  const url = `/assets/runtime/${asset.publicName}.${hash}.js`;
  const reference = await readText(asset.reference);

  assert(reference.includes(url), `${asset.reference} must reference ${url}`);
  assert(htaccess.includes(url.slice(1).replaceAll('.', '\\.')), `public/.htaccess must rewrite ${url}`);
}

const layout = await readText('src/layouts/Layout.astro');
const tracker = await readText('src/components/VisitorTracker.astro');
const olsHelper = await readText('scripts/ols-add-headers.py');
assert(!layout.includes('cookie-banner.js?v='), 'cookie banner must not use query-string versioning');
assert(!tracker.includes('vjt-tracker.js?v='), 'VJT tracker must not use query-string versioning');
assert(htaccess.includes('ExpiresActive Off'), 'public/.htaccess must disable inherited Expires rules');
assert(!/^ExpiresByType\b/m.test(htaccess), 'public/.htaccess must not define ExpiresByType');
assert(htaccess.includes('Header unset Cache-Control'), 'onsuccess Cache-Control must be cleared');
assert(htaccess.includes('Header always unset Cache-Control'), 'always Cache-Control must be cleared');
assert(htaccess.includes('s-maxage=600'), 'HTML must define the CDN edge TTL');
assert(htaccess.includes('KSSMI_CACHE_IMMUTABLE'), 'fingerprinted assets must have an immutable override');
assert(htaccess.includes('<FilesMatch "\\.php$">'), 'PHP must have a central no-store policy');
assert(!olsHelper.includes('expiresByType'), 'OLS helper must not install a second MIME cache policy');

for (const phpPath of [
  'public/email-logs.php',
  'public/send-mail.php',
  'public/visitor-journey.php',
  'public/api/track-submission.php',
  'public/api/vjt-config.php',
  'public/api/track-pageview.php',
]) {
  const php = await readText(phpPath);
  assert(!php.includes('Cache-Control:'), `${phpPath} must defer Cache-Control to .htaccess`);
}

if (failures.length) {
  console.error('Cache policy validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Cache policy validation passed.');
console.log('- Runtime asset fingerprints match their source content.');
console.log('- Query-string versioning is absent.');
console.log('- .htaccess is the single origin Cache-Control source.');
