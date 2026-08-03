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

mkdir -p "$release/dist/api" "$release/private" "$release/scripts" "$shared/email_data"
printf '%s\n' '<?php echo "ok";' > "$release/dist/index.php"
printf '%s\n' 'asset' > "$release/dist/app.css"
printf '%s\n' '<?php' > "$release/private/http-security.php"
printf '%s\n' '#!/usr/bin/env bash' > "$release/scripts/deploy-release.sh"
printf '%s\n' '#!/usr/bin/env bash' > "$release/scripts/permission-policy.sh"
printf '%s\n' 'release' > "$release/.kssmi-release"
printf '%s\n' '[]' > "$shared/email_data/email-logs.json"

chmod 751 "$release"
find "$release/dist" -type d -exec chmod 755 {} \;
find "$release/dist" -type f -exec chmod 644 {} \;
find "$release/private" -type d -exec chmod 750 {} \;
find "$release/private" -type f -exec chmod 640 {} \;
find "$release/scripts" -type d -exec chmod 750 {} \;
find "$release/scripts" -type f -exec chmod 750 {} \;
chmod 640 "$release/.kssmi-release"
ln -s "$shared/email_data" "$release/email_data"

# Windows/MSYS filesystems commonly expose every directory as 0755 and do not
# implement Unix group-mode changes. CI and production are Ubuntu; skip the
# behavioral portion locally when the filesystem cannot represent the policy.
if [ "$(stat -c '%a' -- "$release/private")" != 750 ]; then
  printf 'Permission policy behavioral tests skipped: filesystem does not preserve Unix modes.\n'
  exit 0
fi

expect_failure() {
  local label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    printf 'Expected policy rejection did not occur: %s\n' "$label" >&2
    exit 1
  fi
  printf 'Negative permission case rejected: %s\n' "$label"
}

kssmi_policy_assert_tree "$release/dist" 755 644 "$uid" "$gid" public
kssmi_policy_assert_tree "$release/private" 750 640 "$uid" "$gid" private
kssmi_policy_assert_tree "$release/scripts" 750 750 "$uid" "$gid" scripts
kssmi_policy_assert_path "$release/.kssmi-release" file 640 "$uid" "$gid"
kssmi_policy_reject_world_writable "$release"
kssmi_policy_reject_executable_php "$release"
kssmi_policy_reject_world_readable_files "$release/private"
kssmi_policy_assert_symlink_allowlist "$release" "$uid" "$gid" \
  "$release/email_data" "$shared/email_data"

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

expect_failure 'wrong owner contract' kssmi_policy_assert_path \
  "$release/dist/app.css" file 644 "$((uid + 1))" "$gid"

ln -s "$tmp_root" "$release/out-of-bound"
expect_failure 'unexpected out-of-bound symlink' kssmi_policy_assert_symlink_allowlist \
  "$release" "$uid" "$gid" "$release/email_data" "$shared/email_data"
rm "$release/out-of-bound"

printf 'Permission policy positive and negative tests passed.\n'
