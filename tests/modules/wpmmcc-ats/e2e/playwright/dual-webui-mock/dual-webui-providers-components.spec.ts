/**
 * Independent E2E: dual Client WebUI agents ↔ mock-api.
 *
 * For each of WEBUI_A_BASE + WEBUI_B_BASE:
 *   catalog install → vendor key → quick-test(mock) → enable →
 *   plain_text bind → list → run-once → UI smoke
 *
 * Families: openai-compatible, youdao (signed), deepl, google-translate.
 * Policy: local mock only — no live vendor / 官网.
 *
 * Runner: bash tests/modules/wpmmcc-ats/e2e/run-dual-webui-mock-providers.sh
 */

import { expect, test } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'

const MOCK_API_BASE = process.env.MOCK_API_BASE || 'http://127.0.0.1:9090'
const WEBUI_A = process.env.WEBUI_A_BASE || 'http://127.0.0.1:8977'
const WEBUI_B = process.env.WEBUI_B_BASE || 'http://127.0.0.1:8978'
const REPORT_DIR =
  process.env.REPORT_DIR ||
  path.resolve(__dirname, '../../reports/dual-webui-mock')

const WEBUIS = [
  { label: 'webui-a', base: WEBUI_A },
  { label: 'webui-b', base: WEBUI_B },
] as const

type Family = {
  id: string
  entry_id: string
  vendor_id: string
  auth_values: Record<string, string>
  config_overrides: Record<string, string>
  source_lang: string
  target_lang: string
  extra?: Record<string, unknown>
}

const FAMILIES: Family[] = [
  {
    id: 'openai',
    entry_id: 'openai-compatible',
    vendor_id: 'openai',
    auth_values: { api_key: 'mock-translate-dev-key-2026' },
    config_overrides: {
      'request.url': `${MOCK_API_BASE}/v1/chat/completions`,
      'request.body.model': 'mock-openai-v1',
    },
    source_lang: 'en_US',
    target_lang: 'zh_CN',
    extra: { api_key: 'mock-translate-dev-key-2026' },
  },
  {
    id: 'youdao',
    entry_id: 'youdao',
    vendor_id: 'youdao',
    auth_values: {
      app_key: 'mock-appkey-youdao',
      app_secret: 'mock-secret-youdao',
    },
    config_overrides: {
      'request.url': `${MOCK_API_BASE}/api/youdao/translate`,
      'response.translated_text_path': 'translated_text',
    },
    source_lang: 'en',
    target_lang: 'zh',
  },
  {
    id: 'deepl',
    entry_id: 'deepl',
    vendor_id: 'deepl',
    auth_values: { api_key: 'mock-deepl-auth-key' },
    config_overrides: {
      'request.url': `${MOCK_API_BASE}/v2/translate`,
    },
    source_lang: 'EN',
    target_lang: 'ZH',
  },
  {
    id: 'google',
    entry_id: 'google-translate',
    vendor_id: 'google_translate',
    auth_values: { api_key: 'mock-google-api-key' },
    config_overrides: {
      'request.url': `${MOCK_API_BASE}/language/translate/v2`,
    },
    source_lang: 'en',
    target_lang: 'zh',
  },
]

test.describe.configure({ mode: 'serial' })

async function assertReachable(url: string, label: string) {
  try {
    const r = await fetch(url, { signal: AbortSignal.timeout(5000) })
    if (!r.ok) throw new Error(`status ${r.status}`)
  } catch (err) {
    throw new Error(`${label} not reachable at ${url}: ${err}`)
  }
}

async function jsonFetch(url: string, init?: RequestInit) {
  const r = await fetch(url, init)
  const body = await r.json().catch(() => ({}))
  return { status: r.status, body }
}

