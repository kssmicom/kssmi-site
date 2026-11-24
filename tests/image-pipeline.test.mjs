import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { projectRoot, ensureTemporaryRoot, createTask } from '../scripts/local-tools.mjs';
import { cardSrc, thumbSrc } from '../src/utils/image.ts';
import {
  DEFAULT_PATHS,
  defaultValidatorPaths,
  moduleDirectory,
} from '../scripts/validate-image-variants.mjs';
import {
  assertFixedPolicy,
  DIMENSION_TOLERANCE_PX,
  dimensionsMatchExpected,
  expectedVariantDimensions,
  orientedDimensions,
  VARIANT_FOLDER_RULES,
  VARIANT_SPECS,
} from '../src/config/image-variants.mjs';

const require = createRequire(import.meta.url);
const sharp = require('sharp');

ensureTemporaryRoot();
const taskRoot = createTask('image-pipeline-tests', projectRoot);
const optimizerScript = path.join(projectRoot, 'scripts/optimize-images.mjs');
const validatorScript = path.join(projectRoot, 'scripts/validate-image-variants.mjs');

let sequence = 0;

function sha256(filePath) {
  return createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function createFixtureDirs() {
  const base = path.join(taskRoot, `case-${++sequence}`);
  const publicDir = path.join(base, 'public');
  const mediaDir = path.join(publicDir, 'media');
  const contentDir = path.join(base, 'content', 'products');
  const manifestPath = path.join(base, '.optimize-manifest.json');
  fs.mkdirSync(mediaDir, { recursive: true });
  return { base, publicDir, mediaDir, contentDir, manifestPath };
}

async function createImage(
  filePath,
  { width, height, format, r = 120, g = 140, b = 160, orientation = null }
) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  let pipeline = sharp({
    create: { width, height, channels: 3, background: { r, g, b } },
  });
  if (orientation !== null) {
    pipeline = pipeline.withMetadata({ orientation });
  }
  if (format === 'webp') pipeline = pipeline.webp({ quality: 90 });
  else if (format === 'jpeg' || format === 'jpg') pipeline = pipeline.jpeg({ quality: 92 });
  else if (format === 'png') pipeline = pipeline.png();
  else throw new Error(`Unsupported synthetic format: ${format}`);
  await pipeline.toFile(filePath);
  return filePath;
}

function writeProductMd(filePath, { cover, gallery = [] }) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  const lines = [
    '---',
    'title: "Test Product"',
    'slug: "test-product"',
    `cover: "${cover}"`,
  ];
  if (gallery.length > 0) {
    lines.push('gallery:');
    for (const item of gallery) lines.push(`  - "${item}"`);
  }
  lines.push('---', '', 'Test body.');
  fs.writeFileSync(filePath, lines.join('\n'), 'utf8');
}

function runOptimizer(args) {
  return spawnSync(process.execPath, [optimizerScript, ...args], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 60000,
  });
}

function runValidator(scanRoot, extraArgs = []) {
  return spawnSync(process.execPath, [validatorScript, scanRoot, ...extraArgs], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 30000,
  });
}

function listAllFiles(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) listAllFiles(full, out);
    else out.push(full);
  }
  return out;
}

test('fixed policy config is frozen and EXIF / ±1px math behaves predictably', () => {
  assert.doesNotThrow(() => assertFixedPolicy());
  assert.equal(Object.isFrozen(VARIANT_SPECS), true);
  assert.equal(Object.isFrozen(VARIANT_SPECS.card), true);
  assert.equal(Object.isFrozen(VARIANT_SPECS.thumb), true);
  assert.equal(Object.isFrozen(VARIANT_FOLDER_RULES), true);
  assert.equal(DIMENSION_TOLERANCE_PX, 1);

  assert.throws(() => {
    VARIANT_SPECS.card.quality = 75;
  }, TypeError);

  assert.deepEqual(orientedDimensions(801, 601, 1), { width: 801, height: 601 });
  assert.deepEqual(orientedDimensions(801, 601, 6), { width: 601, height: 801 });

  const portraitFromExif = expectedVariantDimensions(801, 601, VARIANT_SPECS.card, 6);
  assert.deepEqual(portraitFromExif, { width: 225, height: 300 });
  assert.equal(dimensionsMatchExpected(225, 300, portraitFromExif, VARIANT_SPECS.card).ok, true);
  assert.equal(dimensionsMatchExpected(225, 299, portraitFromExif, VARIANT_SPECS.card).ok, true);
  assert.equal(dimensionsMatchExpected(225, 298, portraitFromExif, VARIANT_SPECS.card).ok, false);
  assert.equal(dimensionsMatchExpected(225, 301, portraitFromExif, VARIANT_SPECS.card).ok, false);
});

