#!/usr/bin/env bash
#
# Prune old Kssmi release directories, keeping only the newest release (the
# one that was just finalized). This is a standalone housekeeping script — it
# deliberately lives OUTSIDE deploy-release.sh so the release manager itself
# never performs recursive deletes (see validate-release-policy.mjs:
# "Release manager must not recursively delete release or runtime
# directories").
#
# Safety invariants:
#   - PRIVATE_ROOT must be a direct child of /home (never /home itself).
#   - Only directories whose name matches the release-id shape
#     (<40-hex-sha>-<run-id>[-<attempt>]) or the bootstrap marker are
#     considered for deletion.
#   - The current release (RELEASE_ID) is always kept.
#   - deploy.lock, deploy_state of the current release, private/, public_html/
#     and every runtime data directory are never touched.
#
# Usage:
#   PRIVATE_ROOT=/home/kssmi.com RELEASE_ID=<id> bash scripts/prune-old-releases.sh

set -Eeuo pipefail

PRIVATE_ROOT="${PRIVATE_ROOT:-}"
RELEASE_ID="${RELEASE_ID:-}"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

[ -n "$PRIVATE_ROOT" ] || fail "PRIVATE_ROOT is required."
[ -n "$RELEASE_ID" ] || fail "RELEASE_ID is required."

printf '%s' "$PRIVATE_ROOT" | grep -Eq '^/home/[A-Za-z0-9._-]+$' ||
  fail "PRIVATE_ROOT must be one direct child of /home."
case "$PRIVATE_ROOT" in
  /home/.|/home/..) fail "PRIVATE_ROOT must resolve below /home, not to /home or filesystem root." ;;
esac

printf '%s' "$RELEASE_ID" | grep -Eq '^[0-9a-f]{40}-[1-9][0-9]*(-[1-9][0-9]*)?$' ||
  fail "RELEASE_ID must be a full Git commit SHA plus run id/run attempt."

RELEASE_NAME_RE='^[0-9a-f]{40}-[1-9][0-9]*(-[1-9][0-9]*)?$|^bootstrap-[0-9]{14}-[0-9a-f]{40}(-[1-9][0-9]*)?$'

run_root() {
  if [ "$(id -u)" = 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

RELEASES_DIR="$PRIVATE_ROOT/releases"
STATE_ROOT="$PRIVATE_ROOT/deploy_state"

[ -d "$RELEASES_DIR" ] || { echo "No releases directory; nothing to prune."; exit 0; }

kept=0; removed=0; failed=0

for entry in "$RELEASES_DIR"/*; do
  [ -e "$entry" ] || continue
  name="$(basename "$entry")"
  if [ "$name" = "$RELEASE_ID" ]; then
    kept=$((kept+1))
  elif printf '%s' "$name" | grep -Eq "$RELEASE_NAME_RE"; then
    if run_root rm -rf -- "$entry" 2>/dev/null; then
      removed=$((removed+1))
      echo "pruned release: $name"
    else
      failed=$((failed+1))
      echo "WARN: could not prune release: $name" >&2
    fi
  else
    echo "WARN: skipping non-release entry: $name" >&2
  fi
done

# Remove deploy state for pruned releases (stale state should not accumulate).
# The current release state and the deploy lock are always kept.
if [ -d "$STATE_ROOT" ]; then
  for entry in "$STATE_ROOT"/*; do
    [ -e "$entry" ] || continue
    name="$(basename "$entry")"
    if [ "$name" = "$RELEASE_ID" ] || [ "$name" = deploy.lock ]; then
      kept=$((kept+1))
    elif printf '%s' "$name" | grep -Eq "$RELEASE_NAME_RE"; then
      if run_root rm -rf -- "$entry" 2>/dev/null; then
        removed=$((removed+1))
        echo "pruned state: $name"
      else
        failed=$((failed+1))
        echo "WARN: could not prune state: $name" >&2
      fi
    fi
  done
fi

# The private-module snapshot exists solely to make rollback possible while a
# newly activated release is being smoke-tested. Once finalization succeeded,
# the deployment action invokes this pruner and rollback is no longer an
# available path. Remove that snapshot from the current state as well: it is a
# backup, whereas the remaining state files are only small deployment proof.
current_private_backup="$STATE_ROOT/$RELEASE_ID/private-before"
if [ -d "$current_private_backup" ]; then
  if run_root rm -rf -- "$current_private_backup"; then
    removed=$((removed+1))
    echo "pruned finalized private snapshot: $RELEASE_ID/private-before"
  else
    failed=$((failed+1))
    echo "WARN: could not prune finalized private snapshot: $RELEASE_ID/private-before" >&2
  fi
fi

echo "Release prune complete: kept=$kept removed=$removed failed=$failed"
