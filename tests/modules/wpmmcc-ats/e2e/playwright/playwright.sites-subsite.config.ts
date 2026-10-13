import { defineConfig } from '@playwright/test'

// 批 O2 / U-5 (12号 §27): the Sites-page subsite binding lane.
// Mirrors playwright.review-loop.config.ts (EN-locale pinned because the
// spec asserts English UI copy; serial because the lane drives one client).
export default defineConfig({
  testDir: 'sites-subsite',
  timeout: 600_000,
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
