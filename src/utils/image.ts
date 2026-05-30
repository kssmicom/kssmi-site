import { existsSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Returns the -400 (card) variant of an image if it exists,
 * otherwise returns the original path.
 *
 * Usage:
 *   import { cardSrc } from '../utils/image';
 *   <img src={cardSrc(product.cover)} ... />
 */
export function cardSrc(imagePath: string | undefined): string {
  if (!imagePath) return '';
  const optimized = imagePath.replace(/\.webp$/, '-400.webp');
  return existsSync(join(process.cwd(), 'public', optimized))
    ? optimized
    : imagePath;
}