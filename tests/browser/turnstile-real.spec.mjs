// Turnstile lifecycle against the real Cloudflare API, using Cloudflare's
// published test site keys (they work on any hostname and always pass or
// always fail without a real challenge). The keys are injected into the local
// build's HTML. The capability, contact-intent and send-mail endpoints are
// answered here, and every host other than the local server and
// challenges.cloudflare.com is aborted.
//
// These tests prove the client lifecycle only: a test-key token is not a
// production proof, and nothing here talks to the production site.
import { expect, test } from '@playwright/test';

const HOST = 'kssmi.localhost';
const PORT = 4321;
const ORIGIN = `http://${HOST}:${PORT}`;
const KEYS = {
  invisiblePass: '1x00000000000000000000BB',
  invisibleFail: '2x00000000000000000000BB',
  visiblePass: '1x00000000000000000000AA',
};
const DUMMY_TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';
const COOLDOWN_KEY = 'kssmi_vjt_proof_cooldown_until';

test.use({ baseURL: ORIGIN, launchOptions: { args: [`--host-resolver-rules=MAP ${HOST} 127.0.0.1`] } });
test.setTimeout(90000);

function capabilityToken() {
  const claims = Buffer.from(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 600 })).toString('base64url');
  return `${claims}.local-test`;
}

// proofKey / formKey: test keys to inject. abortFirstApi: fail the first
// api.js request. delay: { api, challenge } milliseconds added to the first
// such request (to hold an attempt in flight).
async function setup(page, testInfo, { proofKey = null, formKey = null, abortFirstApi = false, delay = {} } = {}) {
  const rec = testInfo.rec = { capabilityPosts: [], intents: [], sendMail: 0, apiLoads: 0, turnstileErrors: [], delayed: new Set() };
  page.on('pageerror', (error) => { if (/Turnstile/.test(String(error))) rec.turnstileErrors.push(String(error)); });
  page.on('console', (msg) => { if (msg.type() === 'error' && /TurnstileError|Nothing to reset/.test(msg.text())) rec.turnstileErrors.push(msg.text()); });
  await page.addInitScript(() => {
    window.__proofWidgets = 0;
    new MutationObserver((records) => {
      for (const record of records) {
        for (const node of record.addedNodes) if (node.id === 'kssmi-vjt-capability-turnstile') window.__proofWidgets += 1;
      }
    // Init scripts run before <html> exists, so observe the document itself.
    }).observe(document, { childList: true, subtree: true });
  });
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.host === 'challenges.cloudflare.com') {
      if (url.pathname === '/turnstile/v0/api.js') {
        rec.apiLoads += 1;
        if (abortFirstApi && rec.apiLoads === 1) return route.abort();
        if (delay.api && !rec.delayed.has('api')) { rec.delayed.add('api'); await new Promise((r) => setTimeout(r, delay.api)); }
      } else if (delay.challenge && url.pathname.includes('/cdn-cgi/challenge-platform/') && !rec.delayed.has('challenge')) {
        rec.delayed.add('challenge');
        await new Promise((r) => setTimeout(r, delay.challenge));
      }
      return route.continue().catch(() => {});
    }
    if (url.host !== `${HOST}:${PORT}`) return route.abort();
    if (url.pathname === '/api/vjt-capability.php') {
      const body = request.postDataJSON() || {};
      rec.capabilityPosts.push(body);
      if (body.mode === 'contact' && body.turnstile_token) {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ capabilities: { contact_intent: Array.from({ length: 8 }, capabilityToken) } }) });
      }
      return route.fulfill({ status: 403, contentType: 'application/json', body: '{"error":"denied"}' });
    }
    if (url.pathname === '/api/track-contact-intent.php') {
      try { rec.intents.push(JSON.parse(request.postData() || '{}')); } catch { rec.intents.push({}); }
      return route.fulfill({ status: 204, body: '' });
    }
    if (url.pathname === '/send-mail.php') {
      rec.sendMail += 1;
      return route.fulfill({ status: 422, contentType: 'application/json', body: '{"success":false,"message":"Rejected locally by the test."}' });
    }
    if (url.pathname.startsWith('/api/')) return route.fulfill({ status: 204, body: '' });
    // Pages: direct loads, and ClientRouter's fetch() of the next page (no
    // file extension; resource type "fetch"). Keys go into every page's HTML.
    const isPage = request.resourceType() === 'document'
      || (request.resourceType() === 'fetch' && !/\.[a-z0-9]+$/i.test(url.pathname));
    if (isPage) {
      // Node cannot resolve *.localhost here; fetch the same path from 127.0.0.1.
      const response = await route.fetch({ url: request.url().replace(ORIGIN, `http://127.0.0.1:${PORT}`) });
      if (!(response.headers()['content-type'] || '').includes('text/html')) return route.fulfill({ response });
      let html = await response.text();
      if (proofKey) html = html.replace(/(<script src="\/assets\/runtime\/vjt-capability-proof\.[0-9a-f]{12}\.js") data-sitekey>/, `$1 data-sitekey="${proofKey}">`);
      if (formKey) html = html.replaceAll('data-sitekey data-load-failed', `data-sitekey="${formKey}" data-load-failed`);
      return route.fulfill({ response, body: html });
    }
    return route.continue();
  });
}

