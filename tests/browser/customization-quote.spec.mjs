// Browser regression tests for Customization spec selection -> /quote/ handoff -> FormData.
// Runs strictly against freshly built local dist/; aborts all third-party requests and
// intercepts POST /send-mail.php locally so zero emails or network calls ever leave the host.
import { expect, test } from '@playwright/test';

async function attachIsolationGuards(page, testInfo, origin) {
  testInfo.blockedHosts = new Set();
  testInfo.pageErrors = [];
  testInfo.submittedForms = [];

  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = request.url();
    if (!url.startsWith(origin)) {
      testInfo.blockedHosts.add(new URL(url).host);
      return route.abort();
    }

    const parsed = new URL(url);
    if (parsed.pathname === '/send-mail.php' && request.method() === 'POST') {
      const bodyBuffer = request.postDataBuffer();
      const parsedForm = await new Response(bodyBuffer, { headers: request.headers() }).formData();
      const entries = [];
      for (const [key, value] of parsedForm.entries()) {
        entries.push([key, typeof value === 'string' ? value : `[File:${value.name}]`]);
      }
      testInfo.submittedForms.push(entries);
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    }

    return route.continue();
  });

  await page.addInitScript(() => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__cspViolations.push(`${event.effectiveDirective} ${event.blockedURI || '(inline)'}`);
    });
  });

  page.on('pageerror', (error) => testInfo.pageErrors.push(String(error)));
}

test.beforeEach(async ({ page, baseURL }, testInfo) => {
  await attachIsolationGuards(page, testInfo, baseURL || 'http://127.0.0.1:4321');
});

async function dismissCookieBannerIfPresent(page) {
  const reject = page.locator('#cookie-btn-reject');
  if (await reject.count()) {
    await reject.click();
  }
}

async function expectCleanPage(page, testInfo) {
  expect(await page.evaluate(() => window.__cspViolations), 'CSP violations').toEqual([]);
  expect(testInfo.pageErrors, 'uncaught page errors').toEqual([]);
  for (const host of testInfo.blockedHosts) {
    expect(host).not.toContain('127.0.0.1');
  }
}

function submittedFieldMap(entries) {
  return Object.fromEntries(entries);
}

test('single valid component selection flows from customization page into /quote/ UI and submitted FormData', async ({ page }, testInfo) => {
  await page.goto('/customization/');
  await dismissCookieBannerIfPresent(page);

  await expect(page.locator('meta[name="robots"][content*="noindex"]')).toHaveCount(0);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://kssmi.com/customization/');
  await expect(page.locator('link[rel="alternate"][hreflang]')).toHaveCount(18);

  const firstToggle = page.locator('[data-spec-toggle][data-spec-code="KS-LN-001"]').first();
  await expect(firstToggle).toHaveAttribute('aria-pressed', 'false');
  await firstToggle.click();
  await expect(firstToggle).toHaveAttribute('aria-pressed', 'true');

  const trayLink = page.locator('[data-spec-quote-link]');
  await expect(trayLink).toBeVisible();
  await expect(trayLink).toHaveAttribute('href', /\/quote\/\?specs=KS-LN-001$/);
  await trayLink.click();

  await page.waitForURL('**/quote/?specs=KS-LN-001');
  const form = page.locator('#inquiry-form');
  await expect(form).toBeVisible();

  const panel = page.locator('#selected-specs-panel');
  await expect(panel).toBeVisible();
  await expect(page.locator('#selected-specs-count')).toHaveText('1');
  await expect(page.locator('#selected-specs-chips')).toContainText('KS-LN-001');
  await expect(page.locator('#details')).toHaveValue(/Selected customization components:\n- KS-LN-001 \(Grey Lens \(CAT\.1\)\)/);
  await expect(form.locator('input[name="product_sku"]')).toHaveValue('');
  await expect(form.locator('input[name="product_name"]')).toHaveValue('Customization Components Inquiry');

  await page.fill('#name', 'Alice Buyer');
  await page.fill('#email', 'buyer@example.com');
  await page.check('#inquiry-privacy-consent');
  await page.evaluate(() => { window.turnstileToken = 'mock-turnstile-token'; });
  await page.click('#submit-btn');

  await page.waitForURL('**/thank-you/');
  expect(testInfo.submittedForms.length).toBe(1);
  const fields = submittedFieldMap(testInfo.submittedForms[0]);
  expect(fields.product_name).toBe('Customization Components Inquiry');
  expect(fields.product_sku).toBe('');
  expect(fields.details).toContain('KS-LN-001');
  expect(fields.inquiry_privacy_consent).toBe('1');
  expect(fields.inquiry_notice_id).toMatch(/^inquiry-privacy-/);

  await expectCleanPage(page, testInfo);
});

