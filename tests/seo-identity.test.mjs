import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { projectRoot, ensureTemporaryRoot, createTask } from '../scripts/local-tools.mjs';
import {
  expectedSelfCanonical,
  LANGUAGES,
  ORIGIN,
  parseHtmlIdentity,
  validateIdentityUrl,
} from '../scripts/validate-hreflang.mjs';

ensureTemporaryRoot();
const taskRoot = createTask('seo-identity-tests', projectRoot);
const validatorScript = path.join(projectRoot, 'scripts/validate-hreflang.mjs');

let sequence = 0;

function createDistDir() {
  const base = path.join(taskRoot, `case-${++sequence}`);
  const distDir = path.join(base, 'dist');
  fs.mkdirSync(distDir, { recursive: true });
  return { base, distDir };
}

function writeFile(filePath, content) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function buildLocalizedUrl(lang, baseRoute) {
  const cleanRoute = baseRoute.startsWith('/') ? baseRoute : `/${baseRoute}`;
  const pathPart = lang === 'en' ? cleanRoute : `/${lang}${cleanRoute}`;
  return `${ORIGIN}${pathPart}`;
}

function buildRouteRel(lang, baseRoute) {
  const cleanRoute = baseRoute.replace(/^\/+/, '').replace(/\/+$/, '');
  const prefix = lang === 'en' ? '' : `${lang}/`;
  return cleanRoute ? `${prefix}${cleanRoute}/index.html` : `${prefix}index.html`;
}

function buildIndexableHtml({
  lang,
  canonical,
  ogUrl = canonical,
  ogImage = `${ORIGIN}/media/global/404-team.webp`,
  twitterImage = `${ORIGIN}/media/global/404-team.webp`,
  alternates = [],
  reorderedAttrs = false,
  extraHead = '',
  extraBody = '',
}) {
  const canonTag = reorderedAttrs
    ? `<link href='${canonical}' rel=canonical>`
    : `<link rel="canonical" href="${canonical}" />`;

  const ogUrlTag = reorderedAttrs
    ? `<meta content='${ogUrl}' property="og:url">`
    : `<meta property="og:url" content="${ogUrl}" />`;

  const altTags = alternates
    .map(alt =>
      reorderedAttrs
        ? `<link href='${alt.href}' hreflang="${alt.hreflang}" rel='alternate'>`
        : `<link rel="alternate" hreflang="${alt.hreflang}" href="${alt.href}" />`
    )
    .join('\n  ');

  return `<!doctype html>
<html lang="${lang}">
<head>
  <meta charset="utf-8" />
  <title>Test ${lang}</title>
  <link rel="dns-prefetch" href="https://www.google-analytics.com" />
  ${ogUrlTag}
  <meta property="og:image" content="${ogImage}" />
  <meta name="twitter:image" content="${twitterImage}" />
  ${canonTag}
  ${altTags}
  ${extraHead}
</head>
<body>
  <main id="main-content" tabindex="-1">
    <a href="https://www.linkedin.com/company/kssmi-eyewear">External Link</a>
    ${extraBody}
  </main>
  <script>var apiUrl = isDev ? 'http://localhost:4325/send-mail.php' : '/send-mail.php';</script>
</body>
</html>`;
}

function seedCluster(distDir, baseRoute, { canonicalRoute = baseRoute, mutateHtml = null } = {}) {
  const alternates = [
    ...LANGUAGES.map(l => ({ hreflang: l, href: buildLocalizedUrl(l, canonicalRoute) })),
    { hreflang: 'x-default', href: buildLocalizedUrl('en', canonicalRoute) },
  ];

  for (const lang of LANGUAGES) {
    const rel = buildRouteRel(lang, baseRoute);
    const canonical = buildLocalizedUrl(lang, canonicalRoute);
    let html = buildIndexableHtml({
      lang,
      canonical,
      alternates,
      reorderedAttrs: lang === 'de',
    });
    if (mutateHtml) {
      html = mutateHtml({ lang, rel, canonical, alternates, html });
    }
    writeFile(path.join(distDir, rel), html);
  }
}

