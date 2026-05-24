export interface SidebarCategory {
  label: string;
  href: string;
  items: { label: string; href: string }[];
}

export interface SidebarTagGroup {
  label: string;
  href: string;
  items: { label: string; href: string }[];
}

/**
 * Build the primary sidebar category hierarchy (Sunglasses, Optical Frames).
 * Centralized to avoid ~70 lines of duplication across 8 listing pages.
 */
export function buildPrimaryCategories(t: any, prefix: string, baseSegment: string = 'product'): SidebarCategory[] {
  return [
    {
      label: t.sidebar.sunglasses,
      href: `/${prefix}${baseSegment}/sunglasses`,
      items: [
        { label: t.sidebar.acetate_sunglasses, href: `/${prefix}${baseSegment}/acetate-sunglasses` },
        { label: t.sidebar.metal_sunglasses, href: `/${prefix}${baseSegment}/metal-sunglasses` },
        { label: t.sidebar.titanium_sunglasses, href: `/${prefix}${baseSegment}/titanium-sunglasses` },
        { label: t.sidebar.carbon_fiber_sunglasses, href: `/${prefix}${baseSegment}/carbon-fiber-sunglasses` },
        { label: t.sidebar.rimless_sunglasses, href: `/${prefix}${baseSegment}/rimless-sunglasses` },
      ],
    },
    {
      label: t.sidebar.optical_frames,
      href: `/${prefix}${baseSegment}/optical-frames`,
      items: [
        { label: t.sidebar.acetate_glasses, href: `/${prefix}${baseSegment}/acetate-optical-frames` },
        { label: t.sidebar.metal_glasses, href: `/${prefix}${baseSegment}/metal-optical-frames` },
        { label: t.sidebar.titanium_glasses, href: `/${prefix}${baseSegment}/titanium-optical-frames` },
        { label: t.sidebar.carbon_glasses, href: `/${prefix}${baseSegment}/carbon-fiber-optical-frames` },
        { label: t.sidebar.rimless_glasses, href: `/${prefix}${baseSegment}/rimless-optical-frames` },
      ],
    },
  ];
}

/**
 * Build the sidebar tag groups (Fashion, Luxury, Carbon Fiber, Rimless).
 * Centralized to avoid ~40 lines of duplication across 8 listing pages.
 */
export function buildSidebarTags(t: any, prefix: string, baseSegment: string = 'product'): SidebarTagGroup[] {
  return [
    {
      label: t.sidebar.fashion,
      href: `/${prefix}${baseSegment}/fashion-eyewear`,
      items: [
        { label: t.sidebar.fashion_acetate_sunglasses, href: `/${prefix}${baseSegment}/fashion-acetate-sunglasses` },
        { label: t.sidebar.fashion_metal_sunglasses, href: `/${prefix}${baseSegment}/fashion-metal-sunglasses` },
        { label: t.sidebar.fashion_acetate_optical_frames, href: `/${prefix}${baseSegment}/fashion-acetate-optical-frames` },
        { label: t.sidebar.fashion_metal_optical_frames, href: `/${prefix}${baseSegment}/fashion-metal-optical-frames` },
      ],
    },
    {
      label: t.sidebar.luxury,
      href: `/${prefix}${baseSegment}/luxury-eyewear`,
      items: [
        { label: t.sidebar.luxury_acetate_sunglasses, href: `/${prefix}${baseSegment}/luxury-acetate-sunglasses` },
        { label: t.sidebar.luxury_titanium_sunglasses, href: `/${prefix}${baseSegment}/luxury-titanium-sunglasses` },
        { label: t.sidebar.luxury_acetate_optical_frames, href: `/${prefix}${baseSegment}/luxury-acetate-optical-frames` },
        { label: t.sidebar.luxury_titanium_optical_frames, href: `/${prefix}${baseSegment}/luxury-titanium-optical-frames` },
      ],
    },
    {
      label: t.sidebar.carbon_fiber,
      href: `/${prefix}${baseSegment}/carbon-fiber-eyewear`,
      items: [
        { label: t.sidebar.carbon_fiber_sunglasses, href: `/${prefix}${baseSegment}/carbon-fiber-sunglasses` },
        { label: t.sidebar.carbon_glasses, href: `/${prefix}${baseSegment}/carbon-fiber-optical-frames` },
      ],
    },
    {
      label: t.sidebar.rimless,
      href: `/${prefix}${baseSegment}/rimless-eyewear`,
      items: [
        { label: t.sidebar.rimless_sunglasses, href: `/${prefix}${baseSegment}/rimless-sunglasses` },
        { label: t.sidebar.rimless_glasses, href: `/${prefix}${baseSegment}/rimless-optical-frames` },
      ],
    },
  ];
}
