import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  CUSTOMIZATION_INQUIRY_PRODUCT_NAME,
  DETAILS_SPECS_HEADER,
  MAX_RAW_SPECS_LENGTH,
  MAX_SELECTED_SPECS,
  SPEC_CODE_PATTERN,
  buildCatalogSpecMap,
  formatSelectedSpecsBlock,
  parseAndValidateSpecCodes,
  syncSpecsIntoDetails,
  validateStoredSpecs,
} from '../src/utils/customizationSpecs.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const catalogSource = fs.readFileSync(path.join(projectRoot, 'src/data/customizationCatalog.ts'), 'utf8');
const catalogCodes = [...catalogSource.matchAll(/"code":\s*"([^"]+)"/g)].map((m) => m[1]);
const catalogTitles = [...catalogSource.matchAll(/"title":\s*"([^"]+)"/g)].map((m) => m[1]);
const REAL_CATALOG_MAP = Object.fromEntries(catalogCodes.map((code, index) => [code, catalogTitles[index] || code]));

test('buildCatalogSpecMap handles structured groups and rejects unsafe codes', () => {
  const map = buildCatalogSpecMap([
    {
      subcategories: [
        {
          items: [
            { code: 'ks-ln-001', title: '  Grey Lens (CAT.1)\u0000  ' },
            { code: 'KS-PL-01', title: 'Electroplating Silver' },
            { code: '__proto__', title: 'Bad Prototype' },
            { code: 'INVALID-CODE', title: 'Wrong Format' },
          ],
        },
      ],
    },
  ]);

  assert.equal(Object.getPrototypeOf(map), null);
  assert.equal(map['KS-LN-001'], 'Grey Lens (CAT.1)');
  assert.equal(map['KS-PL-01'], 'Electroplating Silver');
  assert.equal('__proto__' in map, false);
  assert.equal('INVALID-CODE' in map, false);
});

test('all 367 codes in customizationCatalog.ts satisfy SPEC_CODE_PATTERN', () => {
  assert.equal(catalogCodes.length, 367);
  assert.equal(new Set(catalogCodes).size, 367);
  for (const code of catalogCodes) {
    assert.match(code, SPEC_CODE_PATTERN, `Unexpected code format: ${code}`);
  }
});

