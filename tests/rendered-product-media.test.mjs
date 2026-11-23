import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { projectRoot, ensureTemporaryRoot, createTask } from '../scripts/local-tools.mjs';
import { extractProductGallery } from '../scripts/validate-product-rendered-media.mjs';

ensureTemporaryRoot();
const taskRoot = createTask('rendered-product-media-tests', projectRoot);
const validatorScript = path.join(projectRoot, 'scripts/validate-product-rendered-media.mjs');

let sequence = 0;

function createFixture() {
  const base = path.join(taskRoot, `case-${++sequence}`);
  const distDir = path.join(base, 'dist');
  const contentDir = path.join(base, 'content', 'products');
  fs.mkdirSync(path.join(distDir, 'media', 'products'), { recursive: true });
  fs.mkdirSync(contentDir, { recursive: true });
  fs.writeFileSync(path.join(distDir, 'index.html'), '<!doctype html><html><body><main id="main-content" tabindex="-1"></main></body></html>', 'utf8');
  return { base, distDir, contentDir };
}

function writeFile(filePath, content = 'stub-bytes') {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function writeProductMd(contentDir, slug, lang, { cover, gallery = [], customYaml = null } = {}) {
  const mdPath = path.join(contentDir, slug, `${slug}.${lang}.md`);
  if (customYaml !== null) {
    writeFile(mdPath, customYaml);
    return mdPath;
  }
  const lines = ['---', `title: "Product ${slug} (${lang})"`, `slug: "${slug}"`];
  if (cover !== undefined) lines.push(`cover: "${cover}"`);
  if (gallery.length > 0) {
    lines.push('gallery:');
    for (const item of gallery) lines.push(`  - "${item}"`);
  }
  lines.push('---', '', 'Product description.');
  writeFile(mdPath, lines.join('\n'));
  return mdPath;
}

function toThumbUrl(url) {
  return url.replace(/\.(webp|jpe?g|png)$/i, '-240w.webp');
}

function seedMediaAssets(distDir, urls, { includeThumbs = true } = {}) {
  for (const url of urls) {
    const cleanRel = url.replace(/^\//, '');
    writeFile(path.join(distDir, cleanRel), `img:${cleanRel}`);
    if (includeThumbs) {
      const thumbRel = toThumbUrl(url).replace(/^\//, '');
      writeFile(path.join(distDir, thumbRel), `thumb:${thumbRel}`);
    }
  }
}

function renderGalleryHtml({
  mainSrc,
  dialogSrc = mainSrc,
  thumbs = [],
  reorderedAttrs = false,
  empty = false,
} = {}) {
  if (empty) {
    return `<!doctype html><html><head><title>Empty SKU</title></head><body><main id="main-content" tabindex="-1"><div class="product-gallery" data-product-gallery><div class="box"><svg aria-hidden="true"></svg></div></div></main></body></html>`;
  }

  const mainTag = reorderedAttrs
    ? `<img alt='Main view' data-gallery-main-image class=gallery-main-image src='${mainSrc}' loading="eager">`
    : `<img src="${mainSrc}" alt="Main" class="gallery-main-image" width="800" height="800" data-gallery-main-image />`;

  const dialogTag = reorderedAttrs
    ? `<img data-gallery-dialog-image src=${dialogSrc} alt='Zoom'>`
    : `<img src="${dialogSrc}" alt="Zoom" data-gallery-dialog-image />`;

  const thumbHtml = thumbs
    .map(item => {
      const idx = item.index;
      const full = item.full;
      const imgSrc = item.imgSrc ?? toThumbUrl(full);
      const attrs = reorderedAttrs
        ? `type='button' data-full="${full}" data-gallery-index=${idx} class="gallery-thumb"`
        : `type="button" class="gallery-thumb" data-gallery-index="${idx}" data-full="${full}"`;
      const imgTag = reorderedAttrs
        ? `<img alt="" aria-hidden="true" width="64" src='${imgSrc}'>`
        : `<img src="${imgSrc}" alt="" aria-hidden="true" width="64" height="64" />`;
      return `<button ${attrs}>${imgTag}</button>`;
    })
    .join('\n');

  return `<!doctype html><html><head><title>SKU</title></head><body><main id="main-content" tabindex="-1">
<div class="product-gallery" data-product-gallery dir="ltr">
  <div class="stage"><button type="button" data-gallery-open>${mainTag}</button></div>
  <div class="thumbs" role="group">${thumbHtml}</div>
  <dialog data-gallery-dialog><figure>${dialogTag}</figure></dialog>
</div>
<script>const decoy = '<div data-product-gallery><img data-gallery-main-image src="/media/fake.webp"></div>';</script>
</main></body></html>`;
}

function writeProductHtml(distDir, slug, lang, html) {
  const pageRel = lang === 'en' ? path.join('product', slug, 'index.html') : path.join(lang, 'product', slug, 'index.html');
  writeFile(path.join(distDir, pageRel), html);
}

function runValidator(distDir, contentDir, extraArgs = []) {
  return spawnSync(
    process.execPath,
    [validatorScript, distDir, '--products-content', contentDir, ...extraArgs],
    {
      cwd: projectRoot,
      encoding: 'utf8',
      timeout: 30000,
    }
  );
}

test('extractProductGallery parses reordered/unquoted/boolean attributes and ignores script decoys', () => {
  const html = renderGalleryHtml({
    mainSrc: '/media/products/kso-1/a.webp',
    dialogSrc: '/media/products/kso-1/a.webp',
    thumbs: [
      { index: '0', full: '/media/products/kso-1/a.webp', imgSrc: '/media/products/kso-1/a-240w.webp' },
      { index: '1', full: '/media/products/kso-1/b.jpg', imgSrc: '/media/products/kso-1/b-240w.webp' },
    ],
    reorderedAttrs: true,
  });
  const parsed = extractProductGallery(html);
  assert.equal(parsed.galleryCount, 1);
  assert.deepEqual(parsed.mainImages, [{ src: '/media/products/kso-1/a.webp' }]);
  assert.deepEqual(parsed.dialogImages, [{ src: '/media/products/kso-1/a.webp' }]);
  assert.equal(parsed.thumbs.length, 2);
  assert.equal(parsed.thumbs[1].fullSrc, '/media/products/kso-1/b.jpg');
  assert.equal(parsed.thumbs[1].imgs[0].src, '/media/products/kso-1/b-240w.webp');
});

test('valid multilingual fixture passes with excluded catalog/category/pagination/redirect routes and report JSON', () => {
  const { base, distDir, contentDir } = createFixture();
  const imgsA = ['/media/products/sku-a/p-1.webp', '/media/products/sku-a/p-2.jpg'];
  const imgsB = ['/media/products/sku-b/only.png'];
  seedMediaAssets(distDir, [...imgsA, ...imgsB]);

  for (const lang of ['en', 'de', 'ar']) {
    writeProductMd(contentDir, 'sku-a', lang, { cover: imgsA[0], gallery: [imgsA[1]] });
    writeProductHtml(
      distDir,
      'sku-a',
      lang,
      renderGalleryHtml({
        mainSrc: imgsA[0],
        thumbs: imgsA.map((u, idx) => ({ index: String(idx), full: u })),
        reorderedAttrs: lang === 'de',
      })
    );

    writeProductMd(contentDir, 'sku-b', lang, { cover: imgsB[0], gallery: [] });
    writeProductHtml(
      distDir,
      'sku-b',
      lang,
      renderGalleryHtml({
        mainSrc: imgsB[0],
        thumbs: [{ index: '0', full: imgsB[0] }],
      })
    );

    const prefix = lang === 'en' ? '' : `${lang}/`;
    writeFile(path.join(distDir, `${prefix}product/index.html`), '<!doctype html><html><body>Catalog</body></html>');
    writeFile(path.join(distDir, `${prefix}product/sunglasses/index.html`), '<!doctype html><html><body>Category</body></html>');
    writeFile(path.join(distDir, `${prefix}product/2/index.html`), '<!doctype html><html><body>Page 2</body></html>');
  }
  writeFile(
    path.join(distDir, 'product/legacy-redirect/index.html'),
    '<!doctype html><title>Redirect</title><meta http-equiv="refresh" content="0;url=/product/sunglasses/"><meta name="robots" content="noindex">'
  );

  const reportOut = path.join(base, 'report.json');
  const res = runValidator(distDir, contentDir, ['--report-out', reportOut]);
  assert.equal(res.status, 0, res.stderr + res.stdout);

  const report = JSON.parse(fs.readFileSync(reportOut, 'utf8'));
  assert.equal(report.summary.pagesChecked, 6);
  assert.equal(report.summary.uniqueSlugs, 2);
  assert.equal(report.summary.languagesCount, 3);
  assert.equal(report.summary.issueCount, 0);
  assert.deepEqual(report.summary.excluded, {
    catalogIndexPages: 3,
    categoryListingPages: 3,
    paginationPages: 3,
    redirectStubs: 1,
  });
});

test('legal empty media product passes while stray media inside empty product fails', () => {
  const fxOk = createFixture();
  writeProductMd(fxOk.contentDir, 'sku-empty', 'en', { gallery: [] });
  writeProductHtml(fxOk.distDir, 'sku-empty', 'en', renderGalleryHtml({ empty: true }));
  const okRes = runValidator(fxOk.distDir, fxOk.contentDir);
  assert.equal(okRes.status, 0, okRes.stderr + okRes.stdout);
  assert.match(okRes.stdout, /1 empty-media page\(s\)/);

  const fxBad = createFixture();
  const sneaky = '/media/products/sku-empty/sneaky.webp';
  seedMediaAssets(fxBad.distDir, [sneaky]);
  writeProductMd(fxBad.contentDir, 'sku-empty', 'en', { gallery: [] });
  writeProductHtml(
    fxBad.distDir,
    'sku-empty',
    'en',
    renderGalleryHtml({
      mainSrc: sneaky,
      thumbs: [{ index: '0', full: sneaky }],
    })
  );
  const badRes = runValidator(fxBad.distDir, fxBad.contentDir);
  assert.equal(badRes.status, 1);
  assert.match(badRes.stderr, /UNEXPECTED_MEDIA_FOR_EMPTY_PRODUCT/);
});

test('missing image, extra template-scanned image, wrong main/dialog image, and wrong order fail gate', () => {
  const img1 = '/media/products/sku-x/1.webp';
  const img2 = '/media/products/sku-x/2.webp';
  const img3 = '/media/products/sku-x/3.webp';

  // 1. Missing image in HTML -> GALLERY_MISSING_IMAGES
  const fxMiss = createFixture();
  seedMediaAssets(fxMiss.distDir, [img1, img2]);
  writeProductMd(fxMiss.contentDir, 'sku-x', 'en', { cover: img1, gallery: [img2] });
  writeProductHtml(
    fxMiss.distDir,
    'sku-x',
    'en',
    renderGalleryHtml({ mainSrc: img1, thumbs: [{ index: '0', full: img1 }] })
  );
  const resMiss = runValidator(fxMiss.distDir, fxMiss.contentDir);
  assert.equal(resMiss.status, 1);
  assert.match(resMiss.stderr, /GALLERY_MISSING_IMAGES/);

  // 2. Extra image rendered (Markdown vs disk drift) -> GALLERY_EXTRA_IMAGES
  const fxExtra = createFixture();
  seedMediaAssets(fxExtra.distDir, [img1, img2, img3]);
  writeProductMd(fxExtra.contentDir, 'sku-x', 'en', { cover: img1, gallery: [img2] });
  writeProductHtml(
    fxExtra.distDir,
    'sku-x',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [
        { index: '0', full: img1 },
        { index: '1', full: img2 },
        { index: '2', full: img3 },
      ],
    })
  );
  const resExtra = runValidator(fxExtra.distDir, fxExtra.contentDir);
  assert.equal(resExtra.status, 1);
  assert.match(resExtra.stderr, /GALLERY_EXTRA_IMAGES/);

  // 3. Wrong main image and dialog image -> MAIN_IMAGE_MISMATCH + DIALOG_IMAGE_MISMATCH
  const fxMain = createFixture();
  seedMediaAssets(fxMain.distDir, [img1, img2]);
  writeProductMd(fxMain.contentDir, 'sku-x', 'en', { cover: img1, gallery: [img2] });
  writeProductHtml(
    fxMain.distDir,
    'sku-x',
    'en',
    renderGalleryHtml({
      mainSrc: img2,
      dialogSrc: img2,
      thumbs: [
        { index: '0', full: img1 },
        { index: '1', full: img2 },
      ],
    })
  );
  const resMain = runValidator(fxMain.distDir, fxMain.contentDir);
  assert.equal(resMain.status, 1);
  assert.match(resMain.stderr, /MAIN_IMAGE_MISMATCH/);
  assert.match(resMain.stderr, /DIALOG_IMAGE_MISMATCH/);

  // 4. Wrong thumbnail order -> GALLERY_ORDER_MISMATCH
  const fxOrder = createFixture();
  seedMediaAssets(fxOrder.distDir, [img1, img2]);
  writeProductMd(fxOrder.contentDir, 'sku-x', 'en', { cover: img1, gallery: [img2] });
  writeProductHtml(
    fxOrder.distDir,
    'sku-x',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [
        { index: '0', full: img2 },
        { index: '1', full: img1 },
      ],
    })
  );
  const resOrder = runValidator(fxOrder.distDir, fxOrder.contentDir);
  assert.equal(resOrder.status, 1);
  assert.match(resOrder.stderr, /GALLERY_ORDER_MISMATCH/);
});

