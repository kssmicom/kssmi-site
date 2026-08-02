import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [security, emailAdmin, journeyAdmin, htaccess, release] = await Promise.all([
  readText('private/http-security.php'),
  readText('public/email-logs.php'),
  readText('public/visitor-journey.php'),
  readText('public/.htaccess'),
  readText('scripts/deploy-release.sh'),
]);

assert.match(
  security,
  /function\s+kssmi_admin_request_from_trusted_proxy\s*\(/,
  'Shared security module must define the trusted-proxy predicate.'
);
assert.match(
  security,
  /\$_SERVER\[['"]REMOTE_ADDR['"]\]/,
  'Trusted-proxy predicate must inspect the connection peer REMOTE_ADDR.'
);
assert.match(
  security,
  /kssmi_is_cloudflare_proxy\s*\(\s*\$remoteAddress\s*\)/,
  'Trusted-proxy predicate must validate REMOTE_ADDR against Cloudflare CIDRs.'
);
assert.match(
  security,
  /function\s+kssmi_admin_require_trusted_proxy\s*\(/,
  'Shared security module must define a fail-closed admin gate.'
);
assert.match(
  security,
  /http_response_code\s*\(\s*403\s*\)/,
  'Rejected origin requests must return HTTP 403.'
);

for (const [label, source] of [
  ['Email Logs', emailAdmin],
  ['Visitor Journey', journeyAdmin],
]) {
  const gateIndex = source.indexOf('kssmi_admin_require_trusted_proxy();');
  const sessionIndex = source.indexOf('kssmi_admin_session_bootstrap();');
  assert.ok(gateIndex >= 0, `${label} must invoke the shared trusted-proxy gate.`);
  assert.ok(sessionIndex >= 0, `${label} must invoke the shared hardened admin-session bootstrap.`);
  assert.ok(gateIndex < sessionIndex, `${label} must enforce the origin gate before the session bootstrap.`);
}

const adminOriginBlock = htaccess.match(
  /# ─── Admin access control ─+[\s\S]*?# ─── PHP API endpoints/
)?.[0] ?? '';
assert.doesNotMatch(
  adminOriginBlock,
  /RewriteCond[^\n]*CF-Ray|RewriteRule[^\n]*(?:email-logs|visitor-journey)/i,
  'Admin origin trust must not depend on a forgeable CF-Ray .htaccess rule.'
);
assert.match(
  adminOriginBlock,
  /REMOTE_ADDR belongs to a[\s\S]*Cloudflare proxy range/,
  'Admin access-control documentation must describe the REMOTE_ADDR gate.'
);

assert.match(
  release,
  /HTTP_SECURITY_MODULE="\$SHARED_PRIVATE\/http-security\.php"/,
  'Release manager must name the shared HTTP security module.'
);
assert.match(
  release,
  /run_as_site test -r "\$HTTP_SECURITY_MODULE"/,
  'Release manager must prove the HTTP security module is readable by the site account.'
);

console.log('Admin trusted-origin policy validated.');