test('sidecar generation writes provenance hashes/recipes, preserves originals (SHA-256 unchanged), and respects folder rules', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();

  const sourceFiles = [
    await createImage(path.join(mediaDir, 'products/kso-test/cover-1.webp'), { width: 800, height: 600, format: 'webp' }),
    await createImage(path.join(mediaDir, 'products/kso-test/angle-2.jpg'), { width: 900, height: 700, format: 'jpeg' }),
    await createImage(path.join(mediaDir, 'products/kso-test/detail-3.png'), { width: 1000, height: 800, format: 'png' }),
    await createImage(path.join(mediaDir, 'products/kso-test/small-4.jpeg'), { width: 120, height: 90, format: 'jpeg' }),
    await createImage(path.join(mediaDir, 'products/kso-test/cover-1-120w.webp'), { width: 120, height: 120, format: 'webp' }),
    await createImage(path.join(mediaDir, 'products/kso-test/video-poster.jpg'), { width: 640, height: 360, format: 'jpeg' }),
    await createImage(path.join(mediaDir, 'collection/hero/banner.png'), { width: 1200, height: 800, format: 'png' }),
    await createImage(path.join(mediaDir, 'blogs/post/cover.jpg'), { width: 1200, height: 800, format: 'jpeg' }),
    await createImage(path.join(mediaDir, 'feature/eyewear/hero.webp'), { width: 1200, height: 800, format: 'webp' }),
    await createImage(path.join(mediaDir, 'certifications/ce.png'), { width: 500, height: 500, format: 'png' }),
    await createImage(path.join(mediaDir, 'icon/mark.png'), { width: 80, height: 80, format: 'png' }),
    await createImage(path.join(mediaDir, 'global/logo.jpg'), { width: 500, height: 200, format: 'jpeg' }),
    await createImage(path.join(mediaDir, 'customization/swatch.webp'), { width: 600, height: 600, format: 'webp' }),
  ];

  const hashesBefore = new Map(sourceFiles.map(f => [f, sha256(f)]));

  const run1 = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(run1.status, 0, run1.stderr + run1.stdout);

  for (const [filePath, expectedHash] of hashesBefore.entries()) {
    assert.equal(fs.existsSync(filePath), true, `source missing: ${filePath}`);
    assert.equal(sha256(filePath), expectedHash, `source bytes mutated: ${filePath}`);
  }

  for (const stem of ['cover-1', 'angle-2', 'detail-3']) {
    const cardPath = path.join(mediaDir, `products/kso-test/${stem}-400.webp`);
    const thumbPath = path.join(mediaDir, `products/kso-test/${stem}-240w.webp`);
    const cardMeta = await sharp(cardPath).metadata();
    const thumbMeta = await sharp(thumbPath).metadata();
    assert.equal(cardMeta.format, 'webp');
    assert.ok(cardMeta.width <= 400 && cardMeta.height <= 300);
    assert.equal(thumbMeta.format, 'webp');
    assert.ok(thumbMeta.width <= 240 && thumbMeta.height <= 240);
  }

  const smallCard = await sharp(path.join(mediaDir, 'products/kso-test/small-4-400.webp')).metadata();
  const smallThumb = await sharp(path.join(mediaDir, 'products/kso-test/small-4-240w.webp')).metadata();
  assert.equal(smallCard.width, 120);
  assert.equal(smallCard.height, 90);
  assert.equal(smallThumb.width, 120);
  assert.equal(smallThumb.height, 90);

  assert.equal(fs.existsSync(path.join(mediaDir, 'products/kso-test/cover-1-120w-400.webp')), false);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/kso-test/video-poster-400.webp')), false);

  for (const rel of ['collection/hero/banner', 'blogs/post/cover', 'feature/eyewear/hero']) {
    assert.equal(fs.existsSync(path.join(mediaDir, `${rel}-400.webp`)), true);
    assert.equal(fs.existsSync(path.join(mediaDir, `${rel}-240w.webp`)), false);
  }

  for (const rel of ['certifications/ce', 'icon/mark', 'global/logo', 'customization/swatch']) {
    assert.equal(fs.existsSync(path.join(mediaDir, `${rel}-400.webp`)), false);
    assert.equal(fs.existsSync(path.join(mediaDir, `${rel}-240w.webp`)), false);
  }

  const val = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(val.status, 0, val.stderr + val.stdout);

  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const cardEntry = manifest['media/products/kso-test/angle-2-400.webp'];
  const thumbEntry = manifest['media/products/kso-test/angle-2-240w.webp'];
  assert.ok(cardEntry && thumbEntry);
  assert.equal(cardEntry.sourceHash, sha256(path.join(mediaDir, 'products/kso-test/angle-2.jpg')));
  assert.equal(cardEntry.outputHash, sha256(path.join(mediaDir, 'products/kso-test/angle-2-400.webp')));
  assert.equal(cardEntry.recipe, VARIANT_SPECS.card.recipe);
  assert.equal(thumbEntry.recipe, VARIANT_SPECS.thumb.recipe);
  assert.equal('media/products/kso-test/angle-2.jpg' in manifest, false);
  assert.equal('media/certifications/ce-400.webp' in manifest, false);

  // Second run verifies provenance matches and skips without regenerating
  const run2 = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(run2.status, 0, run2.stderr + run2.stdout);
  assert.match(run2.stdout, /Card variants \(-400\)\s*:\s*0/);
  assert.match(run2.stdout, /Thumb variants \(-240w\)\s*:\s*0/);
});