test('parseAndValidateSpecCodes accepts single, multi-digit, and deduplicated valid catalog codes', () => {
  const single = parseAndValidateSpecCodes('ks-ln-001', REAL_CATALOG_MAP);
  assert.deepEqual(single.specs, [{ code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' }]);
  assert.equal(single.ignoredCount, 0);
  assert.equal(single.rejectedReason, null);

  const multi = parseAndValidateSpecCodes('KS-PL-01, KS-LN-001, KS-PL-01, KS-CF-091', REAL_CATALOG_MAP);
  assert.deepEqual(
    multi.specs.map((item) => item.code),
    ['KS-PL-01', 'KS-LN-001', 'KS-CF-091'],
  );
  assert.equal(multi.ignoredCount, 0);
});

test('parseAndValidateSpecCodes rejects empty, unknown, prototype-key, malformed, and overlong inputs', () => {
  assert.deepEqual(parseAndValidateSpecCodes('', REAL_CATALOG_MAP), {
    specs: [],
    ignoredCount: 0,
    truncated: false,
    rejectedReason: null,
  });

  const invalidOnly = parseAndValidateSpecCodes('KS-XX-999,constructor,__proto__,<script>alert(1)</script>', REAL_CATALOG_MAP);
  assert.deepEqual(invalidOnly.specs, []);
  assert.equal(invalidOnly.ignoredCount, 4);
  assert.equal(invalidOnly.rejectedReason, 'no_valid_codes');

  const mixed = parseAndValidateSpecCodes('KS-FAKE-01, KS-LN-002, toString, KS-LN-001', REAL_CATALOG_MAP);
  assert.deepEqual(mixed.specs.map((s) => s.code), ['KS-LN-002', 'KS-LN-001']);
  assert.equal(mixed.ignoredCount, 2);
  assert.equal(mixed.rejectedReason, null);

  const overlong = parseAndValidateSpecCodes('KS-LN-001,'.repeat(80), REAL_CATALOG_MAP);
  assert.deepEqual(overlong.specs, []);
  assert.equal(overlong.truncated, true);
  assert.equal(overlong.rejectedReason, 'too_long');
  assert.ok(overlong.specs.length === 0 && 'KS-LN-001,'.repeat(80).length > MAX_RAW_SPECS_LENGTH);

  const manyValid = catalogCodes.slice(0, MAX_SELECTED_SPECS + 5).join(',');
  assert.ok(manyValid.length <= MAX_RAW_SPECS_LENGTH);
  const capped = parseAndValidateSpecCodes(manyValid, REAL_CATALOG_MAP);
  assert.equal(capped.specs.length, MAX_SELECTED_SPECS);
  assert.equal(capped.truncated, true);
  assert.equal(capped.ignoredCount, 5);
});

test('validateStoredSpecs strips tampered entries, forged titles, corrupt JSON, and oversized storage', () => {
  assert.deepEqual(validateStoredSpecs('not json', REAL_CATALOG_MAP), { specs: [], sanitized: true });
  assert.deepEqual(validateStoredSpecs('{"code":"KS-LN-001"}', REAL_CATALOG_MAP), { specs: [], sanitized: true });
  assert.deepEqual(validateStoredSpecs('x'.repeat(5000), REAL_CATALOG_MAP), { specs: [], sanitized: true });

  const tampered = JSON.stringify([
    { code: 'ks-ln-001', title: '<img src=x onerror=alert(1)>' },
    { code: 'KS-FAKE-999', title: 'Bogus' },
    { code: '__proto__', title: 'Proto' },
    { code: 'KS-LN-001', title: 'Duplicate' },
    42,
  ]);
  const res = validateStoredSpecs(tampered, REAL_CATALOG_MAP);
  assert.equal(res.sanitized, true);
  assert.deepEqual(res.specs, [{ code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' }]);

  const clean = JSON.stringify([{ code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' }]);
  assert.deepEqual(validateStoredSpecs(clean, REAL_CATALOG_MAP), {
    specs: [{ code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' }],
    sanitized: false,
  });
});

test('formatSelectedSpecsBlock and syncSpecsIntoDetails preserve user body text and stay idempotent', () => {
  const s1 = { code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' };
  const s2 = { code: 'KS-CF-091', title: 'Honeycomb Silver Sheet (N39535S)' };

  const block12 = formatSelectedSpecsBlock([s1, s2]);
  assert.ok(block12.startsWith(DETAILS_SPECS_HEADER));
  assert.ok(block12.includes('- KS-LN-001 (Grey Lens (CAT.1))'));
  assert.ok(block12.includes('- KS-CF-091 (Honeycomb Silver Sheet (N39535S))'));

  // Initial sync into empty textarea
  const initRes = syncSpecsIntoDetails('', '', [s1, s2]);
  assert.equal(initRes.value, block12);
  assert.equal(initRes.autoBlock, block12);

  // User adds message body, then sync runs again with same selection -> no duplication
  const withUserText = `${initRes.value}\n\nTarget quantity: 500 pcs matte black.`;
  const repeatRes = syncSpecsIntoDetails(withUserText, initRes.autoBlock, [s1, s2]);
  assert.equal(repeatRes.value, withUserText);

  // Also idempotent even if previousAutoBlock was empty (e.g. reload with browser form restore)
  const reloadRestoreRes = syncSpecsIntoDetails(withUserText, '', [s1, s2]);
  assert.equal(reloadRestoreRes.value, withUserText);

  // Removing one component replaces only the managed block and preserves user text
  const removeOneRes = syncSpecsIntoDetails(withUserText, initRes.autoBlock, [s2]);
  assert.equal(removeOneRes.value, `${formatSelectedSpecsBlock([s2])}\n\nTarget quantity: 500 pcs matte black.`);

  // Clearing all components removes only the managed block and leaves clean user text
  const clearAllRes = syncSpecsIntoDetails(removeOneRes.value, removeOneRes.autoBlock, []);
  assert.equal(clearAllRes.value, 'Target quantity: 500 pcs matte black.');
  assert.equal(clearAllRes.autoBlock, '');

  // Pre-existing user text gets preserved below newly injected specs
  const prepended = syncSpecsIntoDetails('Existing user draft.', '', [s1]);
  assert.equal(prepended.value, `${formatSelectedSpecsBlock([s1])}\n\nExisting user draft.`);

  // Bounded compact fallback when many verbose titles would exceed summary cap
  const oversizedList = catalogCodes.slice(0, 24).map((code) => ({
    code,
    title: 'Very Long Customization Component Specification Name '.repeat(2),
  }));
  const boundedBlock = formatSelectedSpecsBlock(oversizedList);
  assert.ok(boundedBlock.length <= 1500);
  assert.ok(boundedBlock.startsWith(DETAILS_SPECS_HEADER));
  assert.equal(CUSTOMIZATION_INQUIRY_PRODUCT_NAME, 'Customization Components Inquiry');
});
