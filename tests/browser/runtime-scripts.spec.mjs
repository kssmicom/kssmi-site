// Browser tests for the scripts moved out of inline <script> into
// fingerprinted runtime assets (R-14): Header lifecycle, VJT Contact Core,
// VJT tracker bootstrap and the inquiry form. Each must run once per document,
// survive ClientRouter navigations and keep its consent and form behaviour.
//
// The tracker bootstrap skips localhost / 127.0.0.1 / ::1 on purpose, so these
// tests use kssmi.localhost, pinned to the IPv4 local server. Chromium never
// sends *.localhost through a proxy. Requests for any other host are aborted;
// nothing reaches a real analytics or form endpoint.
import { expect, test } from '@playwright/test';

const HOST = 'kssmi.localhost';
const ORIGIN = `http://${HOST}:4321`;

test.use({
  baseURL: ORIGIN,
  // The static server listens on 127.0.0.1 only; *.localhost may resolve to ::1.
  launchOptions: { args: [`--host-resolver-rules=MAP ${HOST} 127.0.0.1`] },
});

test.beforeEach(async ({ page }, testInfo) => {
  testInfo.runtimeRequests = [];
  testInfo.sendMailRequests = 0;
  testInfo.pageErrors = [];
  await page.route('**/*', (route) => {
    const url = route.request().url();
    if (!url.startsWith(ORIGIN)) return route.abort();
    const { pathname } = new URL(url);
    const runtime = pathname.match(/^\/assets\/runtime\/([a-z-]+)\.[0-9a-f]{12}\.js$/);
    if (runtime) testInfo.runtimeRequests.push(runtime[1]);
    if (pathname === '/send-mail.php') {
      testInfo.sendMailRequests += 1;
      return route.abort();
    }
    // Tracking and capability endpoints are not served locally; answer them
    // here so no request depends on the static server's 404 handling.
    if (pathname.startsWith('/api/')) {
      return route.fulfill({ status: 204, headers: { 'Cache-Control': 'no-store' }, body: '' });
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
});

async function expectCleanPage(page, testInfo) {
  expect(await page.evaluate(() => window.__cspViolations), 'CSP violations').toEqual([]);
  expect(testInfo.pageErrors, 'uncaught page errors').toEqual([]);
}

function countOf(testInfo, name) {
  return testInfo.runtimeRequests.filter((entry) => entry === name).length;
}

async function navigateWithin(page, href) {
  await page.evaluate(() => { window.__sameDocument = true; });
  await page.locator(`a[href="${href}"]:visible`).first().click();
  await page.waitForURL((url) => url.pathname.replace(/\/$/, '') === href.replace(/\/$/, ''));
  await page.waitForLoadState('load');
  expect(await page.evaluate(() => window.__sameDocument), 'navigation stayed in the same document').toBe(true);
}

test('runtime scripts: external, consent-gated tracker, one run across navigations', async ({ page }, testInfo) => {
  await page.goto('/');

  // The moved scripts are no longer inline, and each one has started.
  const inlineGuards = await page.evaluate(() => [...document.scripts]
    .filter((script) => !script.src)
    .map((script) => script.textContent)
    .filter((text) => /__kssmiHeaderLifecycle|__vjtLoaderBound|__vjtContactCoreBound/.test(text)).length);
  expect(inlineGuards, 'inline copies of the moved scripts').toBe(0);
  await page.waitForFunction(() => window.__kssmiHeaderLifecycle && window.__vjtLoaderBound && window.__vjtContactCoreBound);

  // No tracker before consent.
  await page.waitForTimeout(500);
  expect(countOf(testInfo, 'vjt-tracker'), 'tracker before consent').toBe(0);
  expect(await page.evaluate(() => typeof window.VJT_init)).toBe('undefined');

  // Accepting analytics consent loads the tracker once.
  await page.locator('#cookie-btn-accept').click();
  await page.waitForFunction(() => typeof window.VJT_init === 'function');
  expect(countOf(testInfo, 'vjt-tracker')).toBe(1);

  // Three ClientRouter navigations: nothing is fetched or bound again, and the
  // tracker is re-initialised for each new page.
  for (const href of ['/product', '/', '/product']) {
    await navigateWithin(page, href);
    await expect.poll(() => page.evaluate(() => window.VJTTracker && window.VJTTracker.page.url)).toBe(page.url());
  }
  for (const name of ['header', 'vjt-loader', 'vjt-contact-core', 'vjt-tracker']) {
    expect(countOf(testInfo, name), `${name} requests`).toBe(1);
  }

  // The Header lifecycle still binds the current page's header.
  const languageMenu = page.locator('.lang-dropdown-container').first();
  await languageMenu.locator('.lang-btn').click();
  await expect(languageMenu).toHaveClass(/is-open/);
  await page.locator('footer').first().click({ position: { x: 5, y: 5 } });
  await expect(languageMenu).not.toHaveClass(/is-open/);

  await expectCleanPage(page, testInfo);
});

test('runtime scripts: no tracker after rejecting consent, across navigations', async ({ page }, testInfo) => {
  await page.goto('/');
  await page.locator('#cookie-btn-reject').click();
  for (const href of ['/product', '/']) await navigateWithin(page, href);
  await page.waitForTimeout(500);
  expect(countOf(testInfo, 'vjt-tracker')).toBe(0);
  await expectCleanPage(page, testInfo);
});

for (const route of ['direct', 'soft navigation']) {
  test(`inquiry form: submit handler bound after ${route}`, async ({ page }, testInfo) => {
    if (route === 'direct') {
      await page.goto('/quote/');
    } else {
      await page.goto('/');
      await navigateWithin(page, '/quote');
    }
    await page.locator('#cookie-btn-reject').click();
    await page.locator('#name').fill('Browser test');
    await page.locator('#email').fill('browser-test@example.invalid');
    await page.locator('#details').fill('Runtime script binding test; never sent.');
    await page.locator('#inquiry-form button[type="submit"]').click();

    // Turnstile is blocked in the test, so the bound handler must stop the
    // submission and show the verification message instead of posting.
    await expect(page.locator('#turnstile-error')).toBeVisible();
    expect(testInfo.sendMailRequests, 'requests to send-mail.php').toBe(0);
    expect(new URL(page.url()).pathname.replace(/\/$/, '')).toBe('/quote');
    expect(countOf(testInfo, 'inquiry-form'), 'inquiry-form requests').toBe(1);
    await expectCleanPage(page, testInfo);
  });
}
