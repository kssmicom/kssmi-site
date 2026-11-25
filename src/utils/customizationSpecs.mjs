/**
 * Pure validation, formatting, and textarea-synchronization helpers for
 * Customization component selections handed off to /quote/.
 *
 * Designed to work identically in Astro SSR, browser ES modules, and Node tests.
 */

export const SPEC_STORAGE_KEY = 'kssmi-selected-specs';
export const SPEC_CODE_PATTERN = /^KS-[A-Z]{2,4}-\d{2,3}$/;
export const MAX_SELECTED_SPECS = 24;
export const MAX_RAW_SPECS_LENGTH = 512;
export const MAX_STORAGE_LENGTH = 4096;
export const MAX_SPECS_SUMMARY_LENGTH = 1500;
export const DETAILS_SPECS_HEADER = 'Selected customization components:';
export const CUSTOMIZATION_INQUIRY_PRODUCT_NAME = 'Customization Components Inquiry';

function hasOwn(obj, key) {
  return Object.prototype.hasOwnProperty.call(obj, key);
}

/**
 * Build a safe lookup map `{ [normalizedCode]: canonicalTitle }` from CUSTOMIZATION_GROUPS.
 */
export function buildCatalogSpecMap(groups) {
  const map = Object.create(null);
  if (!Array.isArray(groups)) return map;

  for (const group of groups) {
    if (!group || !Array.isArray(group.subcategories)) continue;
    for (const sub of group.subcategories) {
      if (!sub || !Array.isArray(sub.items)) continue;
      for (const item of sub.items) {
        if (!item || typeof item.code !== 'string') continue;
        const normalizedCode = item.code.trim().toUpperCase();
        if (!SPEC_CODE_PATTERN.test(normalizedCode)) continue;
        const title = typeof item.title === 'string' ? item.title.replace(/[\u0000-\u001f\u007f]/g, '').trim() : '';
        map[normalizedCode] = title || normalizedCode;
      }
    }
  }
  return map;
}

/**
 * Validate a comma-separated `specs` query value against format, length, count,
 * deduplication, and membership in `validCatalogMap`.
 */
export function parseAndValidateSpecCodes(rawInput, validCatalogMap, options = {}) {
  const maxCount = Number.isInteger(options.maxCount) && options.maxCount > 0 ? options.maxCount : MAX_SELECTED_SPECS;
  const maxRawLength = Number.isInteger(options.maxRawLength) && options.maxRawLength > 0
    ? options.maxRawLength
    : MAX_RAW_SPECS_LENGTH;

  if (typeof rawInput !== 'string' || rawInput.trim() === '') {
    return { specs: [], ignoredCount: 0, truncated: false, rejectedReason: null };
  }

  if (rawInput.length > maxRawLength) {
    return { specs: [], ignoredCount: 1, truncated: true, rejectedReason: 'too_long' };
  }

  if (!validCatalogMap || typeof validCatalogMap !== 'object') {
    return { specs: [], ignoredCount: 1, truncated: false, rejectedReason: 'missing_catalog' };
  }

  const tokens = rawInput.split(',');
  const seen = new Set();
  const specs = [];
  let ignoredCount = 0;
  let truncated = false;

  for (const rawToken of tokens) {
    const trimmed = rawToken.trim();
    if (!trimmed) {
      ignoredCount += 1;
      continue;
    }
    const normalized = trimmed.toUpperCase();
    if (!SPEC_CODE_PATTERN.test(normalized) || !hasOwn(validCatalogMap, normalized)) {
      ignoredCount += 1;
      continue;
    }
    if (seen.has(normalized)) {
      continue;
    }
    if (specs.length >= maxCount) {
      truncated = true;
      ignoredCount += 1;
      continue;
    }
    seen.add(normalized);
    const title = String(validCatalogMap[normalized] || normalized).replace(/[\u0000-\u001f\u007f]/g, '').trim();
    specs.push({ code: normalized, title });
  }

  return {
    specs,
    ignoredCount,
    truncated,
    rejectedReason: specs.length === 0 && ignoredCount > 0 ? 'no_valid_codes' : null,
  };
}

/**
 * Validate serialized sessionStorage array entries against format, cap, and catalog existence.
 * Canonicalizes titles directly from `validCatalogMap` instead of trusting stored strings.
 */
