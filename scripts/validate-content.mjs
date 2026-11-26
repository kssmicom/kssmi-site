#!/usr/bin/env node
/**
 * Pre-build validation script for content files
 * Checks all markdown files for common YAML frontmatter and filesystem issues.
 * This runs before dev/build to catch errors early and exits non-zero on hard errors.
 *
 * Validates all six collections defined in src/content.config.ts:
 *   - src/content/products/          (product markdown, recursive)
 *   - src/content/collection/        (landing pages, about, contact, recursive)
 *   - src/content/faq/               (FAQ hub and topic pages, recursive)
 *   - src/content/blog/              (blog posts, recursive; empty allowed)
 *   - src/content/feature/           (eyewear feature pages, recursive; empty allowed)
 *   - src/content/category-product/  (category SEO metadata, top-level *.md)
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import yaml from 'js-yaml';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const DEFAULT_CONTENT_ROOT = path.resolve(__dirname, '../src/content');

export function getDefaultCollections(contentRoot = DEFAULT_CONTENT_ROOT) {
  return [
    { name: 'products', dir: path.join(contentRoot, 'products'), recursive: true, required: true, allowEmpty: false },
    { name: 'collection', dir: path.join(contentRoot, 'collection'), recursive: true, required: true, allowEmpty: false },
    { name: 'faq', dir: path.join(contentRoot, 'faq'), recursive: true, required: true, allowEmpty: false },
    // Git does not track empty directories, so optional empty collections may be absent on clean CI checkouts.
    { name: 'blog', dir: path.join(contentRoot, 'blog'), recursive: true, required: false, allowEmpty: true },
    { name: 'feature', dir: path.join(contentRoot, 'feature'), recursive: true, required: false, allowEmpty: true },
    { name: 'category-product', dir: path.join(contentRoot, 'category-product'), recursive: false, required: true, allowEmpty: false },
  ];
}

function formatFsError(err) {
  return err && err.code ? `${err.code}: ${err.message}` : (err?.message || String(err));
}

const BLOCK_SCALAR_HEADER_RE = /^(?:[^:#\n]+?:\s*|-\s+)([|>](?:[+-]?\d|\d[+-]|[+-])?)\s*(?:#.*)?$/;

function getLineIndent(line) {
  return line.length - line.trimStart().length;
}

function extractTopLevelLeadingSpaceError(lines) {
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    if (getLineIndent(line) > 0 && !trimmed.startsWith('-')) {
      const keyMatch = line.match(/^\s+([a-zA-Z0-9_-]+)/);
      if (keyMatch) {
        return `Line ${i + 2}: Leading space on key "${keyMatch[1]}" - keys should not be indented`;
      }
    }
    break;
  }
  return null;
}

/**
 * Scan frontmatter lines when YAML parsing fails, skipping YAML block scalar bodies
 * (`|`, `>`, `|-`, `|+`, `>-`, etc.) so multiline prose lines containing colons,
 * dashes, or leading spaces are not misclassified.
 */
