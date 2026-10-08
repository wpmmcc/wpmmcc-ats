// @ts-check
const { defineConfig } = require('@playwright/test');

const baseURL = process.env.WPTSALL_SEED_WP_BASE_URL || process.env.WP_URL || 'https://blog.wpmm.cc';

module.exports = defineConfig({
  testDir: '.',
  timeout: 300000, // 5 minutes per test (imports can be slow)
  expect: {
    timeout: 30000
  },
  fullyParallel: false, // Run sequentially to avoid conflicts
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 30000,
  },
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
        headless: true, // Set to false for debugging
      },
    },
  ],
});
