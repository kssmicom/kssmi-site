import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputRoot = resolve(root, process.argv[2] ?? 'dist');
const runtimeDir = resolve(outputRoot, 'assets/runtime');

const assets = [
  { source: 'public/cookie-banner.js', publicName: 'cookie-banner' },
  { source: 'public/js/vjt-tracker.js', publicName: 'vjt-tracker' },
];

await mkdir(runtimeDir, { recursive: true });

for (const asset of assets) {
  const bytes = await readFile(resolve(root, asset.source));
  // Match validate-cache-policy.mjs on Windows and Linux: URLs and emitted
  // files are both derived from the canonical LF representation.
  const canonicalBytes = Buffer.from(bytes.toString('utf8').replaceAll('\r\n', '\n'));
  const hash = createHash('sha256').update(canonicalBytes).digest('hex').slice(0, 12);
  const fileName = `${asset.publicName}.${hash}.js`;

  await writeFile(resolve(runtimeDir, fileName), canonicalBytes);
  console.log(`Materialized /assets/runtime/${fileName}`);
}