test('duplicate Markdown/HTML images and non-sequential gallery indexes fail gate', () => {
  const img1 = '/media/products/sku-dup/1.webp';
  const img2 = '/media/products/sku-dup/2.webp';

  const fxDupMd = createFixture();
  seedMediaAssets(fxDupMd.distDir, [img1]);
  writeProductMd(fxDupMd.contentDir, 'sku-dup', 'en', { cover: img1, gallery: [img1] });
  writeProductHtml(
    fxDupMd.distDir,
    'sku-dup',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [{ index: '0', full: img1 }],
    })
  );
  const resDupMd = runValidator(fxDupMd.distDir, fxDupMd.contentDir);
  assert.equal(resDupMd.status, 1);
  assert.match(resDupMd.stderr, /DUPLICATE_IMAGE_IN_MARKDOWN/);

  const fxDupHtml = createFixture();
  seedMediaAssets(fxDupHtml.distDir, [img1, img2]);
  writeProductMd(fxDupHtml.contentDir, 'sku-dup', 'en', { cover: img1, gallery: [img2] });
  writeProductHtml(
    fxDupHtml.distDir,
    'sku-dup',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [
        { index: '0', full: img1 },
        { index: '2', full: img1 },
        { index: '2', full: img2 },
      ],
    })
  );
  const resDupHtml = runValidator(fxDupHtml.distDir, fxDupHtml.contentDir);
  assert.equal(resDupHtml.status, 1);
  assert.match(resDupHtml.stderr, /DUPLICATE_RENDERED_IMAGE/);
  assert.match(resDupHtml.stderr, /THUMBNAIL_INDEX_MISMATCH/);
});

