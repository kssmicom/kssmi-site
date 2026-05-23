/**
 * Extract the language code from a content collection entry ID.
 * ID format: "kso-001.en" → "en". Falls back to "en" if no language suffix.
 */
export function getProductLang(product: { id: string }): string {
  const parts = product.id.split('.');
  return parts.length > 1 ? parts.pop()! : 'en';
}

/**
 * Extract the slug from a content collection entry ID (removes language suffix).
 * ID format: "kso-001.en" → "kso-001"
 */
export function getProductSlug(product: { id: string }): string {
  const parts = product.id.split('.');
  return parts.slice(0, -1).join('.') || parts[0];
}

/**
 * Derive product type from SKU prefix.
 * SKU format: "KSO-001" → "Optical Frames", "KTS-020" → "Sunglasses".
 * Returns empty string if the prefix is unrecognised.
 */
export function deriveProductType(sku: string): string {
  const prefix = sku.split('-')[0].toUpperCase();
  const opticalPrefixes = ['KSO', 'KMO', 'KTO', 'KRO'];
  const sunglassPrefixes = ['KSS', 'KMS', 'KTS', 'KRS'];
  if (opticalPrefixes.includes(prefix)) return 'Optical Frames';
  if (sunglassPrefixes.includes(prefix)) return 'Sunglasses';
  return '';
}
