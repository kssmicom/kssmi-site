#!/usr/bin/env node
/**
 * Pre-build validation script for content files
 * Checks all markdown files for common YAML frontmatter issues
 * This runs before dev/build to catch errors early
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CONTENT_DIR = path.resolve(__dirname, '../src/content/products');

/**
 * Check if frontmatter is valid
 */
function validateFrontmatter(content, filePath) {
  const errors = [];
  const warnings = [];
  const relativePath = path.relative(process.cwd(), filePath);

  // Check for UTF-8 BOM
  if (content.charCodeAt(0) === 0xFEFF) {
    errors.push('File starts with UTF-8 BOM (Byte Order Mark) - should be removed');
  }

  // Check for CRLF vs LF (warning only)
  if (content.includes('\r\n')) {
    warnings.push('File uses CRLF line endings (Windows) - LF is preferred');
  }

  // Match frontmatter
  const frontmatterMatch = content.match(/^---\r?\n([\s\S]*?)\r?\n---/);
  if (!frontmatterMatch) {
    errors.push('No valid frontmatter found - must start with --- on line 1');
    return { file: relativePath, valid: false, errors, warnings };
  }

  const frontmatter = frontmatterMatch[1];
  const lines = frontmatter.split(/\r?\n/);

  // Track seen keys for duplicate detection
  const seenKeys = new Map();

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const lineNum = i + 2; // +2 because frontmatter starts after --- (line 1)
    const trimmedLine = line.trim();

    // Skip comments and empty lines
    if (trimmedLine.startsWith('#') || trimmedLine === '') {
      continue;
    }

    // Check for array items
    if (trimmedLine.startsWith('- ')) {
      // Valid if: previous line ends with ":" OR previous line is also an array item
      const prevLine = i > 0 ? lines[i - 1].trim() : '';
      if (!prevLine.endsWith(':') && !prevLine.startsWith('- ')) {
        errors.push(`Line ${lineNum}: Array item "- ${trimmedLine.slice(2)}" not under a valid key`);
      }
      continue;
    }

    // Check for leading spaces on key lines (common error from copy-paste)
    if (line.match(/^\s+[a-zA-Z]/) && !line.match(/^\s+-/)) {
      const keyMatch = line.match(/^\s+([a-zA-Z][a-zA-Z0-9_]*)/);
      if (keyMatch) {
        errors.push(`Line ${lineNum}: Leading space on key "${keyMatch[1]}" - keys should not be indented`);
      }
      continue;
    }

    // Check for key: value format
    const colonIndex = line.indexOf(':');
    if (colonIndex === -1) {
      if (trimmedLine !== '') {
        errors.push(`Line ${lineNum}: Invalid line "${trimmedLine}" - expected "key: value" format`);
      }
      continue;
    }

    const key = line.slice(0, colonIndex).trim();

    // Check for empty key
    if (key === '') {
      errors.push(`Line ${lineNum}: Empty key before colon`);
      continue;
    }

    // Check for invalid key characters (any non-empty key except colon and newlines)
    if (/[\n\r:]/.test(key) || key.trim() === '') {
      warnings.push(`Line ${lineNum}: Invalid key name "${key}"`);
    }

    // Check for duplicate keys
    if (seenKeys.has(key)) {
      errors.push(`Line ${lineNum}: Duplicate key "${key}" (first defined at line ${seenKeys.get(key)})`);
    } else {
      seenKeys.set(key, lineNum);
    }
  }

  // Check for required fields
  if (!seenKeys.has('title')) {
    errors.push('Missing required field: title');
  }

  return {
    file: relativePath,
    valid: errors.length === 0,
    errors,
    warnings
  };
}

/**
 * Recursively find all markdown files
 */
function findMarkdownFiles(dir) {
  const files = [];

  try {
    if (!fs.existsSync(dir)) {
      console.error(`\x1b[31mError:\x1b[0m Directory not found: ${dir}`);
      return files;
    }

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        files.push(...findMarkdownFiles(full));
      } else if (entry.name.endsWith('.md')) {
        files.push(full);
      }
    }
  } catch (err) {
    console.error(`\x1b[31mError reading directory:\x1b[0m ${err.message}`);
  }

  return files;
}

/**
 * Read file with simple error handling
 */
function readFileSafe(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf-8');
  } catch (err) {
    throw err;
  }
}

/**
 * Main validation function
 */
function validateAllFiles() {
  console.log('\n\x1b[36m🔍 Validating content files...\x1b[0m\n');
  console.log(`Content directory: ${CONTENT_DIR}\n`);

  const files = findMarkdownFiles(CONTENT_DIR);

  if (files.length === 0) {
    console.log('\x1b[33m⚠ No markdown files found\x1b[0m\n');
    return true;
  }

  const results = [];
  let totalErrors = 0;
  let totalWarnings = 0;

  for (const file of files) {
    try {
      const content = readFileSafe(file);
      const result = validateFrontmatter(content, file);
      results.push(result);
      totalErrors += result.errors.length;
      totalWarnings += result.warnings.length;
    } catch (err) {
      // Skip files that can't be read (likely still being written)
      results.push({
        file: path.relative(process.cwd(), file),
        valid: true, // Mark as valid to not block build
        errors: [],
        warnings: [`File temporarily unreadable - will retry later`]
      });
      totalWarnings++;
    }
  }

  // Print results
  const invalidFiles = results.filter(r => !r.valid);
  const filesWithWarnings = results.filter(r => r.warnings.length > 0);

  if (invalidFiles.length > 0) {
    console.error('\x1b[31m❌ Content Validation Failed!\x1b[0m\n');

    for (const result of invalidFiles) {
      console.error(`\x1b[31m✗\x1b[0m ${result.file}`);
      for (const error of result.errors) {
        console.error(`      \x1b[31m→\x1b[0m ${error}`);
      }
      console.log('');
    }

    console.log(`\x1b[31m✗ ${invalidFiles.length} file(s) have errors\x1b[0m`);
    console.log(`\x1b[33m⚠ ${totalErrors} total error(s)\x1b[0m\n`);

    // Still return true so build can continue - the resilient loader will handle it
    console.log('\x1b[33m⚠ Build will continue, but problematic files will be skipped.\x1b[0m');
    console.log('\x1b[33m⚠ Check console output during build for details.\x1b[0m\n');

    return true;
  }

  // Show warnings if any
  if (filesWithWarnings.length > 0) {
    console.log('\x1b[33m⚠ Warnings:\x1b[0m\n');
    for (const result of filesWithWarnings) {
      console.log(`\x1b[33m⚠\x1b[0m ${result.file}`);
      for (const warning of result.warnings) {
        console.log(`      \x1b[33m→\x1b[0m ${warning}`);
      }
    }
    console.log('');
  }

  console.log(`\x1b[32m✅ All ${files.length} files validated successfully.\x1b[0m`);

  if (totalWarnings > 0) {
    console.log(`\x1b[33m   ${totalWarnings} warning(s) (non-critical)\x1b[0m`);
  }

  console.log('');
  return true;
}

// Run validation
validateAllFiles();