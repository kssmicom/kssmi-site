// Shared listing-page constants and comparators. These must live in a plain
// module (not page frontmatter) because Astro hoists getStaticPaths out of the
// component scope: page-level consts are invisible inside it.
export const PAGE_SIZE = 12;

// Blog listing order: featured first, then newest published, then id desc.
export function sortBlogPosts(items) {
  return [...items].sort((a, b) => {
    if (a.data.featured && !b.data.featured) return -1;
    if (!a.data.featured && b.data.featured) return 1;
    const dateA = a.data.published ? new Date(a.data.published).getTime() : 0;
    const dateB = b.data.published ? new Date(b.data.published).getTime() : 0;
    if (dateA !== dateB) return dateB - dateA;
    return b.id.localeCompare(a.id);
  });
}

// Eyewear listing order: featured first, then newest date, then id desc.
export function sortEyewearItems(items) {
  return [...items].sort((a, b) => {
    if (a.data.featured && !b.data.featured) return -1;
    if (!a.data.featured && b.data.featured) return 1;
    const dateA = a.data.date ? new Date(a.data.date).getTime() : 0;
    const dateB = b.data.date ? new Date(b.data.date).getTime() : 0;
    if (dateA !== dateB) return dateB - dateA;
    return b.id.localeCompare(a.id);
  });
}
