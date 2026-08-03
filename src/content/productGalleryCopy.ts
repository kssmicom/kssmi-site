export interface ProductGalleryCopy {
  mainView: string;
  additionalView: string;
  openGallery: string;
  thumbnails: string;
  close: string;
  previous: string;
  next: string;
  imageOf: string;
}

export const productGalleryCopy: Record<string, ProductGalleryCopy> = {
  en: { mainView: 'main product view', additionalView: 'additional product view', openGallery: 'Open full-screen image gallery', thumbnails: 'Product image thumbnails', close: 'Close image gallery', previous: 'Previous image', next: 'Next image', imageOf: 'Image {current} of {total}' },
  zh: { mainView: '产品主视图', additionalView: '产品附加视图', openGallery: '打开全屏产品图库', thumbnails: '产品图片缩略图', close: '关闭产品图库', previous: '上一张图片', next: '下一张图片', imageOf: '第 {current} 张，共 {total} 张' },
  it: { mainView: 'vista principale del prodotto', additionalView: 'vista aggiuntiva del prodotto', openGallery: 'Apri la galleria a schermo intero', thumbnails: 'Miniature delle immagini del prodotto', close: 'Chiudi la galleria', previous: 'Immagine precedente', next: 'Immagine successiva', imageOf: 'Immagine {current} di {total}' },
  es: { mainView: 'vista principal del producto', additionalView: 'vista adicional del producto', openGallery: 'Abrir galería a pantalla completa', thumbnails: 'Miniaturas del producto', close: 'Cerrar galería', previous: 'Imagen anterior', next: 'Imagen siguiente', imageOf: 'Imagen {current} de {total}' },
  fr: { mainView: 'vue principale du produit', additionalView: 'vue supplémentaire du produit', openGallery: 'Ouvrir la galerie en plein écran', thumbnails: 'Miniatures du produit', close: 'Fermer la galerie', previous: 'Image précédente', next: 'Image suivante', imageOf: 'Image {current} sur {total}' },
  de: { mainView: 'Hauptansicht des Produkts', additionalView: 'weitere Produktansicht', openGallery: 'Vollbildgalerie öffnen', thumbnails: 'Produktbild-Miniaturen', close: 'Galerie schließen', previous: 'Vorheriges Bild', next: 'Nächstes Bild', imageOf: 'Bild {current} von {total}' },
  pt: { mainView: 'vista principal do produto', additionalView: 'vista adicional do produto', openGallery: 'Abrir galeria em ecrã inteiro', thumbnails: 'Miniaturas do produto', close: 'Fechar galeria', previous: 'Imagem anterior', next: 'Imagem seguinte', imageOf: 'Imagem {current} de {total}' },
  ar: { mainView: 'العرض الرئيسي للمنتج', additionalView: 'عرض إضافي للمنتج', openGallery: 'فتح معرض الصور بملء الشاشة', thumbnails: 'صور المنتج المصغرة', close: 'إغلاق معرض الصور', previous: 'الصورة السابقة', next: 'الصورة التالية', imageOf: 'الصورة {current} من {total}' },
  ru: { mainView: 'основной вид товара', additionalView: 'дополнительный вид товара', openGallery: 'Открыть полноэкранную галерею', thumbnails: 'Миниатюры товара', close: 'Закрыть галерею', previous: 'Предыдущее изображение', next: 'Следующее изображение', imageOf: 'Изображение {current} из {total}' },
  ja: { mainView: '商品のメインビュー', additionalView: '商品の追加ビュー', openGallery: '全画面ギャラリーを開く', thumbnails: '商品画像のサムネイル', close: 'ギャラリーを閉じる', previous: '前の画像', next: '次の画像', imageOf: '{total}枚中{current}枚目' },
  tr: { mainView: 'ürünün ana görünümü', additionalView: 'ürünün ek görünümü', openGallery: 'Tam ekran galeriyi aç', thumbnails: 'Ürün görseli küçük resimleri', close: 'Galeriyi kapat', previous: 'Önceki görsel', next: 'Sonraki görsel', imageOf: '{total} görselden {current}.' },
  ko: { mainView: '제품 기본 보기', additionalView: '제품 추가 보기', openGallery: '전체 화면 갤러리 열기', thumbnails: '제품 이미지 미리보기', close: '갤러리 닫기', previous: '이전 이미지', next: '다음 이미지', imageOf: '전체 {total}장 중 {current}번째' },
  hi: { mainView: 'उत्पाद का मुख्य दृश्य', additionalView: 'उत्पाद का अतिरिक्त दृश्य', openGallery: 'फ़ुल-स्क्रीन गैलरी खोलें', thumbnails: 'उत्पाद छवि थंबनेल', close: 'गैलरी बंद करें', previous: 'पिछली छवि', next: 'अगली छवि', imageOf: '{total} में से छवि {current}' },
  vi: { mainView: 'góc nhìn chính của sản phẩm', additionalView: 'góc nhìn bổ sung của sản phẩm', openGallery: 'Mở thư viện ảnh toàn màn hình', thumbnails: 'Ảnh thu nhỏ của sản phẩm', close: 'Đóng thư viện ảnh', previous: 'Ảnh trước', next: 'Ảnh tiếp theo', imageOf: 'Ảnh {current} trên {total}' },
  jv: { mainView: 'tampilan utama produk', additionalView: 'tampilan tambahan produk', openGallery: 'Bukak galeri layar wutuh', thumbnails: 'Gambar cilik produk', close: 'Tutup galeri', previous: 'Gambar sadurunge', next: 'Gambar sabanjure', imageOf: 'Gambar {current} saka {total}' },
  ms: { mainView: 'pandangan utama produk', additionalView: 'pandangan tambahan produk', openGallery: 'Buka galeri skrin penuh', thumbnails: 'Imej kecil produk', close: 'Tutup galeri', previous: 'Imej sebelumnya', next: 'Imej seterusnya', imageOf: 'Imej {current} daripada {total}' },
  tg: { mainView: 'намуди асосии маҳсулот', additionalView: 'намуди иловагии маҳсулот', openGallery: 'Кушодани галерея дар экрани пурра', thumbnails: 'Тасвирҳои хурди маҳсулот', close: 'Пӯшидани галерея', previous: 'Тасвири қаблӣ', next: 'Тасвири навбатӣ', imageOf: 'Тасвири {current} аз {total}' },
};
