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
        .replace(/s$/, '');
}

/**
 * Checks if a product's category string matches a target category slug leniantly.
 * 
 * @param productCategory The category string from product frontmatter (e.g. "Classis Sunglasses")
 * @param targetSlug The canonical slug from the URL or sidebar (e.g. "classics-sunglasses")
 */
export function isCategoryMatch(productCategory: string, targetSlug: string): boolean {
    if (!productCategory || !targetSlug) return false;

    const targetKey = getLookupKey(targetSlug);
    const cats = productCategory.split(',').map(c => c.trim());

    // Special handling for *-series parent slugs:
    // 'rimless-series' should match any product whose categories contain 'rimless'
    const seriesMatch = targetSlug.match(/^(.+)-series$/);
    if (seriesMatch) {
        const prefix = seriesMatch[1].toLowerCase().replace(/-/g, '');
        if (cats.some(cat => cat.toLowerCase().replace(/[\s-]/g, '').includes(prefix))) {
            return true;
        }
    }

    return cats.some(cat => {
        const catKey = getLookupKey(cat);

        // Strict match on the normalized lookup key
        if (catKey === targetKey) return true;

        return false;
    });
}
