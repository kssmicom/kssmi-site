import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { Readable } from 'node:stream';
import {
  requireAdminSmokePolicy,
  requireSmokeCredentials,
  runAuthenticatedAdminSmoke,
} from './smoke-admin-security.mjs';
import { createSmokeRequester } from './lib/smoke-http.mjs';
import { validateSmokeTargetConfig } from './lib/smoke-target.mjs';

const CSRF = 'c'.repeat(64);
const VALID_ACCESS_ID = 'valid-access-id';
const VALID_ACCESS_SECRET = 'valid-access-secret';
const VALID_PASSWORD = 'correct admin password';
const SESSION_COOKIE = 'PHPSESSID=session-one; Path=/; Secure; HttpOnly; SameSite=Strict';
const REGENERATED_SESSION_COOKIE = 'PHPSESSID=session-two; Path=/; Secure; HttpOnly; SameSite=Strict';
const SIGNED_MARKER = `vjt_admin=v1.19999999999.${'a'.repeat(32)}.${'b'.repeat(64)}; Expires=Tue, 03 Oct 2603 00:00:00 GMT; Path=/; Secure; HttpOnly; SameSite=Strict`;

assert.equal(
  validateSmokeTargetConfig({ target: 'production', configuredBaseUrl: '', expectedHost: '' }).baseUrl.origin,
  'https://kssmi.com',
);
assert.equal(
  validateSmokeTargetConfig({
    target: 'staging',
    configuredBaseUrl: 'https://staging.kssmi.com',
    expectedHost: 'staging.kssmi.com',
  }).baseUrl.origin,
  'https://staging.kssmi.com',
);
for (const invalidTarget of [
  { target: 'staging', configuredBaseUrl: '', expectedHost: 'staging.kssmi.com' },
  { target: 'staging', configuredBaseUrl: 'https://kssmi.com', expectedHost: 'kssmi.com' },
  { target: 'staging', configuredBaseUrl: 'https://evil.example', expectedHost: 'staging.kssmi.com' },
  { target: 'production', configuredBaseUrl: 'https://staging.kssmi.com', expectedHost: 'staging.kssmi.com' },
]) {
  assert.throws(() => validateSmokeTargetConfig(invalidTarget));
}

function response(pathname, status, body, { setCookies = [], location = '' } = {}) {
  const headers = {};
  if (setCookies.length > 0) headers['set-cookie'] = setCookies;
  if (location) headers.location = location;
  const rawHeaders = [
    'X-Content-Type-Options', 'nosniff',
    'X-Frame-Options', 'DENY',
    'Cache-Control', 'no-store, private',
  ];
  for (const setCookie of setCookies) rawHeaders.push('Set-Cookie', setCookie);
  if (location) rawHeaders.push('Location', location);
  return {
    url: new URL(pathname, 'https://kssmi.com'),
    status,
    headers,
    rawHeaders,
    body,
  };
}

function loginBody() {
  return '<form method="POST"><input type="password" name="password"></form>';
}

function dashboardBody() {
  return `<h1>Email Logs</h1><div>Accepted Inquiries</div>
    <form method="post"><input type="hidden" name="csrf_token" value="${CSRF}">
    <button name="logout" value="1">Logout</button></form>`;
}

