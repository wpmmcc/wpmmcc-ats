/**
 * 3.8flash A3+D4 (VERIFIED-REPAIR-PLAN-20260925): provider-catalog offline
 * degradation E2E.
 *
 * Coverage gap (feedback blind spot 4 / D4): the first catalog view on a
 * clean client triggers an online fetch inline — previously with a 30s
 * timeout and no UI indication, an unreachable network meant a half-minute
 * silent hang before the built-in seed finally appeared. The repair (A3)
 * bounds the fetch to 3s (download_catalog_bytes) and says so in the UI
 * (vendor_catalog.first_fetch_loading); the degradation arms (fallback to
 * the built-in seed + honest offline refresh metadata) were already in
 * place and are pinned here end-to-end.
 *
 * Lane (run-playwright-provider-catalog-offline.sh): one owned Client with
 * an isolated empty provider-catalog cache (genuine never_fetched state)
 * and the catalog source pointed at an owned 404 server (requests recorded)
 * — a genuinely broken source without touching the network.
 *
 * Contract:
 *   1. GET /api/provider-catalog serves the built-in seed FAST (bounded
 *      well under the old 30s hang) with a never_fetched/seed cache state;
 *   2. POST /api/provider-catalog/refresh degrades honestly: 200 with
 *      offline + fallback + the seed template count;
 *   3. the 404 source was actually contacted (recorded requests);
 *   4. the WebUI vendor-catalog tab (ApiKeys page, default tab) renders the
 *      seed entries — no hang, no fatal.
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'

const BASE = process.env.WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL
const LOG_404 = process.env.WPTSALL_PROVIDER_CATALOG_OFFLINE_404_LOG

test.beforeAll(() => {
  for (const [name, value] of [
    ['WPTSALL_PROVIDER_CATALOG_OFFLINE_BASE_URL', BASE],
    ['WPTSALL_PROVIDER_CATALOG_OFFLINE_404_LOG', LOG_404],
  ] as const) {
    expect(value, `${name} must be exported by the lane runner`).toBeTruthy()
  }
})

test.describe.configure({ mode: 'serial' })

test('first catalog list serves the built-in seed fast (no 30s hang)', async ({ request }) => {
  // The first list after a never_fetched start attempts the inline fetch
  // (3s-bounded against the 404 source) then falls back to the seed.
  const started = Date.now()
  const res = await request.get(`${BASE}/api/provider-catalog`)
  const elapsedMs = Date.now() - started
  expect(res.status(), 'catalog list must answer 200').toBe(200)

  const body = await res.json()
  expect(body.success, JSON.stringify(body)).toBeTruthy()
  expect(
    (body.data?.items ?? []).length,
    'the built-in seed templates must be served when the source is 404',
  ).toBeGreaterThan(0)
  expect(['never_fetched', 'seed', 'stale_current']).toContain(body.data?.cache_state)
  // A3: the old 30s timeout made this number ~30_000 on a dead source; the
  // 3s bound plus response overhead must land well under it.
  expect(elapsedMs, `catalog list must be 3s-bounded, took ${elapsedMs}ms`).toBeLessThan(10_000)
})

test('manual refresh degrades honestly (200 + offline + fallback + seed count)', async ({ request }) => {
  const res = await request.post(`${BASE}/api/provider-catalog/refresh`)
  expect(res.status(), 'refresh against a dead source must degrade, not error out').toBe(200)

  const body = await res.json()
  expect(body.success, JSON.stringify(body)).toBeTruthy()
  expect(body.data?.offline, 'refresh must report the offline state').toBe(true)
  expect(body.data?.fallback, 'refresh must report the fallback arm').toBe(true)
  expect(
    Number(body.data?.template_count ?? 0),
    'the fallback must still report the seed template count',
  ).toBeGreaterThan(0)
})

test('the 404 catalog source was actually contacted', async () => {
  const log = fs.readFileSync(LOG_404 as string, 'utf-8')
  expect(
    /GET \/catalog\.json/.test(log),
    `the owned 404 server must have recorded the catalog fetch: ${JSON.stringify(log)}`,
  ).toBe(true)
})

test('WebUI vendor-catalog tab renders the seed entries (no hang, no fatal)', async ({ page }) => {
  await page.goto(`${BASE}/`)
  // Sidebar nav: the ApiKeys page (its DEFAULT tab is the vendor catalog).
  await page.getByText('API 密钥').first().click()

  // The seed table renders (rows beyond the header/loading cells).
  const seedRows = page.locator('[data-testid="apikeys-vendors-empty"], table tbody tr:not(:has-text("首次获取目录中"))')
  await expect(seedRows.first()).toBeVisible({ timeout: 20_000 })

  // No silent-hang illusion: the loading cell (if any) either already
  // resolved or shows the A3 first-fetch hint, and the offline line renders.
  const loadingCell = page.locator('[data-testid="apikeys-vendors-loading"]')
  if (await loadingCell.count() > 0) {
    await expect(loadingCell).toContainText(/首次获取目录中|加载中/)
  }
  await expect(page.getByText(/本地目录；无需官网控制面/)).toBeVisible()
})
