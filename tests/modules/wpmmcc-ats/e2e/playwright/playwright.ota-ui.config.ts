import { defineConfig } from '@playwright/test'

// 批 O3 / U-2 (12号 §29): the Settings About-tab OTA UI journey lane.
// Mirrors playwright.sites-subsite.config.ts (EN-locale pinned because the
// specs assert English UI copy; serial because the lane drives one client).
export default defineConfig({
  testDir: 'ota-ui',
  timeout: 300_000,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    locale: 'en-US',
    headless: true,
    actionTimeout: 30_000,
    navigationTimeout: 60_000,
  },
})
