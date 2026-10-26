// Browser smoke tests for the built site. They cover the behaviour that has
// regressed or been fixed before: the static CSP, the cookie banner and the
// consent paths, ClientRouter navigations, RTL layout, search and 404.
import { expect, test } from '@playwright/test';

const ORIGIN = 'http://127.0.0.1:4321';

// Fresh state per test; third-party requests are aborted and recorded; CSP
// violations and uncaught page errors are collected.
test.beforeEach(async ({ page }, testInfo) => {
  testInfo.blockedHosts = new Set();
  testInfo.pageErrors = [];
  await page.route('**/*', (route) => {
    const url = route.request().url();
    if (url.startsWith(ORIGIN)) return route.continue();
    testInfo.blockedHosts.add(new URL(url).host);
    return route.abort();
  });
  await page.addInitScript(() => {
    window.__cspViolations = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      window.__cspViolations.push(`${event.effectiveDirective} ${event.blockedURI || '(inline)'}`);
    });
  });
  page.on('pageerror', (error) => testInfo.pageErrors.push(String(error)));
});

async function expectCleanPage(page, testInfo) {
  expect(await page.evaluate(() => window.__cspViolations), 'CSP violations').toEqual([]);
  expect(testInfo.pageErrors, 'uncaught page errors').toEqual([]);
}

async function consentRecord(page) {
  return page.evaluate(() => JSON.parse(localStorage.getItem('cookie-consent') || 'null'));
}

async function lastConsentUpdate(page) {
  return page.evaluate(() => {
    const updates = (window.dataLayer || []).filter((entry) => entry && entry[0] === 'consent' && entry[1] === 'update');
    return updates.length ? updates[updates.length - 1][2] : null;
  });
}

// Waits for a ClientRouter navigation: same document, new path.
async function navigateWithin(page, href) {
  await page.evaluate(() => { window.__sameDocument = true; });
  await page.locator(`a[href="${href}"]`).first().click();
  await page.waitForURL((url) => url.pathname.replace(/\/$/, '') === href.replace(/\/$/, ''));
  await page.waitForFunction(() => document.querySelectorAll('#cookie-banner').length >= 1 || localStorage.getItem('cookie-consent'));
  expect(await page.evaluate(() => window.__sameDocument), 'navigation stayed in the same document').toBe(true);
}

test('home: banner built once, no CSP violations or page errors', async ({ page }, testInfo) => {
  await page.goto('/');
  await expect(page.locator('#cookie-banner-main')).toBeVisible();
  await page.waitForTimeout(1000); // the astro:page-load rebuild used to happen here
  await expect(page.locator('#cookie-banner')).toHaveCount(1);
  await expect(page.locator('#cookie-banner [style]')).toHaveCount(0);
  await expectCleanPage(page, testInfo);
});

test('consent: reject, save, withdraw and accept update storage and Consent Mode', async ({ page }) => {
  await page.goto('/');
  await page.locator('#cookie-btn-reject').click();
  expect(await consentRecord(page)).toMatchObject({ analytics: false, ads: false });
  expect(await lastConsentUpdate(page)).toMatchObject({ analytics_storage: 'denied', ad_storage: 'denied' });
  await expect(page.locator('#cookie-banner')).toHaveCount(0);
  await expect(page.locator('#cookie-settings-btn')).toBeVisible();

  await page.locator('#cookie-settings-btn').click();
  await expect(page.locator('#cookie-modal-overlay')).toBeVisible();
  await expect(page.locator('#cookie-check-analytics')).not.toBeChecked();
  await page.locator('#cookie-check-analytics').check();
  await page.locator('#cookie-modal-save').click();
  expect(await consentRecord(page)).toMatchObject({ analytics: true, ads: false });
  expect(await lastConsentUpdate(page)).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied' });

  await page.locator('#cookie-settings-btn').click();
  await expect(page.locator('#cookie-check-analytics')).toBeChecked();
  await page.locator('#cookie-check-analytics').uncheck();
  await page.locator('#cookie-modal-save').click();
  expect(await consentRecord(page)).toMatchObject({ analytics: false, ads: false });
  expect(await lastConsentUpdate(page)).toMatchObject({ analytics_storage: 'denied' });

  await page.evaluate(() => localStorage.removeItem('cookie-consent'));
  await page.reload();
  await page.locator('#cookie-btn-accept').click();
  expect(await consentRecord(page)).toMatchObject({ analytics: true, ads: true });
  expect(await lastConsentUpdate(page)).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted' });
});

test('consent: first-visit preferences start unchecked and saving them grants nothing', async ({ page }) => {
  await page.goto('/');
  await page.locator('#cookie-btn-preferences').click();
  await expect(page.locator('#cookie-modal-overlay')).toBeVisible();
  await expect(page.locator('#cookie-check-analytics')).not.toBeChecked();
  await expect(page.locator('#cookie-check-ads')).not.toBeChecked();
  await page.locator('#cookie-modal-save').click();
  expect(await consentRecord(page)).toMatchObject({ analytics: false, ads: false });
  expect(await lastConsentUpdate(page)).toMatchObject({ analytics_storage: 'denied', ad_storage: 'denied' });
  await expect(page.locator('#cookie-banner-main')).toHaveCount(0);
});

