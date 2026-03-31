const fs = require('fs');
const path = require('path');

// Read translations from ts file directly without executing
const trContent = fs.readFileSync(path.join(__dirname, '../translations/index.ts'), 'utf8');
const trLines = trContent.split('\n');

const electroplatingTr = {};
let currentLang = '';

for (const line of trLines) {
    // Simple extraction: e.g. "  en: {"
    const langMatch = line.match(/^\s+([a-z]{2}|[a-z]{2}-[A-Z]{2}):\s*{/);
    if (langMatch) {
        currentLang = langMatch[1];
        continue;
    }

    // E.g. electroplating: "Electroplating Method",
    const attrMatch = line.match(/^\s*electroplating:\s*"([^"]+)",?/);
    if (attrMatch && currentLang) {
        electroplatingTr[currentLang] = attrMatch[1];
    }
}

console.log('Found translations for electroplating:', Object.keys(electroplatingTr).length);

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

    // Extract language from filename, e.g. product.it.md -> it
    const filename = path.basename(file);
    const parts = filename.split('.');
    const lang = parts.length > 2 ? parts[parts.length - 2] : 'en';

    let content = fs.readFileSync(file, 'utf8');
    const targetLabel = electroplatingTr[lang] || 'Electroplating Method';

    // We need to match both 'electroplating:' and 'Electroplating:' since we replaced some earlier
    if (content.match(/^electroplating:/m) || content.match(/^Electroplating:/m)) {
        content = content.replace(/^electroplating:/gm, `${targetLabel}:`);
        content = content.replace(/^Electroplating:/gm, `${targetLabel}:`);
        fs.writeFileSync(file, content, 'utf8');
        changedCount++;
    }
});

console.log('Updated ' + changedCount + ' files with translated Electroplating keys.');
