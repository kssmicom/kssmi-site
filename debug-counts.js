import { readdirSync, readFileSync } from 'fs';
import path from 'path';

const contentDir = path.join(process.cwd(), 'src/content/products');
const files = readdirSync(contentDir).filter(f => f.endsWith('.en.md') || (!f.includes('.') && f.endsWith('.md')) || (f.split('.').length === 2 && f.endsWith('.md')));

console.log(`Found ${files.length} English products`);

const CATEGORY_MAP = {
    'luxury customized': 'luxury-customized',
    'luxury sunglasses': 'luxury-sunglasses',
    'luxury glasses': 'luxury-glasses',
    'high couture series': 'high-couture-series',
    'high couture sunglasses': 'high-couture-sunglasses',
    'high couture glasses': 'high-couture-glasses',
    'classics series': 'classics-series',
    'classics sunglasses': 'classics-sunglasses',
    'classics glasses': 'classics-glasses',
    'fashion series': 'fashion-series',
    'acetate sunglasses': 'acetate-sunglasses',
    'metal sunglasses': 'metal-sunglasses',
    'metal glasses': 'metal-glasses',
    'titanium sunglasses': 'titanium-sunglasses',
    'titanium glasses': 'titanium-glasses',
    'rimless series': 'rimless-series',
    'rimless sunglasses': 'rimless-sunglasses',
    'rimless glasses': 'rimless-glasses',
    'carbon series': 'carbon-series',
    'carbon sunglasses': 'carbon-sunglasses',
    'carbon glasses': 'carbon-glasses',
};

function getCanonicalSlug(categoryName) {
    const normalized = categoryName.toLowerCase().trim();
    return CATEGORY_MAP[normalized] || normalized.replace(/\s+/g, '-');
}

let targetSlug = 'luxury-customized';
let count = 0;

for (const file of files) {
    const content = readFileSync(path.join(contentDir, file), 'utf8');
    const match = content.match(/categories:\s*"([^"]+)"/);
    if (match) {
        const cats = match[1].split(',').map(c => c.trim());
        if (cats.some(cat => getCanonicalSlug(cat) === targetSlug)) {
            count++;
        }
    }
}

console.log(`Count for ${targetSlug}: ${count}`);
