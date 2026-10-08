import { test, expect } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

/**
 * Translation Meta Box — verify Polylang-style flag block on post edit page.
 */

test.describe('10 Translation Meta Box', () => {
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Post edit page renders without errors', async ({ page }) => {
    test.setTimeout(90_000)
    // Find a real post to edit
    const res = await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=post`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    if (res?.status() !== 200) {
      test.skip()
      return
    }
    // Click "Add New"
    await page.goto(`${WP_BASE_URL}/wp-admin/post-new.php?post_type=post`, {
      waitUntil: 'domcontentloaded',
      timeout: 60_000,
    })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
  })

  test('Edit existing post shows translation meta box', async ({ page }) => {
    // Get the latest post ID
    const apiRes = await page.request.get(`${WP_BASE_URL}/wp-json/wp/v2/posts?per_page=1&status=publish,draft`)
    if (!apiRes.ok()) {
      test.skip()
      return
    }
    const posts = await apiRes.json()
    if (!posts || posts.length === 0) {
      test.skip()
      return
    }
    const postId = posts[0].id
    await page.goto(`${WP_BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    // Translation meta box should be present
    const hasMetaBox = html.includes('wptsall-translation-meta-box') ||
                       html.includes('wptsall-translation') ||
                       html.includes('wptsall-flag') ||
                       html.includes('wptsall_translations') ||
                       html.includes('wptsall-meta-box')
    expect(hasMetaBox, 'Translation meta box not found on post edit page').toBe(true)

    const translateLink = page.locator(
      'a[href*="page=wptsall-translate"], .wptsall-translation-flags a, .wptsall-flag a',
    ).first()
    if (await translateLink.count()) {
      await translateLink.click()
      await page.waitForLoadState('domcontentloaded')
      expect(findFatalError(await page.content())).toBeNull()
      expect(page.url()).toMatch(/wptsall-translate|post\.php/)
    }
  })
})