test('cardSrc and thumbSrc resolve -400.webp and -240w.webp sidecars for .webp, .jpg, .jpeg and .png originals', async () => {
  const { publicDir, mediaDir } = createFixtureDirs();

  for (const [stem, origExt] of [
    ['webp-item', '.webp'],
    ['jpg-item', '.jpg'],
    ['jpeg-item', '.jpeg'],
    ['png-item', '.png'],
    ['Photo-Item', '.JPG'],
  ]) {
    await createImage(path.join(mediaDir, `products/sku-disp/${stem}${origExt}`), { width: 400, height: 300, format: 'png' });
    await createImage(path.join(mediaDir, `products/sku-disp/${stem}-400.webp`), { width: 400, height: 300, format: 'webp' });
    await createImage(path.join(mediaDir, `products/sku-disp/${stem}-240w.webp`), { width: 240, height: 240, format: 'webp' });

    const origUrl = `/media/products/sku-disp/${stem}${origExt}`;
    assert.equal(cardSrc(origUrl, publicDir), `/media/products/sku-disp/${stem}-400.webp`);
    assert.equal(thumbSrc(origUrl, publicDir), `/media/products/sku-disp/${stem}-240w.webp`);
  }

  await createImage(path.join(mediaDir, 'products/sku-disp/no-variant.png'), { width: 200, height: 200, format: 'png' });
  fs.mkdirSync(path.join(mediaDir, 'products/sku-disp/dir-variant-400.webp'), { recursive: true });
  assert.equal(cardSrc('/media/products/sku-disp/no-variant.png', publicDir), '/media/products/sku-disp/no-variant.png');
  assert.equal(thumbSrc('/media/products/sku-disp/no-variant.png', publicDir), '/media/products/sku-disp/no-variant.png');
  assert.equal(cardSrc('/media/products/sku-disp/dir-variant.png', publicDir), '/media/products/sku-disp/dir-variant.png');

  assert.equal(cardSrc('', publicDir), '');
  assert.equal(cardSrc(undefined, publicDir), '');
  assert.equal(cardSrc('/media/products/sku-disp/jpg-item-400.webp', publicDir), '/media/products/sku-disp/jpg-item-400.webp');
  assert.equal(thumbSrc('/media/products/sku-disp/jpg-item-120w.webp', publicDir), '/media/products/sku-disp/jpg-item-120w.webp');
  assert.equal(cardSrc('/media/products/sku-disp/clip-poster.jpg', publicDir), '/media/products/sku-disp/clip-poster.jpg');
});

test('preflight collision detection rejects same-basename, directory, and derived-ext conflicts before any write', async () => {
  const case1 = createFixtureDirs();
  const frameJpg = await createImage(path.join(case1.mediaDir, 'products/bad/frame.jpg'), { width: 500, height: 500, format: 'jpeg' });
  const framePng = await createImage(path.join(case1.mediaDir, 'products/bad/frame.png'), { width: 500, height: 500, format: 'png' });
  const goodPng = await createImage(path.join(case1.mediaDir, 'products/good/ok.png'), { width: 500, height: 500, format: 'png' });
  const before = [frameJpg, framePng, goodPng].map(f => [f, sha256(f)]);

  const res1 = runOptimizer(['--media-dir', case1.mediaDir, '--manifest', case1.manifestPath]);
  assert.equal(res1.status, 1);
  assert.match(res1.stderr, /Same-basename original collision/);
  assert.equal(fs.existsSync(path.join(case1.mediaDir, 'products/good/ok-400.webp')), false);
  assert.equal(fs.existsSync(case1.manifestPath), false);
  for (const [f, h] of before) assert.equal(sha256(f), h);

  await createImage(path.join(case1.mediaDir, 'products/bad/frame-400.webp'), { width: 400, height: 300, format: 'webp' });
  await createImage(path.join(case1.mediaDir, 'products/bad/frame-240w.webp'), { width: 240, height: 240, format: 'webp' });
  const valCol = runValidator(path.join(case1.mediaDir, 'products'), ['--manifest', case1.manifestPath, '--no-content-check']);
  assert.equal(valCol.status, 1);
  assert.match(valCol.stderr, /same-basename image collision/i);

  const case2 = createFixtureDirs();
  await createImage(path.join(case2.mediaDir, 'products/dircol/a.png'), { width: 500, height: 500, format: 'png' });
  fs.mkdirSync(path.join(case2.mediaDir, 'products/dircol/a-400.webp'), { recursive: true });
  const res2 = runOptimizer(['--media-dir', case2.mediaDir, '--manifest', case2.manifestPath]);
  assert.equal(res2.status, 1);
  assert.match(res2.stderr, /non-file entry/);
  assert.equal(fs.existsSync(path.join(case2.mediaDir, 'products/dircol/a-240w.webp')), false);
  assert.equal(fs.existsSync(case2.manifestPath), false);

  const case3 = createFixtureDirs();
  await createImage(path.join(case3.mediaDir, 'products/extcol/a.png'), { width: 500, height: 500, format: 'png' });
  await createImage(path.join(case3.mediaDir, 'products/extcol/a-400.jpg'), { width: 400, height: 300, format: 'jpeg' });
  const res3 = runOptimizer(['--media-dir', case3.mediaDir, '--manifest', case3.manifestPath]);
  assert.equal(res3.status, 1);
  assert.match(res3.stderr, /conflicting extension file/);
  assert.equal(fs.existsSync(path.join(case3.mediaDir, 'products/extcol/a-400.webp')), false);
  assert.equal(fs.existsSync(case3.manifestPath), false);
});

