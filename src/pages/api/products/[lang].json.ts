import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';

const LANGS = ['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];

export function getStaticPaths() {
    return LANGS.map((lang) => ({ params: { lang } }));
}

export const GET: APIRoute = async ({ params }) => {
    const lang = params.lang as string;

    const allProducts = await getCollection('products');

    // Filter products by language suffix and exclude the default 'en' brand of products for non-english langs
    const langProducts = allProducts
        .filter((product) => {
            return product.id.endsWith(`.${lang}`);
        })
        .map((product) => {
            const baseName = product.id.slice(0, -(`.${lang}`.length));
            const slug = product.data.slug || baseName;
            const basePath = lang === 'en' ? '/product/' : `/${lang}/product/`;
            return {
                slug,
                title: product.data.title || '',
                itemNo: product.data.itemNo || '',
                seoDescription: product.data.seoDescription || '',
                categories: product.data.categories || [],
                materials: product.data.materials || [],
                colors: product.data.colors || [],
                serviceMode: product.data.serviceMode || [],
                designStyle: product.data.specs?.designStyle || '',
                url: `${basePath}${slug}`,
                cover: product.data.cover || '',
                featured: product.data.featured || false,
                date: product.data.date ? String(product.data.date) : '',
            };
        });

    return new Response(JSON.stringify(langProducts), {
        status: 200,
        headers: {
            'Content-Type': 'application/json',
        },
    });
};