function makeRequest({
  expectedPassword = VALID_PASSWORD,
  denyValidAccess = false,
  sessionCookie = SESSION_COOKIE,
  markerCookie = SIGNED_MARKER,
  acceptMissingCsrf = false,
  acceptInvalidCsrf = false,
  reflectedCredential = '',
} = {}) {
  let authenticated = false;

  return async (pathname, options = {}) => {
    const method = options.method || 'GET';
    const headers = options.headers || {};
    const accessId = headers['CF-Access-Client-Id'];
    const accessSecret = headers['CF-Access-Client-Secret'];
    if (accessId !== VALID_ACCESS_ID || accessSecret !== VALID_ACCESS_SECRET || denyValidAccess) {
      return response(pathname, 403, `Cloudflare Access denied${reflectedCredential}`);
    }

    const url = new URL(pathname, 'https://kssmi.com');
    if (url.pathname === '/visitor-journey.php') {
      return authenticated
        ? response(pathname, 200, `<h1>VJT</h1><div>Raw Contact/Core Event Stream (0)</div>${reflectedCredential}`)
        : response(pathname, 200, loginBody(), { setCookies: [sessionCookie] });
    }

    if (method === 'POST') {
      const fields = new URLSearchParams(options.body || '');
      if (fields.has('password')) {
        authenticated = fields.get('password') === expectedPassword;
        return authenticated
          ? response(pathname, 200, dashboardBody(), {
            setCookies: [REGENERATED_SESSION_COOKIE, markerCookie],
          })
          : response(pathname, 200, loginBody());
      }
      if (fields.has('logout')) {
        const csrf = fields.get('csrf_token');
        const accepted = csrf === CSRF
          || (csrf === null && acceptMissingCsrf)
          || (csrf !== null && csrf !== CSRF && acceptInvalidCsrf);
        if (!accepted) return response(pathname, 403, 'Security check failed.');
        authenticated = false;
        return response(pathname, 302, '', {
          location: 'email-logs.php',
          setCookies: ['vjt_admin=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Strict'],
        });
      }
    }

    return authenticated
      ? response(pathname, 200, dashboardBody())
      : response(pathname, 200, loginBody(), { setCookies: [sessionCookie] });
  };
}

async function expectReject(callback, pattern, label) {
  await assert.rejects(callback, pattern, label);
}

function fakeHttpsTransport(scenario, observedOptions = []) {
  return (url, options, onResponse) => {
    observedOptions.push({ url, options });
    const request = new EventEmitter();
    request.write = () => {};
    request.destroy = (error) => queueMicrotask(() => request.emit('error', error));
    request.end = () => queueMicrotask(() => {
      if (scenario.error) {
        request.emit('error', scenario.error);
        return;
      }
      if (scenario.timeout) {
        request.emit('timeout');
        return;
      }
      const headers = scenario.location ? { location: scenario.location } : {};
      const responseStream = Readable.from([Buffer.from(scenario.body || '')]);
      responseStream.statusCode = scenario.status ?? 200;
      responseStream.headers = headers;
      responseStream.rawHeaders = scenario.location ? ['Location', scenario.location] : [];
      onResponse(responseStream);
    });
    return request;
  };
}

function transportError(message, code) {
  return Object.assign(new Error(message), { code });
}

const credentials = requireSmokeCredentials({
  SMOKE_ACCESS_CLIENT_ID: VALID_ACCESS_ID,
  SMOKE_ACCESS_CLIENT_SECRET: VALID_ACCESS_SECRET,
  SMOKE_ADMIN_PASSWORD: VALID_PASSWORD,
});

assert.equal(requireAdminSmokePolicy({ SMOKE_REQUIRE_ADMIN: 'true' }), true);
assert.equal(requireAdminSmokePolicy({ SMOKE_REQUIRE_ADMIN: ' FALSE ' }), false);
for (const invalidPolicy of [undefined, '', 'yes', '1']) {
  assert.throws(
    () => requireAdminSmokePolicy({ SMOKE_REQUIRE_ADMIN: invalidPolicy }),
    /explicitly set to true or false/i,
    'admin smoke mode must never be inferred from missing credentials',
  );
}
assert.deepEqual(credentials, {
  accessClientId: VALID_ACCESS_ID,
  accessClientSecret: VALID_ACCESS_SECRET,
  adminPassword: VALID_PASSWORD,
});

for (const missing of ['SMOKE_ACCESS_CLIENT_ID', 'SMOKE_ACCESS_CLIENT_SECRET', 'SMOKE_ADMIN_PASSWORD']) {
  const env = {
    SMOKE_ACCESS_CLIENT_ID: VALID_ACCESS_ID,
    SMOKE_ACCESS_CLIENT_SECRET: VALID_ACCESS_SECRET,
    SMOKE_ADMIN_PASSWORD: VALID_PASSWORD,
  };
  delete env[missing];
  assert.throws(() => requireSmokeCredentials(env), new RegExp(missing), `${missing} must be mandatory`);
}
assert.throws(
  () => requireSmokeCredentials({
    SMOKE_ACCESS_CLIENT_ID: VALID_ACCESS_ID,
    SMOKE_ACCESS_CLIENT_SECRET: VALID_ACCESS_SECRET,
    SMOKE_ADMIN_PASSWORD: 'x'.repeat(1025),
  }),
  /password input boundary/i,
  'oversized smoke password must fail before any request'
);