export function validateStoredSpecs(rawStorageValue, validCatalogMap, options = {}) {
  const maxStorageLength = Number.isInteger(options.maxStorageLength) && options.maxStorageLength > 0
    ? options.maxStorageLength
    : MAX_STORAGE_LENGTH;
  const maxCount = Number.isInteger(options.maxCount) && options.maxCount > 0 ? options.maxCount : MAX_SELECTED_SPECS;
  const maxRawLength = Number.isInteger(options.maxRawLength) && options.maxRawLength > 0
    ? options.maxRawLength
    : MAX_RAW_SPECS_LENGTH;

  if (typeof rawStorageValue !== 'string' || rawStorageValue.trim() === '') {
    return { specs: [], sanitized: false };
  }

  if (rawStorageValue.length > maxStorageLength) {
    return { specs: [], sanitized: true };
  }

  let parsed;
  try {
    parsed = JSON.parse(rawStorageValue);
  } catch {
    return { specs: [], sanitized: true };
  }

  if (!Array.isArray(parsed) || !validCatalogMap || typeof validCatalogMap !== 'object') {
    return { specs: [], sanitized: true };
  }

  const seen = new Set();
  const specs = [];
  let sanitized = false;
  let joinedLength = 0;

  for (const entry of parsed) {
    if (!entry || typeof entry !== 'object' || typeof entry.code !== 'string') {
      sanitized = true;
      continue;
    }
    const normalized = entry.code.trim().toUpperCase();
    if (!SPEC_CODE_PATTERN.test(normalized) || !hasOwn(validCatalogMap, normalized)) {
      sanitized = true;
      continue;
    }
    if (seen.has(normalized) || specs.length >= maxCount) {
      sanitized = true;
      continue;
    }
    const nextJoinedLength = joinedLength + (specs.length > 0 ? 1 : 0) + normalized.length;
    if (nextJoinedLength > maxRawLength) {
      sanitized = true;
      continue;
    }
    const canonicalTitle = String(validCatalogMap[normalized] || normalized).replace(/[\u0000-\u001f\u007f]/g, '').trim();
    if (entry.code !== normalized || entry.title !== canonicalTitle) {
      sanitized = true;
    }
    seen.add(normalized);
    joinedLength = nextJoinedLength;
    specs.push({ code: normalized, title: canonicalTitle });
  }

  return { specs, sanitized };
}

/**
 * Format validated customization components for customer-visible inclusion in #details.
 */
export function formatSelectedSpecsBlock(specs, maxLength = MAX_SPECS_SUMMARY_LENGTH) {
  if (!Array.isArray(specs) || specs.length === 0) return '';

  const fullBlock = [
    DETAILS_SPECS_HEADER,
    ...specs.map((item) => (item.title ? `- ${item.code} (${item.title})` : `- ${item.code}`)),
  ].join('\n');

  if (fullBlock.length <= maxLength) return fullBlock;

  const compactLines = [DETAILS_SPECS_HEADER];
  for (const item of specs) {
    const candidateLine = `- ${item.code}`;
    const candidate = `${compactLines.join('\n')}\n${candidateLine}`;
    if (candidate.length > maxLength) break;
    compactLines.push(candidateLine);
  }
  return compactLines.join('\n').slice(0, maxLength);
}

/**
 * Synchronize the selected customization block into `#details` without overwriting
 * user-typed copy or duplicating content across repeated initialization / refreshes.
 */
export function syncSpecsIntoDetails(currentDetails, previousAutoBlock, nextSpecs) {
  const details = typeof currentDetails === 'string' ? currentDetails : '';
  const prevBlock = typeof previousAutoBlock === 'string' ? previousAutoBlock : '';
  const nextBlock = formatSelectedSpecsBlock(nextSpecs);

  if (prevBlock && prevBlock === nextBlock && nextBlock && details.includes(nextBlock)) {
    return { value: details, autoBlock: nextBlock };
  }

  if (prevBlock && details.includes(prevBlock)) {
    const idx = details.indexOf(prevBlock);
    const before = details.slice(0, idx);
    const after = details.slice(idx + prevBlock.length);

    if (!nextBlock) {
      if (before.trim() === '' && after.trim() === '') {
        return { value: '', autoBlock: '' };
      }
      if (before.trim() === '') {
        return { value: after.replace(/^\r?\n+/, ''), autoBlock: '' };
      }
      if (after.trim() === '') {
        return { value: before.replace(/\r?\n+$/, ''), autoBlock: '' };
      }
      return {
        value: `${before.replace(/\r?\n+$/, '')}\n\n${after.replace(/^\r?\n+/, '')}`,
        autoBlock: '',
      };
    }

    return { value: `${before}${nextBlock}${after}`, autoBlock: nextBlock };
  }

  if (!nextBlock) {
    return { value: details, autoBlock: '' };
  }

  if (details.includes(nextBlock)) {
    return { value: details, autoBlock: nextBlock };
  }

  if (details.trim() === '') {
    return { value: nextBlock, autoBlock: nextBlock };
  }

  return { value: `${nextBlock}\n\n${details}`, autoBlock: nextBlock };
}
