/**
 * init-manifest.mjs
 *
 * Run this ONCE to register all current images as "already optimised"
 * so that optimize-images.mjs will skip them on future runs.
 *
 * This does NOT compress anything — it only reads file sizes.
 *
 * Usage:
 *   node scripts/init-manifest.mjs
 */

import { readdir, stat, writeFile } from 'node:fs/promises';
import { join, extname } from 'node:path';

const MEDIA_DIR     = './public/media';                       // All images
const MANIFEST_FILE = './scripts/.optimize-manifest.json';

async function findImages(dir) {
  const images = [];
  const entries = await readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = join(dir, entry.name);
    if (entry.isDirectory()) {
      images.push(...await findImages(fullPath));
    } else if (entry.isFile()) {
      const ext = extname(entry.name).toLowerCase();
      if (['.webp', '.jpg', '.jpeg', '.png'].includes(ext)) {
        images.push(fullPath);
      }
    }
  }
  return images;
}

async function main() {
  console.log('\n📋  Building manifest of already-optimised images…');
  const images = await findImages(MEDIA_DIR);
  console.log(`    Found ${images.length} image files\n`);

  const manifest = {};

  for (const filePath of images) {
    const { size } = await stat(filePath);
    const key = filePath.replace(/\\/g, '/').split('public/')[1];
    manifest[key] = { size, date: new Date().toISOString() };
  }

  await writeFile(MANIFEST_FILE, JSON.stringify(manifest, null, 2), 'utf8');

  console.log(`✅  Manifest saved to: ${MANIFEST_FILE}`);
  console.log(`    ${images.length} images registered as already optimised.`);
  console.log('\n    Future runs of optimize-images.mjs will skip these files.');
  console.log('    Only new product images will be compressed.\n');
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
