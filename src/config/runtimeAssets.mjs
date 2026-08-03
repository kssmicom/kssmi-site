import { resolve } from 'node:path';
import { createRuntimeAssetManifest } from '../../scripts/lib/runtime-assets.mjs';

// Astro copies this module into dist/.prerender before evaluating static
// routes. Resolving from import.meta.url would therefore point at dist/public
// instead of the repository's public directory. Astro runs the build from the
// project root, so keep the source lookup anchored to that stable cwd.
const root = resolve(process.cwd());
export const runtimeAssetManifest = await createRuntimeAssetManifest(root);

export function runtimeAssetUrl(logicalName) {
  const asset = runtimeAssetManifest.assets[logicalName];
  if (!asset) throw new Error(`Unknown runtime asset logical name: ${logicalName}`);
  return asset.url;
}
