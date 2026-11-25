#!/usr/bin/env node
/**
 * Read-only product image variant and provenance gate.
 *
 * Verifies:
 *   1. Immutable variant policy integrity (`src/config/image-variants.mjs`).
 *   2. Manifest presence & JSON object validity (`scripts/.optimize-manifest.json`).
 *   3. No symlinks, unreadable directories, or same-basename multi-extension collisions.
 *   4. Every product original is readable and every required `-400.webp` and
 *      `-240w.webp` derivative exists with exact disk filename casing.
 *   5. Decoded container format is actually `webp` (rejects renamed PNG/JPEG/garbage).
 *   6. Decoded dimensions stay within spec bounding boxes and match expected
 *      post-EXIF-rotation `fit: 'inside', withoutEnlargement: true` dimensions (±1px).
 *   7. Manifest provenance (`sourceHash`, `outputHash`, `recipe`) matches disk files.
 *   8. Product Markdown `cover`/`gallery` `/media/products/...` refs use exact disk
 *      casing and point to originals rather than derived or missing assets.
 */

import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { lstat, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import path, { basename, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import yaml from 'js-yaml';
import {
  assertFixedPolicy,
  DERIVED_NAME_RE,
  dimensionsMatchExpected,
  expectedVariantDimensions,
  hasProvenanceFields,
  IMAGE_EXTENSION_RE,
  POSTER_NAME_RE,
  REQUIRED_PRODUCT_VARIANT_SPECS,
  VARIANT_SPECS,
} from '../src/config/image-variants.mjs';

/**
 * Directory of a module file URL. Only `fileURLToPath` applies the host's path
 * rules: on Windows `file:///D:/x/y.mjs` is `D:\x\y.mjs`, whereas decoding
 * the URL pathname by hand leaves a drive-less `/D:/x/y.mjs` that resolves
 * outside the repository.
 */
export function moduleDirectory(moduleUrl) {
  return dirname(fileURLToPath(moduleUrl));
}

/**
 * Repository-relative defaults for a script directory. `pathApi` is replaced
 * only by tests that exercise the Windows path rules on another host.
 */
export function defaultValidatorPaths(scriptDir, pathApi = path) {
  return {
    scanRoot: pathApi.resolve(scriptDir, '../public/media/products'),
    mediaRoot: pathApi.resolve(scriptDir, '../public/media'),
    manifestPath: pathApi.resolve(scriptDir, '.optimize-manifest.json'),
    productsContentDir: pathApi.resolve(scriptDir, '../src/content/products'),
  };
}

const __dirname = moduleDirectory(import.meta.url);
export const DEFAULT_PATHS = Object.freeze(defaultValidatorPaths(__dirname));
const DEFAULT_SCAN_ROOT = DEFAULT_PATHS.scanRoot;
const DEFAULT_MEDIA_ROOT = DEFAULT_PATHS.mediaRoot;
const DEFAULT_MANIFEST_PATH = DEFAULT_PATHS.manifestPath;
const DEFAULT_PRODUCTS_CONTENT_DIR = DEFAULT_PATHS.productsContentDir;
const DEFAULT_CONCURRENCY = 8;

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
        'Could not load sharp. Ensure dependencies are installed before validating image variants.'
      );
    }
  }
}

function sha256Buffer(buffer) {
  return createHash('sha256').update(buffer).digest('hex');
}

function normalizeRel(rootDir, targetPath) {
  return relative(rootDir, targetPath).replace(/\\/g, '/');
}

function withinOrEqual(rootDir, targetPath) {
  const rel = relative(rootDir, targetPath);
  return rel === '' || (rel !== '..' && !rel.startsWith(`..${sep}`) && !isAbsolute(rel));
}

async function assertNoSymlinksAlong(boundaryDir, targetPath) {
  let cursor = resolve(targetPath);
  const boundary = resolve(boundaryDir);
  while (true) {
    if (existsSync(cursor)) {
      const st = await lstat(cursor);
      if (st.isSymbolicLink()) {
        throw new Error(`Symlink is not allowed in media path: ${cursor}`);
      }
    }
    if (cursor === boundary) break;
    const parent = dirname(cursor);
    if (parent === cursor) break;
    cursor = parent;
  }
}

