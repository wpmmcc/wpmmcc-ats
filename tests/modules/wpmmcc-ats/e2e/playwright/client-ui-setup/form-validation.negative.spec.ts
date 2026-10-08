/**
 * Negative Client UI form validation (no WP required for empty/scope_key).
 * G1 negatives: bad token / bad route_secret → Test connection shows API error.
 */
import { expect, test } from '@playwright/test'
import { CLIENT_BASE, gotoNav, loadSiteFixture } from './helpers'

test.describe('Client UI form validation (negative)', () => {
  test.beforeEach(async () => {
    const status = await fetch(`${CLIENT_BASE}/api/status`, { signal: AbortSignal.timeout(5000) })
    expect(status.ok, 'client status').toBeTruthy()
  })

  test('Sites: empty URL/token shows validation toast and does not call upsert', async ({
    page,
  }) => {
    await gotoNav(page, /Sites|站点/)
    await expect(page.getByTestId('sites-add-site')).toBeVisible({ timeout: 20_000 })
    await page.getByTestId('sites-add-site').click()
    await expect(page.getByTestId('sites-modal-save')).toBeVisible()

    let upsertSeen = false
    page.on('request', (req) => {
      if (req.url().includes('/api/domain-tokens/upsert')) upsertSeen = true
    })

    await page.getByTestId('sites-modal-save').click()
    await expect(
      page.getByText(/请填写域名和 Token|fill domain|domain and token|必填/i).first(),
    ).toBeVisible({ timeout: 10_000 })
    await page.waitForTimeout(500)
    expect(upsertSeen, 'empty Sites save must not POST upsert').toBe(false)
  })

  test('Rule bind: relation scope without numeric scope_key blocks upsert', async ({ page }) => {
    await gotoNav(page, /Components|翻译组件|组件/)
    await page.getByTestId('components-tab-tasktype').click()
    await expect(page.getByTestId('rule-bind-save')).toBeVisible({ timeout: 15_000 })

    await page.getByTestId('rule-bind-scope').selectOption('relation')
    const scopeKey = page.getByTestId('rule-bind-scope-key')
    if (await scopeKey.count()) {
      await scopeKey.fill('not-a-number')
    }
    await page.getByTestId('rule-bind-slot-key').selectOption('plain_text')
    await page.getByTestId('rule-bind-component-id').fill('negative-component-id')

    let upsertSeen = false
    page.on('request', (req) => {
      if (req.url().includes('/api/rule-component-bindings/upsert')) upsertSeen = true
    })

    await page.getByTestId('rule-bind-save').click()
    await expect(
      page
        .getByText(/正整数|scope_key|必须填写|must be|invalid|relation\/rule/i)
        .first(),
    ).toBeVisible({ timeout: 10_000 })
    await page.waitForTimeout(500)
    expect(upsertSeen, 'invalid scope_key must not POST rule upsert').toBe(false)
  })

  test('G1: bad route_secret / token → Test connection fails with error toast', async ({
    page,
  }) => {
    test.setTimeout(90_000)
    await gotoNav(page, /Sites|站点/)
    await expect(page.getByTestId('sites-add-site')).toBeVisible({ timeout: 20_000 })
    await page.getByTestId('sites-add-site').click()

    const badUrl = 'http://127.0.0.1:9083'
    await page.getByTestId('sites-modal-url').fill(badUrl)
    await page.getByTestId('sites-modal-token').fill('bad-token-e2e-negative')
    await page.getByTestId('sites-modal-route-secret').fill('INVALID-ROUTE-SECRET-E2E')

    await page.getByTestId('sites-modal-save').click()

    const row = page.locator('tr').filter({ hasText: /9083/ }).first()
    await expect(row.getByTestId('sites-test-connection')).toBeVisible({ timeout: 20_000 })

    const testResp = page.waitForResponse(
      (r) => r.url().includes('/api/domain-tokens/test') && r.request().method() === 'POST',
      { timeout: 30_000 },
    )
    await row.getByTestId('sites-test-connection').click()
    const res = await testResp
    const body = await res.json().catch(() => null as unknown)
    expect(
      (body as { success?: boolean } | null)?.success === false || res.status() >= 400,
      `expected test failure, got ${JSON.stringify(body)}`,
    ).toBeTruthy()
    await expect(
      page.getByText(/fail|error|route|token|连接|失败|unavailable|No route/i).first(),
    ).toBeVisible({ timeout: 15_000 })

    try {
      const site = loadSiteFixture()
      await page.getByTestId('sites-add-site').click()
      await page.getByTestId('sites-modal-url').fill(site.api_base_url)
      await page.getByTestId('sites-modal-token').fill(site.wp_client_token)
      await page.getByTestId('sites-modal-route-secret').fill(site.route_secret)
      await page.getByTestId('sites-modal-save').click()
      await page.waitForTimeout(800)
    } catch {
      // fixture optional for this negative
    }
  })
})
