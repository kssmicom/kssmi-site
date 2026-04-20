const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'index.ts');
let content = fs.readFileSync(filePath, 'utf8');

const translationsToAdd = {
    en: {
        secure256: "Secure 256-bit Encrypted",
        ndasAvailable: "NDAs available",
        contactDirectly: "Or contact us directly:"
    },
    ar: {
        secure256: "تشفير آمن 256 بت",
        ndasAvailable: "اتفاقيات عدم الإفصاح متاحة",
        contactDirectly: "أو اتصل بنا مباشرة:"
    },
    it: {
        secure256: "Sicuro Crittografato a 256 bit",
        ndasAvailable: "NDA disponibili",
        contactDirectly: "Oppure contattaci direttamente:"
    },
    es: {
        secure256: "Seguridad Encriptada de 256 bits",
        ndasAvailable: "NDA disponibles",
        contactDirectly: "O contáctenos directamente:"
    },
    fr: {
        secure256: "Sécurité Cryptée 256 bits",
        ndasAvailable: "NDA disponibles",
        contactDirectly: "Ou contactez-nous directement :"
    },
    de: {
        secure256: "Sicher 256-Bit Verschlüsselt",
        ndasAvailable: "NDA verfügbar",
        contactDirectly: "Oder kontaktieren Sie uns direkt:"
    },
    pt: {
        secure256: "Seguro Criptografado de 256 bits",
        ndasAvailable: "NDAs disponíveis",
        contactDirectly: "Ou contate-nos diretamente:"
    },
    ru: {
        secure256: "Надежное 256-битное шифрование",
        ndasAvailable: "Доступны NDA",
        contactDirectly: "Или свяжитесь с нами напрямую:"
    },
    ja: {
        secure256: "安全な256ビット暗号化",
        ndasAvailable: "NDA対応可能",
        contactDirectly: "または直接お問い合わせください："
    },
    tr: {
        secure256: "Güvenli 256-bit Şifrelenmiş",
        ndasAvailable: "NDA mevcut",
        contactDirectly: "Veya doğrudan bizimle iletişime geçin:"
    }
};

for (const [lang, t] of Object.entries(translationsToAdd)) {
    // We look for a line like `submitRequest: "...",` within the specific language block.
    // We'll use a regex that matches the language block start `lang: {` and then finds `submitRequest:` inside.
    // A simpler way: just string replace the specific submitRequest line for that language if we know it.
    // Actually, we can just find `submitRequest: ` inside each language's section.

    const langRegex = new RegExp(`(\\s+${lang}:\\s*{[\\s\\S]*?)(\\s+submitRequest:\\s*["'][^"']+["'],?)(?=\\s|\\n)`, 'g');

    content = content.replace(langRegex, (match, before, submitRequestLine) => {
        const appendStr = `\n      secure256: "${t.secure256}",\n      ndasAvailable: "${t.ndasAvailable}",\n      contactDirectly: "${t.contactDirectly}",`;
        return before + submitRequestLine + appendStr;
    });
}

fs.writeFileSync(filePath, content, 'utf8');
console.log('Translations updated.');
