import { defineConfig, devices } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const envTestPath = path.join(__dirname, '.env.test')
if (fs.existsSync(envTestPath)) {
  for (const line of fs.readFileSync(envTestPath, 'utf-8').split('\n')) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) {
      continue
    }
    const eqIdx = trimmed.indexOf('=')
    if (eqIdx <= 0) {
      continue
    }
    const key = trimmed.slice(0, eqIdx).trim()
    const value = trimmed.slice(eqIdx + 1).trim()
    if (!process.env[key]) {
      process.env[key] = value
    }
  }
}

export default defineConfig({
  testDir: './support-client-server',
  timeout: 180_000,
  expect: { timeout: 20_000 },
  fullyParallel: false,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:8977',
    headless: true,
    viewport: { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    bypassCSP: true,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
