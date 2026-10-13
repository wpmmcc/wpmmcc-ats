/**
 * Shared helpers for client-ui-setup Playwright journeys.
 * Fill forms via UI only — never POST domain-tokens / rule-bindings from tests.
 */
import { expect, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { expectApi } from '../lib/expect-api'

export const CLIENT_BASE =
  process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL || process.env.WEBUI_A_BASE || process.env.CLIENT_BASE || 'http://127.0.0.1:8977'
export const MOCK_API_BASE = process.env.MOCK_API_BASE || 'http://127.0.0.1:9090'

export type SiteFixture = {
  api_base_url: string
  wp_client_token: string
  route_secret: string
  component_id_hint?: string
  container?: string
}

export function loadSiteFixture(): SiteFixture {
  const p =
    process.env.PRE_WP_SITE_FIXTURE ||
    path.resolve(__dirname, '../../client-ui-setup/pre-wp-bind/fixtures/site.local.json')
  if (!fs.existsSync(p)) {
    throw new Error(`Missing site fixture: ${p} (run export-site-fixture.sh)`)
  }
  const raw = JSON.parse(fs.readFileSync(p, 'utf8')) as SiteFixture
  if (!raw.api_base_url || !raw.wp_client_token || !raw.route_secret) {
    throw new Error(`Incomplete site fixture: ${p}`)
  }
  if (raw.wp_client_token.includes('REPLACE')) {
    throw new Error(`Fixture still has placeholder token: ${p}`)
  }
  return raw
}

export async function gotoNav(page: Page, name: RegExp) {
  await page.goto(CLIENT_BASE)
  await page.getByRole('button', { name }).click()
}

export async function fillSitesManualAndTest(page: Page, site: SiteFixture) {
  await gotoNav(page, /Sites|站点/)
  await expect(page.getByTestId('sites-add-site')).toBeVisible({ timeout: 20_000 })

  await page.getByTestId('sites-add-site').click()
  await expect(page.getByTestId('sites-modal-url')).toBeVisible()
  await page.getByTestId('sites-modal-url').fill(site.api_base_url)
  await page.getByTestId('sites-modal-token').fill(site.wp_client_token)
  await page.getByTestId('sites-modal-route-secret').fill(site.route_secret)

  const upsertPending = expectApi(page, {
    path: '/api/domain-tokens/upsert',
    method: 'POST',
    requestSchema: 'domain-tokens-upsert.request',
    responseSchema: 'domain-tokens-upsert.response',
    status: 200,
  })
  await page.getByTestId('sites-modal-save').click()
  const upsert = await upsertPending
  const upsertReq = upsert.requestJson as { api_base_url?: string; wp_client_token?: string }
  expect(String(upsertReq.api_base_url || '').length).toBeGreaterThan(0)
  expect(String(upsertReq.wp_client_token || '')).toBe(site.wp_client_token)

  await expect(
    page
      .locator('td')
      .filter({ hasText: site.api_base_url.replace(/\/$/, '') })
      .or(page.locator('td').filter({ hasText: site.api_base_url }))
      .first(),
  ).toBeVisible({ timeout: 20_000 })

  const row = page
    .locator('tr')
    .filter({ hasText: site.api_base_url.replace(/\/$/, '') })
    .or(page.locator('tr').filter({ hasText: site.api_base_url }))
    .first()

  const testPending = expectApi(page, {
    path: '/api/domain-tokens/test',
    method: 'POST',
    responseSchema: 'domain-tokens-test.response',
    status: 200,
    timeoutMs: 90_000,
  })
  await row.getByTestId('sites-test-connection').click()
  await testPending

  await expect(
    page
      .getByText(/Connection OK|连接成功|connection_ok|Token Saved|Token 已保存/i)
      .or(
        page
          .locator('[class*="toast"], [role="status"]')
          .filter({ hasText: /OK|成功|Connected|通/i }),
      )
      .first(),
  ).toBeVisible({ timeout: 60_000 })
  await expect(row.getByTestId('sites-test-connection')).toBeEnabled({ timeout: 10_000 })
}

export async function runProviderWizardOnce(page: Page, entryId = 'openai-compatible') {
  await gotoNav(page, /API Keys|API 密钥|密钥/)
  await expect(page.getByTestId('open-provider-wizard').first()).toBeVisible({ timeout: 25_000 })

  const search = page.getByLabel(/search|搜索/i).first()
  if (await search.count()) {
    // Exact entry_id — substring "openai-compatible" also hits Anthropic OpenAI-compatible.
    await search.fill(entryId)
    await search.press('Enter')
    await page.waitForTimeout(400)
  }

  // Prefer stable testid (entry_id). Fall back to exact template_id prefix so we never
  // click Anthropic OpenAI-compatible when asking for openai-compatible.
  const byTestId = page.getByTestId(`catalog-entry-${entryId}`)
  const escaped = entryId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const byTemplate = page
    .locator('tr')
    .filter({
      has: page.locator('td div.font-mono', {
        hasText: new RegExp(`^${escaped}(-|$)`),
      }),
    })
    .first()
  const row = (await byTestId.count()) ? byTestId.first() : byTemplate
  await expect(row).toBeVisible({ timeout: 25_000 })
  await row.getByTestId('open-provider-wizard').click()
  await expect(page.getByTestId('provider-setup-wizard')).toBeVisible()

  const stamp = Date.now().toString(36)
  const localId = `prewp-${entryId}-${stamp}`.slice(0, 100)
  await page.getByTestId('wizard-local-id').fill(localId)
  await page.getByTestId('wizard-install-next').click()
  await expect(page.getByTestId('wizard-api-key')).toBeVisible({ timeout: 20_000 })
  await page.getByTestId('wizard-key-id').fill(`${localId}-k`.slice(0, 100))
  await page.getByTestId('wizard-api-key').fill('mock-translate-dev-key-2026')
  await page.getByTestId('wizard-request-url').fill(`${MOCK_API_BASE}/v1/chat/completions`)
  if (await page.getByTestId('wizard-model').count()) {
    await page.getByTestId('wizard-model').fill('mock-openai-v1')
  }
  await page.getByTestId('wizard-key-next').click()
  await expect(page.getByTestId('wizard-test-next')).toBeVisible({ timeout: 15_000 })
  await page.getByTestId('wizard-test-text').fill('Hello world')
  await page.getByTestId('wizard-test-next').click()
  await expect(page.getByTestId('wizard-enable-next')).toBeVisible({ timeout: 60_000 })
  await page.getByTestId('wizard-enable-next').click()
  await page.getByTestId('wizard-route-next').click()
  await expect(page.getByTestId('wizard-done')).toBeVisible({ timeout: 15_000 })
  const close = page.getByRole('button', { name: /Close|关闭|取消/ }).first()
  if (await close.isVisible().catch(() => false)) await close.click()
  else await page.keyboard.press('Escape')
  return localId
}

export async function saveGlobalPlainTextBinding(page: Page, componentId: string) {
  await saveGlobalSlotBindings(page, componentId, ['plain_text'])
}

/** Bind common content-format slots so worker preflight is not blocking. */
export async function saveGlobalSlotBindings(
  page: Page,
  componentId: string,
  slots: string[] = [
    'plain_text',
    'rich_html',
    'json_structured',
    'serialized_php',
    'media_ref',
    'slug',
  ],
) {
  await gotoNav(page, /Components|翻译组件|组件/)
  await page.getByTestId('components-tab-tasktype').click()
  await expect(page.getByTestId('rule-bind-save')).toBeVisible({ timeout: 15_000 })

  for (const slot of slots) {
    await page.getByTestId('rule-bind-scope').selectOption('global')
    await page.getByTestId('rule-bind-slot-key').selectOption(slot)
    await page.getByTestId('rule-bind-component-id').fill(componentId)

    const bindPending = expectApi(page, {
      path: '/api/rule-component-bindings/upsert',
      method: 'POST',
      responseSchema: 'rule-component-bindings-upsert.response',
      status: 200,
    })
    await page.getByTestId('rule-bind-save').click()
    const bind = await bindPending
    const data = (bind.responseJson as { data?: { component_id?: string; slot_key?: string } }).data
    expect(data?.component_id).toBe(componentId)
    expect(data?.slot_key).toBe(slot)
  }
}

export async function overviewRunOnce(page: Page) {
  await gotoNav(page, /Overview|概览/)
  const btn = page.getByTestId('overview-run-once')
  await expect(btn).toBeVisible({ timeout: 15_000 })
  await expect(btn).toBeEnabled()

  const runPending = page.waitForResponse(
    (r) =>
      r.url().includes('/api/worker/run-once') && r.request().method() === 'POST',
    { timeout: 600_000 },
  )
  await btn.click()

  const continueBtn = page.getByTestId('overview-preflight-continue')
  const cancelBtn = page.getByTestId('overview-preflight-cancel')

  const outcome = await Promise.race([
    cancelBtn
      .or(continueBtn)
      .first()
      .waitFor({ state: 'visible', timeout: 120_000 })
      .then(() => 'modal' as const),
    runPending.then(() => 'ran' as const),
  ]).catch(() => 'timeout' as const)

  if (outcome === 'timeout') {
    throw new Error('overview Run Once: neither preflight modal nor /api/worker/run-once within 120s')
  }

  if (outcome === 'modal') {
    if (await continueBtn.isVisible().catch(() => false)) {
      await continueBtn.click()
    } else {
      const detail = await page
        .locator('body')
        .innerText()
        .then((t) => t.replace(/\s+/g, ' ').trim().slice(0, 500))
        .catch(() => 'preflight modal')
      throw new Error(
        `worker preflight blocked (no Continue). Bind missing content-format slots. detail=${detail}`,
      )
    }
  }

  // Jobs may appear before the long-running run-once HTTP response finishes.
  await expect
    .poll(
      async () => {
        const j = (await (
          await fetch(`${CLIENT_BASE}/api/jobs`, { signal: AbortSignal.timeout(8_000) })
        ).json()) as { data?: { items?: unknown[]; jobs?: unknown[] } }
        return (j.data?.items ?? j.data?.jobs ?? []).length
      },
      { timeout: 180_000 },
    )
    .toBeGreaterThan(0)

  // Best-effort wait for the HTTP response; do not fail if still running.
  const api = await runPending.catch(() => null)
  const finished = await btn
    .isEnabled({ timeout: 30_000 })
    .then(() => true)
    .catch(() => false)
  return { finished, api }
}

/** Remove non-ATS sites after topology proof so worker preflight is not blocked by WPMMCC 404s. */
export async function removeSitesByUrlSubstring(page: Page, needles: string[]) {
  await gotoNav(page, /Sites|站点/)
  for (const needle of needles) {
    for (let guard = 0; guard < 5; guard++) {
      const row = page.locator('tr').filter({ hasText: needle }).first()
      if (!(await row.count()) || !(await row.isVisible().catch(() => false))) break
      const del = row.getByRole('button', { name: /Delete|删除/i }).first()
      if (!(await del.count())) break
      await del.click()
      await page.waitForTimeout(800)
    }
  }
}

/**
 * After Discovery bootstrap: bind component + enable tasks via UI (not API).
 * Returns how many tasks were enabled.
 */
export async function enableDiscoveryTasksViaUi(
  page: Page,
  componentId: string,
  opts?: { max?: number },
): Promise<number> {
  const max = opts?.max ?? 20
  await gotoNav(page, /任务|Tasks/)
  await page.getByRole('button', { name: /任务配置|Discovery/ }).click()

  await expect
    .poll(
      async () => {
        const j = (await (
          await fetch(`${CLIENT_BASE}/api/discovery-tasks`, { signal: AbortSignal.timeout(8_000) })
        ).json()) as { data?: { items?: unknown[] } }
        return (j.data?.items ?? []).length
      },
      { timeout: 60_000 },
    )
    .toBeGreaterThan(0)

  await expect(page.locator('[data-testid^="discovery-toggle-enabled-"]').first()).toBeVisible({
    timeout: 60_000,
  })

  // Snapshot ids first — editing a row re-renders the table and breaks nth() indices.
  const taskIds = await page
    .locator('[data-testid^="discovery-toggle-enabled-"]')
    .evaluateAll((els) =>
      els
        .map((el) => el.getAttribute('data-testid') || '')
        .map((id) => id.replace(/^discovery-toggle-enabled-/, ''))
        .filter((id) => /^\d+$/.test(id)),
    )
  const targets = taskIds.slice(0, max)
  let touched = 0

  for (const taskId of targets) {
    const toggle = page.getByTestId(`discovery-toggle-enabled-${taskId}`)
    await expect(toggle).toBeVisible({ timeout: 10_000 })
    const row = page.locator('tr').filter({ has: toggle }).first()

    const editComp = row
      .getByTitle(/编辑组件|Click Edit Component|click_edit_component/i)
      .or(row.locator('button').filter({ hasText: /默认路由|Default Route/i }))
      .or(row.locator('td').nth(7).locator('button').first())
      .first()
    await editComp.click()
    const select = page.locator(`#discovery-${taskId}-selected_component_id`)
    await expect(select).toBeVisible({ timeout: 10_000 })
    await select.selectOption(componentId)
    // Wait for PUT to land before next row.
    await page
      .waitForResponse(
        (r) =>
          r.url().includes(`/api/discovery-tasks/${taskId}`) &&
          (r.request().method() === 'PUT' || r.request().method() === 'PATCH'),
        { timeout: 15_000 },
      )
      .catch(() => null)
    await page.waitForTimeout(300)

    const label = ((await toggle.innerText()) || '').trim()
    if (/未启用|Disabled/i.test(label)) {
      await toggle.click()
      await page
        .waitForResponse(
          (r) =>
            r.url().includes(`/api/discovery-tasks/${taskId}`) &&
            (r.request().method() === 'PUT' || r.request().method() === 'PATCH'),
          { timeout: 15_000 },
        )
        .catch(() => null)
      await page.waitForTimeout(200)
    }
    touched += 1
  }

  await expect
    .poll(
      async () => {
        const disc = (await (
          await fetch(`${CLIENT_BASE}/api/discovery-tasks`, { signal: AbortSignal.timeout(10_000) })
        ).json()) as {
          data?: { items?: Array<{ enabled?: boolean; selected_component_id?: string }> }
        }
        const items = disc.data?.items ?? []
        return items.filter((t) => t.enabled && t.selected_component_id === componentId).length
      },
      { timeout: 30_000 },
    )
    .toBeGreaterThan(0)

  return touched
}
