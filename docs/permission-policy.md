# Kssmi permission and ownership policy

This contract is enforced twice: statically in CI and against the actual
filesystem during release activation. A failed check aborts activation before
the live `public_html` pointer changes.

| Object | Directory / file mode | Ownership |
| --- | --- | --- |
| Public release tree, including PHP source | `0755 / 0644`; PHP is never executable | `kssmi4374:kssmi4374` |
| Private release tree and shared PHP/Cloudflare modules | `0750 / 0640` | `kssmi4374:kssmi4374` |
| Release/permission shell scripts; temporary LSAPI probe source | `0750`; probe PHP `0640` and non-executable | `kssmi4374:kssmi4374` |
| `private_config.php`, password hash, reset tokens, GSC JSON, rate-limit files, SQLite/WAL/SHM | private directory / `0600` | `kssmi4374:kssmi4374` |
| Email data and lock | `0750 / 0640` | `kssmi4374:kssmi4374` |
| Deployment state | `0750` | `kssmi4374:kssmi4374` |

`/home/kssmi.com/releases` and each immutable release root are the only
managed `0751` exceptions. OpenLiteSpeed may run its public-file worker as an
unprivileged account, so it needs the other-execute bit only to traverse these
two parents and reach the public `dist` directory. It receives no directory
listing or read/write permission. Deployment state, shared private modules and
runtime data do not serve this purpose and remain `0750`.

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

`scripts/test-permission-policy.sh` builds a normal representative release and
then deliberately introduces a bad mode, world-write, executable PHP,
world-readable private content, an incorrect owner expectation and an
out-of-bound symlink. Each mutation must be rejected.
