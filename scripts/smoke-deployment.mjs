import https from 'node:https';
import {
  requireSmokeCredentials,
  runAuthenticatedAdminSmoke,
} from './smoke-admin-security.mjs';

/**
 * Kssmi production deployment smoke (优化-001 阶段 3 步骤 7).
 *
 * Verifies the deployed site after activate, before finalize:
 *   - public pages, endpoints and sensitive-path blocking;
 *   - security headers (CSP / nosniff / cache policies);
 *   - a mandatory REAL authenticated admin login plus cookie, CSRF, logout,
 *     Access-denial and read-only data-health checks.
 *
 * The admin pages sit behind Cloudflare Access (Zero Trust): an unauthenticated
 * request is 302-redirected to kssmi.cloudflareaccess.com before reaching the
 * origin. To authenticate, the smoke must present CF-Access-Client-Id/Secret
 * service-token headers (SMOKE_ACCESS_CLIENT_ID / SMOKE_ACCESS_CLIENT_SECRET).
 *
 * All three smoke secrets are mandatory in production. Missing credentials
 * are a hard failure so a green deployment can never mean "admin skipped".
 */

if (process.env.NODE_TLS_REJECT_UNAUTHORIZED === '0') {
  throw new Error('Deployment smoke refuses to run with TLS certificate verification disabled.');
}

const target = (process.env.SMOKE_TARGET || 'production').trim().toLowerCase();
if (target !== 'production') {
  throw new Error('Kssmi smoke currently supports SMOKE_TARGET=production only.');
}

const configuredBaseUrl = (process.env.SMOKE_BASE_URL || '').trim();
const baseUrl = new URL(configuredBaseUrl || 'https://kssmi.com');
if (
  baseUrl.protocol !== 'https:'
  || baseUrl.username
  || baseUrl.password
  || baseUrl.pathname !== '/'
  || baseUrl.search
  || baseUrl.hash
) {
  throw new Error('SMOKE_BASE_URL must be an HTTPS origin without credentials, path, query or hash.');
}
if (baseUrl.hostname !== 'kssmi.com') {
  throw new Error('Production smoke is restricted to https://kssmi.com.');
}

const { accessClientId, accessClientSecret, adminPassword } = requireSmokeCredentials(process.env);

const requestHeaders = {
  'Cache-Control': 'no-cache',
  'User-Agent': 'Kssmi-Deployment-Smoke/1.0',
};

// Service-token headers are NEVER part of the global request headers: they
// must only be sent on the authenticated-login flow. The public checks
// deliberately send NO credentials so the 302 (unauthenticated → CF Access
// login) behavior is what gets asserted for the admin pages.

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

function requestOnce(pathname, options = {}) {
  const url = new URL(pathname, baseUrl);
  if (url.origin !== baseUrl.origin) {
    throw new Error(`Smoke request escaped the configured origin: ${url.href}`);
  }

  const method = options.method || 'GET';
  if (method !== 'GET' && !(method === 'POST' && pathname === '/email-logs.php')) {
    throw new Error(`Smoke only permits GET requests plus bounded Email Logs authentication POSTs: ${method} ${pathname}`);
  }
  const body = options.body ?? null;
  if (method === 'GET' && body !== null) {
    throw new Error(`Smoke GET request cannot contain a body: ${url.href}`);
  }
  const headers = {
    ...requestHeaders,
    ...(options.headers || {}),
  };
  if (body !== null) headers['Content-Length'] = Buffer.byteLength(body);

  return new Promise((resolve, reject) => {
    const request = https.request(url, {
      method,
      headers,
      timeout: 10_000,
    }, (response) => {
      const chunks = [];
      let bodyBytes = 0;

      response.on('data', (chunk) => {
        bodyBytes += chunk.length;
        if (bodyBytes > 2_000_000) {
          request.destroy(new Error(`Smoke response exceeded 2 MB: ${url.href}`));
          return;
        }
        chunks.push(chunk);
      });
      response.on('end', () => {
        resolve({
          url,
          status: response.statusCode ?? 0,
          headers: response.headers,
          rawHeaders: response.rawHeaders,
          body: Buffer.concat(chunks).toString('utf8'),
        });
      });
    });

    request.on('timeout', () => request.destroy(new Error(`Smoke request timed out: ${url.href}`)));
    request.on('error', reject);
    if (body !== null) request.write(body);
    request.end();
  });
}

async function requestPath(pathname, options = {}) {
  if ((options.method || 'GET') !== 'GET') {
    throw new Error('Retried smoke requests must be read-only GET requests.');
  }
  let lastError;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    try {
      const response = await requestOnce(pathname, options);
      if (response.status !== 429 && response.status < 500) return response;
      lastError = new Error(`Transient HTTP ${response.status}: ${response.url.href}`);
    } catch (error) {
      lastError = error;
    }
    if (attempt < 3) await sleep(attempt * 1_000);
  }
  throw lastError;
}

