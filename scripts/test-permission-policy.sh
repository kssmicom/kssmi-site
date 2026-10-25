#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=permission-policy.sh
. "$SCRIPT_DIR/permission-policy.sh"

tmp_root="$(mktemp -d)"
cleanup() {
  chmod -R u+rwX "$tmp_root" 2>/dev/null || true
  rm -rf -- "$tmp_root"
}
trap cleanup EXIT

uid="$(id -u)"
gid="$(id -g)"
release="$tmp_root/release"
shared="$tmp_root/shared"

policy_test_fail() {
  printf 'Permission policy test failed: %s\n' "$*" >&2
  exit 1
}

expect_failure() {
  local label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    printf 'Expected policy rejection did not occur: %s\n' "$label" >&2
    exit 1
  fi
  printf 'Negative permission case rejected: %s\n' "$label"
}

expect_success() {
  local label="$1"
  shift
  "$@" >/dev/null 2>&1 || policy_test_fail "expected acceptance: $label"
  printf 'Positive permission case accepted: %s\n' "$label"
}

expect_output() {
  local label="$1"
  local expected="$2"
  local actual
  shift 2
  actual="$("$@" 2>/dev/null)" || policy_test_fail "expected '$expected', got a rejection: $label"
  [ "$actual" = "$expected" ] || policy_test_fail "expected '$expected', got '$actual': $label"
  printf 'Credential policy case %s: %s\n' "$expected" "$label"
}

# ── Credential generation rules (KS-05); no Unix modes or links needed ─────
for generation_state in valid invalid missing; do
  expect_output "pre-fix layout, $generation_state generation" rotate \
    kssmi_policy_generation_action prefix "$generation_state"
  expect_output "first layout, $generation_state generation" rotate \
    kssmi_policy_generation_action initial "$generation_state"
done
expect_output 'migrated layout, valid generation' keep kssmi_policy_generation_action migrated valid
expect_failure 'migrated layout with a missing generation' kssmi_policy_generation_action migrated missing
expect_failure 'migrated layout with an invalid generation' kssmi_policy_generation_action migrated invalid
expect_failure 'unknown credential layout' kssmi_policy_generation_action unknown valid
expect_failure 'unknown credential generation state' kssmi_policy_generation_action prefix unknown

generation_dir="$tmp_root/generation"
mkdir -p "$generation_dir/directory"
valid_generation=0123456789abcdef0123456789abcdef
printf '%s' "$valid_generation" > "$generation_dir/valid"
expect_success 'well-formed credential generation' kssmi_policy_credential_generation_valid "$generation_dir/valid"
expect_output 'well-formed credential generation' valid kssmi_policy_credential_generation_state "$generation_dir/valid"
expect_output 'absent credential generation' missing kssmi_policy_credential_generation_state "$generation_dir/absent"
expect_failure 'absent credential generation' kssmi_policy_credential_generation_valid "$generation_dir/absent"
expect_failure 'credential generation path is a directory' kssmi_policy_credential_generation_valid "$generation_dir/directory"
expect_output 'credential generation path is a directory' invalid kssmi_policy_credential_generation_state "$generation_dir/directory"
for malformed in uppercase short long newline leading-space empty; do
  case "$malformed" in
    uppercase) printf '%s' 0123456789ABCDEF0123456789abcdef ;;
    short) printf '%s' "${valid_generation%?}" ;;
    long) printf '%s0' "$valid_generation" ;;
    newline) printf '%s\n' "$valid_generation" ;;
    leading-space) printf ' %s' "${valid_generation%?}" ;;
    empty) : ;;
  esac > "$generation_dir/$malformed"
  expect_failure "malformed credential generation ($malformed)" \
    kssmi_policy_credential_generation_valid "$generation_dir/$malformed"
  expect_output "malformed credential generation ($malformed)" invalid \
    kssmi_policy_credential_generation_state "$generation_dir/$malformed"
done

mkdir -p "$release/dist/api" "$release/private" "$release/scripts" "$shared/email_data"
printf '%s\n' '<?php echo "ok";' > "$release/dist/index.php"
printf '%s\n' 'asset' > "$release/dist/app.css"
printf '%s\n' '<?php' > "$release/private/http-security.php"
printf '%s\n' '#!/usr/bin/env bash' > "$release/scripts/deploy-release.sh"
printf '%s\n' '#!/usr/bin/env bash' > "$release/scripts/permission-policy.sh"
printf '%s\n' 'release' > "$release/.kssmi-release"
printf '%s\n' '[]' > "$shared/email_data/email-logs.json"

