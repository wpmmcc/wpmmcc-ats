/**
 * WP Models / Templates UI → REST (P1 UI↔API).
 *
 * - Models: GET /models schema + row Rescan → POST /models/{id}/rescan
 * - Templates: relation filter → Scan → POST /templates/scan
 * - Templates: Rescan row → POST /templates/{id}/rescan
 *
 * Note: "Scan All" is intentionally not E2E'd (Lab full-plugin scan can exceed 4m).
 *
 * Lab:
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-models-templates
 */
import { test, expect, type Page } from '@playwright/test'
import { wpLogin, WP_BASE_URL, findFatalError } from './helpers'
import { expectApi, expectSchema } from '../lib/expect-api'

async function restJson(
  page: Page,
  method: string,
  url: string,
  nonce: string,
): Promise<{ status: number; body: unknown }> {
  const res = await page.request.fetch(url, {
    method,
    headers: { 'X-WP-Nonce': nonce },
  })
  let body: unknown = null
  try {
    body = await res.json()
  } catch {
    body = await res.text().catch(() => null)
  }
  return { status: res.status(), body }
}

async function pageRestNonce(page: Page): Promise<string> {
  return page.evaluate(() => {
    const w = window as unknown as { wpApiSettings?: { nonce?: string } }
    return String(w?.wpApiSettings?.nonce || '')
  })
}

/** Models/Templates admin JS binds after options fetch — wait for that. */
async function waitForAdminRestReady(page: Page) {
  await page
    .waitForResponse(
      (r) =>
        r.url().includes('/wp-json/wptsall/v2/') &&
        (r.url().includes('/options') || r.url().includes('/models')) &&
        r.status() < 500,
      { timeout: 30_000 },
    )
    .catch(() => undefined)
  await page.waitForTimeout(400)
}

test.describe.configure({ mode: 'serial' })

test.describe('15 Models / Templates UI→REST', () => {
  test.setTimeout(240_000)

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Models: GET /models schema + Scan New Plugin preview → POST /models/scan', async ({ page }) => {
    // Nested Model Management tab is `tab=templates` (Plugin Templates).
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wpmmcc-ats&tab=templates`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()
    await waitForAdminRestReady(page)

    const nonce = await pageRestNonce(page)
    expect(nonce, 'REST nonce on Models tab').toBeTruthy()

    const list = await restJson(page, 'GET', `${WP_BASE_URL}/wp-json/wptsall/v2/models?per_page=5`, nonce)
    expect(list.status).toBe(200)
    expectSchema(list.body, 'wp-models-list.response', 'GET /models')

    // Prefer a real installed plugin from the Scan modal (list page-1 often has lab stubs).
    await page.locator('#wptsall-scan-plugin-btn').click()
    const modal = page.locator('#wptsall-scan-modal')
    await expect(modal).toBeVisible({ timeout: 10_000 })

    const pluginSelect = page.locator('#scan-plugin')
    const pluginSlug = await pluginSelect.evaluate((el: HTMLSelectElement) => {
      const preferred = ['wordpress-blog', 'woocommerce', 'elementor', 'easy-digital-downloads']
      const values = Array.from(el.options).map((o) => o.value).filter((v) => v && v !== '__custom__')
      for (const p of preferred) {
        if (values.includes(p)) return p
      }
      return values[0] || ''
    })
    expect(pluginSlug, 'scan-plugin has an installed plugin').toBeTruthy()
    await pluginSelect.selectOption(pluginSlug)

    page.on('dialog', async (dialog) => {
      await dialog.accept()
    })

    const pending = expectApi(page, {
      path: /\/wp-json\/wptsall\/v2\/models\/scan\/?(\?|$)/,
      method: 'POST',
      requestSchema: 'wp-models-scan.request',
      responseSchema: 'wp-models-scan.response',
      status: [200, 201],
      timeoutMs: 120_000,
    })

    await page.locator('#scan-preview-btn').click()
    const { responseJson, requestJson } = await pending
    const req = requestJson as { plugin_slug?: string; save?: boolean }
    expect(req.plugin_slug).toBe(pluginSlug)
    expect(req.save === false || req.save === 0 || req.save === 'false' || req.save == null).toBeTruthy()
    expect(responseJson).toBeTruthy()
    await expect(page.locator('#scan-preview')).toBeVisible({ timeout: 15_000 })
  })

  test('Templates: Scan Language Packs UI → POST /templates/scan', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-templates`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    const relationSelect = page.locator('#filter-relation, select[name="relation_id"]')
    await expect(relationSelect).toBeVisible({ timeout: 20_000 })
    const relationId = await relationSelect.evaluate((el: HTMLSelectElement) => {
      const opts = Array.from(el.options).filter((o) => o.value && o.value !== '0')
      return opts[0]?.value || ''
    })
    expect(relationId, 'lab has at least one site relation').toBeTruthy()

    await page.goto(
      `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-templates&relation_id=${encodeURIComponent(relationId)}`,
      { waitUntil: 'domcontentloaded' },
    )
    expect(findFatalError(await page.content())).toBeNull()
    await waitForAdminRestReady(page)

    const scanBtn = page.locator('.wptsall-scan-btn').first()
    await expect(scanBtn).toBeVisible({ timeout: 20_000 })

    const pending = expectApi(page, {
      path: /\/wp-json\/wptsall\/v2\/templates\/scan\/?(\?|$)/,
      method: 'POST',
      requestSchema: 'wp-templates-scan.request',
      responseSchema: 'wp-templates-scan.response',
      status: [200, 201],
      timeoutMs: 180_000,
    })

    await scanBtn.click()
    const { responseJson, requestJson } = await pending
    const req = requestJson as { relation_id?: number | string }
    expect(String(req.relation_id)).toBe(String(relationId))
    const body = responseJson as { success: boolean }
    expect(body.success).toBe(true)
  })

  test('Templates: Rescan row → POST /templates/{id}/rescan', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-templates`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()
    await waitForAdminRestReady(page)

    const rescanBtn = page.locator('.wptsall-rescan-btn').first()
    const count = await rescanBtn.count()
    test.skip(count === 0, 'no language-pack rows to rescan on this Lab')

    const templateId = await rescanBtn.getAttribute('data-id')
    expect(templateId).toBeTruthy()

    page.on('dialog', async (dialog) => {
      await dialog.accept()
    })

    const pending = expectApi(page, {
      path: new RegExp(`/wp-json/wptsall/v2/templates/${templateId}/rescan/?(\\?|$)`),
      method: 'POST',
      // Lab language-pack fixtures may not have on-disk files; accept structured
      // failure so this still proves UI → REST wiring (avoid filtering out 4xx/5xx
      // and hanging on waitForResponse until timeout).
      status: [200, 201, 422, 500],
      timeoutMs: 60_000,
      validateRequestBody: false,
    })

    await rescanBtn.click()
    const { responseJson, status } = await pending
    if (status === 200 || status === 201) {
      const body = responseJson as { success: boolean; new: number; updated: number }
      expect(body.success).toBe(true)
      expect(typeof body.new).toBe('number')
      expect(typeof body.updated).toBe('number')
    } else {
      const err = responseJson as { code?: string; message?: string }
      expect(err.code).toBe('rescan_failed')
      expect(String(err.message || '').length).toBeGreaterThan(0)
    }
  })
})