async function mapWithConcurrency(items, concurrency, worker) {
  if (items.length === 0) return;
  const limit = Math.max(1, Math.min(concurrency, items.length));
  let index = 0;
  async function runner() {
    while (true) {
      const current = index++;
      if (current >= items.length) break;
      await worker(items[current], current);
    }
  }
  await Promise.all(Array.from({ length: limit }, () => runner()));
}

async function loadManifestStrict(manifestPath) {
  let st;
  try {
    st = await lstat(manifestPath);
  } catch (err) {
    return {
      ok: false,
      issue: {
        code: 'MANIFEST_MISSING',
        path: manifestPath,
        message: `Manifest file not found or unreadable (${err.message})`,
      },
      manifest: null,
    };
  }

  if (st.isSymbolicLink() || !st.isFile()) {
    return {
      ok: false,
      issue: {
        code: 'CORRUPT_MANIFEST',
        path: manifestPath,
        message: 'Manifest path must be a regular non-symlink file',
      },
      manifest: null,
    };
  }

  let raw;
  try {
    raw = await readFile(manifestPath, 'utf8');
  } catch (err) {
    return {
      ok: false,
      issue: {
        code: 'CORRUPT_MANIFEST',
        path: manifestPath,
        message: `Failed reading manifest: ${err.message}`,
      },
      manifest: null,
    };
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (err) {
    return {
      ok: false,
      issue: {
        code: 'CORRUPT_MANIFEST',
        path: manifestPath,
        message: `Invalid manifest JSON: ${err.message}`,
      },
      manifest: null,
    };
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return {
      ok: false,
      issue: {
        code: 'CORRUPT_MANIFEST',
        path: manifestPath,
        message: 'Manifest JSON must be a top-level object keyed by media relative path',
      },
      manifest: null,
    };
  }

  return { ok: true, issue: null, manifest: parsed };
}

async function walkMediaTree(scanRoot, mediaRoot) {
  const issues = [];
  const originals = [];
  const dirFileEntries = new Map();
  const diskExactRelSet = new Set();
  const diskLowerToExactMap = new Map();
  let legacyMinus120wCount = 0;

  let rootStat;
  try {
    rootStat = await lstat(scanRoot);
  } catch (err) {
    issues.push({
      code: 'SCAN_ROOT_ERROR',
      path: scanRoot,
      message: `Cannot access image variant scan root ${scanRoot}: ${err.message}`,
    });
    return { issues, originals, dirFileEntries, diskExactRelSet, diskLowerToExactMap, legacyMinus120wCount };
  }

  if (rootStat.isSymbolicLink() || !rootStat.isDirectory()) {
    issues.push({
      code: 'SCAN_ROOT_ERROR',
      path: scanRoot,
      message: `Image variant scan root must be a regular non-symlink directory: ${scanRoot}`,
    });
    return { issues, originals, dirFileEntries, diskExactRelSet, diskLowerToExactMap, legacyMinus120wCount };
  }

  if (withinOrEqual(mediaRoot, scanRoot)) {
    try {
      await assertNoSymlinksAlong(mediaRoot, scanRoot);
    } catch (err) {
      issues.push({
        code: 'SYMLINK_DISALLOWED',
        path: scanRoot,
        message: err.message,
      });
      return { issues, originals, dirFileEntries, diskExactRelSet, diskLowerToExactMap, legacyMinus120wCount };
    }
  }

  async function walk(dir) {
    let entries;
    try {
      entries = await readdir(dir, { withFileTypes: true });
    } catch (err) {
      issues.push({
        code: 'DIR_READ_ERROR',
        path: dir,
        message: `Failed reading directory ${dir}: ${err.message}`,
      });
      return;
    }

    entries.sort((a, b) => a.name.localeCompare(b.name));
    const currentFiles = [];
    dirFileEntries.set(dir, currentFiles);

    for (const dirent of entries) {
      const fullPath = join(dir, dirent.name);
      let st;
      try {
        st = await lstat(fullPath);
      } catch (err) {
        issues.push({
          code: 'STAT_ERROR',
          path: fullPath,
          message: `Failed inspecting ${fullPath}: ${err.message}`,
        });
        continue;
      }

      if (st.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          path: fullPath,
          message: `Symlink is not allowed under media tree: ${fullPath}`,
        });
        continue;
      }

      const relFromMedia = normalizeRel(mediaRoot, fullPath);
      currentFiles.push({
        name: dirent.name,
        path: fullPath,
        dir,
        relFromMedia,
        isFile: st.isFile(),
        isDirectory: st.isDirectory(),
      });

      if (st.isDirectory()) {
        await walk(fullPath);
        continue;
      }

      if (!st.isFile()) continue;

      diskExactRelSet.add(relFromMedia);
      const lowerRel = relFromMedia.toLowerCase();
      if (!diskLowerToExactMap.has(lowerRel)) diskLowerToExactMap.set(lowerRel, []);
      diskLowerToExactMap.get(lowerRel).push(relFromMedia);

      if (/-120w\.webp$/i.test(dirent.name)) {
        legacyMinus120wCount++;
      }

      if (!IMAGE_EXTENSION_RE.test(dirent.name)) continue;
      if (DERIVED_NAME_RE.test(dirent.name) || POSTER_NAME_RE.test(dirent.name)) continue;

      originals.push({
        name: dirent.name,
        path: fullPath,
        dir,
        relFromMedia,
      });
    }
  }

  await walk(scanRoot);
  return {
    issues,
    originals,
    dirFileEntries,
    diskExactRelSet,
    diskLowerToExactMap,
    legacyMinus120wCount,
  };
}

