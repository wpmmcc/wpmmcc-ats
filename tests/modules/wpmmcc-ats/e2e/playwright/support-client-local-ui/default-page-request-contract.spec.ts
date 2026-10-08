/**
 * P0-LF-06 default-page request contract.
 *
 * Records every browser request from navigation through at least one
 * status-poll interval and asserts the default local UI:
 *   - talks only to the owned Client origin (never the canary, never 8787);
 *   - calls only the explicitly enumerated local APIs the overview needs;
 *   - never touches account/platform/server-search/install/refresh/logout
 *     routes removed from the default UI (P0-LF-02/LF-04/LF-05);
 *   - leaves the control-plane recording canary at zero requests;
 *   - has no unhandled 404/401 from removed account/platform routes;
 *   - ignores the stale session token planted in the isolated state root.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const BASE = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL
const STATE_ROOT = process.env.WPTSALL_LANE_STATE_ROOT
const CANARY_URL = process.env.WPTSALL_LANE_CANARY_URL
const ARTIFACTS_DIR = process.env.WPTSALL_LANE_ARTIFACTS_DIR

test.beforeAll(() => {
  for (const [name, value] of [
    ['WPTSALL_CLIENT_LOCAL_UI_BASE_URL', BASE],
    ['WPTSALL_LANE_STATE_ROOT', STATE_ROOT],
    ['WPTSALL_LANE_CANARY_URL', CANARY_URL],
    ['WPTSALL_LANE_ARTIFACTS_DIR', ARTIFACTS_DIR],
  ] as const) {
    expect(value, `${name} must be exported by the lane runner`).toBeTruthy()
  }
})

// Local APIs the default overview legitimately needs (P0-LF-06 contract).
const ALLOWED_API_PATHS = new Set([
  '/api/status',
  '/api/stats/overview',
  '/api/worker/start-check',
  '/api/worker/config',
  // Pending-review count card (59e3141 overview surface). Added after the
  // lane had been failing since that commit — the card fires this call on
  // every default-page load (`?count_only=1`, compared by pathname here).
  '/api/items/pending-review',
])

// Routes removed from the default UI or reserved for the legacy lane.
const FORBIDDEN_PATH_PATTERNS: RegExp[] = [
  /\/api\/v1\/account\//,
  /\/api\/v1\/platform\//,
  /\/api\/platform\//,
  /\/api\/components\/server-search/,
  /\/api\/components\/refresh/,
  /\/api\/components\/local\/install-from-server/,
  /\/api\/components\/local\/snapshot/i,
  /\/api\/domains\/refresh/,
  /\/api\/logout/,
  /\/api\/vendors/,
  /\/api\/wp-translation-providers/,
  /\/api\/cloud-api-types/,
]

test('default page makes only allowed local requests and zero control-plane calls', async ({ page }) => {
  const requests: Array<{ url: string; method: string }> = []
  const badResponses: Array<{ url: string; status: number }> = []

  page.on('request', (r) => {
    requests.push({ url: r.url(), method: r.method() })
  })
  page.on('response', (r) => {
    if (r.status() === 404 || r.status() === 401) {
      badResponses.push({ url: r.url(), status: r.status() })
    }
  })

  await page.goto(`${BASE}/`)
  // App.startPolling(10000): wait through at least one full poll interval.
  await page.waitForTimeout(11_500)

  const origin = new URL(BASE as string).origin
  const canaryOrigin = new URL(CANARY_URL as string).origin

  // 1. Same-origin only: the canary/control-plane origin must never be contacted.
  const foreign = requests.filter((r) => {
    try {
      return new URL(r.url).origin !== origin
    } catch {
      return true
    }
  })
  expect(
    foreign.map((r) => r.url),
    'browser must only talk to the owned Client origin',
  ).toEqual([])

  // 2. No forbidden routes (account/platform/server-search/install/refresh/logout).
  const forbidden = requests.filter((r) =>
    FORBIDDEN_PATH_PATTERNS.some((p) => p.test(r.url)),
  )
  expect(forbidden.map((r) => r.url), 'forbidden routes must not be requested').toEqual([])

  // 3. Enumerated local API allowlist for the default page.
  const apiCalls = requests.filter((r) => {
    try {
      return new URL(r.url).origin === origin && new URL(r.url).pathname.startsWith('/api/')
    } catch {
      return true
    }
  })
  const unexpected = apiCalls.filter((r) => {
    const pathname = new URL(r.url).pathname
    return !ALLOWED_API_PATHS.has(pathname)
  })
  expect(unexpected.map((r) => r.url), 'unexpected local API calls on the default page').toEqual([])

  // 4. The status poll must actually be running (initial + interval fire).
  const statusCalls = apiCalls.filter((r) => new URL(r.url).pathname === '/api/status')
  expect(statusCalls.length).toBeGreaterThanOrEqual(2)

  // 5. Console hygiene: no unhandled 404/401 from removed account/platform routes.
  const removedRouteFailures = badResponses.filter((r) =>
    FORBIDDEN_PATH_PATTERNS.some((p) => p.test(r.url)),
  )
  expect(removedRouteFailures.map((r) => `${r.status} ${r.url}`)).toEqual([])

  // 6. The control-plane canary received zero requests.
  const canaryLog = path.join(STATE_ROOT as string, 'canary', 'requests.log')
  const canaryCount = fs.existsSync(canaryLog)
    ? fs.readFileSync(canaryLog, 'utf8').split('\n').filter((l) => l.trim()).length
    : 0
  expect(canaryCount, 'control-plane canary request count must be 0').toBe(0)

  // 7. A stale session token in the isolated state is ignored in local mode.
  const res = await page.request.get(`${BASE}/api/status`)
  expect(res.ok()).toBeTruthy()
  const body = await res.json()
  const status = body?.data ?? body
  expect(status?.runtime_mode).toBe('local')
  expect(status?.logged_in).toBeFalsy()
  expect(status?.session_token_prefix ?? null).toBeNull()

  // Default UI must not render session affordances (P0-LF-04).
  await expect(page.locator('nav button', { hasText: /退出登录|Log ?out/i })).toHaveCount(0)
  await expect(page.locator('nav')).not.toContainText(/旧版服务器控制面|Legacy server control plane/i)

  // 8. Record the request list for the lane report.
  fs.mkdirSync(ARTIFACTS_DIR as string, { recursive: true })
  fs.writeFileSync(
    path.join(ARTIFACTS_DIR as string, 'recorded-requests.json'),
    JSON.stringify(
      {
        base: BASE,
        canary_url: CANARY_URL,
        canary_count: canaryCount,
        total_requests: requests.length,
        requests,
      },
      null,
      2,
    ),
  )
})
