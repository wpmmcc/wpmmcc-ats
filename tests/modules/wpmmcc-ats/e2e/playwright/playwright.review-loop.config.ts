import { defineConfig, devices } from '@playwright/test'

// 批 O1 / U-3 (12号 §26): Review-loop lane — the single serial spec covering
// wp-admin editor/task-modal entry → client review UI → public frontend
// visibility, against a local-first stack (lab WP + loopback web client +
// mock translate API; NO website/server/PG). The base URL is owned by
// run-playwright-review-loop.sh (one dedicated loopback client per lane run)
// and passed through the environment — no hardcoded 8977, the lane must
// never reuse a systemd or developer client.
const baseURL = process.env.WPTSALL_REVIEW_LOOP_CLIENT_BASE
if (!baseURL) {
  throw new Error(
    'WPTSALL_REVIEW_LOOP_CLIENT_BASE_URL is not set. Run the lane via tests/modules/wpmmcc-ats/e2e/run-playwright-review-loop.sh.',
  )
}

export default defineConfig({
  testDir: './review-loop',
  timeout: 600_000,
  expect: { timeout: 30_000 },
  fullyParallel: false, // Serial: the loop depends on prior state (task → review → writeback)
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL,
    headless: true,
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    launchOptions: {
      args: [
        '--no-proxy-server',
        '--proxy-server=direct://',
        '--proxy-bypass-list=*',
      ],
    },
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // The journey locale lesson (批 M): pin EN so assertions match the
        // EN dictionary regardless of the host browser locale.
        locale: 'en-US',
        timezoneId: 'UTC',
      },
    },
  ],
})
