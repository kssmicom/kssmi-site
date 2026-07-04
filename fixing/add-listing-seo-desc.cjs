/**
 * Add listingSeoDescription and categorySeoDescription to all 17 translation files.
 * Insert after the nextPage key inside the product section.
 */
const fs = require('fs');
const path = require('path');

const DIR = path.resolve(__dirname, '../src/translations');

const data = {
  en: {
    listingSeoDescription: 'Browse {count} premium eyewear products from Kssmi — OEM/ODM custom manufacturing for global brands and retailers.',
    categorySeoDescription: 'Explore {category} collection — {count} styles available. OEM/ODM custom eyewear manufacturing by Kssmi.',
  },
  zh: {
    listingSeoDescription: '浏览 Kssmi 的 {count} 款优质眼镜产品 — 为全球品牌和零售商提供 OEM/ODM 定制制造。',
    categorySeoDescription: '探索 {category} 系列 — 共 {count} 款款式可选。Kssmi OEM/ODM 眼镜定制制造。',
  },
  it: {
    listingSeoDescription: 'Sfoglia {count} prodotti di occhialeria premium di Kssmi — produzione personalizzata OEM/ODM per marchi e rivenditori globali.',
    categorySeoDescription: 'Esplora la collezione {category} — {count} stili disponibili. Produzione occhiali OEM/ODM personalizzati da Kssmi.',
  },
  es: {
    listingSeoDescription: 'Explore {count} productos de gafas premium de Kssmi — fabricación personalizada OEM/ODM para marcas y distribuidores globales.',
    categorySeoDescription: 'Explore la colección {category} — {count} estilos disponibles. Fabricación de gafas OEM/ODM personalizadas por Kssmi.',
  },
  fr: {
    listingSeoDescription: 'Parcourez {count} produits de lunetterie premium de Kssmi — fabrication personnalisée OEM/ODM pour les marques et détaillants mondiaux.',
    categorySeoDescription: 'Explorez la collection {category} — {count} styles disponibles. Fabrication de lunettes OEM/ODM personnalisées par Kssmi.',
  },
  de: {
    listingSeoDescription: 'Durchsuchen Sie {count} Premium-Brillenprodukte von Kssmi — OEM/ODM-Sonderanfertigung für globale Marken und Händler.',
    categorySeoDescription: 'Entdecken Sie die {category}-Kollektion — {count} Stile verfügbar. OEM/ODM-Maßanfertigung von Brillen durch Kssmi.',
  },
  pt: {
    listingSeoDescription: 'Navegue por {count} produtos de óculos premium da Kssmi — fabricação personalizada OEM/ODM para marcas e varejistas globais.',
    categorySeoDescription: 'Explore a coleção {category} — {count} estilos disponíveis. Fabricação de óculos OEM/ODM personalizados pela Kssmi.',
  },
  ru: {
    listingSeoDescription: 'Просмотрите {count} премиальных оптических изделий от Kssmi — OEM/ODM производство для мировых брендов и ритейлеров.',
    categorySeoDescription: 'Исследуйте коллекцию {category} — доступно {count} моделей. OEM/ODM производство очков от Kssmi.',
  },
  ja: {
    listingSeoDescription: 'Kssmiのプレミアムアイウェア製品{count}点をご覧ください — グローバルブランドおよび小売業者向けOEM/ODMカスタム製造。',
    categorySeoDescription: '{category}コレクションをご覧ください — {count}スタイルをご用意。KssmiによるOEM/ODMカスタムアイウェア製造。',
  },
  tr: {
    listingSeoDescription: 'Kssmi\'nin {count} premium gözlük ürününü inceleyin — küresel markalar ve perakendeciler için OEM/ODM özel üretim.',
    categorySeoDescription: '{category} koleksiyonunu keşfedin — {count} model mevcut. Kssmi tarafından OEM/ODM özel gözlük üretimi.',
  },
  ar: {
    listingSeoDescription: 'تصفح {count} منتج نظارات فاخرة من Kssmi — تصنيع مخصص OEM/ODM للعلامات التجارية وتجار التجزئة العالميين.',
    categorySeoDescription: 'استكشف مجموعة {category} — {count} نمط متاح. تصنيع نظارات مخصص OEM/ODM من Kssmi.',
  },
  ko: {
    listingSeoDescription: 'Kssmi의 프리미엄 안경 제품 {count}개를 둘러보세요 — 글로벌 브랜드 및 리테일러를 위한 OEM/ODM 맞춤 제작.',
    categorySeoDescription: '{category} 컬렉션을 둘러보세요 — {count}가지 스타일 제공. Kssmi의 OEM/ODM 맞춤 안경 제작.',
  },
  hi: {
    listingSeoDescription: 'Kssmi के {count} प्रीमियम आईवियर उत्पाद देखें — वैश्विक ब्रांडों और रिटेलर्स के लिए OEM/ODM कस्टम निर्माण।',
    categorySeoDescription: '{category} संग्रह देखें — {count} शैलियां उपलब्ध। Kssmi द्वारा OEM/ODM कस्टम आईवियर निर्माण।',
  },
  vi: {
    listingSeoDescription: 'Duyệt {count} sản phẩm kính mắt cao cấp từ Kssmi — sản xuất tùy chỉnh OEM/ODM cho các thương hiệu và nhà bán lẻ toàn cầu.',
    categorySeoDescription: 'Khám phá bộ sưu tập {category} — có {count} kiểu dáng. Sản xuất kính mắt OEM/ODM tùy chỉnh bởi Kssmi.',
  },
  jv: {
    listingSeoDescription: 'Jelajahi {count} produk kacamata premium saka Kssmi — manufaktur khusus OEM/ODM kanggo merek lan pengecer global.',
    categorySeoDescription: 'Jelajahi koleksi {category} — {count} gaya tersedia. Manufaktur kacamata OEM/ODM khusus dening Kssmi.',
  },
  ms: {
    listingSeoDescription: 'Layari {count} produk cermin mata premium daripada Kssmi — pembuatan tersuai OEM/ODM untuk jenama dan peruncit global.',
    categorySeoDescription: 'Terokai koleksi {category} — {count} gaya tersedia. Pembuatan cermin mata OEM/ODM tersuai oleh Kssmi.',
  },
  tg: {
    listingSeoDescription: 'Маҳсулоти айнаки премиуми Kssmi-ро ({count} адад) бубинед — истеҳсоли фармоишии OEM/ODM барои брендҳо ва фурӯшандагони ҷаҳонӣ.',
    categorySeoDescription: 'Коллексияи {category}-ро кашф кунед — {count} услуб мавҷуд аст. Истеҳсоли айнаки фармоишии OEM/ODM аз Kssmi.',
  },
};

