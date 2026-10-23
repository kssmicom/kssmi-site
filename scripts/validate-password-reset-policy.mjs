import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const readText = (relativePath) => readFile(resolve(root, relativePath), 'utf8');

const [
  security,
  emailAdmin,
  visitorJourney,
  shortLinksApi,
  raceTest,
  persistenceTest,
  packageJson,
  phpCi,
  deploy,
] = await Promise.all([
  readText('private/http-security.php'),
  readText('public/email-logs.php'),
  readText('public/visitor-journey.php'),
  readText('public/api/short-links.php'),
  readText('scripts/test-reset-token-race.php'),
  readText('scripts/test-credential-persistence.php'),
  readText('package.json'),
  readText('.github/workflows/php-ci.yml'),
  readText('.github/workflows/deploy.yml'),
]);

const transactionStart = security.indexOf('function kssmi_admin_rotate_credentials(');
assert.ok(transactionStart >= 0, 'Shared security module must define the token-gated password reset operation.');
const transaction = security.slice(transactionStart);
const hashIndex = transaction.indexOf('password_hash(');
const consumeIndex = transaction.indexOf("kssmi_admin_atomic_write($tokensPath, '[]', 0600)");
const writeIndex = transaction.indexOf('kssmi_admin_secret_write(');
assert.ok(hashIndex >= 0, 'Password reset must prepare a password hash.');
assert.ok(consumeIndex >= 0, 'Password reset must atomically revoke its token set.');
assert.ok(hashIndex > consumeIndex, 'Only the token-consumption winner may perform password hashing.');
assert.ok(writeIndex > hashIndex, 'Password file must be written only after consumption and hashing succeed.');
assert.match(
  security,
  /function kssmi_admin_reset_password[\s\S]*?return kssmi_admin_rotate_credentials\(/,
  'Password reset entry point must use the token-gated credential rotation.'
);
assert.match(
  transaction,
  /if\s*\(\$token\s*!==\s*null\s*&&\s*\(!isset\(\$tokens\[\$token\]\)[\s\S]*?changed['"]\s*=>\s*false/,
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

// ── Credential persistence: links, generations, lock order (KS-05) ──
const functionSource = (name) => {
  const start = security.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `Shared security module must define ${name}().`);
  const end = security.indexOf('\n}\n', start);
  assert.ok(end > start, `${name}() must end with a top-level closing brace.`);
  return security.slice(start, end);
};
const storagePath = functionSource('kssmi_admin_storage_path');
assert.ok(
  storagePath.includes('realpath(') && storagePath.includes('is_file('),
  'A credential link must resolve to an existing regular file, or to null.'
);
for (const helper of ['kssmi_admin_atomic_write', 'kssmi_admin_file_lock']) {
  assert.ok(
    functionSource(helper).includes('kssmi_admin_storage_path('),
    `${helper}() must resolve credential links so that writes and locks reach the persistent file.`
  );
}
assert.ok(
  functionSource('kssmi_admin_credential_generation_valid').includes("'/^[a-f0-9]{32}$/D'"),
  'A credential generation must be exactly 32 lowercase hex characters.'
);
const generationNew = functionSource('kssmi_admin_credential_generation_new');
assert.match(
  generationNew,
  /try\s*\{\s*\$generation\s*=\s*bin2hex\(random_bytes\(16\)\);\s*\}\s*catch\s*\(Throwable/,
  'A credential generation must come from 16 random bytes and fail closed without them.'
);
assert.ok(
  generationNew.includes('kssmi_admin_credential_generation_valid($generation)'),
  'A new credential generation must be shape-checked before any write.'
);
assert.ok(
  functionSource('kssmi_admin_reset_tokens_decode').includes(
    "kssmi_admin_credential_generation_valid($data['credential_generation'] ?? null)"
  ),
  'Every stored reset token must carry a valid credential generation.'
);
assert.match(
  transaction,
  /hash_equals\(\$generation,\s*\$tokens\[\$token\]\['credential_generation'\]\)/,
  'A reset token must match the current credential generation.'
);
const rotation = functionSource('kssmi_admin_rotate_credentials');
const generationWrite = rotation.indexOf('kssmi_admin_secret_write($generationPath, $nextGeneration)');
const passwordWrite = rotation.indexOf('kssmi_admin_secret_write($passwordPath, $passwordHash)');
assert.ok(
  rotation.indexOf('kssmi_admin_credential_generation_new()') < generationWrite
    && generationWrite >= 0
    && generationWrite < passwordWrite,
  'Rotation must write a fresh credential generation before the password.'
);
for (const [label, body, lock] of [
  ['Rotation', rotation, 'kssmi_admin_file_lock($tokensPath, LOCK_EX)'],
  ['Login', functionSource('kssmi_admin_authenticate_and_establish'), 'kssmi_admin_file_lock($rotationLockPath, LOCK_EX)'],
]) {
  const distinct = body.indexOf('kssmi_admin_credential_paths_distinct(');
  assert.ok(
    distinct >= 0 && distinct < body.indexOf(lock),
    `${label} must reject credential paths that resolve to one file before it takes any lock.`
  );
}

const listPhp = async (directory) => {
  const entries = await readdir(resolve(root, directory), { withFileTypes: true });
  const nested = await Promise.all(entries.map((entry) => {
    const relativePath = `${directory}/${entry.name}`;
    if (entry.isDirectory()) return entry.name === 'vendor' ? [] : listPhp(relativePath);
    return entry.name.endsWith('.php') ? [relativePath] : [];
  }));
  return nested.flat();
};
const legacyKeyUses = [];
for (const relativePath of [...await listPhp('private'), ...await listPhp('public')]) {
  const uses = (await readText(relativePath)).split('credential_version').length - 1;
  if (uses > 0) legacyKeyUses.push([relativePath, uses]);
}
assert.deepEqual(
  legacyKeyUses,
  [['private/http-security.php', 1]],
  'Only session revocation may still name the pre-generation credential_version key.'
);
assert.ok(
  functionSource('kssmi_admin_session_revoke_local').includes("$_SESSION['credential_version']"),
  'Session revocation must clear the pre-generation credential_version key.'
);
for (const [label, source] of [['Email Logs', emailAdmin], ['Visitor Journey', visitorJourney]]) {
  assert.ok(
    source.includes("define('CREDENTIAL_GENERATION_FILE', kssmi_admin_credential_generation_path(PASSWORD_FILE));"),
    `${label} must bind admin sessions to the credential generation beside the password.`
  );
}
assert.ok(
  shortLinksApi.includes('kssmi_admin_session_authenticated(kssmi_admin_credential_generation_path('),
  'The short-links API must check the same credential generation as the admin pages.'
);

for (const marker of [
  'symlink(',
  "chmod($r1['dir'], 0551)",
  "'credential_paths_not_distinct'",
  "'credential_version' => 1",
  'posix_geteuid()',
  'proc_open(',
]) {
  assert.ok(persistenceTest.includes(marker), `Credential persistence test is missing: ${marker}`);
}
assert.equal(
  packageData.scripts?.['test:credential-persistence'],
  'php scripts/test-credential-persistence.php',
  'package.json must expose the credential persistence test.'
);
assert.match(
  packageData.scripts?.['test:php'] ?? '',
  /npm run test:credential-persistence/,
  'The full PHP suite must include the credential persistence test.'
);
assert.match(
  deploy,
  /php scripts\/test-credential-persistence\.php/,
  'Deploy must run the credential persistence test before release.'
);

console.log('Password reset atomic-consumption and credential persistence policy validated.');
