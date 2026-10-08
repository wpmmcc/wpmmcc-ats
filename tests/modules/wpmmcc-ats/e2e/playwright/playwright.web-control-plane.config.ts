import { defineConfig } from '@playwright/test'
import * as path from 'path'

const repoRoot = path.resolve(__dirname, '../../../../..')
const webAppDir = path.join(repoRoot, 'web/source/app')
const webAppUrl = process.env.WEB_APP_URL || process.env.WPTSALL_WEB_BASE || 'http://127.0.0.1:5173'
const webAppPort = Number(new URL(webAppUrl).port || 5173)

export default defineConfig({
  testDir: './support-web-control-plane',
  timeout: 30_000,
  retries: 0,
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: webAppUrl,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: `npm run dev -- --host 127.0.0.1 --port ${webAppPort}`,
    cwd: webAppDir,
    port: webAppPort,
    reuseExistingServer: true,
    timeout: 15_000,
  },
})