function collectStructuralDiagnostics(lines) {
  const diagnostics = [];
  const seenRootKeys = new Map();
  let blockScalar = null;

  const leadingError = extractTopLevelLeadingSpaceError(lines);
  if (leadingError) diagnostics.push(leadingError);

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 2; // +2 because frontmatter starts after opening --- on line 1
    const trimmedLine = line.trim();
    const indent = getLineIndent(line);

    if (blockScalar) {
      if (trimmedLine === '') {
        continue;
      }
      if (blockScalar.contentIndent === null) {
        if (indent > blockScalar.parentIndent) {
          blockScalar.contentIndent = indent;
          continue;
        }
        blockScalar = null;
      } else if (indent >= blockScalar.contentIndent) {
        continue;
      } else {
        blockScalar = null;
      }
    }

    if (trimmedLine === '' || trimmedLine.startsWith('#')) {
      continue;
    }

    const headerMatch = trimmedLine.match(BLOCK_SCALAR_HEADER_RE);
    if (headerMatch) {
      if (indent === 0 && !trimmedLine.startsWith('- ')) {
        const colonIdx = trimmedLine.indexOf(':');
        const key = trimmedLine.slice(0, colonIdx).trim();
        if (key) {
          if (seenRootKeys.has(key)) {
            diagnostics.push(`Line ${lineNum}: Duplicate key "${key}" (first defined at line ${seenRootKeys.get(key)})`);
          } else {
            seenRootKeys.set(key, lineNum);
          }
        }
      }
      const digitMatch = headerMatch[1].match(/\d/);
      const explicitIndent = digitMatch ? indent + Number(digitMatch[0]) : null;
      blockScalar = { parentIndent: indent, contentIndent: explicitIndent };
      continue;
    }

    if (trimmedLine.startsWith('- ')) {
      if (indent === 0) {
        let hasSequenceParent = false;
        for (let j = i - 1; j >= 0; j--) {
          const prev = lines[j];
          const prevTrimmed = prev.trim();
          if (prevTrimmed === '' || prevTrimmed.startsWith('#')) continue;
          const prevIndent = getLineIndent(prev);
          if (prevIndent === 0 && (prevTrimmed.endsWith(':') || prevTrimmed.startsWith('- '))) {
            hasSequenceParent = true;
          }
          break;
        }
        if (!hasSequenceParent) {
          diagnostics.push(`Line ${lineNum}: Array item "- ${trimmedLine.slice(2).substring(0, 60)}" not under a valid key`);
        }
      }
      continue;
    }

    const colonIndex = line.indexOf(':');
    if (colonIndex !== -1 && indent === 0) {
      const key = line.slice(0, colonIndex).trim();
      if (key && !key.startsWith('"') && !key.startsWith("'")) {
        if (seenRootKeys.has(key)) {
          diagnostics.push(`Line ${lineNum}: Duplicate key "${key}" (first defined at line ${seenRootKeys.get(key)})`);
        } else {
          seenRootKeys.set(key, lineNum);
        }
      }
    }
  }

  return diagnostics;
}

/**
 * Check if frontmatter is valid
 */
export function validateFrontmatter(content, filePath, { baseDir = process.cwd() } = {}) {
  const errors = [];
  const warnings = [];
  const relativePath = path.relative(baseDir, filePath);

  // Check for UTF-8 BOM; strip only for subsequent checks so other errors are also reported
  const hasBom = content.charCodeAt(0) === 0xFEFF;
  if (hasBom) {
    errors.push('File starts with UTF-8 BOM (Byte Order Mark) - should be removed');
  }
  const normalized = hasBom ? content.slice(1) : content;

  // Check for CRLF vs LF (warning only)
  if (normalized.includes('\r\n')) {
    warnings.push('File uses CRLF line endings (Windows) - LF is preferred');
  }

  // Match frontmatter strictly (must open on line 1 and close cleanly on its own --- line)
  const frontmatterMatch = normalized.match(/^---\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/);
  if (!frontmatterMatch) {
    errors.push('No valid frontmatter found - must start with --- on line 1 and close with ---');
    return { file: relativePath, valid: false, errors, warnings };
  }

  const frontmatter = frontmatterMatch[1];
  const lines = frontmatter.split(/\r?\n/);

  // === Primary YAML parse validation ===
  // Uses js-yaml as the authoritative parser so valid YAML multiline scalars
  // (|, |-, |+, >, >-, nested multiline fields, multiline quoted/plain text)
  // are accepted while invalid indentation, nested duplicates, and syntax errors fail closed.
  let parsed;
  try {
    parsed = yaml.load(frontmatter);
  } catch (e) {
    for (const diagnostic of collectStructuralDiagnostics(lines)) {
      errors.push(diagnostic);
    }
    errors.push(`YAML syntax error: ${e.message}`);
    return {
      file: relativePath,
      valid: false,
      errors,
      warnings
    };
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    errors.push('Frontmatter must parse to a YAML mapping object');
    return {
      file: relativePath,
      valid: false,
      errors,
      warnings
    };
  }

  // Reject globally indented top-level frontmatter mappings (js-yaml tolerates uniform indent).
  const leadingError = extractTopLevelLeadingSpaceError(lines);
  if (leadingError) {
    errors.push(leadingError);
    return {
      file: relativePath,
      valid: false,
      errors,
      warnings
    };
  }

  // Check gallery schema and cover duplication
  const cover = parsed.cover;
  const gallery = parsed.gallery;

  if ('gallery' in parsed && gallery === null) {
    errors.push('gallery is null — use "gallery: []" for empty galleries');
  }

  if (Array.isArray(gallery) && cover && typeof cover === 'string') {
    // Normalize paths for comparison (strip leading slash if present)
    const coverNormalized = cover.replace(/^\/+/, '').toLowerCase();
    for (const [idx, img] of gallery.entries()) {
      if (typeof img === 'string' && img.replace(/^\/+/, '').toLowerCase() === coverNormalized) {
        warnings.push(`gallery[${idx}] "${img}" is the same as cover image — remove extra thumbnail`);
      }
    }
  }

  return {
    file: relativePath,
    valid: errors.length === 0,
    errors,
    warnings
  };
}

