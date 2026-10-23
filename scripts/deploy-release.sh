#!/usr/bin/env bash
#
# Versioned Kssmi release activation and rollback.
#
# The workflow uploads an immutable bundle to:
#   <PRIVATE_ROOT>/releases/<commit>-<run-id>-<run-attempt>/{dist,private,scripts}
#
# This script keeps runtime data under the selected environment's private root,
# activates the public
# webroot with an atomic symlink replacement, and preserves enough state to
# restore both the previous webroot and the previous shared private modules
# when the post-deploy smoke test fails.
#
# Kssmi specifics vs the XinXin reference script:
#   - Shared PHP security modules and the verified Cloudflare range snapshot
#     are installed, backed up and restored together outside the webroot.
#   - VJT data already lives OUTSIDE the webroot (<PRIVATE_ROOT>/vjt_data),
#     so there is no VJT cutover barrier and no webroot migration — only a
#     SQLite integrity probe before activation.
#   - The email cutover barrier (send-mail.php / email-log-store.php) is the
#     only application-level write barrier needed.
#   - Admin credentials (password hash, reset tokens, credential generation)
#     live only in PRIVATE_ROOT; every release links them and has a 0551
#     root. Activation first records the rollback target, then freezes the
#     outgoing release root so the pre-fix code cannot write a credential
#     beside its links, and migrates the generation (KS-05).

set -Eeuo pipefail

COMMAND="${1:-}"
RELEASE_ID="${RELEASE_ID:-}"

DEPLOY_ENVIRONMENT="${DEPLOY_ENVIRONMENT:-production}"
SITE_HOST="${SITE_HOST:-kssmi.com}"
SITE_URL="${SITE_URL:-https://$SITE_HOST}"
SITE_USER="${SITE_USER:-kssmi4374}"
SITE_GROUP="${SITE_GROUP:-kssmi4374}"
PRIVATE_ROOT="${PRIVATE_ROOT:-/home/kssmi.com}"
AUTHENTICATED_SMOKE_VERIFIED="${AUTHENTICATED_SMOKE_VERIFIED:-false}"
RELEASES_DIR="$PRIVATE_ROOT/releases"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
NEW_WEBROOT="$RELEASE_DIR/dist"
PERMISSION_POLICY="$RELEASE_DIR/scripts/permission-policy.sh"
RUNTIME_PROBE_SOURCE="$RELEASE_DIR/scripts/runtime-capability-probe.php"
RUNTIME_PROBE_NAME="kssmi-runtime-capability-$RELEASE_ID.php"
LIVE_WEBROOT="$PRIVATE_ROOT/public_html"
RUNTIME_PROBE_PATH="$LIVE_WEBROOT/$RUNTIME_PROBE_NAME"
STATE_ROOT="$PRIVATE_ROOT/deploy_state"
STATE_DIR="$STATE_ROOT/$RELEASE_ID"
SHARED_PRIVATE="$PRIVATE_ROOT/private"
PRIVATE_BACKUP="$STATE_DIR/private-before"
DEPLOY_LOCK="$STATE_ROOT/deploy.lock"

EMAIL_DATA_DIR="$PRIVATE_ROOT/email_data"
EMAIL_LOG="$EMAIL_DATA_DIR/email-logs.json"
EMAIL_LOCK="$EMAIL_LOG.lock"
EMAIL_CUTOVER_MARKER="$EMAIL_DATA_DIR/email-log-cutover-until"
LEGACY_EMAIL_LOG="$PRIVATE_ROOT/email-logs.json"
LEGACY_EMAIL_LOCK="$LEGACY_EMAIL_LOG.lock"
RATE_LIMIT_DIR="$PRIVATE_ROOT/rate_limit"
VJT_DATA_DIR="$PRIVATE_ROOT/vjt_data"
PASSWORD_FILE="$PRIVATE_ROOT/.email_logs_password"
RESET_TOKENS_FILE="$PRIVATE_ROOT/.email_reset_tokens.json"
CREDENTIAL_GENERATION_FILE="$PRIVATE_ROOT/.admin_credential_generation"
# PHP locks each admin credential beside its persistent file
# (kssmi_admin_file_lock), so every release locks the same three inodes.
PASSWORD_LOCK="$PASSWORD_FILE.lock"
RESET_TOKENS_LOCK="$RESET_TOKENS_FILE.lock"
CREDENTIAL_GENERATION_LOCK="$CREDENTIAL_GENERATION_FILE.lock"
PRIVATE_CONFIG="$PRIVATE_ROOT/private_config.php"
CURRENT_RELEASE_MARKER="$PRIVATE_ROOT/.kssmi-current-release"
GSC_JSON="$SHARED_PRIVATE/gsc/google-service-account.json"

# Shared security deployment: the JSON snapshot is installed before the PHP
# consumer, so the new consumer can never observe a missing new dependency.
PRIVATE_MODULES="email-log-store.php cloudflare-ip-ranges.json rate-limit.php http-security.php"
SHORT_LINK_PRIVATE_DIR="$SHARED_PRIVATE/links"
SHORT_LINK_USERS_FILE="$SHORT_LINK_PRIVATE_DIR/short-links-users.txt"
RATE_LIMIT_MODULE="$SHARED_PRIVATE/rate-limit.php"
EMAIL_LOG_MODULE="$SHARED_PRIVATE/email-log-store.php"
HTTP_SECURITY_MODULE="$SHARED_PRIVATE/http-security.php"
CLOUDFLARE_RANGES="$SHARED_PRIVATE/cloudflare-ip-ranges.json"
# Endpoints whose PHP guard consults the email cutover marker (send-mail.php
# checks kssmi_email_logs_cutover_is_active and returns 503 while the marker
# is active). Only these can prove the barrier via HTTP status. email-logs.php
# also checks the marker but sits behind Cloudflare Access, which answers 302
# at the edge before the request reaches this origin — the cutover proof
# treats a stable CF-Access 302 baseline as "already blocked at the edge" and
# excludes it from the status proof. Other API endpoints do not consult the
# marker and therefore cannot prove it.
CUTOVER_ENDPOINTS="send-mail.php"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

validate_deployment_config() {
  [ "$DEPLOY_ENVIRONMENT" = production ] ||
    fail "This deployment manager is production-only."
  printf '%s' "$SITE_HOST" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$' ||
    fail "SITE_HOST is invalid."
  [ "$SITE_URL" = "https://$SITE_HOST" ] ||
    fail "SITE_URL must be the exact HTTPS origin for SITE_HOST."
  printf '%s' "$SITE_USER" | grep -Eq '^[a-z_][a-z0-9_-]*$' || fail "SITE_USER is invalid."
  printf '%s' "$SITE_GROUP" | grep -Eq '^[a-z_][a-z0-9_-]*$' || fail "SITE_GROUP is invalid."
  printf '%s' "$PRIVATE_ROOT" | grep -Eq '^/home/[A-Za-z0-9._-]+$' ||
    fail "PRIVATE_ROOT must be one direct child of /home."
  case "$PRIVATE_ROOT" in
    /home/.|/home/..) fail "PRIVATE_ROOT must resolve below /home, not to /home or filesystem root." ;;
  esac
  case "$AUTHENTICATED_SMOKE_VERIFIED" in
    true|false) ;;
    *) fail "AUTHENTICATED_SMOKE_VERIFIED must be true or false." ;;
  esac

  [ "$SITE_HOST" = kssmi.com ] || fail "Production SITE_HOST must be kssmi.com."
  [ "$SITE_URL" = https://kssmi.com ] || fail "Production SITE_URL must be https://kssmi.com."
  [ "$PRIVATE_ROOT" = /home/kssmi.com ] || fail "Production PRIVATE_ROOT must be /home/kssmi.com."
  [ "$SITE_USER" = kssmi4374 ] || fail "Production SITE_USER must be kssmi4374."
  [ "$SITE_GROUP" = kssmi4374 ] || fail "Production SITE_GROUP must be kssmi4374."
}

report_failure() {
  echo "ERROR: deploy-release.sh $COMMAND failed at line $1: $2" >&2
}

