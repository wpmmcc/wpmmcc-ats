import { test, expect } from '@playwright/test'
import {
  bypassTurnstile,
  clearWebSession,
  expectClientLoggedIn,
  loginClientViaOAuth,
  loginWebUser,
  registerUser,
  resolveVerificationToken,
  uniqueJourneyEmail,
  verifyUserEmail,
} from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('Journey: Web User Onboarding', () => {
  const password = 'Journey-User-Password-2026!'
  const email = uniqueJourneyEmail('journey-web-user')
  const name = 'Journey Web User'

  test('官网注册 -> 邮箱验证 -> 官网登录 -> Client OAuth 登录', async ({ page, context, request }) => {
    await bypassTurnstile(context)
    await clearWebSession(page)

    await registerUser(page, { email, password, name })

    const token = await resolveVerificationToken(email)
    expect(token.length).toBeGreaterThan(10)
    await verifyUserEmail(request, token)

    await clearWebSession(page)
    await loginWebUser(page, { email, password })

    await expect(page).toHaveURL(/\/dashboard/)
    await expect(page.locator('main')).toBeVisible()
    await page.goto('/domains')
    await expect(page.locator('text=/No domains|暂无域名/i')).toBeVisible({ timeout: 15_000 })

    await loginClientViaOAuth(page, context, { email, password })
    await expectClientLoggedIn(request)
  })
})
