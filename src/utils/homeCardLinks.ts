/**
 * Maps homepage capability showcase card slugs to verified clean static URLs
 * instead of non-functional /product/?<facet>=... query URLs.
 */
const HOME_CARD_STATIC_ROUTES: Record<string, string> = {
  'carbon-fiber-eyewear': '/product/carbon-fiber-eyewear/',
  'rimless-eyewear': '/product/rimless-eyewear/',
  'fashion-avant-garde': '/product/fashion-eyewear/',
  'standard-plating': '/customization/plating/',
  'polished': '/customization/plating/polished/',
  'matte-sandblasted': '/customization/plating/semi-matte/',
  'ip-plating': '/customization/plating/',
};

export function resolveHomeCardHref(prefix: string, slug?: string): string {
  const target = (slug && HOME_CARD_STATIC_ROUTES[slug]) || '/product/';
  return `${prefix}${target}`;
}
