import { existsSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Returns the suffixed variant of an image when that file actually exists
 * in public/, otherwise the original path. Variants are pre-generated
 * derivatives (see scripts/optimize-images.mjs); checking for them keeps
 * pages from emitting URLs that 404 whenever an image has not been
 * processed yet. Empty input returns '', non-.webp input is returned as-is.
 */
function variantSrc(imagePath: string | undefined, suffix: string): string {
  if (!imagePath) return '';
  const optimized = imagePath.replace(/\.webp$/, `${suffix}.webp`);
  if (optimized === imagePath) return imagePath;
  return existsSync(join(process.cwd(), 'public', optimized))
    ? optimized
    : imagePath;
}

/**
 * Returns the -400 (card) variant of an image if it exists,
 * otherwise returns the original path.
 *
 * Usage:
 *   import { cardSrc } from '../utils/image';
 *   <img src={cardSrc(product.cover)} ... />
 */
export function cardSrc(imagePath: string | undefined): string {
  return variantSrc(imagePath, '-400');
}

/**
 * Returns the -240w (thumbnail) variant of an image if it exists,
 * otherwise returns the original path.
 *
 * Usage:
 *   import { thumbSrc } from '../utils/image';
 *   <img src={thumbSrc(image)} ... />
 */
export function thumbSrc(imagePath: string | undefined): string {
  return variantSrc(imagePath, '-240w');
}
