import { defineMiddleware } from 'astro/middleware';

export const onRequest = defineMiddleware((context, next) => {
    const url = new URL(context.request.url);
    const path = url.pathname;

    // Normalize literal route letters, preserving percent-encoded bytes.
    // Lowercasing %E0 into %e0 redirects to the same decoded path and can
    // overwrite a prerendered Unicode route with a self-redirect page.
    const lowercasePath = path.replace(/%[0-9a-f]{2}|[A-Z]/gi, (part) =>
        part.startsWith('%') ? part : part.toLowerCase()
    );
    if (path !== lowercasePath) {
        url.pathname = lowercasePath;

        // Redirect 301 to the lowercase version
        return Response.redirect(url.toString(), 301);
    }

    return next();
});
