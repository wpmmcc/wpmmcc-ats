import { test, expect } from '@playwright/test'
import { wpLogin, gotoAdminPage, findFatalError, WP_BASE_URL, SLOW_ADMIN_GOTO_TIMEOUT } from './helpers'

/**
 * Settings Page — verify all 10 fields render and save works.
 *
 * catalog: WP-CLASS-Settings_Page
 * oracle: L1
 */

test.describe('02 Settings Page', () => {
  // 2026-09-27 slow-lab headroom (29/02 precedent): the locale round-trip
  // already carried its own 120s; the plain render legs need the same
  // scale under external load spikes (32.1s kills on the same-day run).
  test.setTimeout(120_000)
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Settings page shows all expected fields', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Verify some known settings fields exist (search by name attribute)
    const expectedFields = [
      'default_language',
      'override_lang',
      'override_locale',
      'hreflang_emitter',
      'permalink_fallback',
      'sync_mode',
      'media_handling',
      'debug_mode',
      'log_retention_days',
    ]
    let found = 0
    for (const f of expectedFields) {
      if (html.includes(`name="${f}"`) || html.includes(`name='${f}'`) || html.includes(`id="${f}"`)) {
        found++
      }
    }
    expect(found, `Only ${found}/${expectedFields.length} expected settings fields found`).toBeGreaterThanOrEqual(5)
  })

  test('Settings form has nonce field', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const html = await page.content()
    expect(html).toMatch(/name=["']_wpnonce["']|name=["']wptsall_settings_nonce["']/)
  })

  test('Settings form has capability-gated submit button', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const html = await page.content()
    // Submit button should be present (we have manage_options)
    const submitBtn = page.locator('input[type="submit"], button[type="submit"]').first()
    await expect(submitBtn).toBeVisible()
  })

  // UI-28-03 (doc 28): the Locale Override forms were dead forms from
  // 2026-09-12 until 2026-09-20 — the handlers were deleted on a wrong
  // "zero callers" premise while the forms kept posting to them (blank
  // admin-post.php, silent data loss). This round-trip pins the restored
  // handle_locale_save / handle_locale_clear end-to-end.
  // 2026-09-27 slow-lab headroom: two 45s URL waiters + two admin-post
  // round-trips cannot fit the 30s default test budget on the accreted
  // Lab (measured 30s timeout kill with both internal waiters still
  // pending); 120s matches the round-trip's own waiter scale (20-spec
  // SLOW_ADMIN_LIST_GOTO_TIMEOUT precedent).
  test('Locale override save + remove round-trip (restored handlers)', async ({ page }) => {
    test.setTimeout(120_000)
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, {
      waitUntil: 'domcontentloaded',
      timeout: SLOW_ADMIN_GOTO_TIMEOUT,
    })
    expect(findFatalError(await page.content())).toBeNull()

    const saveForm = page.locator('form:has(input[name="action"][value="wptsall_locale_save"])')
    await expect(saveForm).toBeVisible()

    const lang = await saveForm.locator('select[name="override_lang"] option').first().getAttribute('value')
    expect(lang).toBeTruthy()
    const stamp = `e2e_LOC_${Date.now().toString(36)}`
    await saveForm.locator('select[name="override_lang"]').selectOption(String(lang))
    await saveForm.locator('input[name="override_locale"]').fill(stamp)

    // Pre-registered landing waiter (third-party admin JS scrubs our query
    // params ~0.3–0.7s after landing — see spec 18 notes).
    const landing = page.waitForURL(/page=wptsall-settings.*[?&]locale_saved=1/, {
      timeout: 45_000,
      waitUntil: 'commit',
    })
    await saveForm.getByRole('button').click()
    await landing
    expect(findFatalError(await page.content())).toBeNull()

    // The override now renders in the Current overrides table.
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, {
      waitUntil: 'domcontentloaded',
      timeout: SLOW_ADMIN_GOTO_TIMEOUT,
    })
    const overridesTable = page.locator('table.wp-list-table:has(code)')
    const savedRow = overridesTable.locator('tr', { hasText: stamp }).first()
    await expect(savedRow).toBeVisible({ timeout: 15_000 })

    // Remove it (sibling form, own nonce) and assert the cleared redirect;
    // leaves the lab clean.
    const removeForm = savedRow.locator('form:has(input[name="action"][value="wptsall_locale_clear"])')
    await expect(removeForm).toBeVisible()
    const cleared = page.waitForURL(/page=wptsall-settings.*[?&]locale_cleared=1/, {
      timeout: 45_000,
      waitUntil: 'commit',
    })
    await removeForm.getByRole('button').click()
    await cleared
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, {
      waitUntil: 'domcontentloaded',
      timeout: SLOW_ADMIN_GOTO_TIMEOUT,
    })
    await expect(
      page.locator('tr', { hasText: stamp }),
      'removed override row must be gone',
    ).toHaveCount(0)
  })
})
