/**
 * Authenticated-admin portion of the Kssmi deployment smoke.
 *
 * The request function is injected so the exact production state machine can
 * be exercised offline by scripts/test-smoke-deployment.mjs.
 */

function responseHeaderValues(response, headerName) {
  const expected = headerName.toLowerCase();
  const values = [];
  for (let index = 0; index < (response.rawHeaders || []).length; index += 2) {
    if (String(response.rawHeaders[index]).toLowerCase() === expected) {
      values.push(String(response.rawHeaders[index + 1]));
    }
  }
  return values;
}

function setCookieLines(response) {
  if (Array.isArray(response.headers?.['set-cookie'])) return response.headers['set-cookie'];
  return responseHeaderValues(response, 'set-cookie');
}

function cookieName(setCookie) {
  const separator = setCookie.indexOf('=');
  return separator > 0 ? setCookie.slice(0, separator) : '';
}

function cookieValue(setCookie) {
  const pair = setCookie.split(';', 1)[0];
  const separator = pair.indexOf('=');
  return separator > 0 ? pair.slice(separator + 1) : '';
}

function assertStatus(response, expectedStatuses, label) {
  if (!expectedStatuses.includes(response.status)) {
    throw new Error(`${label} returned HTTP ${response.status}; expected ${expectedStatuses.join(', ')}.`);
  }
}

function assertAdminHeaders(response, label) {
  const nosniff = responseHeaderValues(response, 'x-content-type-options').join(', ');
  const frame = responseHeaderValues(response, 'x-frame-options').join(', ');
  const cache = responseHeaderValues(response, 'cache-control');
  if (!/^nosniff$/i.test(nosniff)) throw new Error(`${label} is missing X-Content-Type-Options: nosniff.`);
  if (!/^DENY$/i.test(frame)) throw new Error(`${label} is missing X-Frame-Options: DENY.`);
  if (cache.length !== 1 || !/no-store.*private|private.*no-store/i.test(cache[0])) {
    throw new Error(`${label} must return one no-store, private Cache-Control header.`);
  }
}

