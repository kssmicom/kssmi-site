import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';

export const RUNTIME_MANIFEST_SCHEMA_VERSION = 1;
export const RUNTIME_ASSET_DEFINITIONS = Object.freeze([
  Object.freeze({
    logicalName: 'cookie-banner',
    source: 'public/cookie-banner.js',
    publicName: 'cookie-banner',
    rewriteTarget: 'cookie-banner.js',
  }),
  Object.freeze({
    logicalName: 'vjt-tracker',
    source: 'public/js/vjt-tracker.js',
    publicName: 'vjt-tracker',
    rewriteTarget: 'js/vjt-tracker.js',
  }),
]);

const MANIFEST_KEYS = ['assets', 'schema_version'];
const ASSET_KEYS = ['bytes', 'file_name', 'integrity', 'sha256', 'url'];

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function hasExactKeys(value, expectedKeys) {
  return (
    value
    && typeof value === 'object'
    && !Array.isArray(value)
    && Object.keys(value).sort().join('\n') === [...expectedKeys].sort().join('\n')
  );
}

export function canonicalRuntimeBytes(bytes) {
  return Buffer.from(bytes.toString('utf8').replaceAll('\r\n', '\n'));
}

export async function createRuntimeAssetManifest(
  root,
  definitions = RUNTIME_ASSET_DEFINITIONS
) {
  const assets = {};
  for (const definition of definitions) {
    assert(
      /^[a-z][a-z0-9-]*$/.test(definition.logicalName),
      'invalid runtime asset logical name: ' + definition.logicalName
    );
    assert(!assets[definition.logicalName], 'duplicate runtime asset: ' + definition.logicalName);
    const bytes = canonicalRuntimeBytes(await readFile(resolve(root, definition.source)));
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    const fileName = `${definition.publicName}.${sha256.slice(0, 12)}.js`;
    assets[definition.logicalName] = {
      url: `/assets/runtime/${fileName}`,
      file_name: fileName,
      sha256,
      integrity: `sha256-${createHash('sha256').update(bytes).digest('base64')}`,
      bytes: bytes.length,
    };
  }
  return validateRuntimeAssetManifest({
    schema_version: RUNTIME_MANIFEST_SCHEMA_VERSION,
    assets,
  });
}

export function validateRuntimeAssetManifest(value) {
  assert(hasExactKeys(value, MANIFEST_KEYS), 'runtime manifest has missing or unexpected fields');
  assert(
    value.schema_version === RUNTIME_MANIFEST_SCHEMA_VERSION,
    `runtime manifest schema_version must be ${RUNTIME_MANIFEST_SCHEMA_VERSION}`
  );
  assert(
    value.assets && typeof value.assets === 'object' && !Array.isArray(value.assets),
    'runtime manifest assets must be an object'
  );

  const expectedNames = RUNTIME_ASSET_DEFINITIONS.map((asset) => asset.logicalName).sort();
  assert(
    Object.keys(value.assets).sort().join('\n') === expectedNames.join('\n'),
    'runtime manifest assets do not match the registered logical names'
  );

  const seenUrls = new Set();
  for (const [logicalName, asset] of Object.entries(value.assets)) {
    assert(hasExactKeys(asset, ASSET_KEYS), `${logicalName} has missing or unexpected fields`);
    assert(/^[a-f0-9]{64}$/.test(asset.sha256), `${logicalName} has an invalid SHA-256 digest`);
    assert(
      asset.file_name === `${logicalName}.${asset.sha256.slice(0, 12)}.js`,
      `${logicalName} file name does not match its digest`
    );
    assert(
      asset.url === `/assets/runtime/${asset.file_name}`,
      `${logicalName} URL does not match its file name`
    );
    assert(
      /^sha256-[A-Za-z0-9+/]{43}=$/.test(asset.integrity),
      `${logicalName} has an invalid SRI value`
    );
    assert(Number.isInteger(asset.bytes) && asset.bytes > 0, `${logicalName} has an invalid byte count`);
    assert(!seenUrls.has(asset.url), `duplicate runtime asset URL: ${asset.url}`);
    seenUrls.add(asset.url);
  }
  return value;
}

export async function readRuntimeAssetManifest(filePath) {
  let value;
  try {
    value = JSON.parse(await readFile(filePath, 'utf8'));
  } catch (error) {
    throw new Error(`cannot read runtime asset manifest ${filePath}: ${error.message}`);
  }
  return validateRuntimeAssetManifest(value);
}

export function serializeRuntimeAssetManifest(manifest) {
  return JSON.stringify(validateRuntimeAssetManifest(manifest), null, 2) + '\n';
}
