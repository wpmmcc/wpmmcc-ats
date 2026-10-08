import { test, expect } from '../lib/page-errors'
import { loginAs, loginAsAdmin, loginAsFreeUser, logout, expectNavbarEmail, clearSession } from './helpers'

test.describe('Authentication', () => {
  test.beforeEach(async ({ page }) => {
    await clearSession(page)
  })

  test('login page renders all form elements', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'WPTSALL' })).toBeVisible()
    await expect(page.locator('input[type="email"]')).toBeVisible()
    await expect(page.locator('input[type="password"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
    await expect(page.locator('a[href="/register"]').first()).toBeVisible()
    await expect(page.locator('a[href="/forgot-password"]').first()).toBeVisible()
  })

  test('admin login redirects to dashboard and shows email', async ({ page }) => {
    await loginAsAdmin(page)
    await expect(page).toHaveURL(/\/dashboard/)
    await expectNavbarEmail(page, 'demo@wptsall.dev')
  })

  test('free user login redirects to dashboard and shows email', async ({ page }) => {
    await loginAsFreeUser(page)
    await expect(page).toHaveURL(/\/dashboard/)
    await expectNavbarEmail(page, 'free@wptsall.dev')
  })

  test('login with wrong password stays on login page', async ({ page }) => {
    await page.goto('/login')
    await page.fill('input[type="email"]', 'demo@wptsall.dev')
    await page.fill('input[type="password"]', 'wrongpassword')
    await page.click('button[type="submit"]')
    await page.waitForTimeout(3_000)
    await expect(page).toHaveURL(/\/login/)
  })

  test('login with empty fields shows validation error', async ({ page }) => {
    await page.goto('/login')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('login with empty password shows validation error', async ({ page }) => {
    await page.goto('/login')
    await page.fill('input[type="email"]', 'demo@wptsall.dev')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('unauthenticated user is redirected to login', async ({ page }) => {
    await page.goto('/dashboard')
    await expect(page).toHaveURL(/\/login/)
  })

  test('unauthenticated user redirected from protected routes', async ({ page }) => {
    for (const route of ['/domains', '/keys', '/components', '/templates', '/vendors', '/tickets', '/customizations', '/settings']) {
      await page.goto(route)
      await expect(page).toHaveURL(/\/login/)
    }
  })

  test('logout clears session and redirects to login', async ({ page }) => {
    await loginAsAdmin(page)
    await logout(page)
    await expect(page).toHaveURL(/\/login/)
    const token = await page.evaluate(() => localStorage.getItem('session_token'))
    expect(token).toBeNull()
  })

  test('guest routes redirect authenticated user to dashboard', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/login')
    await expect(page).toHaveURL(/\/dashboard/)
  })

  test('register route redirects authenticated user to dashboard', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/register')
    await expect(page).toHaveURL(/\/dashboard/)
  })

  test('authenticated user is redirected away from removed purchase pages', async ({ page }) => {
    await loginAsAdmin(page)
    for (const route of ['/pricing', '/payment/success', '/payment/cancel', '/account/products', '/entitlements']) {
      await page.goto(route)
      await expect(page).toHaveURL(/\/(dashboard|admin\/overview)/)
    }
  })
})
