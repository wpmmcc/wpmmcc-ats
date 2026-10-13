import { test, expect } from '@playwright/test'
import {
  bootstrapWorkerSetup,
  cleanupWorkerBootstrap,
  type WorkerBootstrapState,
} from '../official-gate/client-translate.shared'
import {
  bypassTurnstile,
  clearWebSession,
  CLIENT_BASE,
  configureClientDiscoveryTasksForRelation,
  configureClientE2ERunCaps,
  createFreshWpLanguagePackJob,
  ensureWpClientApiRuntime,
  expectClientLoggedIn,
  loginClientViaOAuth,
  readUserPasswordHash,
  resetClientRelationBacklog,
  requestWebPasswordReset,
  resetWebPassword,
  restoreClientE2ERunCaps,
  resolveLiveWpClientCredentials,
  resolvePasswordResetToken,
  runClientWorkerOnce,
  restoreUserPasswordHash,
  wpLogin,
} from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('Journey: WP Job To Client Run Once', () => {
  const wpAdminUser = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
  const wpAdminPassword = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
  const webUserEmail = process.env.JOURNEY_DEMO_EMAIL ?? 'demo@wptsall.dev'
  const webUserPassword = process.env.JOURNEY_DEMO_PASSWORD ?? 'Journey-Demo-Password-2026!'
  // 批 O6 复栈: the relation rows surface the WP slot's siteurl host — env
  // overridable like wp-domain-reverify so the lab slot (blog.localhost) and
  // the original production host (blog.wpmm.cc) share the same journey.
  const wpDomain = process.env.JOURNEY_WP_DOMAIN ?? 'blog.wpmm.cc'
  const wpDomainRe = new RegExp(wpDomain.replace(/\./g, '\\.'), 'i')

  test('WP 创建任务包 -> Client OAuth 登录 -> 概览运行一次 -> 任务页可见执行结果', async ({ page, context, request }) => {
    test.setTimeout(420_000)

    await bypassTurnstile(context)
    await wpLogin(page, { user: wpAdminUser, password: wpAdminPassword })

    const jobBundle = await createFreshWpLanguagePackJob(page)
    expect(jobBundle.totalTasks).toBeGreaterThan(0)
    ensureWpClientApiRuntime()
    resetClientRelationBacklog(Number(jobBundle.relationId))
    await configureClientE2ERunCaps({
      discoveryMaxItemsPerRun: 12,
      runOnceMaxElapsedSecs: 90,
    })
    const liveWpCredentials = resolveLiveWpClientCredentials()

    const originalPasswordHash = readUserPasswordHash(webUserEmail)
    let bootstrapState: WorkerBootstrapState = {
      componentId: '',
      createdLocalComponentIds: [],
    }

    try {
      await clearWebSession(page)
      await requestWebPasswordReset(page, { email: webUserEmail })
      const resetToken = await resolvePasswordResetToken(webUserEmail)
      expect(resetToken.length).toBeGreaterThan(10)
      await resetWebPassword(page, { token: resetToken, password: webUserPassword })

      await loginClientViaOAuth(page, context, { email: webUserEmail, password: webUserPassword })
      await expectClientLoggedIn(request)

      bootstrapState = await bootstrapWorkerSetup(request, {
        ...liveWpCredentials,
        forceLocalComponent: true,
      })
      expect(bootstrapState.componentId.length).toBeGreaterThan(0)

      await configureClientDiscoveryTasksForRelation(request, {
        routeSecret: liveWpCredentials.routeSecret,
        relationId: Number(jobBundle.relationId),
        componentId: bootstrapState.componentId,
      })

      await page.goto(CLIENT_BASE)
      await expect(page.locator('h2:has-text("概览")').first()).toBeVisible()
      await expect(page.locator('button:has-text("运行一次")')).toBeVisible()
      const runOnceSummary = await runClientWorkerOnce(request)
      const runOnceTasksProcessed = Number(runOnceSummary?.tasks_processed ?? runOnceSummary?.total_items ?? 0)
      const runOnceTasksSucceeded = Number(runOnceSummary?.tasks_succeeded ?? 0)
      const runOnceBreakReason = String(runOnceSummary?.break_reason ?? '')
      expect(
        runOnceTasksProcessed > 0
          && (runOnceTasksSucceeded > 0 || runOnceBreakReason === 'dedup_or_noop'),
        `worker run-once returned no successful work: ${JSON.stringify(runOnceSummary)}`,
      ).toBe(true)

      await page.getByRole('button', { name: '任务' }).click()
      await expect(page.locator('h2:has-text("任务管理")')).toBeVisible()
      await expect(page.locator('text=暂无翻译任务记录')).not.toBeVisible()
      await expect(page.locator(`text=${jobBundle.relationId}`).first()).toBeVisible({ timeout: 20_000 })
      // getByText (not locator): locator() does not accept a RegExp selector.
      await expect(page.getByText(wpDomainRe).first()).toBeVisible({ timeout: 20_000 })
    } finally {
      await cleanupWorkerBootstrap(request, bootstrapState).catch(() => {})
      await restoreClientE2ERunCaps().catch(() => {})
      restoreUserPasswordHash(webUserEmail, originalPasswordHash)
      await clearWebSession(page).catch(() => {})
    }
  })
})
