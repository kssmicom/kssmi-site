import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

// ─────────────────────────────────────────────────────────────────────────────
// REUSABLE ZOD BUILDING BLOCKS
// Use these to compose page-specific schemas without repeating yourself.
// ─────────────────────────────────────────────────────────────────────────────

export const LangEnum = z.enum([
  'en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja',
  'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'
]);

/** Short hero-style block: headline + supporting text + optional CTA */
export const HeroBlock = z.object({
  headline: z.string(),
  subtext: z.string(),
  badge: z.string().optional(),
  cta: z.string().optional(),
  ctaLink: z.string().optional(),
  image: z.string().optional(),
  imageAlt: z.string().optional(),
});

/** A numbered step list with a section title */
export const StepsBlock = z.object({
  badge: z.string().optional(),
  title: z.string(),
  description: z.string().optional(),
  steps: z.array(z.object({
    number: z.string(),
    title: z.string(),
    desc: z.string(),
  })),
});

/** Key–value stat counters (e.g. "300,000+ / Monthly Frames") */
export const StatsBlock = z.array(z.object({
  value: z.string(),
  label: z.string(),
}));

/** Standard FAQ list */
export const FAQBlock = z.object({
  badge: z.string().optional(),
  title: z.string().optional(),
  items: z.array(z.object({
    question: z.string(),
    answer: z.string(),
  })),
});

/** Feature/benefit card grid */
export const FeaturesBlock = z.object({
  badge: z.string().optional(),
  title: z.string(),
  description: z.string().optional(),
  items: z.array(z.object({
    title: z.string(),
    description: z.string(),
    icon: z.string().optional(),
    image: z.string().optional(),
  })),
});

/** Simple CTA banner */
export const CTABlock = z.object({
  headline: z.string(),
  subtext: z.string().optional(),
  buttonText: z.string(),
  buttonLink: z.string().optional(),
});

/** SEO + routing metadata — used in every meta.{lang}.md file */
export const MetaSchema = z.object({
  lang: LangEnum,
  slug: z.string(),
  title: z.string(),
  seoTitle: z.string(),
  seoDescription: z.string(),
  seoKeywords: z.string().optional(),
  ogImage: z.string().optional(),
  fileType: z.literal('meta'),
});

// ─────────────────────────────────────────────────────────────────────────────
// CONTENT COLLECTIONS
// generateId uses the FULL relative path to prevent collisions between pages.
// e.g. "acetate-sunglass-manufacturer/meta.en" not just "meta.en"
// ─────────────────────────────────────────────────────────────────────────────

const generateFullPathId = ({ entry }: { entry: string }) =>
  entry.replace(/\\/g, '/').replace(/\.md$/, '');

// ── Products ──────────────────────────────────────────────────────────────────
const products = defineCollection({
  loader: glob({
    pattern: '**/*.md',
    base: './src/content/products',
    generateId: generateFullPathId,
  }),
  schema: z.object({
    title: z.string(),
    slug: z.string().optional(),
    cover: z.string().optional(),
    gallery: z.array(z.string()).optional(),
    videoId: z.string().optional(),
    itemNo: z.string().optional(),
    colors: z.string().optional(),
    serviceMode: z.string().default('OEM, ODM'),
    price: z.string().optional(),
    customizable: z.boolean().default(true),
    materials: z.string().optional(),
    featured: z.boolean().default(false),
    moq: z.string().default('100 PCS/Model'),
    categories: z.string().optional(),
    date: z.string().optional(),
    size: z.string().optional(),
    frameMaterial: z.string().optional(),
    lensMaterial: z.string().optional(),
    designStyle: z.string().optional(),
    nosePads: z.string().optional(),
    hinge: z.string().optional(),
    electroplating: z.string().optional(),
    logo: z.string().optional(),
    specService: z.string().optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
    seoKeywords: z.string().optional(),
  }).catchall(z.any()),
});

// ── Collection (Landing Pages + About/Contact/etc.) ───────────────────────────
// NOTE: .catchall(z.any()) is kept intentionally during migration.
// New pages using the meta/top/bottom split will have strict schemas added later.
const collection = defineCollection({
  loader: glob({
    pattern: '**/*.md',
    base: './src/content/collection',
    generateId: generateFullPathId,
  }),
  schema: z.object({
    lang: LangEnum.default('en'),
    title: z.string().optional(),
    slug: z.string().optional(),
    image: z.string().optional(),
    layout: z.enum(['full-width', 'with-sidebar']).default('full-width'),
    category: z.enum([
      'Acetate Series',
      'Titanium Series',
      'Metal Alloy Series',
      'Carbon Fiber Series',
      'TR90 & Injection Series',
      'Sustainable Series',
      'OEM & Private Label Services',
      'ODM & Custom Manufacturing',
      'Performance Series',
      'Protective & Specialty Series',
      'Wholesale Collections',
    ]).optional(),
    cta: z.string().optional(),
    ctaLink: z.string().optional(),
    partnershipHero: z.object({
      badge: z.string().optional(),
      headerPrimary: z.string().optional(),
      headerSecondary: z.string().optional(),
      description: z.string().optional(),
      team: z.array(z.object({
        name: z.string(),
        image: z.string(),
        description: z.string(),
      })).optional(),
    }).optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
    seoKeywords: z.string().optional(),
    // New architecture fields
    fileType: z.enum(['meta', 'top', 'bottom', 'page']).optional(),
  }).catchall(z.any()),
});

// ── Blog ──────────────────────────────────────────────────────────────────────
const blog = defineCollection({
  loader: glob({
    pattern: '**/*.md',
    base: './src/content/blog',
    generateId: generateFullPathId,
  }),
  schema: z.object({
    lang: LangEnum.default('en'),
    title: z.string(),
    slug: z.string().optional(),
    image: z.string().optional(),
    category: z.enum([
      'Material Science',
      'Manufacturing Engineering',
      'Quality & Compliance',
      'Supply Chain & Sourcing',
      'Market & Design Trends',
    ]).optional(),
    excerpt: z.string().optional(),
    author: z.string().default('Kssmi Eyewear'),
    published: z.coerce.date(),
    tags: z.array(z.string()).optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
    seoKeywords: z.string().optional(),
    // New architecture fields
    fileType: z.enum(['meta', 'top', 'bottom', 'page']).optional(),
  }).catchall(z.any()),
});

export const collections = { products, collection, blog };
