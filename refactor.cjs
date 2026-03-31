const fs = require('fs');
const path = require('path');

function processAstroFile(filePath, isLang) {
    let content = fs.readFileSync(filePath, 'utf8');

    // 1. Update getStaticPaths signature
    content = content.replace(
        'export async function getStaticPaths() {',
        'export async function getStaticPaths({ paginate }) {'
    );

    // 2. Add paginate calls
    // English version
    if (!isLang) {
        content = content.replace(
            /if \(categoryProducts\.length > 0\) \{\s*paths\.push\(\{\s*params: \{ category \},\s*props: \{ products: categoryProducts, categorySlug: category \}\s*\}\);\s*\}/,
            `if (categoryProducts.length > 0) {
      const sorted = categoryProducts.sort((a, b) => {
        if (a.data.featured && !b.data.featured) return -1;
        if (!a.data.featured && b.data.featured) return 1;
        const dateA = a.data.date ? new Date(a.data.date).getTime() : 0;
        const dateB = b.data.date ? new Date(b.data.date).getTime() : 0;
        return dateB - dateA;
      });
      paths.push(...paginate(sorted, {
        params: { category },
        pageSize: 12,
        props: { categorySlug: category }
      }));
    }`
        );
    } else {
        // Multi-lang version
        content = content.replace(
            /if \(categoryProducts\.length > 0\) \{\s*paths\.push\(\{\s*params: \{ lang, category: canonicalSlug \},\s*props: \{ products: categoryProducts, categorySlug: canonicalSlug, lang \}\s*\}\);\s*\}/,
            `if (categoryProducts.length > 0) {
        const sorted = categoryProducts.sort((a, b) => {
          if (a.data.featured && !b.data.featured) return -1;
          if (!a.data.featured && b.data.featured) return 1;
          const dateA = a.data.date ? new Date(a.data.date).getTime() : 0;
          const dateB = b.data.date ? new Date(b.data.date).getTime() : 0;
          return dateB - dateA;
        });
        paths.push(...paginate(sorted, {
          params: { lang, category: canonicalSlug },
          pageSize: 12,
          props: { categorySlug: canonicalSlug, lang }
        }));
      }`
        );
    }

    // 3. Update Astro.props to expect `page`
    if (!isLang) {
        content = content.replace(
            'const { products, categorySlug } = Astro.props;',
            'const { page, categorySlug } = Astro.props;\nconst products = page.data;'
        );
    } else {
        content = content.replace(
            'const { products, categorySlug, lang } = Astro.props;',
            'const { page, categorySlug, lang } = Astro.props;\nconst products = page.data;'
        );
    }

    // 4. Remove the manual sort logic since we already sorted before paginate
    content = content.replace(/\/\/ Sort products: featured first, then by date[\s\S]*?return dateB - dateA;\n\}\);/, '');

    // 5. Replace `sortedProducts.length` with `page.total` in DOM
    content = content.replace(/\{sortedProducts\.length\}/g, '{page.total}');

    // 6. Fix "Showing {start}-{end} of {total}" display
    if (isLang) {
        content = content.replace(
            `.replace('{start}', '1')
                    .replace('{end}', String(sortedProducts.length))
                    .replace('{total}', String(page.total))}`,
            `.replace('{start}', String(page.start + 1))
                    .replace('{end}', String(Math.min(page.end, page.total)))
                    .replace('{total}', String(page.total))}`
        );
    } else {
        content = content.replace(
            `.replace('{start}', '1')
                    .replace('{end}', String(sortedProducts.length))
                    .replace('{total}', String(page.total))}`,
            `.replace('{start}', String(page.start + 1))
                    .replace('{end}', String(Math.min(page.end, page.total)))
                    .replace('{total}', String(page.total))}`
        );
    }

    // Replace array name in map
    content = content.replace(/sortedProducts\.map/g, 'products.map');

    // 7. Inject Pagination HTML
    const paginationHtml = `
              <!-- Pagination -->
              {page.lastPage > 1 && (
                <nav class="mt-12 flex justify-center items-center gap-2" dir={isRTL ? 'rtl' : 'ltr'}>
                  <!-- Previous Button -->
                  {page.url.prev ? (
                    <a
                      href={page.url.prev}
                      class="flex items-center gap-1 px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 hover:border-[#8B7355] hover:text-[#8B7355] transition-colors"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                      </svg>
                      <span class="hidden sm:inline">{pt.previousPage || 'Previous'}</span>
                    </a>
                  ) : (
                    <span class="flex items-center gap-1 px-4 py-2 border border-gray-100 rounded-lg text-gray-300 cursor-not-allowed">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                      </svg>
                      <span class="hidden sm:inline">{pt.previousPage || 'Previous'}</span>
                    </span>
                  )}

                  <!-- Page Numbers -->
                  <div class="flex items-center gap-1">
                    {/* First page */}
                    <a
                      href={\`/\${currentPath}product/\${categorySlug}\`}
                      class={\`w-10 h-10 flex items-center justify-center rounded-lg text-sm font-medium transition-colors \${
                        page.currentPage === 1
                          ? 'bg-[#8B7355] text-white'
                          : 'border border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-[#8B7355] hover:text-[#8B7355]'
                      }\`}
                    >
                      1
                    </a>

                    {/* Ellipsis before current */}
                    {page.currentPage > 3 && (
                      <span class="px-2 text-gray-400">...</span>
                    )}

                    {/* Pages around current */}
                    {Array.from({ length: page.lastPage }, (_, i) => i + 1)
                      .filter(p => p > 1 && p < page.lastPage && Math.abs(p - page.currentPage) <= 1)
                      .map(p => (
                        <a
                          href={\`/\${currentPath}product/\${categorySlug}/\${p}\`}
                          class={\`w-10 h-10 flex items-center justify-center rounded-lg text-sm font-medium transition-colors \${
                            p === page.currentPage
                              ? 'bg-[#8B7355] text-white'
                              : 'border border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-[#8B7355] hover:text-[#8B7355]'
                          }\`}
                        >
                          {p}
                        </a>
                      ))}

                    {/* Ellipsis after current */}
                    {page.currentPage < page.lastPage - 2 && (
                      <span class="px-2 text-gray-400">...</span>
                    )}

                    {/* Last page */}
                    {page.lastPage > 1 && (
                      <a
                        href={\`/\${currentPath}product/\${categorySlug}/\${page.lastPage}\`}
                        class={\`w-10 h-10 flex items-center justify-center rounded-lg text-sm font-medium transition-colors \${
                          page.currentPage === page.lastPage
                            ? 'bg-[#8B7355] text-white'
                            : 'border border-gray-200 text-gray-600 hover:bg-gray-50 hover:border-[#8B7355] hover:text-[#8B7355]'
                        }\`}
                      >
                        {page.lastPage}
                      </a>
                    )}
                  </div>

                  <!-- Next Button -->
                  {page.url.next ? (
                    <a
                      href={page.url.next}
                      class="flex items-center gap-1 px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 hover:border-[#8B7355] hover:text-[#8B7355] transition-colors"
                    >
                      <span class="hidden sm:inline">{pt.nextPage || 'Next'}</span>
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                      </svg>
                    </a>
                  ) : (
                    <span class="flex items-center gap-1 px-4 py-2 border border-gray-100 rounded-lg text-gray-300 cursor-not-allowed">
                      <span class="hidden sm:inline">{pt.nextPage || 'Next'}</span>
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                      </svg>
                    </span>
                  )}
                </nav>
              )}
`;

    // Provide fallback for `isRTL` in English file
    if (!isLang) {
        if (!content.includes('const isRTL')) {
            content = content.replace('const currentPath = \'\'; // English is at root', 'const currentPath = \'\'; // English is at root\nconst isRTL = false;');
        }
    }

    content = content.replace(
        /\{\s*products\.map\(\(product\) => \([\s\S]*?ProductCard product=\{product\} lang=\{lang\} \/>[\s\S]*?\)\)\s*\}/,
        (match) => match + '\n              </div>\n' + paginationHtml.replace('</div>', '')
    );

    return content;
}

const enPathOrig = path.join(__dirname, 'src/pages/product/[category].astro');
const enContent = processAstroFile(enPathOrig, false);
fs.mkdirSync(path.join(__dirname, 'src/pages/product/[category]'), { recursive: true });
fs.writeFileSync(path.join(__dirname, 'src/pages/product/[category]/[...page].astro'), enContent);

const langPathOrig = path.join(__dirname, 'src/pages/[lang]/product/[category].astro');
const langContent = processAstroFile(langPathOrig, true);
fs.mkdirSync(path.join(__dirname, 'src/pages/[lang]/product/[category]'), { recursive: true });
fs.writeFileSync(path.join(__dirname, 'src/pages/[lang]/product/[category]/[...page].astro'), langContent);

fs.unlinkSync(enPathOrig);
fs.unlinkSync(langPathOrig);

console.log("Done refactoring category pagination.");
