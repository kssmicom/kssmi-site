#!/usr/bin/env bash
#
# Versioned Kssmi release activation and rollback.
#
# The workflow uploads an immutable bundle to:
#   /home/kssmi.com/releases/<commit>-<run-attempt>/{dist,private,scripts}
#
# This script keeps runtime data under /home/kssmi.com, activates the public
# webroot with an atomic symlink replacement, and preserves enough state to
# restore both the previous webroot and the previous shared private modules
# when the post-deploy smoke test fails.
#
# Kssmi specifics vs the XinXin reference script:
#   - Only two shared private modules: email-log-store.php, rate-limit.php.
#   - No http-security.php / vjt-db-setup.php / private_config.php yet
#     (those arrive in later stages; their absence is asserted here so a
#     future stage that adds them also updates this script).
#   - VJT data already lives OUTSIDE the webroot (/home/kssmi.com/vjt_data),
#     so there is no VJT cutover barrier and no webroot migration — only a
#     SQLite integrity probe before activation.
#   - The email cutover barrier (send-mail.php / email-log-store.php) is the
#     only application-level write barrier needed.

set -Eeuo pipefail

COMMAND="${1:-}"
RELEASE_ID="${RELEASE_ID:-}"

SITE_USER=kssmi4374
SITE_GROUP=kssmi4374
PRIVATE_ROOT=/home/kssmi.com
RELEASES_DIR="$PRIVATE_ROOT/releases"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
NEW_WEBROOT="$RELEASE_DIR/dist"
LIVE_WEBROOT="$PRIVATE_ROOT/public_html"
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
GSC_JSON="$SHARED_PRIVATE/gsc/google-service-account.json"

# Shared module deployment: private/rate-limit.php,private/email-log-store.php
# are uploaded in the release bundle and atomically installed into
# $SHARED_PRIVATE before the webroot switch.
PRIVATE_MODULES="email-log-store.php rate-limit.php"
RATE_LIMIT_MODULE="$SHARED_PRIVATE/rate-limit.php"
EMAIL_LOG_MODULE="$SHARED_PRIVATE/email-log-store.php"
# Endpoints whose PHP guard consults the email cutover marker (send-mail.php
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

report_failure() {
  echo "ERROR: deploy-release.sh $COMMAND failed at line $1: $2" >&2
}
trap 'report_failure "$LINENO" "$BASH_COMMAND"' ERR

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

validate_release_id() {
  printf '%s' "$RELEASE_ID" | grep -Eq '^[0-9a-f]{40}-[1-9][0-9]*$' ||
    fail "RELEASE_ID must be a full Git commit SHA plus the GitHub run attempt."
}

validate_release_bundle() {
  [ -d "$NEW_WEBROOT" ] || fail "Release webroot is missing: $NEW_WEBROOT"
  [ -d "$RELEASE_DIR/private" ] || fail "Release private directory is missing."
  [ -f "$NEW_WEBROOT/.htaccess" ] || fail "Release .htaccess is missing."
  [ -f "$NEW_WEBROOT/send-mail.php" ] || fail "Release send-mail.php is missing."
  [ -f "$NEW_WEBROOT/api/vjt-helpers.php" ] || fail "Release VJT helper is missing."
  for module in $PRIVATE_MODULES; do
    [ -f "$RELEASE_DIR/private/$module" ] ||
      fail "Release private module is missing: $module"
  done
  # The release must not carry runtime-only credentials or data. GSC JSON is
  # a manual, server-side credential kept in SHARED_PRIVATE/gsc — never in the
  # uploaded bundle.
  for sensitive_path in \
    "$NEW_WEBROOT/.email_logs_password" \
    "$NEW_WEBROOT/.email_reset_tokens.json" \
    "$NEW_WEBROOT/api/vjt_data" \
    "$RELEASE_DIR/private/gsc"; do
    [ ! -e "$sensitive_path" ] && [ ! -L "$sensitive_path" ] ||
      fail "Sensitive path exists in the immutable release: $sensitive_path"
  done
}

