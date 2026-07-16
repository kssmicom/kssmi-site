import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { transform } from '@astrojs/compiler';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoot = path.join(root, 'src');
const astroFiles = [];

function collect(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const fullPath = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(fullPath);
    else if (entry.isFile() && entry.name.endsWith('.astro')) astroFiles.push(fullPath);
  }
}

collect(sourceRoot);

const failures = [];
for (const file of astroFiles) {
  try {
    await transform(readFileSync(file, 'utf8'), { filename: file });
  } catch (error) {
    failures.push(`${path.relative(root, file)}: ${error instanceof Error ? error.message : String(error)}`);
  }
}

if (failures.length) {
  console.error('Astro syntax validation failed:');
  failures.forEach((failure) => console.error(`- ${failure}`));
  process.exit(1);
}

console.log(`Astro syntax validation passed for ${astroFiles.length} source files.`);
