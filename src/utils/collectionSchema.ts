export interface CollectionSchemaItem {
  name: string;
  url?: string;
}

export interface CollectionSchemaInput {
  name: string;
  description: string;
  url: string;
  lang: string;
  totalItems: number;
  startPosition: number;
  items: CollectionSchemaItem[];
}

/**
 * Build a Schema.org CollectionPage + ItemList JSON-LD payload for listing and category pages.
 */
export function buildCollectionPageSchema({
  name,
  description,
  url,
  lang,
  totalItems,
  startPosition,
  items,
}: CollectionSchemaInput) {
  return {
    '@context': 'https://schema.org',
    '@type': 'CollectionPage',
    '@id': `${url}#collection`,
    url,
    name,
    description,
    inLanguage: lang,
    isPartOf: { '@id': 'https://kssmi.com/#website' },
    mainEntity: {
      '@type': 'ItemList',
      numberOfItems: totalItems,
      itemListElement: items.map((item, idx) => ({
        '@type': 'ListItem',
        position: startPosition + idx,
        name: item.name,
        ...(item.url ? { url: item.url } : {}),
      })),
    },
  };
}
