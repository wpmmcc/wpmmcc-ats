import { defineConfig, devices } from '@playwright/test'

/**
 * SIM lane: real client + mock plugin sites (in-spec Node servers).
 *
 * The client, its state root, and every mock site are owned by
 * run-simulation-lane.sh; this config only consumes
 * WPTSALL_SIMULATION_BASE_URL (no hardcoded 8977 anywhere).
 */
const baseURL = process.env.WPTSALL_SIMULATION_BASE_URL
if (!baseURL) {
  throw new Error(
    'WPTSALL_SIMULATION_BASE_URL is not set — start the lane via '
    + 'bash tests/modules/wpmmcc-ats/e2e/run-simulation-lane.sh',
  )
}

export default defineConfig({
  testDir: './simulation',
  timeout: 240_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL,
    headless: true,
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    bypassCSP: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
