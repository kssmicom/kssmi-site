import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { projectRoot, ensureTemporaryRoot, createTask } from '../scripts/local-tools.mjs';
import { validateFrontmatter, validateAllFiles, parseCliArgs } from '../scripts/validate-content.mjs';

ensureTemporaryRoot();
const taskRoot = createTask('content-validation-tests', projectRoot);
const validatorScript = path.join(projectRoot, 'scripts/validate-content.mjs');
const isPosixNonRoot = process.platform !== 'win32' && typeof process.getuid === 'function' && process.getuid() !== 0;

let sequence = 0;

function writeFixtureFile(root, relPath, content) {
  const fullPath = path.join(root, relPath);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, content, 'utf8');
  return fullPath;
}

function createValidContentRoot() {
  const root = path.join(taskRoot, `case-${++sequence}`);
  for (const dir of ['products', 'collection', 'blog', 'feature', 'category-product']) {
    fs.mkdirSync(path.join(root, dir), { recursive: true });
  }
  writeFixtureFile(
    root,
    'products/kso-001/kso-001.en.md',
    '---\ntitle: "KSO-001 Optical Frame"\ngallery:\n  - "/media/products/kso-001/kso-001-2.webp"\n---\nBody\n'
  );
  writeFixtureFile(
    root,
    'collection/about/about.en.md',
    '---\ntitle: "About Kssmi"\nsections:\n  - title: "Section One"\n    description: "Text"\n---\nAbout body\n'
  );
  writeFixtureFile(
    root,
    'category-product/optical-frames.md',
    '---\nslug: "optical-frames"\nbaseSegment: "product"\ndisplayName:\n  en: "Optical Frames"\n---\n'
  );
  return root;
}

function runCli(args) {
  return spawnSync(process.execPath, [validatorScript, ...args], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 30000
  });
}

test('validates all 5 collections, allows empty blog/feature, and keeps category-product non-recursive', () => {
  const root = createValidContentRoot();
  // Nested markdown inside category-product is outside Astro's pattern: '*.md'
  writeFixtureFile(root, 'category-product/nested/ignored.md', 'no frontmatter here');

  const res = runCli(['--content-root', root]);
  assert.equal(res.status, 0, res.stderr + res.stdout);
  assert.match(res.stdout, /0 file\(s\) found \(empty collection allowed\)/);
  assert.match(res.stdout, /All 3 files validated successfully\./);

  // Top-level category-product file IS scanned and fails on invalid frontmatter
  writeFixtureFile(root, 'category-product/broken.md', '---\ntitle: "unclosed\n---\n');
  const brokenCat = runCli(['--content-root', root]);
  assert.equal(brokenCat.status, 1);
  assert.match(brokenCat.stderr, /YAML syntax error/);
  fs.unlinkSync(path.join(root, 'category-product/broken.md'));

  // Non-empty blog and feature files ARE actively validated
  writeFixtureFile(root, 'feature/sample.en.md', 'missing frontmatter');
  const brokenFeature = runCli(['--content-root', root]);
  assert.equal(brokenFeature.status, 1);
  assert.match(brokenFeature.stderr, /No valid frontmatter found/);
  fs.unlinkSync(path.join(root, 'feature/sample.en.md'));

  writeFixtureFile(root, 'blog/post.en.md', '---\ntitle: "Post"\ntitle: "Duplicate"\n---\n');
  const brokenBlog = runCli(['--content-root', root]);
  assert.equal(brokenBlog.status, 1);
  assert.match(brokenBlog.stderr, /Duplicate key "title"/);
});

test('CRLF and gallery duplicating cover remain non-blocking warnings', () => {
  const root = createValidContentRoot();
  writeFixtureFile(
    root,
    'products/kso-002/kso-002.en.md',
    '---\r\ntitle: "KSO-002"\r\ncover: "/media/products/kso-002/main.webp"\r\ngallery:\r\n  - "/media/products/kso-002/main.webp"\r\n---\r\nBody\r\n'
  );

  const res = runCli(['--content-root', root]);
  assert.equal(res.status, 0, res.stderr + res.stdout);
  assert.match(res.stdout, /File uses CRLF line endings/);
  assert.match(res.stdout, /same as cover image/);
  assert.match(res.stdout, /2 warning\(s\) \(non-critical\)/);
});

test('YAML syntax errors fail with exit code 1', () => {
  const root = createValidContentRoot();
  writeFixtureFile(
    root,
    'products/kso-001/bad-yaml.en.md',
    '---\ntitle: "Unclosed quote\nmoq: 100\n---\n'
  );

  const res = runCli(['--content-root', root]);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /Content Validation Failed/);
  assert.match(res.stderr, /YAML syntax error/);
});

test('UTF-8 BOM fails with exit code 1 and still reports accompanying syntax errors', () => {
  const root = createValidContentRoot();
  writeFixtureFile(
    root,
    'products/kso-001/bom-only.en.md',
    '\uFEFF---\ntitle: "Valid except BOM"\n---\n'
  );
  const bomOnly = runCli(['--content-root', root]);
  assert.equal(bomOnly.status, 1);
  assert.match(bomOnly.stderr, /UTF-8 BOM/);

  writeFixtureFile(
    root,
    'products/kso-001/bom-only.en.md',
    '\uFEFF---\ntitle: "Broken\n---\n'
  );
  const bomAndYaml = runCli(['--content-root', root]);
  assert.equal(bomAndYaml.status, 1);
  assert.match(bomAndYaml.stderr, /UTF-8 BOM/);
  assert.match(bomAndYaml.stderr, /YAML syntax error/);
});

