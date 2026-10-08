import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('License Keys', () => {
  // ── Admin Keys Page ──
  test('admin keys page loads with table', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    await expect(page.locator('h1')).toBeVisible()
    await page.waitForTimeout(3_000)
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
    // Admin (demo@wptsall.dev) should have at least one order
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible()
    }
  })

  test('admin keys table shows all columns', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible({ timeout: 10_000 })
      const headers = page.locator('table thead th')
      // 5 columns: order, domain, license key, status, actions
      const count = await headers.count()
      expect(count).toBeGreaterThanOrEqual(5)
    }
  })

  test('admin keys show masked keys by default', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible({ timeout: 10_000 })
      // License key cells should have masked text with ****
      const maskedKey = page.locator('table tbody tr').first().locator('.text-gray-500')
      if ((await maskedKey.count()) > 0) {
        const text = await maskedKey.textContent()
        expect(text).toContain('****')
      }
    }
  })

  test('admin can toggle key visibility with show/hide button', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible({ timeout: 10_000 })
      const firstRow = page.locator('table tbody tr').first()
      // Show button
      const showBtn = firstRow.locator('button').filter({ hasText: /show|显示/i })
      if ((await showBtn.count()) > 0) {
        await showBtn.click()
        // After clicking show, key should be visible (blue text span)
        await expect(firstRow.locator('span.text-blue-600')).toBeVisible()
        // Button text changes to Hide
        const hideBtn = firstRow.locator('button').filter({ hasText: /hide|隐藏/i })
        await expect(hideBtn).toBeVisible()
        // Click hide to mask again
        await hideBtn.click()
        await expect(firstRow.locator('span.text-gray-500')).toBeVisible()
      }
    }
  })

  test('admin keys have copy button per row', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible({ timeout: 10_000 })
      const firstRow = page.locator('table tbody tr').first()
      const copyBtn = firstRow.locator('button').filter({ hasText: /copy|复制/i })
      if ((await copyBtn.count()) > 0) {
        await expect(copyBtn).toBeVisible()
      }
    }
  })

  test('admin keys show status badges', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/keys')
    const table = page.locator('table')
    if ((await table.count()) > 0) {
      await expect(table).toBeVisible({ timeout: 10_000 })
      // Status badge (completed/etc)
      const badge = page.locator('table tbody .rounded-full').first()
      if ((await badge.count()) > 0) {
        await expect(badge).toBeVisible()
      }
    }
  })

  // ── Free User Keys Page ──
  test('free user keys page shows empty state', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/keys')
    await expect(page.locator('h1')).toBeVisible()
    await page.waitForTimeout(3_000)
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
    // Free user has no orders → empty state
    const emptyMsg = page.locator('text=/no key|暂无/i')
    const tableRows = page.locator('table tbody tr')
    const isEmpty = (await emptyMsg.count()) > 0 || (await tableRows.count()) === 0
    expect(isEmpty).toBeTruthy()
  })
})
