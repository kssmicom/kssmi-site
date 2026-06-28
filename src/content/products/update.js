const fs = require('fs');
const path = require('path');

const dirs = [
  "d:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/content/products/kto-017-titanium-optical-frames",
  "d:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/content/products/kto-018-titanium-optical-frames",
  "d:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/content/products/kto-019-titanium-optical-frames"
];

for (const dir of dirs) {
  if (fs.existsSync(dir)) {
    const files = fs.readdirSync(dir).filter(f => f.endsWith('.md'));
    let updatedCount = 0;
    for (const file of files) {
      const filePath = path.join(dir, file);
      let content = fs.readFileSync(filePath, 'utf8');
      if (content.includes('itemNo: "kto-')) {
        content = content.replace(/itemNo:\s*"kto-/g, 'itemNo: "KTO-');
        fs.writeFileSync(filePath, content, 'utf8');
        updatedCount++;
      }
    }
    console.log(`Updated ${updatedCount} files in ${path.basename(dir)}`);
  } else {
    console.log(`Directory not found: ${dir}`);
  }
}
