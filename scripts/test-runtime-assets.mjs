import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import {
  copyFile,
  mkdir,
  mkdtemp,
  readFile,
  rm,
  writeFile,
} from 'node:fs/promises';
import os from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  canonicalRuntimeBytes,
  createRuntimeAssetManifest,
  readRuntimeAssetManifest,
  RUNTIME_ASSET_DEFINITIONS,
  serializeRuntimeAssetManifest,
  validateRuntimeAssetManifest,
} from './lib/runtime-assets.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const manifest = await createRuntimeAssetManifest(root);
assert.deepEqual(
  Object.keys(manifest.assets).sort(),
  ['cookie-banner', 'vjt-tracker'],
  'runtime manifest logical assets changed unexpectedly'
);

for (const definition of RUNTIME_ASSET_DEFINITIONS) {
  const bytes = canonicalRuntimeBytes(await readFile(resolve(root, definition.source)));
  const asset = manifest.assets[definition.logicalName];
  assert.equal(asset.bytes, bytes.length);
  assert.equal(asset.sha256, createHash('sha256').update(bytes).digest('hex'));
  assert.match(asset.integrity, /^sha256-[A-Za-z0-9+/]{43}=$/);
  assert.equal(asset.url, `/assets/runtime/${asset.file_name}`);
}
assert.deepEqual(
  validateRuntimeAssetManifest(JSON.parse(serializeRuntimeAssetManifest(manifest))),
  manifest,
  'serialized manifest did not round-trip'
);
assert.throws(
  () => validateRuntimeAssetManifest({ ...manifest, unexpected: true }),
  /unexpected fields/,
  'manifest with unknown fields was accepted'
);
assert.throws(
  () => validateRuntimeAssetManifest({
    ...manifest,
    assets: {
      ...manifest.assets,
      'cookie-banner': { ...manifest.assets['cookie-banner'], url: 'https://example.com/x.js' },
    },
  }),
  /URL does not match/,
  'off-origin runtime URL was accepted'
);

const temporaryRoot = await mkdtemp(join(os.tmpdir(), 'kssmi-runtime-assets-'));
try {
  await mkdir(resolve(temporaryRoot, 'public/js'), { recursive: true });
  for (const definition of RUNTIME_ASSET_DEFINITIONS) {
    const destination = resolve(temporaryRoot, definition.source);
    await mkdir(dirname(destination), { recursive: true });
    await copyFile(resolve(root, definition.source), destination);
  }
  const before = await createRuntimeAssetManifest(temporaryRoot);
  await writeFile(
    resolve(temporaryRoot, 'public/cookie-banner.js'),
    Buffer.concat([
      await readFile(resolve(temporaryRoot, 'public/cookie-banner.js')),
      Buffer.from('\n// one-byte-change-test\n'),
    ])
  );
  const after = await createRuntimeAssetManifest(temporaryRoot);
  assert.notEqual(
    before.assets['cookie-banner'].url,
    after.assets['cookie-banner'].url,
    'changed source byte did not change its runtime URL'
  );
  assert.equal(
    before.assets['vjt-tracker'].url,
    after.assets['vjt-tracker'].url,
    'unchanged runtime asset URL changed unexpectedly'
  );

  const output = resolve(temporaryRoot, 'output');
  await mkdir(output, { recursive: true });
  await copyFile(resolve(root, 'public/.htaccess'), resolve(output, '.htaccess'));
  execFileSync(process.execPath, [resolve(root, 'scripts/materialize-runtime-assets.mjs'), output], {
    cwd: root,
    stdio: 'pipe',
  });
  const emitted = await readRuntimeAssetManifest(resolve(output, 'assets/runtime/manifest.json'));
  assert.deepEqual(emitted, manifest, 'emitted manifest differs from the shared generator output');
  execFileSync(
    process.platform === 'win32' ? 'python' : 'python3',
    [
      resolve(root, 'scripts/ols-add-headers.py'),
      '--check-manifest',
      resolve(output, 'assets/runtime/manifest.json'),
    ],
    { cwd: root, stdio: 'pipe' }
  );
  const generatedHtaccess = await readFile(resolve(output, '.htaccess'), 'utf8');
  for (const asset of Object.values(emitted.assets)) {
    const content = await readFile(resolve(output, 'assets/runtime', asset.file_name));
    assert.equal(createHash('sha256').update(content).digest('hex'), asset.sha256);
    assert.ok(
      generatedHtaccess.includes(asset.url.slice(1).replaceAll('.', '\\.')),
      `generated .htaccess does not contain ${asset.url}`
    );
  }
} finally {
  await rm(temporaryRoot, { recursive: true, force: true });
}

console.log('Runtime asset manifest, materialization and fingerprint-change tests passed.');
