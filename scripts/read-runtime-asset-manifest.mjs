import process from 'node:process';
import { resolve } from 'node:path';
import { readRuntimeAssetManifest } from './lib/runtime-assets.mjs';

if (process.argv.length !== 3) {
  console.error('Usage: node scripts/read-runtime-asset-manifest.mjs <manifest-path>');
  process.exit(2);
}

try {
  const manifest = await readRuntimeAssetManifest(resolve(process.argv[2]));
  for (const asset of Object.values(manifest.assets)) console.log(asset.url);
} catch (error) {
  console.error('Runtime asset manifest read failed: ' + error.message);
  process.exit(1);
}
