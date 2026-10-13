/**
 * Per-project Client UI touch (after first full pre-wp-bind).
 * Tasks bootstrap → Overview Run Once. No bindSite(request).
 */
import { expect, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { CLIENT_BASE, gotoNav, MOCK_API_BASE, overviewRunOnce } from './helpers'

test.describe.configure({ mode: 'serial' })

test('per-project Client UI: Discovery bootstrap + Run Once', async ({ page }) => {
  test.setTimeout(300_000)
  const projectId = process.env.FULL_CHAIN_PROJECT_ID || 'unknown'
  const reportDir =
    process.env.REPORT_DIR ||
    path.resolve(__dirname, '../../reports/content-plugin-full-chain')
  fs.mkdirSync(reportDir, { recursive: true })

  const health = await fetch(`${MOCK_API_BASE}/api/v1/health`, { signal: AbortSignal.timeout(5000) })
  expect(health.ok, 'mock health').toBeTruthy()
  const status = await fetch(`${CLIENT_BASE}/api/status`, { signal: AbortSignal.timeout(5000) })
  expect(status.ok, 'client status').toBeTruthy()

  const steps: Array<{ step: string; ok: boolean; detail?: string }> = []

  try {
    await gotoNav(page, /任务|Tasks/)
    await page.getByRole('button', { name: /任务配置|Discovery/ }).click()
    const boot = page.getByTestId('tasks-bootstrap-discovery')
    await expect(boot).toBeVisible({ timeout: 20_000 })
    await boot.click()
    steps.push({ step: 'tasks_bootstrap_discovery', ok: true })
  } catch (e) {
    steps.push({ step: 'tasks_bootstrap_discovery', ok: false, detail: String(e) })
    throw e
  }

  try {
    const run = await overviewRunOnce(page)
    steps.push({
      step: 'overview_run_once',
      ok: true,
      detail: run.finished ? 'completed' : 'started_ui_loading',
    })
  } catch (e) {
    steps.push({ step: 'overview_run_once', ok: false, detail: String(e) })
    throw e
  }

  // Assert-only: discovery tasks or jobs present after UI ops.
  const disc = (await (await fetch(`${CLIENT_BASE}/api/discovery-tasks`)).json()) as {
    data?: { items?: unknown[] }
  }
  const jobs = (await (await fetch(`${CLIENT_BASE}/api/jobs`)).json()) as {
    data?: { items?: unknown[] }
  }
  const discCount = disc.data?.items?.length ?? 0
  const jobsCount = jobs.data?.items?.length ?? 0
  steps.push({
    step: 'pipeline_counts',
    ok: discCount + jobsCount > 0,
    detail: `discovery=${discCount} jobs=${jobsCount}`,
  })

  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const out = path.join(reportDir, `per-project-ui-${projectId}-${stamp}.json`)
  const report = {
    task: 'per-project-client-ui-touch',
    project_id: projectId,
    client_base: CLIENT_BASE,
    discovery_tasks: discCount,
    jobs: jobsCount,
    steps,
    ok: steps.every((s) => s.ok),
  }
  fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`)
  console.log(`report: ${out}`)
  expect(report.ok, JSON.stringify(steps, null, 2)).toBe(true)
})
