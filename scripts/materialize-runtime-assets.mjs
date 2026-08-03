import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  canonicalRuntimeBytes,
  createRuntimeAssetManifest,
  RUNTIME_ASSET_DEFINITIONS,
  serializeRuntimeAssetManifest,
} from './lib/runtime-assets.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputRoot = resolve(root, process.argv[2] ?? 'dist');
const runtimeDir = resolve(outputRoot, 'assets/runtime');
const manifest = await createRuntimeAssetManifest(root);

await mkdir(runtimeDir, { recursive: true });
for (const definition of RUNTIME_ASSET_DEFINITIONS) {
  const asset = manifest.assets[definition.logicalName];
  const bytes = canonicalRuntimeBytes(await readFile(resolve(root, definition.source)));
  await writeFile(resolve(runtimeDir, asset.file_name), bytes);
  console.log(`Materialized ${asset.url}`);
}
await writeFile(
  resolve(runtimeDir, 'manifest.json'),
  serializeRuntimeAssetManifest(manifest),
  'utf8'
);

const htaccessPath = resolve(outputRoot, '.htaccess');
const htaccess = await readFile(htaccessPath, 'utf8');
const beginMarker = '# KSSMI RUNTIME ASSET REWRITES BEGIN';
const endMarker = '# KSSMI RUNTIME ASSET REWRITES END';
const blockPattern = new RegExp(
  `${beginMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}[\\s\\S]*?${endMarker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`
);
if (!blockPattern.test(htaccess)) {
  throw new Error('dist/.htaccess is missing the runtime asset rewrite markers');
}
const rewriteRules = RUNTIME_ASSET_DEFINITIONS.map((definition) => {
  const asset = manifest.assets[definition.logicalName];
  const escapedPath = asset.url.slice(1).replaceAll('.', '\\.');
  return `RewriteRule ^${escapedPath}$ ${definition.rewriteTarget} [L]`;
});
const generatedBlock = [
  beginMarker,
  '# Generated from assets/runtime/manifest.json; do not hand-edit fingerprint values.',
  ...rewriteRules,
  endMarker,
].join('\n');
await writeFile(htaccessPath, htaccess.replace(blockPattern, generatedBlock), 'utf8');
console.log('Materialized /assets/runtime/manifest.json and generated dist/.htaccess rewrites');
