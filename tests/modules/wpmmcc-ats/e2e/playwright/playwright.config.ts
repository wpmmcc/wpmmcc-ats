import { defineConfig, devices } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

// Load .env.test if present (pure Node.js, no dotenv dependency)
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

function ensureNoProxyHosts(hosts: string[]) {
  for (const key of ['NO_PROXY', 'no_proxy']) {
    const existing = (process.env[key] || '')
      .split(',')
      .map(item => item.trim())
      .filter(Boolean)
    const merged = [...new Set([...existing, ...hosts])]
    if (merged.length > 0) {
      process.env[key] = merged.join(',')
    }
  }
}

function disableProxyForLocalWpE2E() {
  if (process.env.WPTSALL_KEEP_PROXY === '1') return
  ensureNoProxyHosts(['blog.wpmm.cc', '127.0.0.1', 'localhost', '::1'])
  for (const key of ['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy']) {
    if (key in process.env) delete process.env[key]
  }
}

disableProxyForLocalWpE2E()

import { resolveSlotClientBase } from './lib/e2e-slot-ports'

const clientBase = resolveSlotClientBase()
const e2eSlot = process.env.E2E_SLOT || 'shared'
const reporterOutput =
  process.env.PLAYWRIGHT_JSON_OUTPUT ?? path.join(__dirname, '../reports', e2eSlot, 'playwright-results.json')

export default defineConfig({
  testDir:        '.',
  testMatch:      [
    'official-gate/**/*.gate.e2e.spec.ts',
  ],
  testIgnore:     [
    'node_modules/**',
    'test-results/**',
  ],
  timeout:        1_800_000,   // 30 min — 15 plugins × 3 relations = large task volume
  expect:         { timeout: 30_000 },
  fullyParallel:  false,       // Serial execution — tests depend on prior state
  workers:        1,           // One worker: execution must finish before verification
  retries:        0,
  reporter:       [
    ['list'],
    ['json', { outputFile: reporterOutput }],
  ],
  use: {
    baseURL:           clientBase,
    headless:          true,
    viewport:          { width: 1280, height: 800 },
    ignoreHTTPSErrors: true,
    bypassCSP:         true,
    launchOptions: {
      args: [
        '--no-proxy-server',
        '--proxy-server=direct://',
        '--proxy-bypass-list=*',
        '--host-resolver-rules=MAP blog.wpmm.cc 127.0.0.1',
      ],
    },
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
})
