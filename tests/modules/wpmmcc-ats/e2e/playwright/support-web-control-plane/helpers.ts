import { type Page, expect } from '@playwright/test'

/** Login via the UI login form. */
export async function loginAs(page: Page, email: string, password = 'demo') {
  await page.goto('/login')
  await page.fill('input[type="email"]', email)
  await page.fill('input[type="password"]', password)
  await page.click('button[type="submit"]')
  await page.waitForURL(/\/(dashboard|admin\/overview)$/, { timeout: 10_000 })
}

/** Login as admin. */
export async function loginAsAdmin(page: Page) {
  await loginAs(page, 'admin@wptsall.dev')
}

/** Login as paid/pro demo user. */
export async function loginAsPaidUser(page: Page) {
  await loginAs(page, 'demo@wptsall.dev')
}

/** Login as free user (no domains). */
export async function loginAsFreeUser(page: Page) {
  await loginAs(page, 'free@wptsall.dev')
}

/** Logout via navbar button. */
export async function logout(page: Page) {
  await page.click('header button:has-text("Logout"), header button:has-text("退出")')
  await page.waitForURL('**/login', { timeout: 5_000 })
}

/** Expect navbar to show the given email. */
export async function expectNavbarEmail(page: Page, email: string) {
  await expect(page.locator('nav').getByText(email)).toBeVisible()
}

export function expectNoRawI18nKeys(locator = 'body') {
  return async (page: Page) => {
    const body = await page.locator(locator).innerText()
    expect(body).not.toMatch(/\b(?:nav|common|dashboard|products|templates|tickets|entitlements|admin|status)\.[a-z0-9_.-]+/i)
  }
}

/** Clear session storage (call before guest-only tests). */
export async function clearSession(page: Page) {
  await page.goto('/login')
  await page.evaluate(() => {
    localStorage.removeItem('session_token')
    localStorage.removeItem('user_email')
    localStorage.removeItem('is_admin')
  })
}

/** Wait for API data to load (table or cards visible, loading gone). */
export async function waitForDataLoad(page: Page, timeout = 10_000) {
  await page.waitForTimeout(500)
  const loadingEl = page.locator('text=/Loading|加载中/i')
  if ((await loadingEl.count()) > 0) {
    await expect(loadingEl).toHaveCount(0, { timeout })
  }
}
