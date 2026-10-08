import { test, expect } from '@playwright/test'
import {
  bypassTurnstile,
  CLIENT_BASE,
  clearWebSession,
  expectClientLoggedIn,
  generateFreshWpVerificationUrl,
  loginClientViaOAuth,
  loginWebUser,
  readUserPasswordHash,
  requestWebPasswordReset,
  resetWebPassword,
  resolvePasswordResetToken,
  restoreUserPasswordHash,
  wpLogin,
} from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('Journey: WP Domain Reverify', () => {
  const wpAdminUser = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
  const wpAdminPassword = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
  const webUserEmail = process.env.JOURNEY_DEMO_EMAIL ?? 'demo@wptsall.dev'
  const webUserPassword = process.env.JOURNEY_DEMO_PASSWORD ?? 'Journey-Demo-Password-2026!'
  // 批 O6 复栈: the expected site domain is the WP slot's siteurl host —
  // env-overridable so the lab slot (blog.localhost) and the original
  // production host (blog.wpmm.cc) share the same journey.
  const wpDomain = process.env.JOURNEY_WP_DOMAIN ?? 'blog.wpmm.cc'
  const wpDomainRe = new RegExp(wpDomain.replace(/\./g, '\\.'), 'i')

  test('WP 生成 verification URL -> 官网 reverify 绑定域名 -> Client 立即可见域名', async ({ page, context, request }) => {
    await bypassTurnstile(context)

    await wpLogin(page, { user: wpAdminUser, password: wpAdminPassword })
    const verificationUrl = await generateFreshWpVerificationUrl(page)
    expect(verificationUrl).toContain('/wp-json/wptsall/v2/site/verify?nonce=')

    const originalPasswordHash = readUserPasswordHash(webUserEmail)
    expect(originalPasswordHash.length).toBeGreaterThan(20)

    try {
      await clearWebSession(page)
      await requestWebPasswordReset(page, { email: webUserEmail })
      const resetToken = await resolvePasswordResetToken(webUserEmail)
      expect(resetToken.length).toBeGreaterThan(10)
      await resetWebPassword(page, { token: resetToken, password: webUserPassword })

      await clearWebSession(page)
      await loginWebUser(page, { email: webUserEmail, password: webUserPassword })
      // 批 O6 复栈 (B 档净室): this spec previously INHERITED the client
      // session left in the shared lane client's SQLite by whichever spec
      // ran before it — in the per-spec clean room there is no residual
      // session, so the journey must establish its own (the same
      // OAuth-login-to-client flow wp-job-to-client uses). The client
      // bootstraps its domain list at this login, which is why the bind
      // below is followed by the Overview refresh.
      await loginClientViaOAuth(page, context, { email: webUserEmail, password: webUserPassword })
      await expectClientLoggedIn(request)
      await page.goto('/domains')

      const dialogPromise = page.waitForEvent('dialog', { timeout: 5_000 }).catch(() => null)
      await page.locator('button').filter({ hasText: /Add Domain|添加域名/i }).first().click()
      await page.locator('input[type="text"]').fill(verificationUrl)
      await page.locator('button').filter({ hasText: /Bind|绑定/i }).last().click()

      const dialog = await dialogPromise
      if (dialog) {
        await dialog.accept()
      }

      await expect(page.locator('table')).toContainText(wpDomainRe, { timeout: 20_000 })
      await expect(page.locator('table')).toContainText(/active/i, { timeout: 20_000 })

      await page.goto(CLIENT_BASE)
      await expectClientLoggedIn(request)
      // The client bootstrapped its domain list at OAuth login (before the
      // web-side bind created the domain), so the fresh binding is not in
      // its cached status yet. Trigger the Overview's legacy-plane refresh
      // (the real user flow) and wait for the bound domain to appear.
      await page
        .locator('button')
        .filter({ hasText: /刷新域名|Refresh Domains/i })
        .first()
        .click()
      await expect(page.getByText(wpDomainRe).first()).toBeVisible({
        timeout: 20_000,
      })
    } finally {
      restoreUserPasswordHash(webUserEmail, originalPasswordHash)
    }
  })
})
