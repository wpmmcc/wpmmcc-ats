/**
 * E2E-B live-mock lane (no page.route for Client/mock APIs).
 *
 * Proves: catalog install → vendor key → quick-test (real mock-api) →
 * enable → plain_text bind → list → worker run-once.
 *
 * Preconditions:
 *   - mock-api on :9090
 *   - Client WebUI on :8977 with WPTSALL_WEB_UI=1 and
 *     WPTSALL_PROVIDER_ALLOWLIST=127.0.0.1,localhost
 *
 * Prefer:
 *   bash tests/modules/wpmmcc-ats/e2e/run-playwright-provider-live-mock.sh
 */

import { expect, test } from '@playwright/test'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'
import fs from 'node:fs'
import path from 'node:path'

const CLIENT_BASE = resolveSlotClientBase()
const MOCK_API_BASE = process.env.MOCK_API_BASE || 'http://127.0.0.1:9090'
const REPORT_DIR =
  process.env.REPORT_DIR ||
  path.resolve(__dirname, '../../reports')

test.describe.configure({ mode: 'serial' })

async function assertReachable(url: string, label: string) {
  try {
    const r = await fetch(url, { signal: AbortSignal.timeout(3000) })
    if (!r.ok) throw new Error(`status ${r.status}`)
  } catch (err) {
    throw new Error(`${label} not reachable at ${url}: ${err}`)
  }
}

test('live mock: catalog → quick-test → bind → run-once', async ({ page }) => {
  await assertReachable(`${MOCK_API_BASE}/api/v1/health`, 'mock-api')
  await assertReachable(`${CLIENT_BASE}/api/status`, 'client agent')

  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const compId = `live-mock-openai-${stamp}`
  const keyId = `${compId}-key`

  const install = await fetch(`${CLIENT_BASE}/api/components/local/install-from-catalog`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      entry_id: 'openai-compatible',
      local_id: compId,
      name: 'Live Mock OpenAI',
    }),
  }).then((r) => r.json())
  expect(install.success, JSON.stringify(install)).toBeTruthy()

  const key = await fetch(`${CLIENT_BASE}/api/vendor-keys`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      id: keyId,
      vendor_id: 'openai',
      label: 'live-mock',
      auth_values: { api_key: 'mock-translate-dev-key-2026' },
      enabled: true,
    }),
  }).then((r) => r.json())
  expect(key.success, JSON.stringify(key)).toBeTruthy()

  await fetch(`${CLIENT_BASE}/api/components/local/${encodeURIComponent(compId)}/versions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ version: 'v1', key_ids: [keyId], auth_type: 'key' }),
  }).catch(() => null)

  const qt = await fetch(
    `${CLIENT_BASE}/api/components/local/${encodeURIComponent(compId)}/quick-test`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        api_key: 'mock-translate-dev-key-2026',
        auth_values: { api_key: 'mock-translate-dev-key-2026' },
        config_overrides: {
          'request.url': `${MOCK_API_BASE}/v1/chat/completions`,
          'request.body.model': 'mock-openai-v1',
        },
        text: 'Hello world',
        source_lang: 'en_US',
        target_lang: 'zh_CN',
      }),
    },
  ).then((r) => r.json())

  const qtText = qt?.data?.translated_text
  expect(qt.success, JSON.stringify(qt)).toBeTruthy()
  expect(qtText, `SSRF? restart with WPTSALL_PROVIDER_ALLOWLIST=127.0.0.1,localhost — ${JSON.stringify(qt)}`).toBeTruthy()

  await fetch(`${CLIENT_BASE}/api/components/local/${encodeURIComponent(compId)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ enabled: true }),
  })

  const bind = await fetch(`${CLIENT_BASE}/api/rule-component-bindings/upsert`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      scope: 'global',
      slot_key: 'plain_text',
      component_id: compId,
    }),
  }).then((r) => r.json())
  expect(bind.success, JSON.stringify(bind)).toBeTruthy()

  // Local list is paginated (~50/page); look up by id instead of scanning page 1.
  const got = await fetch(`${CLIENT_BASE}/api/components/local/${encodeURIComponent(compId)}`).then(
    (r) => r.json(),
  )
  expect(got.success, JSON.stringify(got)).toBeTruthy()
  expect(String(got?.data?.id ?? got?.data?.component_id ?? '')).toBe(compId)

  const run = await fetch(`${CLIENT_BASE}/api/worker/run-once`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ max_iterations: 1, max_elapsed_secs: 60, max_items_per_run: 1 }),
  }).then((r) => r.json())
  expect(run.success, JSON.stringify(run)).toBeTruthy()

  // Light UI smoke: status page loads against the live agent (no API mocks).
  await page.goto(`${CLIENT_BASE}/`)
  await expect(page.locator('body')).toBeVisible()

  const report = {
    task: 'provider-live-mock-playwright',
    timestamp: stamp,
    client_base: CLIENT_BASE,
    mock_api_base: MOCK_API_BASE,
    component_id: compId,
    status: 'passed',
    quick_test_preview: String(qtText).slice(0, 80),
  }
  fs.mkdirSync(REPORT_DIR, { recursive: true })
  const out = path.join(REPORT_DIR, `provider-live-mock-${stamp}.json`)
  fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`)
  console.log(`report: ${out}`)
})