test('structural frontmatter and schema guard violations fail with exit code 1', () => {
  const badCases = [
    ['missing-delimiters.md', 'title: "No frontmatter"\n', /No valid frontmatter found/],
    ['duplicate-root.md', '---\ntitle: "First"\ntitle: "Second"\n---\n', /Duplicate key "title"/],
    ['duplicate-nested.md', '---\nseo:\n  title: "One"\n  title: "Two"\n---\n', /YAML syntax error/],
    ['orphan-array.md', '---\ntitle: "Ok"\n\n- stray item\n---\n', /not under a valid key/],
    ['null-gallery.md', '---\ntitle: "Ok"\ngallery: null\n---\n', /gallery is null/],
    ['comments-only.md', '---\n# just a comment\n---\n', /must parse to a YAML mapping object/],
    ['array-root.md', '---\n- alpha\n- beta\n---\n', /must parse to a YAML mapping object/],
  ];

  for (const [name, content, pattern] of badCases) {
    const root = createValidContentRoot();
    writeFixtureFile(root, `products/kso-001/${name}`, content);
    const res = runCli(['--content-root', root]);
    assert.equal(res.status, 1, `expected failure for ${name}`);
    assert.match(res.stderr, pattern, `expected ${pattern} for ${name}`);
  }
});

test('unreadable markdown file fails with exit code 1 instead of being marked valid', t => {
  if (!isPosixNonRoot) {
    t.skip('chmod 000 permission enforcement requires a non-root POSIX host');
    return;
  }
  const root = createValidContentRoot();
  const lockedFile = writeFixtureFile(root, 'products/kso-001/locked.en.md', '---\ntitle: "Locked"\n---\n');
  fs.chmodSync(lockedFile, 0o000);
  try {
    const res = runCli(['--content-root', root]);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /Failed to read file/);
  } finally {
    fs.chmodSync(lockedFile, 0o644);
  }
});

test('missing required directories and non-directory paths fail with exit code 1', () => {
  for (const missingDir of ['products', 'blog', 'feature', 'category-product']) {
    const root = createValidContentRoot();
    fs.rmSync(path.join(root, missingDir), { recursive: true, force: true });
    const res = runCli(['--content-root', root]);
    assert.equal(res.status, 1, `expected failure when ${missingDir} is missing`);
    assert.match(res.stderr, /Required content directory not found/);
  }

  const fileInsteadOfDirRoot = createValidContentRoot();
  fs.rmSync(path.join(fileInsteadOfDirRoot, 'feature'), { recursive: true, force: true });
  writeFixtureFile(fileInsteadOfDirRoot, 'feature', 'not a directory');
  const filePathRes = runCli(['--content-root', fileInsteadOfDirRoot]);
  assert.equal(filePathRes.status, 1);
  assert.match(filePathRes.stderr, /Content path is not a directory/);
});

test('unreadable collection directory and unreadable nested subdirectory fail with exit code 1', t => {
  if (!isPosixNonRoot) {
    t.skip('chmod 000 permission enforcement requires a non-root POSIX host');
    return;
  }

  const rootCol = createValidContentRoot();
  const lockedCol = path.join(rootCol, 'collection');
  fs.chmodSync(lockedCol, 0o000);
  try {
    const res = runCli(['--content-root', rootCol]);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /Failed to (read|access content) directory/);
  } finally {
    fs.chmodSync(lockedCol, 0o755);
  }

  const rootSub = createValidContentRoot();
  const lockedSub = path.join(rootSub, 'products/locked-sku');
  fs.mkdirSync(lockedSub, { recursive: true });
  writeFixtureFile(rootSub, 'products/locked-sku/item.en.md', '---\ntitle: "Hidden"\n---\n');
  fs.chmodSync(lockedSub, 0o000);
  try {
    const res = runCli(['--content-root', rootSub]);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /Failed to read directory/);
  } finally {
    fs.chmodSync(lockedSub, 0o755);
  }
});

test('empty non-allowEmpty collection and invalid CLI flags fail with exit code 1', () => {
  const emptyProductsRoot = createValidContentRoot();
  fs.rmSync(path.join(emptyProductsRoot, 'products'), { recursive: true, force: true });
  fs.mkdirSync(path.join(emptyProductsRoot, 'products'), { recursive: true });
  const emptyRes = runCli(['--content-root', emptyProductsRoot]);
  assert.equal(emptyRes.status, 1);
  assert.match(emptyRes.stderr, /contains no markdown files/);

  const missingVal = runCli(['--content-root']);
  assert.equal(missingVal.status, 1);
  assert.match(missingVal.stderr, /Missing value for --content-root/);

  const unknownFlag = runCli(['--unknown-flag']);
  assert.equal(unknownFlag.status, 1);
  assert.match(unknownFlag.stderr, /Unknown option: --unknown-flag/);

  assert.throws(() => parseCliArgs(['--content-root', '--other']), /Missing value/);
  const fm = validateFrontmatter('---\ntitle: "Unit"\n---\n', path.join(taskRoot, 'unit.md'));
  assert.equal(fm.valid, true);
  const summary = validateAllFiles({ contentRoot: emptyProductsRoot, quiet: true });
  assert.equal(summary.ok, false);
  assert.equal(summary.directoryErrors.length, 1);
});
