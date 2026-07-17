import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => readFileSync(path.join(root, relativePath), 'utf8');
const failures = [];

function assert(condition, message) {
  if (!condition) failures.push(message);
}

const header = read('src/components/Header.astro');
const languageTrees = header.match(/class="lang-dropdown-container\b/g) ?? [];

assert(languageTrees.length === 1, `Header must contain exactly one language tree; found ${languageTrees.length}.`);
assert(header.includes('id="header-menu-data"'), 'Header must keep one serialized secondary-menu data source.');
assert(header.includes('function renderMenuContent('), 'Header secondary menus must remain interaction-rendered.');
assert(!header.includes('productsMenu?.children'), 'Products mega-menu tree must not be server-rendered.');
assert(!header.includes('eyewearMenu?.children'), 'Eyewear mega-menu tree must not be server-rendered.');
assert(!header.includes('blogMenu?.children'), 'Blog mega-menu tree must not be server-rendered.');
assert(!header.includes('new ResizeObserver(updateHint)'), 'Hidden language menus must not trigger layout measurement during page initialization.');
assert(!header.includes('Initial check: batch read'), 'Language-menu scroll hints must be measured only after interaction.');

const homeCardFiles = [
  'src/components/collection/home/Materials.astro',
  'src/components/collection/home/Construction.astro',
  'src/components/collection/home/S03_FrameShape.astro',
  'src/components/collection/home/S04_FrameStyle.astro',
  'src/components/collection/home/S05_Frame_Color.astro',
  'src/components/collection/home/S06_FrameSurfaceFinish.astro',
];

for (const file of homeCardFiles) {
  const source = read(file);
  assert(!source.includes('m.points?.map('), `${file} must not restore a multi-node feature list per card.`);
  assert(source.includes("m.points?.join(' · ')"), `${file} must retain all feature text in the compact summary.`);
  assert(source.includes('contain-below-fold--lg'), `${file} must defer below-the-fold layout and paint work.`);
}

const astroConfig = read('astro.config.mjs');
assert(astroConfig.includes("inlineStylesheets: 'auto'"), 'Astro must inline small CSS chunks while keeping the main critical bundle external.');

if (failures.length) {
  console.error('DOM policy validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log('DOM policy validation passed: Header trees are lazy/singleton and home card features are compact.');
