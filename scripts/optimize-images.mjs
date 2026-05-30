/**
 * optimize-images.mjs
 *
 * Generates optimized WebP images with size variants for different contexts.
 *
 * IMAGE STRATEGY:
 *   - products/:  original + -400 (card) + -120w (thumbnail)
 *   - collection/, blogs/, feature/: original + -400 (card)
 *   - certifications/: original only
 *
 * HOW IT WORKS:
 *   - The first time you run this, ALL images are compressed + variants created.
 *   - A record of every processed file is saved to .optimize-manifest.json.
 *   - On future runs, files already in the manifest are SKIPPED automatically.
 *   - Only NEW images get processed.
 *
 * Quality settings (WebP):
 *   - Product cover ("-1."):    max 800x600,   quality 92
 *   - Product gallery ("-2."+): max 1200x900,  quality 90
 *   - Collection/blogs/feature: max 1600x1200, quality 88
 *   - Certifications:           max 600x600,   quality 85
 *
 * Variants:
 *   - Card (-400): 400x300, quality 85 — for cards/featured sections
 *   - Thumb (-120w): 120x120, quality 80 — for product listing grids
 *
 * Usage:
 *   node scripts/optimize-images.mjs
 *
 * Then rebuild and deploy:
 *   npm run build
 */

import { readdir, stat, unlink, readFile, writeFile } from 'node:fs/promises';
import { join, extname, basename, resolve } from 'node:path';
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';

