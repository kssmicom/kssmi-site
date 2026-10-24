// Checks SEO properties of the built site that are easy to regress:
// sitemaps list only existing, indexable pages with full-size images; the
// legacy inquiry-form fragments are noindex; generated CSS custom properties
// are well formed; the FAQ page title is localized.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const distDir = resolve(process.argv[2] || 'dist');
const SITE_URL = 'https://kssmi.com';
const failures = [];
const fail = (message) => failures.push(message);

if (!existsSync(join(distDir, 'index.html'))) {
  console.error(`validate-seo-output: ${distDir} does not contain a built site`);
  process.exit(1);
}

const read = (file) => readFileSync(file, 'utf8');
const isNoindex = (html) => /<meta\s+name="robots"\s+content="[^"]*noindex/i.test(html);

// 1. Sitemaps
const languages = readdirSync(distDir, { withFileTypes: true })
  .filter((entry) => entry.isDirectory() && existsSync(join(distDir, entry.name, 'sitemap.xml')))
  .map((entry) => entry.name);
if (languages.length === 0) fail('no language sitemap index found in dist');

let urlCount = 0;
for (const lang of languages) {
  const index = read(join(distDir, lang, 'sitemap.xml'));
  for (const [, loc] of index.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    const child = loc.replace(`${SITE_URL}/`, '');
    if (!existsSync(join(distDir, child))) fail(`${lang}/sitemap.xml lists missing ${loc}`);
  }
  for (const file of readdirSync(join(distDir, lang)).filter((name) => /^sitemap-.+\.xml$/.test(name))) {
    const xml = read(join(distDir, lang, file));
    if (!index.includes(`/${lang}/${file}<`)) fail(`${lang}/${file} exists but is not listed in ${lang}/sitemap.xml`);
    for (const [, loc] of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
      urlCount += 1;
      const pathname = new URL(loc).pathname;
      const page = join(distDir, pathname, 'index.html');
      if (!existsSync(page)) {
        fail(`${lang}/${file}: ${loc} has no built page`);
        continue;
      }
      if (isNoindex(read(page))) fail(`${lang}/${file}: ${loc} is noindex`);
    }
    for (const [, image] of xml.matchAll(/<image:loc>([^<]+)<\/image:loc>/g)) {
      if (/-(120w|400)\.\w+$/.test(image)) fail(`${lang}/${file}: image ${image} is a thumbnail, not the main image`);
      if (!existsSync(join(distDir, new URL(image).pathname))) fail(`${lang}/${file}: image ${image} does not exist`);
    }
  }
}

// 2. Legacy inquiry-form fragments must not be indexable
const fragmentRoot = join(distDir, 'inquiry-form');
if (existsSync(fragmentRoot)) {
  for (const lang of readdirSync(fragmentRoot)) {
    const page = join(fragmentRoot, lang, 'index.html');
    if (existsSync(page) && !isNoindex(read(page))) fail(`inquiry-form/${lang}/ is missing robots noindex`);
  }
}

// 3. Generated custom properties ("----x" means a key already had "--")
const home = read(join(distDir, 'index.html'));
if (/----[a-z]/.test(home)) fail('index.html contains a malformed "----" CSS custom property');

// 4. FAQ titles are localized
const titleOf = (file) => (read(file).match(/<title>([^<]*)<\/title>/) || [])[1] || '';
const enFaq = join(distDir, 'faq', 'index.html');
if (existsSync(enFaq)) {
  const enTitle = titleOf(enFaq);
  for (const lang of languages.filter((code) => code !== 'en')) {
    const page = join(distDir, lang, 'faq', 'index.html');
    if (existsSync(page) && titleOf(page) === enTitle) fail(`${lang}/faq/ still uses the English title`);
  }
}

if (failures.length > 0) {
  console.error(`SEO output validation failed (${failures.length}):`);
  for (const message of failures.slice(0, 50)) console.error(`- ${message}`);
  process.exit(1);
}
console.log(`SEO output validation passed (${languages.length} languages, ${urlCount} sitemap URLs).`);