run_root() {
  if [ "$(id -u)" = 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

run_as_site() {
  if [ "$(id -un)" = "$SITE_USER" ]; then
    "$@"
  else
    sudo -u "$SITE_USER" "$@"
  fi
}

# Credential checks read files that only the site account can read, so the
# permission-policy functions run as that account. They are passed by
# definition: this never depends on the modes of a not yet normalized bundle.
run_policy_as_site() {
  policy_functions="$(declare -f $(compgen -A function kssmi_policy_))"
  run_as_site bash -c "$policy_functions"'
"$@"' bash "$@"
}

resolve_php_sqlite() {
  # The VJT integrity probe requires the pdo_sqlite driver. An explicit
  # PHP_BIN wins; otherwise probe the CLI php and the OpenLiteSpeed LSAPI
  # binaries and keep the newest interpreter that loads pdo_sqlite. lsphp
  # does not support php -r/-m, so probe via a throwaway script file whose
  # exit code is the extension_loaded() result. Fail closed with a per-
  # candidate report when none qualifies.
  php_loads_sqlite() {
    probe="$(mktemp)"
    printf '<?php exit(extension_loaded("pdo_sqlite") ? 0 : 1);\n' > "$probe"
    if "$1" "$probe" 2>/dev/null; then
      rm -f "$probe"
      return 0
    fi
    rm -f "$probe"
    return 1
  }
  if [ -n "${PHP_BIN:-}" ] && php_loads_sqlite "$PHP_BIN"; then
    printf '%s\n' "$PHP_BIN"
    return 0
  fi
  found=""
  probed=""
  for candidate in "$(command -v php 2>/dev/null || true)" \
      /usr/local/lsws/lsphp*/bin/lsphp /usr/local/lsws/bin/lsphp; do
    [ -n "$candidate" ] || continue
    if [ ! -x "$candidate" ]; then
      probed="${probed}$candidate (not executable); "
      continue
    fi
    if php_loads_sqlite "$candidate"; then
      found="$candidate"
      probed="${probed}$candidate [pdo_sqlite]; "
    else
      probed="${probed}$candidate [no pdo_sqlite]; "
    fi
  done
  [ -n "$found" ] ||
    fail "No PHP with the pdo_sqlite driver was found (probed: $probed). The Kssmi site requires the pdo_sqlite extension; enable it in the server PHP configuration and rerun the deployment."
  printf '%s\n' "$found"
}

state_write() {
  name="$1"
  value="$2"
  run_root sh -c "umask 0027; printf '%s\n' \"\$1\" > \"\$2.tmp\"; chown '$SITE_USER:$SITE_GROUP' \"\$2.tmp\"; chmod 640 \"\$2.tmp\"; mv -f \"\$2.tmp\" \"\$2\"" sh "$value" "$STATE_DIR/$name"
}

state_read() {
  name="$1"
  [ -f "$STATE_DIR/$name" ] || return 1
  cat "$STATE_DIR/$name"
}

is_supported_release_id() {
  # v2 includes the globally unique workflow run id. The two-part legacy
  # suffix remains readable so the first v2 deployment can accept the
  # existing .kssmi-current-release marker and roll back to it safely.
  printf '%s' "$1" | grep -Eq '^[0-9a-f]{40}-[1-9][0-9]*(-[1-9][0-9]*)?$'
}

validate_release_id() {
  is_supported_release_id "$RELEASE_ID" ||
    fail "RELEASE_ID must be a full Git commit SHA plus run id/run attempt (legacy SHA/attempt markers remain supported)."
}

validate_release_bundle() {
  [ -d "$NEW_WEBROOT" ] || fail "Release webroot is missing: $NEW_WEBROOT"
  [ -d "$RELEASE_DIR/private" ] || fail "Release private directory is missing."
  [ -f "$PERMISSION_POLICY" ] || fail "Release permission policy is missing."
  [ -f "$RUNTIME_PROBE_SOURCE" ] || fail "Release LSAPI capability probe is missing."
  [ -f "$NEW_WEBROOT/.htaccess" ] || fail "Release .htaccess is missing."
  [ -f "$NEW_WEBROOT/send-mail.php" ] || fail "Release send-mail.php is missing."
  [ -f "$NEW_WEBROOT/api/vjt-helpers.php" ] || fail "Release VJT helper is missing."
  for module in $PRIVATE_MODULES; do
    [ -f "$RELEASE_DIR/private/$module" ] ||
      fail "Release private module is missing: $module"
  done
  # The release must not carry runtime-only credentials or data. GSC JSON is
  # a manual, server-side credential kept in SHARED_PRIVATE/gsc — never in the
  # uploaded bundle. The credential generation is persistent server state, and
  # the version counter belongs to the pre-fix code (KS-05).
  for sensitive_path in \
    "$NEW_WEBROOT/.email_logs_password" \
    "$NEW_WEBROOT/.email_reset_tokens.json" \
    "$NEW_WEBROOT/.admin_credential_generation" \
    "$NEW_WEBROOT/.admin_credential_version" \
    "$RELEASE_DIR/.admin_credential_generation" \
    "$RELEASE_DIR/.admin_credential_version" \
    "$NEW_WEBROOT/api/vjt_data" \
    "$RELEASE_DIR/private_config.php" \
    "$RELEASE_DIR/private/gsc"; do
    [ ! -e "$sensitive_path" ] && [ ! -L "$sensitive_path" ] ||
      fail "Sensitive path exists in the immutable release: $sensitive_path"
  done
}

prepare_persistent_storage() {
  # RELEASES_DIR is the sole 0751 exception: the LiteSpeed worker needs only
  # execute/traverse access to reach the public 0755 release webroot. Runtime
  # data and deployment state do not need world traverse and remain 0750.
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 751 "$RELEASES_DIR"
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 \
    "$STATE_ROOT" "$SHARED_PRIVATE" "$SHORT_LINK_PRIVATE_DIR" "$EMAIL_DATA_DIR" \
    "$RATE_LIMIT_DIR" "$VJT_DATA_DIR"
  run_root chown -R "$SITE_USER:$SITE_GROUP" "$SHARED_PRIVATE"
  run_root find "$SHARED_PRIVATE" -type d -exec chmod 750 {} \;
  run_root find "$SHARED_PRIVATE" -type f -exec chmod 640 {} \;
  run_root chown -R "$SITE_USER:$SITE_GROUP" "$RATE_LIMIT_DIR"
  run_root find "$RATE_LIMIT_DIR" -type d -exec chmod 750 {} \;
  run_root find "$RATE_LIMIT_DIR" -type f -exec chmod 600 {} \;
  run_root touch "$PASSWORD_FILE" "$RESET_TOKENS_FILE" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  run_root chown "$SITE_USER:$SITE_GROUP" \
    "$PASSWORD_FILE" "$RESET_TOKENS_FILE" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  # Stable lock-file permissions / Legacy lock compatibility permissions:
  # both locks must be owned by the site account and group-readable only.
  run_root chown "$SITE_USER:$SITE_GROUP" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  run_root chmod 600 "$PASSWORD_FILE" "$RESET_TOKENS_FILE"
  run_root chmod 640 "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  # PHP holds its admin credential locks on these inodes, so an existing lock
  # file is only checked: replacing it would let two writers in. A missing
  # one is prepared under a temporary name and published by the site account
  # with link(2), which fails instead of replacing a lock file that PHP
  # created in the meantime; that file is then checked like any other. The
  # generation itself is written only by the credential transition.
  site_uid="$(id -u "$SITE_USER")"
  site_gid="$(id -g "$SITE_USER")"
  for credential_lock in "$PASSWORD_LOCK" "$RESET_TOKENS_LOCK" "$CREDENTIAL_GENERATION_LOCK"; do
    if [ ! -e "$credential_lock" ] && [ ! -L "$credential_lock" ]; then
      run_as_site env SITE_GROUP="$SITE_GROUP" sh -c '
        set -eu
        umask 077
        lock_temp="$(mktemp "$1.deploy-XXXXXXXXXX")"
        trap "rm -f \"\$lock_temp\"" EXIT
        chgrp "$SITE_GROUP" "$lock_temp"
        chmod 600 "$lock_temp"
        ln -T "$lock_temp" "$1" 2>/dev/null || [ -e "$1" ] || [ -L "$1" ]
      ' sh "$credential_lock" ||
        fail "The credential lock file could not be created: $credential_lock"
    fi
    kssmi_policy_assert_path "$credential_lock" file 600 "$site_uid" "$site_gid"
  done
  # SMTP and Turnstile credentials are provisioned once on the server. They
  # must never be bundled into a release or replaced with an empty file.
  [ -f "$PRIVATE_CONFIG" ] && [ ! -L "$PRIVATE_CONFIG" ] ||
    fail "Persistent private_config.php is missing or is not a regular file: $PRIVATE_CONFIG"
  run_root chown "$SITE_USER:$SITE_GROUP" "$PRIVATE_CONFIG"
  run_root chmod 600 "$PRIVATE_CONFIG"
  run_as_site test -r "$PRIVATE_CONFIG"
  # Manual GSC credential: if present, enforce the exact permission contract
  # (readable by the real site account, never world-readable).
  if [ -f "$GSC_JSON" ]; then
    run_root chown -R "$SITE_USER:$SITE_GROUP" "$SHARED_PRIVATE/gsc"
    run_root chmod 700 "$SHARED_PRIVATE/gsc"
    run_root chmod 600 "$GSC_JSON"
    run_as_site test -r "$GSC_JSON"
    echo "GSC JSON readable by $SITE_USER: OK"
  fi
}

prepare_control_paths() {
  # Only RELEASES_DIR is traversed by the web worker. STATE_ROOT is private
  # deployment control data and must not be world-traversable.
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 751 "$RELEASES_DIR"
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_ROOT"
}

create_release_link() {
  link_path="$1"
  target_path="$2"
  [ ! -e "$link_path" ] && [ ! -L "$link_path" ] ||
    fail "Release compatibility path already exists: $link_path"
  run_root ln -s "$target_path" "$link_path"
  run_root chown -h "$SITE_USER:$SITE_GROUP" "$link_path"
}

prepare_release_layout() {
  run_root chown -R "$SITE_USER:$SITE_GROUP" "$RELEASE_DIR"
  run_root find "$NEW_WEBROOT" -type d -exec chmod 755 {} \;
  run_root find "$NEW_WEBROOT" -type f -exec chmod 644 {} \;
  run_root find "$RELEASE_DIR/private" -type d -exec chmod 750 {} \;
  run_root find "$RELEASE_DIR/private" -type f -exec chmod 640 {} \;
  run_root find "$RELEASE_DIR/scripts" -type d -exec chmod 750 {} \;
  run_root find "$RELEASE_DIR/scripts" -type f -exec chmod 640 {} \;
  run_root find "$RELEASE_DIR/scripts" -type f -name '*.sh' -exec chmod 750 {} \;

  create_release_link "$RELEASE_DIR/public_html" "$NEW_WEBROOT"
  create_release_link "$RELEASE_DIR/email_data" "$EMAIL_DATA_DIR"
  create_release_link "$RELEASE_DIR/vjt_data" "$VJT_DATA_DIR"
  create_release_link "$RELEASE_DIR/rate_limit" "$RATE_LIMIT_DIR"
  create_release_link "$RELEASE_DIR/.email_logs_password" "$PASSWORD_FILE"
  create_release_link "$RELEASE_DIR/.email_reset_tokens.json" "$RESET_TOKENS_FILE"
  create_release_link "$RELEASE_DIR/.admin_credential_generation" "$CREDENTIAL_GENERATION_FILE"
  create_release_link "$RELEASE_DIR/private_config.php" "$PRIVATE_CONFIG"
  create_release_link "$RELEASE_DIR/short-links-users.txt" "$SHORT_LINK_USERS_FILE"

  state_write release_webroot "$NEW_WEBROOT"
  run_root sh -c "umask 0027; printf '%s\n' '$RELEASE_ID' > '$RELEASE_DIR/.kssmi-release'; chown '$SITE_USER:$SITE_GROUP' '$RELEASE_DIR/.kssmi-release'; chmod 640 '$RELEASE_DIR/.kssmi-release'"
  # With its links and marker in place, the release root becomes read-only
  # for the site account: PHP writes credentials only through the links, and
  # a credential written beside them would be lost on the next deploy (KS-05).
  # Other-execute stays for the web worker that traverses to dist.
  run_root chmod 551 "$RELEASE_DIR"
}

verify_release_permission_policy() {
  site_uid="$(id -u "$SITE_USER")"
  site_gid="$(id -g "$SITE_USER")"

  kssmi_policy_assert_path "$RELEASES_DIR" directory 751 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RELEASE_DIR" directory 551 "$site_uid" "$site_gid"
  kssmi_policy_assert_tree "$NEW_WEBROOT" 755 644 "$site_uid" "$site_gid" public
  kssmi_policy_assert_tree "$RELEASE_DIR/private" 750 640 "$site_uid" "$site_gid" private
  kssmi_policy_assert_path "$RELEASE_DIR/scripts" directory 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RELEASE_DIR/scripts/deploy-release.sh" file 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$PERMISSION_POLICY" file 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RUNTIME_PROBE_SOURCE" file 640 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RELEASE_DIR/.kssmi-release" file 640 "$site_uid" "$site_gid"
  kssmi_policy_reject_world_writable "$RELEASE_DIR"
  kssmi_policy_reject_executable_php "$RELEASE_DIR"
  kssmi_policy_reject_world_readable_files "$RELEASE_DIR/private"
  kssmi_policy_assert_symlink_allowlist "$RELEASE_DIR" "$site_uid" "$site_gid" \
    "$RELEASE_DIR/public_html" "$NEW_WEBROOT" \
    "$RELEASE_DIR/email_data" "$EMAIL_DATA_DIR" \
    "$RELEASE_DIR/vjt_data" "$VJT_DATA_DIR" \
    "$RELEASE_DIR/rate_limit" "$RATE_LIMIT_DIR" \
    "$RELEASE_DIR/.email_logs_password" "$PASSWORD_FILE" \
    "$RELEASE_DIR/.email_reset_tokens.json" "$RESET_TOKENS_FILE" \
    "$RELEASE_DIR/.admin_credential_generation" "$CREDENTIAL_GENERATION_FILE" \
    "$RELEASE_DIR/private_config.php" "$PRIVATE_CONFIG" \
    "$RELEASE_DIR/short-links-users.txt" "$SHORT_LINK_USERS_FILE"
  # PHP resolves these links before every credential write and lock; each
  # must name its persistent file exactly, and that file must be 0600.
  kssmi_policy_assert_credential_link "$RELEASE_DIR/.email_logs_password" "$PASSWORD_FILE" "$site_uid" "$site_gid"
  kssmi_policy_assert_credential_link "$RELEASE_DIR/.email_reset_tokens.json" "$RESET_TOKENS_FILE" "$site_uid" "$site_gid"
  kssmi_policy_assert_credential_link "$RELEASE_DIR/.admin_credential_generation" "$CREDENTIAL_GENERATION_FILE" "$site_uid" "$site_gid"
  echo "Immutable release permission policy v$KSSMI_PERMISSION_POLICY_VERSION: OK"
}

verify_persistent_permission_policy() {
  site_uid="$(id -u "$SITE_USER")"
  site_gid="$(id -g "$SITE_USER")"

  kssmi_policy_assert_path "$STATE_ROOT" directory 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$STATE_DIR" directory 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$SHARED_PRIVATE" directory 750 "$site_uid" "$site_gid"
  for module in $PRIVATE_MODULES; do
    kssmi_policy_assert_path "$SHARED_PRIVATE/$module" file 640 "$site_uid" "$site_gid"
  done
  kssmi_policy_reject_world_writable "$SHARED_PRIVATE"
  kssmi_policy_reject_world_readable_files "$SHARED_PRIVATE"

  kssmi_policy_assert_path "$EMAIL_DATA_DIR" directory 750 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$EMAIL_LOG" file 640 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$EMAIL_LOCK" file 640 "$site_uid" "$site_gid"
  # The admin credentials and their locks must exist: the release links
  # would otherwise dangle, and PHP fails closed on a dangling link.
  kssmi_policy_assert_path "$PASSWORD_FILE" file 600 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RESET_TOKENS_FILE" file 600 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$CREDENTIAL_GENERATION_FILE" file 600 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$PASSWORD_LOCK" file 600 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$RESET_TOKENS_LOCK" file 600 "$site_uid" "$site_gid"
  kssmi_policy_assert_path "$CREDENTIAL_GENERATION_LOCK" file 600 "$site_uid" "$site_gid"
  run_policy_as_site kssmi_policy_credential_generation_valid "$CREDENTIAL_GENERATION_FILE" ||
    fail "The persistent admin credential generation is not 32 lowercase hex characters."
  kssmi_policy_assert_sensitive_file "$PRIVATE_CONFIG" "$site_uid" "$site_gid"
  kssmi_policy_assert_symlink "$LEGACY_EMAIL_LOG" "$EMAIL_LOG" "$site_uid" "$site_gid"

  kssmi_policy_assert_tree "$RATE_LIMIT_DIR" 750 600 "$site_uid" "$site_gid" rate-limit
  kssmi_policy_assert_tree "$VJT_DATA_DIR" 750 600 "$site_uid" "$site_gid" VJT
  if [ -e "$GSC_JSON" ]; then
    kssmi_policy_assert_path "$SHARED_PRIVATE/gsc" directory 700 "$site_uid" "$site_gid"
    kssmi_policy_assert_path "$GSC_JSON" file 600 "$site_uid" "$site_gid"
  fi
  kssmi_policy_reject_world_writable "$EMAIL_DATA_DIR"
  kssmi_policy_reject_world_writable "$RATE_LIMIT_DIR"
  kssmi_policy_reject_world_writable "$VJT_DATA_DIR"
  echo "Persistent runtime permission policy v$KSSMI_PERMISSION_POLICY_VERSION: OK"
}

cleanup_runtime_capability_probe() {
  # The name is derived only from the validated release id. Remove the exact
  # path from both possible webroots so an interrupted activation cannot leave
  # a diagnostic endpoint behind.
  run_root rm -f -- "$RUNTIME_PROBE_PATH" "$NEW_WEBROOT/$RUNTIME_PROBE_NAME" 2>/dev/null || true
}

probe_real_runtime_capabilities() {
  expected_uid="$(id -u "$SITE_USER")"
  expected_gid="$(id -g "$SITE_USER")"
  cleanup_runtime_capability_probe
  run_root install -o "$SITE_USER" -g "$SITE_GROUP" -m 644 \
    "$RUNTIME_PROBE_SOURCE" "$RUNTIME_PROBE_PATH"

  response=""
  # The origin host may provide an older curl without newer failure options.
  # This is a loopback HTTP capability probe, so portable --fail preserves
  # strict status handling without weakening any public HTTPS verification.
  if ! response="$(curl --fail --show-error --silent \
    --connect-timeout 5 --max-time 30 \
    -H "Host: $SITE_HOST" \
    -H 'X-Forwarded-Proto: https' \
    -H "X-Kssmi-Runtime-Probe: $RELEASE_ID" \
    -H "X-Kssmi-Private-Root: $PRIVATE_ROOT" \
    "http://127.0.0.1/$RUNTIME_PROBE_NAME")"; then
    cleanup_runtime_capability_probe
    [ -n "$response" ] && printf '%s\n' "$response" >&2
    fail "OpenLiteSpeed/LSAPI runtime capability request failed."
  fi
  cleanup_runtime_capability_probe

  printf '%s\n' "$response" | grep -Fxq 'KSSMI_RUNTIME_CAPABILITY_V1' ||
    fail "OpenLiteSpeed/LSAPI runtime capability response marker is missing."
  actual_uid="$(printf '%s\n' "$response" | awk -F= '$1 == "uid" { print $2; exit }')"
  actual_gid="$(printf '%s\n' "$response" | awk -F= '$1 == "gid" { print $2; exit }')"
  printf '%s' "$actual_uid" | grep -Eq '^[0-9]+$' || fail "LSAPI runtime UID is invalid."
  printf '%s' "$actual_gid" | grep -Eq '^[0-9]+$' || fail "LSAPI runtime GID is invalid."
  [ "$actual_uid" = "$expected_uid" ] ||
    fail "LSAPI runtime UID $actual_uid does not match $SITE_USER UID $expected_uid."
  [ "$actual_gid" = "$expected_gid" ] ||
    fail "LSAPI runtime GID $actual_gid does not match $SITE_GROUP GID $expected_gid."

  for capability in \
    private_modules_read password_hash_read gsc_read_if_present \
    email_atomic_write rate_limit_atomic_write \
    sqlite_transaction_rollback sqlite_wal_shm_modes; do
    printf '%s\n' "$response" | grep -Fxq "$capability=PASS" ||
      fail "LSAPI runtime capability failed: $capability"
  done

  runtime_user="$(getent passwd "$actual_uid" | cut -d: -f1 || true)"
  runtime_group="$(getent group "$actual_gid" | cut -d: -f1 || true)"
  echo "LSAPI runtime identity: uid=$actual_uid(${runtime_user:-unknown}) gid=$actual_gid(${runtime_group:-unknown})"
  printf '%s\n' "$response" | grep -E '^(private_modules_read|password_hash_read|gsc_read_if_present|email_atomic_write|rate_limit_atomic_write|sqlite_transaction_rollback|sqlite_wal_shm_modes)=PASS$'
  state_write runtime_capabilities "$response"
  echo "OpenLiteSpeed/LSAPI runtime capability probe: OK"
}

validate_cloudflare_snapshot_pair() {
  rate_limit_path="$1"
  snapshot_path="$2"
  pair_label="$3"
  run_as_site test -r "$rate_limit_path" ||
    fail "$pair_label rate-limit consumer is not readable by $SITE_USER."
  run_as_site test -r "$snapshot_path" ||
    fail "$pair_label Cloudflare snapshot is not readable by $SITE_USER."

  php_bin="$(resolve_php_sqlite)"
  snapshot_probe="$(mktemp)"
  chmod 644 "$snapshot_probe"
  cat > "$snapshot_probe" <<'PHP'
<?php
declare(strict_types=1);
require_once $argv[1];
$ranges = kssmi_cloudflare_snapshot_load($argv[2]);
if (!is_array($ranges) || count($ranges) < 15) {
    file_put_contents('php://stderr', "Cloudflare snapshot rejected by deployed PHP consumer.\n");
    exit(1);
}
PHP
  if ! run_as_site "$php_bin" "$snapshot_probe" "$rate_limit_path" "$snapshot_path"; then
    rm -f "$snapshot_probe"
    fail "$pair_label Cloudflare snapshot/consumer validation failed."
  fi
  rm -f "$snapshot_probe"
  echo "$pair_label Cloudflare snapshot/consumer pair: OK"
}

backup_shared_private() {
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$PRIVATE_BACKUP"
  for module in $PRIVATE_MODULES; do
    source_path="$SHARED_PRIVATE/$module"
    if [ -f "$source_path" ]; then
      run_root cp -p "$source_path" "$PRIVATE_BACKUP/$module"
    else
      run_root touch "$PRIVATE_BACKUP/$module.missing"
      run_root chown "$SITE_USER:$SITE_GROUP" "$PRIVATE_BACKUP/$module.missing"
      run_root chmod 640 "$PRIVATE_BACKUP/$module.missing"
    fi
  done
}

atomic_install_file() {
  source_path="$1"
  destination_path="$2"
  mode="$3"
  temp_path="$destination_path.deploy-$RELEASE_ID"
  run_root install -o "$SITE_USER" -g "$SITE_GROUP" -m "$mode" "$source_path" "$temp_path"
  run_root mv -f "$temp_path" "$destination_path"
}

install_shared_private() {
  run_root chmod 750 "$SHARED_PRIVATE"
  for module in $PRIVATE_MODULES; do
    atomic_install_file \
      "$RELEASE_DIR/private/$module" \
      "$SHARED_PRIVATE/$module" \
      640
    run_as_site test -r "$SHARED_PRIVATE/$module" ||
      fail "Installed private file is not readable by $SITE_USER: $module"
  done
  # Named probes are retained as explicit policy markers for the three PHP
  # modules that are loaded directly by public endpoints.
  run_as_site test -r "$RATE_LIMIT_MODULE"
  run_as_site test -r "$EMAIL_LOG_MODULE"
  run_as_site test -r "$HTTP_SECURITY_MODULE"
  validate_cloudflare_snapshot_pair \
    "$RATE_LIMIT_MODULE" \
    "$CLOUDFLARE_RANGES" \
    "Installed"
}

restore_shared_private() {
  [ -d "$PRIVATE_BACKUP" ] || return 0
  for module in $PRIVATE_MODULES; do
    if [ -f "$PRIVATE_BACKUP/$module" ]; then
      atomic_install_file \
        "$PRIVATE_BACKUP/$module" \
        "$SHARED_PRIVATE/$module" \
        640
    elif [ -f "$PRIVATE_BACKUP/$module.missing" ]; then
      run_root rm -f "$SHARED_PRIVATE/$module"
    fi
  done
  if [ -f "$RATE_LIMIT_MODULE" ] && [ -f "$CLOUDFLARE_RANGES" ]; then
    validate_cloudflare_snapshot_pair \
      "$RATE_LIMIT_MODULE" \
      "$CLOUDFLARE_RANGES" \
      "Restored"
  elif [ -f "$PRIVATE_BACKUP/cloudflare-ip-ranges.json.missing" ]; then
    # Compatibility with the one-time rollback to a pre-4.2 release whose
    # rate-limit.php still carried its own ranges and required no JSON file.
    echo "Restored legacy pre-snapshot private runtime."
  else
    fail "Rollback restored an incomplete Cloudflare snapshot/consumer pair."
  fi
}

write_cutover_markers() {
  cutover_until="$(($(date +%s) + 1800))"
  run_root sh -c "umask 0027; printf '%s' '$cutover_until' > '$EMAIL_CUTOVER_MARKER.tmp'; chown '$SITE_USER:$SITE_GROUP' '$EMAIL_CUTOVER_MARKER.tmp'; chmod 640 '$EMAIL_CUTOVER_MARKER.tmp'; mv -f '$EMAIL_CUTOVER_MARKER.tmp' '$EMAIL_CUTOVER_MARKER'"
}

clear_cutover_markers() {
  run_root rm -f "$EMAIL_CUTOVER_MARKER"
}

restart_live_php_workers() {
  # Atomic file replacement cannot purge PHP OPcache when the server runs with
  # timestamp validation disabled (opcache.validate_timestamps=0): LSAPI
  # workers keep executing the previously compiled guard or endpoint code
  # indefinitely. Recycle this site's PHP workers; LSAPI respawns them on
  # demand with a fresh cache. pgrep first: pkill returns 1 when no processes
  # match (idle LSAPI pool), and that must NOT trigger a full OpenLiteSpeed
  # restart (which affects every tenant on the shared server). Only fall back
  # to lswsctrl restart when workers exist but pkill is denied.
  if pgrep -u "$SITE_USER" -f lsphp >/dev/null 2>&1; then
    run_root pkill -u "$SITE_USER" -f lsphp 2>/dev/null ||
      run_root /usr/local/lsws/bin/lswsctrl restart 2>/dev/null || true
  fi
}

record_cutover_baseline() {
  # Probe every live endpoint before any barrier change so the proof can
  # distinguish "the barrier never engaged" from "the endpoint was already
  # unroutable". Endpoints absent from the live webroot are skipped.
  # POST is required: send-mail.php answers 405 to GET before reaching the
  # cutover guard (L63-65), so only a POST request exercises the 503 path.
  CUTOVER_BASELINE=""
  for endpoint in $(cutover_endpoints_in_live_webroot); do
    status="$(curl -sS --connect-timeout 5 --max-time 10 \
      -X POST --data '' \
      -H 'Cache-Control: no-cache' \
      -o /dev/null \
      -w '%{http_code}' \
      "$SITE_URL/$endpoint?kssmi_cutover=$RELEASE_ID" || true)"
    CUTOVER_BASELINE="${CUTOVER_BASELINE}${CUTOVER_BASELINE:+
}$endpoint $status"
  done
  echo "Cutover baseline: $(printf '%s' "$CUTOVER_BASELINE" | tr '\n' ';')" >&2
}

cutover_endpoints_in_live_webroot() {
  for endpoint in $CUTOVER_ENDPOINTS; do
    [ -f "$LIVE_WEBROOT/$endpoint" ] && printf '%s\n' "$endpoint"
  done
  return 0
}

cutover_baseline_status() {
  printf '%s\n' "${CUTOVER_BASELINE:-}" | awk -v endpoint="$1" '$1 == endpoint { print $2 }'
}

prove_cutover_barriers() {
  # The email cutover marker blocks email-log mutations and send-mail.php
  # submissions, but LSAPI workers can retain an OPcache entry until recycled.
  # Probe the guarded endpoints as a group for up to a minute so worker
  # respawns and edge propagation cannot cause a false rollback. The barrier's
  # safety contract is "normal request processing is provably interrupted",
  # which these outcomes satisfy:
  #   1. 503 - the PHP guard answered as designed;
  #   2. 404 with a 404 baseline - the live webroot never routes the URL;
  #   3. any 5xx that differs from the endpoint's pre-barrier baseline.
  #   4. a stable 302 CF-Access baseline - the request is answered by
  #      Cloudflare Access at the edge and never reaches this origin, so the
  #      endpoint is provably unreachable regardless of the marker (this is
  #      why email-logs.php is not in CUTOVER_ENDPOINTS).
  # An endpoint still returning its normal non-302 baseline status is the only
  # genuinely unsafe outcome and keeps failing closed.
  attempts=20
  delay_seconds=3
  endpoints="$(cutover_endpoints_in_live_webroot)"
  [ -n "$endpoints" ] || fail "No PHP cutover endpoints exist in the live webroot."

  for attempt in $(seq 1 "$attempts"); do
    failures=""
    for endpoint in $endpoints; do
      status="$(curl -sS --connect-timeout 5 --max-time 10 \
        -X POST --data '' \
        -H 'Cache-Control: no-cache' \
        -H 'Pragma: no-cache' \
        -o /dev/null \
        -w '%{http_code}' \
        "$SITE_URL/$endpoint?kssmi_cutover=$RELEASE_ID" || true)"
      if [ "$status" = 503 ]; then
        continue
      fi
      baseline="$(cutover_baseline_status "$endpoint")"
      if [ "$status" = 404 ] && [ "$baseline" = 404 ]; then
        continue
      fi
      if [ "$status" = 302 ] && [ "$baseline" = 302 ]; then
        # Blocked at the Cloudflare Access edge before reaching the origin.
        continue
      fi
      if [ -n "$baseline" ] && [ "$status" != "$baseline" ] &&
        [ "$status" -ge 500 ] 2>/dev/null; then
        continue
      fi
      failures="${failures}${failures:+, }$endpoint=$status"
    done
    [ -z "$failures" ] && return 0

    if [ "$attempt" -lt "$attempts" ]; then
      echo "Waiting for email cutover guard (attempt $attempt/$attempts): $failures" >&2
      sleep "$delay_seconds"
    fi
  done

  if [ -n "${1:-}" ]; then
    # Rollback path: the markers already block writes, so a barrier-proof
    # failure (e.g. the PHP worker did not respawn) must not abort the
    # restoration and leave a half-rolled-back release.
    echo "WARN: cutover barrier proof did not complete after $attempts attempts ($failures); continuing because the cutover markers block writes." >&2
    return 1
  fi
  fail "Application cutover barrier did not become active after $attempts attempts: $failures"
}

migrate_email_history() {
  # The JSON schema check uses the same resolved interpreter as the VJT probe:
  # CLI php may be absent or lack extensions, and lsphp (the LSAPI binary) does
  # not support php -r, so validate via a script file that works for both.
  php_sqlite_bin="$(resolve_php_sqlite)"
  json_probe="$(mktemp)"
  chmod 644 "$json_probe"
  cat > "$json_probe" <<'PHP'
<?php
if (!function_exists("array_is_list")) {
    function array_is_list($value) {
        if (!is_array($value)) return false;
        $expected = 0;
        foreach ($value as $key => $_value) {
            if ($key !== $expected++) return false;
        }
        return true;
    }
}
$raw = file_get_contents($argv[1]);
$data = json_decode($raw, true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || !array_is_list($data)) exit(1);
foreach ($data as $row) if (!is_array($row) || array_is_list($row)) exit(1);
PHP
  run_root env \
    LEGACY_EMAIL_LOG="$LEGACY_EMAIL_LOG" \
    EMAIL_LOG="$EMAIL_LOG" \
    EMAIL_LOCK="$EMAIL_LOCK" \
    VALIDATE_PHP="$php_sqlite_bin" \
    VALIDATE_PROBE="$json_probe" \
    sh -c '
      set -eu
      exec 8>"$EMAIL_LOCK"
      flock -x 8
      exec 9>"$LEGACY_EMAIL_LOG.lock"
      flock -x 9

      if [ -L "$LEGACY_EMAIL_LOG" ]; then
        resolved="$(readlink -f "$LEGACY_EMAIL_LOG")"
        [ "$resolved" = "$EMAIL_LOG" ] || {
          echo "Legacy email log symlink has an unexpected target: $resolved" >&2
          exit 1
        }
      elif [ -e "$LEGACY_EMAIL_LOG" ] && [ -e "$EMAIL_LOG" ]; then
        cmp -s "$LEGACY_EMAIL_LOG" "$EMAIL_LOG" || {
          echo "Legacy and private email logs differ; refusing an unsafe merge." >&2
          exit 1
        }
        rm "$LEGACY_EMAIL_LOG"
        ln -s "$EMAIL_LOG" "$LEGACY_EMAIL_LOG"
      elif [ -e "$LEGACY_EMAIL_LOG" ]; then
        mv "$LEGACY_EMAIL_LOG" "$EMAIL_LOG"
      fi

      if [ ! -e "$EMAIL_LOG" ]; then
        umask 0077
        printf "[]" > "$EMAIL_LOG"
      fi
      "$VALIDATE_PHP" "$VALIDATE_PROBE" "$EMAIL_LOG"
      [ -e "$LEGACY_EMAIL_LOG" ] || ln -s "$EMAIL_LOG" "$LEGACY_EMAIL_LOG"
    '
  rm -f "$json_probe"

  run_root chown "$SITE_USER:$SITE_GROUP" "$EMAIL_LOG" "$EMAIL_LOCK" "$EMAIL_DATA_DIR"
  run_root chown -h "$SITE_USER:$SITE_GROUP" "$LEGACY_EMAIL_LOG"
  run_root chmod 640 "$EMAIL_LOG" "$EMAIL_LOCK"
  run_root chmod 750 "$EMAIL_DATA_DIR"
  run_as_site sh -c '
    set -eu
    probe="$1/.email-log-probe-$$"
    moved="$probe.moved"
    : > "$probe"
    chmod 600 "$probe"
    mv "$probe" "$moved"
    rm "$moved"
  ' sh "$EMAIL_DATA_DIR"
  # Preliminary write/rename check as the configured site account. The later
  # loopback HTTP probe independently proves the real OpenLiteSpeed LSAPI UID.
  echo "Email data atomic-write permissions: OK"
  # Rate-limit module readability + storage permissions as the site account.
  run_as_site test -r "$SHARED_PRIVATE/rate-limit.php"
  run_as_site test -r "$SHARED_PRIVATE/email-log-store.php"
  run_as_site test -r "$SHARED_PRIVATE/http-security.php"
  run_as_site test -r "$SHARED_PRIVATE/cloudflare-ip-ranges.json"
  run_as_site sh -c '
    set -eu
    probe="$1/.rate-limit-write-probe-$$"
    moved="$probe.moved"
    : > "$probe"
    : > "$moved"
    chmod 600 "$probe"
    mv -f "$probe" "$moved"
    rm "$moved"
  ' sh "$RATE_LIMIT_DIR"
  echo "Rate-limit storage permissions: OK"
  echo "Shared PHP module readability: OK"
}

probe_vjt_integrity() {
  # VJT data already lives outside the webroot, so there is no migration and
  # no cutover barrier for it. Before switching the webroot, prove the SQLite
  # store is healthy so a corrupt database can never ship behind a "clean"
  # deployment.
  run_root chown -R "$SITE_USER:$SITE_GROUP" "$VJT_DATA_DIR"
  run_root chmod 750 "$VJT_DATA_DIR"
  run_root find "$VJT_DATA_DIR" -maxdepth 1 -type f -exec chmod 600 {} \;
  for sqlite_file in "$VJT_DATA_DIR/vjt.sqlite" "$VJT_DATA_DIR/shortlinks.sqlite"; do
  if [ -f "$sqlite_file" ]; then
    php_sqlite_bin="$(resolve_php_sqlite)"
    integrity_probe="$(mktemp)"
    chmod 644 "$integrity_probe"
    cat > "$integrity_probe" <<'PHP'
<?php
$db = new PDO("sqlite:" . $argv[1]);
// A live site may hold a WAL checkpoint briefly; wait it out instead of
// treating a busy database as corruption and rolling back a healthy release.
$db->exec("PRAGMA busy_timeout = 5000");
$result = $db->query("PRAGMA integrity_check")->fetchColumn();
if ($result !== "ok") {
    fwrite(STDERR, "VJT SQLite integrity check failed: " . $result . PHP_EOL);
    exit(1);
}
PHP
    run_as_site "$php_sqlite_bin" "$integrity_probe" "$sqlite_file"
    rm -f "$integrity_probe"
  fi
  done
  run_as_site test -w "$VJT_DATA_DIR"
}

probe_email_log_integrity() {
  php_sqlite_bin="$(resolve_php_sqlite)"
  json_probe="$(mktemp)"
  chmod 644 "$json_probe"
  cat > "$json_probe" <<'PHP'
<?php
if (!function_exists("array_is_list")) {
    function array_is_list($value) {
        if (!is_array($value)) return false;
        $expected = 0;
        foreach ($value as $key => $_value) {
            if ($key !== $expected++) return false;
        }
        return true;
    }
}
$raw = file_get_contents($argv[1]);
$data = json_decode($raw, true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || !array_is_list($data)) exit(1);
foreach ($data as $row) if (!is_array($row) || array_is_list($row)) exit(1);
PHP
  if ! run_root env \
    EMAIL_LOCK="$EMAIL_LOCK" \
    EMAIL_LOG="$EMAIL_LOG" \
    VALIDATE_PHP="$php_sqlite_bin" \
    VALIDATE_PROBE="$json_probe" \
    SITE_USER="$SITE_USER" \
    sh -c '
      set -eu
      exec 8>"$EMAIL_LOCK"
      flock -x 8
      sudo -u "$SITE_USER" "$VALIDATE_PHP" "$VALIDATE_PROBE" "$EMAIL_LOG"
    '
  then
    rm -f "$json_probe"
    fail "Email log JSON integrity check failed."
  fi
  rm -f "$json_probe"
}

record_previous_webroot() {
  if [ -L "$LIVE_WEBROOT" ]; then
    previous_target="$(readlink -f "$LIVE_WEBROOT")"
    [ -d "$previous_target" ] ||
      fail "Current public_html symlink target is not a directory."
    state_write previous_kind symlink
    state_write previous_target "$previous_target"
  elif [ -d "$LIVE_WEBROOT" ]; then
    state_write previous_kind directory
  else
    fail "Current public_html is neither a directory nor a symlink."
  fi

  if [ -f "$CURRENT_RELEASE_MARKER" ]; then
    previous_current_release="$(run_as_site cat "$CURRENT_RELEASE_MARKER")"
    is_supported_release_id "$previous_current_release" ||
      fail "Current release marker has an invalid release id."
    state_write previous_current_release "$previous_current_release"
  else
    state_write previous_current_release_missing 1
  fi
}

# ── Admin credential transition (KS-05) ─────────────────────────────────────
# activate_release() runs this first, right after it installs the failure
# trap. The rollback target is recorded completely and then marked; only after
# the mark is the outgoing release root frozen, its credential layout checked
# and the persistent credential generation migrated. rollback_release() relies
# on rollback_target_recorded being that boundary. Logs and state name paths,
# modes, layouts and actions, never a credential value.

record_outgoing_release() {
  previous_kind="$(state_read previous_kind)"
  case "$previous_kind" in
    # First bootstrap: public_html is still the original directory, and no
    # outgoing release root exists to freeze or restore.
    directory) return 0 ;;
    symlink) ;;
    *) fail "Unknown previous webroot kind: $previous_kind" ;;
  esac
  previous_target="$(state_read previous_target)"
  outgoing_release_dir="$(dirname -- "$previous_target")"
  outgoing_release_name="${outgoing_release_dir#"$RELEASES_DIR"/}"
  case "$outgoing_release_name" in
    ''|.|..|*/*) fail "The live webroot is not a release webroot: $previous_target" ;;
  esac
  [ "$previous_target" = "$RELEASES_DIR/$outgoing_release_name/dist" ] ||
    fail "The live webroot is not a release webroot: $previous_target"
  [ "$outgoing_release_dir" != "$RELEASE_DIR" ] ||
    fail "The live webroot already belongs to this release: $RELEASE_ID"
  [ -d "$outgoing_release_dir" ] && [ ! -L "$outgoing_release_dir" ] ||
    fail "The outgoing release root is not a directory: $outgoing_release_dir"
  outgoing_release_mode="$(stat -c '%a' -- "$outgoing_release_dir")"
  case "$outgoing_release_mode" in
    [01234567][01234567][01234567]|[01234567][01234567][01234567][01234567]) ;;
    *) fail "The outgoing release root has an unexpected mode: $outgoing_release_mode" ;;
  esac
  state_write outgoing_release_dir "$outgoing_release_dir"
  state_write outgoing_release_mode "$outgoing_release_mode"
}

freeze_outgoing_release() {
  previous_kind="$(state_read previous_kind)"
  if [ "$previous_kind" = directory ]; then
    echo "First bootstrap: there is no outgoing release root to freeze."
    return 0
  fi
  outgoing_release_dir="$(state_read outgoing_release_dir)"
  run_policy_as_site kssmi_policy_freeze_directory "$outgoing_release_dir"
  if run_as_site test -w "$outgoing_release_dir"; then
    fail "The site account can still write the outgoing release root: $outgoing_release_dir"
  fi
  state_write outgoing_release_frozen 1
  echo "Outgoing release root frozen for the credential transition: $outgoing_release_dir"
}

verify_outgoing_admin_credentials() {
  previous_kind="$(state_read previous_kind)"
  if [ "$previous_kind" = directory ]; then
    admin_credential_layout=initial
  else
    outgoing_release_dir="$(state_read outgoing_release_dir)"
    site_uid="$(id -u "$SITE_USER")"
    site_gid="$(id -g "$SITE_USER")"
    admin_credential_layout="$(run_policy_as_site kssmi_policy_outgoing_credential_layout \
      "$outgoing_release_dir" "$PASSWORD_FILE" "$RESET_TOKENS_FILE" \
      "$CREDENTIAL_GENERATION_FILE" "$site_uid" "$site_gid")" ||
      fail "The outgoing release's admin credentials need a manual decision before this deployment (KS-05)."
    case "$admin_credential_layout" in
      migrated|prefix) ;;
      *) fail "Unexpected admin credential layout: $admin_credential_layout" ;;
    esac
  fi
  state_write admin_credential_layout "$admin_credential_layout"
  echo "Outgoing admin credential layout: $admin_credential_layout"
}

rotate_admin_credential_generation() {
  # One site-account process takes the reset-token lock (FD 7), then the
  # generation lock (FD 6): the order PHP nests them in. It opens the lock
  # files read-only, so it never creates or truncates them, and it leaves
  # FD 9 (deploy lock) and FD 8 (email-log migration) alone. Tokens are
  # cleared first, as in PHP's rotation; the strict PHP token decoder rejects
  # every entry written by the pre-fix code. The new value is checked before
  # it is written and is never printed.
  run_as_site env \
    SITE_GROUP="$SITE_GROUP" \
    RESET_TOKENS_FILE="$RESET_TOKENS_FILE" \
    RESET_TOKENS_LOCK="$RESET_TOKENS_LOCK" \
    CREDENTIAL_GENERATION_FILE="$CREDENTIAL_GENERATION_FILE" \
    CREDENTIAL_GENERATION_LOCK="$CREDENTIAL_GENERATION_LOCK" \
    sh -c '
      set -eu
      umask 077
      tokens_temp=""
      generation_temp=""
      cleanup() {
        [ -z "$tokens_temp" ] || rm -f "$tokens_temp"
        [ -z "$generation_temp" ] || rm -f "$generation_temp"
      }
      trap cleanup EXIT
      exec 7<"$RESET_TOKENS_LOCK"
      flock -x 7
      exec 6<"$CREDENTIAL_GENERATION_LOCK"
      flock -x 6

      generation="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d " \n")"
      case "$generation" in
        *[!0123456789abcdef]*)
          echo "The new credential generation is not lowercase hex; nothing was written." >&2
          exit 1
          ;;
      esac
      [ "${#generation}" -eq 32 ] || {
        echo "The new credential generation is not 32 characters; nothing was written." >&2
        exit 1
      }

      tokens_temp="$(mktemp "$RESET_TOKENS_FILE.deploy-XXXXXXXXXX")"
      printf "[]" > "$tokens_temp"
      chgrp "$SITE_GROUP" "$tokens_temp"
      chmod 600 "$tokens_temp"
      mv -Tf "$tokens_temp" "$RESET_TOKENS_FILE"
      tokens_temp=""

      generation_temp="$(mktemp "$CREDENTIAL_GENERATION_FILE.deploy-XXXXXXXXXX")"
      printf "%s" "$generation" > "$generation_temp"
      chgrp "$SITE_GROUP" "$generation_temp"
      chmod 600 "$generation_temp"
      mv -Tf "$generation_temp" "$CREDENTIAL_GENERATION_FILE"
      generation_temp=""
      [ "$(cat "$CREDENTIAL_GENERATION_FILE")" = "$generation" ]
    ' || fail "The admin credential generation could not be replaced."
}

migrate_admin_credential_generation() {
  admin_credential_layout="$(state_read admin_credential_layout)"
  generation_state="$(run_policy_as_site kssmi_policy_credential_generation_state "$CREDENTIAL_GENERATION_FILE")"
  generation_action="$(kssmi_policy_generation_action "$admin_credential_layout" "$generation_state")" ||
    fail "The persistent admin credential generation is $generation_state; recover it manually before deploying (KS-05)."
  case "$generation_action" in
    keep)
      state_write admin_credential_generation_action kept
      echo "Admin credential generation: kept"
      ;;
    rotate)
      rotate_admin_credential_generation
      run_policy_as_site kssmi_policy_credential_generation_valid "$CREDENTIAL_GENERATION_FILE" ||
        fail "The new admin credential generation did not verify."
      state_write admin_credential_generation_action rotated
      echo "Admin credential generation: rotated; admin sessions and reset links issued before are revoked"
      ;;
    *) fail "Unexpected admin credential generation action: $generation_action" ;;
  esac
}

prepare_admin_credential_transition() {
  record_previous_webroot
  record_outgoing_release
  state_write rollback_target_recorded 1
  freeze_outgoing_release
  verify_outgoing_admin_credentials
  migrate_admin_credential_generation
}

activate_webroot() {
  next_link="$PRIVATE_ROOT/.public_html.next-$RELEASE_ID"
  run_root rm -f "$next_link"
  run_root ln -s "$NEW_WEBROOT" "$next_link"
  previous_kind="$(state_read previous_kind)"

  if [ "$previous_kind" = symlink ]; then
    run_root mv -Tf "$next_link" "$LIVE_WEBROOT"
  else
    bootstrap_parent="$RELEASES_DIR/bootstrap-$(date +%Y%m%d%H%M%S)-$RELEASE_ID"
    bootstrap_webroot="$bootstrap_parent/dist"
    run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$bootstrap_parent"
    run_root mv "$LIVE_WEBROOT" "$bootstrap_webroot"
    state_write previous_target "$bootstrap_webroot"
    if ! run_root mv -T "$next_link" "$LIVE_WEBROOT"; then
      run_root mv "$bootstrap_webroot" "$LIVE_WEBROOT"
      fail "Initial public_html release-pointer bootstrap failed and was restored."
    fi
  fi

  active_target="$(readlink -f "$LIVE_WEBROOT")"
  [ "$active_target" = "$NEW_WEBROOT" ] ||
    fail "Atomic activation target mismatch: $active_target"
  # The live webroot is now a root-owned symlink. chown -h it so LiteSpeed's
  # suEXEC docroot-ownership check sees the site user as owner.
  run_root chown -h "$SITE_USER:$SITE_GROUP" "$LIVE_WEBROOT"
  state_write activated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}

rollback_webroot() {
  [ -d "$STATE_DIR" ] || return 0
  previous_kind="$(state_read previous_kind 2>/dev/null || true)"
  previous_target="$(state_read previous_target 2>/dev/null || true)"
  current_target=""
  if [ -L "$LIVE_WEBROOT" ]; then
    current_target="$(readlink -f "$LIVE_WEBROOT")"
  fi

  # First-deploy bootstrap never reached activate_webroot: previous_target was
  # never written and public_html is still the original directory. Nothing to
  # restore — completing the rollback is the correct outcome.
  if [ -z "$previous_target" ]; then
    if [ -d "$LIVE_WEBROOT" ] && [ ! -L "$LIVE_WEBROOT" ]; then
      echo "No previous webroot was recorded; public_html is still the original directory."
      return 0
    fi
    fail "Rollback required but no previous webroot was recorded and public_html is not the original directory."
  fi

  if [ "$previous_kind" = symlink ]; then
    [ -d "$previous_target" ] ||
      fail "Previous webroot target is missing (release was pruned?): $previous_target — refusing to declare rollback complete."
    if [ "$current_target" != "$previous_target" ]; then
      rollback_link="$PRIVATE_ROOT/.public_html.rollback-$RELEASE_ID"
      run_root rm -f "$rollback_link"
      run_root ln -s "$previous_target" "$rollback_link"
      run_root mv -Tf "$rollback_link" "$LIVE_WEBROOT"
      run_root chown -h "$SITE_USER:$SITE_GROUP" "$LIVE_WEBROOT"
    fi
  elif [ "$previous_kind" = directory ]; then
    [ -d "$previous_target" ] ||
      fail "Previous bootstrap webroot is missing: $previous_target — refusing to declare rollback complete."
    case "$previous_target" in
      "$RELEASES_DIR"/bootstrap-*/dist) ;;
      *) fail "Refusing unsafe bootstrap rollback target: $previous_target" ;;
    esac
    if [ -L "$LIVE_WEBROOT" ]; then
      run_root rm "$LIVE_WEBROOT"
      run_root mv "$previous_target" "$LIVE_WEBROOT"
    fi
  fi
}

