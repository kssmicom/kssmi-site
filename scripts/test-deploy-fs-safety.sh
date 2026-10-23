#!/usr/bin/env bash
# Isolated tests only: no production paths, network, credentials or workers.
set -Eeuo pipefail
if [ "$(uname -s)" != Linux ]; then
  echo 'Deploy filesystem safety tests skipped: Linux UID/DAC/symlinks required.'
  exit 0
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
. "$repo_root/scripts/deploy-release.sh"
test_root="$(mktemp -d)"
case "$test_root" in /tmp/*|/var/tmp/*) ;; *) echo 'Unexpected test root' >&2; exit 1 ;; esac
trap 'chmod -R u+rwX "$test_root" 2>/dev/null || true; rm -rf -- "$test_root"' EXIT
reject() { if ( "$@" ) >/dev/null 2>&1; then echo "Expected rejection: $1" >&2; exit 1; fi; }
SITE_USER="$(id -un)"
SITE_GROUP="$(id -gn)"

if [ "$(id -u)" = 0 ]; then
  [ "${F2_ROOT_TEST:-}" = 1 ] || { echo 'Explicit isolated root test flag required' >&2; exit 1; }
  # Exercise the real production run_as_site, with a root-owned sentinel that
  # the unprivileged process cannot chmod, chown, truncate, read or replace.
  SITE_USER=nobody
  SITE_GROUP="$(id -gn "$SITE_USER")"
  chmod 755 "$test_root"
  mkdir "$test_root/home" "$test_root/outside"
  chown "$SITE_USER:$SITE_GROUP" "$test_root/home"
  chmod 700 "$test_root/outside"
  printf ROOT-SENTINEL > "$test_root/outside/sentinel"
  chmod 600 "$test_root/outside/sentinel"
  before="$(stat -c '%u:%g:%a:%s:%Y' "$test_root/outside/sentinel")"
  run_as_site ln -s "$test_root/outside/sentinel" "$test_root/home/lock"
  reject prepare_site_file "$test_root/home/lock" 640
  # Intermediate directory replacement must not allow a root write either.
  run_as_site ln -s "$test_root/outside" "$test_root/home/parent"
  reject site_atomic_write "$test_root/home/parent/sentinel" 640 ATTACK
  printf SOURCE > "$test_root/source"
  chmod 644 "$test_root/source"
  reject atomic_install_file "$test_root/source" "$test_root/home/parent/sentinel" 640
  reject prepare_site_directory "$test_root/home/parent" 750
  [ "$(cat "$test_root/outside/sentinel")" = ROOT-SENTINEL ]
  [ "$(stat -c '%u:%g:%a:%s:%Y' "$test_root/outside/sentinel")" = "$before" ]
  reject run_root chmod 777 "$test_root/outside/sentinel"
  # A permitted site destination still works through the same UID boundary.
  site_atomic_write "$test_root/home/ordinary" 640 LEGITIMATE
  [ "$(stat -c %u "$test_root/home/ordinary")" = "$(id -u "$SITE_USER")" ]
  [ "$(cat "$test_root/home/ordinary")" = LEGITIMATE ]
  # Production SSH starts in a root-only directory. find saves/restores its
  # initial directory, so an absolute target alone does not avoid EACCES.
  mkdir "$test_root/root-cwd"
  chmod 700 "$test_root/root-cwd"
  (
    cd "$test_root/root-cwd"
    run_as_site find "$test_root/home" -maxdepth 1 -type f -exec chmod 600 {} \;
    site_atomic_write "$test_root/home/from-root-cwd" 640 LEGITIMATE
  )
  [ "$(stat -c %a "$test_root/home/ordinary")" = 600 ]
  [ "$(cat "$test_root/home/from-root-cwd")" = LEGITIMATE ]
  echo 'Deploy filesystem safety dual-UID tests passed.'
  exit 0
fi

# These tests prove link refusal, atomic replacement and stable inode behavior.
# The separate root invocation above proves actual privilege isolation.
run_as_site() { "$@"; }
run_root() { echo 'Unexpected privileged filesystem operation' >&2; return 1; }
printf ORIGINAL > "$test_root/sentinel"
printf SOURCE > "$test_root/source"
before="$(stat -c '%a:%s:%Y' "$test_root/sentinel")"
ln -s "$test_root/sentinel" "$test_root/lock-link"
reject prepare_site_file "$test_root/lock-link" 640
mkdir "$test_root/outside"
ln -s "$test_root/outside" "$test_root/directory-link"
reject prepare_site_directory "$test_root/directory-link" 750
ln -s "$test_root/sentinel" "$test_root/destination"
atomic_install_file "$test_root/source" "$test_root/destination" 640
[ ! -L "$test_root/destination" ]
[ "$(cat "$test_root/destination")" = SOURCE ]
ln -s "$test_root/sentinel" "$test_root/state"
site_atomic_write "$test_root/state" 640 STATE
[ ! -L "$test_root/state" ] && [ "$(cat "$test_root/state")" = STATE ]
[ "$(cat "$test_root/sentinel")" = ORIGINAL ]
[ "$(stat -c '%a:%s:%Y' "$test_root/sentinel")" = "$before" ]

prepare_site_file "$test_root/lock" 640
inode="$(stat -c %i "$test_root/lock")"
prepare_site_file "$test_root/lock" 640
[ "$(stat -c %i "$test_root/lock")" = "$inode" ]
exec 8<"$test_root/lock"
flock -xn 8
if bash -c 'exec 7<"$1"; flock -xn 7' sh "$test_root/lock"; then
  echo 'Read-only exclusive lock did not exclude another process' >&2; exit 1
fi
flock -u 8
bash -c 'exec 7<"$1"; flock -xn 7' sh "$test_root/lock"

# A protected readable upload can be copied without privileged ownership repair.
mkdir -p "$test_root/upload/"{dist,private,scripts} "$test_root/releases"
printf BUNDLE > "$test_root/upload/dist/index.html"
RELEASE_DIR="$test_root/releases/new"
materialize_release "$test_root/upload"
[ "$(cat "$RELEASE_DIR/dist/index.html")" = BUNDLE ]
reject materialize_release "$test_root/upload"
. "$repo_root/scripts/prune-old-releases.sh"
chmod 551 "$RELEASE_DIR"
prune_directory "$RELEASE_DIR"
[ ! -e "$RELEASE_DIR" ]
reject prune_directory "$test_root/directory-link"
[ -d "$test_root/outside" ]
echo 'Deploy filesystem safety tests passed.'
