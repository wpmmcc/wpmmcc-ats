import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Components', () => {
  // ── Admin Components ──
  test('admin sees component card grid', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/components')
    await expect(page.locator('h1')).toBeVisible()
    const cards = page.locator('.grid .bg-white.border')
    await expect(cards.first()).toBeVisible({ timeout: 10_000 })
    // Should have multiple official components (20 seeded)
    const count = await cards.count()
    expect(count).toBeGreaterThanOrEqual(6)
  })

  test('component cards show official badge', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/components')
    const cards = page.locator('.grid .bg-white.border')
    await expect(cards.first()).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('text=official').first()).toBeVisible()
  })

  test('component cards show id in monospace', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/components')
    const firstCard = page.locator('.grid .bg-white.border').first()
    await expect(firstCard).toBeVisible({ timeout: 10_000 })
    await expect(firstCard.locator('.font-mono')).toBeVisible()
  })

  test('components page loads without errors', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/components')
    await page.waitForTimeout(3_000)
    const errorText = page.locator('.text-red-600')
    await expect(errorText).toHaveCount(0)
  })

  // ── Free User Components ──
  test('free user can see official components', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/components')
    await expect(page.locator('h1')).toBeVisible()
    const cards = page.locator('.grid .bg-white.border')
    await expect(cards.first()).toBeVisible({ timeout: 10_000 })
    const count = await cards.count()
    expect(count).toBeGreaterThanOrEqual(6)
  })

  test('free user sees official badges', async ({ page }) => {
    await loginAsFreeUser(page)
    await page.goto('/components')
    const cards = page.locator('.grid .bg-white.border')
    await expect(cards.first()).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('text=official').first()).toBeVisible()
  })
})
