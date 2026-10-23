import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const policy = read('scripts/permission-policy.sh');
const release = read('scripts/deploy-release.sh');
const tests = read('scripts/test-permission-policy.sh');
const transitionTests = read('scripts/test-deploy-credential-transition.sh');
const prefixFixture = read('scripts/fixtures/prefix-admin-credentials-f9214312.php');
const workflow = read('.github/workflows/deploy.yml');
const environmentAction = read('.github/actions/deploy-environment/action.yml');
const phpCi = read('.github/workflows/php-ci.yml');
const docs = read('docs/permission-policy.md');
const packageJson = JSON.parse(read('package.json'));

const requireBefore = (source, first, second, message) => {
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  assert.ok(firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex, message);
};

// A top-level shell function, from its definition to its closing brace.
const functionBody = (source, name) => {
  const start = source.indexOf(`\n${name}() {`);
  assert.ok(start >= 0, `${name}() is missing.`);
  const end = source.indexOf('\n}\n', start);
  assert.ok(end > start, `${name}() is not terminated.`);
  return source.slice(start + 1, end + 3);
};

for (const marker of [
  'kssmi_policy_assert_path',
  'kssmi_policy_assert_tree',
  'kssmi_policy_reject_world_writable',
  'kssmi_policy_reject_executable_php',
  'kssmi_policy_reject_world_readable_files',
  'kssmi_policy_assert_symlink_allowlist',
  "stat -c '%a'",
  "stat -c '%u'",
  "stat -c '%g'",
  'readlink -f',
  'kssmi_policy_assert_credential_link',
  'kssmi_policy_credential_generation_valid',
  'kssmi_policy_credential_generation_state',
  'kssmi_policy_outgoing_credential_layout',
  'kssmi_policy_generation_action',
  'kssmi_policy_freeze_directory',
  'kssmi_policy_restore_directory_mode',
  "LC_ALL=C grep -Eq '^[a-f0-9]{32}$'",
]) {
  assert.ok(policy.includes(marker), `Runtime permission policy missing: ${marker}`);
}
assert.ok(
  functionBody(policy, 'kssmi_policy_freeze_directory').includes('chmod -- u-w "$directory"'),
  'Freezing a release root must remove only the owner write bit.',
);

for (const marker of [
  'PERMISSION_POLICY="$RELEASE_DIR/scripts/permission-policy.sh"',
  '. "$trusted_bundle/scripts/permission-policy.sh"',
  'verify_release_permission_policy',
  'verify_persistent_permission_policy',
  'kssmi_policy_assert_tree "$NEW_WEBROOT" 755 644',
  'kssmi_policy_assert_tree "$RELEASE_DIR/private" 750 640',
  'kssmi_policy_assert_path "$RELEASE_DIR/scripts/deploy-release.sh" file 750',
  'kssmi_policy_assert_path "$RUNTIME_PROBE_SOURCE" file 640',
  'kssmi_policy_assert_tree "$RATE_LIMIT_DIR" 750 600',
  'kssmi_policy_assert_tree "$VJT_DATA_DIR" 750 600',
  'kssmi_policy_assert_path "$GSC_JSON" file 600',
  'kssmi_policy_assert_sensitive_file "$PRIVATE_CONFIG"',
  'create_release_link "$RELEASE_DIR/private_config.php" "$PRIVATE_CONFIG"',
  'kssmi_policy_assert_symlink_allowlist "$RELEASE_DIR"',
  'prepare_site_directory "$RELEASES_DIR" 751',
  'prepare_site_directory "$STATE_ROOT" 750',
]) {
  assert.ok(release.includes(marker), `Release permission enforcement missing: ${marker}`);
}

assert.doesNotMatch(release, /chmod\s+(?:666|777)\b/, 'Deployment must never grant world write.');
assert.doesNotMatch(release, /-m\s+(?:666|777)\b/, 'Deployment must never install world-writable paths.');