test('corrupt or malformed manifest fails closed without fallback or writes', async () => {
  for (const rawManifest of ['{broken-json', '[]', 'null', '{"media/x.webp":"not-an-object"}']) {
    const { mediaDir, manifestPath } = createFixtureDirs();
    await createImage(path.join(mediaDir, 'products/sku-m/item.png'), { width: 500, height: 500, format: 'png' });
    fs.writeFileSync(manifestPath, rawManifest, 'utf8');

    const res = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /manifest/i);
    assert.equal(fs.existsSync(path.join(mediaDir, 'products/sku-m/item-400.webp')), false);
    assert.equal(fs.readFileSync(manifestPath, 'utf8'), rawManifest);
  }
});

test('mid-run decode failure keeps originals untouched, leaves no partial temp files, and saves valid manifest entries only', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();
  const okJpg = await createImage(path.join(mediaDir, 'products/a/ok.jpg'), { width: 500, height: 500, format: 'jpeg' });
  const corruptJpg = path.join(mediaDir, 'products/b/corrupt.jpg');
  fs.mkdirSync(path.dirname(corruptJpg), { recursive: true });
  fs.writeFileSync(corruptJpg, 'not-a-valid-jpeg-payload', 'utf8');

  fs.writeFileSync(
    manifestPath,
    JSON.stringify({ 'media/prior.webp': { size: 42, date: '2026-01-01T00:00:00.000Z' } }, null, 2),
    'utf8'
  );

  const okHash = sha256(okJpg);
  const corruptHash = sha256(corruptJpg);

  const res = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(res.status, 1);
  assert.equal(sha256(okJpg), okHash);
  assert.equal(sha256(corruptJpg), corruptHash);

  assert.equal(fs.existsSync(path.join(mediaDir, 'products/a/ok-400.webp')), true);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/a/ok-240w.webp')), true);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/b/corrupt-400.webp')), false);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/b/corrupt-240w.webp')), false);

  const allFiles = listAllFiles(path.dirname(manifestPath));
  assert.equal(allFiles.some(f => f.includes('.tmp-')), false);

  const manifestAfter = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  assert.equal(manifestAfter['media/prior.webp'].size, 42);
  assert.equal('media/products/a/ok-400.webp' in manifestAfter, true);
  assert.equal('media/products/b/corrupt-400.webp' in manifestAfter, false);
});

test('scoped --target, --dry-run, --force guard, and validate-image-variants guidance', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();
  const skuA = await createImage(path.join(mediaDir, 'products/sku-a/photo.png'), { width: 600, height: 450, format: 'png' });
  await createImage(path.join(mediaDir, 'products/sku-b/photo.png'), { width: 600, height: 450, format: 'png' });

  const dry = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath, '--target', 'products/sku-a', '--dry-run']);
  assert.equal(dry.status, 0, dry.stderr + dry.stdout);
  assert.match(dry.stdout, /\[dry-run:missing\]/);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/sku-a/photo-400.webp')), false);
  assert.equal(fs.existsSync(manifestPath), false);

  const scoped = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath, '--target', 'products/sku-a']);
  assert.equal(scoped.status, 0, scoped.stderr + scoped.stdout);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/sku-a/photo-400.webp')), true);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/sku-a/photo-240w.webp')), true);
  assert.equal(fs.existsSync(path.join(mediaDir, 'products/sku-b/photo-400.webp')), false);

  const missingGate = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(missingGate.status, 1);
  assert.match(missingGate.stderr, /MISSING_VARIANT/);
  assert.match(missingGate.stderr, /--target products\/<sku>/);

  const unsafeForce = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath, '--force']);
  assert.equal(unsafeForce.status, 1);
  assert.match(unsafeForce.stderr, /--force requires at least one --target/);

  const origHash = sha256(skuA);
  const forced = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath, '--target', 'products/sku-a', '--force']);
  assert.equal(forced.status, 0, forced.stderr + forced.stdout);
  assert.equal(sha256(skuA), origHash);
  assert.match(forced.stdout, /Card variants \(-400\)\s*:\s*1/);
});

