#!/usr/bin/env bash
set -euo pipefail

case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*)
    echo "Email log migration tests skipped: native symlink semantics require Linux."
    exit 0
    ;;
esac

test_root="$(mktemp -d)"
trap 'rm -rf -- "$test_root"' EXIT

fail() {
  echo "Email log migration test failed: $*" >&2
  exit 1
}

migrate_email_log() {
  local legacy_email_log="$1"
  local email_log="$2"
  mkdir -p "$(dirname "$email_log")"

  (
    exec 8>"$email_log.lock"
    exec 9>"$legacy_email_log.lock"
    # GitHub/production Linux exercises flock. Git Bash on Windows does not
    # ship util-linux, so local runs still verify every migration state change.
    if command -v flock >/dev/null 2>&1; then
      flock -x 8
      flock -x 9
    fi

    if [ -L "$legacy_email_log" ]; then
      local resolved
      resolved="$(readlink -f "$legacy_email_log")"
      [ "$resolved" = "$email_log" ] ||
        fail "legacy symlink points to an unexpected target"
    elif [ -e "$legacy_email_log" ] && [ -e "$email_log" ]; then
      cmp -s "$legacy_email_log" "$email_log" ||
        fail "divergent legacy and new logs were not rejected"
      rm "$legacy_email_log"
      ln -s "$email_log" "$legacy_email_log"
    elif [ -e "$legacy_email_log" ]; then
      mv "$legacy_email_log" "$email_log"
    fi

    if [ ! -e "$email_log" ]; then
      umask 0077
      printf "[]" > "$email_log"
    fi
    if [ ! -e "$legacy_email_log" ]; then
      ln -s "$email_log" "$legacy_email_log"
    fi
  )
}

# Legacy-only: preserve its exact data and install the compatibility path.
case_dir="$test_root/legacy-only"
mkdir -p "$case_dir/email_data"
legacy="$case_dir/email-logs.json"
current="$case_dir/email_data/email-logs.json"
printf '[{"id":"legacy"}]' > "$legacy"
migrate_email_log "$legacy" "$current"
[ "$(cat "$current")" = '[{"id":"legacy"}]' ] || fail "legacy data changed"
[ -L "$legacy" ] || fail "legacy compatibility symlink missing"
[ "$(readlink -f "$legacy")" = "$current" ] || fail "legacy symlink target is wrong"

# Empty install: create a valid empty log and a compatibility symlink.
case_dir="$test_root/empty"
mkdir -p "$case_dir/email_data"
legacy="$case_dir/email-logs.json"
current="$case_dir/email_data/email-logs.json"
migrate_email_log "$legacy" "$current"
[ "$(cat "$current")" = '[]' ] || fail "empty log was not initialized"
[ -L "$legacy" ] || fail "empty-install compatibility symlink missing"

# Identical old/new files: safely converge to one file.
case_dir="$test_root/identical"
mkdir -p "$case_dir/email_data"
legacy="$case_dir/email-logs.json"
current="$case_dir/email_data/email-logs.json"
printf '[{"id":"same"}]' > "$legacy"
printf '[{"id":"same"}]' > "$current"
migrate_email_log "$legacy" "$current"
[ -L "$legacy" ] || fail "identical files did not converge to a symlink"

# Divergent old/new files: fail closed and preserve both.
case_dir="$test_root/divergent"
mkdir -p "$case_dir/email_data"
legacy="$case_dir/email-logs.json"
current="$case_dir/email_data/email-logs.json"
printf '[{"id":"old"}]' > "$legacy"
printf '[{"id":"new"}]' > "$current"
if (migrate_email_log "$legacy" "$current") 2>/dev/null; then
  fail "divergent files were merged silently"
fi
[ "$(cat "$legacy")" = '[{"id":"old"}]' ] || fail "divergent legacy file changed"
[ "$(cat "$current")" = '[{"id":"new"}]' ] || fail "divergent new file changed"

# An old request that opened the legacy inode before migration must still write
# into the moved file. A request opened afterward must follow the symlink.
case_dir="$test_root/in-flight"
mkdir -p "$case_dir/email_data"
legacy="$case_dir/email-logs.json"
current="$case_dir/email_data/email-logs.json"
printf '[]' > "$legacy"
exec 8>"$legacy"
migrate_email_log "$legacy" "$current"
printf '[{"id":"opened-before"}]' >&8
exec 8>&-
[ "$(cat "$current")" = '[{"id":"opened-before"}]' ] ||
  fail "pre-migration open handle wrote to a split file"
printf '[{"id":"opened-after"}]' > "$legacy"
[ "$(cat "$current")" = '[{"id":"opened-after"}]' ] ||
  fail "post-migration legacy write did not follow the symlink"

echo "Email log migration tests passed."
