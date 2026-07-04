#!/usr/bin/env python3
"""Verify all 24 category-product files against Tier 1 + Tier 2 + Tier 3 criteria."""
import yaml
import re
from pathlib import Path
from collections import defaultdict

LANGS = ['en','it','es','fr','de','pt','ru','ja','tr','ar','ko','zh','hi','vi','jv','ms','tg']

DIR = Path(r"D:/001 Tools/004 Desk/Desk/Tools/KS/Kssmi/kssmi-site/src/content/category-product")

# Lens-related forbidden words (frames-only rule)
LENS_FORBIDDEN = ['blue-light', 'polarized lens', 'polarised lens', 'progressive lens',
                  'prescription lens', 'anti-reflective', 'photochromic', 'transitions']

# Opening verb groups from skill spec
OPENING_GROUPS = {
    'A': ['Discover', 'Explore', 'Source', 'Scopri', 'Esplora', 'Descubre', 'Explora',
          'Découvrez', 'Entdecken', 'Descubra', 'Откройте', 'ご覧ください', 'keşfedin',
          'اكتشف', '만나보세요', '探索', 'खोजें', 'Khám phá', 'Temokake', 'Terokai', 'кашф'],
    'B': ['Partner with', 'Collaborate with', 'Connect with', 'Collabora con',
          'Colabora con', 'Collaborez avec', 'Arbeiten Sie', 'Сотрудничайте',
          '提携', 'ortaklık', 'تعاون', '파트너', '合作', 'साझेदारी', 'Hợp tác', 'Makarya'],
    'C': ['Looking for', 'Need', 'Searching for', 'Cerchi', 'Hai bisogno', 'Stai cercando',
          '¿Buscas', 'Besoin', 'Sie suchen', 'Procura', 'Ищете', 'お探し', 'arıyorsanız',
          'تبحث', '찾고', '寻找', 'खोज', 'Đang tìm', 'nggoleki', 'Mencari', 'Ҷустуҷӯ'],
    'D': ['Elevate', 'Build', 'Create', 'Launch', 'Esalta', 'Crea', 'Lanza',
          'Élevez', 'Créez', 'Erhöhen Sie', 'Crie', 'Создайте', '高めましょう',
          'Yükseltin', 'ارتق', 'Elevate', '打造', 'उन्नत', 'Nâng tầm', 'Ngangkat'],
    'E': ['Custom', 'Tailored', 'Bespoke', 'Made-to-order', 'Personalizzato', 'Su misura',
          'A medida', 'Personnalisé', 'Personnalisé', 'Maßgefertigt', 'Personalizado',
          'Индивидуальные', 'カスタム', 'Özel', 'مخصص', '맞춤', '定制', 'अनुकूलित',
          'Tùy chỉnh', 'Khusus', 'Tersuai', 'Фармоишӣ'],
}

def char_len(s):
    return len(s) if s else 0

def detect_opening_group(text):
    """Return which group (A-E) the seoDescription starts with, or 'OTHER'."""
    t = text.lstrip().lower()
    for group, verbs in OPENING_GROUPS.items():
        for verb in verbs:
            if t.startswith(verb.lower()):
                return group
    return 'OTHER'

def extract_first_sentence(text):
    """Get the first sentence of seoDescription for variance check."""
    text = text.strip()
    # Split on first . ! or ?
    for i, c in enumerate(text):
        if c in '.!?':
            return text[:i+1].strip()
    return text

# Collect all data
files_data = {}
all_errors = defaultdict(list)
all_verb_groups = []  # for variance check

