import { statSync } from 'node:fs';
import { join } from 'node:path';

const SUPPORTED_IMAGE_EXT = /\.(webp|jpe?g|png)$/i;
const DERIVED_OR_POSTER = /(-(400|120w|240w)|-poster)\.(webp|jpe?g|png)$/i;

function isRegularFile(filePath: string): boolean {
  try {
    return statSync(filePath).isFile();
  } catch {
    return false;
  }
}

/**
 * Returns the sidecar WebP variant path of an original image (.webp, .jpg,
 * .jpeg, or .png) when that variant exists as a regular file under public/,
 * otherwise falls back to the original path. Derived (-400/-120w/-240w) and
 * -poster inputs, empty strings, and unsupported extensions are returned as-is.
 */
export function variantSrc(
  imagePath: string | undefined,
  suffix: string,
  publicDir: string = join(process.cwd(), 'public')
): string {
  if (!imagePath) return '';
  if (DERIVED_OR_POSTER.test(imagePath) || !SUPPORTED_IMAGE_EXT.test(imagePath)) {
    return imagePath;
  }
  const optimized = imagePath.replace(SUPPORTED_IMAGE_EXT, `${suffix}.webp`);
  const diskPath = join(publicDir, optimized.replace(/^\/+/, ''));
  return isRegularFile(diskPath) ? optimized : imagePath;
}

/**
 * Returns the -400.webp (card) variant of an image if it exists,
 * otherwise returns the original path.
 *
 * Usage:
 *   import { cardSrc } from '../utils/image';
 *   <img src={cardSrc(product.cover)} ... />
 */
export function cardSrc(imagePath: string | undefined, publicDir?: string): string {
  return variantSrc(imagePath, '-400', publicDir);
}

/**
 * Returns the -240w.webp (thumbnail) variant of an image if it exists,
 * otherwise returns the original path.
 *
 * Usage:
 *   import { thumbSrc } from '../utils/image';
 *   <img src={thumbSrc(image)} ... />
 */
export function thumbSrc(imagePath: string | undefined, publicDir?: string): string {
  return variantSrc(imagePath, '-240w', publicDir);
}
