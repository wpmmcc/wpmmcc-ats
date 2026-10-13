import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, waitForDataLoad } from './helpers'

test.describe('WP Translation Providers (L1 admin catalog)', () => {
  test('admin page loads provider table with seeded rows', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/wp-translation-providers')
    await waitForDataLoad(page)
    await expect(page.locator('h1')).toBeVisible()
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 15_000 })
    const rows = page.locator('table tbody tr')
    await expect(rows.first()).toBeVisible()
    expect(await rows.count()).toBeGreaterThanOrEqual(5)
  })

  test('search filters provider list', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/wp-translation-providers')
    await waitForDataLoad(page)
    const search = page.locator('input[type="text"]').first()
    await expect(search).toBeVisible()
    await search.fill('baidu')
    await search.press('Enter')
    await page.waitForTimeout(800)
    const body = await page.locator('table tbody').innerText()
    expect(body.toLowerCase()).toMatch(/baidu/)
  })

  test('create provider modal opens with form fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/wp-translation-providers')
    await waitForDataLoad(page)
    await page.getByRole('button', { name: /create provider|创建.*服务商|创建.*提供商/i }).first().click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    await expect(modal.locator('input[type="text"]').first()).toBeVisible()
    await expect(modal.locator('textarea')).toBeVisible()
  })
})
