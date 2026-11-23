import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { projectRoot, ensureTemporaryRoot, createTask } from '../scripts/local-tools.mjs';
import {
  collectKeyPaths,
  LANGUAGES,
} from '../scripts/validate-product-localizations.mjs';

ensureTemporaryRoot();
const taskRoot = createTask('product-localizations-tests', projectRoot);
const validatorScript = path.join(projectRoot, 'scripts/validate-product-localizations.mjs');

let sequence = 0;

function createProductsRoot() {
  const base = path.join(taskRoot, `case-${++sequence}`);
  const productsDir = path.join(base, 'products');
  fs.mkdirSync(productsDir, { recursive: true });
  return { base, productsDir };
}

function writeFile(filePath, content, { bom = false } = {}) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  const payload = bom ? Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(content, 'utf8')]) : Buffer.from(content, 'utf8');
  fs.writeFileSync(filePath, payload);
}

const SAMPLE_LOCALE_TEXT = {
  en: {
    title: 'Custom Titanium Optical Frame',
    seoTitle: 'KTO-001 Custom Titanium Frame | Kssmi',
    seoDesc: 'Custom titanium eyewear frame engineered for B2B private label collections.',
    seoKeywords: 'titanium optical frame, custom eyewear factory',
    categories: 'Optical Frames, Titanium Frames',
    size: '52-18-145mm & Customization',
    moq: '300 PCS/Model (100PCS/Color)',
    body: '## Technical Features\n\n- Lightweight aerospace titanium chassis for daily wear.\n- Precision spring hinges and custom logo branding.\n\n## Overview\n\nDetailed OEM & ODM manufacturing overview with balanced paragraphs for optical brands worldwide.',
  },
  it: { prefix: 'Montatura Ottica Titanio', cat: 'Montature Ottiche, Titanio' },
  es: { prefix: 'Montura Óptica Titanio todo el día', cat: 'Monturas Ópticas, Titanio' },
  fr: { prefix: 'Monture Optique Titane', cat: 'Montures Optiques, Titane' },
  de: { prefix: 'Titan Brillenfassung', cat: 'Optische Fassungen, Titan' },
  pt: { prefix: 'Armação Óptica Titânio todo o dia', cat: 'Armações Ópticas, Titânio' },
  ru: { prefix: 'Оправа из титана', cat: 'Оптические оправы, Титан' },
  ja: { prefix: 'カスタムチタン眼鏡フレーム', cat: '眼鏡フレーム、チタン' },
  tr: { prefix: 'Özel Titanyum Optik Çerçeve', cat: 'Optik Çerçeveler, Titanyum' },
  ar: { prefix: 'إطار بصري من التيتانيوم مخصص', cat: 'الإطارات البصرية، تيتانيوم' },
  ko: { prefix: '맞춤형 티타늄 안경테', cat: '안경테, 티타늄' },
  zh: { prefix: '定制钛金属光学镜架', cat: '光学镜架，钛金属' },
  hi: { prefix: 'कस्टम टाइटेनियम ऑप्टिकल फ्रेम', cat: 'ऑप्टिकल फ्रेम्स, टाइटेनियम' },
  vi: { prefix: 'Gọng kính Titan tùy chỉnh', cat: 'Gọng kính, Titan' },
  jv: { prefix: 'Bingkai Optik Titanium Khusus', cat: 'Bingkai Optik, Titanium' },
  ms: { prefix: 'Bingkai Optik Titanium Tersuai', cat: 'Bingkai Optik, Titanium' },
  tg: { prefix: 'Чорчӯбаи оптикии титании фармоишӣ', cat: 'Чорчӯбаҳои оптикӣ, Титан' },
};

