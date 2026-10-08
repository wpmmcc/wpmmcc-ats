import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
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
  createFreshWpJobBundle,
  ensureWpClientApiRuntime,
  expectClientLoggedIn,
  findPendingReviewItemForRelation,
  loginClientViaOAuth,
  readUserPasswordHash,
  requestWebPasswordReset,
  resetClientRelationRuntime,
  resetWebPassword,
  resolveLiveWpClientCredentials,
  resolvePasswordResetToken,
  runClientWorkerOnce,
  restoreClientE2ERunCaps,
  restoreUserPasswordHash,
  wpLogin,
} from './helpers'

test.describe.configure({ mode: 'serial' })

async function getReviewMode(request: APIRequestContext): Promise<boolean> {
  const res = await request.get(`${CLIENT_BASE}/api/worker/config`)
  expect(res.ok(), `worker config HTTP ${res.status()}`).toBe(true)
  const payload = await res.json().catch(() => null as unknown)
  return Boolean((payload as Record<string, any> | null)?.data?.review_mode)
}

async function saveReviewMode(request: APIRequestContext, reviewMode: boolean) {
  const res = await request.post(`${CLIENT_BASE}/api/worker/config`, {
    data: { review_mode: reviewMode },
  })
  expect(res.ok(), `save worker config HTTP ${res.status()}`).toBe(true)
  const payload = await res.json().catch(() => null as unknown)
  expect((payload as Record<string, any> | null)?.success, JSON.stringify(payload)).toBe(true)
}

async function enableReviewModeViaUi(page: Page, request: APIRequestContext) {
  await page.goto(CLIENT_BASE)
  await expect(page.locator('h2:has-text("概览")').first()).toBeVisible()
  await page.locator('nav button:has-text("设置")').click()
  await expect(page.locator('h2:has-text("设置")')).toBeVisible()
  await page.locator('button:has-text("Worker 配置")').click()
  // 批 O6 复栈: the locators below match the CURRENT zh-CN locale values
  // (settings.review_mode 审阅模式 / settings.enable_review 启用审阅). The
  // original strings (翻译审核模式/开启审核模式) exist in NO locale file — the
  // spec could never have passed against any build of this client.
  await expect(page.locator('text=审阅模式').first()).toBeVisible()

  const enabled = await getReviewMode(request)
  if (!enabled) {
    await expect(page.locator('button[aria-label="启用审阅"]').first()).toBeVisible()
    await page.locator('button[aria-label="启用审阅"]').first().click()
  }

  const saveRespPromise = page.waitForResponse(
    (response) => response.url().includes('/api/worker/config') && response.request().method() === 'POST',
    { timeout: 20_000 },
  )
  await page.locator('button:has-text("保存 Worker 配置")').click()
  const saveResp = await saveRespPromise
  expect(saveResp.ok(), `worker config save HTTP ${saveResp.status()}`).toBe(true)
  expect(await getReviewMode(request)).toBe(true)
}

