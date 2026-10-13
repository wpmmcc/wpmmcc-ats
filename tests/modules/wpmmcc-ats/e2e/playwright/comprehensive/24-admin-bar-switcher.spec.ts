/**
 * W1-7: Admin-bar language switcher gold on post edit screen.
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-admin-bar
 *
 * catalog: WP-CLASS-Admin_Bar_Switcher
 * oracle: L1
 */
import { test, expect } from '@playwright/test'
import { wpLogin, WP_BASE_URL } from './helpers'

test.describe('admin-bar language switcher', () => {
  test('post editor shows wptsall-lang-switcher when admin bar is present', async ({ page }) => {
    test.setTimeout(120_000)
    await wpLogin(page)

    // Directly open a known post via REST list (avoids list-table flakiness).
    const list = await page.evaluate(async (base) => {
      const res = await fetch(`${base}/wp-json/wp/v2/posts?per_page=1&status=publish`, {
        credentials: 'same-origin',
      })
      return { status: res.status, body: await res.json() }
    }, WP_BASE_URL)
    test.skip(list.status !== 200 || !Array.isArray(list.body) || list.body.length === 0, 'No publish posts for admin-bar gold')

    const postId = Number(list.body[0].id)
    const editUrl = `${WP_BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`

    await page.goto(editUrl, { waitUntil: 'domcontentloaded', timeout: 60_000 })
    await page.waitForLoadState('domcontentloaded')

    // Block editor often keeps #wpadminbar in DOM but CSS-hidden; assert attachment.
    const adminBar = page.locator('#wpadminbar')
    await expect(adminBar).toBeAttached({ timeout: 30_000 })

    const switcher = page.locator('#wp-admin-bar-wptsall-lang-switcher')
    await expect(switcher, 'Admin-bar language switcher node must render on edit screen').toBeAttached({
      timeout: 30_000,
    })
  })
})
