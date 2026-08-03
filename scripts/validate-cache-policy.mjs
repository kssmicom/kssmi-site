import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  createRuntimeAssetManifest,
  RUNTIME_ASSET_DEFINITIONS,
} from './lib/runtime-assets.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const read = (path) => readFile(resolve(root, path));
const readText = async (path) => (await read(path)).toString('utf8');

const htaccess = await readText('public/.htaccess');
const failures = [];
const assert = (condition, message) => {
  if (!condition) failures.push(message);
};

const layout = await readText('src/layouts/Layout.astro');
const tracker = await readText('src/components/VisitorTracker.astro');
const olsHelper = await readText('scripts/ols-add-headers.py');
const packageJson = JSON.parse(await readText('package.json'));
const materializer = await readText('scripts/materialize-runtime-assets.mjs');
const runtimeLibrary = await readText('scripts/lib/runtime-assets.mjs');
const deployWorkflow = await readText('.github/workflows/deploy.yml');
const environmentAction = await readText('.github/actions/deploy-environment/action.yml');
const deploySurface = `${deployWorkflow}\n${environmentAction}`;
const manifest = await createRuntimeAssetManifest(root);
assert(!layout.includes('cookie-banner.js?v='), 'cookie banner must not use query-string versioning');
assert(!tracker.includes('vjt-tracker.js?v='), 'VJT tracker must not use query-string versioning');
assert(layout.includes("runtimeAssetUrl('cookie-banner')"), 'Layout must resolve the cookie banner through the runtime manifest');
assert(tracker.includes("runtimeAssetUrl('vjt-tracker')"), 'VisitorTracker must resolve its script through the runtime manifest');
assert(!/\/assets\/runtime\/[a-z0-9-]+\.[a-f0-9]{12}\.js/i.test(layout + tracker + htaccess + deploySurface), 'runtime fingerprints must not be hard-coded in source consumers');
assert(htaccess.includes('KSSMI RUNTIME ASSET REWRITES BEGIN'), 'source .htaccess must provide the runtime rewrite generation markers');
assert(htaccess.includes('KSSMI RUNTIME ASSET REWRITES END'), 'source .htaccess runtime rewrite markers must be balanced');
assert(!/^ExpiresByType\b/m.test(htaccess), 'public/.htaccess must not define ExpiresByType');
assert(!/^\s*Header\s+(?:always\s+)?(?:set|unset|append|merge)\s+Cache-Control\b/im.test(htaccess), 'public/.htaccess must not use unsupported OLS Cache-Control Header directives');
assert(!olsHelper.includes('expiresByType'), 'OLS helper must not install a second MIME cache policy');
assert(olsHelper.includes('KSSMI MANAGED CACHE CONTEXTS BEGIN'), 'OLS helper must install a marker-delimited native cache context block');
assert(olsHelper.includes('s-maxage=600'), 'OLS helper must define the HTML shared-cache policy');
assert(olsHelper.includes('max-age=31536000, immutable'), 'OLS helper must define an immutable asset policy');
assert(packageJson.scripts?.build?.includes('node scripts/materialize-runtime-assets.mjs dist'), 'build must materialize fingerprinted runtime assets in dist');
assert(packageJson.scripts?.['validate:runtime-assets'], 'package must expose the runtime manifest policy validator');
assert(packageJson.scripts?.['test:runtime-assets'], 'package must expose runtime manifest behavior tests');
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
assert(environmentAction.includes('check_status "$SITE_URL/email-logs.json" \'403 404\''), 'deploy must accept either forbidden or not-found for the removed legacy email log');
assert(deploySurface.includes('read-runtime-asset-manifest.mjs dist/assets/runtime/manifest.json'), 'deploy cache checks must enumerate the built runtime manifest');
assert(materializer.includes('serializeRuntimeAssetManifest(manifest)'), 'runtime materializer must emit a machine-readable manifest');
for (const definition of RUNTIME_ASSET_DEFINITIONS) {
  const asset = manifest.assets[definition.logicalName];
  assert(runtimeLibrary.includes(`logicalName: '${definition.logicalName}'`), `runtime library must register ${definition.logicalName}`);
  assert(asset.url.startsWith('/assets/runtime/'), `${definition.logicalName} must remain inside the runtime asset directory`);
}

const phpPolicies = new Map([
  ['public/api/contact-intent.php', 'Cache-Control: no-store, private'],
  ['public/api/track-contact-intent.php', 'Cache-Control: no-store, private'],
  ['public/email-logs.php', 'Cache-Control: no-store, private'],
  ['public/send-mail.php', 'Cache-Control: no-store, private'],
  ['public/visitor-journey.php', 'Cache-Control: no-store, private'],
  ['public/404-router.php', 'Cache-Control: no-store, private'],
  ['public/api/track-submission.php', 'Cache-Control: no-store, private'],
  ['public/api/vjt-config.php', 'Cache-Control: public, max-age=300, must-revalidate'],
  ['public/api/track-pageview.php', 'Cache-Control: no-store, private'],
]);

// Admin pages (email-logs.php, visitor-journey.php) emit their security and
// cache headers via the shared private/http-security.php module
// (kssmi_admin_security_headers), so their literal policy lives there, not
// inline in each page. Non-admin PHP endpoints still send their own header.
const sharedHttpSecurity = await readText('private/http-security.php');

for (const [phpPath, expectedPolicy] of phpPolicies) {
  if (phpPath === 'public/email-logs.php' || phpPath === 'public/visitor-journey.php') {
    assert(
      sharedHttpSecurity.includes(expectedPolicy),
      `${phpPath} policy must be provided by private/http-security.php: ${expectedPolicy}`
    );
    continue;
  }
  const php = await readText(phpPath);
  assert(php.includes(expectedPolicy), `${phpPath} must send ${expectedPolicy}`);
}

if (failures.length) {
  console.error('Cache policy validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('Cache policy validation passed.');
console.log('- Runtime manifest fingerprints match their source content.');
console.log('- Query-string versioning is absent.');
console.log('- Build materializes physical files, manifest and generated rewrites.');
console.log('- OpenLiteSpeed contexts are restricted to explicit safe paths.');
console.log('- Security-sensitive JSON paths remain blocked by rewrite rules.');
console.log('- PHP endpoints send their own explicit Cache-Control policy.');