function makeProductMd(slug, lang, overrides = {}) {
  const enBase = SAMPLE_LOCALE_TEXT.en;
  const locInfo = SAMPLE_LOCALE_TEXT[lang];
  const isEn = lang === 'en';
  const title = overrides.title ?? (isEn ? enBase.title : locInfo.prefix);
  const seoTitle = overrides.seoTitle ?? (isEn ? enBase.seoTitle : `${locInfo.prefix} SEO | Kssmi`);
  const seoDesc =
    overrides.seoDescription ??
    (isEn ? enBase.seoDesc : `${locInfo.prefix} — private label OEM ODM B2B manufacturing solution.`);
  const seoKeywords =
    overrides.seoKeywords ?? (isEn ? enBase.seoKeywords : `${locInfo.prefix}, OEM ODM`);
  const categories = overrides.categories ?? (isEn ? enBase.categories : locInfo.cat);
  const body =
    overrides.body ??
    (isEn
      ? enBase.body
      : `## ${locInfo.prefix}\n\n- ${locInfo.prefix} B2B OEM ODM lightweight frame construction for brands.\n- Custom private label finish and flexible MOQ packaging.\n\n## Overview\n\n${locInfo.prefix} detailed optical frame overview crafted for discerning distributors and global eyewear labels.`);

  const fm = {
    title,
    slug: overrides.slug ?? slug,
    itemNo: overrides.itemNo ?? 'KTO-001',
    date: overrides.date ?? '2026-05-01',
    colors: overrides.colors ?? 'Black',
    materials: overrides.materials ?? (isEn ? 'Beta Titanium' : `${locInfo.prefix} Material`),
    serviceMode: overrides.serviceMode ?? (isEn ? 'OEM & ODM' : `${locInfo.prefix} OEM`),
    moq: overrides.moq ?? enBase.moq,
    productType: overrides.productType ?? 'Optical Frames',
    material: overrides.material ?? 'Titanium Optical Frames',
    series: overrides.series ?? 'optical-frames, titanium-optical-frames',
    categories,
    size: overrides.size ?? enBase.size,
    frameMaterial: overrides.frameMaterial ?? (isEn ? 'Titanium' : `${locInfo.prefix} Frame`),
    lensMaterial: overrides.lensMaterial ?? (isEn ? 'Nylon' : `${locInfo.prefix} Lens`),
    designStyle: overrides.designStyle ?? (isEn ? 'Classic, Unisex' : `${locInfo.prefix} Classic`),
    nosePads: overrides.nosePads ?? (isEn ? 'Silicone' : `${locInfo.prefix} Pads`),
    hinge: overrides.hinge ?? (isEn ? '3+2 Barrel Hinge' : `${locInfo.prefix} Hinge`),
    logo: overrides.logo ?? (isEn ? 'Laser, Print' : `${locInfo.prefix} Logo`),
    service: overrides.service ?? (isEn ? 'OEM / ODM Customization' : `${locInfo.prefix} Service`),
    cover: overrides.cover ?? `/media/products/${slug}/${slug}-1.webp`,
    gallery: overrides.gallery ?? [
      `/media/products/${slug}/${slug}-2.webp`,
      `/media/products/${slug}/${slug}-3.webp`,
    ],
    customizable: overrides.customizable ?? true,
    featured: overrides.featured ?? true,
    seoTitle,
    seoDescription: seoDesc,
    seoKeywords,
  };

  if (overrides.omitKey) delete fm[overrides.omitKey];
  if (overrides.extraEntries) Object.assign(fm, overrides.extraEntries);

  const yamlLines = [];
  for (const [k, v] of Object.entries(fm)) {
    if (Array.isArray(v)) {
      yamlLines.push(`${k}:`);
      for (const item of v) yamlLines.push(`  - ${JSON.stringify(item)}`);
    } else if (v && typeof v === 'object') {
      yamlLines.push(`${k}:`);
      for (const [sk, sv] of Object.entries(v)) {
        yamlLines.push(`  ${sk}: ${JSON.stringify(sv)}`);
      }
    } else if (typeof v === 'boolean' || typeof v === 'number') {
      yamlLines.push(`${k}: ${v}`);
    } else {
      yamlLines.push(`${k}: ${JSON.stringify(v)}`);
    }
  }

  return `---\n${yamlLines.join('\n')}\n---\n\n${body}\n`;
}

function seedSku(productsDir, slug, perLangOverrides = {}, dirName = slug) {
  for (const lang of LANGUAGES) {
    const content = makeProductMd(slug, lang, perLangOverrides[lang] || {});
    writeFile(path.join(productsDir, dirName, `${slug}.${lang}.md`), content);
  }
}

function runValidator(productsDir, args) {
  return spawnSync(process.execPath, [validatorScript, ...args, '--products-dir', productsDir], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 30000,
  });
}

test('collectKeyPaths extracts flat and nested object key paths accurately', () => {
  const keys = collectKeyPaths({
    title: 'A',
    nested: { bridge: '18mm', depth: { temple: '145mm' } },
    gallery: ['/a.webp'],
  });
  assert.deepEqual([...keys].sort(), ['gallery', 'nested', 'nested.bridge', 'nested.depth', 'nested.depth.temple', 'title']);
});

