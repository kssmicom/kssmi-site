/**
 * Centralized, immutable KS image variant policy and provenance schema.
 *
 * Shared by:
 * - scripts/optimize-images.mjs
 * - scripts/validate-image-variants.mjs
 * - tests/image-pipeline.test.mjs
 */

const CARD_SPEC = Object.freeze({
  key: 'card',
  suffix: '-400',
  width: 400,
  height: 300,
  quality: 85,
  fit: 'inside',
  withoutEnlargement: true,
  effort: 5,
  rotate: true,
  format: 'webp',
  recipe: 'kssmi-v1:card:400x300:inside:noenlarge:rotate:webp:q85:e5',
});

const THUMB_SPEC = Object.freeze({
  key: 'thumb',
  suffix: '-240w',
  width: 240,
  height: 240,
  quality: 80,
  fit: 'inside',
  withoutEnlargement: true,
  effort: 5,
  rotate: true,
  format: 'webp',
  recipe: 'kssmi-v1:thumb:240x240:inside:noenlarge:rotate:webp:q80:e5',
});

export const VARIANT_SPECS = Object.freeze({
  card: CARD_SPEC,
  thumb: THUMB_SPEC,
});

export const IMAGE_EXTENSION_RE = /\.(webp|jpe?g|png)$/i;
export const DERIVED_NAME_RE = /-(400|120w|240w)\.(webp|jpe?g|png)$/i;
export const POSTER_NAME_RE = /-poster\.(webp|jpe?g|png)$/i;
export const LEGACY_EXCLUDED_SUFFIXES = Object.freeze(['-120w']);

export const VARIANT_FOLDER_RULES = Object.freeze({
  products: Object.freeze(['card', 'thumb']),
  collection: Object.freeze(['card']),
  blogs: Object.freeze(['card']),
  feature: Object.freeze(['card']),
});

export const REQUIRED_PRODUCT_VARIANT_KEYS = Object.freeze(['card', 'thumb']);
export const REQUIRED_PRODUCT_VARIANT_SPECS = Object.freeze([
  VARIANT_SPECS.card,
  VARIANT_SPECS.thumb,
]);

export const DIMENSION_TOLERANCE_PX = 1;
const SHA256_HEX_RE = /^[a-f0-9]{64}$/;

/**
 * Verifies at runtime that the image variant policy has not been mutated
 * or misconfigured. Throws an Error if any invariant fails.
 */
export function assertFixedPolicy() {
  if (!Object.isFrozen(VARIANT_SPECS) || !Object.isFrozen(VARIANT_SPECS.card) || !Object.isFrozen(VARIANT_SPECS.thumb)) {
    throw new Error('VARIANT_SPECS and its spec entries must remain frozen.');
  }
  if (!Object.isFrozen(VARIANT_FOLDER_RULES)) {
    throw new Error('VARIANT_FOLDER_RULES must remain frozen.');
  }
  const { card, thumb } = VARIANT_SPECS;
  if (
    card.suffix !== '-400' ||
    card.width !== 400 ||
    card.height !== 300 ||
    card.quality !== 85 ||
    card.fit !== 'inside' ||
    card.withoutEnlargement !== true ||
    card.effort !== 5 ||
    card.rotate !== true ||
    card.format !== 'webp' ||
    !card.recipe
  ) {
    throw new Error('VARIANT_SPECS.card violates fixed KS card policy.');
  }
  if (
    thumb.suffix !== '-240w' ||
    thumb.width !== 240 ||
    thumb.height !== 240 ||
    thumb.quality !== 80 ||
    thumb.fit !== 'inside' ||
    thumb.withoutEnlargement !== true ||
    thumb.effort !== 5 ||
    thumb.rotate !== true ||
    thumb.format !== 'webp' ||
    !thumb.recipe
  ) {
    throw new Error('VARIANT_SPECS.thumb violates fixed KS thumb policy.');
  }
  if (DIMENSION_TOLERANCE_PX !== 1) {
    throw new Error(`DIMENSION_TOLERANCE_PX must be 1, got ${DIMENSION_TOLERANCE_PX}`);
  }
}

export function isVariantFolder(relFromMedia) {
  return variantsForRelativePath(relFromMedia).length > 0;
}

export function variantsForRelativePath(relFromMedia) {
  if (!relFromMedia) return [];
  const normalized = String(relFromMedia).replace(/\\/g, '/').replace(/^\/+/, '');
  const topFolder = normalized.split('/')[0].toLowerCase();
  const keys = Object.hasOwn(VARIANT_FOLDER_RULES, topFolder)
    ? VARIANT_FOLDER_RULES[topFolder]
    : null;
  if (!keys) return [];
  return keys.map(key => VARIANT_SPECS[key]);
}

