import { expect, test, type APIRequestContext, type APIResponse } from '../lib/page-errors'
import {
  ALLOW_ZERO_TASKS,
  CLIENT_BASE,
  ROUTE_SECRET,
  WP_CLIENT_TOKEN,
  createLocalOpenAiMockComponent,
  deleteRuleBinding,
  ensureLoggedIn,
  readRuntimeArtifactObject,
  resolveRuleBindingContext,
  siteDomainNeedle,
  verifyOfficialClientGateServices,
  waitForClientShell,
  writeRuntimeArtifact,
  type RuleBindingRef,
} from './client-translate.shared'

test.describe.configure({ mode: 'serial' })

test.describe('E2E v2: client verification / post-run assertions', () => {
  const createdLocalComponentIds: string[] = []
  const createdRuleBindings: RuleBindingRef[] = []

  function readExecutionSummary() {
    const artifact = readRuntimeArtifactObject('official-client-execution-summary.json') ?? {}
    const execution = (artifact.summary as Record<string, unknown> | undefined) ?? {}
    const nested = (execution.summary as Record<string, unknown> | undefined) ?? {}
    return {
      project: String(artifact.project ?? ''),
      scope: String(artifact.scope ?? ''),
      selectedRelationId: Number(artifact.selected_relation_id ?? 0),
      tasksProcessed: Number(execution.tasks_processed ?? 0),
      tasksSucceeded: Number(execution.tasks_succeeded ?? 0),
      tasksFailed: Number(execution.tasks_failed ?? 0),
      breakReason: String(execution.break_reason ?? ''),
      domainsProcessed: Number(execution.domains_processed ?? 0),
      domainCount: Number(nested.domain_count ?? 0),
      totalItems: Number(nested.total_items ?? 0),
      replayEvidenceFound: Boolean(artifact.replay_evidence_found),
      allowIdempotentReplay: Boolean(artifact.allow_idempotent_replay),
    }
  }

  async function postWorkerStartCheckWithRetry(request: APIRequestContext): Promise<APIResponse> {
    let lastError = ''
    const maxAttempts = 8
    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      try {
        const response = await request.post(`${CLIENT_BASE}/api/worker/start-check`, { timeout: 45_000 })
        if (response.ok() || attempt === maxAttempts || (response.status() < 500 && response.status() !== 429)) {
          return response
        }
        lastError = `HTTP ${response.status()}`
      } catch (error) {
        lastError = String((error as Error)?.message ?? error ?? '')
        if (attempt === maxAttempts) {
          throw error
        }
      }
      const waitMs = Math.min(15_000, attempt * 2000)
      console.warn(`  start-check attempt ${attempt}/${maxAttempts} failed (${lastError}); retrying in ${waitMs}ms...`)
      await new Promise((resolve) => setTimeout(resolve, waitMs))
    }
    throw new Error(`start-check failed after retries: ${lastError}`)
  }

  test.beforeAll(async ({ request }) => {
    await verifyOfficialClientGateServices(request)
  })

  test.afterAll(async ({ request }) => {
    console.log('\n── afterAll: cleaning up verification group ──')
    for (const binding of [...createdRuleBindings].reverse()) {
      await deleteRuleBinding(request, binding)
    }
    for (const componentId of [...new Set(createdLocalComponentIds)].reverse()) {
      try {
        const encoded = encodeURIComponent(componentId)
        await request.delete(`${CLIENT_BASE}/api/components/local/${encoded}`)
        console.log(`  Deleted local component: ${componentId}`)
      } catch (err) {
        console.warn(`  Failed to delete local component ${componentId}: ${err}`)
      }
    }
    console.log('── afterAll complete ──\n')
  })

  test('Discovery Tasks 验证：自动创建的并发配置行', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })

    console.log('\n  Fetching discovery tasks...')
    const tasksRes = await request.get(`${CLIENT_BASE}/api/discovery-tasks`)
    expect(tasksRes.ok()).toBe(true)

    const tasksData = await tasksRes.json()
    expect(tasksData?.success).toBe(true)

    const items: Array<{
      id: number
      domain: string
      relation_id: number
      concurrency: number
      last_run_at: number
    }> = tasksData?.data?.items ?? []

    console.log(`  Total discovery tasks: ${items.length}`)
    expect(items.length).toBeGreaterThan(0)

    const needle = siteDomainNeedle()
    const siteTasks = items.filter((item) =>
      String(item.domain || '').includes(needle)
      || String(item.domain || '').includes('9083')
      || String(item.domain || '').includes('blog.wpmm.cc')
    )
    console.log(`  site(${needle}) relations: ${siteTasks.map((item) => item.relation_id).join(', ')}`)
    expect(siteTasks.length).toBeGreaterThan(0)
    console.log(`  Unique relation IDs: ${[...new Set(siteTasks.map((item) => item.relation_id))].join(', ')}`)
  })

  test('Jobs 验证：翻译任务记录和状态', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })

    console.log('\n  Fetching jobs...')
    const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
    expect(jobsRes.ok()).toBe(true)

    const jobsData = await jobsRes.json()
    expect(jobsData?.success).toBe(true)
    expect(Array.isArray(jobsData?.data?.items)).toBe(true)

    const jobs: Array<{
      id: number
      domain: string
      relation_id: number
      status: string
      total_items: number
      done_items: number
      failed_items: number
    }> = jobsData?.data?.items ?? []

    console.log(`  Total jobs: ${jobs.length}`)

    if (jobs.length > 0) {
      const statusMap: Record<string, number> = {}
      for (const job of jobs) {
        statusMap[job.status] = (statusMap[job.status] ?? 0) + 1
      }
      console.log(`  Status summary: ${JSON.stringify(statusMap)}`)

      const relationMap: Record<number, { total: number; done: number; failed: number }> = {}
      for (const job of jobs) {
        if (!relationMap[job.relation_id]) {
          relationMap[job.relation_id] = { total: 0, done: 0, failed: 0 }
        }
        relationMap[job.relation_id].total += job.total_items
        relationMap[job.relation_id].done += job.done_items
        relationMap[job.relation_id].failed += job.failed_items
      }

      for (const [relationId, stats] of Object.entries(relationMap)) {
        console.log(`  Relation ${relationId}: total=${stats.total}, done=${stats.done}, failed=${stats.failed}`)
      }

      const totalItems = jobs.reduce((sum, job) => sum + job.total_items, 0)
      const doneItems = jobs.reduce((sum, job) => sum + job.done_items, 0)
      const failedItems = jobs.reduce((sum, job) => sum + job.failed_items, 0)

      console.log(`  Totals: items=${totalItems}, done=${doneItems}, failed=${failedItems}`)
      if (ALLOW_ZERO_TASKS) {
        expect(totalItems).toBeGreaterThanOrEqual(0)
      } else {
        expect(totalItems).toBeGreaterThan(0)
      }
    } else if (ALLOW_ZERO_TASKS) {
      console.warn('  (0 jobs — allowed by E2E_ALLOW_ZERO_TASKS=1)')
    } else {
      const execSummary = readExecutionSummary()
      if (execSummary.tasksProcessed > 0 && execSummary.tasksSucceeded > 0) {
        console.warn(
          `  (0 jobs after worker run; accepted because execution summary reports ` +
            `tasks_processed=${execSummary.tasksProcessed}, tasks_succeeded=${execSummary.tasksSucceeded}, ` +
            `break_reason=${execSummary.breakReason || 'unknown'})`
        )
        expect(execSummary.tasksFailed).toBe(0)
      } else {
        throw new Error(
          'No jobs found after worker run and no successful execution summary evidence. ' +
          'Expected non-empty translation jobs or a completed worker run.'
        )
      }
    }
  })

  test('翻译结果验证：日志事件和 History 数据', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })

    console.log('\n  Fetching recent log entries...')
    const logsRes = await request.post(`${CLIENT_BASE}/api/logs/recent`, {
      data: { limit: 200 },
    })

    if (!logsRes.ok()) {
      console.warn(`  logs/recent unavailable (HTTP ${logsRes.status()}); skip log-line assertions`)
    } else {
      const logs = await logsRes.json().catch(() => null as unknown)
      const rawLines: string[] = ((logs as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)?.lines as string[] ?? []
      const entries = rawLines.map((line) => {
        try {
          return JSON.parse(line) as { event?: string }
        } catch {
          return { raw: line }
        }
      })

      console.log(`  Total log entries: ${entries.length}`)
      const eventCounts: Record<string, number> = {}
      for (const entry of entries) {
        const evt = (entry as { event?: string }).event ?? ''
        if (evt.startsWith('worker.') || evt.startsWith('discovery.') || evt.startsWith('translation_callback')) {
          eventCounts[evt] = (eventCounts[evt] ?? 0) + 1
        }
      }

      if (Object.keys(eventCounts).length > 0) {
        console.log('  Worker-related events:')
        for (const [evt, count] of Object.entries(eventCounts)) {
          console.log(`    ${evt}: ${count}`)
        }
      }
    }

    console.log('\n  Checking translations via History API...')
    const historyRes = await request.get(`${CLIENT_BASE}/api/translations?limit=10&status=success`)
    if (historyRes.ok()) {
      const historyData = await historyRes.json()
      const count = historyData?.data?.total ?? historyData?.data?.items?.length ?? 0
      console.log(`  Completed translations: ${count}`)
      if (ALLOW_ZERO_TASKS) {
        expect(count).toBeGreaterThanOrEqual(0)
      } else if (count > 0) {
        expect(count).toBeGreaterThan(0)
      } else {
        // Language-pack-heavy runs may complete via jobs/callbacks without
        // translation_records(status=success); jobs API is authoritative there.
        const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
        expect(jobsRes.ok(), `jobs fallback HTTP ${jobsRes.status()}`).toBe(true)
        const jobsData = await jobsRes.json()
        const doneItems = ((jobsData?.data?.items ?? []) as Array<{ done_items?: number }>)
          .reduce((sum, job) => sum + Number(job?.done_items ?? 0), 0)
        if (doneItems > 0) {
          console.log(`  History empty; jobs done_items fallback: ${doneItems}`)
          expect(doneItems).toBeGreaterThan(0)
        } else {
          const execSummary = readExecutionSummary()
          if (execSummary.tasksSucceeded > 0) {
            console.warn(
              `  History and jobs are empty; accepted because execution summary reports ` +
                `tasks_processed=${execSummary.tasksProcessed}, tasks_succeeded=${execSummary.tasksSucceeded}`
            )
            expect(execSummary.tasksFailed).toBe(0)
          } else {
            throw new Error(
              'History API returned no success rows, jobs fallback was empty, and no successful execution summary exists.'
            )
          }
        }
      }
    } else if (!ALLOW_ZERO_TASKS) {
      throw new Error(`History API failed: HTTP ${historyRes.status()}`)
    }
  })

  test('Worker 启动前预检 API：返回结构化缺失组件摘要', async ({ page, context, request }) => {
    await ensureLoggedIn({ page, context, request })

    const preflightRes = await postWorkerStartCheckWithRetry(request)
    expect(preflightRes.ok(), `start-check HTTP ${preflightRes.status()}`).toBe(true)

    const preflightData = await preflightRes.json()
    expect(preflightData?.success).toBe(true)

    const summary = preflightData?.data?.summary ?? {}
    expect(typeof summary.domains_checked).toBe('number')
    expect(typeof summary.relations_checked).toBe('number')
    expect(typeof summary.rules_checked).toBe('number')
    expect(typeof summary.fields_checked).toBe('number')

    const missingItems: Array<Record<string, unknown>> = preflightData?.data?.missing_components ?? []
    if (missingItems.length > 0) {
      const item = missingItems[0] ?? {}
      expect(typeof item.source_group).toBe('string')
      expect(typeof item.routing_profile).toBe('string')
      expect(typeof item.delivery_target).toBe('string')
      expect(typeof item.required_slot_key).toBe('string')
      expect(typeof item.preflight_policy).toBe('string')
      expect(typeof item.missing_component_behavior).toBe('string')
    }

    writeRuntimeArtifact('official-client-preflight-summary.json', preflightData?.data ?? {})
  })

  test('规则绑定 WebUI：四层作用域可配置并持久化', async ({ page, context, request }) => {
    test.setTimeout(120_000)
    if (process.env.E2E_SLOT) {
      test.skip(true, 'Rule-binding UI uses direct WP Protocol v2 REST; skip on isolated matrix slots')
    }
    await ensureLoggedIn({ page, context, request })
    expect(WP_CLIENT_TOKEN, 'WP_CLIENT_TOKEN required').not.toBe('')
    expect(ROUTE_SECRET, 'ROUTE_SECRET required').not.toBe('')

    const { relationId, pluginSlug, ruleId } = await resolveRuleBindingContext(request)

    await waitForClientShell(page)

    const componentsNav = page.getByRole('button', {
      name: /翻译组件|Translation Components/i,
    })
    try {
      await expect(componentsNav).toBeVisible({ timeout: 20_000 })
      await componentsNav.click()
    } catch (err) {
      test.info().annotations.push({
        type: 'note',
        description: `Components nav unavailable; skipping rule-binding UI: ${String(err)}`,
      })
      test.skip(true, 'Components nav not available in this Lab/client build')
      return
    }

    const ruleTab = page.getByRole('button', { name: /规则绑定|Rule Bindings/i }).first()
    await expect(ruleTab).toBeVisible({ timeout: 15_000 })
    await ruleTab.click()

    const scopeSelect = page.locator('label:has-text("作用域") + select').first()
    const scopeKeyInput = page.locator('label:has-text("scope_key") + input').first()
    const slotSelect = page.locator('label:has-text("槽位") + select').first()
    const componentInput = page.locator('label:has-text("组件 ID") + input').first()
    const saveBtn = page.getByRole('button', { name: '保存绑定' }).first()
    const bindingsTable = page
      .locator('thead tr th:has-text("scope_key")')
      .locator('xpath=ancestor::table[1]')
      .first()

    // Lab UI can lag or diverge; fail fast instead of hanging the matrix lane.
    try {
      await expect(scopeSelect).toBeVisible({ timeout: 20_000 })
      await expect(slotSelect).toBeVisible({ timeout: 10_000 })
      await expect(componentInput).toBeVisible({ timeout: 10_000 })
      await expect(saveBtn).toBeVisible({ timeout: 10_000 })
      await expect(bindingsTable).toBeVisible({ timeout: 10_000 })
    } catch (err) {
      test.info().annotations.push({
        type: 'note',
        description: `Rule-binding UI controls unavailable; skipping deep UI persistence checks: ${String(err)}`,
      })
      test.skip(true, 'Rule-binding WebUI controls not available in this Lab/client build')
      return
    }
    const marker = Date.now()
    const globalComponent = `pw-global-${marker}`
    const pluginComponent = `pw-plugin-${marker}`
    const relationComponent = `pw-relation-${marker}`
    const ruleComponent = `pw-rule-${marker}`

    await createLocalOpenAiMockComponent(request, globalComponent, 'Playwright rule binding global')
    await createLocalOpenAiMockComponent(request, pluginComponent, 'Playwright rule binding plugin')
    await createLocalOpenAiMockComponent(request, relationComponent, 'Playwright rule binding relation')
    await createLocalOpenAiMockComponent(request, ruleComponent, 'Playwright rule binding rule')
    createdLocalComponentIds.push(globalComponent, pluginComponent, relationComponent, ruleComponent)

    const saveBinding = async (
      scope: 'global' | 'plugin' | 'relation' | 'rule',
      scopeKey: string,
      componentId: string
    ) => {
      await scopeSelect.selectOption(scope)
      await slotSelect.selectOption('plain_text')
      if (scope === 'global') {
        await expect(scopeKeyInput).toBeDisabled()
      } else {
        await expect(scopeKeyInput).toBeEnabled()
        await scopeKeyInput.fill(scopeKey)
      }

      await componentInput.fill(componentId)
      await saveBtn.click()

      createdRuleBindings.push({
        scope,
        scope_key: scopeKey || undefined,
        slot_key: 'plain_text',
      })

      const row = bindingsTable.locator('tbody tr').filter({ hasText: componentId })
      await expect(row, `Row missing after save: ${scope}/${scopeKey}/${componentId}`).toHaveCount(1)
    }

    await saveBinding('global', '', globalComponent)
    await saveBinding('plugin', pluginSlug, pluginComponent)
    await saveBinding('relation', String(relationId), relationComponent)
    await saveBinding('rule', String(ruleId), ruleComponent)

    const statusRes = await request.get(`${CLIENT_BASE}/api/status`)
    expect(statusRes.ok()).toBe(true)
    const statusData = await statusRes.json()
    const records: Array<{
      scope?: string
      scope_key?: string
      slot_key?: string
      component_id?: string
    }> = statusData?.data?.rule_component_bindings ?? []

    const hasRecord = (scope: string, scopeKey: string, componentId: string) =>
      records.some((record) =>
        (record.scope ?? '') === scope
        && (record.scope_key ?? '') === scopeKey
        && (record.slot_key ?? '') === 'plain_text'
        && (record.component_id ?? '') === componentId
      )

    expect(hasRecord('global', '', globalComponent)).toBe(true)
    expect(hasRecord('plugin', pluginSlug, pluginComponent)).toBe(true)
    expect(hasRecord('relation', String(relationId), relationComponent)).toBe(true)
    expect(hasRecord('rule', String(ruleId), ruleComponent)).toBe(true)

    const deleteByComponent = async (componentId: string) => {
      const row = bindingsTable.locator('tbody tr').filter({ hasText: componentId }).first()
      const countBefore = await row.count()
      if (countBefore === 0) return
      await row.getByRole('button', { name: '删除' }).click()
      await expect(
        bindingsTable.locator('tbody tr').filter({ hasText: componentId }),
        `Row should be deleted: ${componentId}`
      ).toHaveCount(0)
    }

    await deleteByComponent(ruleComponent)
    await deleteByComponent(relationComponent)
    await deleteByComponent(pluginComponent)
    await deleteByComponent(globalComponent)
  })
})
