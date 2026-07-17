// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import pagefind from 'astro-pagefind';
import fs from 'node:fs';
import path from 'node:path';

// https://astro.build/config
export default defineConfig({
  site: 'https://kssmi.com',
  output: 'static',
  prefetch: {
    prefetchAll: false,        // Don't auto-prefetch every link on the page
    defaultStrategy: 'viewport', // Prefetch as soon as the link is visible on screen
  },
  integrations: [pagefind()],
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
    format: 'directory',
    // Inline only small CSS chunks to remove high-latency render-blocking requests. The main
    // Tailwind/critical bundle stays hashed and external; generate-csp-hashes.mjs hashes any
    // resulting inline styles before deployment, so the strict static CSP remains valid.
    inlineStylesheets: 'auto'
  },
  vite: {
    plugins: [
      tailwindcss(),
      {
        name: 'content-folder-watcher',
        configureServer(server) {
          // ── Use closure state (survives server.restart()) ──────────────────
          const state = {
            knownFolders: new Set(),
            restarting: false,
            interval: /** @type {ReturnType<typeof setInterval> | null} */ (null),
          };

          const productsDir = path.resolve('./src/content/products');
          const collectionDir = path.resolve('./src/content/collection');
          const blogDir = path.resolve('./src/content/blog');
          const cacheFile = path.resolve('./.astro/data-store.json');
          const cacheDir = path.resolve('./.astro/collections');

          /** @type {ReturnType<typeof setTimeout> | null} */
          let debounceTimer = null;

          // ── Init folder list on first run ────────────────────────────────
          try {
            const entries = fs.readdirSync(productsDir, { withFileTypes: true });
            for (const entry of entries) {
              if (entry.isDirectory()) state.knownFolders.add(entry.name);
            }
          } catch { /* ignore */ }

          // ── Poll for new/removed product folders ─────────────────────────
          state.interval = setInterval(() => {
            if (state.restarting) return;

            try {
              const currentFolders = new Set();
              const entries = fs.readdirSync(productsDir, { withFileTypes: true });
              for (const entry of entries) {
                if (entry.isDirectory()) currentFolders.add(entry.name);
              }

              for (const folder of currentFolders) {
                if (!state.knownFolders.has(folder)) {
                  console.log('\x1b[36m[Content]\x1b[0m New product detected: ' + folder);
                  queueRestart();
                  break;
                }
              }
              for (const folder of state.knownFolders) {
                if (!currentFolders.has(folder)) {
                  console.log('\x1b[36m[Content]\x1b[0m Product removed: ' + folder);
                  queueRestart();
                  break;
                }
              }

              state.knownFolders = currentFolders;
            } catch { /* ignore */ }
          }, 3000);

          // ── Restart with lock to prevent concurrent restarts ──────────────
          function queueRestart() {
            if (debounceTimer) return;
            debounceTimer = setTimeout(async () => {
              state.restarting = true;
              try {
                if (fs.existsSync(cacheFile)) fs.unlinkSync(cacheFile);
                if (fs.existsSync(cacheDir)) fs.rmSync(cacheDir, { recursive: true, force: true });

                console.log('\x1b[36m[Content]\x1b[0m Restarting dev server…');
                await server.restart();
              } catch (/** @type {any} */ e) {
                console.error('\x1b[31m[Content]\x1b[0m Restart failed:', e?.message || e);
              }
              state.restarting = false;
              debounceTimer = null;
            }, 2000);
          }

          // ── Watch collection/blog file changes ────────────────────────────
          [collectionDir, blogDir].forEach(watchPath => {
            if (!fs.existsSync(watchPath)) return;
            try {
              const addRecursiveWatch = (/** @type {string} */ dir, /** @type {number} */ depth = 0) => {
                if (depth > 3) return;
                for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                  const fullPath = path.join(dir, entry.name);
                  if (entry.isDirectory()) {
                    server.watcher.add(fullPath);
                    addRecursiveWatch(fullPath, depth + 1);
                  }
                }
              };
              server.watcher.add(watchPath);
              addRecursiveWatch(watchPath);
            } catch { /* ignore */ }
          });

          if (server.watcher?.setMaxListeners) server.watcher.setMaxListeners(50);

          server.watcher.on('change', (filePath) => {
            const p = filePath.replace(/\\/g, '/');
            if (p.includes('/content/collection/') || p.includes('/content/blog/')) {
              console.log('\x1b[36m[Content]\x1b[0m File changed: ' + path.basename(filePath));
              queueRestart();
            }
          });

          // ── Cleanup interval when THIS server instance closes ────────────
          server.httpServer?.on('close', () => {
            if (state.interval) {
              clearInterval(state.interval);
              state.interval = null;
            }
          });
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