const cooldownUntil = (page) => page.evaluate((key) => Number(sessionStorage.getItem(key) || 0), COOLDOWN_KEY);
const proofWidgets = (page) => page.evaluate(() => window.__proofWidgets);

async function softNavigate(page, href) {
  await page.evaluate(() => { window.__sameDocument = true; });
  await page.locator(`a[href="${href}"]:visible`).first().click();
  await page.waitForURL((url) => url.pathname.replace(/\/$/, '') === href.replace(/\/$/, ''));
  await page.waitForLoadState('load');
  expect(await page.evaluate(() => window.__sameDocument), 'same document').toBe(true);
}

// Turnstile's own retry fires about 8 s after a failure; wait past it.
async function expectNoTurnstileErrors(page, testInfo, ms = 12000) {
  await page.waitForTimeout(ms);
  expect(testInfo.rec.turnstileErrors, 'Turnstile errors').toEqual([]);
}

async function clickContact(page) {
  await page.evaluate(() => document.addEventListener('click', (e) => {
    if (e.target.closest('a[data-vjt-contact]')) e.preventDefault();
  }));
  await page.locator('a[data-vjt-contact="mailto"]:visible').first().click();
}

test('no capability without consent: rejected analytics keeps contact navigation silent', async ({ page }, testInfo) => {
  await setup(page, testInfo, { proofKey: KEYS.invisiblePass });
  await page.goto('/');
  await page.locator('#cookie-btn-reject').click();
  await clickContact(page);
  await page.waitForTimeout(1000);
  // No proof widget, no capability request, no contact event: contact
  // navigation stays available but nothing is measured without consent.
  expect(await proofWidgets(page)).toBe(0);
  expect(testInfo.rec.capabilityPosts).toEqual([]);
  expect(testInfo.rec.intents).toEqual([]);
  await expectNoTurnstileErrors(page, testInfo, 2000);
});

test('proof success (official pass key): capability minted, widget removed, contact click authenticated', async ({ page }, testInfo) => {
  await setup(page, testInfo, { proofKey: KEYS.invisiblePass });
  await page.goto('/');
  // Contact measurement follows analytics consent, so grant it first.
  await page.locator('#cookie-btn-accept').click();
  await expect.poll(() => testInfo.rec.capabilityPosts.length, { timeout: 30000 }).toBe(1);
  expect(testInfo.rec.capabilityPosts[0]).toMatchObject({ mode: 'contact', turnstile_token: DUMMY_TOKEN, analytics: true });
  expect(testInfo.rec.capabilityPosts[0].consent_cycle, 'capability bound to the consent cycle').toBeTruthy();
  await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(0);
  expect(await cooldownUntil(page)).toBe(0);
  await clickContact(page);
  await expect.poll(() => testInfo.rec.intents.length).toBe(1);
  expect(testInfo.rec.intents[0].capability_token).toMatch(/\.local-test$/);
  await expectNoTurnstileErrors(page, testInfo);
});

test('proof failure (official fail key): cooldown starts, no Turnstile error, no new widget while cooling down', async ({ page }, testInfo) => {
  await setup(page, testInfo, { proofKey: KEYS.invisibleFail });
  await page.goto('/');
  await page.locator('#cookie-btn-accept').click();
  await expect.poll(() => cooldownUntil(page), { timeout: 30000 }).toBeGreaterThan(Date.now());
  await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(0);
  expect(testInfo.rec.capabilityPosts, 'a failed proof requests no capability').toEqual([]);
  const widgetsAfterFailure = await proofWidgets(page);
  expect(widgetsAfterFailure).toBe(1);
  // While cooling down, a new request returns at once and renders nothing.
  expect(await page.evaluate(() => window.KSSMI_VJT_CAPABILITY_PROOF())).toBe('');
  await softNavigate(page, '/product');
  expect(await proofWidgets(page)).toBe(widgetsAfterFailure);
  // Without a capability, a contact click sends no event.
  await clickContact(page);
  await page.waitForTimeout(500);
  expect(testInfo.rec.intents).toEqual([]);
  await expectNoTurnstileErrors(page, testInfo);
});

