import { defineConfig, devices } from '@playwright/test'

// P0-LF-06: the base URL is owned by run-playwright-support-client-local-ui.sh
// (one dedicated loopback Client per lane run) and passed in through the
// environment. No hardcoded 8977: the lane must never reuse a systemd or
// developer Client.
const baseURL = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL
if (!baseURL) {
  throw new Error(
    'WPTSALL_CLIENT_LOCAL_UI_BASE_URL is not set. Run the lane via tests/modules/wpmmcc-ats/e2e/run-playwright-support-client-local-ui.sh.',
  )
}

export default defineConfig({
  testDir: './support-client-local-ui',
  outputDir: process.env.WPTSALL_LANE_ARTIFACTS_DIR
    ? `${process.env.WPTSALL_LANE_ARTIFACTS_DIR}/playwright`
    : './test-results/client-local-ui',
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
