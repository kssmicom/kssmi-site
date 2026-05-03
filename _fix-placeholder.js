const fs = require('fs');
const path = 'src/pages/[lang]/404.astro';
let content = fs.readFileSync(path, 'utf-8');

// Count before
const before = (content.match(/\\n/g) || []).length;
console.log('Before:', before, 'occurrences of \\n');

// Replace literal backslash-n with space
content = content.replace(/\\n/g, ' ');

const after = (content.match(/\\n/g) || []).length;
console.log('After:', after, 'occurrences of \\n');

fs.writeFileSync(path, content);
console.log('Done');
