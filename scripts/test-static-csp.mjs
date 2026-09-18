import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';

const run = promisify(execFile);
const projectRoot = path.resolve(import.meta.dirname, '..');
const dir = await mkdtemp(path.join(tmpdir(), 'kssmi-static-csp-'));

try {
  await writeFile(path.join(dir, '.htaccess'), await readFile(path.join(projectRoot, 'public', '.htaccess')));
  await writeFile(path.join(dir, 'index.html'), '<!doctype html><html><head><title>KSSMI</title></head><body><script>window.example = 1;</script></body></html>');
  await writeFile(path.join(dir, 'nested.html'), '<!doctype html><html><head></head><body style="color:red"></body></html>');
  await writeFile(path.join(dir, 'redirect.html'), '<!doctype html><title>Redirecting</title><meta http-equiv="refresh" content="0;url=/">');

  for (let pass = 0; pass < 2; pass += 1) {
    await run(process.execPath, ['scripts/generate-csp-hashes.mjs', dir], { cwd: projectRoot });
  }
  for (const name of ['index.html', 'nested.html']) {
    const html = await readFile(path.join(dir, name), 'utf8');
    const matches = html.match(/<meta data-kssmi-static-csp="1"[^>]*>/g) || [];
    assert.equal(matches.length, 1, `${name} must contain exactly one generated CSP meta policy`);
    assert.match(matches[0], /http-equiv="Content-Security-Policy"/);
    assert.match(matches[0], /default-src 'self'/);
    assert.doesNotMatch(matches[0], /unsafe-inline/);
    const policyValue = /content="([^"]*)"/.exec(matches[0])?.[1] ?? '';
    for (const token of policyValue.split(/\s+/)) {
      if (token.includes('sha256-')) {
        assert.ok(
          token.startsWith("'sha256-") && token.endsWith("'"),
          `CSP hash source must be single-quoted or browsers ignore it: ${token}`,
        );
      }
    }
  }
  const redirect = await readFile(path.join(dir, 'redirect.html'), 'utf8');
  assert.doesNotMatch(redirect, /data-kssmi-static-csp="1"/, 'Headless redirect must remain valid minimal HTML.');
  const htaccess = await readFile(path.join(dir, '.htaccess'), 'utf8');
  assert.doesNotMatch(htaccess, /Static HTML CSP BEGIN[\s\S]*unsafe-inline[\s\S]*Static HTML CSP END/);
  console.log('Static CSP generation tests passed.');
} finally {
  await rm(dir, { recursive: true, force: true });
}
