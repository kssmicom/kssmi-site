#!/usr/bin/env bash
# Fault injection against real rollback orchestration, never a live server.
set -Eeuo pipefail
if [ "$(uname -s)" != Linux ]; then
  echo 'Rollback failure tests skipped: Linux filesystem semantics required.'
  exit 0
fi
[ "$(id -u)" != 0 ] || { echo 'Run as an unprivileged test user' >&2; exit 1; }
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d)"
trap 'chmod -R u+rwX "$test_root" 2>/dev/null || true; rm -rf -- "$test_root"' EXIT
export PRIVATE_ROOT="$test_root/home"
export SITE_USER="$(id -un)" SITE_GROUP="$(id -gn)"
export RELEASE_ID=1111111111111111111111111111111111111111-1-1
. "$repo_root/scripts/deploy-release.sh"
run_as_site() { "$@"; }
run_root() { echo 'Unexpected root operation' >&2; return 1; }
die() { echo "Rollback test failed: $*" >&2; exit 1; }
reject() { if ( "$@" ) > "$test_root/rejection.log" 2>&1; then die "expected failure from $1"; fi; }

# First challenge the real helpers under disabled errexit, the context that
# caused F1. A failed first module install must not be masked by later success.
mkdir -p "$PRIVATE_BACKUP" "$SHARED_PRIVATE" "$STATE_DIR" "$test_root/old" "$test_root/new"
printf MODULE > "$PRIVATE_BACKUP/first.php"
printf MODULE > "$PRIVATE_BACKUP/second.php"
PRIVATE_MODULES='first.php second.php'
atomic_install_file() { printf '%s\n' "$1" >> "$test_root/installs"; return 1; }
reject restore_shared_private
[ "$(wc -l < "$test_root/installs")" = 1 ] || die 'restore continued after first failed install'

ln -s "$test_root/new" "$LIVE_WEBROOT"
state_write previous_kind symlink
state_write previous_target "$test_root/old"
run_as_site() { if [ "$1" = mv ]; then return 1; fi; "$@"; }
reject rollback_webroot
[ "$(readlink -f "$LIVE_WEBROOT")" = "$test_root/new" ] || die 'failed mv changed pointer'
run_as_site() { "$@"; }
rollback_webroot
[ "$(readlink -f "$LIVE_WEBROOT")" = "$test_root/old" ] || die 'ordinary pointer restore failed'

# Reload definitions, then replace side effects with deterministic faults. The
# real state writer and rollback_release remain under test on every iteration.
. "$repo_root/scripts/deploy-release.sh"
run_as_site() { "$@"; }
eval "original_$(declare -f state_write)"
state_write() {
  if [ "$1" = rolled_back_at ] && [ "$fault" = state ]; then return 1; fi
  original_state_write "$@"
}
step() { printf '%s\n' "$1" >> "$test_root/calls"; [ "$fault" != "$1" ]; }
cleanup_runtime_capability_probe() { :; }
restore_outgoing_release() { step outgoing; }
write_cutover_markers() { step marker || return 1; printf BARRIER > "$EMAIL_CUTOVER_MARKER"; }
restart_live_php_workers() { step restart; }
prove_cutover_barriers() { step barrier; }
sleep() { step wait; }
rollback_webroot() { step webroot; }
restore_shared_private() { step private; }
clear_cutover_markers() { step clear || return 1; rm -f "$EMAIL_CUTOVER_MARKER"; }
mkdir -p "$EMAIL_DATA_DIR"
for fault in outgoing marker restart barrier wait webroot private clear state; do
  rm -f "$STATE_DIR/rolled_back_at" "$EMAIL_CUTOVER_MARKER" "$test_root/calls"
  printf RECORDED > "$STATE_DIR/rollback_target_recorded"
  printf CHANGED > "$STATE_DIR/private_installed_at"
  reject rollback_release
  [ ! -e "$STATE_DIR/rolled_back_at" ] || die "$fault falsely marked rollback complete"
  ! grep -Fq 'Release rollback completed:' "$test_root/rejection.log" || die "$fault printed false success"
  if [ "$fault" != state ]; then
    [ "$(tail -n 1 "$test_root/calls")" = "$fault" ] || die "$fault did not stop the pipeline"
  fi
  case "$fault" in restart|barrier|wait|webroot|private|clear)
    [ -f "$EMAIL_CUTOVER_MARKER" ] || die "$fault prematurely removed the barrier" ;;
  esac
  # A corrected retry must run all steps and complete, not treat the failed
  # attempt as an already-completed rollback.
  failed_step="$fault"
  fault=''
  rollback_release > "$test_root/retry.log" 2>&1
  [ -f "$STATE_DIR/rolled_back_at" ] && [ ! -e "$EMAIL_CUTOVER_MARKER" ] || die "$failed_step retry failed"
  echo "ok: $failed_step failure stopped rollback; corrected retry completed"
done
echo 'Rollback failure propagation tests passed.'
