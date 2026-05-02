/**
 * optimize-images.mjs
 *
 * One-time script to compress all product images in /public/media/products/.
 * Uses the Sharp library that is already bundled inside Astro's node_modules.
 *
 * Strategy:
 *   - Cover images (file index "-1."): max 800×600, quality 82 — used as LCP element
 *   - Gallery images (file index "-2." to "-9."): max 1200×900, quality 78 — shown in lightbox
 *   - Files already under 60 KB are skipped (already optimised)
 *
 * Run ONCE before deploying:
 *   node scripts/optimize-images.mjs
 *
 * The originals are OVERWRITTEN in place (they are static assets, not source files).
 * Run `git diff --stat` afterwards to verify the changes look correct before deploying.
 */

import { readdir, stat, rename, unlink } from 'node:fs/promises';
import { join, extname, basename } from 'node:path';
import { createRequire } from 'node:module';

// ── Locate Sharp from Astro's bundled copy ──────────────────────────────────
const require = createRequire(import.meta.url);
let sharp;
try {
  sharp = require('sharp');
} catch {
  // Astro bundles Sharp in a sub-path on some versions
  try {
    sharp = require('./node_modules/sharp/lib/index.js');
  } catch {
    console.error(
      '❌  Could not find Sharp.\n' +
      '   Run:  npm install sharp --save-dev\n' +
      '   Then: node scripts/optimize-images.mjs'
    );
    process.exit(1);
  }
}

// ── Configuration ────────────────────────────────────────────────────────────
const PRODUCTS_DIR   = './public/media/products';
const SKIP_BELOW_KB  = 60;        // Skip files already smaller than this
const COVER_MAX_W    = 800;       // Cover image max width  (4:3 → 800×600)
const COVER_MAX_H    = 600;       // Cover image max height
const GALLERY_MAX_W  = 1200;      // Gallery image max width
const GALLERY_MAX_H  = 900;       // Gallery image max height
const COVER_QUALITY  = 82;        // WebP quality for covers (higher = sharper)
const GALLERY_QUALITY = 78;       // WebP quality for gallery (lower = smaller)

// ── Helpers ──────────────────────────────────────────────────────────────────
function isCoverImage(filename) {
  // Covers are always the "-1." variant e.g. "kmo-258-cover-1.webp"
  // or simply the file with "-1" before the extension
  return /-1\.(webp|jpg|jpeg|png)$/i.test(filename);
}

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

function formatKB(bytes) {
  return (bytes / 1024).toFixed(1) + ' KB';
}

// ── Main ─────────────────────────────────────────────────────────────────────
async function optimizeImage(filePath) {
  const { size: originalSize } = await stat(filePath);

  // Skip tiny files — they're already optimised
  if (originalSize < SKIP_BELOW_KB * 1024) {
    return { status: 'skipped', reason: 'already small', originalSize };
  }

  const isCover = isCoverImage(basename(filePath));
  const maxW    = isCover ? COVER_MAX_W    : GALLERY_MAX_W;
  const maxH    = isCover ? COVER_MAX_H    : GALLERY_MAX_H;
  const quality = isCover ? COVER_QUALITY  : GALLERY_QUALITY;

  const tmpPath = filePath + '.tmp.webp';

  try {
    await sharp(filePath)
      .resize(maxW, maxH, {
        fit: 'inside',              // Preserve aspect ratio, never crop
        withoutEnlargement: true,   // Never upscale a small image
      })
      .webp({ quality, effort: 5 })  // effort 5 = good compression/speed balance
      .toFile(tmpPath);

    const { size: newSize } = await stat(tmpPath);

    if (newSize < originalSize) {
      // Replace the original file with the optimised version
      await rename(tmpPath, filePath.replace(/\.(jpg|jpeg|png)$/i, '.webp'));
      const saved = originalSize - newSize;
      return { status: 'optimised', originalSize, newSize, saved };
    } else {
      // Optimised version is not smaller — keep the original
      await unlink(tmpPath);
      return { status: 'skipped', reason: 'already optimal', originalSize };
    }
  } catch (err) {
    try { await unlink(tmpPath); } catch { /* ignore */ }
    return { status: 'error', error: err.message, originalSize };
  }
}

async function main() {
  console.log('\n🔍  Scanning product images…');
  const images = await findImages(PRODUCTS_DIR);
  console.log(`    Found ${images.length} image files\n`);

  let totalOriginalBytes = 0;
  let totalSavedBytes    = 0;
  let optimisedCount     = 0;
  let skippedCount       = 0;
  let errorCount         = 0;

  for (const filePath of images) {
    const result = await optimizeImage(filePath);
    totalOriginalBytes += result.originalSize;

    const rel = filePath.replace(/\\/g, '/').split('public/')[1];

    if (result.status === 'optimised') {
      optimisedCount++;
      totalSavedBytes += result.saved;
      const pct = Math.round((result.saved / result.originalSize) * 100);
      console.log(
        `  ✅  ${rel}\n` +
        `       ${formatKB(result.originalSize)} → ${formatKB(result.newSize)}` +
        `  (saved ${formatKB(result.saved)}, −${pct}%)`
      );
    } else if (result.status === 'skipped') {
      skippedCount++;
      console.log(`  ⏭   ${rel}  [${result.reason}]`);
    } else {
      errorCount++;
      console.error(`  ❌  ${rel}  ERROR: ${result.error}`);
    }
  }

  const totalSavedMB  = (totalSavedBytes / 1024 / 1024).toFixed(2);
  const totalOrigMB   = (totalOriginalBytes / 1024 / 1024).toFixed(2);

  console.log('\n────────────────────────────────────────');
  console.log(`  Total images scanned : ${images.length}`);
  console.log(`  Optimised            : ${optimisedCount}`);
  console.log(`  Skipped              : ${skippedCount}`);
  console.log(`  Errors               : ${errorCount}`);
  console.log(`  Original total size  : ${totalOrigMB} MB`);
  console.log(`  Total saved          : ${totalSavedMB} MB`);
  console.log('────────────────────────────────────────\n');

  if (optimisedCount > 0) {
    console.log('🚀  Done! Now rebuild and deploy your site.');
    console.log('    npm run build\n');
  }
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
