// Browser smoke tests against the built site (run `npm run build` first).
// Every request that is not for the local server is aborted in the tests, so
// no analytics or other third-party call ever leaves the browser. The one
// exception is turnstile-real.spec.mjs, which loads the real Turnstile API
// from challenges.cloudflare.com with Cloudflare's published test site keys.
import { defineConfig, devices } from '@playwright/test';

const port = 4321;

export default defineConfig({
  testDir: 'tests/browser',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['github']] : 'list',
  timeout: 45000,
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
  ],
  webServer: {
    command: `node scripts/serve-dist.mjs dist ${port}`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: !process.env.CI,
    timeout: 30000,
  },
});
