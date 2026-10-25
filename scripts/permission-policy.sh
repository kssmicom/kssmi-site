#!/usr/bin/env bash
# Reusable Kssmi filesystem permission and ownership policy.
#
# This file is sourced by deploy-release.sh and exercised independently by
# test-permission-policy.sh. It intentionally uses GNU stat/find semantics,
# matching the Ubuntu production host and GitHub Actions runners.

KSSMI_PERMISSION_POLICY_VERSION=2

kssmi_policy_error() {
  printf 'PERMISSION POLICY: %s\n' "$*" >&2
  return 1
}

kssmi_policy_warn() {
  printf 'PERMISSION POLICY WARNING: %s\n' "$*" >&2
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

  # One find pass per type selects the first entry whose mode, owner or group
  # differs, instead of three stat processes per path (a release has ~25k
  # paths). -perm with a bare octal mode compares all mode bits exactly, as
  # stat %a does. The per-path check then reports that entry with its usual
  # message; if it unexpectedly passes, the tree still fails closed.
  case "$directory_mode$file_mode" in
    *[!0-7]*) kssmi_policy_error "$label tree modes must be octal: $directory_mode $file_mode"; return 1 ;;
  esac
  path=''
  IFS= read -r -d '' path < <(find -P "$root" -mindepth 1 -type d \
    \( ! -perm "$directory_mode" -o ! -uid "$expected_uid" -o ! -gid "$expected_gid" \) -print0 -quit) || true
  if [ -n "$path" ]; then
    kssmi_policy_assert_path "$path" directory "$directory_mode" "$expected_uid" "$expected_gid" || return 1
    kssmi_policy_error "$label directory does not match its policy: $path"
    return 1
  fi
  IFS= read -r -d '' path < <(find -P "$root" -type f \
    \( ! -perm "$file_mode" -o ! -uid "$expected_uid" -o ! -gid "$expected_gid" \) -print0 -quit) || true
  if [ -n "$path" ]; then
    kssmi_policy_assert_path "$path" file "$file_mode" "$expected_uid" "$expected_gid" || return 1
    kssmi_policy_error "$label file does not match its policy: $path"
    return 1
  fi

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

# ── Admin credential state (KS-05) ──────────────────────────────────────────
# The password hash, the reset tokens and the credential generation exist only
# as persistent files; a release holds exact links to them. Some checks below
# read file contents, so the deployment runs them as the site account that
# owns the files. Messages name paths and reasons, never contents.

kssmi_policy_assert_credential_link() {
  local link_path="$1"
  local expected_target="$2"
  local expected_uid="$3"
  local expected_gid="$4"
  local actual_target

  kssmi_policy_assert_path "$link_path" symlink any "$expected_uid" "$expected_gid" || return 1
  actual_target="$(readlink -- "$link_path")" || return 1
  [ "$actual_target" = "$expected_target" ] || {
    kssmi_policy_error "$link_path points to $actual_target; expected $expected_target"
    return 1
  }
  kssmi_policy_assert_path "$expected_target" file 600 "$expected_uid" "$expected_gid"
}

# True only when the file holds exactly 32 lowercase hex characters, with no
# newline or other byte, which is what PHP and the deployment write.
kssmi_policy_credential_generation_valid() {
  local path="$1"
  local size
  [ -f "$path" ] || return 1
  size="$(wc -c < "$path")" || return 1
  [ "$size" -eq 32 ] || return 1
  LC_ALL=C grep -Eq '^[a-f0-9]{32}$' -- "$path"
}

kssmi_policy_credential_generation_state() {
  local path="$1"
  if [ ! -e "$path" ] && [ ! -L "$path" ]; then
    printf 'missing\n'
  elif [ ! -L "$path" ] && kssmi_policy_credential_generation_valid "$path"; then
    printf 'valid\n'
  else
    printf 'invalid\n'
  fi
}

