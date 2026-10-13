/**
 * Journey: Sites → (provider wizard) → rule bind → Overview Run Once
 * All form fills via UI. Credentials from PRE_WP_SITE_FIXTURE only.
 */
import { expect, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import {
  CLIENT_BASE,
  fillSitesManualAndTest,
  loadSiteFixture,
  MOCK_API_BASE,
  overviewRunOnce,
  runProviderWizardOnce,
  saveGlobalPlainTextBinding,
} from './helpers'

test.describe.configure({ mode: 'serial' })

test('pre-WP Client UI: Sites + provider + rule bind + Run Once', async ({ page }) => {
  const health = await fetch(`${MOCK_API_BASE}/api/v1/health`, { signal: AbortSignal.timeout(5000) })
  expect(health.ok, 'mock health').toBeTruthy()
  const status = await fetch(`${CLIENT_BASE}/api/status`, { signal: AbortSignal.timeout(5000) })
  expect(status.ok, 'client status').toBeTruthy()

  const site = loadSiteFixture()
  const reportDir =
    process.env.REPORT_DIR ||
    path.resolve(__dirname, '../../reports/client-ui-setup/pre-wp-bind')
  fs.mkdirSync(reportDir, { recursive: true })

  const steps: Array<{ step: string; ok: boolean; detail?: string }> = []

  try {
    await fillSitesManualAndTest(page, site)
    steps.push({ step: 'sites_bind_and_test', ok: true })
  } catch (e) {
    steps.push({ step: 'sites_bind_and_test', ok: false, detail: String(e) })
    throw e
  }

  let componentId = process.env.PRE_WP_COMPONENT_ID || site.component_id_hint || ''
  if (process.env.SKIP_PROVIDER !== '1') {
    try {
      componentId = await runProviderWizardOnce(page, 'openai-compatible')
      steps.push({ step: 'provider_wizard', ok: true, detail: componentId })
    } catch (e) {
      steps.push({ step: 'provider_wizard', ok: false, detail: String(e) })
      throw e
    }
  } else {
    expect(componentId, 'PRE_WP_COMPONENT_ID or component_id_hint required when SKIP_PROVIDER=1').toBeTruthy()
    steps.push({ step: 'provider_wizard', ok: true, detail: `skipped:${componentId}` })
  }

  try {
    await saveGlobalPlainTextBinding(page, componentId)
    steps.push({ step: 'rule_bind_plain_text', ok: true, detail: componentId })
  } catch (e) {
    steps.push({ step: 'rule_bind_plain_text', ok: false, detail: String(e) })
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

  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const out = path.join(reportDir, `pre-wp-bind-${stamp}.json`)
  fs.writeFileSync(
    out,
    `${JSON.stringify(
      {
        task: 'pre-wp-bind-ui-journey',
        client_base: CLIENT_BASE,
        wp_url: site.api_base_url,
        component_id: componentId,
        headed: process.env.HEADED === '1',
        steps,
      },
      null,
      2,
    )}\n`,
  )
  console.log(`report: ${out}`)
})
