/**
 * Live Lab: Client WebUI loads L1 WP translation providers from official server.
 */
import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { ensureClientWebUiLoggedIn } from './helpers/client-oauth-login'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const BASE = resolveSlotClientBase()
async function goToApiKeys(page: Page) {
  await page.click('nav button:has-text("API 密钥"), nav button:has-text("API Keys")')
  await page.waitForTimeout(500)
}

async function expectProvidersApiReady(request: APIRequestContext) {
  const res = await request.get(`${BASE}/api/wp-translation-providers`)
  const body = await res.json().catch(() => null)
  expect(res.ok() && body?.success === true, JSON.stringify(body?.error ?? body)).toBeTruthy()
  expect((body?.data?.items?.length ?? 0)).toBeGreaterThanOrEqual(5)
}

test('Client API Keys: WP translation providers catalog loads from server', async ({ page, request }) => {
  // L1 catalog requires admin; stale non-admin sessions pass server-search but fail here.
  await request.post(`${BASE}/api/logout`).catch(() => null)
  await ensureClientWebUiLoggedIn(page, request, {
    email: 'admin@wptsall.dev',
    password: 'demo',
  })
  await expectProvidersApiReady(request)

  await goToApiKeys(page)
  await page.click('button:has-text("WP 翻译商"), button:has-text("WP Providers")')
  await page.waitForTimeout(500)
  // Section heading for L1 catalog (tab label: "WP 翻译商 (L1)" / "WP Providers (L1)")
  await expect(
    page.locator('h3').filter({ hasText: /WP.*翻译商|WP Translation Providers/i }).first(),
  ).toBeVisible({ timeout: 15_000 })
  const section = page.locator('.bg-white.border.rounded-xl').filter({
    has: page.locator('h3').filter({ hasText: /WP.*翻译商|WP Translation Providers/i }),
  })
  await expect(section.locator('.text-red-700')).not.toBeVisible()
  const table = section.locator('table')
  await expect(table).toBeVisible({ timeout: 20_000 })
  const rows = table.locator('tbody tr').filter({ hasNotText: /暂无数据|No providers|加载|loading/i })
  await expect(rows.first()).toBeVisible({ timeout: 20_000 })
  expect(await rows.count()).toBeGreaterThanOrEqual(5)
  const refresh = section.locator('button').filter({ hasText: /刷新|Refresh/i })
  await expect(refresh).toBeVisible()
  await refresh.click()
  await page.waitForTimeout(1500)
  expect(await rows.count()).toBeGreaterThanOrEqual(5)
})