find "$release/dist" -type d -exec chmod 755 {} \;
find "$release/dist" -type f -exec chmod 644 {} \;
find "$release/private" -type d -exec chmod 750 {} \;
find "$release/private" -type f -exec chmod 640 {} \;
find "$release/scripts" -type d -exec chmod 750 {} \;
find "$release/scripts" -type f -exec chmod 750 {} \;
chmod 640 "$release/.kssmi-release"
ln -s "$shared/email_data" "$release/email_data"
# deploy-release.sh leaves the release root read-only for its owner (KS-05).
chmod 551 "$release"

# Windows/MSYS filesystems commonly expose every directory as 0755 and do not
# implement Unix group-mode changes. CI and production are Ubuntu; skip the
# behavioral portion locally when the filesystem cannot represent the policy.
if [ "$(stat -c '%a' -- "$release/private")" != 750 ]; then
  printf 'Credential generation rules passed.\n'
  printf 'Permission policy behavioral tests skipped: filesystem does not preserve Unix modes.\n'
  exit 0
fi

kssmi_policy_assert_path "$release" directory 551 "$uid" "$gid"
kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
kssmi_policy_assert_tree "$release/private" 750 640 "$uid" "$gid" private
kssmi_policy_assert_tree "$release/scripts" 750 750 "$uid" "$gid" scripts
kssmi_policy_assert_path "$release/.kssmi-release" file 640 "$uid" "$gid"
kssmi_policy_reject_world_writable "$release"
kssmi_policy_reject_executable_php "$release"
kssmi_policy_reject_world_readable_files "$release/private"
kssmi_policy_assert_symlink_allowlist "$release" "$uid" "$gid" \
  "$release/email_data" "$shared/email_data"

chmod 751 "$release"
expect_failure 'release root writable by its owner' kssmi_policy_assert_path "$release" directory 551 "$uid" "$gid"
chmod 551 "$release"

chmod 666 "$release/dist/app.css"
expect_failure 'world-writable public file' kssmi_policy_reject_world_writable "$release"
expect_failure 'wrong public file mode' kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
chmod 644 "$release/dist/app.css"

chmod 744 "$release/dist/index.php"
expect_failure 'executable PHP source' kssmi_policy_reject_executable_php "$release"
chmod 644 "$release/dist/index.php"

chmod 644 "$release/private/http-security.php"
expect_failure 'world-readable private file' kssmi_policy_reject_world_readable_files "$release/private"
expect_failure 'wrong private file mode' kssmi_policy_assert_tree "$release/private" 750 640 "$uid" "$gid" private
chmod 640 "$release/private/http-security.php"

# The tree check selects mismatches with find predicates instead of stat per
# path; it must still catch every kind of mismatch anywhere in the tree.
mkdir -p "$release/dist/deep/er"
printf 'x' > "$release/dist/deep/er/page.html"
chmod 755 "$release/dist/deep" "$release/dist/deep/er"
chmod 644 "$release/dist/deep/er/page.html"
kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
chmod 640 "$release/dist/deep/er/page.html"
expect_failure 'wrong mode on a deeply nested file' kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
chmod 644 "$release/dist/deep/er/page.html"
chmod 750 "$release/dist/deep/er"
expect_failure 'wrong mode on a nested directory' kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
chmod 755 "$release/dist/deep/er"
if chmod 2755 "$release/dist/deep" 2>/dev/null && [ "$(stat -c '%a' -- "$release/dist/deep")" = 2755 ]; then
  expect_failure 'setgid bit on a directory (exact mode, as stat %a)' kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
fi
# A numeric chmod keeps a directory's setgid bit on Linux; clear it explicitly.
chmod g-s "$release/dist/deep"
chmod 755 "$release/dist/deep"
odd_name="$release/dist/deep/odd name
line.html"
printf 'x' > "$odd_name"
chmod 600 "$odd_name"
expect_failure 'wrong mode on a file whose name has a space and a newline' kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
chmod 644 "$odd_name"
kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
expect_failure 'non-octal tree mode' kssmi_policy_assert_tree "$release/dist" 755 64x "$uid" "$gid" public
rm -r "$release/dist/deep"

expect_failure 'wrong owner contract' kssmi_policy_assert_path \
  "$release/dist/app.css" file 644 "$((uid + 1))" "$gid"

chmod u+w "$release"
ln -s "$tmp_root" "$release/out-of-bound"
chmod u-w "$release"
expect_failure 'unexpected out-of-bound symlink' kssmi_policy_assert_symlink_allowlist \
  "$release" "$uid" "$gid" "$release/email_data" "$shared/email_data"
