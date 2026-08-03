import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, extname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

async function filesBelow(directory, extensions) {
  const entries = await readdir(resolve(root, directory), { withFileTypes: true, recursive: true });
  return entries
    .filter((entry) => entry.isFile() && extensions.has(extname(entry.name)))
    .map((entry) => resolve(entry.parentPath, entry.name));
}

const workflowFiles = await filesBelow('.github/workflows', new Set(['.yml', '.yaml']));
const actionFiles = await filesBelow('.github/actions', new Set(['.yml', '.yaml']));
const scriptFiles = (await filesBelow('scripts', new Set(['.js', '.mjs', '.sh'])))
  .filter((file) => file !== fileURLToPath(import.meta.url));
const inspectedFiles = [...workflowFiles, ...actionFiles, ...scriptFiles];
const sources = new Map(await Promise.all(
  inspectedFiles.map(async (file) => [file, await readFile(file, 'utf8')])
));

const insecureCurlOption = /(?:^|\s)(?:--insecure|-[A-Za-z]*k[A-Za-z]*)(?=\s|$)/m;
for (const [file, source] of sources) {
  assert.doesNotMatch(
    source,
    insecureCurlOption,
    `${relative(root, file)} must not disable curl certificate verification.`
  );
}

const productionSources = [...sources]
  .filter(([file]) => !/^test-/i.test(relative(resolve(root, 'scripts'), file)));
for (const [file, source] of productionSources) {
  assert.doesNotMatch(
    source,
    /NODE_TLS_REJECT_UNAUTHORIZED\s*(?::|=)\s*['"]?0\b/,
    `${relative(root, file)} must not disable Node.js TLS verification.`
  );
  assert.doesNotMatch(
    source,
    /rejectUnauthorized\s*:\s*false|strictSSL\s*:\s*false/,
    `${relative(root, file)} must not disable programmatic TLS verification.`
  );
}

const deployPath = resolve(root, '.github/workflows/deploy.yml');
const deploy = sources.get(deployPath);
assert.ok(deploy, 'TLS policy validator could not read deploy.yml.');
const environmentActionPath = resolve(root, '.github/actions/deploy-environment/action.yml');
const environmentAction = sources.get(environmentActionPath);
assert.ok(environmentAction, 'TLS policy validator could not read the shared environment action.');
const logicalDeploy = `${deploy}\n${environmentAction}`.replace(/\\\r?\n\s*/g, ' ');
const curlCommands = logicalDeploy
  .split(/\r?\n/)
  .filter((line) => /\bcurl\s+-/.test(line));
assert.ok(curlCommands.length >= 5, 'Deployment workflow and shared action must expose every online curl call to TLS validation.');
for (const command of curlCommands) {
  for (const option of [
    '--fail-with-body',
    '--show-error',
    '--silent',
    '--connect-timeout',
    '--max-time',
  ]) {
    assert.ok(command.includes(option), `Deploy curl command is missing ${option}: ${command.trim()}`);
  }
}

const smoke = await readFile(resolve(root, 'scripts/smoke-deployment.mjs'), 'utf8');
const smokeHttp = await readFile(resolve(root, 'scripts/lib/smoke-http.mjs'), 'utf8');
const smokeTest = await readFile(resolve(root, 'scripts/test-smoke-deployment.mjs'), 'utf8');
assert.match(
  smoke,
  /NODE_TLS_REJECT_UNAUTHORIZED\s*===\s*['"]0['"]/,
  'Production smoke must refuse the disabled-TLS environment setting.'
);
assert.match(smoke, /createSmokeRequester\s*\(/, 'Production smoke must use the strict HTTP transport.');
assert.match(smokeHttp, /rejectUnauthorized:\s*true/, 'Smoke transport must explicitly verify certificates.');
assert.match(smokeHttp, /servername:\s*url\.hostname/, 'Smoke transport must verify the requested hostname.');
assert.match(smokeHttp, /Smoke request timed out/, 'Smoke transport must enforce a request timeout.');
assert.match(
  smokeHttp,
  /redirect escaped the allowed origins/,
  'Smoke transport must reject redirect destinations outside its allowlist.'
);

for (const scenario of [
  'invalid certificate must fail the smoke',
  'hostname mismatch must fail the smoke',
  'request timeout must fail the smoke',
  'redirect outside the allowed origins must fail the smoke',
  'HTTP redirect downgrade must fail the smoke',
]) {
  assert.ok(smokeTest.includes(scenario), `Smoke TLS self-test is missing: ${scenario}.`);
}

console.log('Strict TLS and online smoke transport policy validated.');