await runAuthenticatedAdminSmoke({
  request: makeRequest(),
  ...credentials,
});

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest(),
    ...credentials,
    adminPassword: 'wrong password',
  }),
  /dashboard or storage health marker/i,
  'wrong admin password must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest(),
    ...credentials,
    accessClientId: 'wrong-access-id',
    accessClientSecret: 'wrong-access-secret',
  }),
  /login page returned HTTP 403/i,
  'wrong Access credentials must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest({ reflectedCredential: VALID_ACCESS_SECRET }),
    ...credentials,
  }),
  /reflected a smoke credential/i,
  'sensitive credentials reflected by an admin response must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest({ sessionCookie: SESSION_COOKIE.replace('; SameSite=Strict', '') }),
    ...credentials,
  }),
  /hardened session cookie/i,
  'weak session cookie attributes must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest({ markerCookie: SIGNED_MARKER.replace('; HttpOnly', '') }),
    ...credentials,
  }),
  /vjt_admin marker cookie must be HttpOnly/i,
  'weak marker cookie attributes must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest({ acceptMissingCsrf: true }),
    ...credentials,
  }),
  /missing-CSRF logout returned HTTP 302/i,
  'logout without CSRF must fail the smoke'
);

await expectReject(
  () => runAuthenticatedAdminSmoke({
    request: makeRequest({ acceptInvalidCsrf: true }),
    ...credentials,
  }),
  /invalid-CSRF logout returned HTTP 302/i,
  'logout with invalid CSRF must fail the smoke'
);

const tlsOptions = [];
const successfulRequest = createSmokeRequester({
  baseUrl: 'https://kssmi.com',
  requestImpl: fakeHttpsTransport({ status: 200, body: 'ok' }, tlsOptions),
});
assert.equal((await successfulRequest('/')).status, 200);
assert.equal(tlsOptions[0].options.rejectUnauthorized, true, 'smoke must explicitly verify TLS certificates');
assert.equal(tlsOptions[0].options.servername, 'kssmi.com', 'smoke must verify the configured hostname');

await expectReject(
  () => createSmokeRequester({
    baseUrl: 'https://kssmi.com',
    requestImpl: fakeHttpsTransport({
      error: transportError('self-signed certificate', 'DEPTH_ZERO_SELF_SIGNED_CERT'),
    }),
  })('/'),
  /self-signed certificate/i,
  'invalid certificate must fail the smoke'
);

await expectReject(
  () => createSmokeRequester({
    baseUrl: 'https://kssmi.com',
    requestImpl: fakeHttpsTransport({
      error: transportError('hostname does not match certificate', 'ERR_TLS_CERT_ALTNAME_INVALID'),
    }),
  })('/'),
  /hostname does not match/i,
  'hostname mismatch must fail the smoke'
);

await expectReject(
  () => createSmokeRequester({
    baseUrl: 'https://kssmi.com',
    requestImpl: fakeHttpsTransport({ timeout: true }),
    timeoutMs: 25,
  })('/'),
  /timed out/i,
  'request timeout must fail the smoke'
);

await expectReject(
  () => createSmokeRequester({
    baseUrl: 'https://kssmi.com',
    requestImpl: fakeHttpsTransport({ status: 302, location: 'https://example.com/capture' }),
  })('/'),
  /redirect escaped the allowed origins/i,
  'redirect outside the allowed origins must fail the smoke'
);

await expectReject(
  () => createSmokeRequester({
    baseUrl: 'https://kssmi.com',
    requestImpl: fakeHttpsTransport({ status: 302, location: 'http://kssmi.com/insecure' }),
  })('/'),
  /insecure redirect/i,
  'HTTP redirect downgrade must fail the smoke'
);

const accessRedirect = await createSmokeRequester({
  baseUrl: 'https://kssmi.com',
  requestImpl: fakeHttpsTransport({
    status: 302,
    location: 'https://kssmi.cloudflareaccess.com/cdn-cgi/access/login',
  }),
})('/email-logs.php');
assert.equal(accessRedirect.status, 302, 'the expected Cloudflare Access redirect must remain observable');

console.log('Deployment smoke runner self-tests passed.');
