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
const launchArgs = [
  '--no-proxy-server',
  '--proxy-server=direct://',
  '--proxy-bypass-list=*',
  '--host-resolver-rules=MAP blog.wpmm.cc 127.0.0.1',
  '--disable-save-password-bubble',
  '--disable-features=PasswordManagerOnboarding,PasswordManager,AutofillServerCommunication',
]

// Parallelism: tests are read-only (page renders only; PHP fixtures are
// created beforehand by the PHP matrix step), so workers > 1 is safe.
// Per-project describes keep serial order inside one project; projects run
// across workers. Each worker writes its own report fragment
// (<report>.w<workerIndex>); scripts/merge-playwright-worker-reports.sh
// merges them back into the single report the shell wrapper consumes.
// Default remains 1 worker (byte-for-byte legacy behaviour) unless
// PW_WORKERS is set.
const workerCount = (() => {
  const raw = process.env.PW_WORKERS
  if (!raw) return 1
  const parsed = Number.parseInt(raw, 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 1
})()

export default defineConfig({
  testDir: './manual-content-plugin-matrix',
  timeout: 180_000,
  expect: { timeout: 10_000 },
  retries: 1,
  workers: workerCount,
  // fullyParallel is required for tests inside one file to be distributed
  // across workers; per-project test.describe.configure({ mode: 'serial' })
  // blocks in the spec keep each project's three tests on one worker in
  // legacy order. With the default single worker this flag changes nothing.
  fullyParallel: workerCount > 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: wpBase,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    bypassCSP: true,
    launchOptions: { args: launchArgs },
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: { args: launchArgs },
      },
    },
  ],
})
