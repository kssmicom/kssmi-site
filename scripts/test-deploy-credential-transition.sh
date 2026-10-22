#!/usr/bin/env bash
#
# KS-05 deployment transition tests. Each scenario runs the real
# deploy-release.sh functions, with the real activation failure trap, against
# a copy of the /home/kssmi.com layout inside one mktemp directory. Only root
# escalation, the site-account switch, PHP worker restarts, the network
# cutover proof and sleeps are replaced. Failures are injected by redefining a
# function in this test, never through a switch in the production script.
# S7, S7b and S8 also run a copy of the pre-fix credential code, which S0
# first proves against its source commit.

set -Eeuo pipefail

case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*)
    echo "Deploy credential transition tests skipped: release links and directory modes need Linux."
    exit 0
    ;;
esac

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
release_script="$repo_root/scripts/deploy-release.sh"
policy_script="$repo_root/scripts/permission-policy.sh"
# The admin credential functions of the pre-fix code (f9214312), to make
# writes that are in flight when the release root is frozen.
prefix_fixture="$repo_root/scripts/fixtures/prefix-admin-credentials-f9214312.php"

transition_fail() {
  echo "Deploy credential transition test failed: $*" >&2
  exit 1
}

# Root ignores the directory modes under test, and no path may ever reach a
# real server layout.
[ "$(id -u)" != 0 ] || transition_fail "run this test as an unprivileged user"
command -v flock >/dev/null 2>&1 || transition_fail "flock (util-linux) is required"
command -v php >/dev/null 2>&1 || transition_fail "php is required to run the pre-fix code copy"

test_root="$(mktemp -d)"
cleanup() {
  chmod -R u+rwX "$test_root" 2>/dev/null || true
  rm -rf -- "$test_root"
}
trap cleanup EXIT
# Canonical, because the transition compares paths with readlink -f output.
test_root="$(cd "$test_root" && pwd -P)"

site_user="$(id -un)"
site_group="$(id -gn)"
site_uid="$(id -u)"
site_gid="$(id -g)"
outgoing_id=1111111111111111111111111111111111111111-1-1
# A reset token in the pre-fix format: an integer version and no generation.
prefix_token_key=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
prefix_token="{\"$prefix_token_key\":{\"created\":1,\"expires\":4102444800,\"credential_version\":1}}"
scenario=''
log=''
last_status=0

scenario_fail() {
  if [ -n "$log" ] && [ -f "$log" ]; then
    sed 's/^/    | /' "$log" >&2
  fi
  transition_fail "$scenario: $*"
}

release_id() {
  printf '%s-%s-1' "$(printf '%040d' 0 | tr 0 "$1")" "$1"
}

random_generation() {
  head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'
}

digest() {
  sha256sum -- "$1" | cut -d' ' -f1
}

