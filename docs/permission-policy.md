# Kssmi permission and ownership policy

Deployment privilege separation and protected entry points are documented in [deploy-fs-safety.md](deploy-fs-safety.md).


This contract is enforced twice: statically in CI and against the actual
filesystem during release activation. A failed check aborts activation before
the live `public_html` pointer changes.

| Object | Directory / file mode | Ownership |
| --- | --- | --- |
| Public release tree, including PHP source | `0755 / 0644`; PHP is never executable | `kssmi4374:kssmi4374` |
| Release root (`releases/<id>`) | `0551`: readable and traversable, but the site account cannot create an entry | `kssmi4374:kssmi4374` |
| Private release tree and shared PHP/Cloudflare modules | `0750 / 0640` | `kssmi4374:kssmi4374` |
| Release/permission shell scripts; temporary LSAPI probe source | `0750`; probe PHP `0640` and non-executable | `kssmi4374:kssmi4374` |
| `private_config.php`, password hash, reset tokens, credential generation and their lock files, GSC JSON, rate-limit files, SQLite/WAL/SHM | private directory / `0600` | `kssmi4374:kssmi4374` |
| Email data and lock | `0750 / 0640` | `kssmi4374:kssmi4374` |
| Deployment state | `0750` | `kssmi4374:kssmi4374` |

`/home/kssmi.com/releases` is the only managed `0751` exception. OpenLiteSpeed
may run its public-file worker as an unprivileged account, so it needs the
other-execute bit only to traverse this parent and each `0551` release root to
reach the public `dist` directory. It receives no directory listing or
read/write permission. Deployment state, shared private modules and runtime
data do not serve this purpose and remain `0750`.

Every release symlink is allowlisted by exact path and exact target. The
deployment rejects missing, unexpected, dangling or out-of-bound symlinks and
checks symlink owner/group without dereferencing it. It also rejects any
world-writable release/runtime path, executable PHP source, world-readable
private file, or unexpected UID/GID.

`/home/kssmi.com/private_config.php` is a persistent server credential file.
It must already exist as a regular `0600` file; deployment never creates an
empty replacement and never bundles it. Each release receives only an exact,
allowlisted `private_config.php` symlink at the release root so PHP files under
`dist` can load it through `dirname(__DIR__)`.

## Admin credentials (KS-05)

The admin password hash (`.email_logs_password`), the reset tokens
(`.email_reset_tokens.json`) and the credential generation
(`.admin_credential_generation`: exactly 32 lowercase hex characters, no
newline) exist only in `/home/kssmi.com`. Each is a `0600` file of the site
account with a `0600` lock file beside it (`<file>.lock`), and each release
root holds an exact symlink to it. PHP resolves the link before every write
and lock, so a password change or reset reaches the persistent file, survives
later deployments, and every release locks the same inode.

- The deployment creates a missing lock file as the site account and publishes
  it with link(2), which never replaces a lock file that PHP created in the
  meantime. An existing lock file is only checked, never replaced.
- The `0551` release root makes any credential written beside the links fail
  instead of landing in a release that the next deployment discards.
- Admin sessions and reset links carry the generation they were issued under
  and are valid only while it is current. A password change or reset writes a
  new random generation; so does the deployment in the cases below.
- The deployment never writes the password hash, and its rollback never
  writes any credential.

### Credential transition during activation

Right after it installs its failure trap, activation records the rollback
target (the live webroot, the outgoing release root and its mode) and marks
it as complete. Only then does it freeze the outgoing release root (owner
write removed, proven as the site account), check the credential paths in that
root, and migrate the generation:

| Outgoing release root | Persistent generation | Deployment |
| --- | --- | --- |
| pre-fix layout (no generation link) or first bootstrap | any | writes a new random generation and clears the reset tokens to `[]`; every admin session and reset link ends, retries included |
| migrated layout (exact generation link) | valid | keeps it |
| migrated layout | missing or invalid | stops; manual recovery below |

During the first deployment with the fix, the admin pages fail closed for the
few minutes between the freeze and activation; public pages are unaffected.
Rollback restores the outgoing release root to its recorded mode before the
webroot pointer, and stops without declaring success if that restore fails.

