import { defineMiddleware } from 'astro/middleware';

export const onRequest = defineMiddleware((context, next) => {
    const url = new URL(context.request.url);
    const path = url.pathname;

    // If the path contains any uppercase letters
    if (path !== path.toLowerCase()) {
        url.pathname = path.toLowerCase();

        // Redirect 301 to the lowercase version
        return Response.redirect(url.toString(), 301);
    }

    return next();
});