test('missing dist/media assets, wrong thumb src, derived Markdown refs, and exact case mismatches fail gate', () => {
  const img1 = '/media/products/sku-asset/Hero.webp';

  // 1. Missing -240w.webp asset on disk -> ASSET_MISSING_IN_DIST
  const fxNoThumb = createFixture();
  seedMediaAssets(fxNoThumb.distDir, [img1], { includeThumbs: false });
  writeProductMd(fxNoThumb.contentDir, 'sku-asset', 'en', { cover: img1, gallery: [] });
  writeProductHtml(
    fxNoThumb.distDir,
    'sku-asset',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [{ index: '0', full: img1 }],
    })
  );
  const resNoThumb = runValidator(fxNoThumb.distDir, fxNoThumb.contentDir);
  assert.equal(resNoThumb.status, 1);
  assert.match(resNoThumb.stderr, /ASSET_MISSING_IN_DIST/);

  // 2. HTML thumbnail <img> src points to original instead of -240w.webp -> THUMB_SRC_MISMATCH
  const fxWrongThumb = createFixture();
  seedMediaAssets(fxWrongThumb.distDir, [img1]);
  writeProductMd(fxWrongThumb.contentDir, 'sku-asset', 'en', { cover: img1, gallery: [] });
  writeProductHtml(
    fxWrongThumb.distDir,
    'sku-asset',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [{ index: '0', full: img1, imgSrc: img1 }],
    })
  );
  const resWrongThumb = runValidator(fxWrongThumb.distDir, fxWrongThumb.contentDir);
  assert.equal(resWrongThumb.status, 1);
  assert.match(resWrongThumb.stderr, /THUMB_SRC_MISMATCH/);

  // 3. Exact disk filename case mismatch (APFS two-step rename) -> ASSET_CASE_MISMATCH
  const fxCase = createFixture();
  seedMediaAssets(fxCase.distDir, [img1]);
  const diskHero = path.join(fxCase.distDir, 'media/products/sku-asset/Hero.webp');
  const tmpHero = path.join(fxCase.distDir, 'media/products/sku-asset/Hero.tmp');
  const lowerHero = path.join(fxCase.distDir, 'media/products/sku-asset/hero.webp');
  fs.renameSync(diskHero, tmpHero);
  fs.renameSync(tmpHero, lowerHero);

  writeProductMd(fxCase.contentDir, 'sku-asset', 'en', { cover: img1, gallery: [] });
  writeProductHtml(
    fxCase.distDir,
    'sku-asset',
    'en',
    renderGalleryHtml({
      mainSrc: img1,
      thumbs: [{ index: '0', full: img1 }],
    })
  );
  const resCase = runValidator(fxCase.distDir, fxCase.contentDir);
  assert.equal(resCase.status, 1);
  assert.match(resCase.stderr, /ASSET_CASE_MISMATCH/);

  // 4. Derived URL in Markdown cover -> DERIVED_IMAGE_IN_MARKDOWN
  const fxDerived = createFixture();
  const derivedUrl = '/media/products/sku-asset/Hero-400.webp';
  writeFile(path.join(fxDerived.distDir, derivedUrl.slice(1)), 'img');
  writeProductMd(fxDerived.contentDir, 'sku-asset', 'en', { cover: derivedUrl, gallery: [] });
  writeProductHtml(
    fxDerived.distDir,
    'sku-asset',
    'en',
    renderGalleryHtml({
      mainSrc: derivedUrl,
      thumbs: [{ index: '0', full: derivedUrl, imgSrc: derivedUrl }],
    })
  );
  const resDerived = runValidator(fxDerived.distDir, fxDerived.contentDir);
  assert.equal(resDerived.status, 1);
  assert.match(resDerived.stderr, /DERIVED_IMAGE_IN_MARKDOWN/);
});