let updated = 0;
let skipped = [];

for (const [lang, texts] of Object.entries(data)) {
  const filePath = path.join(DIR, `${lang}.ts`);
  if (!fs.existsSync(filePath)) {
    skipped.push(lang);
    continue;
  }

  let content = fs.readFileSync(filePath, 'utf-8');

  // Skip if already present
  if (content.includes('listingSeoDescription')) {
    console.log(`[${lang}] already has listingSeoDescription, skipping`);
    continue;
  }

  // Insert after the nextPage line
  const lines = content.split('\n');
  let inserted = false;
  for (let i = 0; i < lines.length; i++) {
    if (/nextPage\s*:/.test(lines[i]) && !lines[i].includes('//')) {
      // Detect indentation from the nextPage line
      const indent = lines[i].match(/^(\s*)/)[1];
      const newLines = [
        `${indent}listingSeoDescription: ${JSON.stringify(texts.listingSeoDescription)},`,
        `${indent}categorySeoDescription: ${JSON.stringify(texts.categorySeoDescription)},`,
      ];
      lines.splice(i + 1, 0, ...newLines);
      inserted = true;
      break;
    }
  }

  if (inserted) {
    fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
    console.log(`[${lang}] ✓ added listingSeoDescription + categorySeoDescription`);
    updated++;
  } else {
    console.log(`[${lang}] ✗ could not find nextPage insertion point`);
    skipped.push(lang);
  }
}

console.log(`\nDone: ${updated} updated, ${skipped.length} skipped (${skipped.join(', ')})`);