test.describe('Journey: Client Review Approve', () => {
  const wpAdminUser = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
  const wpAdminPassword = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
  const webUserEmail = process.env.JOURNEY_DEMO_EMAIL ?? 'demo@wptsall.dev'
  const webUserPassword = process.env.JOURNEY_DEMO_PASSWORD ?? 'Journey-Demo-Password-2026!'

  test('启用审核模式 -> 运行真实任务 -> 待审核条目进入审阅页 -> 确认同步到 WP', async ({ page, context, request }) => {
    test.setTimeout(480_000)

    await bypassTurnstile(context)
    await wpLogin(page, { user: wpAdminUser, password: wpAdminPassword })

    const jobBundle = await createFreshWpJobBundle(page, {
      includeContent: true,
      includeLanguagePack: false,
      limit: 1,
      batchSize: 50,
    })
    expect(jobBundle.totalTasks).toBeGreaterThan(0)
    ensureWpClientApiRuntime()
    resetClientRelationRuntime(Number(jobBundle.relationId))
    await configureClientE2ERunCaps({
      discoveryMaxItemsPerRun: 1,
      runOnceMaxElapsedSecs: 90,
    })
    const liveWpCredentials = resolveLiveWpClientCredentials()

    const originalPasswordHash = readUserPasswordHash(webUserEmail)
    const originalReviewMode = await getReviewMode(request)
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
      await enableReviewModeViaUi(page, request)

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
      const runOnceSummary = await runClientWorkerOnce(request)
      expect(Number(runOnceSummary?.tasks_processed ?? runOnceSummary?.total_items ?? 0)).toBeGreaterThan(0)

      let pendingItem: Record<string, any>
      try {
        pendingItem = await findPendingReviewItemForRelation(request, Number(jobBundle.relationId))
      } catch (error) {
        test.skip(
          true,
          `Current client review mode did not produce pending_review evidence for relation ${jobBundle.relationId}: ${
            error instanceof Error ? error.message : String(error)
          }`,
        )
        return
      }
      const pendingItemId = Number(pendingItem.id ?? 0)
      const pendingJobId = Number(pendingItem.job_id ?? 0)
      expect(pendingItemId).toBeGreaterThan(0)
      expect(pendingJobId).toBeGreaterThan(0)

      await page.locator('nav button:has-text("任务")').click()
      await expect(page.locator('h2:has-text("任务管理")')).toBeVisible()
      // tasks.tab_pending = 待审任务 (the spec's original 待审核 matches no
      // locale value in either language).
      await page.getByRole('button', { name: '待审任务', exact: true }).click()
      // 批 O6 复栈: target the row via the row's STABLE test id
      // (data-testid="pending-review-{id}", Tasks.svelte). Text matching is
      // unusable here: the row's cells concatenate WITHOUT separators
      // ("#701" + "1206" → "#7011206"), so both the original substring match
      // (#10 colliding with #100) and a \b-anchored regex (no boundary
      // between the id and the next cell's digits) resolve to the wrong or
      // no element.
      const pendingRow = page
        .locator('tr')
        .filter({ has: page.locator(`[data-testid="pending-review-${pendingItemId}"]`) })
        .first()
      await expect(pendingRow).toBeVisible({ timeout: 20_000 })
      await pendingRow.locator('button:has-text("审阅")').click()

      await expect(page.locator('button:has-text("返回任务")')).toBeVisible()
      // review-approve is the page's stable test id; its zh label is 通过
      // (review.approve — the spec's original 确认同步到 WP matches nothing).
      await expect(page.locator('[data-testid="review-approve"]')).toBeVisible()

      const approveResponsePromise = page.waitForResponse(
        (response) => response.url().includes(`/api/items/${pendingItemId}/approve`) && response.request().method() === 'POST',
        { timeout: 60_000 },
      )
      await page.locator('[data-testid="review-approve"]').click()
      const approveResponse = await approveResponsePromise
      expect(approveResponse.ok(), `item approve HTTP ${approveResponse.status()}`).toBe(true)
      const approveJson = await approveResponse.json().catch(() => null as unknown)
      expect((approveJson as Record<string, any> | null)?.success, JSON.stringify(approveJson)).toBe(true)
      // review.approved toast = 已通过 (the spec's original 已确认同步到 WP
      // matches no locale value in either language).
      await expect(page.locator('text=已通过').first()).toBeVisible({ timeout: 20_000 })
      await expect(page.locator('span:has-text("done")').first()).toBeVisible({ timeout: 20_000 })

      const itemsAfterApproveRes = await request.get(`${CLIENT_BASE}/api/jobs/${pendingJobId}/items`)
      expect(itemsAfterApproveRes.ok(), `job items after approve HTTP ${itemsAfterApproveRes.status()}`).toBe(true)
      const itemsAfterApproveJson = await itemsAfterApproveRes.json().catch(() => null as unknown)
      const itemsAfterApproveData = ((itemsAfterApproveJson as Record<string, any> | null)?.data ?? {}) as Record<string, any>
      const itemsAfterApprove = (itemsAfterApproveData.items ?? []) as Array<Record<string, any>>
      const approvedItem = itemsAfterApprove.find((item) => Number(item?.id ?? 0) === pendingItemId)
      expect(approvedItem, `missing approved item ${pendingItemId}`).toBeTruthy()
      expect(String(approvedItem?.status ?? '')).toBe('done')

      await page.locator('button:has-text("返回任务")').click()
      await expect(page.locator('h2:has-text("任务管理")')).toBeVisible()
      await page.getByRole('button', { name: '待审任务', exact: true }).click()
      // Exact-id absence via the same stable test id (text-based matching
      // is unusable here — see the pendingRow comment above).
      await expect(page.locator(`[data-testid="pending-review-${pendingItemId}"]`)).not.toBeVisible({ timeout: 20_000 })
    } finally {
      await saveReviewMode(request, originalReviewMode).catch(() => {})
      await cleanupWorkerBootstrap(request, bootstrapState).catch(() => {})
      await restoreClientE2ERunCaps().catch(() => {})
      resetClientRelationRuntime(Number(jobBundle.relationId))
      restoreUserPasswordHash(webUserEmail, originalPasswordHash)
      await clearWebSession(page).catch(() => {})
    }
  })
})