function headerValues(response, headerName) {
  const expected = headerName.toLowerCase();
  const values = [];
  for (let index = 0; index < response.rawHeaders.length; index += 2) {
    if (response.rawHeaders[index].toLowerCase() === expected) {
      values.push(response.rawHeaders[index + 1]);
    }
  }
  return values;
}

function assertStatus(response, expectedStatuses) {
  if (!expectedStatuses.includes(response.status)) {
    throw new Error(
      `${response.url.href} returned HTTP ${response.status}; expected ${expectedStatuses.join(', ')}.`
    );
  }
  console.log(`OK HTTP ${response.status} ${response.url.href}`);
}

function assertHeader(response, headerName, pattern) {
  const value = headerValues(response, headerName).join(', ');
  if (!pattern.test(value)) {
    throw new Error(`${response.url.href} has an invalid ${headerName} header: ${value || '(missing)'}`);
  }
  console.log(`OK ${headerName} ${response.url.href}`);
}

function assertSingleCacheControl(response, pattern) {
  const values = headerValues(response, 'cache-control');
  if (values.length !== 1 || !pattern.test(values[0])) {
    throw new Error(
      `${response.url.href} expected one matching Cache-Control header; got: ${values.join(' | ') || '(missing)'}`
    );
  }
  console.log(`OK Cache-Control ${response.url.href} -> ${values[0]}`);
}

console.log(`Running ${target} smoke against ${baseUrl.origin}`);

const failures = [];
const responseCache = new Map();
const getResponse = (pathname) => {
  if (!responseCache.has(pathname)) responseCache.set(pathname, requestPath(pathname));
  return responseCache.get(pathname);
};
async function runCheck(label, callback) {
  try {
    await callback();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    failures.push(`${label}: ${message}`);
    console.error(`FAIL ${label}: ${message}`);
  }
}

// ── Public pages and endpoints ──
for (const pathname of ['/', '/zh/', '/es/']) {
  await runCheck(`page ${pathname}`, async () => {
    const response = await getResponse(pathname);
    assertStatus(response, [200]);
    if (!/<title>[^<]*kssmi/i.test(response.body)) {
      throw new Error(`${response.url.href} does not contain the expected Kssmi page title.`);
    }
  });
}

await runCheck('homepage cache policy', async () => {
  const homepage = await getResponse('/');
  assertSingleCacheControl(homepage, /(?:^|,\s*)s-maxage=600(?:,|$)/i);
});

for (const [pathname, expectedStatuses] of [
  ['/send-mail.php', [405]],
  ['/api/track-pageview.php', [405]],
  ['/api/vjt-config.php', [200]],
  // Admin pages are behind Cloudflare Access: an unauthenticated request is
  // redirected to the Access login, which is the correct public behavior.
  ['/email-logs.php', [302]],
  ['/visitor-journey.php', [302]],
]) {
  await runCheck(`endpoint ${pathname}`, async () => {
    assertStatus(await getResponse(pathname), expectedStatuses);
  });
}

// ── Authenticated admin security flow (mandatory; never skipped) ──
await runCheck('authenticated admin security flow', async () => {
  await runAuthenticatedAdminSmoke({
    request: requestOnce,
    accessClientId,
    accessClientSecret,
    adminPassword,
    log: (message) => console.log(message),
  });
});

// ── Sensitive paths must be blocked ──
for (const pathname of [
  '/composer.json',
  '/composer.lock',
  '/.env',
  '/.git/config',
  '/ip-debug.php',
  '/php-status.php',
  '/vjt-db-setup.php',
  '/api/vjt-helpers.php',
  '/api/vjt_data/vjt.sqlite',
  '/email-logs.json',
]) {
  await runCheck(`sensitive path ${pathname}`, async () => {
    assertStatus(await getResponse(pathname), [403, 404]);
  });
}

await runCheck('send-mail security headers', async () => {
  const sendMail = await getResponse('/send-mail.php');
  assertHeader(sendMail, 'content-security-policy', /default-src 'none'.*frame-ancestors 'none'/i);
  assertSingleCacheControl(sendMail, /no-store/i);
});

await runCheck('tracker endpoint cache policy', async () => {
  const tracker = await getResponse('/api/track-pageview.php');
  assertSingleCacheControl(tracker, /no-store/i);
});

if (failures.length > 0) {
  console.error(`\n${target} smoke failed with ${failures.length} issue(s):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`${target} smoke passed for ${baseUrl.origin}`);
