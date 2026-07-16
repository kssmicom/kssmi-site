#!/usr/bin/env python3
"""
Adds security headers to the OpenLiteSpeed vhost config (kssmi.com).
Cache policy intentionally lives only in public/.htaccess.
Backs up the original config before making changes.

Usage (on server):
  python3 /home/kssmi.com/ols-add-headers.py
  systemctl restart lsws
"""

import shutil
from datetime import datetime

VHOST_CONF = "/usr/local/lsws/conf/vhosts/kssmi.com/vhost.conf"

# ── Config to insert ──────────────────────────────────────────────────────────
# Inserted before the "rewrite  {" block.
# "context /" with extraHeaders applies security headers site-wide.
# No "type static" — that would break PHP.
INSERTION = """\
context / {
  extraHeaders            X-Content-Type-Options:nosniff
  extraHeaders            X-Frame-Options:SAMEORIGIN
  extraHeaders            Referrer-Policy:strict-origin-when-cross-origin
  extraHeaders            Strict-Transport-Security:max-age=31536000; includeSubDomains
  extraHeaders            Cross-Origin-Opener-Policy:same-origin
  extraHeaders            Cross-Origin-Resource-Policy:same-origin
  extraHeaders            Permissions-Policy:camera=()\\, microphone=()\\, geolocation=()\\, interest-cohort=()
  # CSP is intentionally managed by public/.htaccess. A site-wide OLS CSP
  # would break the static Astro hash policy and the separately scoped admin UI.
}

"""


def main():
    # Backup
    backup = VHOST_CONF + f".bak.{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(VHOST_CONF, backup)
    print(f"Backed up to {backup}")

    with open(VHOST_CONF, "r") as f:
        content = f.read()

    # Guard against double-insertion. Also remove the legacy OLS expires block:
    # public/.htaccess is now the only cache-policy source.
    import re
    content = re.sub(
        r"expires\s*\{.*?enableExpires\s+1.*?\}\s*",
        "",
        content,
        flags=re.DOTALL,
    )

    if "X-Content-Type-Options:nosniff" in content:
        print("Headers already present in config. Removing old block first...")
        content = re.sub(
            r"context\s+/\s*\{.*?Permissions-Policy.*?\}\s*",
            "",
            content,
            flags=re.DOTALL,
        )
        # Clean up extra blank lines
        content = re.sub(r"\n{3,}", "\n\n", content)

    # Insert before the "rewrite  {" block
    if "rewrite  {" in content:
        content = content.replace("rewrite  {", INSERTION + "\nrewrite  {")
        print("Inserted config before 'rewrite' block.")
    else:
        print("ERROR: Could not find 'rewrite  {' in config. Aborting.")
        return 1

    with open(VHOST_CONF, "w") as f:
        f.write(content)

    print(f"Updated {VHOST_CONF}")
    print("Done. Now run: systemctl restart lsws")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