chmod u+w "$release"
rm "$release/out-of-bound"
chmod u-w "$release"

# ── Credential state (KS-05) ────────────────────────────────────────────────
home="$tmp_root/home"
links="$home/links"
outgoing="$home/releases/outgoing"
password="$home/.email_logs_password"
tokens="$home/.email_reset_tokens.json"
generation="$home/.admin_credential_generation"
password_fixture='fixture-password-hash'
mkdir -p "$links" "$home/releases"
printf '%s' "$password_fixture" > "$password"
printf '%s' '[]' > "$tokens"
printf '%s' "$valid_generation" > "$generation"
chmod 600 "$password" "$tokens" "$generation"

# Exact credential links: the link names its persistent file verbatim, and
# that file is a 0600 regular file of the expected owner.
ln -s "$password" "$links/exact"
expect_success 'credential link to its persistent file' \
  kssmi_policy_assert_credential_link "$links/exact" "$password" "$uid" "$gid"
expect_failure 'credential link with a wrong target' \
  kssmi_policy_assert_credential_link "$links/exact" "$tokens" "$uid" "$gid"
expect_failure 'credential link owner mismatch' \
  kssmi_policy_assert_credential_link "$links/exact" "$password" "$((uid + 1))" "$gid"
chmod 644 "$password"
expect_failure 'credential link target that is not 0600' \
  kssmi_policy_assert_credential_link "$links/exact" "$password" "$uid" "$gid"
chmod 600 "$password"
ln -s ../.email_logs_password "$links/relative"
expect_failure 'credential link with a relative target' \
  kssmi_policy_assert_credential_link "$links/relative" "$password" "$uid" "$gid"
ln -s "$home/absent" "$links/dangling"
expect_failure 'dangling credential link' \
  kssmi_policy_assert_credential_link "$links/dangling" "$home/absent" "$uid" "$gid"
ln -s "$password" "$home/chained"
ln -s "$home/chained" "$links/chain"
expect_failure 'credential link to another link' \
  kssmi_policy_assert_credential_link "$links/chain" "$home/chained" "$uid" "$gid"
mkdir "$home/directory"
ln -s "$home/directory" "$links/to-directory"
expect_failure 'credential link to a directory' \
  kssmi_policy_assert_credential_link "$links/to-directory" "$home/directory" "$uid" "$gid"
printf '%s' "$password_fixture" > "$links/regular"
chmod 600 "$links/regular"
expect_failure 'credential path that is not a link' \
  kssmi_policy_assert_credential_link "$links/regular" "$password" "$uid" "$gid"

# Outgoing release layout: one table row per case, every other path normal.
outgoing_layout() {
  kssmi_policy_outgoing_credential_layout "$outgoing" "$password" "$tokens" "$generation" "$uid" "$gid"
}

build_prefix_outgoing() {
  if [ -e "$outgoing" ]; then
    chmod -R u+rwX "$outgoing"
    rm -rf -- "$outgoing"
  fi
  mkdir "$outgoing"
  ln -s "$password" "$outgoing/.email_logs_password"
  ln -s "$tokens" "$outgoing/.email_reset_tokens.json"
  printf '%s' 3 > "$outgoing/.admin_credential_version"
}

build_migrated_outgoing() {
  build_prefix_outgoing
  rm "$outgoing/.admin_credential_version"
  ln -s "$generation" "$outgoing/.admin_credential_generation"
}

build_prefix_outgoing
expect_output 'pre-fix release root with exact links' prefix outgoing_layout
rm "$outgoing/.admin_credential_version"
expect_output 'pre-fix release root before any login' prefix outgoing_layout
ln -s "$home/version" "$outgoing/.admin_credential_version"
expect_failure 'linked pre-fix version counter' outgoing_layout
rm "$outgoing/.admin_credential_version"
mkdir "$outgoing/.admin_credential_version"
expect_failure 'pre-fix version counter of another type' outgoing_layout

build_prefix_outgoing
rm "$outgoing/.email_logs_password"
printf '%s' "$password_fixture" > "$outgoing/.email_logs_password"
expect_output 'outgoing password copy identical to the persistent file' prefix outgoing_layout
printf '%s' 'changed-password-hash' > "$outgoing/.email_logs_password"
expect_failure 'outgoing password differs from the persistent file' outgoing_layout
layout_messages="$(outgoing_layout 2>&1 || true)"
case "$layout_messages" in
  *"$password_fixture"*|*changed-password-hash*) policy_test_fail 'the layout check printed credential contents' ;;
