import { defineConfig } from '@playwright/test'
import * as path from 'path'

const webAppDir = path.resolve(__dirname, '../../../../../web/source/app')

export default defineConfig({
  testDir: './audit-web-control-plane',
  timeout: 30_000,
  retries: 0,
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://localhost:5173',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5173',
    cwd: webAppDir,
    port: 5173,
    reuseExistingServer: true,
    timeout: 15_000,
  },
})