function checkSameBasenameCollisions(originals) {
  const issues = [];
  const stems = new Map();
  for (const orig of originals) {
    const stemKey = orig.path.replace(/\.[^.]+$/, '').toLowerCase();
    if (!stems.has(stemKey)) stems.set(stemKey, []);
    stems.get(stemKey).push(orig);
  }
  for (const group of stems.values()) {
    if (group.length > 1) {
      issues.push({
        code: 'COLLISION',
        path: group[0].relFromMedia,
        message: `Same-basename image collision: ${group.map(g => g.relFromMedia).join(', ')}`,
      });
    }
  }
  return issues;
}

async function validateProductMarkdownRefs(productsContentDir, diskExactRelSet, diskLowerToExactMap) {
  const issues = [];
  let filesChecked = 0;
  let refsChecked = 0;

  let st;
  try {
    st = await lstat(productsContentDir);
  } catch (err) {
    issues.push({
      code: 'CONTENT_DIR_ERROR',
      path: productsContentDir,
      message: `Cannot inspect products content dir ${productsContentDir}: ${err.message}`,
    });
    return { issues, filesChecked, refsChecked };
  }

  if (st.isSymbolicLink() || !st.isDirectory()) {
    issues.push({
      code: 'CONTENT_DIR_ERROR',
      path: productsContentDir,
      message: `Products content path must be a non-symlink directory: ${productsContentDir}`,
    });
    return { issues, filesChecked, refsChecked };
  }

  const mdFiles = [];
  async function walkContent(dir) {
    const entries = await readdir(dir, { withFileTypes: true });
    entries.sort((a, b) => a.name.localeCompare(b.name));
    for (const dirent of entries) {
      const fullPath = join(dir, dirent.name);
      const entryStat = await lstat(fullPath);
      if (entryStat.isSymbolicLink()) {
        issues.push({
          code: 'SYMLINK_DISALLOWED',
          path: fullPath,
          message: `Symlink not allowed under products content: ${fullPath}`,
        });
        continue;
      }
      if (entryStat.isDirectory()) {
        await walkContent(fullPath);
      } else if (entryStat.isFile() && dirent.name.endsWith('.md')) {
        mdFiles.push(fullPath);
      }
    }
  }
  await walkContent(productsContentDir);

  for (const mdFile of mdFiles) {
    filesChecked++;
    const raw = await readFile(mdFile, 'utf8');
    const match = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/);
    if (!match) continue;

    let frontmatter;
    try {
      frontmatter = yaml.load(match[1]);
    } catch (err) {
      issues.push({
        code: 'CONTENT_PARSE_ERROR',
        path: mdFile,
        message: `Invalid YAML frontmatter in ${mdFile}: ${err.message}`,
      });
      continue;
    }

    if (!frontmatter || typeof frontmatter !== 'object') continue;

    const candidates = [];
    if (typeof frontmatter.cover === 'string' && frontmatter.cover.trim()) {
      candidates.push({ field: 'cover', value: frontmatter.cover.trim() });
    }
    if (Array.isArray(frontmatter.gallery)) {
      frontmatter.gallery.forEach((item, idx) => {
        if (typeof item === 'string' && item.trim()) {
          candidates.push({ field: `gallery[${idx}]`, value: item.trim() });
        }
      });
    }

    for (const { field, value } of candidates) {
      if (!value.startsWith('/media/products/')) continue;
      refsChecked++;
      const relFromMedia = value.slice('/media/'.length);

      if (DERIVED_NAME_RE.test(relFromMedia) || POSTER_NAME_RE.test(relFromMedia)) {
        issues.push({
          code: 'DERIVED_REF_IN_CONTENT',
          path: mdFile,
          message: `${field} references derived/poster image (${value}); product content must reference originals`,
        });
        continue;
      }

      if (diskExactRelSet.has(relFromMedia)) continue;

      const lowerMatches = diskLowerToExactMap.get(relFromMedia.toLowerCase());
      if (lowerMatches && lowerMatches.length > 0) {
        issues.push({
          code: 'CONTENT_CASE_MISMATCH',
          path: mdFile,
          message: `${field} case mismatch: references ${value}, disk file is /media/${lowerMatches[0]}`,
        });
      } else {
        issues.push({
          code: 'CONTENT_MISSING_MEDIA',
          path: mdFile,
          message: `${field} references missing product media file ${value}`,
        });
      }
    }
  }

  return { issues, filesChecked, refsChecked };
}

