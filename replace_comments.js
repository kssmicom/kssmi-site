const fs = require('fs');
const path = require('path');

const dir = 'd:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/content/products';
const divider = '# ─────────────────────────────────────────────────────';

function processFile(filePath) {
    let content = fs.readFileSync(filePath, 'utf8');
    let original = content;

    // We can just find all instances of:
    // # ────...
    // # [something]
    // # ────...
    // and replace with:
    // # ────...

    const regex = /# ─────────────────────────────────────────────────────\r?\n# (SECTION 1: PRODUCT IDENTITY.*?|SECTION 2: TECHNICAL SPECS.*?|INTERNAL \/ WEBSITE SETTINGS.*?|SEO)\r?\n# ─────────────────────────────────────────────────────/g;

    content = content.replace(regex, divider);

    if (content !== original) {
        fs.writeFileSync(filePath, content, 'utf8');
        console.log('Updated ' + filePath);
    }
}

function walkDir(currentDir) {
    const files = fs.readdirSync(currentDir);
    for (const file of files) {
        const fullPath = path.join(currentDir, file);
        if (fs.statSync(fullPath).isDirectory()) {
            walkDir(fullPath);
        } else if (fullPath.endsWith('.md')) {
            processFile(fullPath);
        }
    }
}

walkDir(dir);