for path in sorted(DIR.glob('*.md')):
    slug = path.stem
    content = path.read_text(encoding='utf-8')
    parts = content.split('---', 2)
    if len(parts) < 3:
        all_errors[slug].append(f'no frontmatter found')
        continue
    try:
        data = yaml.safe_load(parts[1])
    except yaml.YAMLError as e:
        all_errors[slug].append(f'YAML parse error: {e}')
        continue

    if not data:
        all_errors[slug].append(f'empty frontmatter')
        continue

    files_data[slug] = data

    # Tier 1 checks
    if data.get('slug') != slug:
        all_errors[slug].append(f"T1: slug mismatch (file={slug}, frontmatter={data.get('slug')})")
    if data.get('baseSegment') != 'product':
        all_errors[slug].append(f"T1: baseSegment != 'product' (got {data.get('baseSegment')})")

    # Check all 17 langs in all fields
    for field in ['displayName', 'seoTitle', 'seoDescription', 'seoKeywords']:
        if field not in data:
            all_errors[slug].append(f"T1: missing field '{field}'")
            continue
        for lang in LANGS:
            if lang not in data[field]:
                all_errors[slug].append(f"T1: missing '{lang}' in {field}")
            elif field == 'seoKeywords':
                kws = data[field][lang]
                if not isinstance(kws, list):
                    all_errors[slug].append(f"T2: {lang}.seoKeywords is not a list")
                elif len(kws) < 6:
                    all_errors[slug].append(f"T2: {lang}.seoKeywords has {len(kws)} items (need 6-8)")

    # Tier 2: char counts — check ALL 17 languages
    for lang in LANGS:
        title = data.get('seoTitle', {}).get(lang, '')
        desc = data.get('seoDescription', {}).get(lang, '')
        if title:
            l = char_len(title)
            # CJK languages count differently; use visible char heuristic
            if lang in ('ja', 'ko', 'zh'):
                target_min, target_max = 15, 35
            else:
                target_min, target_max = 50, 65
            if l < target_min or l > target_max:
                all_errors[slug].append(f"T2: {lang}.seoTitle {l} chars (need {target_min}-{target_max})")
        if desc:
            l = char_len(desc)
            if lang in ('ja', 'ko', 'zh'):
                target_min, target_max = 60, 110
            else:
                target_min, target_max = 150, 160
            if l < target_min or l > target_max:
                all_errors[slug].append(f"T2: {lang}.seoDescription {l} chars (need {target_min}-{target_max})")

    # Tier 2: forbidden lens words (across all langs)
    for lang in LANGS:
        for field in ['seoTitle', 'seoDescription', 'seoKeywords', 'displayName']:
            val = data.get(field, {}).get(lang, '')
            if isinstance(val, list):
                val = ' '.join(val)
            val_lower = val.lower() if val else ''
            for forbidden in LENS_FORBIDDEN:
                if forbidden.lower() in val_lower:
                    all_errors[slug].append(f"T2-FRAMES: {lang}.{field} contains forbidden lens term '{forbidden}'")

    # Tier 2: MOQ in keywords
    for lang in LANGS:
        kws = data.get('seoKeywords', {}).get(lang, [])
        if isinstance(kws, list):
            for kw in kws:
                if isinstance(kw, str) and ('MOQ' in kw.upper() and any(d in kw for d in '0123456789')):
                    all_errors[slug].append(f"T2: {lang}.seoKeywords contains MOQ number: {kw!r}")

    # Tier 2: B2B intent in seoTitle (per-language accepted terms)
    B2B_BY_LANG = {
        'en': ['OEM', 'Manufacturer', 'Wholesale', 'Factory', 'Supplier', 'Bespoke', 'Custom'],
        'it': ['OEM', 'Produttore', 'Fabbrica', 'Grossista', 'Fornitore', 'Personalizzat'],
        'es': ['OEM', 'Fabricante', 'Fábrica', 'Mayorista', 'Proveedor', 'Personalizad'],
        'fr': ['OEM', 'Fabricant', 'Usine', 'Grossiste', 'Fournisseur', 'Personnalis'],
        'de': ['OEM', 'Hersteller', 'Fabrik', 'Großhandel', 'Lieferant', 'Maßgefer'],
        'pt': ['OEM', 'Fabricante', 'Fábrica', 'Atacadista', 'Fornecedor', 'Personalizad'],
        'ru': ['OEM', 'Производитель', 'Фабрика', 'Поставщик', 'Оптовый'],
        'ja': ['OEM', 'メーカー', '工場', '卸売', 'サプライヤー'],
        'tr': ['OEM', 'Üretici', 'Fabrika', 'Toptan', 'Tedarikçi'],
        'ar': ['OEM', 'مصنع', 'الشركة المصنعة', 'تصنيع', 'مورد', 'تاجر جملة'],
        'ko': ['OEM', '제조사', '공장', '도매', '공급업체', '제조'],
        'zh': ['OEM', '制造商', '工厂', '批发', '供应商', '定制'],
        'hi': ['OEM', 'निर्माता', 'कारखाने', 'थोक', 'आपूर्तिकर्ता', 'उत्पादक'],
        'vi': ['OEM', 'Nhà sản xuất', 'Nhà máy', 'Bán sỉ', 'Nhà cung cấp'],
        'jv': ['OEM', 'Produsen', 'Pabrik', 'Grosir', 'Supplier'],
        'ms': ['OEM', 'Pengeluar', 'Kilang', 'Borong', 'Pembekal'],
        'tg': ['OEM', 'Истеҳсолкунанда', 'Корхона', 'Яклухт', 'Таъминкунанда'],
    }
    for lang in LANGS:
        title = data.get('seoTitle', {}).get(lang, '')
        if title:
            accepted = B2B_BY_LANG.get(lang, B2B_BY_LANG['en'])
            if not any(t in title for t in accepted):
                all_errors[slug].append(f"T2: {lang}.seoTitle missing B2B intent term (got: {title!r})")

    # Tier 3: collect opening verb group for cross-file variance check
    en_desc_check = data.get('seoDescription', {}).get('en', '')
    group = detect_opening_group(en_desc_check)
    all_verb_groups.append((slug, group, extract_first_sentence(en_desc_check)))

