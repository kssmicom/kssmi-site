import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

function assertLiteralRequestKeysAreBounded(label, source, superglobal) {
  const assignment = `$_${superglobal} = kssmi_admin_normalize_request($_${superglobal},`;
  const start = source.indexOf(assignment);
  const end = source.indexOf(');', start);
  assert.ok(start >= 0 && end > start, `${label} must declare its ${superglobal} boundary.`);
  const boundary = source.slice(start, end + 2);
  const referencePattern = new RegExp(`\\$_${superglobal}\\[['\"]([^'\"]+)['\"]\\]`, 'g');
  const referencedKeys = new Set([...source.matchAll(referencePattern)].map((match) => match[1]));
  for (const key of referencedKeys) {
    assert.match(
      boundary,
      new RegExp(`['\"]${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}['\"]\\s*=>`),
      `${label} ${superglobal} field ${key} must have an explicit boundary.`
    );
  }
}

const [security, emailAdmin, journeyAdmin, sessionTest, packageJson, phpCi, deploy] = await Promise.all([
  readText('private/http-security.php'),
  readText('public/email-logs.php'),
  readText('public/visitor-journey.php'),
  readText('scripts/test-admin-session.php'),
  readText('package.json'),
  readText('.github/workflows/php-ci.yml'),
  readText('.github/workflows/deploy.yml'),
]);

assert.match(security, /function kssmi_admin_normalize_request\s*\(/, 'Shared bounded request normalizer must exist.');
assert.match(security, /!is_string\(\$input\[\$key\]\)/, 'Array-shaped scalar request values must be rejected.');
assert.match(security, /kssmi_array_is_list\(\$input\[\$key\]\)/, 'Intentional list fields must reject associative shapes.');
assert.match(security, /function kssmi_admin_session_bootstrap\s*\(/, 'Shared admin-session bootstrap must exist.');

const optionsStart = security.indexOf('function kssmi_admin_session_options(');
const optionsEnd = security.indexOf('function kssmi_admin_session_bootstrap(', optionsStart);
const options = security.slice(optionsStart, optionsEnd);
assert.match(options, /'use_strict_mode'\s*=>\s*1/, 'Session strict mode must be enabled.');
assert.match(options, /'use_cookies'\s*=>\s*1/, 'Session cookies must be enabled.');
assert.match(options, /'use_only_cookies'\s*=>\s*1/, 'Session IDs must be cookie-only.');
assert.match(options, /'use_trans_sid'\s*=>\s*0/, 'Transparent URL session IDs must be disabled.');
assert.match(options, /'cookie_secure'\s*=>\s*true/, 'Admin session cookie must be Secure.');
assert.match(options, /'cookie_httponly'\s*=>\s*true/, 'Admin session cookie must be HttpOnly.');
assert.match(options, /'cookie_samesite'\s*=>\s*'Strict'/, 'Admin session cookie must be SameSite=Strict.');

for (const [label, source] of [['Email Logs', emailAdmin], ['Visitor Journey', journeyAdmin]]) {
  const gate = source.indexOf('kssmi_admin_require_trusted_proxy();');
  const normalize = source.indexOf('kssmi_admin_normalize_request(');
  const bootstrap = source.indexOf('kssmi_admin_session_bootstrap();');
  assert.ok(gate >= 0 && normalize > gate && bootstrap > normalize, `${label} must gate, normalize input, then start its session.`);
  assert.doesNotMatch(source, /\bsession_set_cookie_params\s*\(/, `${label} must not carry a private cookie policy.`);
  assert.doesNotMatch(source, /\bsession_start\s*\(/, `${label} must not bypass the shared session bootstrap.`);
  assert.match(source, /\$_GET\s*=\s*kssmi_admin_normalize_request\(\$_GET,/, `${label} must bound GET input.`);
  assert.match(source, /\$_POST\s*=\s*kssmi_admin_normalize_request\(\$_POST,/, `${label} must bound POST input.`);
  assertLiteralRequestKeysAreBounded(label, source, 'GET');
  assertLiteralRequestKeysAreBounded(label, source, 'POST');
}

assert.match(emailAdmin, /'reset'\s*=>\s*128/, 'Reset tokens must have a bounded scalar GET field.');
assert.match(emailAdmin, /'password'\s*=>\s*1024/, 'Email Logs passwords must be bounded.');
assert.match(emailAdmin, /'selected_ids'\s*=>\s*\[500, 256\]/, 'Bulk selection must have item-count and item-length bounds.');
assert.match(journeyAdmin, /'search'\s*=>\s*256/, 'Visitor search input must be bounded.');
assert.match(journeyAdmin, /'delete_ids'\s*=>\s*8192/, 'Destructive ID batches must be bounded scalar fields.');

assert.match(sessionTest, /session_id\(\) !== \$attackerChosenId/, 'Session test must prove fixation resistance.');
assert.match(sessionTest, /session\.use_strict_mode/, 'Session test must inspect strict mode at runtime.');
assert.match(sessionTest, /session\.use_only_cookies/, 'Session test must inspect cookie-only mode at runtime.');

const packageData = JSON.parse(packageJson);
assert.equal(
  packageData.scripts?.['test:admin-session'],
  'php scripts/test-admin-session.php',
  'package.json must expose the admin-session integration test.'
);
assert.match(packageData.scripts?.['test:php'] ?? '', /test:admin-session/, 'The full PHP suite must include the admin-session test.');

for (const [label, workflow] of [['PHP CI', phpCi], ['Deploy', deploy]]) {
  assert.match(workflow, /npm run validate:admin-input-session/, `${label} must enforce the input/session policy validator.`);
}
assert.match(deploy, /php scripts\/test-admin-session\.php/, 'Deploy must execute the admin-session integration test.');

console.log('Admin input-boundary and session-bootstrap policy validated.');
