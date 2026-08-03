#!/usr/bin/env bash
# Reusable Kssmi filesystem permission and ownership policy.
#
# This file is sourced by deploy-release.sh and exercised independently by
# test-permission-policy.sh. It intentionally uses GNU stat/find semantics,
# matching the Ubuntu production host and GitHub Actions runners.

KSSMI_PERMISSION_POLICY_VERSION=1

kssmi_policy_error() {
  printf 'PERMISSION POLICY: %s\n' "$*" >&2
  return 1
}

kssmi_policy_assert_path() {
  local path="$1"
  local expected_type="$2"
  local expected_mode="$3"
  local expected_uid="$4"
  local expected_gid="$5"
  local actual_mode actual_uid actual_gid

  case "$expected_type" in
    directory) [ -d "$path" ] && [ ! -L "$path" ] || kssmi_policy_error "expected directory: $path" || return 1 ;;
    file) [ -f "$path" ] && [ ! -L "$path" ] || kssmi_policy_error "expected regular file: $path" || return 1 ;;
    symlink) [ -L "$path" ] || kssmi_policy_error "expected symlink: $path" || return 1 ;;
    *) kssmi_policy_error "unknown path type '$expected_type': $path"; return 1 ;;
  esac

  actual_mode="$(stat -c '%a' -- "$path")" || return 1
  actual_uid="$(stat -c '%u' -- "$path")" || return 1
  actual_gid="$(stat -c '%g' -- "$path")" || return 1
  [ "$expected_mode" = any ] || [ "$actual_mode" = "$expected_mode" ] || {
    kssmi_policy_error "$path mode is $actual_mode; expected $expected_mode"
    return 1
  }
  [ "$actual_uid" = "$expected_uid" ] || {
    kssmi_policy_error "$path uid is $actual_uid; expected $expected_uid"
    return 1
  }
  [ "$actual_gid" = "$expected_gid" ] || {
    kssmi_policy_error "$path gid is $actual_gid; expected $expected_gid"
    return 1
  }
}

kssmi_policy_assert_tree() {
  local root="$1"
  local directory_mode="$2"
  local file_mode="$3"
  local expected_uid="$4"
  local expected_gid="$5"
  local label="$6"
  local path unexpected_link unexpected_special

  kssmi_policy_assert_path "$root" directory "$directory_mode" "$expected_uid" "$expected_gid" || return 1

  while IFS= read -r -d '' path; do
    kssmi_policy_assert_path "$path" directory "$directory_mode" "$expected_uid" "$expected_gid" || return 1
  done < <(find -P "$root" -mindepth 1 -type d -print0)

  while IFS= read -r -d '' path; do
    kssmi_policy_assert_path "$path" file "$file_mode" "$expected_uid" "$expected_gid" || return 1
  done < <(find -P "$root" -type f -print0)

  unexpected_link="$(find -P "$root" -type l -print -quit)"
  [ -z "$unexpected_link" ] || {
    kssmi_policy_error "$label contains a symlink: $unexpected_link"
    return 1
  }
  unexpected_special="$(find -P "$root" -mindepth 1 ! -type d ! -type f ! -type l -print -quit)"
  [ -z "$unexpected_special" ] || {
    kssmi_policy_error "$label contains an unsupported special file: $unexpected_special"
    return 1
  }
}

kssmi_policy_reject_world_writable() {
  local root="$1"
  local offending
  offending="$(find -P "$root" \( -type d -o -type f \) -perm -0002 -print -quit)"
  [ -z "$offending" ] || {
    kssmi_policy_error "world-writable path rejected: $offending"
    return 1
  }
}

kssmi_policy_reject_executable_php() {
  local root="$1"
  local offending
  offending="$(find -P "$root" -type f -name '*.php' -perm /0111 -print -quit)"
  [ -z "$offending" ] || {
    kssmi_policy_error "executable PHP source rejected: $offending"
    return 1
  }
}

kssmi_policy_reject_world_readable_files() {
  local root="$1"
  local offending
  offending="$(find -P "$root" -type f -perm -0004 -print -quit)"
  [ -z "$offending" ] || {
    kssmi_policy_error "world-readable private file rejected: $offending"
    return 1
  }
}

kssmi_policy_assert_symlink() {
  local link_path="$1"
  local expected_target="$2"
  local expected_uid="$3"
  local expected_gid="$4"
  local actual_target resolved_target expected_resolved

  kssmi_policy_assert_path "$link_path" symlink any "$expected_uid" "$expected_gid" || return 1
  actual_target="$(readlink -- "$link_path")" || return 1
  [ "$actual_target" = "$expected_target" ] || {
    kssmi_policy_error "$link_path points to $actual_target; expected $expected_target"
    return 1
  }
  resolved_target="$(readlink -f -- "$link_path")" || {
    kssmi_policy_error "symlink target does not resolve: $link_path"
    return 1
  }
  expected_resolved="$(readlink -f -- "$expected_target")" || {
    kssmi_policy_error "expected symlink target does not resolve: $expected_target"
    return 1
  }
  [ "$resolved_target" = "$expected_resolved" ] || {
    kssmi_policy_error "$link_path resolves outside its approved target"
    return 1
  }
}

kssmi_policy_assert_symlink_allowlist() {
  local root="$1"
  local expected_uid="$2"
  local expected_gid="$3"
  local expected_links=""
  local link_path target_path
  shift 3
  [ $(( $# % 2 )) -eq 0 ] || {
    kssmi_policy_error 'symlink allowlist requires link/target pairs'
    return 1
  }

  while [ "$#" -gt 0 ]; do
    link_path="$1"
    target_path="$2"
    shift 2
    case "$link_path" in
      "$root"/*) ;;
      *) kssmi_policy_error "allowlisted link is outside release root: $link_path"; return 1 ;;
    esac
    kssmi_policy_assert_symlink "$link_path" "$target_path" "$expected_uid" "$expected_gid" || return 1
    expected_links="${expected_links}${expected_links:+
}$link_path"
  done

  while IFS= read -r -d '' link_path; do
    if ! printf '%s\n' "$expected_links" | grep -Fqx -- "$link_path"; then
      kssmi_policy_error "unexpected or out-of-bound release symlink: $link_path -> $(readlink -- "$link_path")"
      return 1
    fi
  done < <(find -P "$root" -type l -print0)
}

kssmi_policy_assert_sensitive_file() {
  local path="$1"
  local expected_uid="$2"
  local expected_gid="$3"
  [ -e "$path" ] || return 0
  kssmi_policy_assert_path "$path" file 600 "$expected_uid" "$expected_gid"
}
