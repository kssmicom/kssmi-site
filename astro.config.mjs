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
  prefetch: {
    prefetchAll: false,       // Don't auto-prefetch every link on the page
    defaultStrategy: 'hover', // Only prefetch when user hovers (shows intent)
  },
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
          // ── Use plugin instance state (survives server.restart()) ──────────
          if (!this._knownFolders) {
            this._knownFolders = new Set();
            this._restarting = false;
            this._interval = null;
          }

          const productsDir = path.resolve('./src/content/products');
          const collectionDir = path.resolve('./src/content/collection');
          const blogDir = path.resolve('./src/content/blog');
          const cacheFile = path.resolve('./.astro/data-store.json');
          const cacheDir = path.resolve('./.astro/collections');

          let debounceTimer = null;

          // ── Init folder list on first run ────────────────────────────────
          try {
            const entries = fs.readdirSync(productsDir, { withFileTypes: true });
            for (const entry of entries) {
              if (entry.isDirectory()) this._knownFolders.add(entry.name);
            }
          } catch { /* ignore */ }

          // ── Clear stale interval from any PREVIOUS server instance ────────
          if (this._interval) {
            clearInterval(this._interval);
            this._interval = null;
          }

          // ── Poll for new/removed product folders ─────────────────────────
          this._interval = setInterval(() => {
            if (this._restarting) return;

            try {
              const currentFolders = new Set();
              const entries = fs.readdirSync(productsDir, { withFileTypes: true });
              for (const entry of entries) {
                if (entry.isDirectory()) currentFolders.add(entry.name);
              }

              for (const folder of currentFolders) {
                if (!this._knownFolders.has(folder)) {
                  console.log('\x1b[36m[Content]\x1b[0m New product detected: ' + folder);
                  queueRestart();
                  break;
                }
              }
              for (const folder of this._knownFolders) {
                if (!currentFolders.has(folder)) {
                  console.log('\x1b[36m[Content]\x1b[0m Product removed: ' + folder);
                  queueRestart();
                  break;
                }
              }

              this._knownFolders = currentFolders;
            } catch { /* ignore */ }
          }, 3000);

          // ── Restart with lock to prevent concurrent restarts ──────────────
          function queueRestart() {
            if (debounceTimer) return;
            debounceTimer = setTimeout(async () => {
              this._restarting = true;
              try {
                if (fs.existsSync(cacheFile)) fs.unlinkSync(cacheFile);
                if (fs.existsSync(cacheDir)) fs.rmSync(cacheDir, { recursive: true, force: true });

                console.log('\x1b[36m[Content]\x1b[0m Restarting dev server…');
                await server.restart();
              } catch (e) {
                console.error('\x1b[31m[Content]\x1b[0m Restart failed:', e.message);
              }
              this._restarting = false;
              debounceTimer = null;
            }, 2000);
          }

          // ── Watch collection/blog file changes ────────────────────────────
          [collectionDir, blogDir].forEach(watchPath => {
            if (!fs.existsSync(watchPath)) return;
            try {
              const addRecursiveWatch = (dir, depth = 0) => {
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
            if (this._interval) {
              clearInterval(this._interval);
              this._interval = null;
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