test('valid single SKU and --all catalog pass with localized categories, Latin short coincidences, Spanish "todo", and report JSON', () => {
  const { base, productsDir } = createProductsRoot();
  seedSku(productsDir, 'kto-001-frame');
  seedSku(productsDir, 'kto-002-frame');

  // Single SKU passes exit 0 (while Spanish "todo el día", Portuguese "todo o dia",
  // identical Latin colors: "Black", and localized `categories` are cleanly allowed)
  const singleRes = runValidator(productsDir, ['kto-001-frame']);
  assert.equal(singleRes.status, 0, singleRes.stderr + singleRes.stdout);
  assert.match(singleRes.stdout, /1 SKU\(s\) \(1\/1 complete across 17 languages\), 17 file\(s\), 0 hard errors/);

  const reportOut = path.join(base, 'report.json');
  const allRes = runValidator(productsDir, ['--all', '--report-out', reportOut]);
  assert.equal(allRes.status, 0, allRes.stderr + allRes.stdout);
  const report = JSON.parse(fs.readFileSync(reportOut, 'utf8'));
  assert.equal(report.summary.skusChecked, 2);
  assert.equal(report.summary.skusWithAll17Languages, 2);
  assert.equal(report.summary.filesChecked, 34);
  assert.equal(report.summary.errorCount, 0);
});

test('missing language file, unknown language file, and missing English baseline fail with exit 1', () => {
  const fxMissingLang = createProductsRoot();
  seedSku(fxMissingLang.productsDir, 'kto-miss');
  fs.unlinkSync(path.join(fxMissingLang.productsDir, 'kto-miss/kto-miss.tg.md'));
  writeFile(path.join(fxMissingLang.productsDir, 'kto-miss/kto-miss.xx.md'), makeProductMd('kto-miss', 'en'));
  const res1 = runValidator(fxMissingLang.productsDir, ['kto-miss']);
  assert.equal(res1.status, 1);
  assert.match(res1.stderr, /MISSING_LANGUAGE_FILE/);
  assert.match(res1.stderr, /UNKNOWN_LANGUAGE_FILE/);

  const fxNoEn = createProductsRoot();
  seedSku(fxNoEn.productsDir, 'kto-noen');
  fs.unlinkSync(path.join(fxNoEn.productsDir, 'kto-noen/kto-noen.en.md'));
  const res2 = runValidator(fxNoEn.productsDir, ['kto-noen']);
  assert.equal(res2.status, 1);
  assert.match(res2.stderr, /MISSING_ENGLISH_BASELINE/);
});

test('missing keys, translated top-level keys, and nested object key mismatches fail with MISSING_KEY / EXTRA_KEY', () => {
  const { productsDir } = createProductsRoot();
  seedSku(productsDir, 'kto-keys', {
    en: { extraEntries: { specs: { bridge: '18mm', temple: '145mm' } } },
    ko: { omitKey: 'frameMaterial', extraEntries: { '안경테 소재': '티타늄', specs: { bridge: '18mm', temple: '145mm' } } },
    vi: { omitKey: 'hinge', extraEntries: { brace: 'Bản lề 2+1', specs: { bridge: '18mm', temple: '145mm' } } },
    de: { extraEntries: { specs: { steg: '18mm', temple: '145mm' } } },
  });

  // Fill matching `specs` on other locales so only ko, vi, de mismatch
  for (const lang of LANGUAGES) {
    if (['en', 'ko', 'vi', 'de'].includes(lang)) continue;
    writeFile(
      path.join(productsDir, `kto-keys/kto-keys.${lang}.md`),
      makeProductMd('kto-keys', lang, { extraEntries: { specs: { bridge: '18mm', temple: '145mm' } } })
    );
  }

  const res = runValidator(productsDir, ['kto-keys']);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /MISSING_KEY/);
  assert.match(res.stderr, /EXTRA_KEY/);
  assert.match(res.stderr, /안경테 소재/);
  assert.match(res.stderr, /specs\.bridge/);
});

test('machine field drift, media sequence mismatch, and derived cover ref fail with exit 1', () => {
  const { productsDir } = createProductsRoot();
  seedSku(productsDir, 'kto-mach', {
    fr: { series: 'translated-optical-frames', itemNo: 'WRONG-999' },
    es: {
      gallery: [
        '/media/products/kto-mach/kto-mach-3.webp',
        '/media/products/kto-mach/kto-mach-2.webp',
      ],
    },
    it: { cover: '/media/products/kto-mach/kto-mach-1-400.webp' },
  });

  const res = runValidator(productsDir, ['kto-mach']);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /MACHINE_FIELD_MISMATCH/);
  assert.match(res.stderr, /MEDIA_SEQUENCE_MISMATCH/);
  assert.match(res.stderr, /DERIVED_MEDIA_REF/);
});

