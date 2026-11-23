#!/usr/bin/env node
/**
 * optimize-images.mjs
 *
 * Generates sidecar optimized WebP derivatives without modifying, re-encoding,
 * or deleting original image files.
 *
 * IMAGE STRATEGY:
 *   - products/:                    original (read-only) + -400.webp (card) + -240w.webp (thumbnail)
 *   - collection/, blogs/, feature/: original (read-only) + -400.webp (card)
 *   - certifications/, icon/, global/, customization/, others: untouched (no variants)
 *
 * SAFETY GUARANTEES:
 *   - Original .webp/.jpg/.jpeg/.png files are strictly read-only and never deleted.
 *   - Derived -400/-120w/-240w and -poster files are excluded from original discovery;
 *     legacy -120w.webp thumbnails are preserved untouched on disk.
 *   - Corrupt or unreadable manifest files fail immediately (never reset to {}).
 *   - Same-basename multi-extension collisions (e.g. a.jpg + a.png) and output
 *     collisions fail preflight before any file or manifest write occurs.
 *   - Derivatives and scripts/.optimize-manifest.json are written atomically.
 *
 * Variant settings (WebP, fit: 'inside', withoutEnlargement: true, effort: 5):
 *   - Card  (-400):  400x300, quality 85
 *   - Thumb (-240w): 240x240, quality 80
 *
 * Usage:
 *   node scripts/optimize-images.mjs --target products/<sku> --dry-run
 *   node scripts/optimize-images.mjs --target products/<sku>
 *   npm run validate:image-variants
 */

