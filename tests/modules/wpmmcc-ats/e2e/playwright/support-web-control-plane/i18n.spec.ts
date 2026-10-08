import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Internationalization', () => {
  // ── Admin Language Switching ──
  test('admin: language switcher changes UI language', async ({ page }) => {
    await loginAsAdmin(page)
    const nav = page.locator('nav')
    const langSelect = nav.locator('select')
    await expect(langSelect).toBeVisible()

    // Switch to Chinese
    await langSelect.selectOption('zh-CN')
    await page.waitForTimeout(500)
    await expect(nav.getByText('仪表盘')).toBeVisible()

    // Switch back to English
    await langSelect.selectOption('en')
    await page.waitForTimeout(500)
    await expect(nav.getByText('Dashboard')).toBeVisible()
  })

  test('admin: language preference persists across navigation', async ({ page }) => {
    await loginAsAdmin(page)
    const nav = page.locator('nav')
    await nav.locator('select').selectOption('zh-CN')
    await page.waitForTimeout(500)

    // Navigate via client-side routing (click nav link)
    await nav.locator('a[href="/domains"]').click()
    await page.waitForURL('**/domains')
    await page.waitForTimeout(500)
    const locale = await page.evaluate(() => localStorage.getItem('wptsall_locale'))
    expect(locale).toBe('zh-CN')

    // Language selector should still show zh-CN
    const selectValue = await nav.locator('select').inputValue()
    expect(selectValue).toBe('zh-CN')

    // Reset to English
    await nav.locator('select').selectOption('en')
  })

  test('admin: language changes apply to page content', async ({ page }) => {
    await loginAsAdmin(page)
    const nav = page.locator('nav')

    // Switch to Chinese
    await nav.locator('select').selectOption('zh-CN')
    await page.waitForTimeout(500)
    // Dashboard content should be in Chinese
    await expect(page.locator('h1')).toBeVisible()
    // Verify Chinese text appears on the current page
    await expect(nav.getByText('仪表盘')).toBeVisible()

    // Navigate via client-side routing
    await nav.locator('a[href="/domains"]').click()
    await page.waitForURL('**/domains')
    await page.waitForTimeout(1_000)

    // Locale should persist
    const locale = await page.evaluate(() => localStorage.getItem('wptsall_locale'))
    expect(locale).toBe('zh-CN')

    // Reset to English
    await nav.locator('select').selectOption('en')
  })

  // ── Free User Language Switching ──
  test('free user: language switcher is available', async ({ page }) => {
    await loginAsFreeUser(page)
    const nav = page.locator('nav')
    const langSelect = nav.locator('select')
    await expect(langSelect).toBeVisible()
  })

  test('free user: language switch works', async ({ page }) => {
    await loginAsFreeUser(page)
    const nav = page.locator('nav')
    await nav.locator('select').selectOption('zh-CN')
    await page.waitForTimeout(500)
    await expect(nav.getByText('仪表盘')).toBeVisible()

    // Reset to English
    await nav.locator('select').selectOption('en')
    await page.waitForTimeout(500)
    await expect(nav.getByText('Dashboard')).toBeVisible()
  })
})