esac
rm "$outgoing/.email_logs_password"
expect_failure 'outgoing password missing' outgoing_layout
mkdir "$outgoing/.email_logs_password"
expect_failure 'outgoing password of another type' outgoing_layout
rmdir "$outgoing/.email_logs_password"
ln -s "$tokens" "$outgoing/.email_logs_password"
expect_failure 'outgoing password linked to the wrong file' outgoing_layout

build_prefix_outgoing
rm "$outgoing/.email_reset_tokens.json"
printf '%s' '{}' > "$outgoing/.email_reset_tokens.json"
expect_output 'outgoing reset tokens as a regular file' prefix outgoing_layout
rm "$outgoing/.email_reset_tokens.json"
expect_failure 'outgoing reset tokens missing' outgoing_layout
mkdir "$outgoing/.email_reset_tokens.json"
expect_failure 'outgoing reset tokens of another type' outgoing_layout
rmdir "$outgoing/.email_reset_tokens.json"
ln -s "$password" "$outgoing/.email_reset_tokens.json"
expect_failure 'outgoing reset tokens linked to the wrong file' outgoing_layout

build_migrated_outgoing
expect_output 'migrated release root' migrated outgoing_layout
printf '%s' 3 > "$outgoing/.admin_credential_version"
expect_output 'migrated release root ignores a version counter' migrated outgoing_layout
printf '%s' 'not-a-generation' > "$generation"
expect_failure 'migrated release root with an invalid generation' outgoing_layout
rm "$generation"
expect_failure 'migrated release root with a lost generation' outgoing_layout
printf '%s' "$valid_generation" > "$generation"
chmod 600 "$generation"
rm "$outgoing/.admin_credential_generation"
ln -s "$tokens" "$outgoing/.admin_credential_generation"
expect_failure 'generation linked to the wrong file' outgoing_layout
rm "$outgoing/.admin_credential_generation"
printf '%s' "$valid_generation" > "$outgoing/.admin_credential_generation"
expect_failure 'generation as a regular file in the release root' outgoing_layout
rm "$outgoing/.admin_credential_generation"
mkdir "$outgoing/.admin_credential_generation"
expect_failure 'generation of another type' outgoing_layout

# Freezing and restoring a release root: only the owner's write bit changes,
# both steps repeat safely, and the recorded mode comes back exactly.
frozen="$tmp_root/frozen"
mkdir "$frozen"
chmod 751 "$frozen"
recorded_mode="$(stat -c '%a' -- "$frozen")"
[ "$recorded_mode" = 751 ] || policy_test_fail "unexpected fixture mode $recorded_mode"
kssmi_policy_freeze_directory "$frozen"
[ "$(stat -c '%a' -- "$frozen")" = 551 ] || policy_test_fail 'freeze did not leave mode 0551'
[ ! -w "$frozen" ] || policy_test_fail 'the owner can still write a frozen directory'
kssmi_policy_freeze_directory "$frozen"
[ "$(stat -c '%a' -- "$frozen")" = 551 ] || policy_test_fail 'a repeated freeze changed the mode'
kssmi_policy_restore_directory_mode "$frozen" "$recorded_mode"
[ "$(stat -c '%a' -- "$frozen")" = 751 ] || policy_test_fail 'restore did not bring back the recorded mode'
[ -w "$frozen" ] || policy_test_fail 'the owner cannot write a restored directory'
kssmi_policy_restore_directory_mode "$frozen" "$recorded_mode"
[ "$(stat -c '%a' -- "$frozen")" = 751 ] || policy_test_fail 'a repeated restore changed the mode'
printf 'Positive permission case accepted: %s\n' 'freeze and restore of a release root'
expect_failure 'restore with a symbolic recorded mode' kssmi_policy_restore_directory_mode "$frozen" u+w
expect_failure 'restore with a short recorded mode' kssmi_policy_restore_directory_mode "$frozen" 75
ln -s "$frozen" "$tmp_root/frozen-link"
expect_failure 'freeze through a link' kssmi_policy_freeze_directory "$tmp_root/frozen-link"
expect_failure 'restore through a link' kssmi_policy_restore_directory_mode "$tmp_root/frozen-link" 751
[ "$(stat -c '%a' -- "$frozen")" = 751 ] || policy_test_fail 'a rejected link changed the directory mode'
chmod 551 "$frozen"
kssmi_policy_freeze_directory "$frozen"
[ "$(stat -c '%a' -- "$frozen")" = 551 ] || policy_test_fail 'freezing a 0551 root changed its mode'

printf 'Permission policy positive and negative tests passed.\n'
