const fs = require('fs');
const file = 'D:/001 Tools/004 Desk/Desk/Tools/Kssmi/kssmi-site/src/translations/index.ts';
let content = fs.readFileSync(file, 'utf8');

const replacements = [
  ['carbon_sun: "Occhiali da Sole Carbonio"', 'carbon_sun: "Occhiali da Sole in Fibra di Carbonio"'],
  ['carbon_glass: "Occhiali Carbonio"', 'carbon_glass: "Occhiali in Fibra di Carbonio"'],
  ['carbon_sun: "Gafas de Sol Carbono"', 'carbon_sun: "Gafas de Sol de Fibra de Carbono"'],
  ['carbon_glass: "Gafas de Carbono"', 'carbon_glass: "Gafas de Fibra de Carbono"'],
  ['carbon_sun: "Lunettes de Soleil Carbone"', 'carbon_sun: "Lunettes de Soleil en Fibre de Carbone"'],
  ['carbon_glass: "Lunettes de Carbone"', 'carbon_glass: "Lunettes en Fibre de Carbone"'],
  ['carbon_sun: "Kohlenstoff Sonnenbrillen"', 'carbon_sun: "Kohlefaser Sonnenbrillen"'],
  ['carbon_glass: "Kohlenstoff Brillen"', 'carbon_glass: "Kohlefaser Brillen"'],
  ['carbon_sun: "Óculos de Sol Carbono"', 'carbon_sun: "Óculos de Sol de Fibra de Carbono"'],
  ['carbon_glass: "Óculos de Carbono"', 'carbon_glass: "Óculos de Fibra de Carbono"'],
  ['carbon_sun: "カーボンサングラス"', 'carbon_sun: "カーボンファイバーサングラス"'],
  ['carbon_glass: "カーボン眼鏡"', 'carbon_glass: "カーボンファイバー眼鏡"'],
  ['carbon_sun: "Karbon Güneş Gözlüğü"', 'carbon_sun: "Karbon Elyaf Güneş Gözlüğü"'],
  ['carbon_glass: "Karbon Gözlük"', 'carbon_glass: "Karbon Elyaf Gözlük"'],
  ['carbon_sun: "카본 선글라스"', 'carbon_sun: "탄소 섬유 선글라스"'],
  ['carbon_glass: "카본 안경"', 'carbon_glass: "탄소 섬유 안경테"'],
  ['carbon_sun: "कार्बन धूप का चश्मा"', 'carbon_sun: "कार्बन फाइबर धूप का चश्मा"'],
  ['carbon_glass: "कार्बन चश्मा"', 'carbon_glass: "कार्बन फाइबर चश्मा"'],
  ['carbon_sun: "Kính mát Carbon"', 'carbon_sun: "Kính mát Sợi carbon"'],
  ['carbon_glass: "Kính quang học Carbon"', 'carbon_glass: "Gọng kính Sợi carbon"'],
  ['carbon_sun: "Kacamata Sun Carbon"', 'carbon_sun: "Kacamata Hitam Serat Karbon"'],
  ['carbon_glass: "Kacamata Kabar"', 'carbon_glass: "Kacamata Serat Karbon"'],
  ['carbon_sun: "Cermin Mata Hitam Karbon"', 'carbon_sun: "Cermin Mata Hitam Gentian Karbon"'],
  ['carbon_glass: "Cermin Mata Karbon"', 'carbon_glass: "Cermin Mata Gentian Karbon"'],
  ['carbon_sun: "Айнаки офтобии Карбон"', 'carbon_sun: "Айнаки офтобии Нахи карбон"'],
  ['carbon_glass: "Айнаки Карбон"', 'carbon_glass: "Айнаки Нахи карбон"']
];

for (const [find, replace] of replacements) {
  if (content.indexOf(find) === -1) {
    console.log("NOT FOUND: " + find);
  }
  content = content.replace(find, replace);
}

fs.writeFileSync(file, content, 'utf8');
console.log('Replacements complete');
