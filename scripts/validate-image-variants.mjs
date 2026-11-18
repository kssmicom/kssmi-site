#!/usr/bin/env node
/**
 * Pre-build gate for product image variants.
 *
 * Read-only scan of public/media/products: every original product image
 * must sit next to its pre-generated derivatives <name>-400.webp (card)
 * and <name>-240w.webp (thumbnail), as produced by
 * scripts/optimize-images.mjs. Missing variants fail the build instead of
 * silently degrading to the heavy originals (src/utils/image.ts keeps a
 * build-time existence fallback, but this gate is what enforces the
 * variant strategy).
 *
 * Usage:
 *   node scripts/validate-image-variants.mjs [scanRoot]
 *
 * scanRoot defaults to public/media/products; pass an isolated fixture
 * directory to test the gate itself. This script never creates, modifies
 * or deletes image files and never runs the optimizer.
 */

import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const DEFAULT_ROOT = 'public/media/products';
const IMAGE_EXTENSION = /\.(webp|jpe?g|png)$/i;
// Pre-generated derivatives (-120w legacy and -240w current thumbnails, card
// variants) and poster assets are never treated as originals.
const DERIVED_NAME = /-(400|120w|240w)\.(webp|jpe?g|png)$/i;
const POSTER_NAME = /-poster\./i;
const REQUIRED_VARIANTS = ['-400', '-240w'];

const scanRoot = process.argv[2] ?? DEFAULT_ROOT;

function fail(message) {
  console.error(message);
  process.exit(1);
}

/**
 * A variant only counts when the path exists and is a regular file —
 * a directory (or anything else) at that path is still a failure.
 */
function variantIsFile(variantPath) {
  try {
    return statSync(variantPath).isFile();
  } catch {
    return false;
  }
}

// The scan root itself must exist; a typo must not pass silently.
try {
  if (!statSync(scanRoot).isDirectory()) {
    fail(`❌ Image variant scan root is not a directory: ${scanRoot}`);
  }
} catch (error) {
  fail(`❌ Cannot access image variant scan root ${scanRoot}: ${error.message}`);
}

const originals = [];
try {
  (function walk(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) {
        walk(full);
        continue;
      }
      if (!entry.isFile()) continue;
      if (!IMAGE_EXTENSION.test(entry.name)) continue;
      if (DERIVED_NAME.test(entry.name) || POSTER_NAME.test(entry.name)) continue;
      originals.push(full);
    }
  })(scanRoot);
} catch (error) {
  fail(`❌ Failed to scan ${scanRoot}: ${error.message}`);
}

const missing = [];
for (const original of originals) {
  const base = original.replace(/\.[^.]+$/, '');
  for (const suffix of REQUIRED_VARIANTS) {
    const variantPath = `${base}${suffix}.webp`;
    if (!variantIsFile(variantPath)) {
      missing.push({ original, variantPath });
    }
  }
}

if (missing.length > 0) {
  console.error(`❌ ${missing.length} product image variant(s) missing under ${scanRoot}:`);
  for (const { original, variantPath } of missing) {
    console.error(`   original: ${original}`);
    console.error(`   missing:  ${variantPath}`);
  }
  console.error(`\n   Originals scanned: ${originals.length}.`);
  console.error('\n   To generate variants for newly added originals, run:');
  console.error('     node scripts/optimize-images.mjs');
  console.error('   Note: the optimizer relies on scripts/.optimize-manifest.json and');
  console.error('   skips variants that already have a manifest record. When a variant');
  console.error('   is recorded in the manifest but its file is missing on disk,');
  console.error('   re-running the optimizer may not recreate it — restore the file');
  console.error('   (e.g. from Git) or regenerate that variant directly. Do not clear');
  console.error('   the whole manifest.');
  process.exit(1);
}

console.log(
  `✅ ${originals.length} product original(s) checked under ${scanRoot}; ` +
  `all ${originals.length * REQUIRED_VARIANTS.length} required -400/-240w variants present.`
);