function summarizeIssuesByCode(issues) {
  const counts = {};
  const samples = {};
  for (const issue of issues) {
    counts[issue.code] = (counts[issue.code] || 0) + 1;
    if (!samples[issue.code]) samples[issue.code] = [];
    if (samples[issue.code].length < 25) {
      samples[issue.code].push(issue);
    }
  }
  return { counts, samples };
}

export async function validateImageVariants({
  scanRoot = DEFAULT_SCAN_ROOT,
  mediaRoot = null,
  manifestPath = DEFAULT_MANIFEST_PATH,
  productsContentDir = undefined,
  noContentCheck = false,
  inventoryOut = null,
  concurrency = DEFAULT_CONCURRENCY,
  quiet = false,
  verbose = false,
} = {}) {
  assertFixedPolicy();

  const resolvedScanRoot = resolve(scanRoot);
  let resolvedMediaRoot;
  if (mediaRoot) {
    resolvedMediaRoot = resolve(mediaRoot);
  } else if (basename(resolvedScanRoot).toLowerCase() === 'products' || basename(dirname(resolvedScanRoot)).toLowerCase() === 'media') {
    resolvedMediaRoot = dirname(resolvedScanRoot);
  } else {
    resolvedMediaRoot = resolvedScanRoot;
  }

  const resolvedManifestPath = resolve(manifestPath);

  let resolvedContentDir = null;
  if (!noContentCheck) {
    if (productsContentDir) {
      resolvedContentDir = resolve(productsContentDir);
    } else if (resolvedScanRoot === DEFAULT_SCAN_ROOT && existsSync(DEFAULT_PRODUCTS_CONTENT_DIR)) {
      resolvedContentDir = DEFAULT_PRODUCTS_CONTENT_DIR;
    }
  }

  const issues = [];
  const manifestRes = await loadManifestStrict(resolvedManifestPath);
  if (!manifestRes.ok) {
    issues.push(manifestRes.issue);
  }
  const manifest = manifestRes.manifest || {};
  const manifestLowerKeys = new Map();
  for (const key of Object.keys(manifest)) {
    const lk = key.toLowerCase();
    if (!manifestLowerKeys.has(lk)) manifestLowerKeys.set(lk, []);
    manifestLowerKeys.get(lk).push(key);
  }

  const walkRes = await walkMediaTree(resolvedScanRoot, resolvedMediaRoot);
  issues.push(...walkRes.issues);
  issues.push(...checkSameBasenameCollisions(walkRes.originals));

  const sharp = walkRes.originals.length > 0 ? loadSharp() : null;

  let originalsValidCount = 0;
  let variantsPresentExactCase = 0;
  let validFormatWebpCount = 0;
  let validDimensionsCount = 0;
  let verifiedProvenanceCount = 0;

  // Validate each original and its required -400 / -240w sidecars
  await mapWithConcurrency(walkRes.originals, concurrency, async original => {
    let origBuffer;
    let origMeta;
    let sourceHash;
    try {
      origBuffer = await readFile(original.path);
      sourceHash = sha256Buffer(origBuffer);
      origMeta = await sharp(origBuffer).metadata();
      if (!origMeta.width || !origMeta.height || !origMeta.format) {
        throw new Error('missing decoded dimensions or container format');
      }
      originalsValidCount++;
    } catch (err) {
      issues.push({
        code: 'INVALID_ORIGINAL',
        path: original.relFromMedia,
        message: `Original image ${original.relFromMedia} cannot be decoded: ${err.message}`,
      });
      return;
    }

    const baseRel = original.relFromMedia.replace(/\.[^.]+$/, '');
    const dirEntries = walkRes.dirFileEntries.get(original.dir) || [];

    for (const spec of REQUIRED_PRODUCT_VARIANT_SPECS) {
      const variantRel = `${baseRel}${spec.suffix}.webp`;
      const variantKey = `media/${variantRel}`;
      const variantFullPath = join(resolvedMediaRoot, variantRel);

      const exactEntry = dirEntries.find(e => e.relFromMedia === variantRel);
      if (!exactEntry) {
        const lowerMatches = walkRes.diskLowerToExactMap.get(variantRel.toLowerCase());
        if (lowerMatches && lowerMatches.length > 0) {
          issues.push({
            code: 'CASE_MISMATCH',
            path: variantRel,
            message: `Variant filename casing mismatch: expected ${variantRel}, found ${lowerMatches[0]}`,
          });
        } else {
          issues.push({
            code: 'MISSING_VARIANT',
            path: variantRel,
            message: `Missing required ${spec.suffix}.webp variant for ${original.relFromMedia}`,
          });
        }
        continue;
      }

      if (!exactEntry.isFile) {
        issues.push({
          code: 'NOT_A_FILE',
          path: variantRel,
          message: `Variant path exists but is not a regular file: ${variantRel}`,
        });
        continue;
      }

      variantsPresentExactCase++;

      let varBuffer;
      let actualOutputHash;
      let varMeta;
      try {
        varBuffer = await readFile(variantFullPath);
        actualOutputHash = sha256Buffer(varBuffer);
      } catch (err) {
        issues.push({
          code: 'UNREADABLE_VARIANT',
          path: variantRel,
          message: `Failed reading variant ${variantRel}: ${err.message}`,
        });
        continue;
      }

      try {
        varMeta = await sharp(varBuffer).metadata();
      } catch (err) {
        issues.push({
          code: 'WRONG_FORMAT',
          path: variantRel,
          message: `Variant ${variantRel} failed image decoding (${err.message}); expected valid WebP`,
        });
        continue;
      }

      if (varMeta.format !== spec.format) {
        issues.push({
          code: 'WRONG_FORMAT',
          path: variantRel,
          message: `Variant ${variantRel} decoded container format is '${varMeta.format || 'unknown'}', expected '${spec.format}'`,
        });
      } else {
        validFormatWebpCount++;
      }

      const expectedDims = expectedVariantDimensions(
        origMeta.width,
        origMeta.height,
        spec,
        origMeta.orientation || 1
      );
      const dimCheck = dimensionsMatchExpected(varMeta.width, varMeta.height, expectedDims, spec);
      if (!dimCheck.ok) {
        issues.push({
          code: 'DIMENSION_MISMATCH',
          path: variantRel,
          message: `Variant ${variantRel} (${spec.key}): ${dimCheck.reason}`,
        });
      } else {
        validDimensionsCount++;
      }

      if (!manifestRes.ok) {
        // MANIFEST_MISSING / CORRUPT_MANIFEST already recorded
        continue;
      }

      if (!Object.hasOwn(manifest, variantKey)) {
        const caseKeys = manifestLowerKeys.get(variantKey.toLowerCase());
        if (caseKeys && caseKeys.length > 0) {
          issues.push({
            code: 'MANIFEST_CASE_MISMATCH',
            path: variantRel,
            message: `Manifest key case mismatch for ${variantKey}: found ${caseKeys[0]}`,
          });
        } else {
          issues.push({
            code: 'UNVERIFIED_NO_MANIFEST_RECORD',
            path: variantRel,
            message: `Variant ${variantRel} has no manifest provenance record (${variantKey}); regenerate with --target ${original.relFromMedia} --force`,
          });
        }
        continue;
      }

      const entry = manifest[variantKey];
      if (!hasProvenanceFields(entry)) {
        issues.push({
          code: 'UNVERIFIED_LEGACY_MANIFEST',
          path: variantRel,
          message: `Variant ${variantRel} only has legacy manifest metadata without sourceHash/outputHash/recipe; regenerate with --target ${original.relFromMedia} --force`,
        });
        continue;
      }

      let provenanceOk = true;
      if (entry.recipe !== spec.recipe) {
        provenanceOk = false;
        issues.push({
          code: 'RECIPE_MISMATCH',
          path: variantRel,
          message: `Variant ${variantRel} recipe mismatch: manifest '${entry.recipe}' vs fixed policy '${spec.recipe}'`,
        });
      }
      if (entry.sourceHash !== sourceHash) {
        provenanceOk = false;
        issues.push({
          code: 'STALE_SOURCE_HASH',
          path: variantRel,
          message: `Variant ${variantRel} sourceHash does not match current original ${original.relFromMedia}`,
        });
      }
      if (entry.outputHash !== actualOutputHash) {
        provenanceOk = false;
        issues.push({
          code: 'OUTPUT_HASH_MISMATCH',
          path: variantRel,
          message: `Variant ${variantRel} outputHash does not match disk file (tampered or unverified derivative)`,
        });
      }
      if (provenanceOk) {
        verifiedProvenanceCount++;
      }
    }
  });

  let contentFilesChecked = 0;
  let contentRefsChecked = 0;
  if (resolvedContentDir) {
    const contentRes = await validateProductMarkdownRefs(
      resolvedContentDir,
      walkRes.diskExactRelSet,
      walkRes.diskLowerToExactMap
    );
    issues.push(...contentRes.issues);
    contentFilesChecked = contentRes.filesChecked;
    contentRefsChecked = contentRes.refsChecked;
  }

  const { counts: issuesByCode, samples: samplesByCode } = summarizeIssuesByCode(issues);
  const summary = {
    originalsCount: walkRes.originals.length,
    originalsValidCount,
    requiredVariantsCount: walkRes.originals.length * REQUIRED_PRODUCT_VARIANT_SPECS.length,
    variantsPresentExactCase,
    legacyMinus120wCount: walkRes.legacyMinus120wCount,
    validFormatWebpCount,
    validDimensionsCount,
    verifiedProvenanceCount,
    contentFilesChecked,
    contentRefsChecked,
    issueCount: issues.length,
    issuesByCode,
  };

  if (inventoryOut) {
    const resolvedInventoryOut = resolve(inventoryOut);
    await mkdir(dirname(resolvedInventoryOut), { recursive: true });
    const payload = {
      generatedAt: new Date().toISOString(),
      scanRoot: resolvedScanRoot,
      mediaRoot: resolvedMediaRoot,
      manifestPath: resolvedManifestPath,
      productsContentDir: resolvedContentDir,
      policy: {
        card: VARIANT_SPECS.card,
        thumb: VARIANT_SPECS.thumb,
      },
      summary,
      samplesByCode,
    };
    await writeFile(resolvedInventoryOut, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
  }

  const ok = issues.length === 0;
  if (!quiet) {
    if (ok) {
      console.log(
        `✅ ${summary.originalsCount} product original(s) checked under ${resolvedScanRoot}; ` +
          `all ${summary.requiredVariantsCount} required -400/-240w variants pass format, ` +
          `EXIF/dimension (±1px), exact case, and manifest provenance checks` +
          (resolvedContentDir ? ` (${contentRefsChecked} product Markdown refs verified across ${contentFilesChecked} files).` : '.')
      );
    } else {
      console.error(`❌ Image variant gate failed with ${issues.length} issue(s) under ${resolvedScanRoot}:`);
      for (const [code, count] of Object.entries(issuesByCode)) {
        console.error(`   [${code}] ${count}`);
      }
      const maxToPrint = verbose || issues.length <= 50 ? issues.length : 25;
      console.error('');
      for (const issue of issues.slice(0, maxToPrint)) {
        console.error(`   - [${issue.code}] ${issue.path}: ${issue.message}`);
      }
      if (issues.length > maxToPrint) {
        console.error(`   … and ${issues.length - maxToPrint} more issue(s). Pass --verbose or --inventory-out <file> for full breakdown.`);
      }
      if (issuesByCode.MISSING_VARIANT || issuesByCode.STALE_SOURCE_HASH || issuesByCode.OUTPUT_HASH_MISMATCH || issuesByCode.RECIPE_MISMATCH) {
        console.error('\n   Generate or refresh missing/stale sidecars without overwriting originals:');
        console.error('     node scripts/optimize-images.mjs --target products/<sku>');
      }
      if (issuesByCode.UNVERIFIED_NO_MANIFEST_RECORD || issuesByCode.UNVERIFIED_LEGACY_MANIFEST) {
        console.error('\n   Unverified historical derivatives require explicit targeted regeneration:');
        console.error('     node scripts/optimize-images.mjs --target products/<sku> --force');
      }
    }
  }

  return { ok, summary, issues, issuesByCode };
}

export function parseCliArgs(argv) {
  const options = {
    scanRoot: DEFAULT_SCAN_ROOT,
    mediaRoot: null,
    manifestPath: DEFAULT_MANIFEST_PATH,
    productsContentDir: undefined,
    noContentCheck: false,
    inventoryOut: null,
    verbose: false,
  };

  let positionalSet = false;

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--manifest') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --manifest');
      options.manifestPath = resolve(value);
      i++;
    } else if (arg === '--media-root') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --media-root');
      options.mediaRoot = resolve(value);
      i++;
    } else if (arg === '--products-content') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --products-content');
      options.productsContentDir = resolve(value);
      i++;
    } else if (arg === '--inventory-out') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) throw new Error('Missing value for --inventory-out');
      options.inventoryOut = resolve(value);
      i++;
    } else if (arg === '--no-content-check') {
      options.noContentCheck = true;
    } else if (arg === '--verbose') {
      options.verbose = true;
    } else if (arg.startsWith('-')) {
      throw new Error(`Unknown option: ${arg}`);
    } else if (positionalSet) {
      throw new Error(`Unexpected extra positional argument: ${arg}`);
    } else if (!arg.trim()) {
      throw new Error('scanRoot argument cannot be empty');
    } else {
      options.scanRoot = resolve(arg);
      positionalSet = true;
    }
  }

  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  try {
    const cliOptions = parseCliArgs(process.argv.slice(2));
    const result = await validateImageVariants(cliOptions);
    if (!result.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(`❌ ${err.message}`);
    process.exitCode = 1;
  }
}
