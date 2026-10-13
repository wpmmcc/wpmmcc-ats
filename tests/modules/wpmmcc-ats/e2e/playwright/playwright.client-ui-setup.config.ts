import { defineConfig, devices } from '@playwright/test'

/** Client UI setup journeys (pre-WP bind, etc.) — real DOM, no form-via-API. */
export default defineConfig({
  testDir: './client-ui-setup',
  testIgnore: ['review-mode.local.spec.ts'],
  timeout: 600_000,
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.WEBUI_A_BASE || process.env.CLIENT_BASE || 'http://127.0.0.1:8977',
    headless: process.env.HEADED === '1' ? false : true,
    viewport: { width: 1400, height: 900 },
    ignoreHTTPSErrors: true,
    bypassCSP: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