test('same-size original replacement triggers STALE_SOURCE_HASH and auto-regenerates provenance', async () => {
  const fx = createFixtureDirs();
  const jpgBuf1 = await sharp({
    create: { width: 600, height: 400, channels: 3, background: { r: 20, g: 40, b: 80 } },
  })
    .jpeg({ quality: 90 })
    .toBuffer();
  const jpgBuf2Raw = await sharp({
    create: { width: 600, height: 400, channels: 3, background: { r: 200, g: 50, b: 30 } },
  })
    .jpeg({ quality: 90 })
    .toBuffer();

  const maxLen = Math.max(jpgBuf1.length, jpgBuf2Raw.length);
  const padJpeg = (buf, targetLen) => {
    if (buf.length === targetLen) return buf;
    // Append a JPEG COM marker (0xFF 0xFE len_hi len_lo ...bytes) right before EOI (0xFF 0xD9)
    const diff = targetLen - buf.length;
    assert.ok(diff >= 4, 'COM marker requires >= 4 bytes padding');
    const com = Buffer.alloc(diff);
    com[0] = 0xff;
    com[1] = 0xfe;
    com.writeUInt16BE(diff - 2, 2);
    com.fill(0x41, 4);
    return Buffer.concat([buf.subarray(0, buf.length - 2), com, buf.subarray(buf.length - 2)]);
  };

  const targetJpg = path.join(fx.mediaDir, 'products/sku-hash/same-size.jpg');
  fs.mkdirSync(path.dirname(targetJpg), { recursive: true });
  fs.writeFileSync(targetJpg, padJpeg(jpgBuf1, maxLen + 8));

  assert.equal(runOptimizer(['--media-dir', fx.mediaDir, '--manifest', fx.manifestPath]).status, 0);
  const validBefore = runValidator(path.join(fx.mediaDir, 'products'), [
    '--manifest',
    fx.manifestPath,
    '--no-content-check',
  ]);
  assert.equal(validBefore.status, 0, validBefore.stderr + validBefore.stdout);

  const hash1 = sha256(targetJpg);
  const size1 = fs.statSync(targetJpg).size;

  // Replace original with DIFFERENT pixels at IDENTICAL byte size
  fs.writeFileSync(targetJpg, padJpeg(jpgBuf2Raw, size1));
  assert.equal(fs.statSync(targetJpg).size, size1);
  assert.notEqual(sha256(targetJpg), hash1);

  const staleVal = runValidator(path.join(fx.mediaDir, 'products'), [
    '--manifest',
    fx.manifestPath,
    '--no-content-check',
  ]);
  assert.equal(staleVal.status, 1);
  assert.match(staleVal.stderr, /STALE_SOURCE_HASH/);

  // Re-running optimize-images without --force detects stale sourceHash and regenerates cleanly
  const refresh = runOptimizer(['--media-dir', fx.mediaDir, '--manifest', fx.manifestPath]);
  assert.equal(refresh.status, 0, refresh.stderr + refresh.stdout);
  assert.match(refresh.stdout, /Card variants \(-400\)\s*:\s*1/);
  assert.match(refresh.stdout, /Thumb variants \(-240w\)\s*:\s*1/);

  const validAfter = runValidator(path.join(fx.mediaDir, 'products'), [
    '--manifest',
    fx.manifestPath,
    '--no-content-check',
  ]);
  assert.equal(validAfter.status, 0, validAfter.stderr + validAfter.stdout);
});

test('tampered derivative triggers OUTPUT_HASH_MISMATCH; fake WebP triggers WRONG_FORMAT; bad sizes trigger DIMENSION_MISMATCH', async () => {
  // 1. Tampered derivative with valid WebP header & identical dimensions -> OUTPUT_HASH_MISMATCH
  const fx1 = createFixtureDirs();
  await createImage(path.join(fx1.mediaDir, 'products/sku-tamper/pic.png'), { width: 600, height: 600, format: 'png' });
  assert.equal(runOptimizer(['--media-dir', fx1.mediaDir, '--manifest', fx1.manifestPath]).status, 0);

  // Overwrite -240w.webp with a DIFFERENT 240x240 WebP (different color)
  await createImage(path.join(fx1.mediaDir, 'products/sku-tamper/pic-240w.webp'), {
    width: 240,
    height: 240,
    format: 'webp',
    r: 255,
    g: 10,
    b: 10,
  });
  const tamperVal = runValidator(path.join(fx1.mediaDir, 'products'), ['--manifest', fx1.manifestPath, '--no-content-check']);
  assert.equal(tamperVal.status, 1);
  assert.match(tamperVal.stderr, /OUTPUT_HASH_MISMATCH/);

  // Optimizer repairs tampered file automatically
  assert.equal(runOptimizer(['--media-dir', fx1.mediaDir, '--manifest', fx1.manifestPath]).status, 0);
  assert.equal(runValidator(path.join(fx1.mediaDir, 'products'), ['--manifest', fx1.manifestPath, '--no-content-check']).status, 0);

  // 2. Fake WebP (PNG encoded bytes saved as .webp) -> WRONG_FORMAT
  const fx2 = createFixtureDirs();
  await createImage(path.join(fx2.mediaDir, 'products/sku-fake/pic.png'), { width: 800, height: 600, format: 'png' });
  assert.equal(runOptimizer(['--media-dir', fx2.mediaDir, '--manifest', fx2.manifestPath]).status, 0);
  const pngBytes = await sharp({ create: { width: 400, height: 300, channels: 3, background: { r: 1, g: 2, b: 3 } } })
    .png()
    .toBuffer();
  fs.writeFileSync(path.join(fx2.mediaDir, 'products/sku-fake/pic-400.webp'), pngBytes);
  const fakeVal = runValidator(path.join(fx2.mediaDir, 'products'), ['--manifest', fx2.manifestPath, '--no-content-check']);
  assert.equal(fakeVal.status, 1);
  assert.match(fakeVal.stderr, /WRONG_FORMAT/);

  // 3. Pixel out-of-bounds (401x300) AND aspect ratio mismatch (400x300 when original is 800x400 -> expected 400x200) -> DIMENSION_MISMATCH
  const fx3 = createFixtureDirs();
  await createImage(path.join(fx3.mediaDir, 'products/sku-dim/wide.png'), { width: 800, height: 400, format: 'png' });
  assert.equal(runOptimizer(['--media-dir', fx3.mediaDir, '--manifest', fx3.manifestPath]).status, 0);

  await createImage(path.join(fx3.mediaDir, 'products/sku-dim/wide-400.webp'), { width: 401, height: 200, format: 'webp' });
  const oobVal = runValidator(path.join(fx3.mediaDir, 'products'), ['--manifest', fx3.manifestPath, '--no-content-check']);
  assert.equal(oobVal.status, 1);
  assert.match(oobVal.stderr, /DIMENSION_MISMATCH/);

  await createImage(path.join(fx3.mediaDir, 'products/sku-dim/wide-400.webp'), { width: 400, height: 300, format: 'webp' });
  const ratioVal = runValidator(path.join(fx3.mediaDir, 'products'), ['--manifest', fx3.manifestPath, '--no-content-check']);
  assert.equal(ratioVal.status, 1);
  assert.match(ratioVal.stderr, /DIMENSION_MISMATCH/);

  // Unknown CLI flag is rejected cleanly
  const badFlag = runValidator(path.join(fx3.mediaDir, 'products'), ['--unknown-flag']);
  assert.equal(badFlag.status, 1);
  assert.match(badFlag.stderr, /Unknown option/);
});

