import fs from 'fs';
import path from 'path';
import yaml from 'js-yaml';

const publicDir = path.join(process.cwd(), 'public');
const SITE_URL = 'https://kssmi.com';

if (!fs.existsSync(publicDir)) {
    fs.mkdirSync(publicDir, { recursive: true });
}

const currentDate = new Date().toISOString().split('T')[0];

const parseFrontmatter = (fileContent) => {
    const match = fileContent.match(/---\r?\n([\s\S]*?)\r?\n---/);
    if (!match || !match[1]) return null;

    const frontmatter = match[1];

    const getValue = (key) => {
        const regex = new RegExp(`${key}:\\s*(.*)`);
        const m = frontmatter.match(regex);
        return m ? m[1].replace(/['"]/g, '').trim() : null;
    };

    const getArray = (key) => {
        const regex = new RegExp(`${key}:\\s*\\[(.*?)\\]`);
        const m = frontmatter.match(regex);
        return m ? m[1].split(',').map(s => s.replace(/['"]/g, '').trim()) : null;
    };

    const overviewMatch = fileContent.match(/##[^\n]*Overview\s*\n\n(.*?)\n/is) || 
                          fileContent.match(/##[^#]*\n\n(.*?)\n/is);
    const textSnippet = overviewMatch && overviewMatch[1] 
        ? overviewMatch[1].replace(/\n/g, ' ').replace(/[<>]/g, '').substring(0, 300).trim() + '...' 
        : null;

    const fm = (() => {
        try {
            return yaml.load(frontmatter) || {};
        } catch {
            return {};
        }
    })();

    const cleanStr = (val) => (typeof val === 'string' ? val.replace(/\s+/g, ' ').trim() : null);

    return {
        title: cleanStr(fm.title) || getValue('title'),
        description: cleanStr(fm.excerpt) || cleanStr(fm.seoDescription) || cleanStr(fm.description) || getValue('description') || getValue('seoDescription') || getValue('excerpt'),
        slug: cleanStr(fm.slug) || getValue('slug'),
        itemNo: cleanStr(fm.itemNo) || getValue('itemNo'),
        categories: Array.isArray(fm.categories) ? fm.categories : getArray('categories'),
        materials: Array.isArray(fm.materials) ? fm.materials : getArray('materials'),
        serviceMode: Array.isArray(fm.serviceMode) ? fm.serviceMode : getArray('serviceMode'),
        moq: cleanStr(fm.moq) || getValue('moq'),
        pubDate: fm.published instanceof Date
            ? fm.published.toISOString().split('T')[0]
            : (cleanStr(fm.pubDate) || cleanStr(fm.published) || getValue('pubDate') || getValue('published')),
        snippet: textSnippet
    };
};

const langData = {};

const processCollection = (collectionName, titlePathPattern) => {
    const dir = path.join(process.cwd(), `src/content/${collectionName}`);
    if (!fs.existsSync(dir)) return;

    const getAllFiles = (dirPath, arrayOfFiles = []) => {
        const files = fs.readdirSync(dirPath);
        files.forEach(file => {
            const absolutePath = path.join(dirPath, file);
            if (fs.statSync(absolutePath).isDirectory()) {
                arrayOfFiles = getAllFiles(absolutePath, arrayOfFiles);
            } else if (file.endsWith('.md') || file.endsWith('.mdx')) {
                arrayOfFiles.push(absolutePath);
            }
        });
        return arrayOfFiles;
    };

    const files = getAllFiles(dir);

    files.forEach(filePath => {
        const file = path.basename(filePath);
        const content = fs.readFileSync(filePath, 'utf-8');
        const data = parseFrontmatter(content);

        if (data && data.title) {
            const langMatch = file.match(/\.([a-z]{2})\.mdx?$/);
            const lang = langMatch ? langMatch[1] : 'en';

            if (!langData[lang]) {
                langData[lang] = { products: [], landing: [], blog: [], faq: [] };
            }

            const urlPrefix = lang === 'en' ? '' : `/${lang}`;
            const slugPart = data.slug ? data.slug.replace(/\/$/, '') : file.replace(/\.[a-z]{2}\.mdx?$/, '').replace(/\.md$/, '');
            const urlPathParams = titlePathPattern ? `${titlePathPattern}/` : '';
            const trailing = collectionName === 'blog' ? '/' : '';
            const url = `${SITE_URL}${urlPrefix}/${urlPathParams}${slugPart}${trailing}`;

            const label = data.itemNo ? `${data.title} (${data.itemNo})` : `${data.title}`;

            let entry = `- [${label}](${url})\n`;
            if (data.categories) entry += `  > Categories: ${data.categories.join(', ')}\n`;
            if (data.materials) entry += `  > Materials: ${data.materials.join(', ')}\n`;
            if (data.serviceMode) entry += `  > Service Modes: ${data.serviceMode.join(', ')}\n`;
            if (data.moq) entry += `  > MOQ: ${data.moq}\n`;
            if (data.pubDate) entry += `  > Published: ${data.pubDate}\n`;
            const summaryText = collectionName === 'blog' ? (data.description || data.snippet) : data.snippet;
            if (summaryText) entry += `  > Summary: ${summaryText}\n`;
            entry += `\n`;

            if (langData[lang][collectionName] !== undefined) {
                langData[lang][collectionName].push(entry);
            }
        }
    });
};

processCollection('products', 'product');
processCollection('blog', 'blog');

const faqRootDir = path.join(process.cwd(), 'src/content/faq');
if (fs.existsSync(faqRootDir)) {
    const slugs = fs.readdirSync(faqRootDir)
        .filter((name) => name !== 'index' && !name.startsWith('.'))
        .filter((name) => fs.statSync(path.join(faqRootDir, name)).isDirectory())
        .sort();

    for (const slug of slugs) {
        const slugDir = path.join(faqRootDir, slug);
        for (const file of fs.readdirSync(slugDir).sort()) {
            if (!file.endsWith('.md') || file.startsWith('.')) continue;
            const langMatch = file.match(/\.([a-z]{2})\.md$/);
            const lang = langMatch ? langMatch[1] : 'en';
            const raw = fs.readFileSync(path.join(slugDir, file), 'utf-8');
            const fmMatch = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/);
            if (!fmMatch) continue;
            const fm = yaml.load(fmMatch[1]) || {};

            if (!langData[lang]) {
                langData[lang] = { products: [], landing: [], blog: [], faq: [] };
            }

            const urlPrefix = lang === 'en' ? '' : `/${lang}`;
            const articleSlug = (typeof fm.slug === 'string' && fm.slug) ? fm.slug.replace(/\/$/, '') : slug;
            const url = `${SITE_URL}${urlPrefix}/faq/${articleSlug}/`;
            const title = fm.heading || fm.seoTitle || articleSlug;

            let entry = `- [${title}](${url})\n`;
            if (fm.seoDescription) {
                entry += `  > ${String(fm.seoDescription).replace(/\s+/g, ' ').trim()}\n`;
            }
            if (Array.isArray(fm.items) && fm.items.length > 0) {
                const questions = fm.items
                    .map((it) => (it && typeof (it.question || it.q) === 'string' ? (it.question || it.q).trim() : ''))
                    .filter(Boolean);
                if (questions.length > 0) {
                    entry += `  > Questions: ${questions.join('; ')}\n`;
                }
            }
            entry += `\n`;
            langData[lang].faq.push(entry);
        }
    }
}

// Populate localized landing pages (24 product categories + 28 customization catalog routes)
const categoryProductDir = path.join(process.cwd(), 'src/content/category-product');
const productCategories = fs.existsSync(categoryProductDir)
    ? fs.readdirSync(categoryProductDir)
        .filter((name) => name.endsWith('.md') && name !== 'product.md')
        .sort()
        .map((name) => {
            const raw = fs.readFileSync(path.join(categoryProductDir, name), 'utf-8');
            const fmMatch = raw.match(/^---\r?\n([\s\S]*?)\r?\n---/);
            const doc = fmMatch ? (yaml.load(fmMatch[1]) || {}) : {};
            return {
                slug: doc.slug || name.replace(/\.md$/, ''),
                displayName: doc.displayName || {},
                seoDescription: doc.seoDescription || {},
            };
        })
    : [];

const getCustomizationGroups = () => {
    const catalogPath = path.join(process.cwd(), 'src', 'data', 'customizationCatalog.ts');
    const source = fs.readFileSync(catalogPath, 'utf-8');
    const match = source.match(/export const CUSTOMIZATION_GROUPS:\s*CustomizationGroup\[\]\s*=\s*(\[[\s\S]*?\n\]);/);
    if (!match) {
        throw new Error(`generate-llms: failed to parse CUSTOMIZATION_GROUPS from ${catalogPath}`);
    }
    return JSON.parse(match[1]);
};
const customizationGroups = getCustomizationGroups();

const loadCustomizationBundle = (lang) => {
    const filePath = path.join(process.cwd(), 'src', 'data', 'customization-i18n', `${lang}.ts`);
    if (!fs.existsSync(filePath)) return null;
    const raw = fs.readFileSync(filePath, 'utf-8');
    const match = raw.match(/export const CUSTOMIZATION_[A-Z0-9_]+:\s*CustomizationLocaleBundle\s*=\s*(\{[\s\S]*?\n\});/);
    if (!match) {
        throw new Error(`generate-llms: failed to parse ${filePath}`);
    }
    return JSON.parse(match[1]);
};
const enCustomBundle = loadCustomizationBundle('en');
const customBundles = {};

for (const lang of Object.keys(langData)) {
    const urlPrefix = lang === 'en' ? '' : `/${lang}`;
    const bundle = loadCustomizationBundle(lang) || enCustomBundle;
    customBundles[lang] = bundle;

    for (const cat of productCategories) {
        const title = cat.displayName[lang] || cat.displayName.en || cat.slug;
        const desc = cat.seoDescription[lang] || cat.seoDescription.en || '';
        const url = `${SITE_URL}${urlPrefix}/product/${cat.slug}/`;
        let entry = `- [${title}](${url})\n`;
        if (desc) entry += `  > ${desc}\n`;
        entry += `\n`;
        langData[lang].landing.push(entry);
    }

    const allCopy = bundle?.copy?.all || enCustomBundle?.copy?.all;
    const allTitle = allCopy?.heading || 'Eyewear Customization Components';
    const allDesc = allCopy?.seoDescription || '';
    langData[lang].landing.push(`- [${allTitle}](${SITE_URL}${urlPrefix}/customization/)\n${allDesc ? `  > ${allDesc}\n` : ''}\n`);

    for (const group of customizationGroups) {
        const groupCopy = bundle?.copy?.[group.slug] || enCustomBundle?.copy?.[group.slug];
        const groupName = bundle?.groups?.[group.slug]?.name || enCustomBundle?.groups?.[group.slug]?.name || group.name;
        const groupHeading = groupCopy?.heading || groupName;
        const groupDesc = groupCopy?.seoDescription || '';
        langData[lang].landing.push(`- [${groupHeading}](${SITE_URL}${urlPrefix}/customization/${group.slug}/)\n${groupDesc ? `  > ${groupDesc}\n` : ''}\n`);

        for (const sub of group.subcategories || []) {
            const subKey = `${group.slug}/${sub.slug}`;
            const subName = bundle?.subcategories?.[subKey] || enCustomBundle?.subcategories?.[subKey] || sub.name;
            langData[lang].landing.push(`- [${groupName} — ${subName}](${SITE_URL}${urlPrefix}/customization/${group.slug}/${sub.slug}/)\n${groupDesc ? `  > ${groupDesc}\n` : ''}\n`);
        }
    }
}

const translations = {
    en: {
        guide: "Large Language Model Guide",
        core: "Core Pages", coreDesc: "Primary navigation and company information.",
        products: "Products", productsDesc: "Catalog of our premium B2B sunglasses and eyewear frames.",
        landing: "Landing Pages", landingDesc: "Product collections, material guides, and marketing pages.",
        faq: "FAQ", faqDesc: "Manufacturing FAQ guides covering MOQ, OEM/ODM, materials, samples, QC, and shipping.",
        blog: "Blogs", blogDesc: "Industry insights, news, and editorial articles.",
        explore: "To explore our %LANG% content, please follow the specialized directories below:",
        generated: "Generated by Kssmi Eyewear",
        home: "Homepage", about: "About Us", contact: "Contact"
    },
    zh: {
        guide: "大语言模型指南",
        core: "核心页面", coreDesc: "主要导航和公司信息。",
        products: "产品", productsDesc: "我们的优质 B2B 太阳镜和眼镜架目录。",
        landing: "着陆页", landingDesc: "产品系列、材料指南和营销页面。",
        faq: "常见问题", faqDesc: "涵盖起订量、OEM/ODM、材质、打样、品控与物流的眼镜工厂常见问题指南。",
        blog: "博客", blogDesc: "行业洞察、新闻和社论文章。",
        explore: "要探索我们的 %LANG% 内容，请按照以下专门的目录进行：",
        generated: "由 可米科技 生成",
        home: "主页", about: "关于我们", contact: "联系我们"
    },
    de: {
        guide: "Leitfaden für große Sprachmodelle",
        core: "Kernseiten", coreDesc: "Primäre Navigation und Unternehmensinformationen.",
        products: "Produkte", productsDesc: "Katalog unserer Premium-B2B-Sonnenbrillen und Brillenfassungen.",
        landing: "Zielseiten", landingDesc: "Produktkollektionen, Materialleitfäden und Marketingseiten.",
        faq: "FAQ", faqDesc: "Häufig gestellte Fragen zu MOQ, OEM/ODM, Materialien, Mustern, QC und Versand.",
        blog: "Blogs", blogDesc: "Branchenerkenntnisse, Neuigkeiten und redaktionelle Artikel.",
        explore: "Um unsere %LANG%-Inhalte zu erkunden, folgen Sie bitte den unten stehenden speziellen Verzeichnissen:",
        generated: "Erstellt von Kssmi Eyewear",
        home: "Startseite", about: "Über uns", contact: "Kontakt"
    },
    es: {
        guide: "Guía de modelos de lenguaje grande",
        core: "Páginas principales", coreDesc: "Navegación principal e información de la empresa.",
        products: "Productos", productsDesc: "Catálogo de nuestras gafas de sol B2B premium y monturas.",
        landing: "Páginas de aterrizaje", landingDesc: "Colecciones de productos, guías de materiales y páginas de marketing.",
        faq: "Preguntas frecuentes", faqDesc: "Guías de preguntas frecuentes sobre MOQ, OEM/ODM, materiales, muestras, control de calidad y envíos.",
        blog: "Blogs", blogDesc: "Perspectivas de la industria, noticias y artículos editoriales.",
        explore: "Para explorar nuestro contenido en %LANG%, siga los directorios especializados a continuación:",
        generated: "Generado por Kssmi Eyewear",
        home: "Inicio", about: "Sobre nosotros", contact: "Contacto"
    },
    fr: {
        guide: "Guide des grands modèles linguistiques",
        core: "Pages principales", coreDesc: "Navigation principale et informations sur l'entreprise.",
        products: "Produits", productsDesc: "Catalogue de nos lunettes de soleil B2B haut de gamme et montures.",
        landing: "Pages de destination", landingDesc: "Collections de produits, guides des matériaux et pages marketing.",
        faq: "FAQ", faqDesc: "Guides FAQ couvrant le MOQ, l'OEM/ODM, les matériaux, les échantillons, le contrôle qualité et l'expédition.",
        blog: "Blogs", blogDesc: "Aperçus de l'industrie, actualités et articles éditoriaux.",
        explore: "Pour explorer notre contenu en %LANG%, veuillez suivre les répertoires spécialisés ci-dessous :",
        generated: "Généré par Kssmi Eyewear",
        home: "Accueil", about: "À propos", contact: "Contact"
    },
    ar: {
        guide: "دليل نماذج اللغة الكبيرة",
        core: "الصفحات الأساسية", coreDesc: "التنقل الأساسي ومعلومات الشركة.",
        products: "المنتجات", productsDesc: "كتالوج نظاراتنا الشمسية B2B وإطارات النظارات الفاخرة.",
        landing: "الصفحات المقصودة", landingDesc: "مجموعات المنتجات وأدلة المواد وصفحات التسويق.",
        faq: "الأسئلة الشائعة", faqDesc: "أدلة الأسئلة الشائعة حول الحد الأدنى للطلب وOEM/ODM والمواد والعينات وفحص الجودة والشحن.",
        blog: "المدونات", blogDesc: "رؤى الصناعة والأخبار والمقالات التحريرية.",
        explore: "لاستكشاف محتوانا بـ %LANG%، يرجى اتباع الأدلة المتخصصة أدناه:",
        generated: "تم الإنشاء بواسطة Kssmi Eyewear",
        home: "الصفحة الرئيسية", about: "من نحن", contact: "اتصل بنا"
    },
    hi: {
        guide: "लार्ज लैंग्वेज मॉडल गाइड",
        core: "मुख्य पृष्ठ", coreDesc: "प्राथमिक नेविगेशन और कंपनी की जानकारी।",
        products: "उत्पाद", productsDesc: "हमारे प्रीमियम B2B धूप का चश्मा और चश्मा फ्रेम की सूची।",
        landing: "लैंडिंग पृष्ठ", landingDesc: "उत्पाद संग्रह, सामग्री गाइड और विपणन पृष्ठ।",
        faq: "सामान्य प्रश्न (FAQ)", faqDesc: "MOQ, OEM/ODM, सामग्री, सैंपल, गुणवत्ता नियंत्रण और शिपिंग पर फैक्ट्री FAQ गाइड।",
        blog: "ब्लॉग", blogDesc: "उद्योग अंतर्दृष्टि, समाचार और संपादकीय लेख।",
        explore: "हमारी %LANG% सामग्री का पता लगाने के लिए, कृपया नीचे दी गई विशेष निर्देशिकाओं का पालन करें:",
        generated: "Kssmi Eyewear द्वारा निर्मित",
        home: "होमपेज", about: "हमारे बारे में", contact: "संपर्क करें"
    },
    it: {
        guide: "Guida ai modelli linguistici di grandi dimensioni",
        core: "Pagine principali", coreDesc: "Navigazione primaria e informazioni aziendali.",
        products: "Prodotti", productsDesc: "Catalogo dei nostri occhiali da sole e montature B2B premium.",
        landing: "Pagine di atterraggio", landingDesc: "Collezioni di prodotti, guide ai materiali e pagine di marketing.",
        faq: "FAQ", faqDesc: "Guide FAQ di produzione su MOQ, OEM/ODM, materiali, campioni, controllo qualità e spedizioni.",
        blog: "Blog", blogDesc: "Approfondimenti del settore, notizie e articoli editoriali.",
        explore: "Per esplorare i nostri contenuti in %LANG%, segui le directory specializzate di seguito:",
        generated: "Generato da Kssmi Eyewear",
        home: "Home page", about: "Chi siamo", contact: "Contattaci"
    },
    ja: {
        guide: "大規模言語モデルガイド",
        core: "コアページ", coreDesc: "主要なナビゲーションと企業情報。",
        products: "製品", productsDesc: "当社のプレミアムB2Bサングラスとメガネフレームのカタログ。",
        landing: "ランディングページ", landingDesc: "製品コレクション、素材ガイド、マーケティングページ。",
        faq: "よくある質問（FAQ）", faqDesc: "MOQ、OEM/ODM、素材、サンプル、品質管理、配送に関する製造FAQガイド。",
        blog: "ブログ", blogDesc: "業界の洞察、ニュース、編集記事。",
        explore: "当社の %LANG% コンテンツを検索するには、以下の専門ディレクトリに従ってください：",
        generated: "Kssmi Eyewear によって生成",
        home: "ホームページ", about: "私たちについて", contact: "お問い合わせ"
    },
    jv: {
        guide: "Pandhuan Model Basa Gedhe",
        core: "Kaca Utama", coreDesc: "Pandhu arah utama lan informasi perusahaan.",
        products: "Produk", productsDesc: "Katalog kacamata ireng B2B premium lan bingkai kacamata.",
        landing: "Kaca kebangkrutan", landingDesc: "Koleksi produk, pandhuan materi, lan kaca pemasaran.",
        faq: "FAQ", faqDesc: "Pandhuan pitakon sing kerep ditakokake babagan MOQ, OEM/ODM, bahan, sampel, QC, lan pangiriman.",
        blog: "Blog", blogDesc: "Wawasan industri, warta, lan artikel editorial.",
        explore: "Kanggo njelajah konten %LANG% kita, tututi direktori khusus ing ngisor iki:",
        generated: "Digawe dening Kssmi Eyewear",
        home: "Kaca Ngarep", about: "Babagan Kita", contact: "Hubungi Kita"
    },
    ko: {
        guide: "대형 언어 모델 가이드",
        core: "핵심 페이지", coreDesc: "기본 탐색 및 회사 정보.",
        products: "제품", productsDesc: "프리미엄 B2B 선글라스 및 안경테 카탈로그.",
        landing: "랜딩 페이지", landingDesc: "제품 컬렉션, 소재 가이드 및 마케팅 페이지.",
        faq: "자주 묻는 질문(FAQ)", faqDesc: "MOQ, OEM/ODM, 소재, 샘플, 품질 관리 및 배송에 관한 제조 FAQ 가이드.",
        blog: "블로그", blogDesc: "산업 통찰력, 뉴스 및 편집 기사.",
        explore: "%LANG% 콘텐츠를 탐색하려면 아래의 전문 디렉토리를 따르십시오.",
        generated: "Kssmi Eyewear 제작",
        home: "홈페이지", about: "회사 소개", contact: "문의하기"
    },
    ms: {
        guide: "Panduan Model Bahasa Besar",
        core: "Halaman Utama", coreDesc: "Navigasi utama dan maklumat syarikat.",
        products: "Produk", productsDesc: "Katalog cermin mata hitam B2B premium dan bingkai cermin mata kami.",
        landing: "Halaman Pendaratan", landingDesc: "Koleksi produk, panduan bahan dan halaman pemasaran.",
        faq: "Soalan Lazim (FAQ)", faqDesc: "Panduan FAQ pembuatan meliputi MOQ, OEM/ODM, bahan, sampel, QC dan penghantaran.",
        blog: "Blog", blogDesc: "Wawasan industri, berita dan artikel editorial.",
        explore: "Untuk meneroka kandungan %LANG% kami, sila ikuti direktori khusus di bawah:",
        generated: "Dihasilkan oleh Kssmi Eyewear",
        home: "Laman Utama", about: "Tentang Kami", contact: "Hubungi Kami"
    },
    pt: {
        guide: "Guia de Grandes Modelos de Linguagem",
        core: "Páginas Principais", coreDesc: "Navegação primária e informações da empresa.",
        products: "Produtos", productsDesc: "Catálogo de nossos óculos de sol e armações B2B premium.",
        landing: "Páginas de Destino", landingDesc: "Coleções de produtos, guias de materiais e páginas de marketing.",
        faq: "Perguntas Frequentes (FAQ)", faqDesc: "Guias de FAQ de fabricação sobre MOQ, OEM/ODM, materiais, amostras, controle de qualidade e envio.",
        blog: "Blogs", blogDesc: "Insights do setor, notícias e artigos editoriais.",
        explore: "Para explorar nosso conteúdo em %LANG%, siga os diretórios especializados abaixo:",
        generated: "Gerado por Kssmi Eyewear",
        home: "Página Inicial", about: "Sobre Nós", contact: "Contato"
    },
    ru: {
        guide: "Руководство по большим языковым моделям",
        core: "Основные страницы", coreDesc: "Основная навигация и информация о компании.",
        products: "Продукты", productsDesc: "Каталог наших премиальных B2B солнцезащитных очков и оправ.",
        landing: "Целевые страницы", landingDesc: "Коллекции продуктов, руководства по материалам и маркетинговые страницы.",
        faq: "Частые вопросы (FAQ)", faqDesc: "Справочные материалы по производству: MOQ, OEM/ODM, материалы, образцы, контроль качества и доставка.",
        blog: "Блоги", blogDesc: "Отраслевые идеи, новости и редакционные статьи.",
        explore: "Чтобы изучить наш контент на %LANG%, пожалуйста, следуйте специализированным каталогам ниже:",
        generated: "Создано Kssmi Eyewear",
        home: "Главная страница", about: "О нас", contact: "Связаться с нами"
    },
    tg: {
        guide: "Роҳнамои моделҳои бузурги забонӣ",
        core: "Саҳифаҳои асосӣ", coreDesc: "Навигатсияи ибтидоӣ ва маълумоти ширкат.",
        products: "Маҳсулот", productsDesc: "Каталоги айнакҳои офтобии премиум B2B ва чорчӯбаи айнакҳои мо.",
        landing: "Саҳифаҳои фуруд", landingDesc: "Маҷмӯаҳои маҳсулот, дастурҳои мавод ва саҳифаҳои маркетингӣ.",
        faq: "Саволҳои маъмул (FAQ)", faqDesc: "Дастурҳои FAQ-и истеҳсолӣ оид ба MOQ, OEM/ODM, мавод, намунаҳо, назорати сифат ва интиқол.",
        blog: "Блогҳо", blogDesc: "Фаҳмишҳои соҳа, ахбор ва мақолаҳои таҳрирӣ.",
        explore: "Барои омӯхтани мундариҷаи %LANG%-и мо, лутфан ба директорияҳои махсуси зерин пайравӣ кунед:",
        generated: "Аз ҷониби Kssmi Eyewear тавлид шудааст",
        home: "Саҳифаи асосӣ", about: "Дар бораи мо", contact: "Бо мо тамос гиред"
    },
    tr: {
        guide: "Büyük Dil Modeli Kılavuzu",
        core: "Ana Sayfalar", coreDesc: "Birincil gezinme ve şirket bilgileri.",
        products: "Ürünler", productsDesc: "Premium B2B güneş gözlüklerimiz ve gözlük çerçevelerimiz kataloğu.",
        landing: "Açılış Sayfaları", landingDesc: "Ürün koleksiyonları, malzeme kılavuzları ve pazarlama sayfaları.",
        faq: "Sıkça Sorulan Sorular (FAQ)", faqDesc: "MOQ, OEM/ODM, malzemeler, numuneler, kalite kontrol ve sevkiyat hakkında üretim SSS kılavuzları.",
        blog: "Bloglar", blogDesc: "Sektörel içgörüler, haberler ve editoryal makaleler.",
        explore: "%LANG% İçeriğimizi keşfetmek için lütfen aşağıdaki özel dizinleri takip edin:",
        generated: "Kssmi Eyewear tarafından oluşturuldu",
        home: "Ana Sayfa", about: "Hakkımızda", contact: "İletişim"
    },
    vi: {
        guide: "Hướng dẫn Mô hình Ngôn ngữ Lớn",
        core: "Trang Cốt Lõi", coreDesc: "Điều hướng chính và thông tin công ty.",
        products: "Sản phẩm", productsDesc: "Danh mục kính râm B2B cao cấp và gọng kính của chúng tôi.",
        landing: "Trang Đích", landingDesc: "Bộ sưu tập sản phẩm, hướng dẫn chất liệu và trang tiếp thị.",
        faq: "Câu hỏi thường gặp (FAQ)", faqDesc: "Hướng dẫn FAQ sản xuất về MOQ, OEM/ODM, chất liệu, mẫu, kiểm soát chất lượng và vận chuyển.",
        blog: "Blog", blogDesc: "Sự hiểu biết sâu sắc về ngành, tin tức và bài báo biên tập.",
        explore: "Để khám phá nội dung %LANG% của chúng tôi, vui lòng theo các thư mục chuyên dụng dưới đây:",
        generated: "Được tạo bởi Kssmi Eyewear",
        home: "Trang chủ", about: "Về chúng tôi", contact: "Liên hệ"
    }
};

// Generate files for each language
Object.keys(langData).forEach(lang => {
    const langDir = path.join(publicDir, lang);
    if (!fs.existsSync(langDir)) {
        fs.mkdirSync(langDir, { recursive: true });
    }

    const t = translations[lang] || translations['en'];
    let localLangName = lang.toUpperCase();
    try {
        localLangName = new Intl.DisplayNames([lang], { type: 'language' }).of(lang);
        // Capitalize first letter for some languages that might return lowercase
        localLangName = localLangName.charAt(0).toUpperCase() + localLangName.slice(1);
    } catch (e) { }

    const exploreText = t.explore.replace('%LANG%', localLangName);

    const langUrlPrefix = lang === 'en' ? '' : `/${lang}`;
    const baseUrl = `${SITE_URL}${langUrlPrefix}`;
    const llmsBaseUrl = `${SITE_URL}/${lang}`;

    const hasBlogs = langData[lang].blog.length > 0;

    // 1. llms-core.txt
    const customLabel = customBundles[lang]?.ui?.breadcrumbLabel || 'Customization';
    const blogCoreLine = hasBlogs ? `- [${t.blog}](${baseUrl}/blog/)\n` : '';
    const coreContent = `# Kssmi - ${t.core} (${localLangName})\n\n> ${t.coreDesc}\n\n- [${t.home}](${baseUrl}/)\n- [${t.products}](${baseUrl}/product/)\n- [${customLabel}](${baseUrl}/customization/)\n- [FAQ](${baseUrl}/faq/)\n${blogCoreLine}- [${t.contact}](${baseUrl}/quote/)\n`;
    fs.writeFileSync(path.join(langDir, 'llms-core.txt'), coreContent, 'utf-8');

    // 2. llms-products.txt
    const productsContent = `# Kssmi - ${t.products} (${localLangName})\n\n> ${t.productsDesc}\n\n${langData[lang].products.join('')}`;
    fs.writeFileSync(path.join(langDir, 'llms-products.txt'), productsContent, 'utf-8');

    // 3. llms-landing.txt
    const landingContent = `# Kssmi - ${t.landing} (${localLangName})\n\n> ${t.landingDesc}\n\n${langData[lang].landing.join('')}`;
    fs.writeFileSync(path.join(langDir, 'llms-landing.txt'), landingContent, 'utf-8');

    // 4. llms-faq.txt (emit whenever FAQ articles exist)
    const faqPath = path.join(langDir, 'llms-faq.txt');
    const faqEntries = langData[lang].faq || [];
    const hasFaq = faqEntries.length > 0;
    if (hasFaq) {
        const faqContent = `# Kssmi - ${t.faq} (${localLangName})\n\n> ${t.faqDesc}\n\n${faqEntries.join('')}`;
        fs.writeFileSync(faqPath, faqContent, 'utf-8');
    } else {
        fs.rmSync(faqPath, { force: true });
    }

    // 5. llms-blogs.txt (only emit when there are published blog posts)
    const blogPath = path.join(langDir, 'llms-blogs.txt');
    if (hasBlogs) {
        const blogContent = `# Kssmi - ${t.blog} (${localLangName})\n\n> ${t.blogDesc}\n\n${langData[lang].blog.join('')}`;
        fs.writeFileSync(blogPath, blogContent, 'utf-8');
    } else {
        fs.rmSync(blogPath, { force: true });
    }

    // 6. Language Index (llms.txt) for this specific language
    const faqSection = hasFaq ? `\n## ${t.faq} (${t.faqDesc})\n- [${t.faq}](${llmsBaseUrl}/llms-faq.txt)\n` : '';
    const blogSection = hasBlogs ? `\n## ${t.blog} (${t.blogDesc})\n- [${t.blog}](${llmsBaseUrl}/llms-blogs.txt)\n` : '';
    const langIndexContent = `# Kssmi ${t.guide} - ${localLangName}

> ${exploreText}

## ${t.core} (${t.coreDesc})
- [${t.core}](${llmsBaseUrl}/llms-core.txt)

## ${t.products} (${t.productsDesc})
- [${t.products}](${llmsBaseUrl}/llms-products.txt)

## ${t.landing} (${t.landingDesc})
- [${t.landing}](${llmsBaseUrl}/llms-landing.txt)
${faqSection}${blogSection}
*${t.generated}*
`;

    fs.writeFileSync(path.join(langDir, 'llms.txt'), langIndexContent, 'utf-8');
});

// Finally, generate the Global Router at public/llms.txt
const hasEnBlogs = (langData.en?.blog?.length || 0) > 0;
const globalBlogLinks = hasEnBlogs
    ? `- [Blog](https://kssmi.com/blog/)\n- [Blog Directory (EN)](https://kssmi.com/en/llms-blogs.txt)\n`
    : '';
let globalContent = `# Kssmi Eyewear — B2B Eyewear Manufacturing

> Kssmi is a B2B handmade eyewear and sunglasses manufacturer (OEM/ODM) based in Shenzhen, China since 2003, serving global optical retailers, boutique shops, and fashion brands.

## About
Kssmi is a leading B2B handmade eyewear and sunglasses manufacturer based in Shenzhen, China, operational since 2003. We produce high-quality, ethically handcrafted eyewear for global optical retailers, boutique shops, and fashion brands.

## Capabilities
- OEM: produce eyewear to your exact specifications and designs
- ODM: our in-house design team creates exclusive collections for your brand
- Full customization: materials (acetate from Mazzucchelli, JIMEI, and JINYU; titanium from Kobe Steel and Baoji Titanium; metal, TR-90, carbon fiber), colors, logos, and custom hardware (we do not manufacture or supply retail packaging; finished eyewear can be packed into customer-supplied packaging at no extra charge)
- MOQ: 300 pairs per model (100 pairs/color) for acetate, metal, and titanium; 200 pairs/model for carbon fiber; 1,200 pairs/model for TR-90 (600 pairs/color)
- Technical drawings (2D rendering): 3–10 working days
- Fully custom sampling: 60 working days after drawing approval and full payment of the relevant fees, including tooling where required
- Bulk production: 60 working days for all materials, including TR-90 injection-moulded frames, after sample approval and receipt of the 50% deposit
- Fee credits: For non-TR-90 orders, all fees, including development, design, tooling, samples and logo fees, are fully credited toward bulk payments once the same model reaches a cumulative 1000 pairs within 12 months; only freight is excluded. TR-90 injection tooling and sample fees are fully credited once the same model reaches a cumulative 30000 pairs within 24 months; freight and taxes are excluded.
- Worldwide shipping via air courier (DHL or customer-designated forwarder) or sea freight

## Key Product Lines
- Optical Frames (acetate, metal, titanium, rimless; shipped with demo lenses — we do not produce prescription lenses)
- Sunglasses (acetate, metal, titanium, rimless; UV400 lenses in TAC, CR-39, PC, and nylon)

## Quality & Compliance
Our quality management system is ISO 9001 certified (certificate copy available once an order is placed). Product conformity documents and independent third-party testing are confirmed separately for the destination market and finished specification.

## Contact
- [Website](https://kssmi.com/)
- [Request a Quote](https://kssmi.com/quote/)
- [FAQ](https://kssmi.com/faq/)
- [FAQ Directory (EN)](https://kssmi.com/en/llms-faq.txt)
${globalBlogLinks}- Email: sales@kssmi.com
- WhatsApp: +86-135-1053-2553

## Language Index
For language-specific content, follow the directory for your desired language:

`;

// Use Intl.DisplayNames to dynamically support any new language added in the future
const languageNames = new Intl.DisplayNames(['en'], { type: 'language' });

Object.keys(langData).sort().forEach(lang => {
    let langName = lang.toUpperCase();
    try {
        langName = languageNames.of(lang);
    } catch (e) {
        // Fallback to uppercase code if Intl fails for some reason
    }
    globalContent += `- [${langName} (${lang})](https://kssmi.com/${lang}/llms.txt)\n`;
});

globalContent += `
## Sitemap
- [XML Sitemap](https://kssmi.com/sitemap.xml)

*Generated by Kssmi Eyewear*
`;

fs.writeFileSync(path.join(publicDir, 'llms.txt'), globalContent, 'utf-8');

console.log('✅ Generated split llms tree architecture inside public/ directory successfully!');
