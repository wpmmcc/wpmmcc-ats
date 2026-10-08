import { defineConfig, devices } from '@playwright/test'

// 3.8flash A3+D4: provider-catalog offline degradation lane config. The base
// URL is owned by run-playwright-provider-catalog-offline.sh (one dedicated
// loopback Client per lane run, with the catalog source pointed at an owned
// 404 server) and passed in through the environment — never hardcoded.
const baseURL = process.env.WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL
if (!baseURL) {
  throw new Error(
    'WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL is not set. Run the lane via tests/modules/wpmmcc-ats/e2e/run-playwright-provider-catalog-offline.sh.',
  )
}

export default defineConfig({
  testDir: './provider-catalog-offline',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL,
    headless: true,
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        locale: 'zh-CN',
        timezoneId: 'Asia/Shanghai',
      },
    },
  ],
})