# Print per-file errors
print("=" * 80)
print(f"TIER 1 + TIER 2 PER-FILE ERRORS")
print("=" * 80)

if not all_errors:
    print("✅ NO ERRORS — all 24 files pass Tier 1 + Tier 2 mechanical checks\n")
else:
    total_errs = sum(len(v) for v in all_errors.values())
    print(f"❌ {len(all_errors)} files with errors ({total_errs} total):\n")
    for slug in sorted(all_errors.keys()):
        errs = all_errors[slug]
        print(f"  {slug}.md ({len(errs)} issues):")
        for e in errs[:8]:  # limit per file
            print(f"    - {e}")
        if len(errs) > 8:
            print(f"    ... and {len(errs)-8} more")
        print()

# Use semantic order rather than alphabetical for variance check
SEMANTIC_ORDER = [
    # Parent categories
    'sunglasses', 'optical-frames',
    # Sunglasses material sub
    'acetate-sunglasses', 'metal-sunglasses', 'titanium-sunglasses',
    'carbon-fiber-sunglasses', 'rimless-sunglasses',
    # Optical material sub
    'acetate-optical-frames', 'metal-optical-frames', 'titanium-optical-frames',
    'carbon-fiber-optical-frames', 'rimless-optical-frames',
    # Cross-type
    'fashion-eyewear', 'luxury-eyewear', 'carbon-fiber-eyewear', 'rimless-eyewear',
    # Fashion sub
    'fashion-acetate-sunglasses', 'fashion-metal-sunglasses',
    'fashion-acetate-optical-frames', 'fashion-metal-optical-frames',
    # Luxury sub
    'luxury-acetate-sunglasses', 'luxury-titanium-sunglasses',
    'luxury-acetate-optical-frames', 'luxury-titanium-optical-frames',
]
verb_map = {slug: (group, first) for slug, group, first in all_verb_groups}

# Variance check
print("=" * 80)
print(f"TIER 3: OPENING VERB VARIANCE (using semantic order)")
print("=" * 80)
group_counts = defaultdict(int)
for slug in SEMANTIC_ORDER:
    if slug not in verb_map:
        continue
    group, first = verb_map[slug]
    group_counts[group] += 1
    print(f"  {slug:38} → Group {group:5} | {first[:55]}...")
print()
print(f"Group distribution: {dict(group_counts)}")
print()
# Find adjacent duplicates in SEMANTIC order
prev_group = None
adjacent_dupes = []
for slug in SEMANTIC_ORDER:
    if slug not in verb_map:
        continue
    group, _ = verb_map[slug]
    if group == prev_group and group != 'OTHER':
        adjacent_dupes.append((slug, group, prev_slug))
    prev_group = group
    prev_slug = slug
if adjacent_dupes:
    print(f"⚠ Adjacent files with same opening verb group: {len(adjacent_dupes)}")
    for slug, g, prev in adjacent_dupes:
        print(f"    {prev} → {slug} (both Group {g})")
else:
    print(f"✅ No adjacent files share the same opening verb group")

# Summary stats
print()
print("=" * 80)
print(f"SUMMARY")
print("=" * 80)
print(f"Total files: {len(files_data)}")
print(f"Files with errors: {len(all_errors)}")
print(f"Total error instances: {sum(len(v) for v in all_errors.values())}")
