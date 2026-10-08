import { defineConfig, devices } from '@playwright/test'

/** UI-driven WebUI provider wizard ↔ live mock (no page.route). */
export default defineConfig({
  testDir: './ui-provider-mock',
  timeout: 7_200_000, // full 112 catalog UI wizards can take >1h
  expect: { timeout: 30_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.WEBUI_A_BASE || process.env.CLIENT_BASE || 'http://127.0.0.1:8977',
    headless: true,
    viewport: { width: 1400, height: 900 },
    ignoreHTTPSErrors: true,
    bypassCSP: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
