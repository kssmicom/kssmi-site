import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import { translations } from '../../../translations';

const LANGS = ['en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'];

export function getStaticPaths() {
    return LANGS.map((lang) => ({ params: { lang } }));
}

export const GET: APIRoute = async ({ params }) => {
    const lang = params.lang as string;
    const t = (translations as any)[lang] || (translations as any).en;
    const basePath = lang === 'en' ? '' : `/${lang}`;
    const results: object[] = [];

    // ─── 1. PRODUCTS ─────────────────────────────────────────────────────────────
    const allProducts = await getCollection('products');
    allProducts
        .filter((product) => {
            // IDs are like: yeto-lc001-metal-optical.en | yeto-lc001-metal-optical.ja
            // Use endsWith to robustly match the language suffix
            return product.id.endsWith(`.${lang}`);
        })
        .forEach((product) => {
            // Strip the language suffix to get the base slug
            const baseName = product.id.slice(0, -(`.${lang}`.length));
            const slug = product.data.slug || baseName;
            const productBase = lang === 'en' ? '/product/' : `/${lang}/product/`;
            results.push({
                type: 'product',
                title: product.data.title || '',
                itemNo: product.data.itemNo || '',
                description: product.data.seoDescription || '',
                keywords: `${(product.data.categories || []).join(' ')} ${(product.data.materials || []).join(' ')} ${(product.data.colors || []).join(' ')} ${product.data.specs?.designStyle || ''}`.trim(),
                url: `${productBase}${slug}`,
                image: product.data.cover || '',
                featured: product.data.featured || false,
            });
        });


    // ─── 2. BLOG POSTS ────────────────────────────────────────────────────────────
    try {
        const allPosts = await getCollection('blog');
        allPosts
            .filter((post) => (post.data.lang || 'en') === lang)
            .forEach((post) => {
                const slug = post.data.slug || post.slug || post.id;
                results.push({
                    type: 'blog',
                    title: post.data.title || '',
                    description: post.data.excerpt || post.data.seoDescription || '',
                    keywords: (post.data.tags || []).join(' '),
                    url: `${basePath}/blog/${slug}`,
                    image: post.data.image || '',
                    date: post.data.published ? String(post.data.published) : '',
                });
            });
    } catch (_) {
        // Blog collection may be empty; ignore
    }

    // ─── 3. LANDING PAGES ────────────────────────────────────────────────────────
    try {
        const allLanding = await getCollection('landing');
        allLanding
            .filter((page) => (page.data.lang || 'en') === lang)
            .forEach((page) => {
                const slug = page.data.slug || page.slug || page.id;
                results.push({
                    type: 'page',
                    title: page.data.title || '',
                    description: page.data.seoDescription || '',
                    keywords: '',
                    url: `${basePath}/landing/${slug}`,
                    image: page.data.image || '',
                });
            });
    } catch (_) {
        // Landing collection may be empty; ignore
    }

    // ─── 4. STATIC SITE PAGES ────────────────────────────────────────────────────
    // No description/keywords — only the translated title should trigger a match.
    const staticPages = [
        {
            key: 'home',
            title: t.nav?.home || 'Home',
            description: '',
            keywords: '',
            url: `${basePath}/`,
            image: '',
        },
        {
            key: 'products',
            title: t.nav?.products || 'Products',
            description: '',
            keywords: '',
            url: `${basePath}/product`,
            image: '',
        },
        {
            key: 'quote',
            title: t.cta?.quote || 'Request Quote',
            description: '',
            keywords: '',
            url: `${basePath}/quote`,
            image: '',
        },
    ];

    staticPages.forEach((p) => {
        results.push({ type: 'page', ...p });
    });

    // Deduplicate by URL
    const seen = new Set<string>();
    const deduped = results.filter((r: any) => {
        if (seen.has(r.url)) return false;
        seen.add(r.url);
        return true;
    });

    return new Response(JSON.stringify(deduped), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
};
