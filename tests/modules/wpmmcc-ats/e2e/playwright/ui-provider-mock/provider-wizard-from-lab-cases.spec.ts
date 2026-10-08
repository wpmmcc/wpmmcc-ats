/**
 * UI-driven WebUI wizard — parameters from mock lab-provider-cases only.
 *
 * Does NOT hardcode vendor keys/URLs. Loads:
 *   GET {MOCK}/api/v1/lab-provider-cases
 * or synced files under tests/modules/wpmmcc-ats/e2e/lab-provider-cases/
 *
 * Env:
 *   MOCK_API_BASE, WEBUI_A_BASE / CLIENT_BASE
 *   LAB_CASE_FILTER=deepl,youdao   (optional substring filter on case_id)
 *   LAB_CASES_LIMIT=0              (0 = all catalog:* cases)
 *   LAB_INCLUDE_AUTH_PROFILES=0    (1 = also run auth-profile:* cases)
 */

import { expect, test, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'

const CLIENT_BASE = process.env.WEBUI_A_BASE || process.env.CLIENT_BASE || 'http://127.0.0.1:8977'
const MOCK_API_BASE = process.env.MOCK_API_BASE || 'http://127.0.0.1:9090'
const REPORT_DIR =
  process.env.REPORT_DIR ||
  path.resolve(__dirname, '../../reports/ui-provider-mock')
const CASES_DIR = path.resolve(__dirname, '../../lab-provider-cases')
const INCLUDE_PROFILES = process.env.LAB_INCLUDE_AUTH_PROFILES === '1'
const LIMIT = Number(process.env.LAB_CASES_LIMIT || '0')
const FILTER = (process.env.LAB_CASE_FILTER || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
const SHARDS = Math.max(1, Number(process.env.LAB_SHARDS || '1'))
const SHARD_INDEX = Math.max(0, Number(process.env.LAB_SHARD_INDEX || '0'))

type LabCase = {
  case_id: string
  entry_id: string
  vendor_id: string
  name: string
  family: string
  auth_fields: string[]
  auth_values: Record<string, string>
  request_url: string
  response_translated_text_path?: string
  ui?: { search?: string; display_name?: string }
}

async function loadCases(): Promise<LabCase[]> {
  try {
    const r = await fetch(`${MOCK_API_BASE}/api/v1/lab-provider-cases`, {
      signal: AbortSignal.timeout(15_000),
    })
    if (r.ok) {
      const inv = (await r.json()) as { cases: LabCase[] }
      return inv.cases || []
    }
  } catch {
    /* fall through to synced files */
  }
  const manifestPath = path.join(CASES_DIR, 'manifest.json')
  if (!fs.existsSync(manifestPath)) {
    throw new Error(
      `No lab cases: mock ${MOCK_API_BASE}/api/v1/lab-provider-cases unreachable and ${manifestPath} missing. Run: python3 scripts/sync-lab-provider-ui-cases.py`,
    )
  }
  const files = (JSON.parse(fs.readFileSync(manifestPath, 'utf8')).files || []) as string[]
  return files.map((f) => {
    const raw = JSON.parse(fs.readFileSync(path.join(CASES_DIR, f), 'utf8'))
    return raw.case as LabCase
  })
}

function selectCases(all: LabCase[]): LabCase[] {
  let list = all.filter((c) => c.case_id?.startsWith('catalog:') || INCLUDE_PROFILES)
  if (!INCLUDE_PROFILES) list = list.filter((c) => c.case_id?.startsWith('catalog:'))
  if (FILTER.length) {
    list = list.filter((c) => FILTER.some((f) => c.case_id.includes(f) || c.entry_id.includes(f)))
  }
  if (LIMIT > 0) list = list.slice(0, LIMIT)
  if (SHARDS > 1) {
    list = list.filter((_, idx) => idx % SHARDS === SHARD_INDEX)
  }
  return list
}

test.describe.configure({ mode: 'serial' })

async function openCatalog(page: Page) {
  await page.goto(CLIENT_BASE)
  await page.getByRole('button', { name: /API Keys|API 密钥|密钥/ }).click()
  await expect(page.getByRole('heading', { name: /API Keys|API 密钥|密钥/ })).toBeVisible({
    timeout: 20_000,
  })
}

async function runOne(page: Page, c: LabCase) {
  const searchKey = c.ui?.search || c.entry_id
  const search = page.getByLabel(/search|搜索/i).first()
  await search.fill(searchKey)
  await search.press('Enter')
  await page.waitForTimeout(500)

  const rowById = page
    .locator('tr')
    .filter({ has: page.getByText(c.entry_id, { exact: true }) })
  const rowByName = page
    .locator('tr')
    .filter({ has: page.getByText(c.name, { exact: true }) })
  const row = rowById.or(rowByName).first()
  await expect(row, `row for ${c.case_id}`).toBeVisible({ timeout: 25_000 })
  await row.getByTestId('open-provider-wizard').click()
  await expect(page.getByTestId('provider-setup-wizard')).toBeVisible({ timeout: 10_000 })

  const stamp = Date.now().toString(36)
  await page.getByTestId('wizard-local-id').fill(`lab-${c.entry_id}-${stamp}`.slice(0, 100))
  await page.getByTestId('wizard-install-next').click()
  await expect(page.getByTestId('wizard-api-key')).toBeVisible({ timeout: 20_000 })
  await page.getByTestId('wizard-key-id').fill(`lab-${c.entry_id}-k-${stamp}`.slice(0, 100))

  // Fill every auth_values key (oauth needs access_token beyond auth_fields).
  const values = c.auth_values || {}
  const fields = [
    ...(c.auth_fields?.length ? c.auth_fields : []),
    ...Object.keys(values).filter((k) => !(c.auth_fields || []).includes(k)),
  ]
  for (let i = 0; i < fields.length; i++) {
    const name = fields[i]
    const value = values[name]
    if (value == null || value === '') continue
    const testId = i === 0 ? 'wizard-api-key' : `wizard-auth-${name}`
    const input = page.getByTestId(testId)
    if (await input.count()) await input.fill(String(value))
    else {
      // Fallback: first empty auth input or named label
      const byName = page.locator(`[data-testid="wizard-auth-${name}"], input[name="${name}"]`).first()
      if (await byName.count()) await byName.fill(String(value))
    }
  }

  await page.getByTestId('wizard-request-url').fill(c.request_url)
  if (c.family === 'openai_compatible' && (await page.getByTestId('wizard-model').count())) {
    await page.getByTestId('wizard-model').fill('mock-openai-v1')
  }
  if (
    c.response_translated_text_path &&
    (await page.getByTestId('wizard-response-path').count())
  ) {
    await page.getByTestId('wizard-response-path').fill(c.response_translated_text_path)
  }

  await page.getByTestId('wizard-key-next').click()
  await expect(page.getByTestId('wizard-test-next')).toBeVisible({ timeout: 15_000 })
  await page.getByTestId('wizard-test-text').fill('Hello world')

  // Adapt test source/target languages to provider-specific requirements (P1)
  const srcInput = page.getByTestId('wizard-test-source-lang')
  if (await srcInput.count()) {
    const isDeepL = c.entry_id?.includes('deepl') || c.case_id?.includes('deepl')
    const src = isDeepL ? 'EN' : ((c as any).source_lang || (c as any).fill?.source_lang || 'en')
    await srcInput.fill(src)
  }
  const tgtInput = page.getByTestId('wizard-test-target-lang')
  if (await tgtInput.count()) {
    const isDeepL = c.entry_id?.includes('deepl') || c.case_id?.includes('deepl')
    const tgt = isDeepL ? 'ZH' : ((c as any).target_lang || (c as any).fill?.target_lang || 'zh')
    await tgtInput.fill(tgt)
  }

  await page.getByTestId('wizard-test-next').click()

  const enable = page.getByTestId('wizard-enable-next')
  const errBox = page.locator('[data-testid="provider-setup-wizard"]').locator('.text-red-700, .text-red-600')
  await expect(enable.or(errBox.first())).toBeVisible({ timeout: 60_000 })
  if (await errBox.count()) {
    throw new Error(`${c.case_id} quick-test UI error: ${await errBox.first().innerText()}`)
  }
  await enable.click()
  await page.getByTestId('wizard-route-next').click()
  await expect(page.getByTestId('wizard-done')).toBeVisible({ timeout: 15_000 })

  const close = page.getByRole('button', { name: /Close|关闭|取消/ }).first()
  if (await close.isVisible().catch(() => false)) await close.click()
  else await page.keyboard.press('Escape')
}

test('WebUI: all lab-provider-cases from mock (catalog)', async ({ page }) => {
  const health = await fetch(`${MOCK_API_BASE}/api/v1/health`, { signal: AbortSignal.timeout(5000) })
  expect(health.ok, 'mock-api health').toBeTruthy()
  const status = await fetch(`${CLIENT_BASE}/api/status`, { signal: AbortSignal.timeout(5000) })
  expect(status.ok, 'client status').toBeTruthy()

  const all = await loadCases()
  const cases = selectCases(all)
  expect(cases.length, 'expected lab cases').toBeGreaterThan(0)

  await openCatalog(page)

  const results: Array<{ case_id: string; ok: boolean; error?: string }> = []
  for (const c of cases) {
    try {
      await runOne(page, c)
      results.push({ case_id: c.case_id, ok: true })
    } catch (e) {
      results.push({ case_id: c.case_id, ok: false, error: String(e) })
      // Continue remaining vendors; fail at end.
    }
  }

  fs.mkdirSync(REPORT_DIR, { recursive: true })
  const stamp = new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)
  const failed = results.filter((r) => !r.ok)
  const report = {
    task: 'ui-webui-lab-provider-cases',
    source: `${MOCK_API_BASE}/api/v1/lab-provider-cases`,
    client_base: CLIENT_BASE,
    total: results.length,
    passed: results.length - failed.length,
    failed: failed.length,
    results,
  }
  const out = path.join(REPORT_DIR, `webui-lab-cases-${stamp}.json`)
  fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`)
  console.log(`report: ${out}`)
  expect(failed, JSON.stringify(failed, null, 2)).toEqual([])
})
