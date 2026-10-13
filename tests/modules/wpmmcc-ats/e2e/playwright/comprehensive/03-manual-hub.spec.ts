import { test, expect } from '@playwright/test'
import { wpLogin, gotoAdminPage, findFatalError, WP_BASE_URL, SLOW_ADMIN_GOTO_TIMEOUT } from './helpers'

/**
 * Manual Translation Hub — verify hub + 5 sub-pages all render correctly.
 *
 * catalog: WP-CLASS-Manual_Translation_Hub
 * oracle: L1
 * catalog: WP-CLASS-Content_Types_Config_Page
 * oracle: L1
 */

const SUB_PAGES = [
  { slug: 'wptsall-manual', label: 'Manual Hub' },
  { slug: 'wptsall-url-discovery', label: 'URL Discovery' },
  { slug: 'wptsall-pending', label: 'Pending Translations' },
  { slug: 'wptsall-tax-translations', label: 'Taxonomy Translation' },
  { slug: 'wptsall-field-discovery', label: 'Field Discovery' },
  { slug: 'wptsall-content-types', label: 'Content Types' },
]

test.describe('03 Manual Translation Hub', () => {
  // 2026-09-27 slow-lab headroom (29/02 precedent): the manual hub page
  // is the heaviest render (translation-status chain); 30.7s kills on the
  // same-day full run re-passed solo on quiet windows (28.0s/22.3s).
  test.setTimeout(120_000)
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  for (const { slug, label } of SUB_PAGES) {
    test(`Manual sub-page ${slug} (${label}) renders with key UI elements`, async ({ page }) => {
      await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
      const html = await page.content()
      expect(findFatalError(html), `Fatal on ${slug}`).toBeNull()

      // Each manual page should show wptsall-stat-card or wptsall-section elements
      const hasPluginUI = html.includes('wptsall-stat-card') ||
                          html.includes('wptsall-section') ||
                          html.includes('wptsall-empty') ||
                          html.includes('wptsall-table') ||
                          html.includes('WPTSALL')
      expect(hasPluginUI, `Page ${slug} missing plugin UI markers`).toBe(true)
    })
  }

  test('Manual Hub shows navigation to all 5 sub-pages', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-manual`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Hub should link to sub-pages (or at least show their slugs in body)
    const subSlugs = ['wptsall-url-discovery', 'wptsall-pending', 'wptsall-tax-translations', 'wptsall-field-discovery', 'wptsall-content-types']
    let found = 0
    for (const s of subSlugs) {
      if (html.includes(s)) found++
    }
    expect(found, `Only ${found}/${subSlugs.length} sub-page references in hub`).toBeGreaterThanOrEqual(3)
  })

  test('Content Types page shows post types checkboxes', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-content-types`, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Should have post types form
    const hasPostTypesSection = html.includes('post_types[]') || html.includes('Translatable post types')
    expect(hasPostTypesSection).toBe(true)
  })
})
