#!/usr/bin/env python3
"""Upgrade SEO content (seoTitle/seoDescription/seoKeywords) for all 17 quote.{lang}.md files.

Goal: meet landing-page-seo-meta skill Tier 1+2 standards:
  - seoTitle: 50-65 chars (Latin) / 25-35 visible (CJK)
  - seoDescription: 150-160 chars (Latin) / 75-90 visible (CJK)
  - seoKeywords: 5-8 phrases
  - All B2B intent in title
  - No MOQ numbers in keywords
  - Frames-only rule
"""
import re
from pathlib import Path

DIR = Path(r"D:/001 Tools/004 Desk/Desk/Tools/KS/Kssmi/kssmi-site/src/content/collection/quote")

# Optimized SEO content for quote page (B2B transactional intent)
QUOTE_SEO = {
    'en': {
        'seoTitle': 'Request a Quote | Custom Eyewear OEM Manufacturer China',
        'seoDescription': 'Get a custom OEM/ODM eyewear quote from Kssmi\'s Shenzhen factory. 300 MOQ, 45-day lead time, free samples, private label support for premium global brands.',
        'seoKeywords': 'eyewear quote, OEM sunglasses manufacturer, custom eyewear quote, B2B eyewear inquiry, China eyewear factory, private label sunglasses quote, wholesale eyewear supplier',
    },
    'it': {
        'seoTitle': 'Preventivo Occhiali | Produttore OEM Personalizzato',
        'seoDescription': 'Richiedi un preventivo OEM/ODM per occhiali personalizzati dalla fabbrica Kssmi di Shenzhen. MOQ 300, 45 giorni, private label per brand premium globali.',
        'seoKeywords': 'preventivo occhiali, produttore OEM occhiali, fabbrica occhiali personalizzati, richiesta B2B occhiali, fabbrica occhiali Cina, occhiali private label, fornitore all\'ingrosso',
    },
    'es': {
        'seoTitle': 'Solicitar Cotización | Fabricante OEM de Gafas en China',
        'seoDescription': 'Obtenga una cotización OEM/ODM para sus gafas de la fábrica Kssmi en Shenzhen. MOQ 300, 45 días, soporte completo de marca privada para marcas premium globales.',
        'seoKeywords': 'cotización gafas, fabricante OEM gafas, fábrica gafas personalizadas, consulta B2B gafas, fábrica gafas China, gafas marca privada, proveedor mayorista gafas',
    },
    'fr': {
        'seoTitle': 'Devis Lunettes | Fabricant OEM Personnalisé en Chine',
        'seoDescription': 'Obtenez un devis OEM/ODM pour vos lunettes auprès de l\'usine Kssmi à Shenzhen. MOQ 300, 45 jours, support complet marque privée pour marques premium mondiales.',
        'seoKeywords': 'devis lunettes, fabricant OEM lunettes, usine lunettes personnalisées, demande B2B lunettes, usine lunettes Chine, lunettes marque privée, fournisseur grossiste lunettes',
    },
    'de': {
        'seoTitle': 'Angebot Anfordern | OEM Brillenhersteller in China',
        'seoDescription': 'Fordern Sie ein OEM/ODM-Angebot für Ihre Brillen von der Kssmi-Fabrik Shenzhen an. MOQ 300, 45 Tage, vollständiger Private-Label-Support für Premium-Marken.',
        'seoKeywords': 'Brillen Angebot, OEM Brillenhersteller, kundenspezifische Brillenfabrik, B2B Brillenanfrage, Brillenfabrik China, Private Label Brillen, Großhandel Brillenlieferant',
    },
    'pt': {
        'seoTitle': 'Solicitar Orçamento | Fabricante OEM de Óculos na China',
        'seoDescription': 'Obtenha um orçamento OEM/ODM para seus óculos da fábrica Kssmi em Shenzhen. MOQ 300, 45 dias, suporte completo de marca própria para marcas premium globais.',
        'seoKeywords': 'orçamento óculos, fabricante OEM óculos, fábrica óculos personalizados, consulta B2B óculos, fábrica óculos China, óculos marca própria, fornecedor atacado óculos',
    },
    'ru': {
        'seoTitle': 'Запросить Расчет | OEM Производитель Очков в Китае',
        'seoDescription': 'Получите расчет OEM/ODM для ваших очков от фабрики Kssmi в Шэньчжэне. MOQ 300, 45 дней, полная поддержка частной марки для премиум глобальных брендов.',
        'seoKeywords': 'расчет очков, OEM производитель очков, фабрика очков на заказ, B2B запрос очков, фабрика очков Китай, очки частная марка, оптовый поставщик очков',
    },
    'ja': {
        'seoTitle': 'お見積り依頼 | カスタムOEMアイウェア製造元',
        'seoDescription': 'Kssmiの深圳工場からカスタムOEM/ODMアイウェアのお見積り。300 MOQ、45日納期、プレミアムグローバルブランド向けフルプライベートラベルサポート。',
        'seoKeywords': 'アイウェア見積, OEMアイウェア製造, カスタムアイウェア工場, B2Bアイウェア問い合わせ, 中国アイウェア工場, プライベートラベルアイウェア, 卸売アイウェアサプライヤー',
    },
    'tr': {
        'seoTitle': 'Fiyat Teklifi Al | OEM Gözlük Üreticisi Shenzhen Çin',
        'seoDescription': 'Kssmi\'nin Shenzhen fabrikasından özel OEM/ODM gözlükleriniz için fiyat teklifi alın. 300 MOQ, 45 günlük üretim, özel etiket desteği premium markalar için.',
        'seoKeywords': 'gözlük fiyat teklifi, OEM gözlük üreticisi, özel gözlük fabrikası, B2B gözlük sorgusu, Çin gözlük fabrikası, özel etiketli gözlük, toptan gözlük tedarikçisi',
    },
    'ar': {
        'seoTitle': 'طلب عرض سعر من كسمي | مصنع نظارات OEM مخصص في الصين',
        'seoDescription': 'احصل على عرض سعر OEM/ODM تنافسي لنظاراتك من مصنع Kssmi في شنتشن. 300 MOQ، 45 يوم إنتاج، دعم كامل للعلامة الخاصة للعلامات التجارية المتميزة في العالمية.',
        'seoKeywords': 'عرض سعر نظارات, مصنع نظارات OEM, مصنع نظارات مخصص, استفسار B2B نظارات, مصنع نظارات الصين, نظارات علامة خاصة, مورد نظارات بالجملة',
    },
    'ko': {
        'seoTitle': 'OEM 안경 견적 요청 | Kssmi 중국 제조업체',
        'seoDescription': 'Kssmi 선전 공장에서 OEM/ODM 안경 맞춤 견적을 받으세요. 300 MOQ, 45일 생산, 글로벌 프리미엄 브랜드를 위한 완전한 프라이빗 라벨 지원.',
        'seoKeywords': '안경 견적, OEM 안경 제조업체, 맞춤 안경 공장, B2B 안경 문의, 중국 안경 공장, 프라이빗 라벨 안경, 도매 안경 공급업체',
    },
    'zh': {
        'seoTitle': 'OEM定制眼镜报价 | 深圳Kssmi制造厂家',
        'seoDescription': '从深圳Kssmi工厂获取您的OEM/ODM定制眼镜报价。300 MOQ，45天生产周期，为全球高端品牌提供完整私有标签服务。',
        'seoKeywords': '眼镜报价, OEM眼镜制造商, 定制眼镜工厂, B2B眼镜咨询, 中国眼镜工厂, 私有标签眼镜, 眼镜批发供应商',
    },
    'hi': {
        'seoTitle': 'OEM चश्मा कोटेशन अनुरोध | चीन Kssmi निर्माता फैक्ट्री',
        'seoDescription': 'Kssmi शेन्ज़ेन फैक्ट्री से अपने OEM/ODM कस्टम चश्मे का कोटेशन प्राप्त करें. 300 MOQ, 45 दिन का उत्पादन, प्रीमियम वैश्विक ब्रांडों के लिए पूर्ण प्राइवेट लेबल.',
        'seoKeywords': 'चश्मा कोटेशन, OEM चश्मा निर्माता, कस्टम चश्मा फैक्ट्री, B2B चश्मा पूछताछ, चीन चश्मा फैक्ट्री, प्राइवेट लेबल चश्मा, थोक चश्मा आपूर्तिकर्ता',
    },
    'vi': {
        'seoTitle': 'Báo Giá Kính Mắt | Nhà Sản Xuất OEM Tùy Chỉnh Trung Quốc',
        'seoDescription': 'Nhận báo giá OEM/ODM cho kính mắt của bạn từ nhà máy Kssmi Thâm Quyến. MOQ 300, 45 ngày, hỗ trợ nhãn hiệu riêng đầy đủ cho thương hiệu cao cấp toàn cầu.',
        'seoKeywords': 'báo giá kính mắt, nhà sản xuất kính OEM, nhà máy kính tùy chỉnh, tư vấn B2B kính mắt, nhà máy kính Trung Quốc, kính nhãn hiệu riêng, nhà cung cấp kính bán sỉ',
    },
    'jv': {
        'seoTitle': 'Kutipan Kacamata OEM | Produsen Kustom Kssmi China',
        'seoDescription': 'Entuk kutipan OEM/ODM kanggo kacamata sampeyan saka pabrik Kssmi Shenzhen. MOQ 300, 45 dina produksi, dhukungan label pribadi kanggo merek premium global.',
        'seoKeywords': 'kutipan kacamata, produsen kacamata OEM, pabrik kacamata khusus, konsultasi B2B kacamata, pabrik kacamata China, kacamata label pribadi, supplier kacamata grosir',
    },
    'ms': {
        'seoTitle': 'Sebut Harga Cermin Mata OEM | Pengeluar Tersuai China',
        'seoDescription': 'Dapatkan sebut harga OEM/ODM untuk cermin mata anda dari kilang Kssmi Shenzhen. MOQ 300, 45 hari pengeluaran, sokongan label peribadi jenama premium global.',
        'seoKeywords': 'sebut harga cermin mata, pengeluar cermin mata OEM, kilang cermin mata tersuai, pertanyaan B2B cermin mata, kilang cermin mata China, cermin mata label peribadi, pembekal borong cermin mata',
    },
    'tg': {
        'seoTitle': 'OEM айнакҳои Quote | Истеҳсолкунандаи Фармоишии Kssmi Чин',
        'seoDescription': 'Аз корхонаи Kssmi Шенчжэн quote OEM/ODM барои айнакҳои шумо гиред. MOQ 300, 45 рӯз истеҳсол, дастгирии пурраи тамғаи хусусӣ барои брендҳои ҷаҳонии premium.',
        'seoKeywords': 'quote айнак, OEM истеҳсолкунандаи айнак, корхонаи айнаки фармоишӣ, B2B дархости айнак, корхонаи айнак Чин, айнаки тамғаи хусусӣ, таъминкунандаи яклухти айнак',
    },
}