const activationStart = release.indexOf('activate_release() {');
const activationEnd = release.indexOf('\nfinalize_release() {', activationStart);
assert.ok(activationStart >= 0 && activationEnd > activationStart, 'activate_release is missing.');
const activation = release.slice(activationStart, activationEnd);
requireBefore(
  activation,
  'prepare_release_layout',
  'verify_release_permission_policy',
  'The immutable release must be normalized before permission verification.',
);
requireBefore(
  activation,
  'verify_release_permission_policy',
  'validate_cloudflare_snapshot_pair',
  'Release permission verification must finish before deployment mutates shared runtime state.',
);
requireBefore(
  activation,
  'probe_vjt_integrity',
  'verify_persistent_permission_policy',
  'VJT files must be normalized and checked before persistent policy verification.',
);
requireBefore(
  activation,
  'verify_persistent_permission_policy',
  'activate_webroot',
  'Persistent permission verification must pass before the live webroot switches.',
);

// ── KS-05: the credential transition and its rollback ──
// Nothing may change before the rollback target is recorded: only signal
// traps sit between the failure trap and the transition, and the transition
// records, marks, freezes, checks and migrates in exactly this order.
requireBefore(
  activation,
  'trap rollback_on_failure EXIT',
  'prepare_admin_credential_transition',
  'The failure trap must be installed before the credential transition changes anything.',
);
requireBefore(
  activation,
  'prepare_admin_credential_transition',
  'prepare_release_layout',
  'The credential transition must finish before the new release layout is prepared.',
);
assert.match(
  activation.slice(
    activation.indexOf('trap rollback_on_failure EXIT'),
    activation.indexOf('prepare_admin_credential_transition'),
  ),
  /^trap rollback_on_failure EXIT\n(?:\s*trap '[^']*' (?:HUP|INT|TERM)\n)+\s*$/,
  'Only signal traps may run between the failure trap and the credential transition.',
);
assert.ok(
  !activation.includes('record_previous_webroot'),
  'The rollback target is recorded only inside the credential transition.',
);
assert.equal(
  functionBody(release, 'prepare_admin_credential_transition').trimEnd(),
  [
    'prepare_admin_credential_transition() {',
    '  record_previous_webroot',
    '  record_outgoing_release',
    '  state_write rollback_target_recorded 1',
    '  freeze_outgoing_release',
    '  verify_outgoing_admin_credentials',
    '  migrate_admin_credential_generation',
    '}',
  ].join('\n'),
  'The credential transition must record, mark, freeze, check and migrate in this order.',
);
for (const name of ['record_previous_webroot', 'record_outgoing_release']) {
  assert.doesNotMatch(
    functionBody(release, name),
    /\b(?:chmod|chown|mv|ln|rm|install|run_policy_as_site|rotate_admin_credential_generation)\b/,
    `${name}() may only record state; it runs before the rollback target is marked.`,
  );
}

const freeze = functionBody(release, 'freeze_outgoing_release');
requireBefore(freeze, 'kssmi_policy_freeze_directory', 'run_as_site test -w', 'The freeze must be proven as the site account.');
requireBefore(
  freeze,
  'run_as_site test -w',
  'state_write outgoing_release_frozen 1',
  'The outgoing release root is recorded as frozen only after the site account provably cannot write it.',
);
assert.ok(
  functionBody(release, 'verify_outgoing_admin_credentials').includes('kssmi_policy_outgoing_credential_layout'),
  'The outgoing credential layout must be checked by the permission policy.',
);

const migration = functionBody(release, 'migrate_admin_credential_generation');
for (const marker of [
  'kssmi_policy_credential_generation_state "$CREDENTIAL_GENERATION_FILE"',
  'kssmi_policy_generation_action',
  'state_write admin_credential_generation_action kept',
  'state_write admin_credential_generation_action rotated',
]) {
  assert.ok(migration.includes(marker), `Credential generation migration missing: ${marker}`);
}
requireBefore(
  migration,
  'rotate_admin_credential_generation',
  'state_write admin_credential_generation_action rotated',
  'The rotation is recorded only after it happened.',
);

