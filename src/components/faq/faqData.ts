/**
 * FAQ hub + sub-FAQ content helpers.
 *
 * Content layout (folder name = URL):
 *   src/content/collection/faq/index/faq.{lang}.md     → /faq/          (hub)
 *   src/content/collection/faq/<topic>/<topic>.{lang}.md → /faq/<topic>/  (sub-FAQ)
 *
 * The hub declares the topics (key + localized title) and UI labels. A sub-FAQ
 * page sets `faqTopic: <key>`; when it exists, the hub's topic card links to it,
 * otherwise the card jumps to that topic's group on the hub.
 */
import { getCollection, getEntry, type CollectionEntry } from 'astro:content';

export interface FaqItem {
  question: string;
  answer: string;
  topic?: string;
}

export interface FaqTopic {
  key: string;
  title: string;
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
  href: string;
  count: number;
}

export const faqPath = (lang: string, topic?: string) =>
  `${lang === 'en' ? '' : `/${lang}`}/faq/${topic ? `${topic}/` : ''}`;

export async function getFaqHub(lang: string) {
  // Look the hub up by id: reading data.slug on entries without one logs a deprecation error.
  const entry = await getEntry('collection', `faq/index/faq.${lang}`);
  if (!entry?.data.items) {
    throw new Error(`Missing FAQ content for language: ${lang}`);
  }
  return entry.data as CollectionEntry<'collection'>['data'] & {
    heading: string;
    subtitle: string;
    items: FaqItem[];
    topics: FaqTopic[];
    ui: FaqUi;
  };
}

/** All sub-FAQ entries, any language. */
export async function getFaqTopicEntries() {
  return getCollection('collection', ({ id, data }) =>
    id.startsWith('faq/') && !id.startsWith('faq/index/') && typeof data.faqTopic === 'string'
  );
}

/** Sub-FAQ keys that have a page (an English entry is required; other languages fall back to it). */
export async function getFaqTopicKeys() {
  const entries = await getFaqTopicEntries();
  return [...new Set(entries.filter(e => e.data.lang === 'en').map(e => e.data.faqTopic as string))];
}

/** Topic cards for the hub/sub pages: link to the sub-FAQ page when it exists, else to the hub group. */
export function buildTopicCards(lang: string, topics: FaqTopic[], items: FaqItem[], pageKeys: string[]): FaqTopicCard[] {
  return topics.map(topic => ({
    ...topic,
    count: items.filter(item => item.topic === topic.key).length,
    href: pageKeys.includes(topic.key) ? faqPath(lang, topic.key) : `${faqPath(lang)}#topic-${topic.key}`,
  }));
}
