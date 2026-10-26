import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const publicDir = path.join(process.cwd(), 'public');
const contentDir = path.join(process.cwd(), 'src/content');
const SITE_URL = 'https://kssmi.com';

if (!fs.existsSync(publicDir)) {
  fs.mkdirSync(publicDir, { recursive: true });
}

// Generate the locales list explicitly mapping what files exist in the project
const locales = [
  'en', 'zh', 'ar', 'de', 'es', 'fr', 'hi', 'it',
  'ja', 'jv', 'ko', 'ms', 'pt', 'ru', 'tg', 'tr', 'vi'
];

// lastmod must describe the content, not the build. File mtimes in a CI
// checkout are all the checkout time, so dates come from Git history: one
// `git log` pass maps each source file to its latest commit date. Without
// history (shallow clone, no Git) lastmod is omitted rather than invented.
const gitDates = (() => {
  const dates = new Map();
  try {
    const shallow = execFileSync('git', ['rev-parse', '--is-shallow-repository'], { encoding: 'utf8' }).trim();
    if (shallow !== 'false') return dates;
    const log = execFileSync(
      'git',
      ['log', '--format=@%cs', '--name-only', '--no-renames', '--', 'src/content', 'src/pages', 'src/components'],
      { encoding: 'utf-8', maxBuffer: 256 * 1024 * 1024 },
    );
    let current = null;
    for (const line of log.split('\n')) {
      if (line.startsWith('@')) current = line.slice(1);
      else if (line && current && !dates.has(line)) dates.set(line, current);
    }
  } catch {
    // No Git available: leave every lastmod out.
  }
  return dates;
})();

// Helper: latest commit date (YYYY-MM-DD) of a source file, or null
const fileDate = (filePath) => {
  const relative = path.relative(process.cwd(), filePath).split(path.sep).join('/');
  return gitDates.get(relative) || null;
};

// Newest lastmod of a list of entries, or null when none has one
const newestDate = (items) => items.reduce((newest, item) => (
  item.lastmod && (!newest || item.lastmod > newest) ? item.lastmod : newest
), null);

// Newest of plain YYYY-MM-DD date strings, ignoring nulls
const newestDateString = (dates) => dates.reduce((newest, date) => (
  date && (!newest || date > newest) ? date : newest
), null);

// Newest commit date among a content collection's files for one language
// (collections name files <slug>.<lang>.md), or null. Core pages that render
// a collection must take the collection's date into account: a content-only
// change leaves the route file untouched and must still bump lastmod.
const langContentDate = (dir, lang) => {
  const prefix = `${dir}/`;
  const suffix = `.${lang}.md`;
  let newest = null;
  for (const [file, date] of gitDates) {
    if (file.startsWith(prefix) && file.endsWith(suffix) && (!newest || date > newest)) newest = date;
  }
  return newest;
};

const lastmodTag = (date, indent) => (date ? `${indent}<lastmod>${date}</lastmod>\n` : '');

// Map core page slugs to their source .astro files
const coreSourceMap = {
  '':            'index.astro',
  'faq':         'faq.astro',
  'quote':       'quote.astro',
  'terms-privacy': 'terms-privacy.astro',
  'product':     'product/index.astro',
};
// about-us, contact, search, blog and eyewear render with noindex, so they are
// not listed: a sitemap must only contain canonical, indexable URLs. Add a page
// back here when its noindex is removed (scripts/validate-seo-output.mjs fails
// the build if a listed page is noindex).

let rootSitemapIndexEntries = '';

// Helper to generate a urlset XML from a list of urls
const generateUrlset = (items) => {
  let xml = `<?xml version="1.0" encoding="UTF-8"?>\n`;
  xml += `<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>\n`;
  xml += `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">\n`;
  items.forEach(item => {
    let url, cover, title;
    if (typeof item === 'object') {
      url = item.url;
      cover = item.cover;
      title = item.title;
    } else {
      url = item;
    }
    const pathSegments = new URL(url).pathname.split('/').filter(Boolean);
    let priority = '0.8';
    if (pathSegments.length === 0 || (pathSegments.length === 1 && locales.includes(pathSegments[0]))) {
      priority = '1.0';
    }
    xml += `  <url>\n`;
    xml += `    <loc>${url}</loc>\n`;
    xml += lastmodTag(item.lastmod, '    ');
    xml += `    <changefreq>weekly</changefreq>\n`;
    xml += `    <priority>${priority}</priority>\n`;
    if (cover) {
      xml += `    <image:image>\n`;
      xml += `      <image:loc>${cover}</image:loc>\n`;
      if (title) xml += `      <image:title><![CDATA[${title}]]></image:title>\n`;
      xml += `    </image:image>\n`;
    }
    xml += `  </url>\n`;
  });
  xml += `</urlset>`;
  return xml;
};

