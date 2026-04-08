/**
 * Lenient Category Matching Utility
 *
 * This utility provides a more robust way to match category names, 
 * making the site resilient to minor typos or singular/plural differences.
 */

/**
 * Standard slugification for URLs.
 * This should be STABLE and match the sidebar/canonical URLs.
 */
export function getSlug(input: string): string {
    if (!input) return '';
    return input.toLowerCase().trim().replace(/\s+/g, '-');
}

/**
 * Strips a string down to its core "match key" for lenient comparison.
 * Removes 's', 'es', and hyphens to allow "classis" to match "classics".
 */
export function getLookupKey(input: string): string {
    if (!input) return '';

    return input.toLowerCase()
        .trim()
        .replace(/\s+/g, '')
        .replace(/-/g, '')
        .replace(/classis/g, 'classic')
        .replace(/es$/, '')
        .replace(/s$/, '')
        .replace(/-/g, '');  // Remove any remaining hyphens
}

/**
 * Checks if a product's category string matches a target category slug leniantly.
 * 
 * @param productCategory The category string from product frontmatter (e.g. "Classis Sunglasses")
 * @param targetSlug The canonical slug from the URL or sidebar (e.g. "classics-sunglasses")
 */
export function isCategoryMatch(productCategory: string | string[] | undefined, targetSlug: string): boolean {
    if (!productCategory || !targetSlug) return false;

    const targetKey = getLookupKey(targetSlug);
    const cats = Array.isArray(productCategory) 
        ? productCategory 
        : String(productCategory).split(',').map(c => c.trim());

    // Special handling for *-series parent slugs:
    const seriesMatch = targetSlug.match(/^(.+)-series$/);
    if (seriesMatch) {
        const prefix = seriesMatch[1].toLowerCase().replace(/-/g, '');
        if (cats.some(cat => cat.toLowerCase().replace(/[\s-]/g, '').includes(prefix))) {
            return true;
        }
    }

    const targetFragments = targetSlug.split('-').map(f => getLookupKey(f));
    const combinedCats = cats.map(cat => getLookupKey(cat)).join(' ');

    // Intersection Match: Every fragment of the target URL must exist somewhere in the product tags
    return targetFragments.every(f => combinedCats.includes(f));
}