/**
 * Find markdown files under a directory without swallowing access or read errors.
 */
export function findMarkdownFiles(dir, { recursive = true, required = true, baseDir = process.cwd() } = {}) {
  const files = [];
  const errors = [];
  const displayDir = path.relative(baseDir, dir) || dir;

  let stat;
  try {
    stat = fs.statSync(dir);
  } catch (err) {
    if (err && err.code === 'ENOENT') {
      if (!required) {
        return { files, errors, missingAllowed: true };
      }
      errors.push(`Required content directory not found: ${displayDir}`);
    } else {
      errors.push(`Failed to access content directory ${displayDir}: ${formatFsError(err)}`);
    }
    return { files, errors, missingAllowed: false };
  }

  if (!stat.isDirectory()) {
    errors.push(`Content path is not a directory: ${displayDir}`);
    return { files, errors, missingAllowed: false };
  }

  function walk(currentDir) {
    const currentDisplay = path.relative(baseDir, currentDir) || currentDir;
    let entries;
    try {
      entries = fs.readdirSync(currentDir, { withFileTypes: true });
    } catch (err) {
      errors.push(`Failed to read directory ${currentDisplay}: ${formatFsError(err)}`);
      return;
    }

    entries.sort((a, b) => a.name.localeCompare(b.name));

    for (const entry of entries) {
      const full = path.join(currentDir, entry.name);
      if (entry.isDirectory()) {
        if (recursive) {
          walk(full);
        }
      } else if (entry.name.endsWith('.md')) {
        files.push(full);
      }
    }
  }

  walk(dir);
  return { files, errors, missingAllowed: false };
}

/**
 * Validate all files in a single collection without masking read or directory errors.
 */
export function validateCollection(collection, { baseDir = process.cwd() } = {}) {
  const { files, errors: directoryErrors, missingAllowed } = findMarkdownFiles(collection.dir, {
    recursive: collection.recursive !== false,
    required: collection.required !== false,
    baseDir
  });
  const displayDir = path.relative(baseDir, collection.dir) || collection.dir;

  if (directoryErrors.length === 0 && files.length === 0 && !collection.allowEmpty) {
    directoryErrors.push(`Required collection "${collection.name}" contains no markdown files: ${displayDir}`);
  }

  const results = [];
  for (const file of files) {
    try {
      const content = fs.readFileSync(file, 'utf-8');
      results.push(validateFrontmatter(content, file, { baseDir }));
    } catch (err) {
      results.push({
        file: path.relative(baseDir, file),
        valid: false,
        errors: [`Failed to read file: ${formatFsError(err)}`],
        warnings: []
      });
    }
  }

  return {
    name: collection.name,
    dir: collection.dir,
    allowEmpty: Boolean(collection.allowEmpty),
    missingAllowed: Boolean(missingAllowed),
    fileCount: files.length,
    results,
    directoryErrors
  };
}

/**
 * Main validation function
 */
