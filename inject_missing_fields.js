const fs = require('fs');
const path = require('path');

const injectionBlock = `
# ─────────────────────────────────────────────────────
cover: "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-1.webp"
gallery:
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-2.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-2.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-3.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-4.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-5.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-6.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-7.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-8.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-9.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-10.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-11.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-12.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-13.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-14.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-15.webp"
  - "/media/products/kso-006-carbon-fiber-optical/oem-odm-customized-luxury-carbon-fiber-optical-glasses-yeto006-16.webp"
customizable: true
featured: true
categories: "Carbon Series, Carbon Glasses"
`;

const dir = 'D:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/content/products/kso-006-carbon-fiber-optical';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.md') && !f.endsWith('.en.md'));

files.forEach(file => {
    const filePath = path.join(dir, file);
    let content = fs.readFileSync(filePath, 'utf8');
    
    if (content.includes('cover:')) {
        console.log('Skipping ' + file + ', already has cover.');
        return;
    }
    
    const target = '# ─────────────────────────────────────────────────────\r\nseoTitle:';
    const target2 = '# ─────────────────────────────────────────────────────\nseoTitle:';
    
    if (content.includes(target)) {
        content = content.replace(target, injectionBlock.trim() + '\n\n' + target);
        fs.writeFileSync(filePath, content, 'utf8');
        console.log('Successfully injected into ' + file);
    } else if (content.includes(target2)) {
        content = content.replace(target2, injectionBlock.trim() + '\n\n' + target2);
        fs.writeFileSync(filePath, content, 'utf8');
        console.log('Successfully injected into ' + file + ' (LF)');
    } else {
        console.log('Could not find insertion point in ' + file);
    }
});