test('missing product routes, redirect stubs, orphan pages, missing gallery DOM, bad YAML, and bad CLI fail closed', () => {
  const img1 = '/media/products/sku-route/1.webp';
  const fx = createFixture();
  seedMediaAssets(fx.distDir, [img1]);
  writeProductMd(fx.contentDir, 'sku-missing', 'en', { cover: img1 });
  writeProductMd(fx.contentDir, 'sku-redir', 'en', { cover: img1 });
  writeProductHtml(
    fx.distDir,
    'sku-redir',
    'en',
    '<!doctype html><meta http-equiv="refresh" content="0;url=/">'
  );
  writeProductMd(fx.contentDir, 'sku-nodom', 'en', { cover: img1 });
  writeProductHtml(fx.distDir, 'sku-nodom', 'en', '<!doctype html><html><body>No gallery</body></html>');
  writeProductHtml(
    fx.distDir,
    'sku-orphan',
    'fr',
    renderGalleryHtml({ mainSrc: img1, thumbs: [{ index: '0', full: img1 }] })
  );
  writeProductMd(fx.contentDir, 'sku-bad-yaml', 'en', { customYaml: '---\ntitle: [unterminated\n---\n' });

  const res = runValidator(fx.distDir, fx.contentDir);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /MISSING_PRODUCT_PAGE/);
  assert.match(res.stderr, /REDIRECT_STUB_FOR_PRODUCT/);
  assert.match(res.stderr, /MISSING_GALLERY_CONTAINER/);
  assert.match(res.stderr, /UNEXPECTED_ORPHAN_PRODUCT_PAGE/);
  assert.match(res.stderr, /CONTENT_PARSE_ERROR/);

  // Missing dist dir
  const noDist = runValidator(path.join(fx.base, 'no-such-dist'), fx.contentDir);
  assert.equal(noDist.status, 1);
  assert.match(noDist.stderr, /INVALID_DIST_DIR/);

  // Empty products directory
  const fxEmptyContent = createFixture();
  const emptyRes = runValidator(fxEmptyContent.distDir, fxEmptyContent.contentDir);
  assert.equal(emptyRes.status, 1);
  assert.match(emptyRes.stderr, /EMPTY_PRODUCTS_CONTENT/);

  // Unknown CLI flag
  const badFlag = runValidator(fx.distDir, fx.contentDir, ['--unknown-flag']);
  assert.equal(badFlag.status, 1);
  assert.match(badFlag.stderr, /Unknown option/);
});
