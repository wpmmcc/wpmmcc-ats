import { defineConfig, devices } from '@playwright/test'

function ensureNoProxyHosts(hosts: string[]) {
  for (const key of ['NO_PROXY', 'no_proxy']) {
    const existing = (process.env[key] || '')
      .split(',')
      .map((item) => item.trim())
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

const wpBase = process.env.WP_BASE || 'http://127.0.0.1:9083'
const e2eSlot = (process.env.E2E_SLOT || 'shared').replace(/[^a-zA-Z0-9_-]/g, '_')
const outputDir = process.env.PLAYWRIGHT_OUTPUT_DIR
  || `test-results/plugin-journeys-${e2eSlot}`

const launchArgs = [
  '--no-proxy-server',
  '--proxy-server=direct://',
  '--proxy-bypass-list=*',
  '--host-resolver-rules=MAP blog.wpmm.cc 127.0.0.1',
]

export default defineConfig({
  testDir: './plugin-content-journeys',
  outputDir,
  timeout: 120_000,
  retries: 0,
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never', outputFolder: `playwright-report/plugin-journeys-${e2eSlot}` }]],
  use: {
    baseURL: wpBase,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    bypassCSP: true,
    launchOptions: {
      args: launchArgs,
    },
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: launchArgs,
        },
      },
    },
  ],
})