test('EXIF orientation 6 and odd dimensions 801x601 optimize accurately and pass ±1px rounding checks', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();
  const rotatedJpg = path.join(mediaDir, 'products/sku-exif/rotated.jpg');
  const oddPng = path.join(mediaDir, 'products/sku-exif/odd.png');

  // 801x601 JPEG with EXIF orientation 6 rotates to effective 601x801 portrait -> card 225x300, thumb 180x240
  await createImage(rotatedJpg, { width: 801, height: 601, format: 'jpeg', orientation: 6 });
  await createImage(oddPng, { width: 801, height: 601, format: 'png' });

  const opt = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(opt.status, 0, opt.stderr + opt.stdout);

  const rotCardMeta = await sharp(path.join(mediaDir, 'products/sku-exif/rotated-400.webp')).metadata();
  const rotThumbMeta = await sharp(path.join(mediaDir, 'products/sku-exif/rotated-240w.webp')).metadata();
  assert.equal(rotCardMeta.orientation, undefined);
  assert.ok(rotCardMeta.width <= 226 && rotCardMeta.width >= 224 && rotCardMeta.height === 300);
  assert.ok(rotThumbMeta.width <= 181 && rotThumbMeta.width >= 179 && rotThumbMeta.height === 240);

  const val = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(val.status, 0, val.stderr + val.stdout);
});

test('RECIPE_MISMATCH fails gate and automatically refreshes on next optimize-images run', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();
  await createImage(path.join(mediaDir, 'products/sku-recipe/item.png'), { width: 800, height: 600, format: 'png' });
  assert.equal(runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]).status, 0);

  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  manifest['media/products/sku-recipe/item-400.webp'].recipe = 'old-v0:card:400x300:q75';
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2), 'utf8');

  const valFail = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(valFail.status, 1);
  assert.match(valFail.stderr, /RECIPE_MISMATCH/);

  const refresh = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(refresh.status, 0, refresh.stderr + refresh.stdout);
  assert.match(refresh.stdout, /Card variants \(-400\)\s*:\s*1/);

  const valPass = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(valPass.status, 0, valPass.stderr + valPass.stdout);
});

