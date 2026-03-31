import fs from 'fs';
import path from 'path';

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

// Standard XML dates (YYYY-MM-DD)
const currentDate = new Date().toISOString().split('T')[0];

let rootSitemapIndexEntries = '';

// Helper to generate a urlset XML from a list of urls
const generateUrlset = (urls) => {
  let xml = `<?xml version="1.0" encoding="UTF-8"?>\n`;
  xml += `<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>\n`;
  xml += `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n`;
  urls.forEach(url => {
    const pathSegments = new URL(url).pathname.split('/').filter(Boolean);
    let priority = '0.8';
    if (pathSegments.length === 0 || (pathSegments.length === 1 && locales.includes(pathSegments[0]))) {
      priority = '1.0';
    }
    xml += `  <url>\n`;
    xml += `    <loc>${url}</loc>\n`;
    xml += `    <lastmod>${currentDate}</lastmod>\n`;
    xml += `    <changefreq>weekly</changefreq>\n`;
    xml += `    <priority>${priority}</priority>\n`;
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
  const coreUrls = [
    `${baseUrl}/`,
    `${baseUrl}/about-us/`,
    `${baseUrl}/contact/`
  ];
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
          urls.push(`${baseUrl}/${routeName}/${slug}/`);
        }
      });
    }
    return urls;
  };

  // 2. Products
  const productUrls = getCollectionUrls('products', 'product');
  fs.writeFileSync(path.join(langDir, 'sitemap-products.xml'), generateUrlset(productUrls), 'utf-8');

  // 3. Landing
  const landingUrls = getCollectionUrls('landing', 'landing');
  fs.writeFileSync(path.join(langDir, 'sitemap-landing.xml'), generateUrlset(landingUrls), 'utf-8');

  // 4. Blogs
  const blogUrls = getCollectionUrls('blog', 'blog');
  fs.writeFileSync(path.join(langDir, 'sitemap-blogs.xml'), generateUrlset(blogUrls), 'utf-8');

  // Language specific sitemap.xml (index for this language)
  let langSitemapIndex = `<?xml version="1.0" encoding="UTF-8"?>\n`;
  langSitemapIndex += `<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>\n`;
  langSitemapIndex += `<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n`;
  ['core', 'products', 'landing', 'blogs'].forEach(cat => {
    langSitemapIndex += `  <sitemap>\n`;
    langSitemapIndex += `    <loc>${sitemapBaseUrl}/sitemap-${cat}.xml</loc>\n`;
    langSitemapIndex += `    <lastmod>${currentDate}</lastmod>\n`;
    langSitemapIndex += `  </sitemap>\n`;
  });
  langSitemapIndex += `</sitemapindex>`;

  fs.writeFileSync(path.join(langDir, 'sitemap.xml'), langSitemapIndex, 'utf-8');

  // Add exactly this language index to the root sitemap
  rootSitemapIndexEntries += `  <sitemap>\n`;
  rootSitemapIndexEntries += `    <loc>${sitemapBaseUrl}/sitemap.xml</loc>\n`;
  rootSitemapIndexEntries += `    <lastmod>${currentDate}</lastmod>\n`;
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
          tr:hover td { background-color: #f8fafc; }
          a { color: #2563eb; text-decoration: none; }
          a:hover { text-decoration: underline; }
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
