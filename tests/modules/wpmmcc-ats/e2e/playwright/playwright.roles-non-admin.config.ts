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
]

// Roles / non-admin capability gating lane.
// Authenticated non-admin (editor) must NOT reach plugin admin pages or
// admin REST routes. Complements comprehensive/11-capability.spec.ts, which
// only covers the unauthenticated case.
export default defineConfig({
  testDir: './roles-non-admin',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: wpBase,
    headless: true,
    screenshot: 'only-on-failure',
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
