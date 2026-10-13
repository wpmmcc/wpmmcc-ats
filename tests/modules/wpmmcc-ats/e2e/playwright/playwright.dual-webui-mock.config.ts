import { defineConfig, devices } from '@playwright/test'

/**
 * Independent lane: dual WebUI agents ↔ mock-api.
 * Not part of release-gate / Lab matrix.
 */
export default defineConfig({
  testDir: './dual-webui-mock',
  timeout: 180_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.WEBUI_A_BASE || 'http://127.0.0.1:8977',
    headless: true,
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    bypassCSP: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
