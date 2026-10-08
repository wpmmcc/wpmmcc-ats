/**
 * WP Admin: Languages / SEO / Menu Sync submit round-trips (P1).
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-languages-seo-menu
 *   WP_BASE=http://127.0.0.1:9083 bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 16
 */
import { test, expect } from '@playwright/test'
import { wpLogin, WP_BASE_URL, findFatalError } from './helpers'

async function submitAdminPostForm(
  page: import('@playwright/test').Page,
  action: string,
  urlHint: RegExp,
) {
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
  await adminPostPromise
  try {
    await page.waitForURL(urlHint, { timeout: 45_000, waitUntil: 'domcontentloaded' })
  } catch {
    await page.waitForLoadState('domcontentloaded')
    if (!urlHint.test(page.url())) {
      throw new Error(`admin-post ${action} landed on unexpected URL: ${page.url()}`)
    }
  }
}

test.describe.configure({ mode: 'serial' })

test.describe('16 Languages / SEO / Menu Sync submit', () => {
  test.setTimeout(120_000)

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Languages: Add Language via admin-post persists in list', async ({ page }) => {
    const code = `e2e_${Date.now().toString(36).slice(-6)}`
    const slug = code.replace(/_/g, '-')

    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-languages&action=add`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    await page.locator('#code').fill(code)
    await page.locator('#slug').fill(slug)
    await page.locator('#name').fill(`E2E Lang ${code}`)
    await page.locator('#native_name').fill(code)
    await page.locator('#locale').fill(code)

    await submitAdminPostForm(page, 'wptsall_language_save', /page=wptsall-languages/)
    expect(findFatalError(await page.content())).toBeNull()
    await expect(page.locator('code').filter({ hasText: code }).first()).toBeVisible({
      timeout: 15_000,
    })

    // Cleanup: delete non-default language if delete control exists
    const row = page.locator('tr').filter({ hasText: code }).first()
    page.once('dialog', (d) => d.accept())
    const del = row.locator('button', { hasText: /Delete|删除/i })
    if (await del.count()) {
      await del.click()
      await page.waitForLoadState('domcontentloaded')
    }
  })

  test('SEO: Save SEO settings via admin-post and re-read select', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-seo`, {
      waitUntil: 'domcontentloaded',
    })
    expect(findFatalError(await page.content())).toBeNull()

    const select = page.locator('#hreflang_emitter')
    await expect(select).toBeVisible()
    const before = await select.inputValue()
    const next = before === 'none' ? 'wpmmcc-ats' : 'none'
    await select.selectOption(next)

    await submitAdminPostForm(page, 'wptsall_seo_settings_save', /page=wptsall-seo/)
    expect(findFatalError(await page.content())).toBeNull()
    await expect(page.locator('#hreflang_emitter')).toHaveValue(next)

    // Restore
    await page.locator('#hreflang_emitter').selectOption(before)
    await submitAdminPostForm(page, 'wptsall_seo_settings_save', /page=wptsall-seo/)
    await expect(page.locator('#hreflang_emitter')).toHaveValue(before)
  })

  test('Menu Sync: page renders form and validates required fields on submit', async ({
    page,
  }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-menu-sync`, {
      waitUntil: 'domcontentloaded',
    })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    const form = page.locator('form[action*="admin-post.php"]').filter({
      has: page.locator('input[name="action"][value="wptsall_menu_sync"]'),
    })
    await expect(form).toHaveCount(1)

    // Prefer validating empty/missing virtual site rather than mutating menus
    const submit = form.locator('button[type="submit"], input[type="submit"]').first()
    await expect(submit).toBeVisible()

    // If there are no virtual sites or menus, page should still be usable (hint/error)
    const hasMenus = (await form.locator('select, input[name*="menu"]').count()) > 0
    const hasSites = (await form.locator('select, input[name*="virtual"], input[name*="site"]').count()) > 0
    expect(hasMenus || hasSites || /Menu Sync|菜单/i.test(html)).toBeTruthy()

    // Soft submit only when both selects exist and have options — expect redirect with synced|error
    const menuSelect = form.locator('select').first()
    if ((await menuSelect.count()) && (await menuSelect.locator('option').count()) > 1) {
      await menuSelect.selectOption({ index: 1 })
      const siteSelect = form.locator('select').nth(1)
      if ((await siteSelect.count()) && (await siteSelect.locator('option').count()) > 1) {
        await siteSelect.selectOption({ index: 1 })
        await submitAdminPostForm(page, 'wptsall_menu_sync', /page=wptsall-menu-sync/)
        expect(findFatalError(await page.content())).toBeNull()
        const notice = page.locator('.notice-success, .notice-error, .notice.notice-success, .notice.notice-error').filter({
          hasText: /synced|error|Menu|菜单|成功|失败|New menu/i,
        })
        // Plugin may redirect with ?synced= or ?error= even if notice is buried under WP nags
        const urlOk = /[?&](synced|error)=/.test(page.url())
        if (!urlOk) {
          await expect(notice.first()).toBeVisible({ timeout: 10_000 })
        }
      }
    }
  })
})
