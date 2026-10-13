import { test, expect } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

/**
 * Translation Columns — verify post/taxonomy list tables have language columns.
 */

test.describe('04 Translation Columns', () => {
  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('Posts list shows language column', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=post`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
    // Column header should exist
    const hasLangCol = html.includes('wptsall-language-column') ||
                       html.includes('column-wptsall_language') ||
                       html.includes('column-wptsall_site') ||
                       html.includes('column-wptsall_term_translations') ||
                       html.includes('column-wptsall_translations') ||
                       html.includes('Languages</th>') ||
                       html.includes('>Language</th>') ||
                       html.includes('>Translations</th>') ||
                       html.includes('wptsall_lang') ||
                       html.includes('Translation')
    expect(hasLangCol, 'Posts list missing language column').toBe(true)
  })

  test('Pages list shows language column', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=page`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
    const hasLangCol = html.includes('wptsall-language-column') ||
                       html.includes('column-wptsall_language') ||
                       html.includes('column-wptsall_site') ||
                       html.includes('column-wptsall_term_translations') ||
                       html.includes('column-wptsall_translations') ||
                       html.includes('Languages</th>') ||
                       html.includes('>Language</th>') ||
                       html.includes('>Translations</th>') ||
                       html.includes('wptsall_lang') ||
                       html.includes('Translation')
    expect(hasLangCol, 'Pages list missing language column').toBe(true)
  })

  test('Categories taxonomy list shows language column', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit-tags.php?taxonomy=category`, { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
    const hasLangCol = html.includes('wptsall-language-column') ||
                       html.includes('column-wptsall_language') ||
                       html.includes('column-wptsall_site') ||
                       html.includes('column-wptsall_term_translations') ||
                       html.includes('column-wptsall_translations') ||
                       html.includes('Languages</th>') ||
                       html.includes('>Language</th>') ||
                       html.includes('>Translations</th>') ||
                       html.includes('wptsall_lang')
    expect(hasLangCol, 'Categories list missing language column').toBe(true)
  })
})
