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
const [vjtTrackerSource, inquiryFormSource, visitorTrackerSource] = await Promise.all([
  readFile(resolve(root, 'public/js/vjt-tracker.js'), 'utf8'),
  readFile(resolve(root, 'src/components/InquiryForm.astro'), 'utf8'),
  readFile(resolve(root, 'src/components/VisitorTracker.astro'), 'utf8'),
]);

function extractNamedFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `${name} function is missing`);
  const bodyStart = source.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `${name} function body is missing`);

  let depth = 0;
  let quote = '';
  let escaped = false;
  let lineComment = false;
  let blockComment = false;
  for (let index = bodyStart; index < source.length; index += 1) {
    const character = source[index];
    const next = source[index + 1];
    if (lineComment) {
      if (character === '\n') lineComment = false;
      continue;
    }
    if (blockComment) {
      if (character === '*' && next === '/') {
        blockComment = false;
        index += 1;
      }
      continue;
    }
    if (quote) {
      if (escaped) escaped = false;
      else if (character === '\\') escaped = true;
      else if (character === quote) quote = '';
      continue;
    }
    if (character === '/' && next === '/') {
      lineComment = true;
      index += 1;
      continue;
    }
    if (character === '/' && next === '*') {
      blockComment = true;
      index += 1;
      continue;
    }
    if (character === "'" || character === '"' || character === '`') {
      quote = character;
      continue;
    }
    if (character === '{') depth += 1;
    if (character === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, index + 1);
    }
  }
  assert.fail(`${name} function body is not balanced`);
}

// F-01 browser regressions: optional analytics must fail soft and must never
// relabel a queued conversion when a capability refill rotates the identity.
const buildPathSnapshot = Function(
  'readPath',
  `'use strict'; ${extractNamedFunction(vjtTrackerSource, 'buildPathSnapshot')}; return buildPathSnapshot;`
)(() => [null, { session_id: 'vjts_old', step_order: 1, visited_at: '2026-01-01T00:00:00Z' }]);
const snapshot = buildPathSnapshot({
  session_id: 'vjts_current',
  step_order: 2,
  visited_at: '2026-01-01T00:01:00Z',
});
assert.equal(snapshot.includes(null), false, 'corrupt null path entries must be discarded');
assert.equal(snapshot.length, 2, 'valid stored and current path entries must survive sanitization');

const currentIdentity = { visitorId: 'vjtv_current', sessionId: 'vjts_current' };
let submissionFetches = 0;
let submissionBody = null;
const deliverConversion = Function(
  'cfg',
  'identityReady',
  'serverIdentity',
  'acquireCapability',
  'payloadMatchesServerIdentity',
  'fetch',
  'payloadWithCapability',
  `'use strict'; ${extractNamedFunction(vjtTrackerSource, 'deliverConversion')}; return deliverConversion;`
)(
  { enabled: true, routes: { submission: '/api/track-submission.php' } },
  true,
  currentIdentity,
  () => Promise.resolve('fresh-capability'),
  (payload) => !!payload
    && payload.visitor_id === currentIdentity.visitorId
    && payload.session_id === currentIdentity.sessionId,
  async (_url, options) => {
    submissionFetches += 1;
    submissionBody = JSON.parse(options.body);
    return { ok: true, json: async () => ({ success: true, result: 'stored' }) };
  },
  (payload, token) => ({ ...payload, capability_token: token })
);
const stalePayload = {
  event_id: 'vjtev_stale',
  visitor_id: 'vjtv_old',
  session_id: 'vjts_old',
  status: 'attempt',
};
assert.equal(
  await deliverConversion(stalePayload),
  'drop',
  'capability refill identity mismatch must drop the stale queued conversion'
);
assert.equal(submissionFetches, 0, 'stale conversion must not reach the submission endpoint');
assert.deepEqual(
  { visitor_id: stalePayload.visitor_id, session_id: stalePayload.session_id },
  { visitor_id: 'vjtv_old', session_id: 'vjts_old' },
  'delivery must not relabel a queued conversion to the current identity'
);

const validPayload = {
  event_id: 'vjtev_current',
  visitor_id: currentIdentity.visitorId,
  session_id: currentIdentity.sessionId,
  status: 'attempt',
};
assert.equal(await deliverConversion(validPayload), 'stored', 'matching identity remains deliverable');
assert.equal(submissionFetches, 1, 'matching conversion performs exactly one request');
assert.deepEqual(
  {
    visitor_id: submissionBody?.visitor_id,
    session_id: submissionBody?.session_id,
    capability_token: submissionBody?.capability_token,
  },
  {
    visitor_id: currentIdentity.visitorId,
    session_id: currentIdentity.sessionId,
    capability_token: 'fresh-capability',
  },
  'matching conversion keeps its captured identity and receives only the fresh capability'
);

const trackerCall = inquiryFormSource.indexOf('window.VJT_beginInquirySubmission(newForm)');
const formDataCreation = inquiryFormSource.indexOf('new FormData(newForm)', trackerCall);
assert.ok(trackerCall >= 0 && formDataCreation > trackerCall, 'InquiryForm tracker hook is missing');
const trackerBoundary = inquiryFormSource.slice(Math.max(0, trackerCall - 200), formDataCreation);
const hookCall = trackerBoundary.lastIndexOf('window.VJT_beginInquirySubmission(newForm)');
const localTry = trackerBoundary.lastIndexOf('try {', hookCall);
const localCatch = trackerBoundary.indexOf('catch', hookCall);
assert.ok(localTry >= 0 && localCatch > localTry, 'optional tracker hook must be guarded by local try/catch');
assert.match(
  trackerBoundary.slice(localCatch),
  /submissionEventId\s*=\s*['"]['"]\s*;/,
  'tracker failure must fall back to an empty submission event ID'
);

const hostnameMappings = [
  ...visitorTrackerSource.matchAll(/var\s+(?:vjtDevHostname|devHostname)\s*=\s*window\.location\.hostname[\s\S]*?;/g),
  ...inquiryFormSource.matchAll(/const\s+devHostname\s*=\s*window\.location\.hostname[\s\S]*?;/g),
].map((match) => match[0]);
assert.ok(hostnameMappings.length >= 4, 'development hostname mappings are missing');
for (const mapping of hostnameMappings) {
  const bracketedLoopbackLiterals = mapping.match(/['"]\[::1\]['"]/g) || [];
  assert.ok(
    /['"]::1['"]/.test(mapping) && bracketedLoopbackLiterals.length >= 2,
    'every development API hostname mapping must recognize bracketed and unbracketed IPv6 loopback'
  );
}
const localHostBoundary = visitorTrackerSource.match(/var\s+isLocalHost\s*=[\s\S]*?;/)?.[0] || '';
assert.ok(
  /hostname\s*===\s*['"]::1['"]/.test(localHostBoundary)
    && /hostname\s*===\s*['"]\[::1\]['"]/.test(localHostBoundary),
  'local tracker suppression must recognize bracketed and unbracketed IPv6 loopback'
);

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
