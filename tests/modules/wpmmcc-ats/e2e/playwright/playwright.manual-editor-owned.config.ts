import { defineConfig, devices } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { assertOwnedManualLane, assertOwnedManualContainer } from './lib/owned-manual-editor'

const lane = assertOwnedManualLane(process.env)
for (const site of lane.sites) {
  const info = JSON.parse(execFileSync('docker', ['inspect', site.name],
    { encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 15_000 }))[0]
  assertOwnedManualContainer(info, lane, site)
}
for (const key of ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy']) {
  delete process.env[key]
}

export default defineConfig({
  testDir: './comprehensive',
  testMatch: ['17-translate-editor-save.spec.ts'],
  outputDir: `${process.env.WPTSALL_MANUAL_ARTIFACTS_DIR}/playwright`,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 180_000,
  expect: { timeout: 10_000 },
  reporter: 'list',
  use: { headless: true, actionTimeout: 20_000, navigationTimeout: 60_000, trace: 'off' },
  projects: [{ name: 'owned-manual-editor', use: { ...devices['Desktop Chrome'] } }],
})