prepare_persistent_storage() {
  # 751 (o+x) rather than 750: the LiteSpeed HTTP worker runs as nobody and
  # must traverse RELEASES_DIR to serve the symlinked webroot, and the
  # .htaccess marker checks also run as nobody. Owner/group keep rwx/r-x;
  # other users only get traverse.
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 751 \
    "$RELEASES_DIR" "$STATE_ROOT" "$SHARED_PRIVATE" \
    "$EMAIL_DATA_DIR" "$RATE_LIMIT_DIR" "$VJT_DATA_DIR"
  run_root touch "$PASSWORD_FILE" "$RESET_TOKENS_FILE" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  run_root chown "$SITE_USER:$SITE_GROUP" \
    "$PASSWORD_FILE" "$RESET_TOKENS_FILE" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  # Stable lock-file permissions / Legacy lock compatibility permissions:
  # both locks must be owned by the site account and group-readable only.
  run_root chown "$SITE_USER:$SITE_GROUP" "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
  run_root chmod 600 "$PASSWORD_FILE" "$RESET_TOKENS_FILE"
  run_root chmod 640 "$EMAIL_LOCK" "$LEGACY_EMAIL_LOCK"
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
  # 751: see prepare_persistent_storage — nobody must traverse RELEASES_DIR.
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 751 \
    "$RELEASES_DIR" "$STATE_ROOT"
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
  run_root chmod 750 "$RELEASE_DIR/scripts/deploy-release.sh"

  create_release_link "$RELEASE_DIR/public_html" "$NEW_WEBROOT"
  create_release_link "$RELEASE_DIR/email_data" "$EMAIL_DATA_DIR"
  create_release_link "$RELEASE_DIR/vjt_data" "$VJT_DATA_DIR"
  create_release_link "$RELEASE_DIR/rate_limit" "$RATE_LIMIT_DIR"
  create_release_link "$RELEASE_DIR/.email_logs_password" "$PASSWORD_FILE"
  create_release_link "$RELEASE_DIR/.email_reset_tokens.json" "$RESET_TOKENS_FILE"

  state_write release_webroot "$NEW_WEBROOT"
  run_root sh -c "umask 0027; printf '%s\n' '$RELEASE_ID' > '$RELEASE_DIR/.kssmi-release'; chown '$SITE_USER:$SITE_GROUP' '$RELEASE_DIR/.kssmi-release'; chmod 640 '$RELEASE_DIR/.kssmi-release'"
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
  done
  run_as_site test -r "$RATE_LIMIT_MODULE"
  run_as_site test -r "$EMAIL_LOG_MODULE"
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
      "https://kssmi.com/$endpoint?kssmi_cutover=$RELEASE_ID" || true)"
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
        "https://kssmi.com/$endpoint?kssmi_cutover=$RELEASE_ID" || true)"
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
  # Deployment write/rename smoke test as the real PHP-FPM account.
  echo "Email data atomic-write permissions: OK"
  # Rate-limit module readability + storage permissions as the site account.
  run_as_site test -r "$SHARED_PRIVATE/rate-limit.php"
  run_as_site test -r "$SHARED_PRIVATE/email-log-store.php"
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
  if [ -f "$VJT_DATA_DIR/vjt.sqlite" ]; then
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
    run_as_site "$php_sqlite_bin" "$integrity_probe" "$VJT_DATA_DIR/vjt.sqlite"
    rm -f "$integrity_probe"
  fi
  run_as_site test -w "$VJT_DATA_DIR"
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

rollback_release() {
  [ -d "$STATE_DIR" ] || {
    echo "No release state exists; rollback is not required: $RELEASE_ID"
    return 0
  }
  if [ -f "$STATE_DIR/rolled_back_at" ]; then
    clear_cutover_markers
    echo "Release was already rolled back: $RELEASE_ID"
    return 0
  fi
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

activate_release() {
  validate_release_bundle
  prepare_persistent_storage
  [ ! -e "$STATE_DIR" ] || fail "Release state already exists: $STATE_DIR"
  run_root install -d -o "$SITE_USER" -g "$SITE_GROUP" -m 750 "$STATE_DIR"

  rollback_on_failure() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ]; then
      rollback_release || true
    fi
    exit "$status"
  }
  trap rollback_on_failure EXIT
  trap 'exit 129' HUP
  trap 'exit 130' INT
  trap 'exit 143' TERM

  prepare_release_layout
  record_previous_webroot
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
  activate_webroot
  clear_cutover_markers
  echo "Email log cutover barrier released: OK"

  trap - EXIT HUP INT TERM
  echo "Versioned release activated: $RELEASE_ID"
}

finalize_release() {
  [ -d "$STATE_DIR" ] || fail "Release state is missing."
  active_target="$(readlink -f "$LIVE_WEBROOT")"
  [ "$active_target" = "$NEW_WEBROOT" ] ||
    fail "Cannot finalize a release that is not active."
  clear_cutover_markers
  echo "Email log cutover barrier released: OK"
  state_write finalized_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  run_root sh -c "umask 0027; printf '%s\n' '$RELEASE_ID' > '$PRIVATE_ROOT/.kssmi-current-release.tmp'; chown '$SITE_USER:$SITE_GROUP' '$PRIVATE_ROOT/.kssmi-current-release.tmp'; chmod 640 '$PRIVATE_ROOT/.kssmi-current-release.tmp'; mv -f '$PRIVATE_ROOT/.kssmi-current-release.tmp' '$PRIVATE_ROOT/.kssmi-current-release'"
  echo "Release finalized after production smoke: $RELEASE_ID"
  echo "NOTE: old releases under $RELEASES_DIR and $STATE_ROOT are pruned manually (keep the newest few)."
}

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
  *) fail "Usage: deploy-release.sh {activate|rollback|finalize}" ;;
esac
