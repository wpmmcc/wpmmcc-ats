import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Domains', () => {
  test('admin sees seeded domains table', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/domains')
    await expect(page.locator('h1')).toBeVisible()
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    expect(await page.locator('table thead th').count()).toBeGreaterThanOrEqual(4)
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0)
  })

  test('domain rows show status and subscription badges', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/domains')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('table .rounded-full').first()).toBeVisible()
  })

  test('add domain button opens purchase modal with payment methods', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/domains')
    await page.locator('button').filter({ hasText: /add domain|添加域名/i }).click()

    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    await expect(modal.locator('input[type="text"]')).toBeVisible()
    await expect(modal.locator('input[type="radio"][value="simulated"]')).toBeVisible()
    await expect(modal.locator('input[type="radio"][value="stripe"]')).toBeVisible()
    await expect(modal.locator('button[type="submit"]')).toBeVisible()
  })

  test('purchase modal toggles submit label by payment method', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/domains')
    await page.locator('button').filter({ hasText: /add domain|添加域名/i }).click()

    const modal = page.locator('.fixed.inset-0')
    const stripeRadio = modal.locator('input[type="radio"][value="stripe"]')
    await stripeRadio.check()
    await expect(modal.locator('button[type="submit"]')).toContainText(/stripe|继续|proceed/i)
  })

  test('free user can open add-domain modal too', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/domains')
    await expect(page.locator('h1')).toBeVisible()
    await page.locator('button').filter({ hasText: /add domain|添加域名/i }).click()
    await expect(page.locator('.fixed.inset-0')).toBeVisible()
  })
})