async function proveOneWebui(
  label: string,
  base: string,
  stamp: string,
): Promise<Record<string, unknown>> {
  await assertReachable(`${base}/api/status`, label)

  const familyResults: Record<string, unknown> = {}
  let bindComponentId = ''

  for (const fam of FAMILIES) {
    const compId = `dual-${label}-${fam.id}-${stamp}`
    const keyId = `${compId}-key`
    if (!bindComponentId) bindComponentId = compId

    const install = await jsonFetch(`${base}/api/components/local/install-from-catalog`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        entry_id: fam.entry_id,
        local_id: compId,
        name: `Dual ${label} ${fam.id}`,
      }),
    })
    expect(install.body.success, `${label}/${fam.id} install: ${JSON.stringify(install.body)}`).toBeTruthy()

    const key = await jsonFetch(`${base}/api/vendor-keys`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: keyId,
        vendor_id: fam.vendor_id,
        label: `dual-${fam.id}`,
        auth_values: fam.auth_values,
        enabled: true,
      }),
    })
    expect(key.body.success, `${label}/${fam.id} key: ${JSON.stringify(key.body)}`).toBeTruthy()

    await fetch(`${base}/api/components/local/${encodeURIComponent(compId)}/versions`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ version: 'v1', key_ids: [keyId], auth_type: 'key' }),
    }).catch(() => null)

    const qtPayload: Record<string, unknown> = {
      auth_values: fam.auth_values,
      config_overrides: fam.config_overrides,
      text: 'Hello world',
      source_lang: fam.source_lang,
      target_lang: fam.target_lang,
      ...(fam.extra || {}),
    }
    const qt = await jsonFetch(
      `${base}/api/components/local/${encodeURIComponent(compId)}/quick-test`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(qtPayload),
      },
    )
    const qtText = qt.body?.data?.translated_text
    expect(
      qt.body.success,
      `${label}/${fam.id} quick-test: ${JSON.stringify(qt.body)}`,
    ).toBeTruthy()
    expect(
      qtText,
      `${label}/${fam.id} empty translate (SSRF?). Set WPTSALL_PROVIDER_ALLOWLIST=127.0.0.1,localhost — ${JSON.stringify(qt.body)}`,
    ).toBeTruthy()

    familyResults[fam.id] = {
      component_id: compId,
      success: true,
      translated_preview: String(qtText).slice(0, 80),
    }
  }

  await fetch(`${base}/api/components/local/${encodeURIComponent(bindComponentId)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ enabled: true }),
  })

  const bind = await jsonFetch(`${base}/api/rule-component-bindings/upsert`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      scope: 'global',
      slot_key: 'plain_text',
      component_id: bindComponentId,
    }),
  })
  expect(bind.body.success, `${label} bind: ${JSON.stringify(bind.body)}`).toBeTruthy()

  const got = await jsonFetch(`${base}/api/components/local/${encodeURIComponent(bindComponentId)}`)
  const gotId = String(got.body?.data?.id || got.body?.id || '')
  expect(
    got.status < 400 && (gotId === bindComponentId || JSON.stringify(got.body).includes(bindComponentId)),
    `${label} get-by-id missing ${bindComponentId}: ${JSON.stringify(got.body)}`,
  ).toBeTruthy()

  const run = await jsonFetch(`${base}/api/worker/run-once`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      max_iterations: 1,
      max_elapsed_secs: 60,
      max_items_per_run: 1,
    }),
  })
  expect(run.body.success, `${label} run-once: ${JSON.stringify(run.body)}`).toBeTruthy()

  return {
    label,
    base,
    bind_component_id: bindComponentId,
    families: familyResults,
    bind: true,
    run_once: true,
  }
}

test('dual webui: providers + component bind against mock', async ({ page }) => {
  await assertReachable(`${MOCK_API_BASE}/api/v1/health`, 'mock-api')

  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const perWebui: Record<string, unknown>[] = []

  for (const w of WEBUIS) {
    perWebui.push(await proveOneWebui(w.label, w.base, stamp))
  }

  // UI smoke on both agents: open Providers catalog and click first shared wizard control
  for (const w of WEBUIS) {
    await page.goto(`${w.base}/`)
    await expect(page.locator('body')).toBeVisible()
    const providers = page.getByRole('button', { name: /API Keys|密钥|Providers|厂商/i }).first()
    if (await providers.count()) {
      await providers.click()
      const openWizard = page.getByTestId('open-provider-wizard').first()
      if (await openWizard.count()) {
        await openWizard.click()
        await expect(page.getByTestId('provider-setup-wizard')).toBeVisible({ timeout: 15_000 })
        const close = page.getByTestId('wizard-close')
        if (await close.count()) await close.click()
      }
    }
  }

  const report = {
    task: 'dual-webui-mock-providers-components',
    independent: true,
    timestamp: stamp,
    mock_api_base: MOCK_API_BASE,
    status: 'passed',
    webuis: perWebui,
    policy: 'local mock-api only; not wired into release-gate',
  }
  fs.mkdirSync(REPORT_DIR, { recursive: true })
  const out = path.join(REPORT_DIR, `playwright-${stamp}.json`)
  fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`)
  console.log(`report: ${out}`)
})
