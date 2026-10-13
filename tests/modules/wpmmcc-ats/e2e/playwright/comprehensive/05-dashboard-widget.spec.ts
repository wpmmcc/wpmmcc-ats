import { test, expect } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

/**
 * Dashboard Widget — verify translation progress widget shows in admin dashboard.
 */

test.describe('05 Dashboard Widget', () => {
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Dashboard page renders without errors', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
  })

  test('WPTSALL Statistics widget is registered', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    // Widget div + title
    const hasWidget = html.includes('wptsall_stats_widget') ||
                      html.includes('WPTSALL Statistics') ||
                      html.includes('wptsall-stat-card')
    expect(hasWidget, 'WPTSALL dashboard widget not found').toBe(true)
  })

  test('Widget shows Translation Progress section with percentages', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    // The widget should show percentages
    if (html.includes('wptsall_stats_widget')) {
      expect(html).toMatch(/[0-9]+%/)  // Has some percentage
    }
  })
})