### Manual recovery when the transition stops

Each step changes production credentials and needs an explicit decision.

- **The outgoing release root holds a different password hash.** The pre-fix
  code replaced the link when a password was changed, so the live admin login
  uses the hash in the outgoing release, while the persistent file still holds
  the old one. The stopped deployment has already restored the outgoing root's
  mode, so the site account can change it. Decide which password stays, run
  the matching command below, set the `SMOKE_ADMIN_PASSWORD` secret to that
  password, and deploy again.
  - *Keep the password changed through the pre-fix code:* copy that hash to
    the persistent file. The outgoing copy is then identical, which the next
    deployment accepts with a warning.
  - *Keep the persistent (old) password:* put the exact link back in place of
    the outgoing copy. The changed hash is discarded, and the live admin login
    uses the old password again at once. Without this step the next
    deployment stops at the same check.

```sh
# Keep the password changed through the pre-fix code.
sudo -u kssmi4374 sh -c '
  set -eu
  umask 077
  outgoing="$(dirname "$(readlink -f /home/kssmi.com/public_html)")"
  case "$outgoing" in /home/kssmi.com/releases/?*) ;; *) exit 1 ;; esac
  [ ! -L "$outgoing/.email_logs_password" ]
  [ -f "$outgoing/.email_logs_password" ]
  cd /home/kssmi.com
  temp="$(mktemp .email_logs_password.manual-XXXXXXXXXX)"
  cat "$outgoing/.email_logs_password" > "$temp"
  mv -Tf "$temp" .email_logs_password
'

# Keep the persistent (old) password.
sudo -u kssmi4374 sh -c '
  set -eu
  outgoing="$(dirname "$(readlink -f /home/kssmi.com/public_html)")"
  case "$outgoing" in /home/kssmi.com/releases/?*) ;; *) exit 1 ;; esac
  cd "$outgoing"
  [ ! -L .email_logs_password ]
  [ -f .email_logs_password ]
  ln -s /home/kssmi.com/.email_logs_password .email_logs_password.relink
  mv -Tf .email_logs_password.relink .email_logs_password
'
```

- **The generation is lost or damaged in a migrated layout.** Write a new
  random generation and clear the reset tokens while holding the reset-token
  lock and then the generation lock, as the deployment does; then deploy
  again. Every admin session and reset link ends.

```sh
sudo -u kssmi4374 sh -c '
  set -eu
  umask 077
  cd /home/kssmi.com
  exec 7<.email_reset_tokens.json.lock
  flock -x 7
  exec 6<.admin_credential_generation.lock
  flock -x 6
  generation="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d " \n")"
  [ "${#generation}" -eq 32 ]
  temp="$(mktemp .email_reset_tokens.json.manual-XXXXXXXXXX)"
  printf "[]" > "$temp"
  mv -Tf "$temp" .email_reset_tokens.json
  temp="$(mktemp .admin_credential_generation.manual-XXXXXXXXXX)"
  printf "%s" "$generation" > "$temp"
  mv -Tf "$temp" .admin_credential_generation
'
```

Before the first deployment with the fix, check read-only that the live
release's credential paths are still links (no password was changed through
the pre-fix code), that `kssmi4374` can write `/home/kssmi.com`, and that any
existing `*.lock` file there is a `0600` regular file of `kssmi4374`.

## Tests

`scripts/test-permission-policy.sh` builds a normal representative release and
then deliberately introduces a bad mode, world-write, executable PHP,
world-readable private content, an incorrect owner expectation and an
out-of-bound symlink. Each mutation must be rejected. Its credential section
covers the exact credential links, every row of the outgoing-layout and
generation tables, and freezing and restoring a release root.

`scripts/test-deploy-credential-transition.sh` runs the real activation
transition and failure trap of `deploy-release.sh` against a temporary copy of
the server layout: failures before and after the freeze and after the
migration, retries, rollback after activation, a failed restore, concurrent
lock files, and writes from a copy of the pre-fix code
(`scripts/fixtures/prefix-admin-credentials-f9214312.php`) that are in flight
while the release root is frozen. It needs Linux and refuses to run as root.
