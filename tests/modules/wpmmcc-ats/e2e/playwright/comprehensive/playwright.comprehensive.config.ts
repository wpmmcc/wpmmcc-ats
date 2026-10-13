import { defineConfig, devices } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const envTestPath = path.join(__dirname, '.env.test')
if (fs.existsSync(envTestPath)) {
  for (const line of fs.readFileSync(envTestPath, 'utf-8').split('\n')) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) continue
    const eqIdx = trimmed.indexOf('=')
    if (eqIdx > 0) {
      const key = trimmed.slice(0, eqIdx).trim()
      const value = trimmed.slice(eqIdx + 1).trim()
      if (!process.env[key]) process.env[key] = value
    }
  }
}

function disableProxyForLocalWpE2E() {
  if (process.env.WPTSALL_KEEP_PROXY === '1') return
  const hosts = ['blog.wpmm.cc', '127.0.0.1', 'localhost', '::1']
  for (const key of ['NO_PROXY', 'no_proxy']) {
    const existing = (process.env[key] || '').split(',').map(s => s.trim()).filter(Boolean)
    const merged = [...new Set([...existing, ...hosts])]
    if (merged.length) process.env[key] = merged.join(',')
  }
  for (const key of ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy']) {
    if (key in process.env) delete process.env[key]
  }
}
disableProxyForLocalWpE2E()

const wpBase = (process.env.WP_BASE || 'http://127.0.0.1:9083').replace(/\/+$/, '')

// Per-run id (opus5 P-4): concurrent comprehensive runs must not clobber each
// other's artifacts. A consumer wanting a stable path can pin it via
// WPTSALL_COMPREHENSIVE_RUN_ID / PLAYWRIGHT_JSON_OUTPUT.
const runId = process.env.WPTSALL_COMPREHENSIVE_RUN_ID || `run-${Date.now()}-${process.pid}`

export default defineConfig({
  testDir: '.',
  testMatch: ['*.spec.ts'],
  testIgnore: [
    '17-translate-editor-save.spec.ts', '20-content-plugin-admin-meta.spec.ts',
    '20-content-plugin-admin-save.owned.spec.ts',
    '21-content-type-admin-matrix.spec.ts', '21-content-type-admin-save.owned.spec.ts',
  ],
  fullyParallel: false,
  workers: 1,
  retries: 1,
  // P-4: the JSON report and the trace outputDir used to live at fixed shared
  // paths (/tmp/wptsall-comprehensive-results.json, ./test-results); two
  // parallel lanes overwrote each other's results.
  outputDir: path.join(__dirname, 'test-results', runId),
  reporter: [
    ['list'],
    ['json', {
      outputFile:
        process.env.PLAYWRIGHT_JSON_OUTPUT ??
        `/tmp/wptsall-comprehensive-results-${runId}.json`,
    }],
  ],
  use: {
    baseURL: wpBase,
    ignoreHTTPSErrors: true,
    actionTimeout: 15000,
    navigationTimeout: 30000,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'comprehensive',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 800 },
      },
    },
  ],
})
