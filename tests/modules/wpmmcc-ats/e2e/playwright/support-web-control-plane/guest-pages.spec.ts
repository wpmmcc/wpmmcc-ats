import { test, expect } from '../lib/page-errors'
import { clearSession } from './helpers'

test.describe('Guest Pages', () => {
  test.beforeEach(async ({ page }) => {
    await clearSession(page)
  })

  // ── Register Page ──
  test('register page renders all form fields', async ({ page }) => {
    await page.goto('/register')
    await expect(page.locator('h1')).toBeVisible()
    // Name field (optional)
    await expect(page.locator('input[type="text"]')).toBeVisible()
    // Email field
    await expect(page.locator('input[type="email"]')).toBeVisible()
    // Password field
    await expect(page.locator('input[type="password"]')).toBeVisible()
    // Submit button
    await expect(page.locator('button[type="submit"]')).toBeVisible()
    // Link to login
    await expect(page.locator('a[href="/login"]').first()).toBeVisible()
  })

  test('register with empty email and password shows validation error', async ({ page }) => {
    await page.goto('/register')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('register with short password shows validation error', async ({ page }) => {
    await page.goto('/register')
    await page.fill('input[type="email"]', 'test-short@example.com')
    await page.fill('input[type="password"]', 'abc')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  // ── Forgot Password Page ──
  test('forgot password page renders form', async ({ page }) => {
    await page.goto('/forgot-password')
    await expect(page.locator('h1')).toBeVisible()
    await expect(page.locator('input[type="email"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
    // Back to login link
    await expect(page.locator('a[href="/login"]').first()).toBeVisible()
  })

  test('forgot password with empty email shows validation error', async ({ page }) => {
    await page.goto('/forgot-password')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  // ── Reset Password Page ──
  test('reset password page renders form fields', async ({ page }) => {
    await page.goto('/reset-password?token=test-token')
    await expect(page.locator('h1')).toBeVisible()
    // Two password fields
    const passwordFields = page.locator('input[type="password"]')
    await expect(passwordFields).toHaveCount(2)
    await expect(page.locator('button[type="submit"]')).toBeVisible()
  })

  test('reset password without token shows error on submit', async ({ page }) => {
    await page.goto('/reset-password')
    const fields = page.locator('input[type="password"]')
    await fields.nth(0).fill('newpassword123')
    await fields.nth(1).fill('newpassword123')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('reset password with short password shows error', async ({ page }) => {
    await page.goto('/reset-password?token=test-token')
    const fields = page.locator('input[type="password"]')
    await fields.nth(0).fill('abc')
    await fields.nth(1).fill('abc')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  test('reset password with mismatched passwords shows error', async ({ page }) => {
    await page.goto('/reset-password?token=test-token')
    const fields = page.locator('input[type="password"]')
    await fields.nth(0).fill('newpassword123')
    await fields.nth(1).fill('differentpassword')
    await page.click('button[type="submit"]')
    await expect(page.locator('.text-red-600')).toBeVisible({ timeout: 3_000 })
  })

  // ── Verify Email Page ──
  test('verify email page renders', async ({ page }) => {
    await page.goto('/verify-email')
    await expect(page.locator('h1')).toBeVisible()
  })

  // ── Redirects ──
  test('root path renders public home page', async ({ page }) => {
    await page.goto('/')
    await expect(page).toHaveURL(/\/$/)
    const main = page.locator('main')
    await expect(main).toBeVisible()
    await expect(main.locator('a[href="/register"], a[href="/dashboard"]')).toHaveCount(1)
  })

  test('unknown path redirects to public home page', async ({ page }) => {
    await page.goto('/some-random-path')
    await expect(page).toHaveURL(/\/$/)
  })
})
