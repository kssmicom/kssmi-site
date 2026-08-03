import { validateSmokeBaseUrl } from './smoke-http.mjs';

export function validateSmokeTargetConfig({ target, configuredBaseUrl, expectedHost }) {
  const normalizedTarget = String(target || 'production').trim().toLowerCase();
  if (!['staging', 'production'].includes(normalizedTarget)) {
    throw new Error('SMOKE_TARGET must be staging or production.');
  }

  const normalizedExpectedHost = String(expectedHost || '').trim().toLowerCase();
  const fallbackUrl = normalizedTarget === 'production' ? 'https://kssmi.com' : '';
  if (!configuredBaseUrl && !fallbackUrl) {
    throw new Error('Staging smoke requires SMOKE_BASE_URL.');
  }
  const baseUrl = validateSmokeBaseUrl(String(configuredBaseUrl || fallbackUrl).trim());

  if (normalizedTarget === 'production') {
    if (baseUrl.hostname !== 'kssmi.com' || (normalizedExpectedHost && normalizedExpectedHost !== 'kssmi.com')) {
      throw new Error('Production smoke is restricted to https://kssmi.com.');
    }
  } else {
    if (!normalizedExpectedHost) {
      throw new Error('Staging smoke requires SMOKE_EXPECTED_HOST.');
    }
    if (normalizedExpectedHost === 'kssmi.com' || baseUrl.hostname !== normalizedExpectedHost) {
      throw new Error('Staging smoke hostname must match its non-production environment host.');
    }
  }

  return { target: normalizedTarget, baseUrl };
}
