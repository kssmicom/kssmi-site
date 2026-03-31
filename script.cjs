const fs = require('fs');
let content = fs.readFileSync('src/translations/index.ts', 'utf8');

const translations = {
    en: { prev: 'Previous', next: 'Next' },
    ar: { prev: 'السابق', next: 'التالي' },
    it: { prev: 'Precedente', next: 'Successivo' },
    es: { prev: 'Anterior', next: 'Siguiente' },
    fr: { prev: 'Précédent', next: 'Suivant' },
    de: { prev: 'Vorherige', next: 'Nächste' },
    pt: { prev: 'Anterior', next: 'Próximo' },
    ru: { prev: 'Предыдущая', next: 'Следующая' },
    ja: { prev: '前へ', next: '次へ' },
    tr: { prev: 'Önceki', next: 'Sonraki' },
    ko: { prev: '이전', next: '다음' },
    zh: { prev: '上一页', next: '下一页' },
    hi: { prev: 'पिछला', next: 'अगला' },
    vi: { prev: 'Trước', next: 'Tiếp' },
    jv: { prev: 'Sadurunge', next: 'Sabanjure' },
    ms: { prev: 'Sebelumnya', next: 'Seterusnya' },
    tg: { prev: 'Гузашта', next: 'Оянда' }
};

for (const [lang, { prev, next }] of Object.entries(translations)) {
    const regex = new RegExp(`(${lang}\\s*:\\s*\\{[\\s\\S]*?product\\s*:\\s*\\{)`);
    content = content.replace(regex, (match, p1) => {
        return p1 + '\n      previousPage: "' + prev + '",\n      nextPage: "' + next + '",';
    });
}

fs.writeFileSync('src/translations/index.ts', content);