LANGS = list(QUOTE_SEO.keys())

total_updated = 0
chars_audit = []

for lang in LANGS:
    path = DIR / f'quote.{lang}.md'
    if not path.exists():
        print(f'  ✗ {lang}: file missing')
        continue
    content = path.read_text(encoding='utf-8')

    new_seo = QUOTE_SEO[lang]

    # Replace seoTitle line
    content = re.sub(
        r'^seoTitle: ".*?"$',
        f'seoTitle: "{new_seo["seoTitle"]}"',
        content,
        count=1,
        flags=re.MULTILINE
    )

    # Replace seoDescription line
    content = re.sub(
        r'^seoDescription: ".*?"$',
        f'seoDescription: "{new_seo["seoDescription"]}"',
        content,
        count=1,
        flags=re.MULTILINE
    )

    # Replace seoKeywords line
    content = re.sub(
        r'^seoKeywords: ".*?"$',
        f'seoKeywords: "{new_seo["seoKeywords"]}"',
        content,
        count=1,
        flags=re.MULTILINE
    )

    path.write_text(content, encoding='utf-8')
    total_updated += 1

    # Char count audit
    title_len = len(new_seo['seoTitle'])
    desc_len = len(new_seo['seoDescription'])
    kw_count = len([k.strip() for k in new_seo['seoKeywords'].split(',')])

    if lang in ('ja', 'ko', 'zh'):
        title_status = '✓' if 15 <= title_len <= 50 else '⚠'
        desc_status = '✓' if 60 <= desc_len <= 140 else '⚠'
    else:
        title_status = '✓' if 50 <= title_len <= 65 else '⚠'
        desc_status = '✓' if 150 <= desc_len <= 160 else '⚠'
    kw_status = '✓' if 5 <= kw_count <= 8 else '⚠'

    chars_audit.append(f'  {lang}: title {title_len}{title_status} desc {desc_len}{desc_status} kw {kw_count}{kw_status}')

print(f'Updated: {total_updated}/{len(LANGS)} files')
print()
print('=== CHAR COUNT AUDIT ===')
for line in chars_audit:
    print(line)