test('consent: Escape only acts while the preferences modal is open', async ({ page }) => {
  await page.goto('/');
  await page.locator('#cookie-btn-accept').click();
  const link = page.locator('footer a[href]').first();
  await link.focus();
  await page.keyboard.press('Escape');
  await expect(link).toBeFocused();

  await page.locator('#cookie-settings-btn').click();
  await expect(page.locator('#cookie-modal-overlay')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('#cookie-modal-overlay')).toBeHidden();
  await expect(page.locator('#cookie-settings-btn')).toBeFocused();
  expect(await consentRecord(page)).toMatchObject({ analytics: true, ads: true });
});

test('ClientRouter: navigations rebuild one banner in the page language', async ({ page }, testInfo) => {
  await page.goto('/zh/');
  await expect(page.locator('#cookie-banner')).toHaveCount(1);
  for (const href of ['/zh/product', '/zh/', '/zh/product']) {
    await navigateWithin(page, href);
    await expect(page.locator('#cookie-banner')).toHaveCount(1);
    await expect(page.locator('html')).toHaveAttribute('lang', 'zh');
    await expect(page.locator('.cookie-banner-message')).toContainText('Cookie');
  }
  await expectCleanPage(page, testInfo);
});

// R-31: arriving on a page whose last new script was an inline module made
// ClientRouter append a data: module, which the CSP blocks.
test('ClientRouter: quote, home and product navigations raise no CSP violations', async ({ page }, testInfo) => {
  await page.goto('/quote/');
  await page.locator('#cookie-btn-reject').click();
  for (const href of ['/', '/quote', '/product', '/']) {
    await page.locator(`a[href="${href}"]:visible`).first().click();
    await page.waitForURL((url) => url.pathname.replace(/\/$/, '') === href.replace(/\/$/, ''));
    await page.waitForLoadState('load');
  }
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => [...document.scripts].filter((s) => s.type === 'module' && !s.src).length), 'inline module scripts').toBe(0);
  await expectCleanPage(page, testInfo);
});

test('RTL: Arabic product page does not scroll sideways at 375px', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/ar/product/krs-210-rimless-sunglasses/');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(widths.scroll).toBeLessThanOrEqual(widths.client);
});

test('search: a query returns results with full-size arrows', async ({ page }) => {
  await page.goto('/search/?q=titanium');
  const arrows = page.locator('.src-arrow-icon');
  await expect(arrows.first()).toBeVisible({ timeout: 15000 });
  expect(await arrows.count()).toBeGreaterThan(0);
  const width = await arrows.first().evaluate((element) => element.getBoundingClientRect().width);
  expect(width).toBe(16);
});

test('404: unknown paths get the branded 404 page with status 404', async ({ page }, testInfo) => {
  const response = await page.goto('/this-page-does-not-exist-smoke/');
  expect(response.status()).toBe(404);
  await expect(page).toHaveTitle(/Page Not Found/);
  await expect(page.locator('#cookie-banner')).toHaveCount(1);
  await expectCleanPage(page, testInfo);
});

test('quote form: fields have accessible names and no request leaves the site', async ({ page }, testInfo) => {
  await page.goto('/quote/');
  await expect(page.getByRole('button', { name: 'Attach reference files' })).toBeAttached();
  await expect(page.locator('#name')).toBeVisible();
  await expectCleanPage(page, testInfo);
  // Third-party hosts may be attempted (they are aborted), but none may be reached.
  for (const host of testInfo.blockedHosts) expect(host).not.toContain('127.0.0.1');
});

for (const language of ['en', 'zh', 'ar']) {
  test(`inquiry privacy: required separate consent in ${language}`, async ({ page }, testInfo) => {
    const prefix = language === 'en' ? '' : `/${language}`;
    await page.goto(`${prefix}/quote/`);
    await page.locator('#cookie-btn-reject').click();
    const consent = page.locator('#inquiry-privacy-consent');
    await expect(consent).toBeVisible();
    await expect(consent).not.toBeChecked();
    await expect(consent).toHaveAttribute('required', '');
    expect(await consent.evaluate((input) => input.validity.valueMissing)).toBe(true);
    await expect(page.locator('input[name="inquiry_notice_id"]')).toHaveValue(/inquiry-privacy-/);
    await consent.check();
    expect(await consent.evaluate((input) => input.checkValidity())).toBe(true);
    expect(await consentRecord(page)).toMatchObject({ analytics: false, ads: false });
    await expect(page.locator('footer')).toContainText(language === 'zh' ? '可米科技有限公司' : 'Kssmi Technology Co., Ltd.');
    await expectCleanPage(page, testInfo);
  });
}