# Restores the outgoing release root to the mode recorded before the freeze.
# It is idempotent and returns every failure instead of exiting, because the
# activation trap calls rollback_release() where errexit does not apply.
restore_outgoing_release() {
  previous_kind="$(state_read previous_kind)" || {
    echo "ERROR: The rollback target has no previous webroot kind: $STATE_DIR" >&2
    return 1
  }
  case "$previous_kind" in
    directory) return 0 ;;
    symlink) ;;
    *)
      echo "ERROR: Unknown previous webroot kind: $previous_kind" >&2
      return 1
      ;;
  esac
  outgoing_release_dir="$(state_read outgoing_release_dir)" || {
    echo "ERROR: The rollback target has no outgoing release root: $STATE_DIR" >&2
    return 1
  }
  outgoing_release_mode="$(state_read outgoing_release_mode)" || {
    echo "ERROR: The rollback target has no outgoing release mode: $STATE_DIR" >&2
    return 1
  }
  if [ ! -e "$outgoing_release_dir" ] && [ ! -L "$outgoing_release_dir" ]; then
    echo "WARN: The outgoing release root no longer exists; there is no mode to restore: $outgoing_release_dir" >&2
    return 0
  fi
  if ! run_policy_as_site kssmi_policy_restore_directory_mode "$outgoing_release_dir" "$outgoing_release_mode"; then
    echo "ERROR: Could not restore mode $outgoing_release_mode on the outgoing release root: $outgoing_release_dir" >&2
    return 1
  fi
  echo "Outgoing release root mode restored to $outgoing_release_mode: $outgoing_release_dir"
}

