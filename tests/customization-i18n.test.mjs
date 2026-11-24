import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import ts from 'typescript';

const projectRoot = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const i18nDir = path.join(projectRoot, 'src/data/customization-i18n');
const catalogPath = path.join(projectRoot, 'src/data/customizationCatalog.ts');

const LANGUAGES = [
  'en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr',
  'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg',
];

const SCRIPT_PATTERNS = {
  ru: /[\u0400-\u04FF]/,
  tg: /[\u0400-\u04FF]/,
  ar: /[\u0600-\u06FF]/,
  ja: /[\u3040-\u30FF\u4E00-\u9FFF]/,
  ko: /[\uAC00-\uD7AF]/,
  zh: /[\u4E00-\u9FFF]/,
  hi: /[\u0900-\u097F]/,
};

function loadLocaleBundle(lang) {
  const filePath = path.join(i18nDir, `${lang}.ts`);
  const src = fs.readFileSync(filePath, 'utf8');
  const transpiled = ts.transpileModule(src, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', transpiled)(mod.exports, () => ({}), mod);
  return mod.exports.default;
}

const enBundle = loadLocaleBundle('en');
const catalogGroups = JSON.parse(
  fs
    .readFileSync(catalogPath, 'utf8')
    .match(/export const CUSTOMIZATION_GROUPS:\s*CustomizationGroup\[\]\s*=\s*(\[[\s\S]*?\n\]);/)[1],
);

test('all 17 languages have complete customization bundles with real non-English translations', () => {
  const canonicalTitleSet = new Set();
  const canonicalNoteSet = new Set();
  for (const g of catalogGroups) {
    for (const s of g.subcategories) {
      for (const item of s.items) {
        canonicalTitleSet.add(item.title);
        canonicalNoteSet.add(item.note);
      }
    }
  }

  for (const lang of LANGUAGES) {
    const bundle = loadLocaleBundle(lang);
    assert.equal(bundle.lang, lang, `bundle.lang mismatch for ${lang}`);

    // UI keys + placeholders
    assert.deepEqual(Object.keys(bundle.ui).sort(), Object.keys(enBundle.ui).sort(), `UI keys mismatch for ${lang}`);
    for (const [k, enVal] of Object.entries(enBundle.ui)) {
      const val = bundle.ui[k];
      assert.equal(typeof val, 'string');
      assert.ok(val.trim().length > 0, `${lang}: empty ui.${k}`);
      for (const ph of enVal.match(/\{[a-z]+\}/g) || []) {
        assert.ok(val.includes(ph), `${lang}: ui.${k} missing placeholder ${ph}`);
      }
      if (lang !== 'en' && k !== 'altTemplate') {
        assert.notEqual(val.trim(), enVal.trim(), `${lang}: ui.${k} left untranslated`);
      }
    }

    // Groups & subcategories
    assert.deepEqual(Object.keys(bundle.groups).sort(), Object.keys(enBundle.groups).sort(), `groups mismatch for ${lang}`);
    assert.deepEqual(
      Object.keys(bundle.subcategories).sort(),
      Object.keys(enBundle.subcategories).sort(),
      `subcategories mismatch for ${lang}`,
    );
    for (const [slug, gVal] of Object.entries(bundle.groups)) {
      assert.ok(gVal.name?.trim() && gVal.shortName?.trim(), `${lang}: empty group ${slug}`);
    }
    for (const [subKey, subVal] of Object.entries(bundle.subcategories)) {
      assert.ok(subVal?.trim(), `${lang}: empty subcategory ${subKey}`);
    }

    // Badges
    assert.deepEqual(Object.keys(bundle.badges).sort(), Object.keys(enBundle.badges).sort(), `badges mismatch for ${lang}`);
    assert.ok(bundle.blueBlockPrefix?.trim(), `${lang}: empty blueBlockPrefix`);

    // Copy blocks (all + 6 groups)
    assert.deepEqual(Object.keys(bundle.copy).sort(), Object.keys(enBundle.copy).sort(), `copy keys mismatch for ${lang}`);
    for (const [key, enCopy] of Object.entries(enBundle.copy)) {
      const loc = bundle.copy[key];
      for (const field of ['seoTitle', 'seoDescription', 'heading', 'intro', 'guideTitle', 'guideSubtitle', 'faqTitle']) {
        assert.ok(loc[field]?.trim(), `${lang}: empty copy.${key}.${field}`);
        if (lang !== 'en') {
          assert.notEqual(loc[field].trim(), enCopy[field].trim(), `${lang}: untranslated copy.${key}.${field}`);
        }
      }
      assert.equal(loc.quickPills.length, enCopy.quickPills.length, `${lang}: quickPills count mismatch`);
      assert.equal(loc.tableHeaders.length, enCopy.tableHeaders.length, `${lang}: tableHeaders count mismatch`);
      assert.equal(loc.tableRows.length, enCopy.tableRows.length, `${lang}: tableRows count mismatch`);
      assert.equal(loc.engineeringTips.length, enCopy.engineeringTips.length, `${lang}: tips count mismatch`);
      assert.equal(loc.faqs.length, enCopy.faqs.length, `${lang}: faqs count mismatch`);
      if (SCRIPT_PATTERNS[lang]) {
        assert.match(loc.heading, SCRIPT_PATTERNS[lang], `${lang}: copy.${key}.heading missing expected script`);
        assert.match(loc.intro, SCRIPT_PATTERNS[lang], `${lang}: copy.${key}.intro missing expected script`);
      }
    }

    // Item titles & notes parity with customizationCatalog.ts
    assert.deepEqual(
      Object.keys(bundle.titles).sort(),
      [...canonicalTitleSet].sort(),
      `${lang}: titles keys mismatch catalog`,
    );
    assert.deepEqual(
      Object.keys(bundle.notes).sort(),
      [...canonicalNoteSet].sort(),
      `${lang}: notes keys mismatch catalog`,
    );

    if (lang !== 'en') {
      let sameTitleCount = 0;
      let sameNoteCount = 0;
      for (const tKey of canonicalTitleSet) {
        assert.ok(bundle.titles[tKey]?.trim(), `${lang}: empty title for ${tKey}`);
        if (bundle.titles[tKey].trim() === tKey.trim()) sameTitleCount++;
      }
      for (const nKey of canonicalNoteSet) {
        assert.ok(bundle.notes[nKey]?.trim(), `${lang}: empty note for ${nKey}`);
        if (bundle.notes[nKey].trim() === nKey.trim()) sameNoteCount++;
      }
      assert.ok(sameTitleCount <= 3, `${lang}: too many untranslated item titles (${sameTitleCount})`);
      assert.ok(sameNoteCount === 0, `${lang}: ${sameNoteCount} item notes remained identical to English`);
    }
  }
});

test('all 17 site translation files include nav.customization', () => {
  for (const lang of LANGUAGES) {
    const file = path.join(projectRoot, 'src/translations', `${lang}.ts`);
    const content = fs.readFileSync(file, 'utf8');
    assert.match(content, /\bcustomization:\s*"[^"]+"/, `${lang}.ts missing nav.customization`);
  }
});

test('non-English and English customization routes and Header nav render without English-only lock', () => {
  const header = fs.readFileSync(path.join(projectRoot, 'src/components/Header.astro'), 'utf8');
  assert.doesNotMatch(header, /lang === 'en' \? \[\{ label: 'Customization'/);
  assert.ok(header.includes('t.nav.customization'));
  assert.ok(fs.existsSync(path.join(projectRoot, 'src/pages/[lang]/customization/[...path].astro')));
  const enPage = fs.readFileSync(path.join(projectRoot, 'src/pages/customization/[...path].astro'), 'utf8');
  assert.doesNotMatch(enPage, /noHreflang=\{true\}/);
});