test('unverified legacy files (missing or {size,date} manifest) fail gate, are protected without --force, and pass after --target --force', async () => {
  const { mediaDir, manifestPath } = createFixtureDirs();
  const orig = path.join(mediaDir, 'products/sku-legacy/item.png');
  const card = path.join(mediaDir, 'products/sku-legacy/item-400.webp');
  const thumb = path.join(mediaDir, 'products/sku-legacy/item-240w.webp');

  await createImage(orig, { width: 800, height: 600, format: 'png' });
  await createImage(card, { width: 400, height: 300, format: 'webp', r: 10, g: 10, b: 10 });
  await createImage(thumb, { width: 240, height: 180, format: 'webp', r: 10, g: 10, b: 10 });

  // Card has legacy {size, date} record; thumb has NO record at all
  fs.writeFileSync(
    manifestPath,
    JSON.stringify(
      {
        'media/products/sku-legacy/item-400.webp': {
          size: fs.statSync(card).size,
          date: '2026-01-01T00:00:00.000Z',
        },
      },
      null,
      2
    ),
    'utf8'
  );

  const origHash = sha256(orig);
  const legacyCardHash = sha256(card);
  const legacyThumbHash = sha256(thumb);

  const valBefore = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(valBefore.status, 1);
  assert.match(valBefore.stderr, /UNVERIFIED_LEGACY_MANIFEST/);
  assert.match(valBefore.stderr, /UNVERIFIED_NO_MANIFEST_RECORD/);

  // Running optimizer WITHOUT --force must NOT overwrite files or forge hashes
  const unforced = runOptimizer(['--media-dir', mediaDir, '--manifest', manifestPath]);
  assert.equal(unforced.status, 0, unforced.stderr + unforced.stdout);
  assert.equal(sha256(card), legacyCardHash);
  assert.equal(sha256(thumb), legacyThumbHash);
  const manifestAfterUnforced = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  assert.equal('sourceHash' in manifestAfterUnforced['media/products/sku-legacy/item-400.webp'], false);
  assert.equal('media/products/sku-legacy/item-240w.webp' in manifestAfterUnforced, false);

  // Explicit --target --force regenerates cleanly with provenance while keeping original untouched
  const forced = runOptimizer([
    '--media-dir',
    mediaDir,
    '--manifest',
    manifestPath,
    '--target',
    'products/sku-legacy',
    '--force',
  ]);
  assert.equal(forced.status, 0, forced.stderr + forced.stdout);
  assert.equal(sha256(orig), origHash);

  const valAfter = runValidator(path.join(mediaDir, 'products'), ['--manifest', manifestPath, '--no-content-check']);
  assert.equal(valAfter.status, 0, valAfter.stderr + valAfter.stdout);
});

test('exact case-sensitive disk path and product Markdown reference checks + inventory JSON export', async () => {
  // 1. Disk derivative case mismatch caught even on case-insensitive macOS APFS
  const fxDiskCase = createFixtureDirs();
  await createImage(path.join(fxDiskCase.mediaDir, 'products/sku-case/Main.png'), {
    width: 800,
    height: 600,
    format: 'png',
  });
  assert.equal(runOptimizer(['--media-dir', fxDiskCase.mediaDir, '--manifest', fxDiskCase.manifestPath]).status, 0);

  const exactCard = path.join(fxDiskCase.mediaDir, 'products/sku-case/Main-400.webp');
  const tmpCard = path.join(fxDiskCase.mediaDir, 'products/sku-case/Main-400.tmp');
  const wrongCaseCard = path.join(fxDiskCase.mediaDir, 'products/sku-case/main-400.webp');
  fs.renameSync(exactCard, tmpCard);
  fs.renameSync(tmpCard, wrongCaseCard);

  const diskCaseVal = runValidator(path.join(fxDiskCase.mediaDir, 'products'), [
    '--manifest',
    fxDiskCase.manifestPath,
    '--no-content-check',
  ]);
  assert.equal(diskCaseVal.status, 1);
  assert.match(diskCaseVal.stderr, /CASE_MISMATCH/);

  // 2. Product Markdown cover/gallery exact-case & non-derived validation
  const fxContent = createFixtureDirs();
  await createImage(path.join(fxContent.mediaDir, 'products/sku-md/Hero.png'), {
    width: 800,
    height: 600,
    format: 'png',
  });
  assert.equal(runOptimizer(['--media-dir', fxContent.mediaDir, '--manifest', fxContent.manifestPath]).status, 0);

  const mdFile = path.join(fxContent.contentDir, 'en/sku-md.md');
  writeProductMd(mdFile, {
    cover: '/media/products/sku-md/Hero.png',
    gallery: ['/media/products/sku-md/Hero.png'],
  });
  const inventoryFile = path.join(fxContent.base, 'inventory.json');
  const mdPass = runValidator(path.join(fxContent.mediaDir, 'products'), [
    '--manifest',
    fxContent.manifestPath,
    '--products-content',
    fxContent.contentDir,
    '--inventory-out',
    inventoryFile,
  ]);
  assert.equal(mdPass.status, 0, mdPass.stderr + mdPass.stdout);
  const inv = JSON.parse(fs.readFileSync(inventoryFile, 'utf8'));
  assert.equal(inv.summary.originalsCount, 1);
  assert.equal(inv.summary.verifiedProvenanceCount, 2);
  assert.equal(inv.summary.contentRefsChecked, 2);
  assert.equal(inv.summary.issueCount, 0);

  // Markdown case mismatch -> CONTENT_CASE_MISMATCH
  writeProductMd(mdFile, {
    cover: '/media/products/sku-md/hero.png',
  });
  const mdCaseFail = runValidator(path.join(fxContent.mediaDir, 'products'), [
    '--manifest',
    fxContent.manifestPath,
    '--products-content',
    fxContent.contentDir,
  ]);
  assert.equal(mdCaseFail.status, 1);
  assert.match(mdCaseFail.stderr, /CONTENT_CASE_MISMATCH/);

  // Markdown pointing directly to derived -400.webp -> DERIVED_REF_IN_CONTENT
  writeProductMd(mdFile, {
    cover: '/media/products/sku-md/Hero-400.webp',
  });
  const mdDerivedFail = runValidator(path.join(fxContent.mediaDir, 'products'), [
    '--manifest',
    fxContent.manifestPath,
    '--products-content',
    fxContent.contentDir,
  ]);
  assert.equal(mdDerivedFail.status, 1);
  assert.match(mdDerivedFail.stderr, /DERIVED_REF_IN_CONTENT/);
});

