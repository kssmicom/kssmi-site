#!/usr/bin/env bash
#
# deploy-release.sh must remain the production entry point when executed and a
# side-effect-free function library when sourced by the deployment tests.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
release_script="$repo_root/scripts/deploy-release.sh"

entry_fail() {
  echo "Deploy entry test failed: $*" >&2
  exit 1
}

[ "$(id -u)" != 0 ] || entry_fail "run this test as an unprivileged user"

test_root="$(mktemp -d)"
trap 'rm -rf -- "$test_root"' EXIT
private_root="$test_root/home/kssmi.com"
mkdir -p "$private_root"

test_release_id=0000000000000000000000000000000000000000-1-1

snapshot() {
  (cd "$test_root" && find . -print | LC_ALL=C sort)
}
initial_tree="$(snapshot)"

# Direct executions get every path-relevant variable explicitly, so isolation
# never depends on the entry checks under test or on the caller's environment.
run_direct() {
  env DEPLOY_ENVIRONMENT="$1" \
    PRIVATE_ROOT="$private_root" \
    RELEASE_ID="$test_release_id" \
    SITE_HOST=kssmi.com \
    SITE_URL=https://kssmi.com \
    SITE_USER=kssmi4374 \
    SITE_GROUP=kssmi4374 \
    AUTHENTICATED_SMOKE_VERIFIED=false \
    bash "$release_script" activate 2>&1
}

# Executed directly, main() still enforces the production contract, and it
# does so before touching the filesystem.
if output="$(run_direct test)"; then
  entry_fail "a non-production environment was accepted"
fi
printf '%s\n' "$output" | grep -Fq 'This deployment manager is production-only.' ||
  entry_fail "direct execution did not reach validate_deployment_config: $output"

if output="$(run_direct production)"; then
  entry_fail "a non-production private root was accepted"
fi
printf '%s\n' "$output" | grep -Fq 'PRIVATE_ROOT must be one direct child of /home.' ||
  entry_fail "direct execution did not enforce the production private root: $output"
[ "$initial_tree" = "$(snapshot)" ] || entry_fail "a rejected direct execution changed the filesystem"

# Sourced, it only defines functions: no lock, no state, no traps.
(
  trap - EXIT ERR
  export DEPLOY_ENVIRONMENT=production
  export PRIVATE_ROOT="$private_root"
  export RELEASE_ID="$test_release_id"
  SITE_USER="$(id -un)"
  SITE_GROUP="$(id -g)"
  export SITE_USER SITE_GROUP
  # shellcheck source=deploy-release.sh
  . "$release_script"
  for function_name in main activate_release rollback_release rollback_on_failure \
      finalize_release emit_release_evidence prepare_release_layout rollback_webroot \
      prepare_admin_credential_transition restore_outgoing_release; do
    declare -F "$function_name" >/dev/null ||
      entry_fail "$function_name is not defined after sourcing"
  done
  [ -z "$(trap -p EXIT)" ] || entry_fail "sourcing installed an EXIT trap"
  [ -z "$(trap -p ERR)" ] || entry_fail "sourcing installed an ERR trap"
) || entry_fail "sourcing deploy-release.sh failed"
[ "$initial_tree" = "$(snapshot)" ] || entry_fail "sourcing deploy-release.sh changed the filesystem"

echo "Deploy entry tests passed."
