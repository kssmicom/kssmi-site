// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import tailwind from '@astrojs/tailwind';
import pagefind from 'astro-pagefind';

// https://astro.build/config
export default defineConfig({
  site: 'https://yeetian.com',
  output: 'static', // Enable API routes to work dynamically
  integrations: [
    sitemap({
      i18n: {
        defaultLocale: 'en',
        locales: {
          en: 'en', it: 'it', es: 'es', fr: 'fr', de: 'de',
          pt: 'pt', ru: 'ru', ja: 'ja', tr: 'tr', ar: 'ar'
        }
      }
    }),
    tailwind({
      applyBaseStyles: false, // We'll import CSS manually in Layout
    }),
    pagefind()
  ],
  i18n: {
    defaultLocale: 'en',
    locales: [
      'en', // English
      'it', // Italian
      'es', // Spanish
      'fr', // French
      'de', // German
      'pt', // Portuguese
      'ru', // Russian
      'ja', // Japanese
      'tr', // Turkish
      'ar' // Arabic (RTL - Right-to-Left)
    ],
    routing: {
      prefixDefaultLocale: false // yeetian.com (EN), yeetian.com/it/ (IT)
    }
  },
  build: {
    format: 'directory' // Creates /index.html for clean URLs
  }
});
