/**
 * P0-LF-07 legacy lane isolation assertions.
 *
 * Runs against the owned legacy Client (WPTSALL_USE_SERVER_CONTROL_PLANE=1,
 * explicit WPTSALL_SERVER_BASE). Asserts:
 *   - status is visibly labeled legacy_server_control_plane;
 *   - server search/refresh dispatch (not locally rejected) in this lane;
 *   - the UI shows the legacy entry + session affordances while every local
 *     nav surface stays available (legacy/paid state must never hide local
 *     features);
 *   - the Products page is visibly titled "Legacy server control plane".
 */
import { expect, test } from '@playwright/test'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const CLIENT_BASE = resolveSlotClientBase()

// P0-LF-02: this code/status marks the LOCAL dispatch gate rejection. In the
// legacy lane legacy routes must dispatch, so this code must never appear.
const LOCAL_REJECTION_CODE = 'LEGACY_CONTROL_PLANE_DISABLED'

test('legacy lane labels status and keeps local features available', async ({ page }) => {
  // 1. Legacy status labeling.
  const res = await page.request.get(`${CLIENT_BASE}/api/status`)
  expect(res.ok()).toBeTruthy()
  const body = await res.json()
  const status = body?.data ?? body
  expect(status?.runtime_mode).toBe('legacy_server_control_plane')

  // 2. Server search/refresh dispatch in the legacy lane (never the local
  //    dispatch rejection; upstream errors are acceptable against a stub).
  const refresh = await page.request
    .post(`${CLIENT_BASE}/api/components/refresh`, { data: {} })
    .catch(() => null)
  expect(refresh, 'legacy /api/components/refresh must dispatch').not.toBeNull()
  const refreshBody = await refresh!.json().catch(() => null)
  expect(refreshBody?.error?.code ?? '').not.toBe(LOCAL_REJECTION_CODE)

  const search = await page.request
    .get(`${CLIENT_BASE}/api/components/server-search?page=1&per_page=5`)
    .catch(() => null)
  expect(search, 'legacy /api/components/server-search must dispatch').not.toBeNull()
  const searchBody = await search!.json().catch(() => null)
  expect(searchBody?.error?.code ?? '').not.toBe(LOCAL_REJECTION_CODE)

  // 3. UI: legacy entry + logout visible, and every local nav surface remains.
  await page.goto(CLIENT_BASE)
  const nav = page.locator('nav')
  await expect(nav).toContainText(/旧版服务器控制面|Legacy server control plane/i)
  await expect(nav.locator('button', { hasText: /退出登录|Log ?out/i })).toHaveCount(1)
  for (const label of [/概览|Overview/i, /站点|Sites/i, /翻译组件|Components/i, /API 密钥|API Keys/i]) {
    await expect(nav.locator('button').filter({ hasText: label }).first()).toBeVisible()
  }

  // 4. Products page is visibly titled as the legacy control plane.
  await page
    .locator('nav button')
    .filter({ hasText: /旧版服务器控制面|Legacy server control plane/i })
    .click()
  await expect(page.locator('h2').first()).toContainText(
    /旧版服务器控制面|Legacy server control plane/i,
  )
})
