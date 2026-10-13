import { test, expect } from '../lib/page-errors'
import { loginAsAdmin, loginAsFreeUser } from './helpers'

const currentAdminRoutes = [
  '/admin/overview',
  '/admin/users',
  '/admin/orders',
  '/templates',
  '/admin/wp-translation-providers',
  '/admin/cloud-api-types',
  '/admin/github-projects',
  '/admin/tickets',
  '/admin/customizations',
]

test.describe('Admin Pages', () => {
  test('admin overview shows statistics cards', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/overview')
    await expect(page.locator('h1')).toBeVisible()
    const statCards = page.locator('.bg-white.border.rounded-lg')
    await expect(statCards.first()).toBeVisible({ timeout: 10_000 })
    expect(await statCards.count()).toBeGreaterThanOrEqual(4)
  })

  test('admin users page shows table with data', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/users')
    await expect(page.locator('h1')).toBeVisible()
    await expect(page.locator('table')).toBeVisible({ timeout: 15_000 })
    expect(await page.locator('table tbody tr').count()).toBeGreaterThanOrEqual(1)
  })

  test('admin orders page loads without fatal errors', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/admin/orders')
    await expect(page.locator('h1')).toBeVisible()
    await expect(page.locator('main')).toBeVisible()
  })

  test('current admin routes render for admin users', async ({ page }) => {
    await loginAsAdmin(page)
    for (const route of currentAdminRoutes) {
      await page.goto(route)
      await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
      await expect(page.locator('h1').first()).toBeVisible({ timeout: 10_000 })
    }
  })

  test('admin navigation exposes current admin surfaces only', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await loginAsAdmin(page)
    const nav = page.locator('nav')

    for (const href of currentAdminRoutes) {
      await expect(nav.locator(`a[href="${href}"]`)).toBeVisible({ timeout: 3_000 })
    }

    await expect(nav.locator('a[href="/pricing"]')).toHaveCount(0)
    await expect(nav.locator('a[href="/admin/payment-settings"]')).toHaveCount(0)
    await expect(nav.locator('a[href="/admin/sessions"]')).toHaveCount(0)
    await expect(nav.locator('a[href="/admin/audit-logs"]')).toHaveCount(0)
  })

  test('non-admin users are redirected away from admin-only routes', async ({ page }) => {
    await loginAsFreeUser(page)
    for (const route of ['/admin/overview', '/templates', '/admin/users']) {
      await page.goto(route)
      await expect(page).toHaveURL(/\/dashboard$/)
    }
  })
})
