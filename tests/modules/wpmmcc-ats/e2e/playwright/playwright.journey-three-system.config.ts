import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './journey-three-system',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    // B 档净室 (2026-09-24): the web leg targets the lane server's STATIC_DIR
    // SPA at :8787 (WPTSALL_WEB_BASE in the runner). The old vite webServer
    // leg at :5173 was an unowned dev server — outside the lane lifecycle —
    // and is retired; helpers.ts builds absolute WEB_BASE URLs anyway.
    baseURL: process.env.WPTSALL_WEB_BASE ?? 'http://127.0.0.1:8787',
    headless: true,
    ignoreHTTPSErrors: true,
    bypassCSP: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    launchOptions: {
      args: [
        '--no-proxy-server',
        '--proxy-server=direct://',
        '--proxy-bypass-list=*',
        '--host-resolver-rules=MAP blog.wpmm.cc 127.0.0.1',
      ],
    },
  },
})
