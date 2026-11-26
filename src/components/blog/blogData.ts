/**
 * Blog post content helpers + two-level sidebar topic tree.
 *
 * Content layout (every subfolder under src/content/blog/ is a blog article; NO index/ folder):
 *   src/content/blog/<slug>/<slug>.{lang}.md  → /blog/<slug>/
 *
 * Listing & filter pages:
 *   /blog/                       → all posts
 *   /blog/<topic>/               → posts under topic
 *   /blog/<topic>/<subtopic>/    → posts under topic/subtopic
 * Sidebar topics/subtopics with >= 1 post link to filter pages; empty nodes render as non-linking labels.
 */
import { getCollection, type CollectionEntry } from 'astro:content';
import {
  BLOG_TOPICS,
  getSubtopicTitle,
  getTopicTitle,
  resolveBlogTopicKey,
} from '../../data/blogTaxonomy';

export interface BlogPostSummary {
  slug: string;
  lang: string;
  title: string;
  seoTitle: string;
  seoDescription: string;
  excerpt: string;
  cover?: string;
  blogTopic: string;
  blogSubtopic?: string;
  category?: string;
  author: string;
  published?: Date;
  tags: string[];
  readingMinutes: number;
  entry: CollectionEntry<'blog'>;
}

export interface BlogSubtopicLink {
  key: string;
  title: string;
  href?: string;
  count: number;
}

export interface BlogTopicNode {
  key: string;
  title: string;
  href?: string;
  count: number;
  subtopics: BlogSubtopicLink[];
}

export const blogPath = (lang: string, segment?: string) =>
  `${lang === 'en' ? '' : `/${lang}`}/blog/${segment ? `${segment}/` : ''}`;

function estimateReadingMinutes(body?: string): number {
  if (!body) return 3;
  const plain = body.replace(/!\[[^\]]*\]\([^)]*\)/g, ' ').replace(/[#*`>|_[\]()]/g, ' ');
  const cjkChars = (plain.match(/[\u4e00-\u9fff\u3040-\u30ff\uac00-\ud7af]/g) || []).length;
  const words = plain.split(/\s+/).filter(Boolean).length;
  const totalUnits = words + cjkChars * 0.5;
  return Math.max(2, Math.round(totalUnits / 220));
}

/** Load all blog articles localized to `lang` (falls back to `en` if a translation file is absent). */
export async function getBlogPosts(lang: string): Promise<BlogPostSummary[]> {
  const entries = await getCollection('blog');
  const bySlug = new Map<string, Map<string, CollectionEntry<'blog'>>>();

  for (const entry of entries) {
    const folder = entry.id.split('/')[0];
    const slug = (typeof entry.data.slug === 'string' && entry.data.slug.trim())
      ? entry.data.slug.trim()
      : folder;
    const entryLang = (entry.data.lang as string) || 'en';
    if (!bySlug.has(slug)) bySlug.set(slug, new Map());
    bySlug.get(slug)!.set(entryLang, entry);
  }

  const posts: BlogPostSummary[] = [];
  for (const [slug, langMap] of bySlug.entries()) {
    const enEntry = langMap.get('en');
    if (!enEntry) continue;
    const picked = langMap.get(lang) ?? enEntry;
    const data = picked.data;
    const enData = enEntry.data;
    const topicKey = resolveBlogTopicKey(
      (data.blogTopic as string | undefined) ?? (enData.blogTopic as string | undefined),
      (data.category as string | undefined) ?? (enData.category as string | undefined),
    );
    const subtopicKey = (data.blogSubtopic as string | undefined) ?? (enData.blogSubtopic as string | undefined);
    const cover = (data.cover as string | undefined)
      ?? (data.image as string | undefined)
      ?? (enData.cover as string | undefined)
      ?? (enData.image as string | undefined);

    posts.push({
      slug,
      lang: (data.lang as string) || lang,
      title: data.title,
      seoTitle: (data.seoTitle as string) || data.title,
      seoDescription: (data.seoDescription as string) || (data.excerpt as string) || '',
      excerpt: (data.excerpt as string) || (data.seoDescription as string) || '',
      cover,
      blogTopic: topicKey,
      blogSubtopic: subtopicKey,
      category: (data.category as string | undefined) ?? (enData.category as string | undefined),
      author: data.author || (lang === 'zh' ? '可米科技' : 'Kssmi Eyewear'),
      published: data.published ?? enData.published,
      tags: Array.isArray(data.tags) ? data.tags : [],
      readingMinutes: estimateReadingMinutes(picked.body),
      entry: picked,
    });
  }

  return posts.sort((a, b) => {
    const tA = a.published ? a.published.getTime() : 0;
    const tB = b.published ? b.published.getTime() : 0;
    if (tA !== tB) return tB - tA;
    return a.slug.localeCompare(b.slug);
  });
}

export async function getBlogSlugs(): Promise<string[]> {
  const posts = await getBlogPosts('en');
  return posts.map((p) => p.slug);
}

/** Active `/blog/<topic>/` and `/blog/<topic>/<subtopic>/` paths that contain at least one post. */
export async function getActiveBlogFilterPaths(): Promise<Array<{ path: string; topic: string; subtopic?: string }>> {
  const posts = await getBlogPosts('en');
  const seen = new Set<string>();
  const filters: Array<{ path: string; topic: string; subtopic?: string }> = [];

  for (const post of posts) {
    if (post.blogTopic && !seen.has(post.blogTopic)) {
      seen.add(post.blogTopic);
      filters.push({ path: post.blogTopic, topic: post.blogTopic });
    }
    if (post.blogTopic && post.blogSubtopic) {
      const subPath = `${post.blogTopic}/${post.blogSubtopic}`;
      if (!seen.has(subPath)) {
        seen.add(subPath);
        filters.push({ path: subPath, topic: post.blogTopic, subtopic: post.blogSubtopic });
      }
    }
  }
  return filters;
}

/** Two-level topic tree for sidebar and mobile chips. */
export function buildBlogTopicTree(lang: string, posts: BlogPostSummary[]): BlogTopicNode[] {
  return BLOG_TOPICS.map((topic) => {
    const topicPosts = posts.filter((p) => p.blogTopic === topic.key);
    const topicCount = topicPosts.length;
    return {
      key: topic.key,
      title: getTopicTitle(topic.key, lang),
      count: topicCount,
      href: topicCount > 0 ? blogPath(lang, topic.key) : undefined,
      subtopics: topic.subtopics.map((sub) => {
        const subCount = topicPosts.filter((p) => p.blogSubtopic === sub.key).length;
        return {
          key: sub.key,
          title: getSubtopicTitle(topic.key, sub.key, lang),
          count: subCount,
          href: subCount > 0 ? blogPath(lang, `${topic.key}/${sub.key}`) : undefined,
        };
      }),
    };
  });
}
