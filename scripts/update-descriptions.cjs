#!/usr/bin/env node
/**
 * Update Product Descriptions Script
 * Applies 4 unique B2B product descriptions to all product files
 */

const fs = require('fs');
const path = require('path');

const PRODUCTS_DIR = path.join(__dirname, '..', 'src', 'content', 'products');

// 4 Unique B2B Product Descriptions (Professional, SEO-optimized)
const DESCRIPTIONS = {
  'yeto-lc001': `Premium luxury optical glasses engineered for discerning B2B partners. YETO-LC001 combines sophisticated metal craftsmanship with customizable OEM/ODM options, delivering exceptional quality for boutique eyewear brands and optical retailers. Features spring hinge technology, IP plating durability, and adjustable nose pads for superior comfort.`,

  'yeto-lc002': `Elevate your eyewear collection with YETO-LC002, a refined metal optical frame designed for B2B wholesale and private label partnerships. This premium frame showcases elegant minimalist aesthetics, robust spring hinge construction, and versatile color options. Perfect for fashion-forward optical chains seeking reliable manufacturing partners with 300+ MOQ flexibility.`,

  'yeto-lc003': `YETO-LC003 represents the pinnacle of metal optical frame engineering for B2B eyewear distributors. Crafted with precision manufacturing techniques, this frame offers customizable branding opportunities, premium IP electroplating finishes, and ergonomic spring hinge design. Ideal for established optical brands seeking consistent quality and scalable production capabilities.`,

  'yeto-lc004': `Discover YETO-LC004, a sophisticated metal optical solution tailored for B2B eyewear professionals. This premium frame features advanced construction with adjustable metal nose pads, durable spring hinges, and flawless IP plating in multiple finishes. Partner with us for OEM/ODM customization, competitive wholesale pricing, and reliable 300-piece MOQ fulfillment.`
};

// Get all product files
const files = fs.readdirSync(PRODUCTS_DIR).filter(f => f.endsWith('.md'));

console.log(`Found ${files.length} product files to update\n`);

let updatedCount = 0;
let skippedCount = 0;

files.forEach(filename => {
  const filepath = path.join(PRODUCTS_DIR, filename);
  let content = fs.readFileSync(filepath, 'utf-8');

  // Normalize line endings to handle Windows CRLF
  content = content.replace(/\r\n/g, '\n');

  // Determine which product this is
  const productKey = Object.keys(DESCRIPTIONS).find(key => filename.includes(key));

  if (!productKey) {
    console.log(`⚠️  Skipping ${filename} - no matching description`);
    skippedCount++;
    return;
  }

  const newDescription = DESCRIPTIONS[productKey];

  // Parse frontmatter and body - handle both \n and \r\n
  const frontmatterMatch = content.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);

  if (!frontmatterMatch) {
    console.log(`⚠️  Skipping ${filename} - invalid frontmatter`);
    skippedCount++;
    return;
  }

  const frontmatter = frontmatterMatch[1];
  let body = frontmatterMatch[2].trim();

  // Check if body already has the new description
  if (body.includes(newDescription.substring(0, 80))) {
    console.log(`⏭️  Skipping ${filename} - already updated`);
    skippedCount++;
    return;
  }

  // Create new body with the description
  const titleMatch = frontmatter.match(/title:\s*"([^"]+)"/);
  const title = titleMatch ? titleMatch[1] : productKey.toUpperCase();
  const itemNoMatch = frontmatter.match(/itemNo:\s*"([^"]+)"/);
  const itemNo = itemNoMatch ? itemNoMatch[1] : productKey.toUpperCase();

  const newBody = `# ${title} ${itemNo}

${newDescription}

## Features

- Premium metal construction
- Adjustable nose pads for personalized fit
- Spring hinge for durability and comfort
- IP plating for long-lasting color
- OEM/ODM customization available

## Perfect For

- Premium Eyewear Brands
- Independent Eyewear Designers
- Boutique Fashion Labels
- High-End Optical Chains
- Private Label Distributors
- Regional Eyewear Wholesalers
`;

  // Reconstruct file
  const newContent = `---\n${frontmatter}\n---\n\n${newBody}`;

  fs.writeFileSync(filepath, newContent, 'utf-8');
  console.log(`✅ Updated ${filename}`);
  updatedCount++;
});

console.log(`\n========================================`);
console.log(`Update Complete!`);
console.log(`========================================`);
console.log(`Updated: ${updatedCount} files`);
console.log(`Skipped: ${skippedCount} files`);
console.log(`Total:   ${files.length} files`);