/**
 * Returns post-EXIF-rotation natural dimensions (Sharp `.rotate()` semantics).
 * EXIF orientations 5..8 transpose width and height.
 */
export function orientedDimensions(width, height, orientation = 1) {
  if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
    throw new Error(`Invalid image dimensions: ${width}x${height}`);
  }
  const transposed = Number.isInteger(orientation) && orientation >= 5 && orientation <= 8;
  return transposed ? { width: height, height: width } : { width, height };
}

/**
 * Computes expected derivative dimensions under `fit: 'inside'`,
 * `withoutEnlargement: true`, and post-EXIF `.rotate()`.
 */
export function expectedVariantDimensions(sourceWidth, sourceHeight, spec, orientation = 1) {
  const oriented = orientedDimensions(sourceWidth, sourceHeight, orientation);
  const scale = Math.min(spec.width / oriented.width, spec.height / oriented.height);
  if (spec.withoutEnlargement && scale >= 1) {
    return { width: oriented.width, height: oriented.height };
  }
  return {
    width: Math.max(1, Math.round(oriented.width * scale)),
    height: Math.max(1, Math.round(oriented.height * scale)),
  };
}

/**
 * Checks whether decoded derivative dimensions stay within spec bounds and match
 * expected aspect-ratio dimensions within `±tolerance` px (default ±1px rounding).
 */
export function dimensionsMatchExpected(
  actualWidth,
  actualHeight,
  expected,
  spec,
  tolerance = DIMENSION_TOLERANCE_PX
) {
  if (
    !Number.isInteger(actualWidth) ||
    !Number.isInteger(actualHeight) ||
    actualWidth <= 0 ||
    actualHeight <= 0
  ) {
    return { ok: false, reason: `invalid actual dimensions ${actualWidth}x${actualHeight}` };
  }
  if (actualWidth > spec.width || actualHeight > spec.height) {
    return {
      ok: false,
      reason: `actual ${actualWidth}x${actualHeight} exceeds bounding box ${spec.width}x${spec.height}`,
    };
  }
  const dw = Math.abs(actualWidth - expected.width);
  const dh = Math.abs(actualHeight - expected.height);
  if (dw > tolerance || dh > tolerance) {
    return {
      ok: false,
      reason: `actual ${actualWidth}x${actualHeight} differs from expected ${expected.width}x${expected.height} (tolerance ±${tolerance}px)`,
    };
  }
  return { ok: true, dw, dh };
}

export function isSha256Hex(value) {
  return typeof value === 'string' && SHA256_HEX_RE.test(value);
}

export function hasProvenanceFields(entry) {
  return Boolean(
    entry &&
      typeof entry === 'object' &&
      !Array.isArray(entry) &&
      isSha256Hex(entry.sourceHash) &&
      isSha256Hex(entry.outputHash) &&
      typeof entry.recipe === 'string' &&
      entry.recipe.length > 0
  );
}

export function isLegacyManifestEntry(entry) {
  return Boolean(
    entry &&
      typeof entry === 'object' &&
      !Array.isArray(entry) &&
      !hasProvenanceFields(entry) &&
      Number.isFinite(entry.size) &&
      entry.size > 0
  );
}

export function manifestEntryMatches(entry, { sourceHash, outputHash, recipe, size }) {
  if (!hasProvenanceFields(entry)) return false;
  if (entry.sourceHash !== sourceHash) return false;
  if (entry.outputHash !== outputHash) return false;
  if (entry.recipe !== recipe) return false;
  if (Number.isFinite(size) && Number.isFinite(entry.size) && entry.size !== size) return false;
  return true;
}

export function buildManifestEntry({
  sourceHash,
  outputHash,
  recipe,
  size,
  date = new Date().toISOString(),
}) {
  if (!isSha256Hex(sourceHash)) {
    throw new Error(`Invalid sourceHash for manifest entry: ${sourceHash}`);
  }
  if (!isSha256Hex(outputHash)) {
    throw new Error(`Invalid outputHash for manifest entry: ${outputHash}`);
  }
  if (typeof recipe !== 'string' || !recipe) {
    throw new Error(`Invalid recipe for manifest entry: ${recipe}`);
  }
  if (!Number.isInteger(size) || size <= 0) {
    throw new Error(`Invalid size for manifest entry: ${size}`);
  }
  return {
    sourceHash,
    outputHash,
    recipe,
    size,
    date,
  };
}
