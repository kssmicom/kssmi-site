#!/usr/bin/env python3
"""
Final cleanup: remove spurious periods I added to displayName and seoTitle.
Keep description fixes as-is (some are slightly awkward but in valid range).
"""
import re
from pathlib import Path

DIR = Path(r"D:/001 Tools/004 Desk/Desk\Tools\KS/Kssmi/kssmi-site/src/content/category-product")

# Find blocks: between "displayName:" and the next "seoTitle:" — remove trailing .
DISPLAYNAME_BLOCK_RE = re.compile(
    r'^(displayName:)(.*?)(?=^seoTitle:)',
    re.MULTILINE | re.DOTALL
)
SEOTITLE_BLOCK_RE = re.compile(
    r'^(seoTitle:)(.*?)(?=^seoDescription:)',
    re.MULTILINE | re.DOTALL
)

# Match a quoted string value (possibly with escape \")
QUOTED_STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')

def strip_trailing_period(m):
    val = m.group(1)
    # Strip trailing period if the value doesn't naturally end with one
    # (displayNames don't end with periods; titles can have "." mid-text but not end)
    if val.endswith('.') and not val.endswith('..'):
        return f'"{val[:-1]}"'
    return m.group(0)

files_touched = 0
total_stripped = 0

for path in sorted(DIR.glob('*.md')):
    content = path.read_text(encoding='utf-8')
    new_content = content

    # Strip periods from displayName block
    def strip_display(m):
        block = m.group(0)
        return QUOTED_STR_RE.sub(strip_trailing_period, block)

    new_content = DISPLAYNAME_BLOCK_RE.sub(strip_display, new_content)

    # Strip periods from seoTitle block
    def strip_title(m):
        block = m.group(0)
        return QUOTED_STR_RE.sub(strip_trailing_period, block)

    new_content = SEOTITLE_BLOCK_RE.sub(strip_title, new_content)

    if new_content != content:
        path.write_text(new_content, encoding='utf-8')
        files_touched += 1
        # Count changes
        total_stripped += sum(1 for _ in re.finditer(r'"[^"]*\.\s*"', content)) - sum(1 for _ in re.finditer(r'"[^"]*\.\s*"', new_content))

print(f"Files modified: {files_touched}")