function buildNoindexHtml({
  lang = 'en',
  canonical,
  ogUrl = canonical,
  extraHead = '',
}) {
  return `<!doctype html>
<html lang="${lang}">
<head>
  <meta charset="utf-8" />
  <title>Noindex ${lang}</title>
  <meta name="robots" content="noindex, nofollow" />
  <meta property="og:url" content="${ogUrl}" />
  <meta property="og:image" content="${ORIGIN}/media/global/404-team.webp" />
  <link rel="canonical" href="${canonical}" />
  ${extraHead}
</head>
<body>
  <main id="main-content" tabindex="-1"></main>
  <script>var apiUrl = isDev ? 'http://localhost:4325/send-mail.php' : '/send-mail.php';</script>
</body>
</html>`;
}

function runValidator(distDir, extraArgs = []) {
  return spawnSync(process.execPath, [validatorScript, distDir, ...extraArgs], {
    cwd: projectRoot,
    encoding: 'utf8',
    timeout: 30000,
  });
}

test('unit helpers parse reversed attributes, ignore scripts/comments, and classify URL identity issues', () => {
  const parsed = parseHtmlIdentity(`
    <!doctype html>
    <HTML LANG='DE'>
    <head>
      <!-- <link rel="canonical" href="https://evil.example/"> -->
      <link href="https://kssmi.com/de/" rel='alternate canonical'>
      <meta content='https://kssmi.com/de/' property=og:url>
      <meta name="ROBOTS" content="follow, noindex">
      <script>const decoy = '<link rel="canonical" href="http://localhost:4324/">';</script>
    </head>
  `);
  assert.equal(parsed.hasHtmlTag, true);
  assert.equal(parsed.sourceLang, 'de');
  assert.equal(parsed.noindex, true);
  assert.deepEqual(parsed.canonicals, ['https://kssmi.com/de/']);
  assert.deepEqual(parsed.ogUrls, ['https://kssmi.com/de/']);

  assert.equal(expectedSelfCanonical('index.html'), `${ORIGIN}/`);
  assert.equal(expectedSelfCanonical('404.html'), `${ORIGIN}/404/`);
  assert.equal(expectedSelfCanonical('de/product/kso-1/index.html'), `${ORIGIN}/de/product/kso-1/`);

  assert.equal(validateIdentityUrl(`${ORIGIN}/de/`).ok, true);
  assert.equal(validateIdentityUrl(`${ORIGIN}//de/`).code, 'DOUBLE_SLASH_URL');
  assert.equal(validateIdentityUrl('http://localhost:4324/de/').code, 'LOCALHOST_LEAK');
  assert.equal(validateIdentityUrl('http://127.0.0.1:4324/').code, 'LOCALHOST_LEAK');
  assert.equal(validateIdentityUrl('https://other-site.example/de/').code, 'WRONG_ORIGIN');
  assert.equal(validateIdentityUrl('http://kssmi.com/de/').code, 'WRONG_ORIGIN');
});

test('valid 17-language cluster plus 404.html, noindex pages, inquiry-form fragments, and redirect stub pass with report JSON', () => {
  const { base, distDir } = createDistDir();
  seedCluster(distDir, '/');
  seedCluster(distDir, '/product/target/');

  // Root 404.html (canonical https://kssmi.com/404/) and multilingual thank-you noindex page
  writeFile(path.join(distDir, '404.html'), buildNoindexHtml({ lang: 'en', canonical: `${ORIGIN}/404/` }));
  writeFile(
    path.join(distDir, 'thank-you/index.html'),
    buildNoindexHtml({ lang: 'en', canonical: `${ORIGIN}/thank-you/` })
  );

  // Internal InquiryFormLazy fragment
  writeFile(
    path.join(distDir, 'inquiry-form/en/index.html'),
    `<meta name="robots" content="noindex, nofollow" />\n<form data-inquiry></form>`
  );

  // Astro redirect stub
  writeFile(
    path.join(distDir, 'product/old-slug/index.html'),
    `<!doctype html><title>Redirect</title><meta http-equiv="refresh" content="0;url=/product/target/"><meta name="robots" content="noindex"><link rel="canonical" href="${ORIGIN}/product/target/">`
  );

  const reportOut = path.join(base, 'report.json');
  const res = runValidator(distDir, ['--report-out', reportOut]);
  assert.equal(res.status, 0, res.stderr + res.stdout);

  const report = JSON.parse(fs.readFileSync(reportOut, 'utf8'));
  assert.equal(report.summary.totalHtmlFiles, 38);
  assert.equal(report.summary.indexablePages, 34);
  assert.equal(report.summary.noindexPages, 2);
  assert.equal(report.summary.internalFragments, 1);
  assert.equal(report.summary.redirectStubs, 1);
  assert.equal(report.summary.issueCount, 0);
});

