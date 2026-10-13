/**
 * WP Admin form submit → options / REST round-trip (P0 UI↔API).
 *
 * - Settings: admin-post save → redirect → effective JSON + form fields
 * - Content Types: admin-post save → redirect → checkbox persistence
 * - Sites: Add Virtual Site UI → POST /wp-json/wptsall/v2/virtual-sites (+ AJV)
 *
 * Run (Lab):
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-admin-forms
 * or via comprehensive:
 *   WP_BASE=http://127.0.0.1:9083 bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 14
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
  // Use APIRequestContext so navigations (virtual_list redirect) cannot destroy the call.
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

function parseEffectiveSettings(html: string): Record<string, unknown> | null {
  // Settings page dumps effective JSON in a <pre> under "Current effective settings"
  const match = html.match(
    /Current effective settings[\s\S]*?<pre[^>]*>\s*(\{[\s\S]*?\})\s*<\/pre>/i,
  )
  if (!match) return null
  try {
    return JSON.parse(match[1]) as Record<string, unknown>
  } catch {
    return null
  }
}

async function submitAdminPostForm(page: Page, action: string, urlHint: RegExp) {
  const form = page.locator('form[action*="admin-post.php"]').filter({
    has: page.locator(`input[name="action"][value="${action}"]`),
  })
  await expect(form).toHaveCount(1)
  const submit = form.locator('button[type="submit"], input[type="submit"]').first()
  await expect(submit).toBeVisible()

  const adminPostPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-post.php') && res.request().method() === 'POST',
    { timeout: 45_000 },
  )
  await submit.click()
  const postRes = await adminPostPromise
  // After 302, wait for the landing admin page (same-page re-saves still navigate).
  try {
    await page.waitForURL(urlHint, { timeout: 45_000, waitUntil: 'domcontentloaded' })
  } catch {
    await page.waitForLoadState('domcontentloaded')
    if (!urlHint.test(page.url())) {
      throw new Error(`admin-post ${action} landed on unexpected URL: ${page.url()}`)
    }
  }
  return postRes
}

test.describe.configure({ mode: 'serial' })

test.describe('14 Admin form submit round-trip', () => {
  test.setTimeout(120_000)

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Settings: Save Settings persists via admin-post and effective JSON', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, {
      waitUntil: 'domcontentloaded',
    })
    let html = await page.content()
    expect(findFatalError(html)).toBeNull()

    const before = parseEffectiveSettings(html)
    expect(before, 'effective settings JSON on page').not.toBeNull()
    expectSchema(before, 'wp-settings-effective', 'settings before save')

    const originalRetention = Number(before!.log_retention_days ?? 7)
    const nextRetention = originalRetention === 11 ? 12 : 11

    await page.locator('#log_retention_days').fill(String(nextRetention))
    // Keep client API on — do not toggle client_api_enabled
    const clientApi = page.locator('input[name="client_api_enabled"]')
    if (await clientApi.count()) {
      await clientApi.check()
    }

    const postRes = await submitAdminPostForm(
      page,
      'wptsall_settings_save',
      /page=wptsall-settings/,
    )
    expect([200, 302, 303]).toContain(postRes.status())

    html = await page.content()
    expect(findFatalError(html)).toBeNull()

    const after = parseEffectiveSettings(html)
    expect(after, 'effective settings after save').not.toBeNull()
    expectSchema(after, 'wp-settings-effective', 'settings after save')
    expect(Number(after!.log_retention_days)).toBe(nextRetention)
    expect(await page.locator('#log_retention_days').inputValue()).toBe(String(nextRetention))

    // Restore original retention so Lab stays stable for other suites
    await page.locator('#log_retention_days').fill(String(originalRetention))
    if (await clientApi.count()) {
      await clientApi.check()
    }
    await submitAdminPostForm(page, 'wptsall_settings_save', /page=wptsall-settings/)
    const restored = parseEffectiveSettings(await page.content())
    expect(Number(restored?.log_retention_days)).toBe(originalRetention)
  })

  test('Content Types: Save persists post_types[] checkboxes', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-content-types`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    const postCb = page.locator('input[name="post_types[]"][value="post"]')
    const pageCb = page.locator('input[name="post_types[]"][value="page"]')
    await expect(postCb.or(pageCb).first()).toBeVisible({ timeout: 15_000 })

    const hadPost = (await postCb.count()) ? await postCb.isChecked() : false
    const hadPage = (await pageCb.count()) ? await pageCb.isChecked() : false

    // Ensure at least `post` is selected for a deterministic save
    if (await postCb.count()) {
      await postCb.check()
    }

    await submitAdminPostForm(page, 'wptsall_content_types_save', /page=wptsall-content-types/)

    expect(findFatalError(await page.content())).toBeNull()
    await expect(page.getByText(/^Saved\.?$/)).toBeVisible()
    expect(page.url()).toMatch(/wptsall_saved=1/)

    if (await postCb.count()) {
      await expect(postCb).toBeChecked()
    }

    // Restore prior checkbox states
    if (await postCb.count()) {
      if (hadPost) await postCb.check()
      else await postCb.uncheck()
    }
    if (await pageCb.count()) {
      if (hadPage) await pageCb.check()
      else await pageCb.uncheck()
    }
    await submitAdminPostForm(page, 'wptsall_content_types_save', /page=wptsall-content-types/)
  })

  test('Sites: Add Virtual Site UI → POST /virtual-sites (AJV) → GET verify → DELETE', async ({
    page,
  }) => {
    const suffix = Date.now().toString(36).slice(-6)
    const pathPrefix = `e2e${suffix}`
    const siteName = `E2E VS ${suffix}`

    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites&tab=add_virtual`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    // Sites page already loads wp-api; grab nonce before UI triggers a redirect.
    const nonce = await page.evaluate(() => {
      const w = window as unknown as { wpApiSettings?: { nonce?: string }; wptsallSites?: { nonce?: string } }
      return String(w?.wpApiSettings?.nonce || w?.wptsallSites?.nonce || '')
    })
    expect(nonce, 'REST nonce on Sites page').toBeTruthy()

    const form = page.locator('#wptsall-create-virtual-site-form')
    await expect(form).toBeVisible({ timeout: 20_000 })

    await page.locator('#site_name').fill(siteName)
    await page.locator('#site_path').fill(pathPrefix)

    const langSelect = page.locator('#site_language')
    await expect(langSelect).toBeAttached()
    const langValue = await langSelect.evaluate((el: HTMLSelectElement) => {
      const opts = Array.from(el.options).filter((o) => o.value)
      return opts[0]?.value || ''
    })
    expect(langValue, 'at least one language option').toBeTruthy()
    await langSelect.evaluate((el: HTMLSelectElement, value: string) => {
      el.value = value
      el.dispatchEvent(new Event('change', { bubbles: true }))
      const w = window as unknown as { jQuery?: (sel: string) => { val: (v: string) => { trigger: (e: string) => void } } }
      if (typeof w.jQuery === 'function') {
        try {
          w.jQuery('#site_language').val(value).trigger('change')
        } catch {
          /* ignore select2 glue */
        }
      }
    }, langValue)

    const pending = expectApi(page, {
      path: /\/wp-json\/wptsall\/v2\/virtual-sites\/?(\?|$)/,
      method: 'POST',
      requestSchema: 'wp-virtual-sites-create.request',
      responseSchema: 'wp-virtual-sites-create.response',
      status: [200, 201],
      timeoutMs: 60_000,
    })

    await form.locator('button[type="submit"], input[type="submit"]').first().click()
    const { responseJson, requestJson } = await pending

    const body = responseJson as { success: boolean; site_id: number }
    expect(body.success).toBe(true)
    expect(body.site_id).toBeGreaterThan(0)
    const req = requestJson as { name: string; path_prefix: string; lang: string }
    expect(req.name).toBe(siteName)
    expect(req.path_prefix).toBe(pathPrefix)
    expect(req.lang).toBe(langValue)

    // Verify + cleanup via request context (UI redirects ~1s after success).
    const got = await restJson(
      page,
      'GET',
      `${WP_BASE_URL}/wp-json/wptsall/v2/virtual-sites/${body.site_id}`,
      nonce,
    )
    expect(got.status).toBe(200)
    const site = got.body as { id?: number; name?: string; path_prefix?: string; lang?: string }
    expect(Number(site.id ?? body.site_id)).toBe(body.site_id)
    expect(String(site.path_prefix || '').replace(/^\/+|\/+$/g, '')).toBe(pathPrefix)
    expect(String(site.lang || '')).toBe(langValue)

    const del = await restJson(
      page,
      'DELETE',
      `${WP_BASE_URL}/wp-json/wptsall/v2/virtual-sites/${body.site_id}`,
      nonce,
    )
    expect([200, 204]).toContain(del.status)
  })
})
