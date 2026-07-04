#!/usr/bin/env python3
"""Add `productName` field to all 17 quote.{lang}.md files for the Problem 3 refactor."""
import re
from pathlib import Path

DIR = Path(r"D:/001 Tools/004 Desk/Desk/Tools/KS/Kssmi/kssmi-site/src/content/collection/quote")

# Localized "General Inquiry" for 17 languages
PRODUCT_NAME = {
    'en': 'General Inquiry',
    'it': 'Richiesta Generale',
    'es': 'Consulta General',
    'fr': 'Demande Générale',
    'de': 'Allgemeine Anfrage',
    'pt': 'Consulta Geral',
    'ru': 'Общий запрос',
    'ja': '一般のお問い合わせ',
    'tr': 'Genel Soru',
    'ar': 'استفسار عام',
    'ko': '일반 문의',
    'zh': '一般询盘',
    'hi': 'सामान्य पूछताछ',
    'vi': 'Yêu cầu chung',
    'jv': 'Pitakonan umum',
    'ms': 'Pertanyaan am',
    'tg': 'Pangkalahatang Katanungan',
}

LANGS = list(PRODUCT_NAME.keys())

total_updated = 0
for lang in LANGS:
    path = DIR / f'quote.{lang}.md'
    if not path.exists():
        print(f'  ✗ {lang}: file missing')
        continue
    content = path.read_text(encoding='utf-8')

    # Check if productName already exists
    if 'productName:' in content:
        print(f'  ⚠ {lang}: already has productName, skipping')
        continue

    # Add productName after seoDescription line
    new_content = re.sub(
        r'(seoDescription: ".*?")\n',
        rf'\1\nproductName: "{PRODUCT_NAME[lang]}"\n',
        content,
        count=1
    )

    if new_content == content:
        print(f'  ✗ {lang}: regex did not match')
        continue

    path.write_text(new_content, encoding='utf-8')
    total_updated += 1
    print(f'  ✓ {lang}: added productName = "{PRODUCT_NAME[lang]}"')

print(f'\nTotal updated: {total_updated}/{len(LANGS)}')