const rotation = functionBody(release, 'rotate_admin_credential_generation');
for (const marker of [
  'run_as_site env',
  'exec 7<"$RESET_TOKENS_LOCK"',
  'exec 6<"$CREDENTIAL_GENERATION_LOCK"',
  'head -c 16 /dev/urandom',
  '*[!0123456789abcdef]*)',
  'printf "[]" > "$tokens_temp"',
  'chmod 600 "$generation_temp"',
  'mv -Tf "$tokens_temp" "$RESET_TOKENS_FILE"',
  'mv -Tf "$generation_temp" "$CREDENTIAL_GENERATION_FILE"',
]) {
  assert.ok(rotation.includes(marker), `Credential generation rotation missing: ${marker}`);
}
for (const [first, second, message] of [
  ['exec 7<"$RESET_TOKENS_LOCK"', 'flock -x 7', 'The reset-token lock file must be opened before it is locked.'],
  ['flock -x 7', 'exec 6<"$CREDENTIAL_GENERATION_LOCK"', 'The reset-token lock must be held before the generation lock, as in PHP.'],
  ['exec 6<"$CREDENTIAL_GENERATION_LOCK"', 'flock -x 6', 'The generation lock file must be opened before it is locked.'],
  ['flock -x 6', 'head -c 16 /dev/urandom', 'Both locks must be held before a new generation exists.'],
  ['"${#generation}" -eq 32', 'printf "[]" > "$tokens_temp"', 'The new generation must be validated before anything is written.'],
  ['mv -Tf "$tokens_temp" "$RESET_TOKENS_FILE"', 'mv -Tf "$generation_temp" "$CREDENTIAL_GENERATION_FILE"', 'Reset tokens must be cleared before the generation changes, as in PHP.'],
]) {
  requireBefore(rotation, first, second, message);
}
assert.doesNotMatch(
  rotation,
  /exec\s+[6-9]\s*>|\b[89][<>]|flock\s+-\w+\s+[89]\b/,
  'The migration must open its lock files read-only and leave FD 8 and FD 9 alone.',
);
assert.deepEqual(
  rotation
    .split('\n')
    .filter((line) => /\$\{?#?generation\}?(?![_A-Za-z])/.test(line))
    .map((line) => line.trim()),
  [
    'case "$generation" in',
    '[ "${#generation}" -eq 32 ] || {',
    'printf "%s" "$generation" > "$generation_temp"',
    '[ "$(cat "$CREDENTIAL_GENERATION_FILE")" = "$generation" ]',
  ],
  'The new credential generation may only be validated and written to its temporary file, never printed.',
);

const storage = functionBody(release, 'prepare_persistent_storage');
assert.ok(
  storage.includes('for credential_lock in "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK"; do'),
  'Deployment must prepare all three credential lock files.',
);
for (const marker of [
  'run_as_site env SITE_GROUP="$SITE_GROUP" sh -c',
  'lock_temp="$(mktemp "$1.deploy-XXXXXXXXXX")"',
  'chmod 600 "$lock_temp"',
  'ln -T "$lock_temp" "$1" 2>/dev/null || [ -e "$1" ] || [ -L "$1" ]',
]) {
  assert.ok(storage.includes(marker), `Credential lock creation missing: ${marker}`);
}
requireBefore(
  storage,
  'if [ ! -e "$credential_lock" ] && [ ! -L "$credential_lock" ]; then',
  'ln -T "$lock_temp" "$1"',
  'A credential lock file is created only while it is missing.',
);
requireBefore(
  storage,
  'chmod 600 "$lock_temp"',
  'ln -T "$lock_temp" "$1"',
  'A new credential lock file must be complete before link(2) publishes it.',
);
assert.doesNotMatch(
  storage,
  /\bln\s+-[A-Za-z]*f|\b(?:install|mv|cp)\b[^\n]*(?:"\$credential_lock"|"\$1")/,
  'A credential lock file is published with link(2), never written over: PHP may have created it meanwhile.',
);
assert.ok(
  storage.includes('kssmi_policy_assert_path "$credential_lock" file 600'),
  'Existing credential lock files must be checked.',
);
assert.ok(
  !storage.includes('CREDENTIAL_GENERATION_FILE'),
  'Persistent storage preparation must never create or write the credential generation.',
);

const layout = functionBody(release, 'prepare_release_layout');
assert.ok(
  layout.includes('create_release_link "$RELEASE_DIR/.admin_credential_generation" "$CREDENTIAL_GENERATION_FILE"'),
  'Every release must link the persistent credential generation.',
);
assert.ok(
  layout.trimEnd().endsWith('run_as_site chmod 551 "$RELEASE_DIR"\n}'),
  'The release root becomes 0551 only after its links and marker exist.',
);
assert.doesNotMatch(release, /chmod 751 "\$RELEASE_DIR"/, 'The release root must not stay writable for the site account.');
const releaseVerification = functionBody(release, 'verify_release_permission_policy');
assert.ok(
  releaseVerification.includes('kssmi_policy_assert_path "$RELEASE_DIR" directory 551'),
  'Release verification must require a 0551 release root.',
);
for (const [link, target] of [
  ['.email_logs_password', 'PASSWORD_FILE'],
  ['.email_reset_tokens.json', 'RESET_TOKENS_FILE'],
  ['.admin_credential_generation', 'CREDENTIAL_GENERATION_FILE'],
]) {
  assert.ok(
    releaseVerification.includes(`"$RELEASE_DIR/${link}" "$${target}" \\`),
    `The release symlink allowlist must contain ${link}.`,
  );
  assert.ok(
    releaseVerification.includes(`kssmi_policy_assert_credential_link "$RELEASE_DIR/${link}" "$${target}"`),
    `The release credential link must name its persistent file exactly: ${link}`,
  );
}

const persistentVerification = functionBody(release, 'verify_persistent_permission_policy');
for (const path of [
  'PASSWORD_FILE',
  'RESET_TOKENS_FILE',
  'CREDENTIAL_GENERATION_FILE',
  'PASSWORD_LOCK',
  'RESET_TOKENS_LOCK',
  'CREDENTIAL_GENERATION_LOCK',
]) {
  assert.ok(
    persistentVerification.includes(`kssmi_policy_assert_path "$${path}" file 600 "$site_uid" "$site_gid"`),
    `The persistent credential state must exist as a 0600 file: ${path}`,
  );
}
assert.ok(
  persistentVerification.includes('kssmi_policy_credential_generation_valid "$CREDENTIAL_GENERATION_FILE"'),
  'The persistent credential generation must be checked for its exact format.',
);

const rollback = functionBody(release, 'rollback_release');
requireBefore(
  rollback,
  'rollback_target_recorded',
  'restore_outgoing_release || return 1',
  'Rollback must look for the recorded target before it restores anything.',
);
requireBefore(
  rollback,
  'restore_outgoing_release || return 1',
  'rollback_webroot',
  'The outgoing release mode must be restored before the webroot pointer.',
);
const unrecordedBranch = rollback.slice(0, rollback.indexOf('restore_outgoing_release || return 1'));
for (const laterState of ['outgoing_release_frozen', 'admin_credential_generation_action', 'private_installed_at', 'activated_at']) {
  assert.ok(
    unrecordedBranch.includes(laterState),
    `Rollback without a recorded target must refuse when ${laterState} exists.`,
  );
}
assert.ok(
  unrecordedBranch.includes('state_write rolled_back_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" || return 1'),
  'A rollback that changed nothing must still check that it recorded its completion.',
);
const restore = functionBody(release, 'restore_outgoing_release');
assert.ok(restore.includes('kssmi_policy_restore_directory_mode'), 'Rollback must restore the recorded outgoing mode.');
assert.doesNotMatch(
  restore,
  /\bfail\b/,
  'restore_outgoing_release() must return its failures: the trap runs rollback where errexit does not apply.',
);
const rollbackPath = [
  'rollback_on_failure',
  'rollback_release',
  'restore_outgoing_release',
  'rollback_webroot',
  'restore_shared_private',
  'write_cutover_markers',
  'clear_cutover_markers',
].map((name) => functionBody(release, name)).join('\n');
for (const credential of [
  'CREDENTIAL_GENERATION_FILE',
  'PASSWORD_FILE',
  'RESET_TOKENS_FILE',
  'CREDENTIAL_GENERATION_LOCK',
  'RESET_TOKENS_LOCK',
  'PASSWORD_LOCK',
  'rotate_admin_credential_generation',
]) {
  assert.ok(!rollbackPath.includes(credential), `Rollback must never write admin credentials: ${credential}`);
}

for (const negativeCase of [
  'world-writable public file',
  'wrong public file mode',
  'executable PHP source',
  'world-readable private file',
  'wrong private file mode',
  'wrong owner contract',
  'unexpected out-of-bound symlink',
  'release root writable by its owner',
  'credential link with a wrong target',
  'credential link target that is not 0600',
  'credential link with a relative target',
  'dangling credential link',
  'credential link to another link',
  'outgoing password differs from the persistent file',
  'outgoing password missing',
  'linked pre-fix version counter',
  'migrated release root with a lost generation',
  'generation as a regular file in the release root',
  'migrated layout with a missing generation',
  'malformed credential generation',
  'restore with a symbolic recorded mode',
  'freeze through a link',
]) {
  assert.ok(tests.includes(negativeCase), `Permission tests missing negative case: ${negativeCase}`);
}

// The orchestration test runs the real transition and trap, and only in a
// temporary directory.
for (const marker of [
  '[ "$(id -u)" != 0 ] || transition_fail',
  'test_root="$(mktemp -d)"',
  'assert_isolated_paths',
  'trap rollback_on_failure EXIT',
  'prepare_admin_credential_transition',
  "scenario='S1 pre-fix layout'",
  "scenario='S1b first bootstrap'",
  "scenario='S2 failure after the freeze'",
  "scenario='S3 failure after the migration'",
  "scenario='S3b generation lock missing at the migration'",
  "scenario='S3c lock order'",
  "scenario='S3d lock file created at the same moment'",
  "scenario='S4 retry after a failed migration'",
  "scenario='S4b retry after keeping the password changed through the pre-fix code'",
  "scenario='S4c retry after keeping the persistent password'",
  "scenario='S5 failure while recording the rollback target'",
  "scenario='S6 rollback after activation'",
  "scenario='S9 migrated layout'",
  "scenario='S10 restore failure'",
  "scenario='S11 later state without a rollback target'",
  "scenario='S0 pre-fix code copy matches f9214312'",
  "scenario='S7 pre-fix password change in flight during the freeze'",
  "scenario='S7b pre-fix password change completed before the freeze'",
  "scenario='S8 pre-fix code started after the freeze'",
  'export KSSMI_PREFIX_PAUSE_FILE="$pause"',
  '"error":"password_write_failed"',
  "sed 's/kssmi_prefix_admin_/kssmi_admin_/g'",
]) {
  assert.ok(transitionTests.includes(marker), `Credential transition tests missing: ${marker}`);
}

// The pre-fix code copy: the source hash is recorded once from git history,
// because CI clones shallowly; the test proves the copy against it.
assert.ok(
  prefixFixture.startsWith('<?php\ndeclare(strict_types=1);\n'),
  'The pre-fix code copy must keep strict types, as its source file does.',
);
assert.ok(
  prefixFixture.includes('// KS05-SOURCE-SHA256: 38c4759e434f1ec9b85d5b4422aa0bb19b69abd45ebc2dcc471681295806c045\n'),
  'The pre-fix code copy must record the hash of f9214312:private/http-security.php lines 211-344 and 570-831.',
);
assert.deepEqual(
  [...prefixFixture.matchAll(/^\/\/ KS05-COPY-BEGIN (\S+)$/gm)].map((match) => match[1]),
  ['f9214312:private/http-security.php:211-344', 'f9214312:private/http-security.php:570-831'],
  'The pre-fix code copy must name its two source fragments.',
);
assert.equal((prefixFixture.match(/^\/\/ KS05-COPY-END$/gm) || []).length, 2, 'Each copied fragment must be closed.');
assert.equal((prefixFixture.match(/KS05-PAUSE-BEGIN/g) || []).length, 1, 'The copy may add exactly one pause block.');
assert.equal((prefixFixture.match(/KS05-PAUSE-END/g) || []).length, 1, 'The pause block must be closed.');
assert.doesNotMatch(
  prefixFixture,
  /\bkssmi_admin_[a-z_]+\s*\(/,
  'Every copied function must be renamed, so the copy never calls the current code.',
);
assert.ok(!environmentAction.includes('fixtures'), 'The pre-fix code copy must never be uploaded with a release.');
const sealStart = workflow.indexOf('tar -cf kssmi-release.tar');
const sealEnd = workflow.indexOf('sha256sum kssmi-release.tar', sealStart);
assert.ok(sealStart >= 0 && sealEnd > sealStart, 'The release seal step is missing.');
assert.ok(
  !workflow.slice(sealStart, sealEnd).includes('fixtures'),
  'The pre-fix code copy must never be sealed into a release.',
);
for (const ciSource of [workflow, phpCi]) {
  assert.ok(
    ciSource.includes("find public private scripts -name '*.php'"),
    'CI must lint every PHP file under scripts, the pre-fix code copy included.',
  );
}

for (const marker of [
  '`0551`',
  '.admin_credential_generation',
  'link(2)',
  'Manual recovery',
  'flock -x 7',
  'flock -x 6',
  'SMOKE_ADMIN_PASSWORD',
  'prefix-admin-credentials-f9214312.php',
  // Both outcomes of a password difference: keeping the changed hash copies it
  // to the persistent file; keeping the persistent one restores the link.
  'cat "$outgoing/.email_logs_password" > "$temp"',
  'ln -s /home/kssmi.com/.email_logs_password .email_logs_password.relink',
  'mv -Tf .email_logs_password.relink .email_logs_password',
]) {
  assert.ok(docs.includes(marker), `Permission documentation missing the KS-05 rule: ${marker}`);
}
assert.deepEqual(
  [...functionBody(transitionTests, 'load_release').matchAll(/^ {2}([a-z_]+)\(\) \{/gm)].map((match) => match[1]),
  ['run_root', 'run_as_site', 'restart_live_php_workers', 'prove_cutover_barriers', 'sleep'],
  'The transition tests may replace only root escalation, the site-account switch, worker restarts, the network proof and sleeps.',
);

assert.ok(docs.includes('0755 / 0644'), 'Permission documentation must state the public contract.');
assert.ok(docs.includes('0750 / 0640'), 'Permission documentation must state the private contract.');
assert.ok(docs.includes('0751'), 'Permission documentation must explain the traverse exception.');
assert.ok(docs.includes('symlink'), 'Permission documentation must define symlink boundaries.');
assert.ok(docs.includes('private_config.php'), 'Permission documentation must protect the persistent application config.');

assert.equal(packageJson.scripts?.['validate:permissions'], 'node scripts/validate-permission-policy.mjs');
assert.equal(packageJson.scripts?.['test:permissions'], 'bash scripts/test-permission-policy.sh');
assert.equal(
  packageJson.scripts?.['test:deploy-credential-transition'],
  'bash scripts/test-deploy-credential-transition.sh',
);
for (const ciSource of [workflow, phpCi]) {
  assert.ok(ciSource.includes('bash -n scripts/permission-policy.sh'));
  assert.ok(ciSource.includes('bash -n scripts/test-permission-policy.sh'));
  assert.ok(ciSource.includes('npm run validate:permissions'));
  assert.ok(ciSource.includes('npm run test:permissions'));
  assert.ok(ciSource.includes('bash -n scripts/test-deploy-credential-transition.sh'));
  assert.ok(ciSource.includes('npm run test:deploy-credential-transition'));
}
assert.ok(
  environmentAction.includes('source: "dist,private,scripts/deploy-release.sh,scripts/permission-policy.sh,scripts/runtime-capability-probe.php,scripts/prune-old-releases.sh"'),
  'The runtime policy must ship in every immutable release.',
);

console.log('Permission and ownership policy-as-code validated.');
