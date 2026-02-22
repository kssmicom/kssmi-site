import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

// Product Collection (supports 10 languages)
const products = defineCollection({
  loader: glob({
    pattern: '**/*.md',
    base: './src/content/products',
    generateId: ({ entry }) => {
      // Use the full filename without .md extension as ID
      return entry.replace(/\.md$/, '');
    }
  }),
  schema: ({ image }) => z.object({
    title: z.string(),
    slug: z.string().optional(),
    cover: image().optional(),
    gallery: z.array(image()).optional(),
    videoId: z.string().optional(),
    itemNo: z.string().optional(),
    colors: z.array(z.string()).optional(),
    serviceMode: z.array(z.string()).default(['OEM', 'ODM']),
    price: z.string().optional(),
    customizable: z.boolean().default(true),
    materials: z.array(z.string()).optional(),
    featured: z.boolean().default(false),
    moq: z.number().default(100),
    categories: z.array(z.string()).optional(),
    specs: z.object({
      size: z.string().optional(),
      frameMaterial: z.string().optional(),
      lensMaterial: z.string().optional(),
      designStyle: z.string().optional(),
      nosePads: z.string().optional(),
      hinge: z.string().optional(),
      electroplating: z.string().optional(),
      logo: z.array(z.string()).optional(),
    }).optional(),
    date: z.coerce.date().optional(),
  })
});

// Landing Page Collection
const landing = defineCollection({
  type: 'content',
  schema: ({ image }) => z.object({
    lang: z.enum(['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar']),
    title: z.string(),
    slug: z.string(),
    image: image().optional(),  // ✅ Use image() helper
    layout: z.enum(['full-width', 'with-sidebar']).default('full-width'),
    cta: z.string().optional(),
    ctaLink: z.string().optional(),
  })
});

// Blog Collection
const blog = defineCollection({
  type: 'content',
  schema: ({ image }) => z.object({
    lang: z.enum(['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar']).default('en'),
    title: z.string(),
    slug: z.string(),
    image: image().optional(),  // ✅ Use image() helper
    excerpt: z.string().optional(),
    author: z.string().default('KSSMI Eyewear'),
    published: z.coerce.date(),
    tags: z.array(z.string()).optional(),
  })
});

export const collections = { products, landing, blog };
