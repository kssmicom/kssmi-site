/**
 * FAQ hub + article folder content helpers.
 *
 * Content layout (folder name = URL):
 *   src/content/faq/index/faq.{lang}.md          → /faq/        (card listing hub & sidebar taxonomy)
 *   src/content/faq/<slug>/<slug>.{lang}.md      → /faq/<slug>/ (FAQ article detail page)
 *
 * Articles declare `faqTopic` and optional `faqSubtopic` matching the hub's `topics` taxonomy.
 * Sidebar topics/subtopics with >= 1 article link to `/faq/<topic>/` and `/faq/<topic>/<subtopic>/`;
 * empty topics/subtopics render without links so zero 404s or empty listing pages are shipped.
 */
import { getCollection, getEntry, type CollectionEntry } from 'astro:content';

export interface FaqItem {
  question: string;
  answer: string;
  topic?: string;
  image?: string;
  imageAlt?: string;
}

export interface FaqSubtopic {
  key: string;
  title: string;
  anchor?: string;
}

export interface FaqSubtopicLink {
  key: string;
  title: string;
  href?: string;
  count: number;
}

export interface FaqTopic {
  key: string;
  title: string;
  subtopics?: FaqSubtopic[];
}

export interface FaqUi {
  searchPlaceholder: string;
  topicsTitle: string;
  questionsTitle: string;
  noResults: string;
  backToFaq: string;
  otherTopics: string;
}

export interface FaqTopicCard extends FaqTopic {
  href?: string;
  count: number;
  subtopics: FaqSubtopicLink[];
}

export interface NumberedFaqItem extends FaqItem {
  id: string;
  n: string;
}

export interface FaqGroup {
  key: string;
  title: string;
  items: NumberedFaqItem[];
}

export interface FaqArticle {
  slug: string;
  lang: string;
  cover?: string;
  faqTopic: string;
  faqSubtopic?: string;
  seoTitle: string;
  seoDescription: string;
  heading: string;
  subtitle?: string;
  items: FaqItem[];
}

export const faqPath = (lang: string, segment?: string) =>
  `${lang === 'en' ? '' : `/${lang}`}/faq/${segment ? `${segment}/` : ''}`;

/** Group items by declared topic order and number them sequentially (`q-01`, `q-02`, ...). */
export function groupFaqItems(topics: FaqTopic[], items: FaqItem[]): FaqGroup[] {
  const grouped = topics.length > 0
    ? [
        ...topics.map(topic => ({ key: topic.key, title: topic.title, items: items.filter(item => item.topic === topic.key) })),
        { key: 'more', title: '', items: items.filter(item => !topics.some(topic => topic.key === item.topic)) },
      ].filter(group => group.items.length > 0)
    : [{ key: '', title: '', items }];

  let position = 0;
  return grouped.map(group => ({
    ...group,
    items: group.items.map(item => {
      const n = String(++position).padStart(2, '0');
      return { ...item, id: `q-${n}`, n };
    }),
  }));
}

export async function getFaqHub(lang: string) {
  // Look the hub up by id: reading data.slug on entries without one logs a deprecation error.
  const entry = await getEntry('faq', `index/faq.${lang}`);
  if (!entry?.data.heading || !entry?.data.ui) {
    throw new Error(`Missing FAQ hub content for language: ${lang}`);
  }
  return entry.data as CollectionEntry<'faq'>['data'] & {
    seoTitle: string;
    seoDescription: string;
    heading: string;
    subtitle: string;
    topics: FaqTopic[];
    ui: FaqUi;
  };
}

/** Load all FAQ article entries (localized to `lang`, falling back to `en` if a translation is missing). */
export async function getFaqArticles(lang: string): Promise<FaqArticle[]> {
  const entries = await getCollection('faq', ({ id, data }) =>
    !id.startsWith('index/') && Array.isArray(data.items) && data.items.length > 0
  );

  const bySlug = new Map<string, Map<string, CollectionEntry<'faq'>>>();
  for (const entry of entries) {
    const folder = entry.id.split('/')[0];
    const slug = (typeof entry.data.slug === 'string' && entry.data.slug.trim()) ? entry.data.slug.trim() : folder;
    const entryLang = (entry.data.lang as string) || 'en';
    if (!bySlug.has(slug)) bySlug.set(slug, new Map());
    bySlug.get(slug)!.set(entryLang, entry);
  }

  const articles: FaqArticle[] = [];
  for (const [slug, langMap] of [...bySlug.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
    const enEntry = langMap.get('en');
    if (!enEntry) continue;
    const picked = langMap.get(lang) ?? enEntry;
    const data = picked.data;
    articles.push({
      slug,
      lang: (data.lang as string) || lang,
      cover: (data.cover as string | undefined) ?? (enEntry.data.cover as string | undefined),
      faqTopic: (data.faqTopic as string) || (enEntry.data.faqTopic as string) || '',
      faqSubtopic: (data.faqSubtopic as string | undefined) ?? (enEntry.data.faqSubtopic as string | undefined),
      seoTitle: (data.seoTitle as string) || (data.heading as string) || slug,
      seoDescription: (data.seoDescription as string) || (data.subtitle as string) || '',
      heading: (data.heading as string) || slug,
      subtitle: data.subtitle as string | undefined,
      items: (data.items as FaqItem[]) || [],
    });
  }
  return articles;
}

/** Unique FAQ article slugs (requires an English entry). */
export async function getFaqArticleSlugs(): Promise<string[]> {
  const articles = await getFaqArticles('en');
  return articles.map(article => article.slug);
}

/** Active topic and topic/subtopic filter paths that contain at least one FAQ article. */
export async function getActiveFaqFilterPaths(): Promise<Array<{ path: string; topic: string; subtopic?: string }>> {
  const articles = await getFaqArticles('en');
  const seen = new Set<string>();
  const filters: Array<{ path: string; topic: string; subtopic?: string }> = [];

  for (const article of articles) {
    if (article.faqTopic && !seen.has(article.faqTopic)) {
      seen.add(article.faqTopic);
      filters.push({ path: article.faqTopic, topic: article.faqTopic });
    }
    if (article.faqTopic && article.faqSubtopic) {
      const subPath = `${article.faqTopic}/${article.faqSubtopic}`;
      if (!seen.has(subPath)) {
        seen.add(subPath);
        filters.push({ path: subPath, topic: article.faqTopic, subtopic: article.faqSubtopic });
      }
    }
  }
  return filters;
}

/** Topic tree for sidebar and mobile chips, with article counts and links for non-empty topics/subtopics. */
export function buildTopicCards(lang: string, topics: FaqTopic[], articles: FaqArticle[]): FaqTopicCard[] {
  return topics.map(topic => {
    const topicArticles = articles.filter(article => article.faqTopic === topic.key);
    const topicCount = topicArticles.length;
    return {
      ...topic,
      count: topicCount,
      href: topicCount > 0 ? faqPath(lang, topic.key) : undefined,
      subtopics: (topic.subtopics ?? []).map(sub => {
        const subCount = topicArticles.filter(article => article.faqSubtopic === sub.key).length;
        return {
          key: sub.key,
          title: sub.title,
          count: subCount,
          href: subCount > 0 ? faqPath(lang, `${topic.key}/${sub.key}`) : undefined,
        };
      }),
    };
  });
}