export function validateAllFiles({ contentRoot = DEFAULT_CONTENT_ROOT, collections, baseDir = process.cwd(), quiet = false } = {}) {
  const log = quiet ? () => {} : console.log;
  const logError = quiet ? () => {} : console.error;
  const targetCollections = collections || getDefaultCollections(contentRoot);

  log('\n\x1b[36m🔍 Validating content files...\x1b[0m\n');

  const allResults = [];
  const allDirectoryErrors = [];
  const collectionReports = [];
  let totalFiles = 0;

  for (const collection of targetCollections) {
    log(`Content collection [${collection.name}]: ${collection.dir}`);
    const colResult = validateCollection(collection, { baseDir });
    collectionReports.push(colResult);
    allResults.push(...colResult.results);
    for (const dirErr of colResult.directoryErrors) {
      allDirectoryErrors.push({ collection: colResult.name, message: dirErr });
    }
    totalFiles += colResult.fileCount;

    if (colResult.directoryErrors.length > 0) {
      log(`  \x1b[31m${colResult.directoryErrors.length} directory/collection error(s)\x1b[0m\n`);
    } else if (colResult.missingAllowed) {
      log('  0 file(s) found (optional empty directory absent)\n');
    } else if (colResult.fileCount === 0 && colResult.allowEmpty) {
      log('  0 file(s) found (empty collection allowed)\n');
    } else {
      log(`  ${colResult.fileCount} file(s) found\n`);
    }
  }

  const invalidFiles = allResults.filter(r => !r.valid);
  const filesWithWarnings = allResults.filter(r => r.warnings.length > 0);
  const totalFileErrors = allResults.reduce((sum, r) => sum + r.errors.length, 0);
  const totalErrors = allDirectoryErrors.length + totalFileErrors;
  const totalWarnings = allResults.reduce((sum, r) => sum + r.warnings.length, 0);

  if (totalErrors > 0) {
    logError('\x1b[31m❌ Content Validation Failed!\x1b[0m\n');

    for (const dirErr of allDirectoryErrors) {
      logError(`\x1b[31m✗\x1b[0m [${dirErr.collection}] ${dirErr.message}`);
    }
    if (allDirectoryErrors.length > 0) {
      logError('');
    }

    for (const result of invalidFiles) {
      logError(`\x1b[31m✗\x1b[0m ${result.file}`);
      for (const error of result.errors) {
        logError(`      \x1b[31m→\x1b[0m ${error}`);
      }
      logError('');
    }

    logError(`\x1b[31m✗ ${allDirectoryErrors.length} directory/collection error(s), ${invalidFiles.length} invalid file(s), ${totalErrors} total error(s)\x1b[0m\n`);

    return {
      ok: false,
      totalFiles,
      totalErrors,
      totalWarnings,
      invalidFiles,
      directoryErrors: allDirectoryErrors,
      filesWithWarnings,
      collections: collectionReports
    };
  }

  if (filesWithWarnings.length > 0) {
    log('\x1b[33m⚠ Warnings:\x1b[0m\n');
    for (const result of filesWithWarnings) {
      log(`\x1b[33m⚠\x1b[0m ${result.file}`);
      for (const warning of result.warnings) {
        log(`      \x1b[33m→\x1b[0m ${warning}`);
      }
    }
    log('');
  }

  log(`\x1b[32m✅ All ${totalFiles} files validated successfully.\x1b[0m`);

  if (totalWarnings > 0) {
    log(`\x1b[33m   ${totalWarnings} warning(s) (non-critical)\x1b[0m`);
  }

  log('');
  return {
    ok: true,
    totalFiles,
    totalErrors: 0,
    totalWarnings,
    invalidFiles: [],
    directoryErrors: [],
    filesWithWarnings,
    collections: collectionReports
  };
}

export function parseCliArgs(argv) {
  let contentRoot = DEFAULT_CONTENT_ROOT;
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--content-root') {
      const value = argv[i + 1];
      if (!value || value.startsWith('--')) {
        throw new Error('Missing value for --content-root');
      }
      contentRoot = path.resolve(value);
      i++;
    } else {
      throw new Error(`Unknown option: ${arg}`);
    }
  }
  return { contentRoot };
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  try {
    const { contentRoot } = parseCliArgs(process.argv.slice(2));
    const summary = validateAllFiles({ contentRoot });
    if (!summary.ok) {
      process.exitCode = 1;
    }
  } catch (err) {
    console.error(`\x1b[31m❌ ${err.message}\x1b[0m`);
    process.exitCode = 1;
  }
}
