import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => readFileSync(path.join(root, relativePath), 'utf8');
const failures = [];

function assert(condition, message) {
  if (!condition) failures.push(message);
}

const layout = read('src/layouts/Layout.astro');
assert(layout.includes('rel="preload" as="image" href={lcpImage}'), 'Layout must preload the declared LCP image URL.');
assert(!layout.includes('fonts.googleapis.com') && !layout.includes('fonts.gstatic.com'), 'Layout must not inject late Google Fonts that can reflow settled text.');

const globalStyles = read('src/styles/global.css');
assert(globalStyles.includes('--font-sans: system-ui'), 'Primary font utilities must use the stable local system-font stack.');

const productCard = read('src/components/ProductCard.astro');
assert(productCard.includes('index === 0'), 'ProductCard must identify the first card as the LCP candidate.');
assert(productCard.includes('loading={loadingStrategy}'), 'ProductCard must keep conditional eager/lazy loading.');
assert(productCard.includes('fetchpriority={isLcpCandidate ? "high" : "auto"}'), 'ProductCard first image must retain high fetch priority.');

const directListingPages = [
  'src/pages/blog/index.astro',
  'src/pages/blog/[page].astro',
  'src/pages/blog/[category]/[...page].astro',
  'src/pages/eyewear/index.astro',
  'src/pages/eyewear/[page].astro',
  'src/pages/eyewear/[category]/[...page].astro',
  'src/pages/[lang]/blog/index.astro',
  'src/pages/[lang]/blog/[page].astro',
  'src/pages/[lang]/blog/[category]/[...page].astro',
  'src/pages/[lang]/eyewear/index.astro',
  'src/pages/[lang]/eyewear/[page].astro',
  'src/pages/[lang]/eyewear/[category]/[...page].astro',
];

for (const file of directListingPages) {
  const source = read(file);
  assert(source.includes('index: number'), `${file} must track the first visible card.`);
  assert(source.includes('const img = cardSrc('), `${file} must render the same optimized URL that is preloaded.`);
  assert(source.includes("loading={isLcpCandidate ? 'eager' : 'lazy'}"), `${file} must eagerly load only its first card image.`);
  assert(source.includes("fetchpriority={isLcpCandidate ? 'high' : 'auto'}"), `${file} must prioritize only its first card image.`);
}

const categoryPage = read('src/components/pages/ProductCategoryPage.astro');
assert(categoryPage.includes("baseSegment === 'product'"), 'Category LCP selection must support product cover and blog/eyewear image fields.');
assert(categoryPage.includes('cardSrc(firstCardImage)'), 'Category preload must use the rendered card URL helper.');

for (const file of ['src/pages/[slug].astro', 'src/pages/[lang]/[slug].astro']) {
  const source = read(file);
  assert(source.includes('lcpImage={image}'), `${file} hero preload must match its full-width rendered image.`);
  assert(source.includes('loading="eager"') && source.includes('fetchpriority="high"'), `${file} hero must stay eager/high priority.`);
}

for (const file of ['src/pages/product/[slug].astro', 'src/pages/[lang]/product/[slug].astro']) {
  const source = read(file);
  assert(source.includes('lcpImage={cover}'), `${file} must preload its main cover.`);
  assert(!source.includes('videoId'), `${file} must not restore the unused product-video branch.`);
  assert(!source.includes('VideoObject'), `${file} must not emit video schema for products without videos.`);
}

const hero = read('src/components/collection/home/S00_Hero.astro');
assert(hero.includes('loading="eager"') && hero.includes('fetchpriority="high"'), 'Home poster must remain the LCP image.');
assert(hero.includes('preload="none"') && hero.includes('data-src={videoUrl}'), 'Home video URL must stay outside the initial media request.');
assert(hero.includes('pageLoaded') && hero.includes('interactionSeen'), 'Home video must wait for both load and trusted interaction.');
assert(!hero.includes('<h1 class="hero-fade'), 'Home LCP heading must be visible on the first frame, without the entrance fade.');
assert(!hero.includes('navigator.userActivation'), 'Home video must not inherit sticky activation from an earlier page or lifecycle event.');
assert(!hero.includes("addEventListener('scroll'"), 'Home video must not treat generic scroll events as proof of user interaction.');
assert(!hero.includes("addEventListener('pointerdown'"), 'Home video must not start on pointerdown alone.');
assert(hero.includes('event.isTrusted'), 'Home video interaction handlers must reject synthetic events.');
assert(hero.includes('TOUCH_SCROLL_THRESHOLD') && hero.includes("addEventListener('touchmove'"), 'Home video must require thresholded real touch movement on mobile.');
assert(hero.includes("addEventListener('wheel'") && hero.includes('event.deltaX === 0 && event.deltaY === 0'), 'Home video must require real wheel movement on desktop.');
assert(hero.includes("v.dataset.userStarted = '1'") && hero.includes("v.dataset.userStarted !== '1'"), 'Home video loading must be guarded by a current-hero user-started marker.');

if (failures.length) {
  console.error('LCP policy validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('LCP policy validation passed: one prioritized image per visual page and deferred home video.');
