const fs = require('fs');
const path = require('path');

const directoryPath = path.join(__dirname, 'src', 'content', 'products');

const audiences = {
    en: [
        '- Premium Eyewear Brands',
        '- Independent Optical Retailers',
        '- Boutique Fashion Labels',
        '- Private Label Distributors',
        '- Regional Eyewear Wholesalers'
    ],
    zh: [
        '- 高端眼镜品牌',
        '- 独立眼镜零售商',
        '- 精品时尚品牌',
        '- 自有品牌分销商',
        '- 区域眼镜批发商'
    ],
    it: [
        '- Marchi di Occhiali Premium',
        '- Rivenditori Indipendenti di Ottica',
        '- Marchi di Moda Boutique',
        '- Distributori a Marchio Privato',
        '- Grossisti Regionali di Occhiali'
    ],
    es: [
        '- Marcas de Gafas Premium',
        '- Minoristas Ópticos Independientes',
        '- Marcas de Moda Boutique',
        '- Distribuidores de Marca Privada',
        '- Mayoristas Regionales de Gafas'
    ],
    fr: [
        '- Marques de Lunettes Premium',
        '- Détaillants d\'Optique Indépendants',
        '- Maisons de Mode Boutique',
        '- Distributeurs de Marques Privées',
        '- Grossistes Régionaux en Lunetterie'
    ],
    de: [
        '- Premium-Brillenmarken',
        '- Unabhängige Optikerfachhändler',
        '- Boutique-Modelabels',
        '- Private-Label-Distributoren',
        '- Regionale Brillengroßhändler'
    ],
    pt: [
        '- Marcas de Óculos Premium',
        '- Varejistas Independentes de Ótica',
        '- Marcas de Moda Boutique',
        '- Distribuidores de Marca Própria',
        '- Atacadistas Regionais de Óculos'
    ],
    ru: [
        '- Премиальные бренды очков',
        '- Независимые оптики',
        '- Бутиковые модные бренды',
        '- Дистрибьюторы частных торговых марок',
        '- Региональные оптовые продавцы очков'
    ],
    ja: [
        '- プレミアムアイウェアブランド',
        '- 独立系メガネ小売業者',
        '- ブティックファッションブランド',
        '- プライベートレーベル代理店',
        '- 地域アイウェア卸売業者'
    ],
    tr: [
        '- Premium Gözlük Markaları',
        '- Bağımsız Optik Perakendeciler',
        '- Butik Moda Markaları',
        '- Özel Marka Distribütörleri',
        '- Bölgesel Gözlük Toptancıları'
    ],
    ar: [
        '- العلامات التجارية الفاخرة للنظارات',
        '- تجار التجزئة المستقلين للبصريات',
        '- ماركات أزياء البوتيك',
        '- موزعي العلامات التجارية الخاصة',
        '- تجار الجملة الإقليميين للنظارات'
    ],
    ko: [
        '- 프리미엄 안경 브랜드',
        '- 독립 안경 소매업체',
        '- 부티크 패션 브랜드',
        '- 프라이빗 라벨 유통업체',
        '- 지역 안경 도매업체'
    ],
    hi: [
        '- प्रीमियम आईवियर ब्रांड',
        '- स्वतंत्र ऑप्टिकल खुदरा विक्रेता',
        '- बुटीक फैशन लेबल',
        '- निजी लेबल वितरक',
        '- क्षेत्रीय आईवियर थोक व्यापारी'
    ],
    vi: [
        '- Thương Hiệu Kính Mắt Cao Cấp',
        '- Bán Lẻ Kính Mắt Độc Lập',
        '- Thương Hiệu Thời Trang Boutique',
        '- Nhà Phân Phối Thương Hiệu Riêng',
        '- Nhà Bán Buôn Kính Mắt Khu Vực'
    ],
    jv: [
        '- Merk Kacamata Premium',
        '- Pengecer Optik Independen',
        '- Label Fashion Butik',
        '- Distributor Label Pribadi',
        '- Pengecer Grosir Kacamata Regional'
    ],
    ms: [
        '- Jenama Cermin Mata Premium',
        '- Peruncit Optik Bebas',
        '- Label Fesyen Butik',
        '- Pengedar Label Peribadi',
        '- Pemborong Cermin Mata Serantau'
    ],
    tg: [
        '- Брендҳои Айнаки Премиум',
        '- Фурӯшандагони Мустақили Оптикӣ',
        '- Брендҳои Мӯди Бутик',
        '- Дистрибюторҳои Тамғаи Хусусӣ',
        '- Фурӯшандагони Яклухти Минтақавии Айнак'
    ]
};

fs.readdir(directoryPath, (err, files) => {
    if (err) {
        return console.log('Unable to scan directory: ' + err);
    }

    let updatedCount = 0;

    files.forEach((file) => {
        if (file.endsWith('.md')) {
            const parts = file.split('.');
            const lang = parts.length >= 3 ? parts[parts.length - 2] : 'en';

            const newItems = audiences[lang];
            if (!newItems) {
                console.log(`No translation for lang: ${lang} in file ${file}`);
                return;
            }

            const filePath = path.join(directoryPath, file);
            let content = fs.readFileSync(filePath, 'utf8');

            // Find the ## Perfect For heading (it's the second ## heading in the document)
            const headingRegex = /^##\s+.*$/gm;
            let match;
            let headingIndices = [];

            while ((match = headingRegex.exec(content)) !== null) {
                headingIndices.push(match.index);
            }

            if (headingIndices.length >= 2) {
                // Find the index of the second ## heading
                const secondHeadingStart = headingIndices[1];

                // Find the first line after the heading
                const contentAfterHeading = content.slice(secondHeadingStart);
                const nextNewline = contentAfterHeading.indexOf('\n') + 1;

                const insertPos = secondHeadingStart + nextNewline;

                // Replace all remaining text after the new line with the new items
                const newText = '\n' + newItems.join('\n') + '\n';
                content = content.slice(0, insertPos) + newText;

                fs.writeFileSync(filePath, content, 'utf8');
                updatedCount++;
            }
        }
    });

    console.log(`Successfully rewrote audiences in ${updatedCount} markdown files.`);
});
