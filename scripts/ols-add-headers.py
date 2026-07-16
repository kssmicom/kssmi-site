#!/usr/bin/env python3
"""Install KSSMI browser-cache contexts in the OpenLiteSpeed vhost.

OpenLiteSpeed reads rewrite rules from ``.htaccess``, but it does not implement
Apache ``Header``/``FilesMatch`` directives there. Static response headers must
therefore be configured as native OLS contexts. PHP endpoints set their own
Cache-Control headers in application code and are deliberately not covered by
these static contexts.

Usage on the server:
  sudo python3 /home/kssmi.com/ols-add-headers.py
  sudo /usr/local/lsws/bin/openlitespeed -t
  sudo systemctl restart lsws
"""

import re
import shutil
from datetime import datetime

VHOST_CONF = "/usr/local/lsws/conf/vhosts/kssmi.com/vhost.conf"
BEGIN_MARKER = "# KSSMI MANAGED CACHE CONTEXTS BEGIN"
END_MARKER = "# KSSMI MANAGED CACHE CONTEXTS END"

# Runtime URLs are physical files produced by materialize-runtime-assets.mjs.
# Keep this list intentionally narrow: OLS static contexts can take precedence
# over .htaccess rewrite denials. Broad extension contexts (especially JSON)
# can therefore expose files that security rewrites are meant to forbid.
INSERTION = r"""# KSSMI MANAGED CACHE CONTEXTS BEGIN
context exp:^/$ {
  location                $DOC_ROOT/index.html
  allowBrowse             1
  extraHeaders            <<<END_extraHeaders
unset Cache-Control
set Cache-Control public, max-age=0, must-revalidate, s-maxage=600, stale-while-revalidate=60
END_extraHeaders
  addDefaultCharset       off
}

context exp:^/assets/runtime/.*\.js$ {
  location                $DOC_ROOT/$0
  allowBrowse             1
  extraHeaders            <<<END_extraHeaders
unset Cache-Control
set Cache-Control public, max-age=31536000, immutable
END_extraHeaders
  addDefaultCharset       off
}
# KSSMI MANAGED CACHE CONTEXTS END
"""


def main():
    with open(VHOST_CONF, "r", encoding="utf-8") as file:
        original = file.read()

    content = re.sub(
        rf"{re.escape(BEGIN_MARKER)}.*?{re.escape(END_MARKER)}\s*",
        "",
        original,
        flags=re.DOTALL,
    )

    # Remove the legacy MIME-expiry block if the hosting panel previously
    # created one. It can generate a second, conflicting Cache-Control value.
    content = re.sub(
        r"expires\s*\{.*?enableExpires\s+1.*?\}\s*",
        "",
        content,
        flags=re.DOTALL,
    )

    anchor = "rewrite  {"
    if anchor not in content:
        print(f"ERROR: Could not find {anchor!r} in {VHOST_CONF}; no changes made.")
        return 1

    content = content.replace(anchor, INSERTION + "\n" + anchor, 1)
    if content == original:
        print("OpenLiteSpeed cache contexts are already current; no changes made.")
        return 0

    backup = VHOST_CONF + f".bak.{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    shutil.copy2(VHOST_CONF, backup)
    print(f"Backed up to {backup}")

    with open(VHOST_CONF, "w", encoding="utf-8") as file:
        file.write(content)

    print(f"Updated {VHOST_CONF}")
    print("Validate and restart with:")
    print("  sudo /usr/local/lsws/bin/openlitespeed -t")
    print("  sudo systemctl restart lsws")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