rollback_release() {
  cleanup_runtime_capability_probe
  [ -d "$STATE_DIR" ] || {
    echo "No release state exists; rollback is not required: $RELEASE_ID"
    return 0
  }
  if [ -f "$STATE_DIR/rolled_back_at" ]; then
    clear_cutover_markers
    echo "Release was already rolled back: $RELEASE_ID"
    return 0
  fi
  # The activation trap calls this as `rollback_release || true`, where
  # errexit does not apply, so each KS-05 step checks its own status. A
  # failure returns before rolled_back_at is written, so the workflow's
  # separate rollback command tries again instead of reporting it done.
  if [ ! -f "$STATE_DIR/rollback_target_recorded" ]; then
    # Activation changes nothing before the rollback target is recorded.
    # Later state without that record means something did change.
    for later_state in outgoing_release_frozen admin_credential_generation_action \
        private_installed_at activated_at; do
      if [ -e "$STATE_DIR/$later_state" ]; then
        echo "ERROR: Release state has $later_state but no recorded rollback target; refusing to declare rollback complete: $RELEASE_ID" >&2
        return 1
      fi
    done
    state_write rolled_back_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" || return 1
    echo "Release rollback completed; nothing had changed before the rollback target was recorded: $RELEASE_ID"
    return 0
  fi
  restore_outgoing_release || return 1
  changed_runtime=no
  if [ -f "$STATE_DIR/private_installed_at" ] || [ -f "$STATE_DIR/activated_at" ]; then
    changed_runtime=yes
  fi
  if [ "$changed_runtime" = yes ]; then
    write_cutover_markers
    restart_live_php_workers
    prove_cutover_barriers non-fatal || true
    sleep 35
  fi
  rollback_webroot
  restore_shared_private
  clear_cutover_markers
  state_write rolled_back_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "Release rollback completed: $RELEASE_ID"
}

