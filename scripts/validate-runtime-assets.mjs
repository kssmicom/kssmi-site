import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRuntimeAssetManifest } from './lib/runtime-assets.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');
const [
  layout,
  tracker,
  htaccess,
  materializer,
  runtimeConfig,
  deploy,
  environmentAction,
  phpCi,
  smoke,
  olsHelper,
  packageText,
] = await Promise.all([
  readText('src/layouts/Layout.astro'),
  readText('src/components/VisitorTracker.astro'),
  readText('public/.htaccess'),
  readText('scripts/materialize-runtime-assets.mjs'),
  readText('src/config/runtimeAssets.mjs'),
  readText('.github/workflows/deploy.yml'),
  readText('.github/actions/deploy-environment/action.yml'),
  readText('.github/workflows/php-ci.yml'),
  readText('scripts/smoke-deployment.mjs'),
  readText('scripts/ols-add-headers.py'),
  readText('package.json'),
]);

const manifest = await createRuntimeAssetManifest(root);
const fingerprintLiteral = /\/assets\/runtime\/[a-z0-9-]+\.[a-f0-9]{12}\.js/i;
for (const [label, source] of [
  ['Layout', layout],
  ['VisitorTracker', tracker],
  ['source .htaccess', htaccess],
  ['deploy workflow', deploy],
  ['environment deploy action', environmentAction],
  ['production smoke', smoke],
]) {
  assert.doesNotMatch(source, fingerprintLiteral, `${label} must not hard-code a runtime fingerprint.`);
}

assert.match(layout, /runtimeAssetUrl\(['"]cookie-banner['"]\)/, 'Layout must resolve the cookie banner by logical name.');
assert.match(tracker, /runtimeAssetUrl\(['"]vjt-tracker['"]\)/, 'VisitorTracker must resolve the tracker by logical name.');
assert.match(runtimeConfig, /createRuntimeAssetManifest\(root\)/, 'Astro runtime config must use the shared manifest generator.');
assert.match(runtimeConfig, /resolve\(process\.cwd\(\)\)/, 'Astro runtime asset lookup must remain anchored to the project cwd after prerender bundling.');
assert.doesNotMatch(runtimeConfig, /fileURLToPath\(import\.meta\.url\)/, 'Astro runtime asset lookup must not resolve from the copied prerender module URL.');
assert.match(htaccess, /KSSMI RUNTIME ASSET REWRITES BEGIN/, 'Source .htaccess must contain the generated rewrite marker.');
assert.match(htaccess, /KSSMI RUNTIME ASSET REWRITES END/, 'Source .htaccess must close the generated rewrite marker.');
assert.match(materializer, /serializeRuntimeAssetManifest\(manifest\)/, 'Materializer must emit the shared manifest.');
assert.match(materializer, /htaccess\.replace\(blockPattern, generatedBlock\)/, 'Materializer must generate dist .htaccess from the manifest.');
assert.match(deploy, /read-runtime-asset-manifest\.mjs dist\/assets\/runtime\/manifest\.json/, 'Deploy checks must enumerate URLs from the built manifest.');
assert.match(environmentAction, /SMOKE_RUNTIME_MANIFEST:\s*dist\/assets\/runtime\/manifest\.json/, 'Environment smoke must receive the release manifest path.');
assert.match(smoke, /readRuntimeAssetManifest\(runtimeManifestPath\)/, 'Production smoke must read the built manifest.');
assert.match(smoke, /digest !== asset\.sha256/, 'Production smoke must verify deployed runtime content digests.');
assert.match(olsHelper, /validate_runtime_manifest\(manifest_path\)/, 'OLS helper must validate the deployed manifest before changing configuration.');
assert.match(olsHelper, /\/assets\/runtime\//, 'OLS helper must restrict manifest URLs to the runtime directory.');

for (const asset of Object.values(manifest.assets)) {
  assert.ok(asset.url.startsWith('/assets/runtime/') && asset.url.endsWith('.js'));
}

const packageJson = JSON.parse(packageText);
assert.match(packageJson.scripts?.build || '', /materialize-runtime-assets\.mjs dist/, 'Build must materialize the manifest and assets.');
assert.equal(packageJson.scripts?.['validate:runtime-assets'], 'node scripts/validate-runtime-assets.mjs');
assert.equal(packageJson.scripts?.['test:runtime-assets'], 'node scripts/test-runtime-assets.mjs');

for (const [label, workflow] of [['Deploy', deploy], ['PHP CI', phpCi]]) {
  for (const command of ['npm run validate:runtime-assets', 'npm run test:runtime-assets']) {
    assert.ok(workflow.includes(command), `${label} workflow must run ${command}.`);
  }
}

console.log('Unified runtime asset manifest policy validated.');
