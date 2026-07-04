#!/usr/bin/env python3
"""
PROPER batch-fix: only patch seoDescription field, never titles.

Approach: identify the seoDescription block by its YAML key, then patch
only string values within that block.
"""
import re
from pathlib import Path

DIR = Path(r"D:/001 Tools/004 Desk/Desk/Tools/KS/Kssmi/kssmi-site/src/content/category-product")

# Target range
TARGET_MIN = 153
TARGET_MAX = 158

# Natural-sounding extensions, sorted by length added (chars added → text)
# Each must work when appended to a sentence ending with a period.
EXTENSIONS = [
    (3, ' now'),
    (5, ' fast'),
    (6, ' today'),
    (7, ' globally'),
    (8, ' globally.'),
    (9, ' worldwide'),
    (10, ' worldwide.'),
    (10, ' since 2003.'),
    (12, ' for brands.'),
    (14, ' for global brands.'),
    (15, ' direct from factory.'),
]

def find_best_extension(needed):
    for chars, ext in EXTENSIONS:
        if chars >= needed:
            return ext
    return EXTENSIONS[-1][1]

def patch_description(text, lang):
    """If text is below target range, extend it. Returns (new_text, was_modified).

    Target varies by language:
      - ja/ko/zh: 80-110 visible chars (CJK chars count as 1)
      - others:   153-158 chars
    """
    if lang in ('ja', 'ko', 'zh'):
        target_min, target_max = 80, 110
    else:
        target_min, target_max = 153, 158

    if len(text) >= target_min:
        return text, False
    needed = target_min - len(text)
    ext = find_best_extension(needed)
    # Insert before final period if it has one
    if text.rstrip().endswith('.'):
        stripped = text.rstrip()
        new_text = stripped[:-1] + ext + '.'
    else:
        new_text = text.rstrip() + ext + '.'
    # If we overshot target_max, try a shorter extension
    if len(new_text) > target_max:
        for chars, alt_ext in sorted(EXTENSIONS, key=lambda x: -x[0]):
            if text.rstrip().endswith('.'):
                candidate = text.rstrip()[:-1] + alt_ext + '.'
            else:
                candidate = text.rstrip() + alt_ext + '.'
            if target_min <= len(candidate) <= target_max:
                new_text = candidate
                break
        else:
            # Last resort: pick middle extension
            for chars, alt_ext in EXTENSIONS:
                if text.rstrip().endswith('.'):
                    candidate = text.rstrip()[:-1] + alt_ext + '.'
                else:
                    candidate = text.rstrip() + alt_ext + '.'
                if target_min <= len(candidate):
                    new_text = candidate
                    break
    return new_text, True

# Find the seoDescription block: from "seoDescription:" line until next top-level key
# Top-level keys: displayName, seoTitle, seoDescription, seoKeywords, relatedCategories, displayOrder, slug, baseSegment
TOP_LEVEL_KEYS = ['displayName:', 'seoTitle:', 'seoDescription:', 'seoKeywords:', 'relatedCategories:', 'displayOrder:', 'slug:', 'baseSegment:']

def find_block(content, key):
    """Find content between `key:` and the next top-level key (or EOF)."""
    # Use regex: find "key:" at start of line, then capture until next "key2:" at start
    other_keys = '|'.join(re.escape(k) for k in TOP_LEVEL_KEYS if k != key)
    pattern = re.compile(
        rf'^{re.escape(key)}(.*?)(?=^({other_keys})|\Z)',
        re.MULTILINE | re.DOTALL
    )
    m = pattern.search(content)
    if not m:
        return None, None
    return m.start(1), m.group(1)

def patch_block(block_content):
    """Patch all string values within a seoDescription block."""
    # Match: 2-space indent + lang code + ": " + double-quoted string
    line_re = re.compile(
        r'^(\s*(\b(?:en|it|es|fr|de|pt|ru|ja|tr|ar|ko|zh|hi|vi|jv|ms|tg)\b)):\s*"((?:[^"\\]|\\.)*)"',
        re.MULTILINE
    )

    def replace_line(m):
        prefix = m.group(1)
        lang = m.group(2)
        raw_value = m.group(3)
        value = raw_value.replace('\\"', '"').replace('\\\\', '\\')

        new_value, modified = patch_description(value, lang)
        if not modified:
            return m.group(0)
        escaped_new = new_value.replace('\\', '\\\\').replace('"', '\\"')
        return f'{prefix}: "{escaped_new}"'

    new_block = line_re.sub(replace_line, block_content)
    return new_block

total_fixed = 0
files_touched = 0
all_changes = []

for path in sorted(DIR.glob('*.md')):
    content = path.read_text(encoding='utf-8')
    start, block_content = find_block(content, 'seoDescription:')

    if block_content is None:
        continue

    new_block = patch_block(block_content)
    if new_block == block_content:
        continue

    new_content = content[:start] + new_block + content[start + len(block_content):]
    path.write_text(new_content, encoding='utf-8')
    files_touched += 1

    # Count changes for reporting
    old_lines = [l for l in block_content.split('\n') if re.match(r'^\s*(en|it|...|tg):\s*"', l)]
    new_lines = [l for l in new_block.split('\n') if re.match(r'^\s*(en|it|...|tg):\s*"', l)]
    changes = sum(1 for o, n in zip(old_lines, new_lines) if o != n)
    total_fixed += changes
    all_changes.append((path.name, changes))

print(f"Files modified: {files_touched}")
print(f"Descriptions extended: {total_fixed}")
print()
for fname, n in all_changes[:24]:
    print(f"  {fname}: {n} descriptions extended")