test('duplicate canonical, missing canonical, and whole-cluster canonical misdirection fail gate', () => {
  // 1. Duplicate canonical with reversed attribute order / body placement
  const fxDup = createDistDir();
  seedCluster(fxDup.distDir, '/', {
    mutateHtml: ({ lang, html }) =>
      lang === 'fr'
        ? html.replace('</body>', `<link href="${ORIGIN}/fr/" rel="canonical"></body>`)
        : html,
  });
  const resDup = runValidator(fxDup.distDir);
  assert.equal(resDup.status, 1);
  assert.match(resDup.stderr, /DUPLICATE_CANONICAL/);

  // 2. Missing canonical
  const fxMissing = createDistDir();
  seedCluster(fxMissing.distDir, '/', {
    mutateHtml: ({ lang, html }) =>
      lang === 'en' ? html.replace(/<link[^>]*canonical[^>]*>/i, '') : html,
  });
  const resMissing = runValidator(fxMissing.distDir);
  assert.equal(resMissing.status, 1);
  assert.match(resMissing.stderr, /MISSING_CANONICAL/);

  // 3. Whole-cluster misdirection: /alt/ 17-lang cluster exists on disk, but every page's canonical/og:url/hreflang
  //    points consistently to /target/ instead of /alt/! Simple reciprocal check alone would pass; CANONICAL_NOT_SELF catches it.
  const fxMis = createDistDir();
  seedCluster(fxMis.distDir, '/target/');
  seedCluster(fxMis.distDir, '/alt/', { canonicalRoute: '/target/' });
  const resMis = runValidator(fxMis.distDir);
  assert.equal(resMis.status, 1);
  assert.match(resMis.stderr, /CANONICAL_NOT_SELF/);
});

test('wrong domain, localhost leak, and host double slash in identity tags fail gate', () => {
  // 1. Wrong domain / wrong scheme / wrong subdomain in canonical, og:url, and og:image
  const fxWrongOrigin = createDistDir();
  seedCluster(fxWrongOrigin.distDir, '/', {
    mutateHtml: ({ lang, html }) => {
      if (lang === 'it') {
        return html
          .replace(`<link rel="canonical" href="${ORIGIN}/it/" />`, '<link rel="canonical" href="https://other-site.example/it/" />')
          .replace(`<meta property="og:url" content="${ORIGIN}/it/" />`, '<meta property="og:url" content="http://kssmi.com/it/" />')
          .replace(`<meta property="og:image" content="${ORIGIN}/media/global/404-team.webp" />`, '<meta property="og:image" content="https://www.kssmi.com/media/a.webp" />');
      }
      return html;
    },
  });
  const resWrongOrigin = runValidator(fxWrongOrigin.distDir);
  assert.equal(resWrongOrigin.status, 1);
  assert.match(resWrongOrigin.stderr, /WRONG_ORIGIN/);

  // 2. Localhost leak in canonical, og:url, and hreflang
  const fxLocal = createDistDir();
  seedCluster(fxLocal.distDir, '/', {
    mutateHtml: ({ lang, html }) =>
      lang === 'es'
        ? html.replace(`<meta property="og:url" content="${ORIGIN}/es/" />`, '<meta property="og:url" content="http://127.0.0.1:4324/es/" />')
        : html,
  });
  const resLocal = runValidator(fxLocal.distDir);
  assert.equal(resLocal.status, 1);
  assert.match(resLocal.stderr, /LOCALHOST_LEAK/);

  // 3. Double slash after host in canonical or hreflang
  const fxSlash = createDistDir();
  seedCluster(fxSlash.distDir, '/', {
    mutateHtml: ({ lang, html }) =>
      lang === 'ja'
        ? html.replace(`<link rel="canonical" href="${ORIGIN}/ja/" />`, `<link rel="canonical" href="${ORIGIN}//ja/" />`)
        : html,
  });
  const resSlash = runValidator(fxSlash.distDir);
  assert.equal(resSlash.status, 1);
  assert.match(resSlash.stderr, /DOUBLE_SLASH_URL/);
});