test('validator locates itself through fileURLToPath and defaults to this repository', () => {
  const scriptPath = fileURLToPath(new URL('../scripts/validate-image-variants.mjs', import.meta.url));
  assert.equal(moduleDirectory(pathToFileURL(scriptPath).href), path.dirname(scriptPath));

  assert.deepEqual(DEFAULT_PATHS, {
    scanRoot: path.join(projectRoot, 'public/media/products'),
    mediaRoot: path.join(projectRoot, 'public/media'),
    manifestPath: path.join(projectRoot, 'scripts/.optimize-manifest.json'),
    productsContentDir: path.join(projectRoot, 'src/content/products'),
  });
  for (const target of Object.values(DEFAULT_PATHS)) {
    assert.equal(fs.existsSync(target), true, `default path does not exist: ${target}`);
  }

  // The drive-letter bug only shows on Windows, so on POSIX hosts the module
  // wiring is guarded statically: the module URL must be converted by
  // fileURLToPath and never by reading the URL pathname.
  const source = fs.readFileSync(validatorScript, 'utf8');
  assert.match(source, /export function moduleDirectory\(moduleUrl\) \{\s*return dirname\(fileURLToPath\(moduleUrl\)\);\s*\}/);
  assert.match(source, /moduleDirectory\(import\.meta\.url\)/);
  assert.doesNotMatch(source, /\.pathname|decodeURIComponent/);
});

test('module URLs with spaces and reserved characters resolve to the real directory', () => {
  const { base } = createFixtureDirs();
  const scriptDir = path.join(base, 'KS site 100% #1', 'scripts');
  const scriptPath = path.join(scriptDir, 'validate-image-variants.mjs');
  fs.mkdirSync(scriptDir, { recursive: true });
  fs.writeFileSync(scriptPath, '// path fixture only\n', 'utf8');

  // pathToFileURL percent-encodes the name, so the conversion has to decode it back.
  const moduleUrl = pathToFileURL(scriptPath).href;
  assert.match(moduleUrl, /KS%20site%20100%25%20%231\/scripts\/validate-image-variants\.mjs$/);

  const resolvedDir = moduleDirectory(moduleUrl);
  assert.equal(resolvedDir, scriptDir);
  assert.equal(fs.statSync(path.join(resolvedDir, 'validate-image-variants.mjs')).isFile(), true);

  assert.deepEqual(defaultValidatorPaths(resolvedDir), {
    scanRoot: path.resolve(scriptDir, '../public/media/products'),
    mediaRoot: path.resolve(scriptDir, '../public/media'),
    manifestPath: path.resolve(scriptDir, '.optimize-manifest.json'),
    productsContentDir: path.resolve(scriptDir, '../src/content/products'),
  });
});

test('Windows drive-letter path logic, simulated with path.win32 (not a Windows build)', () => {
  // fileURLToPath picks its rules from the host platform, so the URL-to-path
  // half of the Windows conversion cannot run here. Only the path arithmetic
  // that follows it is simulated, starting from the directory fileURLToPath
  // returns on Windows for file:///D:/.../validate-image-variants.mjs.
  const repository = 'D:\\Desk\\Desk\\Tools\\KS\\Kssmi\\kssmi-site';
  const windowsDefaults = defaultValidatorPaths(`${repository}\\scripts`, path.win32);
  assert.deepEqual(windowsDefaults, {
    scanRoot: `${repository}\\public\\media\\products`,
    mediaRoot: `${repository}\\public\\media`,
    manifestPath: `${repository}\\scripts\\.optimize-manifest.json`,
    productsContentDir: `${repository}\\src\\content\\products`,
  });

  // The pathname of a drive-letter file URL keeps a leading slash before "D:".
  const fileUrl = 'file:///D:/Desk/Desk/Tools/KS/Kssmi/kssmi-site/scripts/validate-image-variants.mjs';
  assert.equal(new URL(fileUrl).pathname, '/D:/Desk/Desk/Tools/KS/Kssmi/kssmi-site/scripts/validate-image-variants.mjs');

  // Decoding that pathname by hand (the removed conversion) hands the validator
  // a directory without the D: drive, and under the Windows path rules its
  // defaults no longer lead into the repository.
  const driveLessDir = path.win32.dirname(decodeURIComponent(new URL(fileUrl).pathname));
  const driveLessDefaults = defaultValidatorPaths(driveLessDir, path.win32);
  assert.equal(windowsDefaults.scanRoot.startsWith(`${repository}\\`), true);
  assert.equal(
    driveLessDefaults.scanRoot.startsWith(`${repository}\\`),
    false,
    `drive-less scan root must not lead into the repository, got ${driveLessDefaults.scanRoot}`
  );
});
