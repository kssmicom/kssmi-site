import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [security, emailAdmin, raceTest, packageJson, phpCi, deploy] = await Promise.all([
  readText('private/http-security.php'),
  readText('public/email-logs.php'),
  readText('scripts/test-reset-token-race.php'),
  readText('package.json'),
  readText('.github/workflows/php-ci.yml'),
  readText('.github/workflows/deploy.yml'),
]);

const transactionStart = security.indexOf('function kssmi_admin_reset_password(');
assert.ok(transactionStart >= 0, 'Shared security module must define the token-gated password reset operation.');
const transaction = security.slice(transactionStart);
const hashIndex = transaction.indexOf('password_hash(');
const consumeIndex = transaction.indexOf('kssmi_admin_reset_token_consume(');
const writeIndex = transaction.indexOf('kssmi_admin_secret_write(');
assert.ok(hashIndex >= 0, 'Password reset must prepare a password hash.');
assert.ok(consumeIndex >= 0, 'Password reset must atomically consume its token.');
assert.ok(hashIndex > consumeIndex, 'Only the token-consumption winner may perform password hashing.');
assert.ok(writeIndex > hashIndex, 'Password file must be written only after consumption and hashing succeed.');
assert.match(
  transaction,
  /if\s*\(\s*!\$consume\[['"]consumed['"]\]\s*\)[\s\S]*?changed['"]\s*=>\s*false/,
  'A request that does not consume the token must not report a password change.'
);

const resetFlow = emailAdmin.slice(
  emailAdmin.indexOf('// Handle password reset with token'),
  emailAdmin.indexOf('// Rate limit failed/attempted admin logins')
);
assert.match(
  resetFlow,
  /kssmi_admin_reset_password\s*\(/,
  'Email Logs reset submission must use the shared token-gated operation.'
);
assert.doesNotMatch(
  resetFlow,
  /\bsetPassword\s*\(/,
  'Email Logs reset submission must not write the password before token consumption.'
);
assert.match(resetFlow, /\$resetResult\[['"]changed['"]\]/, 'Reset success must require changed=true.');
assert.match(resetFlow, /\$resetResult\[['"]consumed['"]\]/, 'Write-failure handling must know whether the token was consumed.');
assert.match(
  resetFlow,
  /if\s*\(\$resetResult\[['"]ok['"]\]\s*&&\s*\$resetResult\[['"]changed['"]\]\)[\s\S]*?\$message\s*=/,
  'Successful reset must render a confirmation on the login view.'
);

assert.match(raceTest, /\$workerCount\s*=\s*8\s*;/, 'Race test must launch multiple workers.');
assert.match(raceTest, /proc_open\s*\(/, 'Race test must execute workers concurrently.');
assert.match(raceTest, /count\s*\(\s*\$winners\s*\)\s*===\s*1/, 'Race test must require exactly one winner.');
assert.match(raceTest, /leaves the winning hash unchanged/, 'Race test must prove replay cannot overwrite the winner.');

const packageData = JSON.parse(packageJson);
assert.equal(
  packageData.scripts?.['test:reset-race'],
  'php scripts/test-reset-token-race.php',
  'package.json must expose the concurrency integration test.'
);
assert.match(packageData.scripts?.['test:php'] ?? '', /test:reset-race/, 'The full PHP suite must include the race test.');

for (const [label, workflow] of [['PHP CI', phpCi], ['Deploy', deploy]]) {
  assert.match(workflow, /npm run validate:password-reset/, `${label} must validate password-reset ordering.`);
}
assert.match(deploy, /php scripts\/test-reset-token-race\.php/, 'Deploy must run the reset concurrency test before release.');

console.log('Password reset atomic-consumption policy validated.');
