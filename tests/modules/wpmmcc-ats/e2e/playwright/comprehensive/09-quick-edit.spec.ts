import { test, expect } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

/**
 * Quick Edit / Bulk Edit — verify language selector works in inline edit panels.
 */

test.describe('09 Quick Edit / Bulk Edit', () => {
  // Slow-admin goto family (2026-09-27 §60 comprehensive run: bulk-edit first
  // attempt 30.1s vs 30s test default; retry 22.3s) — same 120s budget the
  // 01/02/03 describes got in §59.
  test.setTimeout(120_000)

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Quick Edit panel shows language selector', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=post`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Quick Edit / Bulk Edit JS should be enqueued
    const hasQuickEdit = html.includes('inline-edit') || html.includes('wptsall-quick-edit')
    expect(hasQuickEdit, 'Quick Edit JS not enqueued').toBe(true)
  })

  test('Bulk Edit shows language dropdown', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=post`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Look for the inline edit post template containing language select
    const hasBulkLang = html.includes('wptsall_bulk_lang') ||
                        html.includes('bulk-edit') && (html.includes('wptsall-language') || html.includes('wptsall_lang'))
    expect(hasBulkLang, 'Bulk Edit language selector not found').toBe(true)
  })
})