test('multiple valid component codes propagate without impersonating product_sku', async ({ page }, testInfo) => {
  await page.goto('/customization/');
  await dismissCookieBannerIfPresent(page);

  await page.locator('[data-spec-toggle][data-spec-code="KS-LN-001"]').first().click();
  await page.locator('[data-spec-toggle][data-spec-code="KS-LN-002"]').first().click();
  await page.locator('[data-spec-quote-link]').click();

  await page.waitForURL('**/quote/?specs=KS-LN-001%2CKS-LN-002');
  await expect(page.locator('#selected-specs-panel')).toBeVisible();
  await expect(page.locator('#selected-specs-count')).toHaveText('2');
  await expect(page.locator('#selected-specs-chips [data-quote-spec-remove]')).toHaveCount(2);

  const formState = await page.evaluate(() => {
    const f = document.querySelector('#inquiry-form');
    return Object.fromEntries(new FormData(f));
  });
  expect(formState.product_sku).toBe('');
  expect(formState.product_name).toBe('Customization Components Inquiry');
  expect(formState.details).toContain('- KS-LN-001');
  expect(formState.details).toContain('- KS-LN-002');
  await expectCleanPage(page, testInfo);
});

test('empty, invalid, unknown, and overlong ?specs= parameters fail closed cleanly', async ({ page }, testInfo) => {
  // 1. Plain /quote/ with no specs
  await page.goto('/quote/');
  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  await expect(page.locator('#details')).toHaveValue('');
  await expect(page.locator('#inquiry-form input[name="product_name"]')).toHaveValue('General Inquiry');
  await expect(page.locator('#inquiry-form input[name="product_sku"]')).toHaveValue('');

  // 2. Invalid/unknown only -> ignored completely
  await page.goto('/quote/?specs=KS-FAKE-999,__proto__,<script>alert(1)</script>');
  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  await expect(page.locator('#details')).toHaveValue('');
  await expect(page.locator('#inquiry-form input[name="product_name"]')).toHaveValue('General Inquiry');

  // 3. Mixed valid and invalid -> keeps only genuine catalog code
  await page.goto('/quote/?specs=KS-FAKE-999,bad_code,ks-ln-001');
  await expect(page.locator('#selected-specs-panel')).toBeVisible();
  await expect(page.locator('#selected-specs-count')).toHaveText('1');
  await expect(page.locator('#selected-specs-chips')).toContainText('KS-LN-001');
  await expect(page.locator('#selected-specs-chips')).not.toContainText('KS-FAKE-999');

  // 4. Overlong query string (> 512 chars) -> rejected fail closed
  const oversized = 'KS-LN-001,'.repeat(80);
  await page.goto(`/quote/?specs=${encodeURIComponent(oversized)}`);
  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  await expect(page.locator('#details')).toHaveValue('');

  await expectCleanPage(page, testInfo);
});

