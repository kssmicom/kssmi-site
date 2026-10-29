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

// 1. Sitemaps — a flat root index listing per-language urlset files only.
// The sitemap protocol forbids an index referencing another index, so a
// nested index or any stray <loc> must fail the build.
const rootIndexPath = join(distDir, 'sitemap.xml');
const hasRootIndex = existsSync(rootIndexPath);
if (!hasRootIndex) fail('dist/sitemap.xml not found');
const rootIndex = hasRootIndex ? read(rootIndexPath) : '';
const rootLocs = [...rootIndex.matchAll(/<loc>([^<]+)<\/loc>/g)].map(([, loc]) => loc);
if (hasRootIndex && rootLocs.length === 0) fail('root sitemap.xml lists no child sitemaps');

const listedChildFiles = new Set();
const languages = new Set();
let urlCount = 0;
for (const loc of rootLocs) {
  const child = loc.replace(`${SITE_URL}/`, '');
  // Only language sub-sitemap files may be referenced — page URLs or another
  // index (e.g. <lang>/sitemap.xml) mean the index is nested or malformed.
  if (!/^\/?[a-z]{2}\/sitemap-.+\.xml$/.test(child) || child.endsWith('/sitemap.xml')) {
    fail(`root sitemap.xml must list sitemap-*.xml urlsets only, found ${loc}`);
    continue;
  }
  const childPath = join(distDir, child);
  if (!existsSync(childPath)) {
    fail(`root sitemap.xml lists missing ${loc}`);
    continue;
  }
  listedChildFiles.add(child);
  languages.add(child.split('/')[0]);
  const xml = read(childPath);
  if (xml.includes('<sitemapindex')) fail(`${child} is itself a sitemap index — nested indexes are not allowed`);
  for (const [, url] of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    urlCount += 1;
    const pathname = new URL(url).pathname;
    const page = join(distDir, pathname, 'index.html');
    if (!existsSync(page)) {
      fail(`${child}: ${url} has no built page`);
      continue;
    }
    if (isNoindex(read(page))) fail(`${child}: ${url} is noindex`);
  }
  for (const [, image] of xml.matchAll(/<image:loc>([^<]+)<\/image:loc>/g)) {
    if (/-(120w|400)\.\w+$/.test(image)) fail(`${child}: image ${image} is a thumbnail, not the main image`);
    if (!existsSync(join(distDir, new URL(image).pathname))) fail(`${child}: image ${image} does not exist`);
  }
}

// No language directory may keep a per-language index or an unlisted child:
// leftovers would ship to production and reintroduce the nested index.
for (const entry of readdirSync(distDir, { withFileTypes: true }).filter((e) => e.isDirectory())) {
  const langDir = join(distDir, entry.name);
  if (!existsSync(join(langDir, 'sitemap.xml')) && !readdirSync(langDir).some((name) => /^sitemap-.+\.xml$/.test(name))) continue;
  languages.add(entry.name);
  if (existsSync(join(langDir, 'sitemap.xml'))) fail(`${entry.name}/sitemap.xml must not exist — the root index lists sitemap-*.xml files directly`);
  for (const file of readdirSync(langDir).filter((name) => /^sitemap-.+\.xml$/.test(name))) {
    if (!listedChildFiles.has(`${entry.name}/${file}`)) fail(`${entry.name}/${file} exists but is not listed in the root sitemap.xml`);
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
  for (const lang of [...languages].filter((code) => code !== 'en')) {
    const page = join(distDir, lang, 'faq', 'index.html');
    if (existsSync(page) && titleOf(page) === enTitle) fail(`${lang}/faq/ still uses the English title`);
  }
}

if (failures.length > 0) {
  console.error(`SEO output validation failed (${failures.length}):`);
  for (const message of failures.slice(0, 50)) console.error(`- ${message}`);
  process.exit(1);
}
console.log(`SEO output validation passed (${languages.size} languages, ${urlCount} sitemap URLs).`);
