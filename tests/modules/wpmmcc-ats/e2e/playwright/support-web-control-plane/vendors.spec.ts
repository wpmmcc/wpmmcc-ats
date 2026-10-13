import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Vendors', () => {
  // ── Admin Vendor List ──
  test('vendors page loads with table', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('h1')).toBeVisible()
    const table = page.locator('table')
    await expect(table).toBeVisible({ timeout: 10_000 })
  })

  test('vendor table shows all columns', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    const headers = page.locator('table thead th')
    const count = await headers.count()
    // 7 columns: id, name, owner, website, templates_count, created, actions
    expect(count).toBeGreaterThanOrEqual(7)
  })

  test('vendor table shows id, name, and owner type badges', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    const firstRow = page.locator('table tbody tr').first()
    await expect(firstRow).toBeVisible()
    // ID in monospace
    await expect(firstRow.locator('.font-mono')).toBeVisible()
    // Owner type badge
    await expect(firstRow.locator('.rounded-full')).toBeVisible()
  })

  test('vendor table has search input', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    const searchInput = page.locator('input[type="text"]').first()
    await expect(searchInput).toBeVisible()
  })

  // ── Search ──
  test('search vendors with no results shows empty state', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    const searchInput = page.locator('input[type="text"]').first()
    await searchInput.fill('zzz_nonexistent_vendor_xyz')
    await searchInput.press('Enter')
    await page.waitForTimeout(1_000)
    const noResults = page.locator('text=/no vendor|暂无厂商/i')
    const rows = page.locator('table tbody tr')
    const isEmpty = (await noResults.count()) > 0 || (await rows.count()) === 0
    expect(isEmpty).toBeTruthy()
  })

  // ── Create Vendor Modal ──
  test('create vendor button opens modal with all form fields', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await page.locator('button').filter({ hasText: /create vendor|创建厂商/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    // Modal title
    await expect(modal.locator('h2')).toBeVisible()
    // Name input (text)
    const inputs = modal.locator('input[type="text"]')
    await expect(inputs.first()).toBeVisible()
    // Description textarea
    await expect(modal.locator('textarea')).toBeVisible()
    // Website URL input
    expect(await inputs.count()).toBeGreaterThanOrEqual(2)
    // Submit and Cancel buttons
    await expect(modal.locator('button[type="submit"]')).toBeVisible()
    const cancelBtn = modal.locator('button[type="button"]').filter({ hasText: /cancel|取消/i })
    await expect(cancelBtn).toBeVisible()
  })

  test('vendor modal validation requires name', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await page.locator('button').filter({ hasText: /create vendor|创建厂商/i }).click()
    const modal = page.locator('.fixed.inset-0')
    // Submit with empty name
    await modal.locator('button[type="submit"]').click()
    await expect(modal.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('vendor modal cancel closes it', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await page.locator('button').filter({ hasText: /create vendor|创建厂商/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await expect(modal).toBeVisible()
    await modal.locator('button[type="button"]').filter({ hasText: /cancel|取消/i }).click()
    await expect(modal).not.toBeVisible()
  })

  test('create vendor with valid data submits successfully', async ({ page }) => {
    const vendorName = `e2e-vendor-${Date.now()}`
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })

    // Open create modal
    await page.locator('button').filter({ hasText: /create vendor|创建厂商/i }).click()
    const modal = page.locator('.fixed.inset-0')

    // Fill name
    const nameInput = modal.locator('input[type="text"]').first()
    await nameInput.fill(vendorName)
    // Fill description
    await modal.locator('textarea').fill('E2E test vendor description')
    // Fill website URL
    const textInputs = modal.locator('input[type="text"]')
    const lastInput = textInputs.nth((await textInputs.count()) - 1)
    await lastInput.fill('https://example.com')

    // Submit
    await modal.locator('button[type="submit"]').click()
    // Modal should close after successful creation
    await expect(modal).not.toBeVisible({ timeout: 5_000 })
    // Search by the exact name so the check does not depend on current default sort order.
    const searchInput = page.locator('input[type="text"]').first()
    await searchInput.fill(vendorName)
    await searchInput.press('Enter')
    await expect(page.locator('table tbody tr').filter({ hasText: vendorName }).first()).toBeVisible({ timeout: 5_000 })
  })

  test('vendor details entry opens detail page without polluting templates filter state', async ({ page }) => {
    const vendorName = `e2e-vendor-detail-${Date.now()}`
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })

    await page.locator('button').filter({ hasText: /create vendor|创建厂商/i }).click()
    const modal = page.locator('.fixed.inset-0')
    await modal.locator('input[type="text"]').first().fill(vendorName)
    await modal.locator('textarea').fill('Vendor detail navigation regression test')
    const textInputs = modal.locator('input[type="text"]')
    await textInputs.nth((await textInputs.count()) - 1).fill('https://example.com')
    await modal.locator('button[type="submit"]').click()
    await expect(modal).not.toBeVisible({ timeout: 5_000 })

    const searchInput = page.locator('input[type="text"]').first()
    await searchInput.fill(vendorName)
    await searchInput.press('Enter')
    const vendorRow = page.locator('table tbody tr').filter({ hasText: vendorName }).first()
    await expect(vendorRow).toBeVisible({ timeout: 5_000 })
    await vendorRow.getByTestId('vendor-details-link').click()

    await expect(page).toHaveURL(/\/vendors\/[^/]+$/)
    await expect(page.locator('h1')).toHaveText(vendorName)

    await page.goto('/templates')
    const vendorFilter = page.getByTestId('templates-vendor-filter')
    await expect(vendorFilter).toBeVisible({ timeout: 10_000 })
    await expect(vendorFilter).toHaveValue('')
  })

  // ── Edit Vendor ──
  test('edit button opens modal with pre-filled data', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    // Find a non-official vendor's edit button (or any vendor)
    const editBtn = page.locator('table tbody tr').first().locator('button').filter({ hasText: /edit|编辑/i })
    if ((await editBtn.count()) > 0) {
      await editBtn.click()
      const modal = page.locator('.fixed.inset-0')
      await expect(modal).toBeVisible()
      // Name input should be pre-filled (not empty)
      const nameInput = modal.locator('input[type="text"]').first()
      const value = await nameInput.inputValue()
      expect(value.length).toBeGreaterThan(0)
    }
  })

  // ── Delete Vendor (2-step confirm) ──
  test('delete button shows confirm/cancel before deletion', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/vendors')
    await expect(page.locator('table')).toBeVisible({ timeout: 10_000 })
    // Look for a user-owned (non-official) vendor with delete button
    const deleteBtn = page.locator('table tbody button').filter({ hasText: /delete|删除/i }).first()
    if ((await deleteBtn.count()) > 0) {
      await deleteBtn.click()
      // After clicking delete, Confirm and Cancel should appear
      await expect(page.locator('button').filter({ hasText: /confirm|确认/i })).toBeVisible({ timeout: 2_000 })
      await expect(page.locator('button').filter({ hasText: /cancel|取消/i })).toBeVisible()
      // Click cancel to abort
      await page.locator('table tbody button').filter({ hasText: /cancel|取消/i }).first().click()
    }
  })

  // ── Free User ──
  test('free user can view vendors page', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/vendors')
    await expect(page.locator('h1')).toBeVisible()
    await page.waitForTimeout(3_000)
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
  })
})