test('chip removal, user text preservation, reload persistence, and repeated init stay idempotent', async ({ page }, testInfo) => {
  await page.goto('/quote/?specs=KS-LN-001,KS-LN-002');
  const details = page.locator('#details');
  const initialVal = await details.inputValue();
  await details.fill(`${initialVal}\n\nTarget quantity: 500 pcs matte black.`);

  // Repeat astro:page-load initialization manually -> must not duplicate or wipe user copy
  await page.evaluate(() => {
    document.dispatchEvent(new Event('astro:page-load'));
    document.dispatchEvent(new Event('astro:page-load'));
  });
  const afterRepeat = await details.inputValue();
  expect(afterRepeat).toBe(`${initialVal}\n\nTarget quantity: 500 pcs matte black.`);

  // Remove first chip -> only KS-LN-002 stays, user text stays intact, URL updates cleanly
  await page.locator('[data-quote-spec-remove="KS-LN-001"]').click();
  await expect(page.locator('#selected-specs-count')).toHaveText('1');
  await expect(page.locator('#selected-specs-chips [data-quote-spec-remove="KS-LN-001"]')).toHaveCount(0);
  await expect(page.locator('#selected-specs-chips [data-quote-spec-remove="KS-LN-002"]')).toHaveCount(1);
  expect(new URL(page.url()).searchParams.get('specs')).toBe('KS-LN-002');
  const afterRemove = await details.inputValue();
  expect(afterRemove).not.toContain('KS-LN-001');
  expect(afterRemove).toContain('KS-LN-002');
  expect(afterRemove).toContain('Target quantity: 500 pcs matte black.');

  // Clear selection -> removes component block while keeping user's message intact
  await page.locator('#selected-specs-clear').click();
  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  expect(new URL(page.url()).searchParams.has('specs')).toBe(false);
  await expect(details).toHaveValue('Target quantity: 500 pcs matte black.');
  await expect(page.locator('#inquiry-form input[name="product_name"]')).toHaveValue('General Inquiry');

  // Reload with ?specs=KS-LN-002 -> renders cleanly without duplication
  await page.goto('/quote/?specs=KS-LN-002');
  await page.reload();
  await expect(page.locator('#selected-specs-count')).toHaveText('1');
  expect((await details.inputValue()).match(/Selected customization components:/g)?.length).toBe(1);

  await expectCleanPage(page, testInfo);
});

test('tampered sessionStorage is sanitized and never leaks onto plain /quote/ across ClientRouter navigation', async ({ page }, testInfo) => {
  await page.goto('/customization/');
  await dismissCookieBannerIfPresent(page);

  await page.evaluate(() => {
    sessionStorage.setItem('kssmi-selected-specs', JSON.stringify([
      { code: 'KS-FAKE-777', title: 'Forged Component' },
      { code: 'ks-ln-001', title: '<img src=x onerror=alert(1)>' },
    ]));
  });
  await page.reload();

  await expect(page.locator('[data-spec-tray]')).toBeVisible();
  await expect(page.locator('[data-spec-count]')).toHaveText('1');
  await expect(page.locator('[data-spec-chips]')).toContainText('KS-LN-001');
  await expect(page.locator('[data-spec-chips]')).not.toContainText('KS-FAKE-777');

  const stored = await page.evaluate(() => JSON.parse(sessionStorage.getItem('kssmi-selected-specs') || '[]'));
  expect(stored).toEqual([{ code: 'KS-LN-001', title: 'Grey Lens (CAT.1)' }]);

  // Soft-navigate to /quote/?specs=KS-LN-001, then to /, then to plain /quote/ -> zero stale leak
  await page.locator('[data-spec-quote-link]').click();
  await page.waitForURL('**/quote/?specs=KS-LN-001');
  await expect(page.locator('#selected-specs-panel')).toBeVisible();

  await page.locator('header a[href="/"]').first().click();
  await page.waitForURL((url) => url.pathname === '/');
  await page.evaluate(() => { window.__sameDocument = true; });
  await page.locator('main a[href="/quote/"]:visible, main a[href="/quote"]:visible').first().click();
  await page.waitForURL((url) => url.pathname === '/quote/' && !url.search);
  expect(await page.evaluate(() => window.__sameDocument), 'ClientRouter stayed in same document').toBe(true);

  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  await expect(page.locator('#details')).toHaveValue('');
  await expect(page.locator('#inquiry-form input[name="product_name"]')).toHaveValue('General Inquiry');

  await expectCleanPage(page, testInfo);
});

test('normal product inquiry page ignores ?specs= and keeps product attribution intact', async ({ page }, testInfo) => {
  await page.goto('/product/krs-210-rimless-sunglasses/?specs=KS-LN-001&product_name=Spoofed&sku=FAKE');
  await dismissCookieBannerIfPresent(page);

  const form = page.locator('#inquiry-form');
  await expect(form).toBeVisible();
  await expect(page.locator('#selected-specs-panel')).toBeHidden();
  await expect(page.locator('#details')).toHaveValue('');
  await expect(form.locator('input[name="source"]')).toHaveValue('product_page');
  await expect(form.locator('input[name="product_sku"]')).toHaveValue('KRS-210');
  await expect(form.locator('input[name="product_name"]')).toHaveValue('KRS-210 Custom Rimless Sunglasses');

  await page.fill('#name', 'Product Buyer');
  await page.fill('#email', 'product-buyer@example.com');
  await page.fill('#details', 'Interested in KRS-210 standard samples.');
  await page.check('#inquiry-privacy-consent');
  await page.evaluate(() => { window.turnstileToken = 'mock-turnstile-token'; });
  await page.click('#submit-btn');

  await page.waitForURL('**/thank-you/');
  expect(testInfo.submittedForms.length).toBe(1);
  const fields = submittedFieldMap(testInfo.submittedForms[0]);
  expect(fields.source).toBe('product_page');
  expect(fields.product_sku).toBe('KRS-210');
  expect(fields.product_name).toBe('KRS-210 Custom Rimless Sunglasses');
  expect(fields.details).toBe('Interested in KRS-210 standard samples.');
  expect(fields.details).not.toContain('KS-LN-001');

  await expectCleanPage(page, testInfo);
});

