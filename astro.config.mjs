// @ts-check
import { defineConfig } from 'astro/config';
import tailwind from '@astrojs/tailwind';
import pagefind from 'astro-pagefind';
import fs from 'node:fs';
import path from 'node:path';

// https://astro.build/config
export default defineConfig({
  site: 'https://kssmi.com',
  output: 'static',
  prefetch: true,
  integrations: [
    tailwind({
      applyBaseStyles: false,
    }),
    pagefind(),
  ],
  i18n: {
    defaultLocale: 'en',
    locales: [
      'en', 'it', 'es', 'fr', 'de', 'pt', 'ru', 'ja', 'tr', 'ar', 'ko', 'zh', 'hi', 'vi', 'jv', 'ms', 'tg'
    ],
    routing: {
      prefixDefaultLocale: false
    }
  },
  build: {
    format: 'directory'
  },
  vite: {
    plugins: [
      {
        name: 'content-folder-watcher',
        configureServer(server) {
          const productsDir = path.resolve('./src/content/products');
          const collectionDir = path.resolve('./src/content/collection');
          const blogDir = path.resolve('./src/content/blog');
          const cacheFile = path.resolve('./.astro/data-store.json');
          const cacheDir = path.resolve('./.astro/collections');

          // Track product folders
          let knownProductFolders = new Set();
          let debounceTimer = null;

          // Initialize known product folders
          try {
            const entries = fs.readdirSync(productsDir, { withFileTypes: true });
            for (const entry of entries) {
              if (entry.isDirectory()) {
                knownProductFolders.add(entry.name);
              }
            }
          } catch (e) {
            // Ignore
          }

          // Check for product folder changes every 2 seconds
          const productCheckInterval = setInterval(() => {
            try {
              const currentFolders = new Set();
              const entries = fs.readdirSync(productsDir, { withFileTypes: true });

              for (const entry of entries) {
                if (entry.isDirectory()) {
                  currentFolders.add(entry.name);

                  if (!knownProductFolders.has(entry.name)) {
                    console.log('\x1b[36m[Content]\x1b[0m New product detected: ' + entry.name);
                    triggerCacheClear();
                  }
                }
              }

              for (const folder of knownProductFolders) {
                if (!currentFolders.has(folder)) {
                  console.log('\x1b[36m[Content]\x1b0m Product removed: ' + folder);
                  triggerCacheClear();
                }
              }

              knownProductFolders = currentFolders;
            } catch (e) {
              // Ignore errors
            }
          }, 2000);

          // Watch collection and blog directories for file changes (not just folders)
          const contentWatchPaths = [collectionDir, blogDir];

          function triggerCacheClear() {
            if (debounceTimer) clearTimeout(debounceTimer);

            debounceTimer = setTimeout(async () => {
              try {
                if (fs.existsSync(cacheFile)) {
                  fs.unlinkSync(cacheFile);
                }
                if (fs.existsSync(cacheDir)) {
                  fs.rmSync(cacheDir, { recursive: true });
                }
                console.log('\x1b[36m[Content]\x1b[0m Content changed — restarting server to pick up changes...');

                // Restart the dev server so Astro re-runs the glob content loader
                await server.restart();
              } catch (e) {
                // Ignore errors
              }
            }, 1000);
          }

          // Use Vite's file system watcher for collection/blog changes
          contentWatchPaths.forEach(watchPath => {
            if (!fs.existsSync(watchPath)) return;

            // Watch for any file changes in collection/blog directories
            server.watcher.add(watchPath);

            // Also watch recursively by adding individual subdirectories
            try {
              const addRecursiveWatch = (dir, depth = 0) => {
                if (depth > 3) return; // Limit recursion depth
                const entries = fs.readdirSync(dir, { withFileTypes: true });
                for (const entry of entries) {
                  const fullPath = path.join(dir, entry.name);
                  if (entry.isDirectory()) {
                    server.watcher.add(fullPath);
                    addRecursiveWatch(fullPath, depth + 1);
                  }
                }
              };
              addRecursiveWatch(watchPath);
            } catch (e) {
              // Ignore watch errors
            }
          });

          // Listen for change events on the watcher
          server.watcher.on('change', (filePath) => {
            const normalizedPath = filePath.replace(/\\/g, '/');

            // Check if changed file is in collection or blog
            if (normalizedPath.includes('/content/collection/') ||
                normalizedPath.includes('/content/blog/')) {
              console.log('\x1b[36m[Content]\x1b[0m File changed: ' + path.basename(filePath));
              triggerCacheClear();
            }
          });

          // Cleanup on server close
          if (server.httpServer) {
            server.httpServer.on('close', () => {
              clearInterval(productCheckInterval);
            });
          }
        }
      },
    ],
    server: {
      watch: {
        usePolling: true,
        interval: 300
      }
    }
  }
});