# Activation failure handler. It lives at top level rather than inside
# activate_release so the deployment tests can install the exact same trap.
rollback_on_failure() {
  status=$?
  trap - EXIT HUP INT TERM
  cleanup_runtime_capability_probe
  if [ "$status" -ne 0 ]; then
    rollback_release || true
  fi
  exit "$status"
}

activate_release() {
  check_disk_space
  validate_release_bundle
  prepare_persistent_storage
  [ ! -e "$STATE_DIR" ] || fail "Release state already exists: $STATE_DIR"
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_DIR"

  trap rollback_on_failure EXIT
  trap 'exit 129' HUP
  trap 'exit 130' INT
  trap 'exit 143' TERM

  prepare_admin_credential_transition
  prepare_release_layout
  verify_release_permission_policy
  validate_cloudflare_snapshot_pair \
    "$RELEASE_DIR/private/rate-limit.php" \
    "$RELEASE_DIR/private/cloudflare-ip-ranges.json" \
    "Release"
  backup_shared_private
  record_cutover_baseline
  write_cutover_markers
  restart_live_php_workers
  prove_cutover_barriers
  sleep 35

  install_shared_private
  state_write private_installed_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  migrate_email_history
  probe_vjt_integrity
  verify_persistent_permission_policy
  state_write permission_policy PASS
  probe_real_runtime_capabilities
  activate_webroot
  clear_cutover_markers
  echo "Email log cutover barrier released: OK"

  trap - EXIT HUP INT TERM
  echo "Versioned release activated: $RELEASE_ID"
}