test('entity-encoded onerror handler on product page executes in browser without CSP violations', async ({ page, baseURL }, testInfo) => {
  const origin = baseURL || 'http://127.0.0.1:4321';
  let failedOnce = false;
  let sawRetryRequest = false;

  await page.route('**/media/products/krs-210-rimless-sunglasses/oem-odm-customized-rimless-sunglasses-krs-210-1.webp*', async (route) => {
    const reqUrl = route.request().url();
    if (!reqUrl.startsWith(origin)) return route.abort();
    if (!failedOnce && !reqUrl.includes('retry=1')) {
      failedOnce = true;
      return route.fulfill({ status: 404, body: 'simulated missing image' });
    }
    if (reqUrl.includes('retry=1')) {
      sawRetryRequest = true;
    }
    return route.continue();
  });

  await page.goto('/product/krs-210-rimless-sunglasses/');
  await expect.poll(() => failedOnce).toBe(true);
  await expect.poll(() => sawRetryRequest).toBe(true);
  await expect.poll(async () => page.evaluate(() => {
    const main = document.querySelector('img[data-gallery-main-image]');
    return Boolean(main && main.dataset.retried === 'true' && main.complete && main.naturalWidth > 0);
  })).toBe(true);

  await expectCleanPage(page, testInfo);
});

test('multilingual and Arabic RTL customization pages preserve specs across language switch and fit viewports', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/customization/');
  await dismissCookieBannerIfPresent(page);

  await expect(page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).resolves.toBe(true);
  await page.locator('[data-spec-toggle][data-spec-code="KS-LN-001"]').first().click();

  // Switch to Chinese via language switcher link (no 404, keeps sessionStorage specs with localized label)
  await page.locator('.lang-dropdown-menu a[href="/zh/customization/"]').evaluate((el) => el.click());
  await page.waitForURL('**/zh/customization/');
  await expect(page.locator('html')).toHaveAttribute('lang', 'zh');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://kssmi.com/zh/customization/');
  await expect(page.locator('[data-spec-tray] [data-spec-count]')).toHaveText('1');
  await expect(page.locator('[data-spec-toggle][data-spec-code="KS-LN-001"]').first()).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('h1')).not.toHaveText('Eyewear Customization Components');

  // Switch to German (longer nav labels) and check 1280px desktop header & page do not overflow horizontally
  await page.locator('.lang-dropdown-menu a[href="/de/customization/"]').evaluate((el) => el.click());
  await page.waitForURL('**/de/customization/');
  await expect(page.locator('html')).toHaveAttribute('lang', 'de');
  await expect(page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).resolves.toBe(true);

  // Switch to Arabic RTL and check mobile 375px + localized /ar/quote/?specs= handoff
  await page.setViewportSize({ width: 375, height: 812 });
  await page.locator('.lang-dropdown-menu a[href="/ar/customization/"]').evaluate((el) => el.click());
  await page.waitForURL('**/ar/customization/');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).resolves.toBe(true);

  const arTrayLink = page.locator('[data-spec-quote-link]');
  await expect(arTrayLink).toHaveAttribute('href', /\/ar\/quote\/\?specs=KS-LN-001$/);
  await arTrayLink.click();
  await page.waitForURL('**/ar/quote/?specs=KS-LN-001');
  await expect(page.locator('#selected-specs-panel')).toBeVisible();
  await expect(page.locator('#selected-specs-count')).toHaveText('1');
  await expect(page.locator('#details')).toHaveValue(/KS-LN-001/);

  await expectCleanPage(page, testInfo);
});