import { lstat, mkdir, readdir, readFile, rename, unlink, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { basename, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { createHash, randomBytes } from 'node:crypto';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  VARIANT_SPECS,
  IMAGE_EXTENSION_RE as IMAGE_EXTENSION,
  DERIVED_NAME_RE as DERIVED_NAME,
  POSTER_NAME_RE as POSTER_NAME,
  assertFixedPolicy,
  isVariantFolder,
  variantsForRelativePath,
  hasProvenanceFields,
  manifestEntryMatches,
  buildManifestEntry,
} from '../src/config/image-variants.mjs';

export { VARIANT_SPECS };

const __dirname = dirname(fileURLToPath(import.meta.url));
const DEFAULT_MEDIA_DIR = resolve(__dirname, '../public/media');
const DEFAULT_MANIFEST_FILE = resolve(__dirname, '.optimize-manifest.json');

let cachedSharp = null;
function loadSharp() {
  if (cachedSharp) return cachedSharp;
  const require = createRequire(import.meta.url);
  try {
    cachedSharp = require('sharp');
    return cachedSharp;
  } catch {
    try {
      cachedSharp = require(resolve(__dirname, '../node_modules/sharp/lib/index.js'));
      return cachedSharp;
    } catch {
      throw new Error(
        'Could not load sharp. Ensure dependencies are installed before generating image variants.'
      );
    }
  }
}

function sha256Buffer(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function escapeRegExp(text) {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function formatKB(bytes) {
  return `${(bytes / 1024).toFixed(1)} KB`;
}

function withinOrEqual(rootDir, targetPath) {
  const rel = relative(rootDir, targetPath);
  return rel === '' || (rel !== '..' && !rel.startsWith(`..${sep}`) && !isAbsolute(rel));
}

export async function loadManifest(manifestPath = DEFAULT_MANIFEST_FILE) {
  let st;
  try {
    st = await lstat(manifestPath);
  } catch (err) {
    if (err && err.code === 'ENOENT') {
      return {};
    }
    throw new Error(`Failed to inspect image manifest ${manifestPath}: ${err.message}`);
  }

  if (st.isSymbolicLink() || !st.isFile()) {
    throw new Error(`Image manifest must be a regular file (not symlink/directory): ${manifestPath}`);
  }

  let raw;
  try {
    raw = await readFile(manifestPath, 'utf8');
  } catch (err) {
    throw new Error(`Failed to read image manifest ${manifestPath}: ${err.message}`);
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    throw new Error(`Corrupt image manifest ${manifestPath}: ${err.message}`);
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error(`Invalid image manifest structure in ${manifestPath}: root must be a JSON object`);
  }

  for (const [key, value] of Object.entries(parsed)) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new Error(`Invalid image manifest entry for "${key}" in ${manifestPath}`);
    }
  }

  return parsed;
}

async function writeFileAtomic(targetPath, data) {
  await mkdir(dirname(targetPath), { recursive: true });
  const tmpPath = `${targetPath}.tmp-${process.pid}-${randomBytes(6).toString('hex')}`;
  try {
    await writeFile(tmpPath, data);
    await rename(tmpPath, targetPath);
  } catch (err) {
    await unlink(tmpPath).catch(() => {});
    throw err;
  }
}

async function assertNoSymlinksAlong(rootDir, targetPath) {
  let cursor = resolve(targetPath);
  const boundary = resolve(rootDir);
  while (true) {
    if (existsSync(cursor)) {
      const st = await lstat(cursor);
      if (st.isSymbolicLink()) {
        throw new Error(`Symlink is not allowed in media tree: ${cursor}`);
      }
    }
    if (cursor === boundary) break;
    const parent = dirname(cursor);
    if (parent === cursor) break;
    cursor = parent;
  }
}

async function resolveTargetPath(mediaDir, rawTarget) {
  let candidate;
  if (isAbsolute(rawTarget)) {
    candidate = resolve(rawTarget);
  } else {
    const underMedia = resolve(mediaDir, rawTarget);
    if (existsSync(underMedia)) {
      candidate = underMedia;
    } else {
      const fromCwd = resolve(rawTarget);
      candidate = withinOrEqual(mediaDir, fromCwd) && existsSync(fromCwd) ? fromCwd : underMedia;
    }
  }

  if (!withinOrEqual(mediaDir, candidate)) {
    throw new Error(`Target path escapes media directory: ${rawTarget}`);
  }
  await assertNoSymlinksAlong(mediaDir, candidate);
  return candidate;
}

async function inspectDirectoryEntries(dirPath, mediaDir, state) {
  if (state.inspectedDirs.has(dirPath)) return state.inspectedDirs.get(dirPath);

  let dirEntries;
  try {
    dirEntries = await readdir(dirPath, { withFileTypes: true });
  } catch (err) {
    state.errors.push(`Failed to read media directory ${dirPath}: ${err.message}`);
    const empty = [];
    state.inspectedDirs.set(dirPath, empty);
    return empty;
  }

  dirEntries.sort((a, b) => a.name.localeCompare(b.name));
  const records = [];

  for (const dirent of dirEntries) {
    const fullPath = join(dirPath, dirent.name);
    let st;
    try {
      st = await lstat(fullPath);
    } catch (err) {
      state.errors.push(`Failed to inspect ${fullPath}: ${err.message}`);
      continue;
    }

    if (st.isSymbolicLink()) {
      state.errors.push(`Symlink is not allowed in media tree: ${fullPath}`);
      continue;
    }

    const relFromMedia = relative(mediaDir, fullPath).replace(/\\/g, '/');
    records.push({
      name: dirent.name,
      path: fullPath,
      dir: dirPath,
      relFromMedia,
      isDirectory: st.isDirectory(),
      isFile: st.isFile(),
    });
  }

  state.inspectedDirs.set(dirPath, records);
  return records;
}

async function scanScope(mediaDir, targets) {
  const state = {
    inspectedDirs: new Map(),
    targetedOriginals: [],
    targetedOriginalKeys: new Set(),
    errors: [],
  };

  let rootStat;
  try {
    rootStat = await lstat(mediaDir);
  } catch (err) {
    state.errors.push(`Cannot access media directory ${mediaDir}: ${err.message}`);
    return state;
  }
  if (rootStat.isSymbolicLink() || !rootStat.isDirectory()) {
    state.errors.push(`Media directory must be a regular directory: ${mediaDir}`);
    return state;
  }

  const resolvedTargets = [];
  if (!targets || targets.length === 0) {
    resolvedTargets.push(mediaDir);
  } else {
    for (const rawTarget of targets) {
      try {
        const targetPath = await resolveTargetPath(mediaDir, rawTarget);
        let st;
        try {
          st = await lstat(targetPath);
        } catch (err) {
          state.errors.push(`Target path not found: ${rawTarget} (${err.message})`);
          continue;
        }
        resolvedTargets.push({ path: targetPath, stat: st });
      } catch (err) {
        state.errors.push(err.message);
      }
    }
  }

  if (state.errors.length > 0 && (!targets || targets.length > 0)) {
    if (resolvedTargets.length === 0) return state;
  }

  async function walkDir(dirPath) {
    const entries = await inspectDirectoryEntries(dirPath, mediaDir, state);
    for (const entry of entries) {
      if (entry.isDirectory) {
        await walkDir(entry.path);
      } else if (
        entry.isFile &&
        IMAGE_EXTENSION.test(entry.name) &&
        !DERIVED_NAME.test(entry.name) &&
        !POSTER_NAME.test(entry.name)
      ) {
        const lowerKey = entry.path.toLowerCase();
        if (!state.targetedOriginalKeys.has(lowerKey)) {
          state.targetedOriginalKeys.add(lowerKey);
          state.targetedOriginals.push(entry);
        }
      }
    }
  }

  if (!targets || targets.length === 0) {
    await walkDir(mediaDir);
  } else {
    for (const target of resolvedTargets) {
      if (target.stat.isDirectory()) {
        await walkDir(target.path);
      } else if (target.stat.isFile()) {
        await inspectDirectoryEntries(dirname(target.path), mediaDir, state);
        const relFromMedia = relative(mediaDir, target.path).replace(/\\/g, '/');
        const name = basename(target.path);
        if (!IMAGE_EXTENSION.test(name) || DERIVED_NAME.test(name) || POSTER_NAME.test(name)) {
          state.errors.push(`Target file is not an eligible original image: ${relFromMedia}`);
          continue;
        }
        const lowerKey = target.path.toLowerCase();
        if (!state.targetedOriginalKeys.has(lowerKey)) {
          state.targetedOriginalKeys.add(lowerKey);
          state.targetedOriginals.push({
            name,
            path: target.path,
            dir: dirname(target.path),
            relFromMedia,
            isDirectory: false,
            isFile: true,
          });
        }
      } else {
        state.errors.push(`Target path is neither a regular file nor directory: ${target.path}`);
      }
    }
  }

  return state;
}

function runPreflightChecks(mediaDir, scanState) {
  const errors = [...scanState.errors];

  // Check same-basename multi-extension collisions in every inspected directory
  // that resides under a variant-enabled top-level folder.
  for (const [dirPath, entries] of scanState.inspectedDirs.entries()) {
    const dirRel = relative(mediaDir, dirPath).replace(/\\/g, '/');
    if (dirRel && !isVariantFolder(dirRel)) continue;

    const stems = new Map();
    for (const entry of entries) {
      if (!entry.isFile) continue;
      if (!IMAGE_EXTENSION.test(entry.name)) continue;
      if (DERIVED_NAME.test(entry.name) || POSTER_NAME.test(entry.name)) continue;
      if (!isVariantFolder(entry.relFromMedia)) continue;

      const stemLower = entry.name.replace(IMAGE_EXTENSION, '').toLowerCase();
      if (!stems.has(stemLower)) stems.set(stemLower, []);
      stems.get(stemLower).push(entry);
    }

    for (const [, group] of stems.entries()) {
      if (group.length > 1) {
        const list = group.map(item => item.relFromMedia).join(', ');
        errors.push(`Same-basename original collision (multiple extensions/casings map to identical variants): ${list}`);
      }
    }
  }

  const plannedOutputs = new Map();
  const plan = [];

  for (const original of scanState.targetedOriginals) {
    const specs = variantsForRelativePath(original.relFromMedia);
    if (specs.length === 0) continue;

    const dirEntries = scanState.inspectedDirs.get(original.dir) || [];
    const baseStem = original.name.replace(IMAGE_EXTENSION, '');
    const originalLowerSet = new Set(
      dirEntries
        .filter(e => e.isFile && IMAGE_EXTENSION.test(e.name) && !DERIVED_NAME.test(e.name) && !POSTER_NAME.test(e.name))
        .map(e => e.path.toLowerCase())
    );

    for (const spec of specs) {
      const outName = `${baseStem}${spec.suffix}.webp`;
      const outPath = join(original.dir, outName);
      const outLower = outPath.toLowerCase();
      const outRel = relative(mediaDir, outPath).replace(/\\/g, '/');
      const outKey = `media/${outRel}`;

      if (originalLowerSet.has(outLower)) {
        errors.push(`Planned variant path collides with original image: ${outRel}`);
        continue;
      }

      const conflictingPattern = new RegExp(`^${escapeRegExp(baseStem + spec.suffix)}\\.[^.]+$`, 'i');
      for (const existing of dirEntries) {
        if (existing.path.toLowerCase() === outLower) {
          if (!existing.isFile) {
            errors.push(`Planned variant path is blocked by non-file entry: ${outRel}`);
          } else if (existing.name !== outName) {
            errors.push(`Planned variant path collides with differently cased file: ${existing.relFromMedia} vs ${outRel}`);
          }
        } else if (conflictingPattern.test(existing.name)) {
          errors.push(`Planned variant path collides with conflicting extension file: ${existing.relFromMedia} vs ${outRel}`);
        }
      }

      if (plannedOutputs.has(outLower)) {
        errors.push(`Duplicate planned variant output ${outRel} from ${plannedOutputs.get(outLower)} and ${original.relFromMedia}`);
        continue;
      }
      plannedOutputs.set(outLower, original.relFromMedia);
      plan.push({ original, spec, outName, outPath, outKey, outRel });
    }
  }

  return { errors, plan };
}

async function generateVariant(sharp, sourcePath, outPath, spec) {
  const inputBuffer = await readFile(sourcePath);
  const sourceHash = sha256Buffer(inputBuffer);
  const outputBuffer = await sharp(inputBuffer)
    .rotate()
    .resize(spec.width, spec.height, {
      fit: spec.fit,
      withoutEnlargement: spec.withoutEnlargement,
    })
    .webp({ quality: spec.quality, effort: spec.effort })
    .toBuffer();
  const outputHash = sha256Buffer(outputBuffer);
  await writeFileAtomic(outPath, outputBuffer);
  return {
    sourceHash,
    outputHash,
    recipe: spec.recipe,
    size: outputBuffer.length,
  };
}

export async function runImagePipeline({
  mediaDir = DEFAULT_MEDIA_DIR,
  manifestPath = DEFAULT_MANIFEST_FILE,
  targets = [],
  dryRun = false,
  force = false,
  quiet = false,
} = {}) {
  assertFixedPolicy();

  const log = quiet ? () => {} : console.log;
  const logError = quiet ? () => {} : console.error;
  const resolvedMediaDir = resolve(mediaDir);
  const resolvedManifestPath = resolve(manifestPath);

  if (force && (!targets || targets.length === 0)) {
    throw new Error('--force requires at least one --target so formal derivatives are never batch-overwritten.');
  }

  // Validate manifest before any scanning or writing; corrupt JSON fails closed.
  const manifest = await loadManifest(resolvedManifestPath);

  log(`\n🔍  Scanning ${targets.length ? `targeted media (${targets.join(', ')})` : 'media'} under ${resolvedMediaDir} (originals strictly read-only)…`);
  const scanState = await scanScope(resolvedMediaDir, targets);
  const preflight = runPreflightChecks(resolvedMediaDir, scanState);

  if (preflight.errors.length > 0) {
    logError('\n❌  Image pipeline preflight failed; no files or manifest were modified:');
    for (const msg of preflight.errors) {
      logError(`   - ${msg}`);
    }
    return {
      ok: false,
      dryRun,
      originalsScanned: scanState.targetedOriginals.length,
      plannedCount: 0,
      generatedCount: 0,
      regeneratedCount: 0,
      cardGenCount: 0,
      thumbGenCount: 0,
      skippedCount: 0,
      unverifiedSkippedCount: 0,
      unverifiedItems: [],
      errorCount: preflight.errors.length,
      errors: preflight.errors,
    };
  }

  const worklist = [];
  const unverifiedItems = [];
  const sourceHashCache = new Map();
  let skippedCount = 0;
  let regeneratedCount = 0;

  async function getSourceHash(sourcePath) {
    if (!sourceHashCache.has(sourcePath)) {
      const buf = await readFile(sourcePath);
      sourceHashCache.set(sourcePath, sha256Buffer(buf));
    }
    return sourceHashCache.get(sourcePath);
  }

  for (const item of preflight.plan) {
    const dirEntries = scanState.inspectedDirs.get(item.original.dir) || [];
    const existing = dirEntries.find(e => e.path === item.outPath && e.isFile);

    if (force) {
      worklist.push({ ...item, reason: existing ? 'forced' : 'missing' });
      if (existing) regeneratedCount++;
      continue;
    }

    if (!existing) {
      worklist.push({ ...item, reason: 'missing' });
      continue;
    }

    const manifestEntry = Object.hasOwn(manifest, item.outKey) ? manifest[item.outKey] : null;
    if (!hasProvenanceFields(manifestEntry)) {
      // Do not overwrite unverified historical files or forge manifest hashes without --target --force.
      unverifiedItems.push(item.outRel);
      continue;
    }

    const sourceHash = await getSourceHash(item.original.path);
    const outBuffer = await readFile(item.outPath);
    const outputHash = sha256Buffer(outBuffer);

    if (
      manifestEntryMatches(manifestEntry, {
        sourceHash,
        outputHash,
        recipe: item.spec.recipe,
        size: outBuffer.length,
      })
    ) {
      skippedCount++;
      continue;
    }

    const mismatchReasons = [];
    if (manifestEntry.sourceHash !== sourceHash) mismatchReasons.push('stale-source');
    if (manifestEntry.outputHash !== outputHash || manifestEntry.size !== outBuffer.length) {
      mismatchReasons.push('output-changed');
    }
    if (manifestEntry.recipe !== item.spec.recipe) mismatchReasons.push('recipe-changed');

    regeneratedCount++;
    worklist.push({ ...item, reason: mismatchReasons.join('+') || 'provenance-mismatch' });
  }

  log(`    Originals in scope     : ${scanState.targetedOriginals.length}`);
  log(`    Variants verified      : ${skippedCount}`);
  log(`    Unverified protected   : ${unverifiedItems.length}`);
  log(`    Variants to generate   : ${worklist.length}${dryRun ? ' (dry-run)' : ''}\n`);

  if (dryRun) {
    for (const item of worklist) {
      log(`  [dry-run:${item.reason}] ${item.original.relFromMedia} -> ${item.outRel} (${item.spec.width}x${item.spec.height}, q${item.spec.quality})`);
    }
    if (unverifiedItems.length > 0) {
      log(`  ⚠️   ${unverifiedItems.length} existing derivative(s) lack provenance; pass --target <path> --force to regenerate.`);
    }
    return {
      ok: true,
      dryRun: true,
      originalsScanned: scanState.targetedOriginals.length,
      plannedCount: worklist.length,
      generatedCount: 0,
      regeneratedCount,
      cardGenCount: 0,
      thumbGenCount: 0,
      skippedCount,
      unverifiedSkippedCount: unverifiedItems.length,
      unverifiedItems,
      errorCount: 0,
      errors: [],
    };
  }

  let cardGenCount = 0;
  let thumbGenCount = 0;
  let generatedCount = 0;
  let errorCount = 0;
  const runtimeErrors = [];

  if (worklist.length > 0) {
    const sharp = loadSharp();
    for (const item of worklist) {
      try {
        const generated = await generateVariant(sharp, item.original.path, item.outPath, item.spec);
        manifest[item.outKey] = buildManifestEntry(generated);
        generatedCount++;
        if (item.spec.suffix === '-400') cardGenCount++;
        if (item.spec.suffix === '-240w') thumbGenCount++;
        log(`  ✅  ${item.outKey}  → ${formatKB(generated.size)} (${item.reason}, from ${item.original.relFromMedia})`);
      } catch (err) {
        errorCount++;
        const msg = `Failed generating ${item.outKey} from ${item.original.relFromMedia}: ${err.message}`;
        runtimeErrors.push(msg);
        logError(`  ❌  ${msg}`);
      }
    }
  }

  if (generatedCount > 0) {
    await writeFileAtomic(resolvedManifestPath, JSON.stringify(manifest, null, 2));
  }

  log('\n────────────────────────────────────────');
  log(`  Originals preserved     : ${scanState.targetedOriginals.length} (read-only)`);
  log(`  Card variants (-400)    : ${cardGenCount}`);
  log(`  Thumb variants (-240w)  : ${thumbGenCount}`);
  log(`  Verified, skipped       : ${skippedCount}`);
  log(`  Unverified, protected   : ${unverifiedItems.length}`);
  log(`  Errors                  : ${errorCount}`);
  log('────────────────────────────────────────\n');

  if (unverifiedItems.length > 0) {
    log(`⚠️  ${unverifiedItems.length} existing derivative(s) lack provenance and were left untouched.`);
    for (const rel of unverifiedItems.slice(0, 5)) {
      log(`    - ${rel}`);
    }
    if (unverifiedItems.length > 5) {
      log(`    … and ${unverifiedItems.length - 5} more`);
    }
    log('    Use explicit `--target <relPath> --force` to regenerate unverified files.\n');
  }

  if (errorCount > 0) {
    logError('❌  Image variant generation completed with errors. Originals were left untouched.\n');
  } else if (generatedCount > 0) {
    log('✅  Sidecar variants generated with provenance without modifying originals. Next verify with:');
    log('    npm run validate:image-variants\n');
  } else if (unverifiedItems.length > 0) {
    log('ℹ️  Existing unverified derivatives were preserved untouched. validate:image-variants requires --target ... --force regeneration.\n');
  } else {
    log('✅  All required sidecar variants are verified and up-to-date. Nothing to do.\n');
  }

  return {
    ok: errorCount === 0,
    dryRun: false,
    originalsScanned: scanState.targetedOriginals.length,
    plannedCount: worklist.length,
    generatedCount,
    regeneratedCount,
    cardGenCount,
    thumbGenCount,
    skippedCount,
    unverifiedSkippedCount: unverifiedItems.length,
    unverifiedItems,
    errorCount,
    errors: runtimeErrors,
  };
}

export function parseCliArgs(argv) {
  const options = {
    mediaDir: DEFAULT_MEDIA_DIR,
    manifestPath: DEFAULT_MANIFEST_FILE,
    targets: [],
    dryRun: false,
    force: false,
  };

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--media-dir') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --media-dir');
      options.mediaDir = resolve(value);
      i++;
    } else if (arg === '--manifest') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --manifest');
      options.manifestPath = resolve(value);
      i++;
    } else if (arg === '--target') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --target');
      options.targets.push(value);
      i++;
    } else if (arg === '--dry-run') {
      options.dryRun = true;
    } else if (arg === '--force') {
      options.force = true;
    } else {
      throw new Error(`Unknown option: ${arg}`);
    }
  }

  if (options.force && options.targets.length === 0) {
    throw new Error('--force requires at least one --target');
  }

  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const cliOptions = parseCliArgs(process.argv.slice(2));
    const result = await runImagePipeline(cliOptions);
    if (!result.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(`❌  ${err.message}`);
    process.exitCode = 1;
  }
}
