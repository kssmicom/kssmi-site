import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { promisify } from 'node:util';
import { decodeHtmlAttributeValue } from './generate-csp-hashes.mjs';

const run = promisify(execFile);
const projectRoot = path.resolve(import.meta.dirname, '..');
const dir = await mkdtemp(path.join(tmpdir(), 'kssmi-static-csp-'));
const sha256 = (value) => `sha256-${createHash('sha256').update(value, 'utf8').digest('base64')}`;

// Direct unit checks for single-pass HTML attribute decoding
assert.equal(decodeHtmlAttributeValue('()=>{}'), '()=>{}');
assert.equal(decodeHtmlAttributeValue('()=&gt;{a&amp;&amp;b&lt;c}'), '()=>{a&&b<c}');
assert.equal(decodeHtmlAttributeValue('&#39;x&#39;+&#x31;+&amp;gt;'), "'x'+1+&gt;");
assert.equal(decodeHtmlAttributeValue('&#0;&#xD800;&#x110000;&unknown;'), '\uFFFD\uFFFD\uFFFD&unknown;');

try {
  const rawScriptWithEntities = "window.example = '&gt;&amp;';";
  const rawStyleWithText = '/* a&amp;b */ body { color: blue; }';
  const encodedOnError = "if(!this.dataset.retried){setTimeout(()=&gt;{this.src=this.src+'?a=1&amp;b=2'},50);}";
  const decodedOnError = "if(!this.dataset.retried){setTimeout(()=>{this.src=this.src+'?a=1&b=2'},50);}";
  const encodedOnClick = 'window.__x=&#39;ok&#39;&amp;&amp;(&#x31;&lt;2)';
  const decodedOnClick = "window.__x='ok'&&(1<2)";
  const doubleEncodedOnLoad = 'window.__s=&quot;&amp;gt;&quot;';
  const decodedDoubleEncodedOnLoad = 'window.__s="&gt;"';

  await writeFile(path.join(dir, '.htaccess'), await readFile(path.join(projectRoot, 'public', '.htaccess')));
  await writeFile(
    path.join(dir, 'index.html'),
    `<!doctype html><html><head><title>KSSMI</title><style>${rawStyleWithText}</style></head>`
    + `<body><script>${rawScriptWithEntities}</script>`
    + `<img src="/a.webp" onerror="${encodedOnError}">`
    + `<button type="button" onclick="${encodedOnClick}">Go</button>`
    + `<img src="/b.webp" onload='${doubleEncodedOnLoad}'>`
    + `</body></html>`,
  );
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
    assert.doesNotMatch(matches[0], /frame-ancestors/, 'Browsers ignore frame-ancestors in a meta policy and log an error.');
    const rawPolicy = (/content="([^"]*)"/.exec(matches[0])?.[1] ?? '').replaceAll('&quot;', '"').replaceAll('&amp;', '&');
    for (const token of rawPolicy.split(/\s+/)) {
      if (token.includes('sha256-')) {
        assert.ok(
          token.startsWith("'sha256-") && token.endsWith("'"),
          `CSP hash source must be single-quoted or browsers ignore it: ${token}`,
        );
      }
    }

    // Event attributes MUST hash decoded values, NEVER raw encoded values
    for (const [label, encodedVal, decodedVal] of [
      ['onerror', encodedOnError, decodedOnError],
      ['onclick', encodedOnClick, decodedOnClick],
      ['onload', doubleEncodedOnLoad, decodedDoubleEncodedOnLoad],
    ]) {
      assert.ok(rawPolicy.includes(`'${sha256(decodedVal)}'`), `${label} must emit decoded CSP hash`);
      assert.ok(!rawPolicy.includes(`'${sha256(encodedVal)}'`), `${label} must not emit raw entity-encoded CSP hash`);
    }

    // Raw <script> and <style> elements MUST NOT undergo HTML attribute decoding
    assert.ok(rawPolicy.includes(`'${sha256(rawScriptWithEntities)}'`), 'inline <script> must emit raw text hash');
    assert.ok(!rawPolicy.includes(`'${sha256("window.example = '>&';")}'`), 'inline <script> must not decode &gt;/&amp;');
    assert.ok(rawPolicy.includes(`'${sha256(rawStyleWithText)}'`), 'inline <style> must emit raw text hash');
    assert.ok(!rawPolicy.includes(`'${sha256('/* a&b */ body { color: blue; }')}'`), 'inline <style> must not decode &amp;');
  }
  const redirect = await readFile(path.join(dir, 'redirect.html'), 'utf8');
  assert.doesNotMatch(redirect, /data-kssmi-static-csp="1"/, 'Headless redirect must remain valid minimal HTML.');
  const htaccess = await readFile(path.join(dir, '.htaccess'), 'utf8');
  assert.doesNotMatch(htaccess, /Static HTML CSP BEGIN[\s\S]*unsafe-inline[\s\S]*Static HTML CSP END/);
  assert.match(htaccess, /Static HTML CSP BEGIN[\s\S]*frame-ancestors 'self'[\s\S]*Static HTML CSP END/, 'The header policy keeps frame-ancestors.');
  console.log('Static CSP generation tests passed.');
} finally {
  await rm(dir, { recursive: true, force: true });
}