test('og:url drift (missing, duplicate, and mismatching canonical) fails gate', () => {
  const fx = createDistDir();
  seedCluster(fx.distDir, '/', {
    mutateHtml: ({ lang, html }) => {
      if (lang === 'en') {
        return html.replace(`<meta property="og:url" content="${ORIGIN}/" />`, `<meta property="og:url" content="${ORIGIN}/other/" />`);
      }
      if (lang === 'pt') {
        return html.replace(/<meta[^>]*og:url[^>]*>/i, '');
      }
      if (lang === 'ko') {
        return html.replace('</head>', `<meta property="og:url" content="${ORIGIN}/ko/"></head>`);
      }
      return html;
    },
  });
  const res = runValidator(fx.distDir);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /OG_URL_MISMATCH/);
  assert.match(res.stderr, /MISSING_OG_URL/);
  assert.match(res.stderr, /DUPLICATE_OG_URL/);
});

test('existing hreflang matrix regressions (missing lang, x-default, reciprocity, noindex rules, html lang) remain blocked', () => {
  // 1. Missing hreflang, bad x-default, non-reciprocal target, html lang mismatch
  const fx = createDistDir();
  seedCluster(fx.distDir, '/', {
    mutateHtml: ({ lang, html }) => {
      if (lang === 'zh') {
        return html
          .replace(`<link rel="alternate" hreflang="ar" href="${ORIGIN}/ar/" />`, '')
          .replace(`<link rel="alternate" hreflang="x-default" href="${ORIGIN}/" />`, `<link rel="alternate" hreflang="x-default" href="${ORIGIN}/zh/" />`)
          .replace('<html lang="zh">', '<html lang="en">');
      }
      if (lang === 'tr') {
        // Drop reciprocal link back to ja
        return html.replace(`<link rel="alternate" hreflang="ja" href="${ORIGIN}/ja/" />`, '');
      }
      return html;
    },
  });
  // Full noindex page wrongly emitting hreflang + pointing og:url wrong
  writeFile(
    path.join(fx.distDir, 'thank-you/index.html'),
    buildNoindexHtml({
      lang: 'en',
      canonical: `${ORIGIN}/thank-you/`,
      extraHead: `<link rel="alternate" hreflang="en" href="${ORIGIN}/thank-you/" />`,
    })
  );

  const res = runValidator(fx.distDir);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /MISSING_HREFLANG/);
  assert.match(res.stderr, /X_DEFAULT_MISMATCH/);
  assert.match(res.stderr, /LANG_PATH_MISMATCH/);
  assert.match(res.stderr, /HREFLANG_NOT_RECIPROCAL/);
  assert.match(res.stderr, /NOINDEX_HAS_HREFLANG/);

  // 2. Indexable hreflang pointing to a noindex page
  const fxNoindexTarget = createDistDir();
  seedCluster(fxNoindexTarget.distDir, '/');
  writeFile(
    path.join(fxNoindexTarget.distDir, 'ru/index.html'),
    buildNoindexHtml({ lang: 'ru', canonical: `${ORIGIN}/ru/` })
  );
  const resNoindexTarget = runValidator(fxNoindexTarget.distDir);
  assert.equal(resNoindexTarget.status, 1);
  assert.match(resNoindexTarget.stderr, /HREFLANG_TARGET_NOINDEX/);
});

