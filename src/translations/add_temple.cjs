const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'index.ts');
let content = fs.readFileSync(filePath, 'utf8');

const translationsToAdd = {
  en: "Temple",
  zh: "镜腿",
  it: "Aste",
  es: "Patillas",
  fr: "Branches",
  de: "Bügel",
  pt: "Hastes",
  ru: "Заушники",
  ja: "テンプル",
  tr: "Saplar",
  ar: "الأذرع",
  hi: "टेम्पल",
  ko: "안경다리",
  ms: "Tangkai",
  vi: "Càng kính",
  jv: "Gagang",
  tg: "Дастакҳо"
};

for (const [lang, t] of Object.entries(translationsToAdd)) {
    const langRegex = new RegExp(`(  ${lang}:\\s*{[\\s\\S]*?)(\\s+hinge:\\s*["'][^"']+["'],?)(?=\\s|\\n)`, 'g');
    content = content.replace(langRegex, (match, before, hingeLine) => {
        const appendStr = `\n      temple: "${t}",`;
        return before + hingeLine + appendStr;
    });
}

fs.writeFileSync(filePath, content, 'utf8');
console.log('Translations updated for temple.');
