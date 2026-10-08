import { defineConfig, devices } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { assertOwnedContentMatrixLane } from './lib/owned-content-matrix'
import { assertOwnedManualContainer } from './lib/owned-manual-editor'

const lane = assertOwnedContentMatrixLane(process.env)
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
  testMatch: ['21-content-type-admin-save.owned.spec.ts'],
  outputDir: `${process.env.WPTSALL_MANUAL_ARTIFACTS_DIR}/playwright`,
  workers: 1, retries: 0, fullyParallel: false, timeout: 240_000,
  reporter: 'list', expect: { timeout: 10_000 },
  use: { headless: true, actionTimeout: 20_000, navigationTimeout: 60_000, trace: 'off', screenshot: 'only-on-failure' },
  projects: [{ name: 'owned-content-matrix', use: { ...devices['Desktop Chrome'] } }],
})