// Generate an XML <urlset> for each language directory
locales.forEach(lang => {
  const langDir = path.join(publicDir, lang);
  if (!fs.existsSync(langDir)) {
    fs.mkdirSync(langDir, { recursive: true });
  }

  const langUrlPrefix = lang === 'en' ? '' : `/${lang}`;
  const baseUrl = `${SITE_URL}${langUrlPrefix}`;
  const sitemapBaseUrl = `${SITE_URL}/${lang}`;

  // 1. Core Pages
  const coreUrlDefs = [
    { slug: '', route: '' },
    { slug: 'faq', route: 'faq' },
    { slug: 'quote', route: 'quote' },
    { slug: 'terms-privacy', route: 'terms-privacy' },
    { slug: 'product', route: 'product' },
  ];
  const coreUrls = coreUrlDefs.map(d => {
    const astroFile = coreSourceMap[d.slug];
    const srcPath = lang === 'en'
      ? path.join(process.cwd(), 'src', 'pages', astroFile)
      : path.join(process.cwd(), 'src', 'pages', '[lang]', astroFile);
    // Content the page renders from outside its route file: the terms
    // collection for terms-privacy, the form for quote.
    const extraDates = [];
    if (d.slug === 'terms-privacy') {
      extraDates.push(langContentDate('src/content/collection/terms-privacy', lang));
    }
    if (d.slug === 'quote') {
      extraDates.push(fileDate(path.join(process.cwd(), 'src', 'components', 'InquiryForm.astro')));
    }
    return {
      url: `${baseUrl}${d.route ? '/' + d.route : ''}/`,
      lastmod: newestDateString([fileDate(srcPath), ...extraDates])
    };
  });
  fs.writeFileSync(path.join(langDir, 'sitemap-core.xml'), generateUrlset(coreUrls), 'utf-8');

  // Dynamically retrieve collections
  const getCollectionUrls = (dirName, routeName) => {
    let urls = [];
    const fullPath = path.join(contentDir, dirName);
    if (fs.existsSync(fullPath)) {
      const getAllFiles = (dir) => {
        let results = [];
        const list = fs.readdirSync(dir);
        list.forEach(file => {
          const filePath = path.join(dir, file);
          const stat = fs.statSync(filePath);
          if (stat && stat.isDirectory()) {
            results = results.concat(getAllFiles(filePath));
          } else {
            results.push(filePath);
          }
        });
        return results;
      };

      const files = getAllFiles(fullPath);
      files.forEach(filePath => {
        const file = path.basename(filePath);
        const ext = lang === 'en' ? '.en.md' : `.${lang}.md`;
        if (file.endsWith(ext)) {
          // example: yet-lc010-titanium-sunglasses.en.md -> yet-lc010-titanium-sunglasses
          const slug = file.replace(ext, '');
          const urlStr = `${baseUrl}/${routeName}/${slug}/`;

          let cover = null;
          let title = null;
          if (dirName === 'products') {
            const content = fs.readFileSync(filePath, 'utf-8');
            const titleMatch = content.match(/title:\s*(.*?)\r?\n/);
            if (titleMatch) title = titleMatch[1].replace(/['"]/g, '').trim();

            // The frontmatter cover is the full-size main image. Sorting the
            // media folder used to pick the 120 px "-1-120w" thumbnail.
            const coverMatch = content.match(/^cover:\s*["']?([^"'\r\n]+)["']?\s*$/m);
            const coverPath = coverMatch ? coverMatch[1].trim() : '';
            if (coverPath.startsWith('/media/') && fs.existsSync(path.join(process.cwd(), 'public', coverPath))) {
                cover = `${SITE_URL}${coverPath}`;
            } else {
                const mediaDir = path.join(process.cwd(), 'public', 'media', 'products', slug);
                if (fs.existsSync(mediaDir)) {
                    const fullSize = fs.readdirSync(mediaDir)
                        .filter(f => /-\d+\.(webp|jpg|png)$/.test(f))
                        .sort((a, b) => Number(a.match(/-(\d+)\.\w+$/)[1]) - Number(b.match(/-(\d+)\.\w+$/)[1]));
                    if (fullSize.length > 0) {
                        cover = `${SITE_URL}/media/products/${slug}/${fullSize[0]}`;
                    }
                }
            }
          }
          const itemLastmod = fileDate(filePath);
          if (cover) {
            urls.push({ url: urlStr, cover, title, lastmod: itemLastmod });
          } else {
            urls.push({ url: urlStr, lastmod: itemLastmod });
          }
        }
      });
    }
    return urls;
  };

  // 2. Products
  const productUrls = getCollectionUrls('products', 'product');
  fs.writeFileSync(path.join(langDir, 'sitemap-products.xml'), generateUrlset(productUrls), 'utf-8');

  // 3. Blogs (skip if empty)
  const blogUrls = getCollectionUrls('blog', 'blog');
  if (blogUrls.length > 0) {
    fs.writeFileSync(path.join(langDir, 'sitemap-blogs.xml'), generateUrlset(blogUrls), 'utf-8');
  }

  // 4. Features / Eyewear (skip if empty)
  const featureUrls = getCollectionUrls('feature', 'eyewear');
  if (featureUrls.length > 0) {
    fs.writeFileSync(path.join(langDir, 'sitemap-features.xml'), generateUrlset(featureUrls), 'utf-8');
  }

  // Language specific sitemap.xml (index for this language)
  let langSitemapIndex = `<?xml version="1.0" encoding="UTF-8"?>\n`;
  langSitemapIndex += `<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>\n`;
  langSitemapIndex += `<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n`;
  const childSitemaps = [
    { cat: 'core', urls: coreUrls },
    { cat: 'products', urls: productUrls },
    ...(blogUrls.length > 0 ? [{ cat: 'blogs', urls: blogUrls }] : []),
    ...(featureUrls.length > 0 ? [{ cat: 'features', urls: featureUrls }] : []),
  ];
  childSitemaps.forEach(({ cat, urls }) => {
    langSitemapIndex += `  <sitemap>\n`;
    langSitemapIndex += `    <loc>${sitemapBaseUrl}/sitemap-${cat}.xml</loc>\n`;
    langSitemapIndex += lastmodTag(newestDate(urls), '    ');
    langSitemapIndex += `  </sitemap>\n`;
  });
  langSitemapIndex += `</sitemapindex>`;

  fs.writeFileSync(path.join(langDir, 'sitemap.xml'), langSitemapIndex, 'utf-8');

  // Add exactly this language index to the root sitemap
  rootSitemapIndexEntries += `  <sitemap>\n`;
  rootSitemapIndexEntries += `    <loc>${sitemapBaseUrl}/sitemap.xml</loc>\n`;
  rootSitemapIndexEntries += lastmodTag(newestDate(childSitemaps.flatMap(({ urls }) => urls)), '    ');
  rootSitemapIndexEntries += `  </sitemap>\n`;
});

// Generate the global `sitemap.xml` replacing the generic astrojs `sitemap-index.xml`
const globalSitemapXml = `<?xml version="1.0" encoding="UTF-8"?>\n<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>\n<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${rootSitemapIndexEntries}</sitemapindex>`;

// Save the root sitemap
fs.writeFileSync(path.join(publicDir, 'sitemap.xml'), globalSitemapXml, 'utf-8');

console.log('✅ Generated split custom XML sitemap tree architecture inside public/ directory successfully!');

// Generate the XSL stylesheet to render the XML strictly as HTML in the browser to prevent extension-injection bugs
const xslContent = `<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="2.0" 
    xmlns:html="http://www.w3.org/TR/REC-html40"
    xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
  <xsl:template match="/">
    <html xmlns="http://www.w3.org/1999/xhtml">
      <head>
        <title>XML Sitemap</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style type="text/css">
          body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; color: #333; margin: 0; padding: 2rem; background: #f9f9f9; }
          #content { margin: 0 auto; max-width: 960px; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
          h1 { font-size: 24px; color: #111; margin-bottom: 5px; }
          p.description { color: #666; font-size: 14px; margin-bottom: 2rem; }
          table { width: 100%; border-collapse: collapse; font-size: 14px; }
          th { text-align: left; background-color: #f1f5f9; padding: 12px; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
          td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
          tr:is(:hover, :active, :focus-visible, :focus-within) td { background-color: #f8fafc; }
          a { color: #2563eb; text-decoration: none; }
          a:is(:hover, :active, :focus-visible, :focus-within) { text-decoration: underline; }
        </style>
      </head>
      <body>
        <div id="content">
          <h1>XML Sitemap</h1>
          <p class="description">This is the programmatic XML sitemap for Kssmi, styled for human readability.</p>
          <xsl:if test="count(sitemap:sitemapindex/sitemap:sitemap) &gt; 0">
            <table>
              <thead>
                <tr>
                  <th>Sitemap URL</th>
                  <th>Last Modified</th>
                </tr>
              </thead>
              <tbody>
                <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                  <tr>
                    <td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
                    <td><xsl:value-of select="sitemap:lastmod"/></td>
                  </tr>
                </xsl:for-each>
              </tbody>
            </table>
          </xsl:if>
          <xsl:if test="count(sitemap:urlset/sitemap:url) &gt; 0">
            <table>
              <thead>
                <tr>
                  <th>Page URL</th>
                  <th width="15%">Priority</th>
                  <th width="15%">Change Frequency</th>
                  <th width="20%">Last Modified</th>
                </tr>
              </thead>
              <tbody>
                <xsl:for-each select="sitemap:urlset/sitemap:url">
                  <tr>
                    <td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
                    <td><xsl:value-of select="sitemap:priority"/></td>
                    <td><xsl:value-of select="sitemap:changefreq"/></td>
                    <td><xsl:value-of select="sitemap:lastmod"/></td>
                  </tr>
                </xsl:for-each>
              </tbody>
            </table>
          </xsl:if>
        </div>
      </body>
    </html>
  </xsl:template>
</xsl:stylesheet>`;
fs.writeFileSync(path.join(publicDir, 'sitemap.xsl'), xslContent, 'utf-8');
