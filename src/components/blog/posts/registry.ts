import RouteComparisonCallout from './oem-vs-odm-eyewear/RouteComparisonCallout.astro';
import MaterialQuickCompare from './eyewear-frame-materials-comparison/MaterialQuickCompare.astro';
import LensQuickCompare from './eyewear-lens-materials-comparison/LensQuickCompare.astro';
import BrandingQuickCompare from './eyewear-branding-logo-hardware/BrandingQuickCompare.astro';

/**
 * Registry mapping a blog post slug to optional article-specific Astro components.
 * Future articles can register custom interactive tables, timelines, or callouts here.
 */
export const BLOG_POST_COMPONENTS: Record<string, any> = {
  'oem-vs-odm-eyewear': RouteComparisonCallout,
  'eyewear-frame-materials-comparison': MaterialQuickCompare,
  'eyewear-lens-materials-comparison': LensQuickCompare,
  'eyewear-branding-logo-hardware': BrandingQuickCompare,
};