// ── Locate Sharp ─────────────────────────────────────────────────────────────
const require = createRequire(import.meta.url);
let sharp;
try {
  sharp = require('sharp');
} catch {
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

// ── Configuration ─────────────────────────────────────────────────────────────
const MEDIA_DIR     = './public/media';
const MANIFEST_FILE = './scripts/.optimize-manifest.json';

// Original image settings per folder
const COVER_MAX_W     = 800;
const COVER_MAX_H     = 600;
const GALLERY_MAX_W   = 1200;
const GALLERY_MAX_H   = 900;
const COVER_QUALITY   = 92;
const GALLERY_QUALITY = 90;
const HERO_MAX_W      = 1600;
const HERO_MAX_H      = 1200;
const HERO_QUALITY    = 88;
const CERT_MAX_W      = 600;
const CERT_MAX_H      = 600;
const CERT_QUALITY    = 85;

// Variant settings
const CARD_W          = 400;
const CARD_H          = 300;
const CARD_QUALITY    = 85;

const THUMB_W         = 120;
const THUMB_H         = 120;
const THUMB_QUALITY   = 80;

// ── Detect image type from path ───────────────────────────────────────────────
function getImageSettings(filePath) {
  const normalized = filePath.replace(/\\/g, '/');

  if (normalized.includes('/media/products/')) {
    const isCover = /-1\.(webp|jpg|jpeg|png)$/i.test(filePath);
    return isCover
      ? { maxW: COVER_MAX_W, maxH: COVER_MAX_H, quality: COVER_QUALITY }
      : { maxW: GALLERY_MAX_W, maxH: GALLERY_MAX_H, quality: GALLERY_QUALITY };
  }
  if (normalized.includes('/media/certifications/')) {
    return { maxW: CERT_MAX_W, maxH: CERT_MAX_H, quality: CERT_QUALITY };
  }
  // Collection, blogs, feature
  return { maxW: HERO_MAX_W, maxH: HERO_MAX_H, quality: HERO_QUALITY };
}

// ── Helpers ───────────────────────────────────────────────────────────────────
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

async function loadManifest() {
  if (!existsSync(MANIFEST_FILE)) return {};
  try {
    const raw = await readFile(MANIFEST_FILE, 'utf8');
    return JSON.parse(raw);
  } catch {
    return {};
  }
}

async function saveManifest(manifest) {
  await writeFile(MANIFEST_FILE, JSON.stringify(manifest, null, 2), 'utf8');
}

// ── Compress one image ────────────────────────────────────────────────────────
async function optimizeImage(filePath) {
  const { size: originalSize } = await stat(filePath);
  const { maxW, maxH, quality } = getImageSettings(filePath);

  try {
    const inputBuffer = await readFile(filePath);
    const outputBuffer = await sharp(inputBuffer)
      .resize(maxW, maxH, { fit: 'inside', withoutEnlargement: true })
      .webp({ quality, effort: 5 })
      .toBuffer();

    const newSize = outputBuffer.length;
    const outPath = filePath.replace(/\.(jpg|jpeg|png)$/i, '.webp');
    await writeFile(outPath, outputBuffer);
    if (outPath !== filePath) {
      await unlink(filePath).catch(() => {});
    }
    const saved = originalSize - newSize;
    return { status: 'optimised', originalSize, newSize, saved };
  } catch (err) {
    return { status: 'error', error: err.message, originalSize };
  }
}

// ── Generate card variant (-400) ────────────────────────────────────────────
async function generateCardVariant(filePath) {
  const outPath = filePath
    .replace(/\.(jpg|jpeg|png)$/i, '.webp')
    .replace(/\.webp$/, '-400.webp')
    .replace(/-400\.webp$/, '-400.webp'); // avoid double -400

  try {
    const inputBuffer = await readFile(filePath);
    const outputBuffer = await sharp(inputBuffer)
      .resize(CARD_W, CARD_H, { fit: 'inside', withoutEnlargement: true })
      .webp({ quality: CARD_QUALITY, effort: 5 })
      .toBuffer();
    await writeFile(outPath, outputBuffer);
    return { status: 'card_created', size: outputBuffer.length };
  } catch (err) {
    return { status: 'card_error', error: err.message };
  }
}

// ── Generate thumbnail variant (-120w) ─────────────────────────────────────
async function generateThumbVariant(filePath) {
  const outPath = filePath
    .replace(/\.(jpg|jpeg|png)$/i, '.webp')
    .replace(/\.webp$/, '-120w.webp')
    .replace(/-120w\.webp$/, '-120w.webp'); // avoid double

  try {
    const inputBuffer = await readFile(filePath);
    const outputBuffer = await sharp(inputBuffer)
      .resize(THUMB_W, THUMB_H, { fit: 'inside', withoutEnlargement: true })
      .webp({ quality: THUMB_QUALITY, effort: 5 })
      .toBuffer();
    await writeFile(outPath, outputBuffer);
    return { status: 'thumb_created', size: outputBuffer.length };
  } catch (err) {
    return { status: 'thumb_error', error: err.message };
  }
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
  console.log('\n🔍  Scanning all images in /public/media/…');
  const images = await findImages(MEDIA_DIR);
  console.log(`    Found ${images.length} image files\n`);

  const manifest = await loadManifest();
  const alreadyDone = Object.keys(manifest).length;
  if (alreadyDone > 0) {
    console.log(`    ${alreadyDone} files already in manifest (will skip originals)\n`);
  } else {
    console.log('    No manifest found — processing all files\n');
  }

  let totalOriginalBytes = 0;
  let totalSavedBytes    = 0;
  let optimisedCount    = 0;
  let skippedCount       = 0;
  let errorCount        = 0;
  let cardGenCount      = 0;
  let thumbGenCount     = 0;

  for (const filePath of images) {
    const normalized = filePath.replace(/\\/g, '/');
    const key = normalized.split('public/')[1];
    totalOriginalBytes += (await stat(filePath)).size;

    // ── Determine folder type ───────────────────────────────────────────────
    const isProducts   = normalized.includes('/media/products/');
    const isCollection = normalized.includes('/media/collection/');
    const isBlogs      = normalized.includes('/media/blogs/');
    const isFeature    = normalized.includes('/media/feature/');
    const isCardFolder = isProducts || isCollection || isBlogs || isFeature;

    // ── Generate variants (check manifest to skip if exists) ───────────────

    // Card variant (-400) — for products AND collection/blogs/feature
    if (isCardFolder && !key.includes('-400') && !key.includes('-120w')) {
      const cardPath = filePath
        .replace(/\.(jpg|jpeg|png)$/i, '.webp')
        .replace(/\.webp$/, '-400.webp')
        .replace(/-400\.webp$/, '-400.webp');
      const cardKey = key.replace(/\.(jpg|jpeg|png)$/i, '.webp').replace(/\.webp$/, '-400.webp').replace(/-400\.webp$/, '-400.webp');

      if (!manifest[cardKey] && !existsSync(cardPath)) {
        const cardResult = await generateCardVariant(filePath);
        if (cardResult.status === 'card_created') {
          cardGenCount++;
          manifest[cardKey] = { size: cardResult.size, date: new Date().toISOString() };
          console.log(`  🃏  ${cardKey}  → ${formatKB(cardResult.size)} (card -400)`);
        } else {
          console.error(`  ❌  ${cardKey}  CARD ERROR: ${cardResult.error}`);
        }
      }
    }

    // Thumbnail variant (-120w) — ONLY for products
    if (isProducts && !key.includes('-400') && !key.includes('-120w')) {
      const thumbPath = filePath
        .replace(/\.(jpg|jpeg|png)$/i, '.webp')
        .replace(/\.webp$/, '-120w.webp')
        .replace(/-120w\.webp$/, '-120w.webp');
      const thumbKey = key.replace(/\.(jpg|jpeg|png)$/i, '.webp').replace(/\.webp$/, '-120w.webp').replace(/-120w\.webp$/, '-120w.webp');

      if (!manifest[thumbKey] && !existsSync(thumbPath)) {
        const thumbResult = await generateThumbVariant(filePath);
        if (thumbResult.status === 'thumb_created') {
          thumbGenCount++;
          manifest[thumbKey] = { size: thumbResult.size, date: new Date().toISOString() };
          console.log(`  🖼️   ${thumbKey}  → ${formatKB(thumbResult.size)} (thumb -120w)`);
        } else {
          console.error(`  ❌  ${thumbKey}  THUMB ERROR: ${thumbResult.error}`);
        }
      }
    }

    // ── SKIP if already in manifest ───────────────────────────────────────
    if (manifest[key]) {
      skippedCount++;
      continue;
    }

    // ── Process original image ─────────────────────────────────────────────
    const result = await optimizeImage(filePath);

    if (result.status === 'optimised') {
      optimisedCount++;
      totalSavedBytes += result.saved;
      const pct = Math.round((result.saved / result.originalSize) * 100);
      console.log(
        `  ✅  ${key}\n` +
        `       ${formatKB(result.originalSize)} → ${formatKB(result.newSize)}` +
        `  (saved ${formatKB(result.saved)}, −${pct}%)`
      );
      manifest[key] = { size: result.newSize, date: new Date().toISOString() };
    } else {
      errorCount++;
      console.error(`  ❌  ${key}  ERROR: ${result.error}`);
    }
  }

  await saveManifest(manifest);

  const totalSavedMB = (totalSavedBytes / 1024 / 1024).toFixed(2);
  const totalOrigMB  = (totalOriginalBytes / 1024 / 1024).toFixed(2);

  console.log('\n────────────────────────────────────────');
  console.log(`  Total images scanned   : ${images.length}`);
  console.log(`  Newly optimised        : ${optimisedCount}`);
  console.log(`  Card variants (-400)   : ${cardGenCount}`);
  console.log(`  Thumb variants (-120w) : ${thumbGenCount}`);
  console.log(`  Already done, skipped  : ${skippedCount}`);
  console.log(`  Errors                 : ${errorCount}`);
  console.log(`  Total saved this run   : ${totalSavedMB} MB`);
  console.log('────────────────────────────────────────\n');

  if (optimisedCount > 0 || cardGenCount > 0 || thumbGenCount > 0) {
    console.log('🚀  Done! Now rebuild and deploy your site.');
    console.log('    npm run build\n');
  } else if (skippedCount === images.length && cardGenCount === 0 && thumbGenCount === 0) {
    console.log('✅  All images are already optimised. Nothing to do.\n');
  } else {
    console.log('✅  Processing complete.\n');
  }
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});