check_disk_space() {
  # Fail fast before any mutation when the server cannot hold the new release
  # alongside the current one. Mirrors the pre-upload check in the deploy
  # action (6G minimum) so a near-full disk fails with a clear message
  # instead of surfacing later as SQLite disk I/O errors.
  avail_mb="$(df -P -B 1M -- "$PRIVATE_ROOT" | awk 'NR==2 {print $4}')"
  avail_gb="$(( avail_mb / 1024 ))"
  echo "Free disk on $PRIVATE_ROOT: ${avail_gb}G (minimum required: 6G)"
  [ "$avail_gb" -ge 6 ] || fail "Insufficient disk space (${avail_gb}G < 6G). Free space before deploying."
}

finalize_release() {
  [ -d "$STATE_DIR" ] || fail "Release state is missing."
  [ "$AUTHENTICATED_SMOKE_VERIFIED" = true ] ||
    fail "Authenticated admin smoke proof is required before finalization."
  active_target="$(readlink -f "$LIVE_WEBROOT")"
  [ "$active_target" = "$NEW_WEBROOT" ] ||
    fail "Cannot finalize a release that is not active."
  clear_cutover_markers
  echo "Email log cutover barrier released: OK"
  state_write authenticated_admin_smoke PASS
  state_write finalized_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  run_root sh -c "umask 0027; printf '%s\n' '$RELEASE_ID' > '$CURRENT_RELEASE_MARKER.tmp'; chown '$SITE_USER:$SITE_GROUP' '$CURRENT_RELEASE_MARKER.tmp'; chmod 640 '$CURRENT_RELEASE_MARKER.tmp'; mv -f '$CURRENT_RELEASE_MARKER.tmp' '$CURRENT_RELEASE_MARKER'"
  echo "Release finalized after $DEPLOY_ENVIRONMENT smoke: $RELEASE_ID"
  # Old releases are pruned by scripts/prune-old-releases.sh (invoked by the
  # deploy action after finalize, keeping the newest release only). The
  # release manager itself never performs recursive deletes.
}