# Every path deploy-release.sh derives must stay inside this test's mktemp
# directory before any production function runs.
assert_isolated_paths() {
  local checked=0 variable value
  while IFS= read -r variable; do
    value="${!variable-}"
    case "$value" in
      /*)
        case "$value" in
          "$test_root"/*) checked=$((checked + 1)) ;;
          *) transition_fail "$variable escapes the test directory: $value" ;;
        esac
        ;;
    esac
  done < <(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$release_script" | sort -u)
  [ "$checked" -ge 30 ] || transition_fail "only $checked release paths were checked"
  for variable in PRIVATE_ROOT RELEASES_DIR RELEASE_DIR LIVE_WEBROOT STATE_ROOT STATE_DIR \
      PASSWORD_FILE RESET_TOKENS_FILE CREDENTIAL_GENERATION_FILE \
      PASSWORD_LOCK RESET_TOKENS_LOCK CREDENTIAL_GENERATION_LOCK; do
    case "${!variable}" in
      "$test_root"/*) ;;
      *) transition_fail "$variable escapes the test directory: ${!variable}" ;;
    esac
  done
}

# Loads the production functions for one temporary home and release, then
# replaces only the privileged, network and timing steps.
load_release() {
  export DEPLOY_ENVIRONMENT=production
  export PRIVATE_ROOT="$1"
  export RELEASE_ID="$2"
  export SITE_USER="$site_user"
  export SITE_GROUP="$site_group"
  set --
  # shellcheck source=deploy-release.sh
  . "$release_script"
  # shellcheck source=permission-policy.sh
  . "$policy_script"
  run_root() { "$@"; }
  run_as_site() { "$@"; }
  restart_live_php_workers() { :; }
  prove_cutover_barriers() { return 0; }
  sleep() { :; }
  assert_isolated_paths
}

make_persistent_files() {
  local home="$1"
  mkdir -p "$home/releases" "$home/private/links"
  printf '%s' 'fixture-password-hash' > "$home/.email_logs_password"
  printf '%s' "$prefix_token" > "$home/.email_reset_tokens.json"
  printf '%s\n' '<?php return [];' > "$home/private_config.php"
  chmod 600 "$home/.email_logs_password" "$home/.email_reset_tokens.json" "$home/private_config.php"
}

# A live outgoing release as production has it: public_html links to its
# dist, and its root links the persistent credentials.
make_home() {
  local home="$1"
  local layout="$2"
  local outgoing="$home/releases/$outgoing_id"
  make_persistent_files "$home"
  mkdir -p "$outgoing/dist"
  ln -s "$outgoing/dist" "$home/public_html"
  ln -s "$home/.email_logs_password" "$outgoing/.email_logs_password"
  ln -s "$home/.email_reset_tokens.json" "$outgoing/.email_reset_tokens.json"
  case "$layout" in
    prefix)
      printf '%s' 4 > "$outgoing/.admin_credential_version"
      chmod 751 "$outgoing"
      ;;
    migrated)
      random_generation > "$home/.admin_credential_generation"
      chmod 600 "$home/.admin_credential_generation"
      ln -s "$home/.admin_credential_generation" "$outgoing/.admin_credential_generation"
      chmod 551 "$outgoing"
      ;;
  esac
}

# The opening of activate_release(): persistent storage, a new state
# directory, the real failure trap, the real credential transition, then the
# given next activation step. Callers switch errexit off to collect the
# status; it is on inside, as in production.
run_transition() {
  (
    set -Eeuo pipefail
    trap - EXIT
    prepare_persistent_storage
    [ ! -e "$STATE_DIR" ] || transition_fail "release state already exists: $STATE_DIR"
    run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_DIR"
    trap rollback_on_failure EXIT
    prepare_admin_credential_transition
    "$@"
    trap - EXIT
  )
}

# The workflow's separate `deploy-release.sh rollback` command.
run_rollback() {
  (
    set -Eeuo pipefail
    rollback_release
  )
}

# Runs a step with errexit on, as production does, and collects its status.
# The log is opened with exec inside the subshell: bash undoes a redirection
# placed on a function call before a subshell's EXIT trap runs, which would
# send the rollback output past the log.
run_logged() {
  log="$test_root/$1.log"
  shift
  set +e
  (
    set -Eeuo pipefail
    exec > "$log" 2>&1
    "$@"
  )
  last_status=$?
  set -e
}

expect_success() {
  [ "$last_status" = 0 ] || scenario_fail "expected success, got status $last_status"
}

expect_failure_status() {
  [ "$last_status" != 0 ] || scenario_fail "$1"
}

expect_logged() {
  grep -Fq -- "$1" "$log" || scenario_fail "the output does not mention: $1"
}

assert_state() {
  [ -f "$STATE_DIR/$1" ] || scenario_fail "state $1 is missing"
  [ "$(cat "$STATE_DIR/$1")" = "$2" ] ||
    scenario_fail "state $1 is '$(cat "$STATE_DIR/$1")', expected '$2'"
}

assert_state_exists() {
  [ -f "$STATE_DIR/$1" ] || scenario_fail "state $1 is missing"
}

assert_no_state() {
  [ ! -e "$STATE_DIR/$1" ] || scenario_fail "unexpected state $1"
}

assert_mode() {
  local actual
  actual="$(stat -c '%a' -- "$1")"
  [ "$actual" = "$2" ] || scenario_fail "$1 has mode $actual, expected $2"
}

assert_live_webroot() {
  [ "$(readlink -f -- "$LIVE_WEBROOT")" = "$1" ] ||
    scenario_fail "public_html points to $(readlink -- "$LIVE_WEBROOT"), expected $1"
}

assert_site_file() {
  kssmi_policy_assert_path "$1" file 600 "$site_uid" "$site_gid" 2>/dev/null ||
    scenario_fail "$1 is not a 0600 regular file of the site account"
}

assert_fresh_generation() {
  assert_site_file "$CREDENTIAL_GENERATION_FILE"
  kssmi_policy_credential_generation_valid "$CREDENTIAL_GENERATION_FILE" ||
    scenario_fail "the persistent generation is not 32 lowercase hex characters"
}

assert_tokens_cleared() {
  assert_site_file "$RESET_TOKENS_FILE"
  [ "$(cat -- "$RESET_TOKENS_FILE")" = '[]' ] || scenario_fail "the reset tokens were not cleared"
}

# The generation value is compared, never printed.
assert_generation_not_logged() {
  local generation
  generation="$(cat -- "$CREDENTIAL_GENERATION_FILE")"
  ! grep -Fq -- "$generation" "$log" || scenario_fail "the output contains the credential generation"
  ! grep -rFq -- "$generation" "$STATE_DIR" || scenario_fail "the deployment state contains the credential generation"
}

# Puts a guard in front of a production step: the scenario fails when the
# step runs before the rollback target has been recorded.
guard_after_rollback_target() {
  local step="$1"
  eval "unguarded_$(declare -f "$step")"
  eval "$step() {
    [ -f \"\$STATE_DIR/rollback_target_recorded\" ] ||
      transition_fail '$step ran before the rollback target was recorded'
    unguarded_$step \"\$@\"
  }"
}

# Returns once another process holds the lock file exclusively (10 s at most).
wait_until_locked() {
  local attempt
  for attempt in $(seq 1 100); do
    flock -n -x "$1" true || return 0
    command sleep 0.1
  done
  return 1
}

# Returns once the file exists (10 s at most).
wait_for_file() {
  local attempt
  for attempt in $(seq 1 100); do
    [ -e "$1" ] && return 0
    command sleep 0.1
  done
  return 1
}

# Names, types, link targets and file digests of a release root's entries.
release_root_snapshot() {
  (
    cd "$1"
    find . -mindepth 1 -maxdepth 1 ! -type f -printf '%f %y %l\n'
    find . -mindepth 1 -maxdepth 1 -type f -exec sha256sum {} +
  ) | LC_ALL=C sort
}

wait_for_background() {
  local pid="$1" attempt
  for attempt in $(seq 1 100); do
    kill -0 "$pid" 2>/dev/null || break
    command sleep 0.1
  done
  if kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    last_status=124
    return 0
  fi
  set +e
  wait "$pid"
  last_status=$?
  set -e
}

scenario='S0 pre-fix code copy matches f9214312'
recorded_source_sha="$(sed -n 's#^// KS05-SOURCE-SHA256: \([0-9a-f]\{64\}\)$#\1#p' "$prefix_fixture")"
[ -n "$recorded_source_sha" ] || scenario_fail "the copy does not record its source hash"
[ "$(grep -c 'KS05-PAUSE-BEGIN' "$prefix_fixture")" = 1 ] &&
  [ "$(grep -c 'KS05-PAUSE-END' "$prefix_fixture")" = 1 ] ||
  scenario_fail "the copy must contain exactly one pause block"
# With the rename reverted and the pause block removed, the copied blocks must
# be the original lines byte for byte. CI clones shallowly, so the reference is
# the hash recorded from git history, not one computed here.
copied_source_sha="$(
  sed -n '/^\/\/ KS05-COPY-BEGIN /,/^\/\/ KS05-COPY-END$/p' "$prefix_fixture" |
    sed '/^\/\/ KS05-COPY-/d' |
    sed '/KS05-PAUSE-BEGIN/,/KS05-PAUSE-END/d' |
    sed 's/kssmi_prefix_admin_/kssmi_admin_/g' |
    sha256sum | cut -d' ' -f1
)"
[ "$copied_source_sha" = "$recorded_source_sha" ] ||
  scenario_fail "the copied pre-fix code differs from f9214312 beyond the rename and the pause block"
echo "ok: $scenario"

scenario='S1 pre-fix layout'
home="$test_root/s1/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
password_digest="$(digest "$PASSWORD_FILE")"
guard_after_rollback_target freeze_outgoing_release
guard_after_rollback_target verify_outgoing_admin_credentials
guard_after_rollback_target migrate_admin_credential_generation
run_logged s1 run_transition
expect_success
assert_state previous_kind symlink
assert_state previous_target "$outgoing/dist"
assert_state outgoing_release_dir "$outgoing"
assert_state outgoing_release_mode 751
assert_state rollback_target_recorded 1
assert_state outgoing_release_frozen 1
assert_state admin_credential_layout prefix
assert_state admin_credential_generation_action rotated
assert_no_state rolled_back_at
assert_mode "$outgoing" 551
[ ! -w "$outgoing" ] || scenario_fail "the site account can still write the outgoing release root"
assert_live_webroot "$outgoing/dist"
assert_fresh_generation
assert_tokens_cleared
[ "$(digest "$PASSWORD_FILE")" = "$password_digest" ] || scenario_fail "the password hash changed"
for credential_lock in "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK"; do
  assert_site_file "$credential_lock"
done
leftover="$(find "$PRIVATE_ROOT" -maxdepth 1 -name '*.deploy-*' -print -quit)"
[ -z "$leftover" ] || scenario_fail "a temporary file was left behind: $leftover"
assert_generation_not_logged
echo "ok: $scenario"

scenario='S1b first bootstrap'
home="$test_root/s1b/kssmi.com"
make_persistent_files "$home"
mkdir "$home/public_html"
load_release "$home" "$(release_id 2)"
run_logged s1b run_transition false
expect_failure_status "the injected failure did not fail the activation"
assert_state previous_kind directory
assert_no_state outgoing_release_dir
assert_no_state outgoing_release_frozen
assert_state admin_credential_layout initial
assert_state admin_credential_generation_action rotated
assert_state_exists rolled_back_at
[ -d "$LIVE_WEBROOT" ] && [ ! -L "$LIVE_WEBROOT" ] || scenario_fail "public_html is no longer the original directory"
assert_fresh_generation
assert_tokens_cleared
echo "ok: $scenario"

scenario='S2 failure after the freeze'
home="$test_root/s2/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
# The pre-fix code replaced the password link with a changed hash.
chmod u+w "$outgoing"
rm -- "$outgoing/.email_logs_password"
printf '%s' 'fixture-changed-password-hash' > "$outgoing/.email_logs_password"
chmod 600 "$outgoing/.email_logs_password"
chmod 751 "$outgoing"
changed_digest="$(digest "$outgoing/.email_logs_password")"
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
run_logged s2 run_transition
expect_failure_status "a password copy that differs from the persistent file was accepted"
expect_logged 'does not match'
assert_state outgoing_release_frozen 1
assert_no_state admin_credential_layout
assert_no_state admin_credential_generation_action
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
assert_live_webroot "$outgoing/dist"
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] || scenario_fail "a generation was written"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the reset tokens changed"
[ -f "$outgoing/.email_logs_password" ] && [ ! -L "$outgoing/.email_logs_password" ] &&
  [ "$(digest "$outgoing/.email_logs_password")" = "$changed_digest" ] ||
  scenario_fail "the changed password was not left in place for manual recovery"
s2_home="$home"
echo "ok: $scenario"

scenario='S3 failure after the migration'
home="$test_root/s3/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
# A generation left behind by an earlier failed attempt.
random_generation > "$CREDENTIAL_GENERATION_FILE"
chmod 600 "$CREDENTIAL_GENERATION_FILE"
earlier_digest="$(digest "$CREDENTIAL_GENERATION_FILE")"
eval "unwrapped_$(declare -f migrate_admin_credential_generation)"
migrate_admin_credential_generation() {
  unwrapped_migrate_admin_credential_generation
  digest "$CREDENTIAL_GENERATION_FILE" > "$test_root/s3-migrated.digest"
}
run_logged s3 run_transition false
expect_failure_status "the injected failure did not fail the activation"
assert_state admin_credential_generation_action rotated
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
assert_live_webroot "$outgoing/dist"
assert_fresh_generation
assert_tokens_cleared
s3_generation_digest="$(digest "$CREDENTIAL_GENERATION_FILE")"
[ "$s3_generation_digest" = "$(cat "$test_root/s3-migrated.digest")" ] ||
  scenario_fail "the rollback changed the migrated generation"
[ "$s3_generation_digest" != "$earlier_digest" ] || scenario_fail "the earlier generation was kept"
s3_home="$home"
echo "ok: $scenario"

scenario='S3b generation lock missing at the migration'
home="$test_root/s3b/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
eval "unwrapped_$(declare -f migrate_admin_credential_generation)"
migrate_admin_credential_generation() {
  rm -f -- "$CREDENTIAL_GENERATION_LOCK"
  unwrapped_migrate_admin_credential_generation
}
run_logged s3b run_transition
expect_failure_status "the migration ran without its lock file"
[ ! -e "$CREDENTIAL_GENERATION_LOCK" ] || scenario_fail "the migration created a lock file"
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] || scenario_fail "a generation was written without its lock"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the reset tokens changed without both locks"
assert_no_state admin_credential_generation_action
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
echo "ok: $scenario"

scenario='S3c lock order'
home="$test_root/s3c/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
prepare_persistent_storage > "$test_root/s3c-storage.log" 2>&1
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
# While this test holds the generation lock, the transition must wait while
# holding the reset-token lock, and must not have written anything yet.
exec {held_lock}<"$CREDENTIAL_GENERATION_LOCK"
flock -x "$held_lock"
log="$test_root/s3c.log"
(
  exec > "$log" 2>&1
  run_transition
) &
transition_pid=$!
if ! wait_until_locked "$RESET_TOKENS_LOCK"; then
  flock -u "$held_lock"
  scenario_fail "the transition did not take the reset-token lock first"
fi
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] || scenario_fail "the generation was written while its lock was held"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] ||
  scenario_fail "the reset tokens were cleared before both locks were held"
flock -u "$held_lock"
exec {held_lock}<&-
wait_for_background "$transition_pid"
expect_success
assert_state admin_credential_generation_action rotated
assert_fresh_generation
assert_tokens_cleared
echo "ok: $scenario"

scenario='S3d lock file created at the same moment'
home="$test_root/s3d/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
# PHP creates the reset-token lock file after the deployment found it missing
# and just before the deployment creates it. The race is placed in front of
# whichever command the deployment uses to create that file.
eval "unraced_$(declare -f run_root)"
eval "unraced_$(declare -f run_as_site)"
race_reset_token_lock() {
  if [ "${!#}" = "$RESET_TOKENS_LOCK" ] && [ ! -e "$RESET_TOKENS_LOCK" ]; then
    # As kssmi_admin_file_lock() does: fopen() with c+b, then chmod 0600.
    : > "$RESET_TOKENS_LOCK"
    chmod 600 "$RESET_TOKENS_LOCK"
    stat -c '%i' -- "$RESET_TOKENS_LOCK" > "$test_root/s3d-php.inode"
  fi
}
run_root() {
  race_reset_token_lock "$@"
  unraced_run_root "$@"
}
run_as_site() {
  race_reset_token_lock "$@"
  unraced_run_as_site "$@"
}
run_logged s3d prepare_persistent_storage
expect_success
[ -s "$test_root/s3d-php.inode" ] || scenario_fail "the concurrent lock file was never created"
[ "$(stat -c '%i' -- "$RESET_TOKENS_LOCK")" = "$(cat "$test_root/s3d-php.inode")" ] ||
  scenario_fail "the deployment replaced the lock file that PHP had just created"
for credential_lock in "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK"; do
  assert_site_file "$credential_lock"
done
leftover="$(find "$PRIVATE_ROOT" -maxdepth 1 -name '*.deploy-*' -print -quit)"
[ -z "$leftover" ] || scenario_fail "a temporary file was left behind: $leftover"
echo "ok: $scenario"

scenario='S4 retry after a failed migration'
load_release "$s3_home" "$(release_id 4)"
outgoing="$RELEASES_DIR/$outgoing_id"
lock_inodes="$(stat -c '%i' -- "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK")"
run_logged s4 run_transition
expect_success
# The earlier rollback restored the root; this run froze it afresh.
assert_state outgoing_release_mode 751
assert_mode "$outgoing" 551
assert_state admin_credential_layout prefix
assert_state admin_credential_generation_action rotated
assert_fresh_generation
[ "$(digest "$CREDENTIAL_GENERATION_FILE")" != "$s3_generation_digest" ] ||
  scenario_fail "the retry kept the generation of the failed attempt"
[ "$(stat -c '%i' -- "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK")" = "$lock_inodes" ] ||
  scenario_fail "an existing lock file was replaced"
echo "ok: $scenario"

scenario='S4b retry after keeping the password changed through the pre-fix code'
load_release "$s2_home" "$(release_id 4)"
outgoing="$RELEASES_DIR/$outgoing_id"
# Manual recovery as documented: the changed password stays and is copied to
# the persistent file.
recovery_temp="$(umask 077 && mktemp "$PASSWORD_FILE.manual-XXXXXXXXXX")"
cat "$outgoing/.email_logs_password" > "$recovery_temp"
mv -Tf "$recovery_temp" "$PASSWORD_FILE"
run_logged s4b run_transition
expect_success
expect_logged 'identical to'
assert_state admin_credential_layout prefix
assert_state admin_credential_generation_action rotated
assert_mode "$outgoing" 551
[ "$(digest "$PASSWORD_FILE")" = "$(digest "$outgoing/.email_logs_password")" ] ||
  scenario_fail "the persistent password is not the one that was kept"
assert_fresh_generation
assert_tokens_cleared
echo "ok: $scenario"

scenario='S4c retry after keeping the persistent password'
home="$test_root/s4c/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
password_digest="$(digest "$PASSWORD_FILE")"
# The pre-fix code replaced the password link with a changed hash, and the
# first deployment stopped at the check.
rm -- "$outgoing/.email_logs_password"
printf '%s' 'fixture-changed-password-hash' > "$outgoing/.email_logs_password"
chmod 600 "$outgoing/.email_logs_password"
run_logged s4c-stopped run_transition
expect_failure_status "a password copy that differs from the persistent file was accepted"
assert_mode "$outgoing" 751
# Manual recovery as documented: the persistent password stays, and the exact
# link replaces the outgoing copy.
[ -f "$outgoing/.email_logs_password" ] && [ ! -L "$outgoing/.email_logs_password" ] ||
  scenario_fail "the stopped deployment did not leave the outgoing copy in place"
ln -s "$PASSWORD_FILE" "$outgoing/.email_logs_password.relink"
mv -Tf "$outgoing/.email_logs_password.relink" "$outgoing/.email_logs_password"
load_release "$home" "$(release_id 4)"
run_logged s4c run_transition
expect_success
assert_state admin_credential_layout prefix
assert_state admin_credential_generation_action rotated
assert_mode "$outgoing" 551
[ "$(readlink -- "$outgoing/.email_logs_password")" = "$PASSWORD_FILE" ] ||
  scenario_fail "the outgoing password path is not the exact link"
[ "$(digest "$PASSWORD_FILE")" = "$password_digest" ] || scenario_fail "the persistent password hash changed"
assert_fresh_generation
assert_tokens_cleared
echo "ok: $scenario"

scenario='S5 failure while recording the rollback target'
home="$test_root/s5/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
record_outgoing_release() { return 1; }
run_logged s5 run_transition
expect_failure_status "the injected recording failure did not fail the activation"
expect_logged 'nothing had changed before the rollback target was recorded'
assert_state previous_kind symlink
assert_no_state rollback_target_recorded
assert_no_state outgoing_release_frozen
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
assert_live_webroot "$outgoing/dist"
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] || scenario_fail "a generation was written"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the reset tokens changed"
echo "ok: $scenario"

scenario='S6 rollback after activation'
home="$test_root/s6/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
mkdir -p "$NEW_WEBROOT"
run_logged s6 run_transition activate_webroot
expect_success
assert_live_webroot "$NEW_WEBROOT"
assert_state_exists activated_at
generation_digest="$(digest "$CREDENTIAL_GENERATION_FILE")"
password_digest="$(digest "$PASSWORD_FILE")"
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
run_logged s6-rollback run_rollback
expect_success
assert_live_webroot "$outgoing/dist"
assert_mode "$outgoing" 751
assert_state_exists rolled_back_at
[ ! -e "$EMAIL_CUTOVER_MARKER" ] || scenario_fail "the cutover marker was left behind"
[ "$(digest "$CREDENTIAL_GENERATION_FILE")" = "$generation_digest" ] || scenario_fail "the rollback changed the generation"
[ "$(digest "$PASSWORD_FILE")" = "$password_digest" ] || scenario_fail "the rollback changed the password hash"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the rollback changed the reset tokens"
run_logged s6-again run_rollback
expect_success
expect_logged 'Release was already rolled back'
echo "ok: $scenario"

scenario='S7 pre-fix password change in flight during the freeze'
home="$test_root/s7/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
password_digest="$(digest "$PASSWORD_FILE")"
# The pre-fix code writes the new hash to a temporary file in the release
# root and pauses before rename(); the transition freezes the root meanwhile.
pause="$test_root/s7.pause"
printf '%s' .email_logs_password > "$pause"
(
  export KSSMI_PREFIX_PAUSE_FILE="$pause"
  exec php "$prefix_fixture" change "$outgoing" fixture-new-password-1 > "$test_root/s7.json" 2>&1
) &
prefix_pid=$!
wait_for_file "$pause.paused" || scenario_fail "the pre-fix password change never reached its pause point"
[ -f "$(cat "$pause.paused")" ] || scenario_fail "the paused write has no temporary file"
[ -L "$outgoing/.email_logs_password" ] || scenario_fail "the password link was replaced before the freeze"
run_logged s7 run_transition
expect_success
assert_mode "$outgoing" 551
assert_state admin_credential_layout prefix
assert_state admin_credential_generation_action rotated
touch "$pause.continue"
wait_for_background "$prefix_pid"
[ "$last_status" = 0 ] || scenario_fail "the pre-fix code exited with status $last_status"
grep -Fq '"ok":false,"changed":false' "$test_root/s7.json" &&
  grep -Fq '"error":"password_write_failed"' "$test_root/s7.json" ||
  scenario_fail "the in-flight pre-fix change did not fail closed: $(cat "$test_root/s7.json")"
[ -L "$outgoing/.email_logs_password" ] &&
  [ "$(readlink -- "$outgoing/.email_logs_password")" = "$PASSWORD_FILE" ] ||
  scenario_fail "the outgoing password path is no longer the link to the persistent file"
[ "$(digest "$PASSWORD_FILE")" = "$password_digest" ] || scenario_fail "the persistent password hash changed"
echo "ok: $scenario"

scenario='S7b pre-fix password change completed before the freeze'
home="$test_root/s7b/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
password_digest="$(digest "$PASSWORD_FILE")"
pause="$test_root/s7b.pause"
printf '%s' .email_logs_password > "$pause"
(
  export KSSMI_PREFIX_PAUSE_FILE="$pause"
  exec php "$prefix_fixture" change "$outgoing" fixture-new-password-2 > "$test_root/s7b.json" 2>&1
) &
prefix_pid=$!
wait_for_file "$pause.paused" || scenario_fail "the pre-fix password change never reached its pause point"
# Released before the freeze, the pre-fix change completes and replaces the
# link in the release root with the new hash.
touch "$pause.continue"
wait_for_background "$prefix_pid"
[ "$last_status" = 0 ] || scenario_fail "the pre-fix code exited with status $last_status"
grep -Fq '"ok":true,"changed":true' "$test_root/s7b.json" ||
  scenario_fail "the pre-fix change did not complete: $(cat "$test_root/s7b.json")"
[ -f "$outgoing/.email_logs_password" ] && [ ! -L "$outgoing/.email_logs_password" ] ||
  scenario_fail "the pre-fix change did not replace the link"
changed_digest="$(digest "$outgoing/.email_logs_password")"
run_logged s7b run_transition
expect_failure_status "a password changed through the pre-fix code was not detected"
expect_logged 'does not match'
assert_no_state admin_credential_generation_action
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
[ "$(digest "$outgoing/.email_logs_password")" = "$changed_digest" ] ||
  scenario_fail "the changed password was not left in place for manual recovery"
[ "$(digest "$PASSWORD_FILE")" = "$password_digest" ] || scenario_fail "the persistent password hash changed"
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] || scenario_fail "a generation was written"
echo "ok: $scenario"

scenario='S8 pre-fix code started after the freeze'
home="$test_root/s8/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
run_logged s8 run_transition
expect_success
assert_mode "$outgoing" 551
credentials_before="$(digest "$PASSWORD_FILE") $(digest "$RESET_TOKENS_FILE") $(digest "$CREDENTIAL_GENERATION_FILE")"
outgoing_before="$(release_root_snapshot "$outgoing")"
php "$prefix_fixture" change "$outgoing" fixture-new-password-3 > "$test_root/s8-change.json" 2>&1 ||
  scenario_fail "the pre-fix change exited abnormally: $(cat "$test_root/s8-change.json")"
php "$prefix_fixture" reset "$outgoing" fixture-new-password-4 "$prefix_token_key" > "$test_root/s8-reset.json" 2>&1 ||
  scenario_fail "the pre-fix reset exited abnormally: $(cat "$test_root/s8-reset.json")"
for prefix_result in "$test_root/s8-change.json" "$test_root/s8-reset.json"; do
  grep -Fq '"ok":false,"changed":false' "$prefix_result" ||
    scenario_fail "the pre-fix code reported success after the freeze: $(cat "$prefix_result")"
done
[ "$(digest "$PASSWORD_FILE") $(digest "$RESET_TOKENS_FILE") $(digest "$CREDENTIAL_GENERATION_FILE")" = "$credentials_before" ] ||
  scenario_fail "a persistent credential changed"
[ "$(release_root_snapshot "$outgoing")" = "$outgoing_before" ] ||
  scenario_fail "the frozen release root changed"
echo "ok: $scenario"

scenario='S9 migrated layout'
home="$test_root/s9/kssmi.com"
make_home "$home" migrated
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
generation_digest="$(digest "$CREDENTIAL_GENERATION_FILE")"
tokens_digest="$(digest "$RESET_TOKENS_FILE")"
run_logged s9 run_transition
expect_success
assert_state admin_credential_layout migrated
assert_state admin_credential_generation_action kept
assert_state outgoing_release_mode 551
assert_mode "$outgoing" 551
[ "$(digest "$CREDENTIAL_GENERATION_FILE")" = "$generation_digest" ] || scenario_fail "a valid generation was replaced"
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the reset tokens of a migrated layout changed"
# A lost persistent generation stops the next deployment and is never
# recreated by it.
rm -- "$CREDENTIAL_GENERATION_FILE"
load_release "$home" "$(release_id 3)"
run_logged s9-lost run_transition
expect_failure_status "a lost generation was accepted"
[ ! -e "$CREDENTIAL_GENERATION_FILE" ] && [ ! -L "$CREDENTIAL_GENERATION_FILE" ] ||
  scenario_fail "the deployment recreated the lost generation"
assert_no_state admin_credential_generation_action
assert_state_exists rolled_back_at
assert_mode "$outgoing" 551
[ "$(digest "$RESET_TOKENS_FILE")" = "$tokens_digest" ] || scenario_fail "the reset tokens changed"
echo "ok: $scenario"

scenario='S10 restore failure'
home="$test_root/s10/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
kssmi_policy_restore_directory_mode() {
  echo 'PERMISSION POLICY: injected restore failure' >&2
  return 1
}
run_logged s10 run_transition false
expect_failure_status "the injected failure did not fail the activation"
expect_logged 'Could not restore mode 751'
assert_no_state rolled_back_at
assert_mode "$outgoing" 551
# The workflow's separate rollback command meets the same fault and still
# does not report success.
run_logged s10-retry run_rollback
expect_failure_status "rollback reported success although the mode was not restored"
assert_no_state rolled_back_at
assert_mode "$outgoing" 551
# With the fault gone, the same command restores the mode and completes.
# shellcheck source=permission-policy.sh
. "$policy_script"
run_logged s10-fixed run_rollback
expect_success
assert_mode "$outgoing" 751
assert_state_exists rolled_back_at
assert_live_webroot "$outgoing/dist"
echo "ok: $scenario"

scenario='S11 later state without a rollback target'
home="$test_root/s11/kssmi.com"
make_home "$home" prefix
load_release "$home" "$(release_id 2)"
outgoing="$RELEASES_DIR/$outgoing_id"
run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_DIR"
state_write previous_kind symlink
state_write previous_target "$outgoing/dist"
for later_state in outgoing_release_frozen admin_credential_generation_action private_installed_at activated_at; do
  state_write "$later_state" 1
  run_logged "s11-$later_state" run_rollback
  expect_failure_status "rollback accepted $later_state without a rollback target"
  expect_logged "has $later_state but no recorded rollback target"
  assert_no_state rolled_back_at
  rm -- "$STATE_DIR/$later_state"
done
# A partial record alone means nothing had changed.
run_logged s11-partial run_rollback
expect_success
assert_state_exists rolled_back_at
assert_mode "$outgoing" 751
assert_live_webroot "$outgoing/dist"
echo "ok: $scenario"

echo "Deploy credential transition tests passed."