function assertHardenedCookie(setCookie, label, { session = false } = {}) {
  if (!/;\s*Path=\//i.test(setCookie)) throw new Error(`${label} cookie must use Path=/.`);
  if (!/;\s*Secure(?:;|$)/i.test(setCookie)) throw new Error(`${label} cookie must be Secure.`);
  if (!/;\s*HttpOnly(?:;|$)/i.test(setCookie)) throw new Error(`${label} cookie must be HttpOnly.`);
  if (!/;\s*SameSite=Strict(?:;|$)/i.test(setCookie)) throw new Error(`${label} cookie must be SameSite=Strict.`);
  if (session && /;\s*(?:Expires|Max-Age)=/i.test(setCookie)) {
    throw new Error(`${label} cookie must have browser-session lifetime.`);
  }
}

function findLogoutCsrf(body) {
  const forms = body.match(/<form\b[\s\S]*?<\/form>/gi) || [];
  const logoutForm = forms.find((form) => /name=["']logout["']/i.test(form));
  if (!logoutForm || !/method=["']post["']/i.test(logoutForm)) {
    throw new Error('Authenticated dashboard must render a POST logout form.');
  }
  const match = logoutForm.match(/name=["']csrf_token["'][^>]*value=["']([a-f0-9]{64})["']/i);
  if (!match) throw new Error('Logout form must contain a 64-hex CSRF token.');
  return match[1];
}

function isDeletionCookie(setCookie) {
  if (/;\s*Max-Age=0(?:;|$)/i.test(setCookie)) return true;
  if (/^(?:deleted)?$/i.test(cookieValue(setCookie))) return true;
  const expires = setCookie.match(/;\s*Expires=([^;]+)/i)?.[1];
  return Boolean(expires && Number.isFinite(Date.parse(expires)) && Date.parse(expires) <= Date.now());
}

export function mergeResponseCookies(cookieHeader, response) {
  const jar = new Map();
  for (const pair of (cookieHeader || '').split(/;\s*/)) {
    const separator = pair.indexOf('=');
    if (separator > 0) jar.set(pair.slice(0, separator), pair.slice(separator + 1));
  }
  for (const setCookie of setCookieLines(response)) {
    const name = cookieName(setCookie);
    if (!name) continue;
    if (isDeletionCookie(setCookie)) jar.delete(name);
    else jar.set(name, cookieValue(setCookie));
  }
  return [...jar].map(([name, value]) => `${name}=${value}`).join('; ');
}

export function requireSmokeCredentials(env) {
  const accessClientId = String(env.SMOKE_ACCESS_CLIENT_ID || '').trim();
  const accessClientSecret = String(env.SMOKE_ACCESS_CLIENT_SECRET || '').trim();
  const adminPassword = env.SMOKE_ADMIN_PASSWORD;
  const missing = [];
  if (!accessClientId) missing.push('SMOKE_ACCESS_CLIENT_ID');
  if (!accessClientSecret) missing.push('SMOKE_ACCESS_CLIENT_SECRET');
  if (typeof adminPassword !== 'string' || adminPassword.length === 0) missing.push('SMOKE_ADMIN_PASSWORD');
  if (missing.length > 0) {
    throw new Error(`Production smoke requires authenticated admin secrets; missing: ${missing.join(', ')}.`);
  }
  if (adminPassword.length > 1024) {
    throw new Error('SMOKE_ADMIN_PASSWORD exceeds the application password input boundary.');
  }
  return { accessClientId, accessClientSecret, adminPassword };
}

export async function runAuthenticatedAdminSmoke({
  request,
  accessClientId,
  accessClientSecret,
  adminPassword,
  log = () => {},
}) {
  if (typeof request !== 'function') throw new Error('Authenticated smoke requires a request function.');

  const accessHeaders = {
    'CF-Access-Client-Id': accessClientId,
    'CF-Access-Client-Secret': accessClientSecret,
  };

  // An intentionally invalid service token must never reach the origin login.
  const invalidAccess = await request('/email-logs.php', {
    headers: {
      'CF-Access-Client-Id': 'invalid-smoke-token.access',
      'CF-Access-Client-Secret': 'invalid-smoke-secret',
    },
  });
  assertStatus(invalidAccess, [302, 403], 'Invalid Cloudflare Access token');

  const loginPage = await request('/email-logs.php', { headers: accessHeaders });
  assertStatus(loginPage, [200], 'Email Logs login page');
  assertAdminHeaders(loginPage, 'Email Logs login page');
  if (!/<input[^>]+type=["']password["'][^>]+name=["']password["']/i.test(loginPage.body)) {
    throw new Error('Email Logs login form was not rendered after the Access gate.');
  }

  const loginPageCookies = setCookieLines(loginPage);
  const sessionCookie = loginPageCookies.find((line) =>
    !/^vjt_admin=/i.test(line) && /;\s*SameSite=Strict(?:;|$)/i.test(line)
  );
  if (!sessionCookie) throw new Error('Email Logs login page did not issue a hardened session cookie.');
  const sessionCookieName = cookieName(sessionCookie);
  assertHardenedCookie(sessionCookie, 'Admin session', { session: true });

  let cookies = mergeResponseCookies('', loginPage);
  const loginResponse = await request('/email-logs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      ...accessHeaders,
      ...(cookies ? { Cookie: cookies } : {}),
    },
    body: new URLSearchParams({ password: adminPassword }).toString(),
  });
  assertStatus(loginResponse, [200], 'Email Logs login');
  assertAdminHeaders(loginResponse, 'Email Logs login');
  if (!/<h1>Email Logs<\/h1>/i.test(loginResponse.body) || !/Accepted Inquiries/i.test(loginResponse.body)) {
    throw new Error('Email Logs authenticated dashboard or storage health marker did not render after login.');
  }
  if (/Email Logs could not be read safely/i.test(loginResponse.body)) {
    throw new Error('Email Logs reported that its data store could not be read safely.');
  }

  const regeneratedSession = setCookieLines(loginResponse)
    .find((line) => cookieName(line) === sessionCookieName);
  if (!regeneratedSession) throw new Error('Successful login did not regenerate the admin session cookie.');
  assertHardenedCookie(regeneratedSession, 'Regenerated admin session', { session: true });

  const markerCookies = setCookieLines(loginResponse)
    .filter((line) => cookieName(line) === 'vjt_admin' && !isDeletionCookie(line));
  if (markerCookies.length === 0) throw new Error('Successful login did not issue the vjt_admin marker.');
  for (const markerCookie of markerCookies) {
    assertHardenedCookie(markerCookie, 'vjt_admin marker');
    if (!/^v1\.[0-9]{10,11}\.[a-f0-9]{32}\.[a-f0-9]{64}$/.test(cookieValue(markerCookie))) {
      throw new Error('vjt_admin marker must be signed and must never use the legacy plaintext value.');
    }
  }
  cookies = mergeResponseCookies(cookies, loginResponse);

  const journey = await request('/visitor-journey.php?tab=contacts', {
    headers: { ...accessHeaders, Cookie: cookies },
  });
  assertStatus(journey, [200], 'Visitor Journey authenticated view');
  assertAdminHeaders(journey, 'Visitor Journey authenticated view');
  if (!/<h1>VJT<\/h1>/i.test(journey.body) || !/Core Events\s*\(/i.test(journey.body)) {
    throw new Error('Visitor Journey dashboard or Core data health marker did not render after login.');
  }

  const csrfToken = findLogoutCsrf(loginResponse.body);

  // GET logout must be inert and preserve authentication.
  const getLogout = await request('/email-logs.php?logout=1', {
    headers: { ...accessHeaders, Cookie: cookies },
  });
  assertStatus(getLogout, [200], 'GET logout attempt');
  if (!/<h1>Email Logs<\/h1>/i.test(getLogout.body)) {
    throw new Error('GET logout unexpectedly ended the authenticated session.');
  }

  for (const [label, submittedToken] of [
    ['missing-CSRF logout', null],
    ['invalid-CSRF logout', '0'.repeat(64)],
  ]) {
    const fields = { logout: '1' };
    if (submittedToken !== null) fields.csrf_token = submittedToken;
    const response = await request('/email-logs.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        ...accessHeaders,
        Cookie: cookies,
      },
      body: new URLSearchParams(fields).toString(),
    });
    assertStatus(response, [403], label);
  }

  const stillAuthenticated = await request('/email-logs.php', {
    headers: { ...accessHeaders, Cookie: cookies },
  });
  assertStatus(stillAuthenticated, [200], 'Session after rejected logout');
  if (!/<h1>Email Logs<\/h1>/i.test(stillAuthenticated.body)) {
    throw new Error('Rejected logout request damaged the authenticated session.');
  }

  const logoutResponse = await request('/email-logs.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      ...accessHeaders,
      Cookie: cookies,
    },
    body: new URLSearchParams({ logout: '1', csrf_token: csrfToken }).toString(),
  });
  assertStatus(logoutResponse, [302], 'Valid logout');
  if (!/(?:^|\/)email-logs\.php(?:$|[?#])/i.test(String(logoutResponse.headers?.location || ''))) {
    throw new Error('Valid logout must redirect to the Email Logs login page.');
  }
  const clearedMarker = setCookieLines(logoutResponse)
    .find((line) => cookieName(line) === 'vjt_admin' && isDeletionCookie(line));
  if (!clearedMarker) throw new Error('Valid logout did not expire the vjt_admin marker.');
  cookies = mergeResponseCookies(cookies, logoutResponse);
  if (/(?:^|;\s*)vjt_admin=/i.test(cookies)) {
    throw new Error('Expired vjt_admin marker remained in the smoke cookie jar.');
  }

  const afterLogout = await request('/email-logs.php', {
    headers: { ...accessHeaders, ...(cookies ? { Cookie: cookies } : {}) },
  });
  assertStatus(afterLogout, [200], 'Email Logs after logout');
  if (!/name=["']password["']/i.test(afterLogout.body) || /<h1>Email Logs<\/h1>/i.test(afterLogout.body)) {
    throw new Error('The server-side admin session remained authenticated after logout.');
  }

  log('OK authenticated admin login, data health, cookie, CSRF and logout checks');
  return { sessionCookieName };
}