# Classifies the credential paths in the outgoing release root: "migrated"
# when it links the persistent generation, "prefix" when it predates the
# generation. Every state that needs a person to decide fails.
kssmi_policy_outgoing_credential_layout() {
  local release="$1"
  local password_file="$2"
  local tokens_file="$3"
  local generation_file="$4"
  local expected_uid="$5"
  local expected_gid="$6"
  local path

  path="$release/.email_logs_password"
  if [ -L "$path" ]; then
    kssmi_policy_assert_credential_link "$path" "$password_file" "$expected_uid" "$expected_gid" || return 1
  elif [ -f "$path" ]; then
    # The pre-fix code replaced the link when the password was changed.
    cmp -s -- "$path" "$password_file" || {
      kssmi_policy_error "$path is a regular file that does not match $password_file; decide which password to keep"
      return 1
    }
    kssmi_policy_warn "$path is a regular file identical to $password_file"
  elif [ -e "$path" ]; then
    kssmi_policy_error "$path is neither a link nor a regular file"
    return 1
  else
    kssmi_policy_error "$path is missing"
    return 1
  fi

  path="$release/.email_reset_tokens.json"
  if [ -L "$path" ]; then
    kssmi_policy_assert_credential_link "$path" "$tokens_file" "$expected_uid" "$expected_gid" || return 1
  elif [ -f "$path" ]; then
    kssmi_policy_warn "$path is a regular file; its unused reset links do not carry over"
  elif [ -e "$path" ]; then
    kssmi_policy_error "$path is neither a link nor a regular file"
    return 1
  else
    kssmi_policy_error "$path is missing"
    return 1
  fi

  path="$release/.admin_credential_generation"
  if [ -L "$path" ]; then
    kssmi_policy_assert_credential_link "$path" "$generation_file" "$expected_uid" "$expected_gid" || return 1
    kssmi_policy_credential_generation_valid "$generation_file" || {
      kssmi_policy_error "$generation_file does not hold a valid credential generation"
      return 1
    }
    printf 'migrated\n'
    return 0
  elif [ -f "$path" ]; then
    # The fixed code writes the generation only through the link.
    kssmi_policy_error "$path is a regular file in the release root"
    return 1
  elif [ -e "$path" ]; then
    kssmi_policy_error "$path is neither a link nor a regular file"
    return 1
  fi

  # The pre-fix code kept its version counter in the release root and never
  # linked it; a missing counter only means nobody logged in.
  path="$release/.admin_credential_version"
  if [ -L "$path" ]; then
    kssmi_policy_error "$path is a link"
    return 1
  elif [ -e "$path" ] && [ ! -f "$path" ]; then
    kssmi_policy_error "$path is neither a link nor a regular file"
    return 1
  fi
  printf 'prefix\n'
}

# A migrated layout keeps a valid persistent generation and stops on a lost
# or damaged one. A pre-fix or first layout always gets a fresh generation,
# retries included, so no session or reset link issued before survives.
kssmi_policy_generation_action() {
  local layout="$1"
  local generation_state="$2"

  case "$generation_state" in
    valid|invalid|missing) ;;
    *) kssmi_policy_error "unknown credential generation state '$generation_state'"; return 1 ;;
  esac
  case "$layout" in
    migrated)
      [ "$generation_state" = valid ] || {
        kssmi_policy_error "the persistent credential generation is $generation_state in a migrated layout; restore it manually"
        return 1
      }
      printf 'keep\n'
      ;;
    prefix|initial) printf 'rotate\n' ;;
    *) kssmi_policy_error "unknown credential layout '$layout'"; return 1 ;;
  esac
}

# The pre-fix code creates temporary and lock files beside each credential in
# the release root, so it fails closed once its owner cannot write there.
kssmi_policy_freeze_directory() {
  local directory="$1"
  [ -d "$directory" ] && [ ! -L "$directory" ] || {
    kssmi_policy_error "expected directory: $directory"
    return 1
  }
  chmod -- u-w "$directory"
}

kssmi_policy_restore_directory_mode() {
  local directory="$1"
  local mode="$2"
  case "$mode" in
    [01234567][01234567][01234567]|[01234567][01234567][01234567][01234567]) ;;
    *) kssmi_policy_error "invalid recorded mode '$mode' for $directory"; return 1 ;;
  esac
  [ -d "$directory" ] && [ ! -L "$directory" ] || {
    kssmi_policy_error "expected directory: $directory"
    return 1
  }
  chmod -- "$mode" "$directory"
}
