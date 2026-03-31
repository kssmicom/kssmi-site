const fs = require('fs');
const path = require('path');

// Read translations from ts file directly without executing
const trContent = fs.readFileSync(path.join(__dirname, '../translations/index.ts'), 'utf8');
const trLines = trContent.split('\n');

const trMap = {};
let currentLang = '';

for (const line of trLines) {
    const langMatch = line.match(/^\s+([a-z]{2}|[a-z]{2}-[A-Z]{2}):\s*{/);
    if (langMatch) {
        currentLang = langMatch[1];
        trMap[currentLang] = {};
        continue;
    }

    if (!currentLang) continue;

    const extractMatch = (key) => {
        // Matches e.g.  lensMaterial: "Lenses Material",
        const reg = new RegExp(`^\\s*${key}:\\s*"([^"]+)",?`);
        const match = line.match(reg);
        if (match) {
            trMap[currentLang][key] = match[1];
        }
    };

    extractMatch('size');
    extractMatch('frameMaterial');
    extractMatch('lensMaterial');
    extractMatch('designStyle');
    extractMatch('nosePads');
    extractMatch('hinge');
    extractMatch('logo');
    extractMatch('service'); // specService mapped to 'service' in pt
    extractMatch('moq');     // specMoq mapped to 'moq' in pt
    extractMatch('electroplating');
}

// Ensure English has all values properly set just in case
if (!trMap['en']) trMap['en'] = {};
trMap['en'].size = trMap['en'].size || 'Size';
trMap['en'].frameMaterial = trMap['en'].frameMaterial || 'Frame Material';
trMap['en'].lensMaterial = trMap['en'].lensMaterial || 'Lenses Material';
trMap['en'].designStyle = trMap['en'].designStyle || 'Design Style';
trMap['en'].nosePads = trMap['en'].nosePads || 'Nose Pads';
trMap['en'].hinge = trMap['en'].hinge || 'Hinge';
trMap['en'].logo = trMap['en'].logo || 'Logo';
trMap['en'].service = trMap['en'].service || 'Service';
trMap['en'].moq = trMap['en'].moq || 'MOQ';
trMap['en'].electroplating = trMap['en'].electroplating || 'Electroplating Method';

function walkDir(dir, callback) {
    fs.readdirSync(dir).forEach(f => {
        let dirPath = path.join(dir, f);
        if (fs.statSync(dirPath).isDirectory()) {
            walkDir(dirPath, callback);
        } else {
            callback(path.join(dir, f));
        }
    });
}

let changedCount = 0;
walkDir(path.join(__dirname, '../content/products'), (file) => {
    if (!file.endsWith('.md')) return;

    const filename = path.basename(file);
    const parts = filename.split('.');
    const lang = parts.length > 2 ? parts[parts.length - 2] : 'en';

    if (!trMap[lang]) return;

    let content = fs.readFileSync(file, 'utf8');
    let originalContent = content;

    const replaceKey = (oldKeys, newKey) => {
        oldKeys.forEach(oldKey => {
            // replace strictly at start of line
            const reg = new RegExp(`^${oldKey}:\\s+`, 'gm');
            content = content.replace(reg, `${newKey}: `);
        });
    };

    const t = trMap[lang];

    replaceKey(['size', 'Size'], t.size);
    replaceKey(['frameMaterial', 'Frame Material'], t.frameMaterial);
    replaceKey(['lensMaterial', 'Lenses Material'], t.lensMaterial);
    replaceKey(['designStyle', 'Design Style'], t.designStyle);
    replaceKey(['nosePads', 'Nose Pads'], t.nosePads);
    replaceKey(['hinge', 'Hinge'], t.hinge);
    replaceKey(['logo', 'Logo'], t.logo);
    replaceKey(['specService', 'Service'], t.service);
    replaceKey(['specMoq', 'MOQ'], t.moq);
    replaceKey(['electroplating', 'Electroplating', 'Electroplating Method'], t.electroplating);

    if (content !== originalContent) {
        fs.writeFileSync(file, content, 'utf8');
        changedCount++;
    }
});

console.log(`Updated ${changedCount} files with full translated spec keys.`);
