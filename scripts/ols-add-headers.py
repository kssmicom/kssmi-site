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

import argparse
import base64
import hashlib
import json
import os
import re
import stat
import tempfile

VHOST_CONF = "/usr/local/lsws/conf/vhosts/kssmi.com/vhost.conf"
DEFAULT_MANIFEST = "/home/kssmi.com/public_html/assets/runtime/manifest.json"
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


def validate_runtime_manifest(manifest_path):
    with open(manifest_path, "r", encoding="utf-8") as file:
        manifest = json.load(file)
    if set(manifest) != {"schema_version", "assets"} or manifest["schema_version"] != 1:
        raise ValueError("runtime manifest schema is invalid")
    if not isinstance(manifest["assets"], dict) or not manifest["assets"]:
        raise ValueError("runtime manifest assets must be a non-empty object")

    manifest_dir = os.path.dirname(os.path.realpath(manifest_path))
    for logical_name, asset in manifest["assets"].items():
        if set(asset) != {"url", "file_name", "sha256", "integrity", "bytes"}:
            raise ValueError(f"runtime asset {logical_name} has invalid fields")
        digest = asset["sha256"]
        expected_name = f"{logical_name}.{digest[:12]}.js"
        if not re.fullmatch(r"[a-f0-9]{64}", digest) or asset["file_name"] != expected_name:
            raise ValueError(f"runtime asset {logical_name} has an invalid fingerprint")
        if asset["url"] != f"/assets/runtime/{expected_name}":
            raise ValueError(f"runtime asset {logical_name} escaped the OLS runtime context")
        asset_path = os.path.join(manifest_dir, expected_name)
        with open(asset_path, "rb") as file:
            content = file.read()
        actual_digest = hashlib.sha256(content).hexdigest()
        actual_integrity = "sha256-" + base64.b64encode(hashlib.sha256(content).digest()).decode("ascii")
        if actual_digest != digest or actual_integrity != asset["integrity"]:
            raise ValueError(f"runtime asset {logical_name} digest does not match the manifest")
        if len(content) != asset["bytes"]:
            raise ValueError(f"runtime asset {logical_name} byte count does not match the manifest")
    return manifest


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--check-manifest",
        metavar="PATH",
        help="validate a built runtime manifest and files without changing OLS",
    )
    args = parser.parse_args()
    manifest_path = args.check_manifest or DEFAULT_MANIFEST
    try:
        manifest = validate_runtime_manifest(manifest_path)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"ERROR: Runtime asset manifest validation failed: {error}")
        return 1
    print(f"Validated {len(manifest['assets'])} runtime assets from {manifest_path}")
    if args.check_manifest:
        return 0

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

    # Write a replacement beside the live file, then atomically swap it in.
    # This preserves a valid vhost.conf at all times without accumulating
    # .bak files on the production server.
    original_stat = os.stat(VHOST_CONF)
    temp_path = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=os.path.dirname(VHOST_CONF),
            prefix=".vhost.conf.",
            delete=False,
        ) as file:
            temp_path = file.name
            file.write(content)
            file.flush()
            os.fsync(file.fileno())
        os.chmod(temp_path, stat.S_IMODE(original_stat.st_mode))
        os.chown(temp_path, original_stat.st_uid, original_stat.st_gid)
        os.replace(temp_path, VHOST_CONF)
    finally:
        if temp_path and os.path.exists(temp_path):
            os.unlink(temp_path)

    print(f"Updated {VHOST_CONF}")
    print("Validate and restart with:")
    print("  sudo /usr/local/lsws/bin/openlitespeed -t")
    print("  sudo systemctl restart lsws")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
