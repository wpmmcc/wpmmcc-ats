import { test, expect } from '../lib/page-errors'
import { loginAsAdmin } from './helpers'

test.describe.configure({ mode: 'serial' })

test('Auxiliary pages render for logged-in user', async ({ page }) => {
  await loginAsAdmin(page)

  for (const path of ['/tickets', '/customizations', '/settings']) {
    await page.goto(path, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('h1').first()).toBeVisible({ timeout: 10_000 })
    const html = await page.content()
    expect(html.length).toBeGreaterThan(500)
  }
})

test('Admin customizations surface is reachable', async ({ page }) => {
  await loginAsAdmin(page)
  await page.goto('/admin/customizations', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
  await expect(page.locator('h1').first()).toBeVisible({ timeout: 10_000 })
})

test('Sidebar navigation includes current user surfaces only', async ({ page }) => {
  await loginAsAdmin(page)
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

  for (const href of ['/tickets', '/customizations', '/settings']) {
    await expect(page.locator(`nav a[href="${href}"]`)).toBeVisible({ timeout: 10_000 })
  }
  await expect(page.locator('nav a[href="/account/products"]')).toHaveCount(0)
  await expect(page.locator('nav a[href="/entitlements"]')).toHaveCount(0)
})
