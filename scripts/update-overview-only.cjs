#!/usr/bin/env node
/**
 * Update Product Files - Convert to Overview-only format
 * Removes H1 title, converts paragraph to ## Overview, removes Features/Perfect For
 */

const fs = require('fs');
const path = require('path');

const PRODUCTS_DIR = path.join(__dirname, '..', 'src', 'content', 'products');

// Get all product files
const files = fs.readdirSync(PRODUCTS_DIR).filter(f => f.endsWith('.md'));

console.log(`Found ${files.length} product files to update\n`);

let updatedCount = 0;

files.forEach(filename => {
  const filepath = path.join(PRODUCTS_DIR, filename);
  let content = fs.readFileSync(filepath, 'utf-8');

  // Normalize line endings
  content = content.replace(/\r\n/g, '\n');

  // Parse frontmatter and body
  const frontmatterMatch = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);

  if (!frontmatterMatch) {
    console.log(`⚠️  Skipping ${filename} - invalid frontmatter`);
    return;
  }

  const frontmatter = frontmatterMatch[1];
  let body = frontmatterMatch[2].trim();

  // Remove H1 title line if exists (e.g., "# Personalice Gafas...")
  body = body.replace(/^# [^\n]+\n\n*/, '');

  // Extract the first paragraph (overview text)
  const lines = body.split('\n');
  let overviewText = '';

  for (const line of lines) {
    if (line.startsWith('## ')) break;
    if (line.trim()) {
      overviewText = line.trim();
      break;
    }
  }

  if (!overviewText) {
    console.log(`⚠️  Skipping ${filename} - no overview text found`);
    return;
  }

  // Create new body with only Overview section
  const newBody = `## Overview\n\n${overviewText}`;

  // Reconstruct file
  const newContent = `---\n${frontmatter}\n---\n\n${newBody}\n`;

  fs.writeFileSync(filepath, newContent, 'utf-8');
  console.log(`✅ Updated ${filename}`);
  updatedCount++;
});

console.log(`\n========================================`);
console.log(`Update Complete!`);
console.log(`========================================`);
console.log(`Updated: ${updatedCount} files`);
console.log(`Total:   ${files.length} files`);