emit_release_evidence() {
  [ -d "$STATE_DIR" ] || fail "Release evidence state is missing."
  [ -f "$STATE_DIR/finalized_at" ] || fail "Release is not finalized."
  [ ! -f "$STATE_DIR/rolled_back_at" ] || fail "A rolled-back release cannot be accepted."
  [ "$(state_read permission_policy)" = PASS ] || fail "Permission policy proof is missing."
  [ "$(state_read authenticated_admin_smoke)" = PASS ] || fail "Admin smoke proof is missing."

  active_target="$(readlink -f "$LIVE_WEBROOT")"
  [ "$active_target" = "$NEW_WEBROOT" ] || fail "Finalized release is not the active webroot."
  [ -f "$CURRENT_RELEASE_MARKER" ] || fail "Current release marker is missing."
  current_release_id="$(run_as_site cat "$CURRENT_RELEASE_MARKER")"
  [ "$current_release_id" = "$RELEASE_ID" ] || fail "Current release marker does not match."

  runtime_response="$(state_read runtime_capabilities)"
  printf '%s\n' "$runtime_response" | grep -Fxq 'KSSMI_RUNTIME_CAPABILITY_V1' ||
    fail "Runtime capability proof marker is missing."
  runtime_uid="$(printf '%s\n' "$runtime_response" | awk -F= '$1 == "uid" { print $2; exit }')"
  runtime_gid="$(printf '%s\n' "$runtime_response" | awk -F= '$1 == "gid" { print $2; exit }')"
  printf '%s' "$runtime_uid" | grep -Eq '^[0-9]+$' || fail "Runtime UID proof is invalid."
  printf '%s' "$runtime_gid" | grep -Eq '^[0-9]+$' || fail "Runtime GID proof is invalid."
  previous_release_id="$(state_read previous_current_release 2>/dev/null || printf 'NONE')"
  previous_target="$(state_read previous_target)"

  echo 'KSSMI_DEPLOYMENT_EVIDENCE_V1'
  echo "environment=$DEPLOY_ENVIRONMENT"
  echo "release_id=$RELEASE_ID"
  echo "previous_release_id=$previous_release_id"
  echo "before_symlink_target=$previous_target"
  echo "after_symlink_target=$active_target"
  echo "current_release_id=$current_release_id"
  echo "site_user=$SITE_USER"
  echo "site_group=$SITE_GROUP"
  echo "runtime_uid=$runtime_uid"
  echo "runtime_gid=$runtime_gid"
  echo 'permission_policy=PASS'
  echo 'authenticated_admin_smoke=PASS'
  for capability in \
    private_modules_read password_hash_read gsc_read_if_present \
    email_atomic_write rate_limit_atomic_write \
    sqlite_transaction_rollback sqlite_wal_shm_modes; do
    printf '%s\n' "$runtime_response" | grep -Fxq "$capability=PASS" ||
      fail "Runtime capability evidence failed: $capability"
    echo "$capability=PASS"
  done
  echo "runtime_capabilities_sha256=$(printf '%s\n' "$runtime_response" | sha256sum | awk '{print $1}')"
  echo "activated_at=$(state_read activated_at)"
  echo "finalized_at=$(state_read finalized_at)"
  echo 'final_health=PASS'
}

# Executed directly, the release manager validates its production contract,
# takes the deploy lock and runs one command. Sourced by the deployment tests,
# it only defines the functions above and touches nothing.
main() {
  validate_deployment_config

  [ -f "$PERMISSION_POLICY" ] || fail "Permission policy is missing: $PERMISSION_POLICY"
  # shellcheck source=permission-policy.sh
  . "$PERMISSION_POLICY"

  trap 'report_failure "$LINENO" "$BASH_COMMAND"' ERR

  validate_release_id
  prepare_control_paths
  run_root touch "$DEPLOY_LOCK"
  run_root chown "$SITE_USER:$SITE_GROUP" "$DEPLOY_LOCK"
  run_root chmod 640 "$DEPLOY_LOCK"
  exec 9>"$DEPLOY_LOCK"
  flock -x 9

  case "$COMMAND" in
    activate) activate_release ;;
    rollback) rollback_release ;;
    finalize) finalize_release ;;
    evidence) emit_release_evidence ;;
    *) fail "Usage: deploy-release.sh {activate|rollback|finalize|evidence}" ;;
  esac
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