test('empty body, empty localized field, untranslated fields, missing script, literal \\n, and uppercase placeholders fail gate', () => {
  const fx = createProductsRoot();
  seedSku(fx.productsDir, 'kto-trans', {
    de: { body: '' },
    fr: { seoKeywords: '' },
    it: { title: SAMPLE_LOCALE_TEXT.en.title },
    pt: { seoTitle: SAMPLE_LOCALE_TEXT.en.seoTitle },
    hi: { title: 'Custom Titanium Optical Frame' },
    ja: { body: '## カスタムチタン\n\n\\n 改行リテラルを含みます TODO' },
  });

  const res = runValidator(fx.productsDir, ['kto-trans']);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /EMPTY_BODY/);
  assert.match(res.stderr, /EMPTY_LOCALIZED_FIELD/);
  assert.match(res.stderr, /UNTRANSLATED_TITLE/);
  assert.match(res.stderr, /UNTRANSLATED_SEO_FIELD/);
  assert.match(res.stderr, /MISSING_TARGET_SCRIPT/);
  assert.match(res.stderr, /LITERAL_ESCAPED_NEWLINE/);
  assert.match(res.stderr, /PLACEHOLDER_TOKEN/);
});

test('review advisories (numeric/unit drift, short body, heading/bullet drift, folder casing) warn cleanly without failing exit 0', () => {
  const { base, productsDir } = createProductsRoot();
  seedSku(
    productsDir,
    'kto-adv',
    {
      ja: { moq: '300 個/モデル (1色100個)' },
      de: { size: '52-18-145 & Individualisierung' },
      zh: { body: '钛金属光学镜架简短说明。' },
      es: { body: '## Enfoque Única\n\nTexto continuo sin encabezados secundarios ni lista de puntos para revisar.' },
    },
    'kto-ADV'
  );

  const reportOut = path.join(base, 'adv-report.json');
  const res = runValidator(productsDir, ['kto-adv', '--report-out', reportOut]);
  assert.equal(res.status, 0, res.stderr + res.stdout);

  const report = JSON.parse(fs.readFileSync(reportOut, 'utf8'));
  assert.equal(report.summary.errorCount, 0);
  assert.ok(report.advisoriesByCode.DIR_SLUG_CASE_MISMATCH >= 1);
  assert.ok(report.advisoriesByCode.NUMERIC_TOKEN_DRIFT >= 1);
  assert.ok(report.advisoriesByCode.UNIT_TOKEN_DRIFT >= 1);
  assert.ok(report.advisoriesByCode.BODY_LENGTH_RATIO_ADVISORY >= 1);
  assert.ok(report.advisoriesByCode.HEADING_COUNT_DRIFT >= 1);
  assert.ok(report.advisoriesByCode.BULLET_COUNT_DRIFT >= 1);
});

test('fail-closed CLI usage errors, unknown SKU, UTF-8 BOM, malformed YAML, and missing dir exit 1', () => {
  const fx = createProductsRoot();
  seedSku(fx.productsDir, 'kto-cli');

  // BOM and bad YAML
  writeFile(path.join(fx.productsDir, 'kto-cli/kto-cli.de.md'), makeProductMd('kto-cli', 'de'), { bom: true });
  writeFile(path.join(fx.productsDir, 'kto-cli/kto-cli.fr.md'), '---\ntitle: [broken\n---\n');
  const badFilesRes = runValidator(fx.productsDir, ['kto-cli']);
  assert.equal(badFilesRes.status, 1);
  assert.match(badFilesRes.stderr, /UTF8_BOM/);
  assert.match(badFilesRes.stderr, /CONTENT_PARSE_ERROR/);

  // Bare call without <slug> or --all rejected
  assert.equal(runValidator(fx.productsDir, []).status, 1);
  // Both <slug> and --all rejected
  assert.equal(runValidator(fx.productsDir, ['kto-cli', '--all']).status, 1);
  // Unknown flag rejected
  assert.equal(runValidator(fx.productsDir, ['--all', '--bogus']).status, 1);
  // Unknown SKU rejected
  const unknownSku = runValidator(fx.productsDir, ['no-such-sku']);
  assert.equal(unknownSku.status, 1);
  assert.match(unknownSku.stderr, /UNKNOWN_SKU/);
  // Missing products dir rejected
  const missingDir = runValidator(path.join(fx.base, 'no-products'), ['--all']);
  assert.equal(missingDir.status, 1);
  assert.match(missingDir.stderr, /PRODUCTS_DIR_ERROR/);
});
