import fs from 'fs';

const filePath = './src/translations/index.ts';
let content = fs.readFileSync(filePath, 'utf8');

const addresses = {
    en: "1501, Bldg. 2, Baiwangda Health Tech Park, Yuanshan St., Longgang Dist., Shenzhen, Guangdong, China. 518127.",
    zh: "广东省 深圳市 龙岗区 园山街道办 百旺达健康科技产业园 2栋 1501 518127",
    ar: "1501، مبنى 2، حديقة بايوينغدا للصحة والتكنولوجيا، شارع يوانشان، منطقة لونغغانغ، شنتشن، قوانغدونغ، الصين. 518127.",
    it: "1501, Edificio 2, Baiwangda Health Tech Park, Via Yuanshan, Distretto di Longgang, Shenzhen, Guangdong, Cina. 518127.",
    es: "1501, Edificio 2, Baiwangda Health Tech Park, Calle Yuanshan, Distrito de Longgang, Shenzhen, Guangdong, China. 518127.",
    fr: "1501, Bâtiment 2, Baiwangda Health Tech Park, Rue Yuanshan, District de Longgang, Shenzhen, Guangdong, Chine. 518127.",
    de: "1501, Gebäude 2, Baiwangda Health Tech Park, Yuanshan Str., Bezirk Longgang, Shenzhen, Guangdong, China. 518127.",
    pt: "1501, Edifício 2, Baiwangda Health Tech Park, Rua Yuanshan, Distrito de Longgang, Shenzhen, Guangdong, China. 518127.",
    ru: "1501, Здание 2, Научный парк здоровья Байванда, улица Юаньшань, район Лунган, Шэньчжэнь, Гуандун, Китай. 518127.",
    ja: "中国広東省深セン市龍崗区園山街道百旺達健康科技産業園2棟1501号 518127",
    tr: "1501, Bina 2, Baiwangda Sağlık Teknoloji Parkı, Yuanshan Cd., Longgang İlçesi, Shenzhen, Guangdong, Çin. 518127.",
    ko: "중국 광둥성 선전시 룽강구 위안산 가도 바이왕다 건강 기술 산업 공원 2동 1501호 518127",
    hi: "1501, बिल्डिंग 2, बैवांगडा हेल्थ टेक पार्क, युआनशान स्ट्रीट, लोंगगैंग डिस्ट्रिक्ट, शेनझेन, ग्वांगडोंग, चीन. 518127.",
    vi: "1501, Tòa nhà 2, Công viên Công nghệ Y tế Baiwangda, Đường Yuanshan, Quận Longgang, Thâm Quyến, Quảng Đông, Trung Quốc. 518127.",
    jv: "1501, Gedung 2, Taman Teknologi Kesehatan Baiwangda, Jl. Yuanshan, Distrik Longgang, Shenzhen, Guangdong, China. 518127.",
    ms: "1501, Bangunan 2, Taman Teknologi Kesihatan Baiwangda, Jalan Yuanshan, Daerah Longgang, Shenzhen, Guangdong, China. 518127.",
    tg: "1501, Бинои 2, Парки Технологияҳои Тандурустии Байванда, Кӯчаи Юаншан, Ноҳияи Лонгганг, Шенжен, Гуандун, Чин. 518127."
};

let lines = content.split('\n');
let currentLang = null;

for (let i = 0; i < lines.length; i++) {
    let line = lines[i];

    let langMatch = line.match(/^  ([a-z]{2}):\s*\{/);
    if (langMatch) {
        currentLang = langMatch[1];
    }

    if (currentLang && addresses[currentLang]) {
        if (line.includes('address: "')) {
            lines[i] = line.replace(/address:\s*".*?"/, `address: "${addresses[currentLang]}"`);
            delete addresses[currentLang];
        }
    }
}

fs.writeFileSync(filePath, lines.join('\n'), 'utf8');
console.log('Translations updated.');
