import https from 'node:https';

const DEFAULT_TIMEOUT_MS = 10_000;
const MAXIMUM_RESPONSE_BYTES = 2_000_000;
const ACCESS_REDIRECT_ORIGIN = 'https://kssmi.cloudflareaccess.com';

export function validateSmokeBaseUrl(value) {
  const baseUrl = value instanceof URL ? new URL(value.href) : new URL(value);
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
  return baseUrl;
}

function validateRedirect(response, requestUrl, baseUrl) {
  const status = response.statusCode ?? 0;
  const location = response.headers?.location;
  if (status < 300 || status >= 400 || !location) return;

  let redirectUrl;
  try {
    redirectUrl = new URL(location, requestUrl);
  } catch {
    throw new Error(`Smoke response returned an invalid redirect location: ${location}`);
  }

  if (redirectUrl.protocol !== 'https:') {
    throw new Error(`Smoke response attempted an insecure redirect: ${redirectUrl.href}`);
  }
  if (
    redirectUrl.origin !== baseUrl.origin
    && redirectUrl.origin !== ACCESS_REDIRECT_ORIGIN
  ) {
    throw new Error(`Smoke response redirect escaped the allowed origins: ${redirectUrl.href}`);
  }
}

export function createSmokeRequester({
  baseUrl: configuredBaseUrl,
  requestImpl = https.request,
  timeoutMs = DEFAULT_TIMEOUT_MS,
} = {}) {
  const baseUrl = validateSmokeBaseUrl(configuredBaseUrl);
  if (!Number.isInteger(timeoutMs) || timeoutMs < 1 || timeoutMs > 30_000) {
    throw new Error('Smoke request timeout must be between 1 and 30000 milliseconds.');
  }

  return function requestOnce(pathname, options = {}) {
    const url = new URL(pathname, baseUrl);
    if (url.origin !== baseUrl.origin) {
      throw new Error(`Smoke request escaped the configured origin: ${url.href}`);
    }

    const method = options.method || 'GET';
    if (method !== 'GET' && !(method === 'POST' && url.pathname === '/email-logs.php')) {
      throw new Error(
        `Smoke only permits GET requests plus bounded Email Logs authentication POSTs: ${method} ${pathname}`
      );
    }
    const body = options.body ?? null;
    if (method === 'GET' && body !== null) {
      throw new Error(`Smoke GET request cannot contain a body: ${url.href}`);
    }
    const headers = { ...(options.headers || {}) };
    if (body !== null) headers['Content-Length'] = Buffer.byteLength(body);

    return new Promise((resolve, reject) => {
      let deadline;
      const request = requestImpl(url, {
        method,
        headers,
        timeout: timeoutMs,
        rejectUnauthorized: true,
        servername: url.hostname,
      }, (response) => {
        try {
          validateRedirect(response, url, baseUrl);
        } catch (error) {
          response.resume?.();
          request.destroy(error);
          return;
        }

        const chunks = [];
        let bodyBytes = 0;
        response.on('data', (chunk) => {
          bodyBytes += chunk.length;
          if (bodyBytes > MAXIMUM_RESPONSE_BYTES) {
            request.destroy(new Error(`Smoke response exceeded 2 MB: ${url.href}`));
            return;
          }
          chunks.push(chunk);
        });
        response.on('error', (error) => request.destroy(error));
        response.on('end', () => {
          clearTimeout(deadline);
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
      request.on('error', (error) => {
        clearTimeout(deadline);
        reject(error);
      });
      deadline = setTimeout(
        () => request.destroy(new Error(`Smoke request timed out: ${url.href}`)),
        timeoutMs
      );
      if (body !== null) request.write(body);
      request.end();
    });
  };
}