test('proof recovery: a failed API load cools down, then the next retry succeeds after the cooldown', async ({ page }, testInfo) => {
  await setup(page, testInfo, { proofKey: KEYS.invisiblePass, abortFirstApi: true });
  await page.goto('/');
  await page.locator('#cookie-btn-accept').click();
  await expect.poll(() => cooldownUntil(page), { timeout: 30000 }).toBeGreaterThan(Date.now());
  expect(testInfo.rec.capabilityPosts).toEqual([]);
  // Simulate the 30-minute cooldown having passed. Contact Core's own retry
  // (15 s after the failure) must then load the API again and succeed.
  await page.evaluate((key) => sessionStorage.setItem(key, String(Date.now() - 1)), COOLDOWN_KEY);
  await expect.poll(() => testInfo.rec.capabilityPosts.length, { timeout: 40000 }).toBe(1);
  expect(testInfo.rec.capabilityPosts[0].turnstile_token).toBe(DUMMY_TOKEN);
  expect(testInfo.rec.apiLoads, 'API requested again after the failed load').toBe(2);
  await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(0);
  await expectNoTurnstileErrors(page, testInfo, 2000);
});

for (const phase of ['api', 'challenge']) {
  test(`proof abort: soft navigation while waiting for the ${phase} ends the attempt without a cooldown`, async ({ page }, testInfo) => {
    await setup(page, testInfo, { proofKey: KEYS.invisiblePass, delay: { [phase]: 6000 } });
    await page.goto('/');
    await page.locator('#cookie-btn-accept').click();
    if (phase === 'challenge') {
      await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(1, { timeout: 20000 });
    } else {
      await expect.poll(() => testInfo.rec.apiLoads, { timeout: 20000 }).toBe(1);
    }
    await softNavigate(page, '/product');
    await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(0);
    expect(await cooldownUntil(page), 'navigation never starts a cooldown').toBe(0);
    // The provider works on the new page.
    expect(await page.evaluate(() => window.KSSMI_VJT_CAPABILITY_PROOF())).toBe(DUMMY_TOKEN);
    await expect(page.locator('#kssmi-vjt-capability-turnstile')).toHaveCount(0);
    await expectNoTurnstileErrors(page, testInfo);
  });
}

test('form (official pass key): token, local rejection resets the widget, soft navigation remounts it', async ({ page }, testInfo) => {
  await setup(page, testInfo, { formKey: KEYS.visiblePass });
  await page.goto('/quote/');
  await page.locator('#cookie-btn-reject').click();
  await page.locator('#inquiry-form').scrollIntoViewIfNeeded();
  await expect.poll(() => page.evaluate(() => window.turnstileToken), { timeout: 30000 }).toBe(DUMMY_TOKEN);
  await page.locator('#name').fill('Turnstile test');
  await page.locator('#email').fill('turnstile-test@example.invalid');
  await page.locator('#details').fill('Real Turnstile test key; answered locally, never sent.');
  await page.locator('#inquiry-privacy-consent').check();
  await page.locator('#inquiry-form button[type="submit"]').click();
  await expect(page.locator('#form-error')).toBeVisible();
  expect(testInfo.rec.sendMail).toBe(1);
  // reset() on the live widget issues a fresh token.
  await expect.poll(() => page.evaluate(() => window.turnstileToken), { timeout: 30000 }).toBe(DUMMY_TOKEN);
  const firstWidget = await page.evaluate(() => window.turnstileWidgetId);
  await softNavigate(page, '/');
  expect(await page.evaluate(() => window.turnstileWidgetId)).toBeUndefined();
  await softNavigate(page, '/quote');
  await page.locator('#inquiry-form').scrollIntoViewIfNeeded();
  await expect.poll(() => page.evaluate(() => window.turnstileToken), { timeout: 30000 }).toBe(DUMMY_TOKEN);
  expect(await page.evaluate(() => window.turnstileWidgetId)).not.toBe(firstWidget);
  await expectNoTurnstileErrors(page, testInfo);
});
