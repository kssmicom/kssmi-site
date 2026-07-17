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
const runtimeUrls = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

for (const asset of assets) {
  const bytes = await read(asset.source);
  // Git stores these text assets with LF line endings, but editors may leave a
  // normalized-equivalent CRLF working copy on Windows. Hash the canonical Git
  // representation so local validation and Linux CI produce the same URL.
  const canonicalBytes = Buffer.from(bytes.toString('utf8').replaceAll('\r\n', '\n'));
  const hash = createHash('sha256').update(canonicalBytes).digest('hex').slice(0, 12);
  const url = `/assets/runtime/${asset.publicName}.${hash}.js`;
  runtimeUrls.push(url);
  const reference = await readText(asset.reference);

  assert(reference.includes(url), `${asset.reference} must reference ${url}`);
  assert(htaccess.includes(url.slice(1).replaceAll('.', '\\.')), `public/.htaccess must rewrite ${url}`);
}

const layout = await readText('src/layouts/Layout.astro');
const tracker = await readText('src/components/VisitorTracker.astro');
const olsHelper = await readText('scripts/ols-add-headers.py');
const packageJson = JSON.parse(await readText('package.json'));
const materializer = await readText('scripts/materialize-runtime-assets.mjs');
const deployWorkflow = await readText('.github/workflows/deploy.yml');
assert(!layout.includes('cookie-banner.js?v='), 'cookie banner must not use query-string versioning');
assert(!tracker.includes('vjt-tracker.js?v='), 'VJT tracker must not use query-string versioning');
assert(!/^ExpiresByType\b/m.test(htaccess), 'public/.htaccess must not define ExpiresByType');
assert(!/^\s*Header\s+(?:always\s+)?(?:set|unset|append|merge)\s+Cache-Control\b/im.test(htaccess), 'public/.htaccess must not use unsupported OLS Cache-Control Header directives');
assert(!olsHelper.includes('expiresByType'), 'OLS helper must not install a second MIME cache policy');
assert(olsHelper.includes('KSSMI MANAGED CACHE CONTEXTS BEGIN'), 'OLS helper must install a marker-delimited native cache context block');
assert(olsHelper.includes('s-maxage=600'), 'OLS helper must define the HTML shared-cache policy');
assert(olsHelper.includes('max-age=31536000, immutable'), 'OLS helper must define an immutable asset policy');
assert(packageJson.scripts?.build?.includes('node scripts/materialize-runtime-assets.mjs dist'), 'build must materialize fingerprinted runtime assets in dist');
assert(!/context exp:[^\n]*\\\.\(css\|js\|mjs\)/.test(olsHelper), 'OLS helper must not install an overlapping catch-all JS/CSS regex context');
assert(olsHelper.includes('context exp:^/assets/runtime/.*\\.js$ {'), 'OLS helper must define a non-overlapping runtime fingerprint context');
assert(olsHelper.includes('location                $DOC_ROOT/$0'), 'runtime context must map to its physical files');

const nativeContexts = [...olsHelper.matchAll(/^context (.+) \{$/gm)].map((match) => match[1]);
assert(nativeContexts.length === 2, 'OLS helper must install only the exact homepage and runtime asset contexts');
assert(nativeContexts.includes('exp:^/$'), 'OLS helper must define only an exact homepage HTML context');
assert(nativeContexts.includes('exp:^/assets/runtime/.*\\.js$'), 'OLS helper must allowlist the runtime fingerprint directory');
assert(!/context exp:[^\n]*\\\.\(json\|xml\|txt\|webmanifest\)/.test(olsHelper), 'OLS helper must not install a broad data-file context that bypasses security rewrites');
assert(!/context exp:[^\n]*\\\.html\$/.test(olsHelper), 'OLS helper must not install a broad HTML context that bypasses routing rewrites');
assert(!/context exp:[^\n]*webp\|png\|jpg/.test(olsHelper), 'OLS helper must not install a broad media context that bypasses routing rewrites');
assert(/^RewriteRule \^email-logs\\\.json\$ - \[F,L\]$/m.test(htaccess), 'public/.htaccess must forbid email-logs.json');
assert(/^RewriteRule \^composer\\\.json\$ - \[F,L\]$/m.test(htaccess), 'public/.htaccess must forbid composer.json');
assert(deployWorkflow.includes("check_status 'https://kssmi.com/email-logs.json' '403 404'"), 'deploy must accept either forbidden or not-found for the removed legacy email log');

for (const [index, url] of runtimeUrls.entries()) {
  assert(materializer.includes(assets[index].source), `runtime materializer must read ${assets[index].source}`);
  assert(materializer.includes(`publicName: '${assets[index].publicName}'`), `runtime materializer must emit ${assets[index].publicName}`);
}

const phpPolicies = new Map([
  ['public/email-logs.php', 'Cache-Control: no-store, private'],
  ['public/send-mail.php', 'Cache-Control: no-store, private'],
  ['public/visitor-journey.php', 'Cache-Control: no-store, private'],
  ['public/404-router.php', 'Cache-Control: no-store, private'],
  ['public/api/track-submission.php', 'Cache-Control: no-store, private'],
  ['public/api/vjt-config.php', 'Cache-Control: public, max-age=300, must-revalidate'],
  ['public/api/track-pageview.php', 'Cache-Control: no-store, private'],
]);

for (const [phpPath, expectedPolicy] of phpPolicies) {
  const php = await readText(phpPath);
  assert(php.includes(expectedPolicy), `${phpPath} must send ${expectedPolicy}`);
}

if (failures.length) {
  console.error('Cache policy validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Cache policy validation passed.');
console.log('- Runtime asset fingerprints match their source content.');
console.log('- Query-string versioning is absent.');
console.log('- Build materializes physical fingerprinted runtime files.');
console.log('- OpenLiteSpeed contexts are restricted to explicit safe paths.');
console.log('- Security-sensitive JSON paths remain blocked by rewrite rules.');
console.log('- PHP endpoints send their own explicit Cache-Control policy.');