test('redirect stub, fragment, malformed HTML, empty dist, and CLI edge cases fail closed', () => {
  const fx = createDistDir();
  seedCluster(fx.distDir, '/');

  // Redirect missing target & self-loop & wrong canonical
  writeFile(
    path.join(fx.distDir, 'product/bad-redir/index.html'),
    `<!doctype html><meta http-equiv="refresh" content="0;url=/product/missing/"><meta name="robots" content="noindex"><link rel="canonical" href="${ORIGIN}/">`
  );
  writeFile(
    path.join(fx.distDir, 'product/loop-redir/index.html'),
    `<!doctype html><meta http-equiv="refresh" content="0;url=/product/loop-redir/"><meta name="robots" content="noindex">`
  );
  // Fragment missing noindex & emitting canonical
  writeFile(
    path.join(fx.distDir, 'inquiry-form/en/index.html'),
    `<link rel="canonical" href="${ORIGIN}/inquiry-form/en/"><form></form>`
  );
  // Malformed HTML (neither fragment, redirect, nor <html>)
  writeFile(path.join(fx.distDir, 'broken/index.html'), '<div>orphan markup without html tag</div>');

  const res = runValidator(fx.distDir);
  assert.equal(res.status, 1);
  assert.match(res.stderr, /REDIRECT_TARGET_MISSING/);
  assert.match(res.stderr, /REDIRECT_CANONICAL_MISMATCH/);
  assert.match(res.stderr, /REDIRECT_SELF_LOOP/);
  assert.match(res.stderr, /FRAGMENT_MISSING_NOINDEX/);
  assert.match(res.stderr, /FRAGMENT_HAS_CANONICAL/);
  assert.match(res.stderr, /MALFORMED_HTML_DOCUMENT/);

  // Empty dist dir fails closed
  const fxEmpty = createDistDir();
  const emptyRes = runValidator(fxEmpty.distDir);
  assert.equal(emptyRes.status, 1);
  assert.match(emptyRes.stderr, /EMPTY_DIST/);

  // Missing dist dir fails closed
  const noDistRes = runValidator(path.join(fxEmpty.base, 'no-dist'));
  assert.equal(noDistRes.status, 1);
  assert.match(noDistRes.stderr, /INVALID_DIST_DIR/);

  // Unknown CLI flag fails closed
  const badFlagRes = runValidator(fx.distDir, ['--unknown-flag']);
  assert.equal(badFlagRes.status, 1);
  assert.match(badFlagRes.stderr, /Unknown option/);
});

test('English-only indexable /customization/ routes pass without hreflang and reject stray alternates', () => {
  const fxPass = createDistDir();
  seedCluster(fxPass.distDir, '/');
  writeFile(
    path.join(fxPass.distDir, 'customization/index.html'),
    buildIndexableHtml({
      lang: 'en',
      canonical: `${ORIGIN}/customization/`,
      alternates: [],
    })
  );
  writeFile(
    path.join(fxPass.distDir, 'customization/plating/index.html'),
    buildIndexableHtml({
      lang: 'en',
      canonical: `${ORIGIN}/customization/plating/`,
      alternates: [],
    })
  );
  const resPass = runValidator(fxPass.distDir);
  assert.equal(resPass.status, 0, resPass.stderr);

  // Stray hreflang alternates on English-only route must fail
  const fxStrayAlt = createDistDir();
  seedCluster(fxStrayAlt.distDir, '/');
  writeFile(
    path.join(fxStrayAlt.distDir, 'customization/index.html'),
    buildIndexableHtml({
      lang: 'en',
      canonical: `${ORIGIN}/customization/`,
      alternates: [{ hreflang: 'en', href: `${ORIGIN}/customization/` }],
    })
  );
  const resStrayAlt = runValidator(fxStrayAlt.distDir);
  assert.equal(resStrayAlt.status, 1);
  assert.match(resStrayAlt.stderr, /ENGLISH_ONLY_HAS_HREFLANG/);

  // Non-allowlisted route with zero hreflang must still fail with MISSING_HREFLANG
  const fxMissingHreflang = createDistDir();
  seedCluster(fxMissingHreflang.distDir, '/');
  writeFile(
    path.join(fxMissingHreflang.distDir, 'about-us/index.html'),
    buildIndexableHtml({
      lang: 'en',
      canonical: `${ORIGIN}/about-us/`,
      alternates: [],
    })
  );
  const resMissingHreflang = runValidator(fxMissingHreflang.distDir);
  assert.equal(resMissingHreflang.status, 1);
  assert.match(resMissingHreflang.stderr, /MISSING_HREFLANG/);
});
