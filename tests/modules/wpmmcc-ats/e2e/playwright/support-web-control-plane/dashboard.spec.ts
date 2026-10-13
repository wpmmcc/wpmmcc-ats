import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

test.describe('Dashboard', () => {
  // ── Admin Dashboard ──
  test('admin sees dashboard with stat cards', async ({ page }) => {
    await loginAsAdmin(page)
    await expect(page.locator('h1')).toBeVisible()
    // 4 stat cards in the top grid
    const statCards = page.locator('.grid .bg-white.border')
    await expect(statCards.first()).toBeVisible()
    const count = await statCards.count()
    expect(count).toBeGreaterThanOrEqual(4)
  })

  test('admin dashboard shows quick action links', async ({ page }) => {
    await loginAsAdmin(page)
    await expect(page.locator('main a[href="/domains"]')).toBeVisible()
    await expect(page.locator('main a[href="/keys"]')).toBeVisible()
    await expect(page.locator('main a[href="/components"]')).toBeVisible()
  })

  test('admin sees admin panel link in quick actions', async ({ page }) => {
    await loginAsAdmin(page)
    await expect(page.locator('main a[href="/admin/overview"]')).toBeVisible()
  })

  // ── Free User Dashboard ──
  test('free user sees dashboard without admin link', async ({ page }) => {
    await loginAsFreeUser(page)
    await expect(page.locator('h1')).toBeVisible()
    await expect(page.locator('main a[href="/admin/overview"]')).toHaveCount(0)
  })

  test('free user sees stat cards', async ({ page }) => {
    await loginAsFreeUser(page)
    const statCards = page.locator('.grid .bg-white.border')
    await expect(statCards.first()).toBeVisible()
  })

  test('free user sees quick action links except admin', async ({ page }) => {
    await loginAsFreeUser(page)
    await expect(page.locator('main a[href="/domains"]')).toBeVisible()
    await expect(page.locator('main a[href="/keys"]')).toBeVisible()
    await expect(page.locator('main a[href="/components"]')).toBeVisible()
  })

  // ── Navigation ──
  test('dashboard shows correct navigation links', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await loginAsAdmin(page)
    const nav = page.locator('nav')
    for (const href of ['/domains', '/tickets', '/customizations', '/keys', '/components', '/vendors', '/templates']) {
      await expect(nav.locator(`a[href="${href}"]`)).toBeVisible({ timeout: 3_000 })
    }
    // WPTSALL logo link also points to /dashboard
    await expect(nav.getByRole('link', { name: 'WPTSALL' })).toBeVisible()
  })

  test('free user sees same navigation links', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await loginAsFreeUser(page)
    const nav = page.locator('nav')
    for (const href of ['/domains', '/tickets', '/customizations', '/keys', '/components', '/vendors', '/templates']) {
      await expect(nav.locator(`a[href="${href}"]`)).toBeVisible({ timeout: 3_000 })
    }
  })

  test('navigation excludes removed purchase routes', async ({ page }) => {
    await loginAsAdmin(page)
    const nav = page.locator('nav')
    await expect(nav.locator('a[href="/pricing"]')).toHaveCount(0)
    await expect(nav.locator('a[href="/account/products"]')).toHaveCount(0)
    await expect(nav.locator('a[href="/entitlements"]')).toHaveCount(0)
  })
})
