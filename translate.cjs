const fs = require('fs');
const path = require('path');

const translateMap = {
    'en': {},
    'ko': {
        "Luxury Customized": "럭셔리 맞춤형",
        "Luxury Glasses": "럭셔리 안경",
        "Premium Metal": "프리미엄 메탈",
        "Premium Acetate": "프리미엄 아세테이트",
        "Premium Metal and Acetate": "프리미엄 메탈 및 아세테이트",
        "Golden": "골드",
        "Silvery": "실버",
        "Copper": "코퍼",
        "Blue": "블루",
        "Tortoise": "톨토이즈",
        "Nylon Lens": "나일론 렌즈",
        "TAC Lens": "TAC 렌즈",
        "Luxury, Classic, Unisex": "럭셔리, 클래식, 남녀공용",
        "Adjustable Metal Nose Pads": "조절 가능한 메탈 코 패드",
        "Spring Hinge": "스프링 힌지",
        "IP Plating": "IP 도금",
        "ION Plating": "ION 도금",
        "Print": "프린트",
        "Laser": "레이저",
        "Laser With Oil": "오일 레이저",
        "Hot Stamping": "핫 스탬핑",
        "Metal": "메탈",
        "Crystal": "크리스털",
        "Up-sticker": "업 스티커"
    },
    'zh': {
        "Luxury Customized": "奢华定制",
        "Luxury Glasses": "奢华眼镜",
        "Premium Metal": "高级金属",
        "Premium Acetate": "高级板材",
        "Premium Metal and Acetate": "高级金属与板材",
        "Golden": "金色",
        "Silvery": "银色",
        "Copper": "铜色",
        "Blue": "蓝色",
        "Tortoise": "玳瑁色",
        "Nylon Lens": "尼龙镜片",
        "TAC Lens": "TAC镜片",
        "Luxury, Classic, Unisex": "奢华、经典、中性",
        "Adjustable Metal Nose Pads": "可调节金属鼻托",
        "Spring Hinge": "弹簧铰链",
        "IP Plating": "IP电镀",
        "ION Plating": "ION电镀",
        "Print": "印刷",
        "Laser": "激光",
        "Laser With Oil": "注油激光",
        "Hot Stamping": "烫金",
        "Metal": "金属",
        "Crystal": "水晶",
        "Up-sticker": "立体贴纸"
    },
    'hi': {
        "Luxury Customized": "लक्ज़री कस्टमाइज़्ड",
        "Luxury Glasses": "लक्ज़री चश्मा",
        "Premium Metal": "प्रीमियम धातु",
        "Premium Acetate": "प्रीमियम एसीटेट",
        "Premium Metal and Acetate": "प्रीमियम धातु और एसीटेट",
        "Golden": "सुनहरा",
        "Silvery": "चांदी सा",
        "Copper": "तांबा",
        "Blue": "नीला",
        "Tortoise": "कछुआ",
        "Nylon Lens": "नायलॉन लेंस",
        "TAC Lens": "टीएसी लेंस",
        "Luxury, Classic, Unisex": "लक्ज़री, क्लासिक, यूनिसेक्स",
        "Adjustable Metal Nose Pads": "समायोज्य धातु नाक पैड",
        "Spring Hinge": "स्प्रिंग हिंज",
        "IP Plating": "आईपी प्लेटिंग",
        "ION Plating": "आयन प्लेटिंग",
        "Print": "प्रिंट",
        "Laser": "लेजर",
        "Laser With Oil": "तेल के साथ लेजर",
        "Hot Stamping": "हॉट स्टैम्पिंग",
        "Metal": "धातु",
        "Crystal": "क्रिस्टल",
        "Up-sticker": "अप-स्टिकर"
    },
    'vi': {
        "Luxury Customized": "Tùy chỉnh sang trọng",
        "Luxury Glasses": "Kính sang trọng",
        "Premium Metal": "Kim loại cao cấp",
        "Premium Acetate": "Acetate cao cấp",
        "Premium Metal and Acetate": "Kim loại và Acetate cao cấp",
        "Golden": "Vàng",
        "Silvery": "Bạc",
        "Copper": "Đồng",
        "Blue": "Xanh lam",
        "Tortoise": "Đồi mồi",
        "Nylon Lens": "Tròng kính Nylon",
        "TAC Lens": "Tròng kính TAC",
        "Luxury, Classic, Unisex": "Sang trọng, Cổ điển, Unisex",
        "Adjustable Metal Nose Pads": "Đệm mũi kim loại",
        "Spring Hinge": "Bản lề lò xo",
        "IP Plating": "Mạ IP",
        "ION Plating": "Mạ ION",
        "Print": "In",
        "Laser": "Laser",
        "Laser With Oil": "Laser phủ dầu",
        "Hot Stamping": "Ép kim",
        "Metal": "Kim loại",
        "Crystal": "Pha lê",
        "Up-sticker": "Nhãn nổi"
    },
    'jv': {
        "Luxury Customized": "Kustomisasi Mewah",
        "Luxury Glasses": "Kacamata Mewah",
        "Premium Metal": "Logam Premium",
        "Premium Acetate": "Asetat Premium",
        "Premium Metal and Acetate": "Logam lan Asetat",
        "Golden": "Emas",
        "Silvery": "Perak",
        "Copper": "Tembaga",
        "Blue": "Biru",
        "Tortoise": "Penyu",
        "Nylon Lens": "Lensa Nilon",
        "TAC Lens": "Lensa TAC",
        "Luxury, Classic, Unisex": "Mewah, Klasik, Uniseks",
        "Adjustable Metal Nose Pads": "Bantalan Irung Logam",
        "Spring Hinge": "Engsel Pegas",
        "IP Plating": "Pelapisan IP",
        "ION Plating": "Pelapisan ION",
        "Print": "Cetak",
        "Laser": "Laser",
        "Laser With Oil": "Laser Lengo",
        "Hot Stamping": "Stamping Panas",
        "Metal": "Logam",
        "Crystal": "Kristal",
        "Up-sticker": "Stiker Munggah"
    },
    'ms': {
        "Luxury Customized": "Disesuaikan Mewah",
        "Luxury Glasses": "Cermin Mata Mewah",
        "Premium Metal": "Logam Premium",
        "Premium Acetate": "Asetat Premium",
        "Premium Metal and Acetate": "Logam dan Asetat",
        "Golden": "Emas",
        "Silvery": "Perak",
        "Copper": "Tembaga",
        "Blue": "Biru",
        "Tortoise": "Kura-kura",
        "Nylon Lens": "Kanta Nilon",
        "TAC Lens": "Kanta TAC",
        "Luxury, Classic, Unisex": "Mewah, Klasik, Uniseks",
        "Adjustable Metal Nose Pads": "Pelapik Hidung",
        "Spring Hinge": "Engsel Spring",
        "IP Plating": "Penyaduran IP",
        "ION Plating": "Penyaduran ION",
        "Print": "Cetak",
        "Laser": "Laser",
        "Laser With Oil": "Laser Minyak",
        "Hot Stamping": "Setem Panas",
        "Metal": "Logam",
        "Crystal": "Kristal",
        "Up-sticker": "Pelekat"
    },
    'tg': {
        "Luxury Customized": "Боҳашамати Фармоишӣ",
        "Luxury Glasses": "Айнаки Боҳашамат",
        "Premium Metal": "Металли Премиум",
        "Premium Acetate": "Атсетати Премиум",
        "Premium Metal and Acetate": "Металл ва Атсетат",
        "Golden": "Тиллоӣ",
        "Silvery": "Нуқрагӣ",
        "Copper": "Мисӣ",
        "Blue": "Кабуд",
        "Tortoise": "Сангпуштӣ",
        "Nylon Lens": "Линзаи Нейлонӣ",
        "TAC Lens": "Линзаи TAC",
        "Luxury, Classic, Unisex": "Боҳашамат, Классикӣ, Унисекс",
        "Adjustable Metal Nose Pads": "Гӯшакҳои Бинии Металлӣ",
        "Spring Hinge": "Ҳалқаи Пружинагӣ",
        "IP Plating": "Рангкунии IP",
        "ION Plating": "Рангкунии ION",
        "Print": "Чоп",
        "Laser": "Лазер",
        "Laser With Oil": "Лазер бо Равған",
        "Hot Stamping": "Штампкунии Гарм",
        "Metal": "Металл",
        "Crystal": "Кристалл",
        "Up-sticker": "Стикер"
    },
    'fr': {
        "Luxury Customized": "Luxe Personnalisé",
        "Luxury Glasses": "Lunettes de Luxe"
    },
    'es': {
        "Luxury Customized": "Lujo Personalizado",
        "Luxury Glasses": "Gafas de Lujo"
    },
    'it': {
        "Luxury Customized": "Lusso Personalizzato",
        "Luxury Glasses": "Occhiali di Lusso"
    },
    'ja': {
        "Luxury Customized": "高級カスタマイズ",
        "Luxury Glasses": "高級メガネ"
    },
    'ar': {
        "Luxury Customized": "فخامة مخصصة",
        "Luxury Glasses": "نظارات فاخرة"
    },
    'de': {
        "Luxury Customized": "Luxus Maßgeschneidert",
        "Luxury Glasses": "Luxusbrillen"
    },
    'pt': {
        "Luxury Customized": "Luxo Personalizado",
        "Luxury Glasses": "Óculos de Luxo"
    },
    'ru': {
        "Luxury Customized": "Индивидуальная Роскошь",
        "Luxury Glasses": "Роскошные Очки"
    },
    'tr': {
        "Luxury Customized": "Özel Lüks",
        "Luxury Glasses": "Lüks Gözlükler"
    }
};

const productsDir = path.join(__dirname, 'src', 'content', 'products');
const files = fs.readdirSync(productsDir).filter(f => f.endsWith('.md'));

let changedFiles = 0;

for (const file of files) {
    const parts = file.split('.');
    if (parts.length < 3) continue;
    const lang = parts[parts.length - 2];
    if (lang === 'en') continue;

    const dict = translateMap[lang];
    if (!dict) continue;

    let content = fs.readFileSync(path.join(productsDir, file), 'utf-8');
    const original = content;

    // Only replace in the frontmatter
    let parts2 = content.split('---');
    if (parts2.length >= 3) {
        let frontmatter = parts2[1];

        for (const [en, trans] of Object.entries(dict)) {
            const re = new RegExp(`"${en}"`, 'g');
            frontmatter = frontmatter.replace(re, `"${trans}"`);
        }

        parts2[1] = frontmatter;
        content = parts2.join('---');

        if (content !== original) {
            fs.writeFileSync(path.join(productsDir, file), content, 'utf-8');
            changedFiles++;
            console.log(`Updated ${file}`);
        }
    }
}

console.log(`Successfully updated ${changedFiles} files.`);
