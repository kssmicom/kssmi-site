# OpenLiteSpeed runtime identity and capability gate

`SITE_USER=kssmi4374` is a deployment expectation, not proof of the account
that handles production PHP requests. Every activation therefore sends a real
loopback HTTP request through OpenLiteSpeed and its configured LSAPI handler.
The returned effective UID/GID must exactly match the numeric UID/GID of the
configured site user and group.

The one-shot probe is stored in the immutable release under `scripts/`, not in
`dist`. During activation it is copied temporarily into the current webroot as
a non-executable `0644` PHP file. It answers only when both conditions hold:

- `REMOTE_ADDR` is `127.0.0.1` or `::1`;
- the request supplies the exact current release identifier.

All other requests receive `403` with `Cache-Control: no-store`. The deployment
removes the exact probe filename after the request and also invokes cleanup in
the activation failure and rollback paths, so no public diagnostic endpoint is
retained.

The LSAPI process itself must prove these capabilities without printing any
secret value:

1. read the persistent `private_config.php`, all shared PHP modules and the
   verified Cloudflare snapshot;
2. read the password hash and, when present, the GSC credential;
3. create → chmod `0600` → flush/fsync → atomic rename → delete in the email
   and rate-limit directories;
4. open the VJT SQLite database, run `integrity_check`, perform a controlled
   transaction write and rollback, prove no probe table remains, and verify
   the database plus any WAL/SHM sidecars remain `0600`.

The sanitized result is printed in deployment logs and written to the private
release state as `runtime_capabilities` with mode `0640`. Any UID/GID mismatch
or failed capability aborts the release before `public_html` is switched.
