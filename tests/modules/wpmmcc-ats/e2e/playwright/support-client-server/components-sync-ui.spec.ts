import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { ensureClientWebUiLoggedIn } from './helpers/client-oauth-login'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const CLIENT_BASE = resolveSlotClientBase()

type LocalKind = 'text' | 'image' | 'video' | 'audio' | 'document' | 'openai_compatible'

type ServerTemplate = {
  id: string
  name?: string
  kind?: string
  type?: string
  status?: string
  supported_types?: string[]
  supported_formats?: string[]
  supported_content_formats?: string[]
  vendor_id?: string
}

type LocalComponent = {
  id: string
  template_id?: string
  enabled?: boolean
}

async function ensureLoggedIn(page: Page, _context: unknown, request: Parameters<typeof ensureClientWebUiLoggedIn>[1]) {
  await ensureClientWebUiLoggedIn(page, request, { requireServerSearch: true })
}

async function fetchAllItems<T>(
  request: APIRequestContext,
  path: string,
  perPage = 200,
  extraQuery: Record<string, string> = {},
): Promise<T[]> {
  const out: T[] = []
  let page = 1
  while (true) {
    const params = new URLSearchParams({
      ...extraQuery,
      page: String(page),
      per_page: String(perPage),
    })
    const res = await request.get(`${CLIENT_BASE}${path}?${params.toString()}`)
    expect(res.ok()).toBe(true)
    const json = await res.json()
    expect(json?.success).toBe(true)
    const items: T[] = json?.data?.items ?? []
    out.push(...items)
    const totalPages = Number(json?.data?.total_pages ?? 1)
    if (page >= totalPages) break
    page += 1
  }
  return out
}

function normalizeKind(raw: string): LocalKind {
  const v = raw.trim().toLowerCase()
  if (v === 'text' || v === 'text_translation' || v === 'field' || v === 'fields') return 'text'
  if (v === 'image' || v === 'image_translation' || v === 'images') return 'image'
  if (v === 'video' || v === 'video_translation' || v === 'videos') return 'video'
  if (v === 'audio' || v === 'audio_translation' || v === 'audios') return 'audio'
  if (v === 'document' || v === 'document_translation' || v === 'doc' || v === 'file' || v === 'files') return 'document'
  if (v === 'openai_compatible' || v === 'openai-compatible' || v === 'openai' || v === 'llm') return 'openai_compatible'
  return 'text'
}

function inferLocalKindFromTemplate(tpl: ServerTemplate): LocalKind {
  const preferredType = Array.isArray(tpl.supported_types)
    ? tpl.supported_types.find((item) => typeof item === 'string' && item.trim())
    : undefined
  const primary = String(preferredType ?? tpl.type ?? tpl.kind ?? 'text')
  return normalizeKind(primary)
}

function uniqueLocalId(baseId: string, usedIds: Set<string>): string {
  if (!usedIds.has(baseId)) return baseId
  let i = 1
  while (usedIds.has(`${baseId}--ui-${i}`)) i += 1
  return `${baseId}--ui-${i}`
}

async function createOneByUi(page: Page, input: {
  localId: string
  name: string
  kind: LocalKind
  templateId: string
  vendorId: string
}) {
  await page.click('button:has-text("创建组件")')
  const modal = page.locator('div.fixed.inset-0').filter({ has: page.locator('h3:has-text("创建组件")') }).first()
  await expect(modal).toBeVisible()

  await modal.locator('input[placeholder*="组件 ID"]').fill(input.localId)
  await modal.locator('input[placeholder*="显示名称"]').fill(input.name)
  await modal.locator('select').first().selectOption(input.kind)
  await modal.locator('input[placeholder*="Server 模板 ID"]').fill(input.templateId)
  if (input.vendorId) {
    await modal.locator('input[placeholder="Vendor ID"]').fill(input.vendorId)
  }
  await modal.locator('input[placeholder*="备注"]').fill(`Synced via Playwright UI from ${input.templateId}`)

  await modal.locator('button:has-text("保存")').click()
  await expect(modal).toBeHidden({ timeout: 20_000 })
}

test.describe.configure({ mode: 'serial' })

test('Playwright UI: 批量创建官网模板对应本地组件', async ({ page, context, request }) => {
  test.setTimeout(20 * 60 * 1000)

  await ensureLoggedIn(page, context, request)
  await request.post(`${CLIENT_BASE}/api/components/refresh`)

  const serverTemplates = await fetchAllItems<ServerTemplate>(
    request,
    '/api/components/server-search',
    200,
    { status: 'active' },
  )
  const disabledTemplates = await fetchAllItems<ServerTemplate>(
    request,
    '/api/components/server-search',
    200,
    { status: 'disabled' },
  )
  const localComponents = await fetchAllItems<LocalComponent>(request, '/api/components/local')

  const existingTemplateIds = new Set(
    localComponents.map((c) => String(c.template_id ?? '').trim()).filter(Boolean),
  )
  const usedLocalIds = new Set(localComponents.map((c) => c.id))
  const missing = serverTemplates.filter((tpl) => !existingTemplateIds.has(tpl.id))

  console.log(`Server templates=${serverTemplates.length}, local components=${localComponents.length}, missing=${missing.length}`)

  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 15_000 })
  await page.click('nav button:has-text("翻译组件")')
  await page.click('button:has-text("我的组件")')
  await expect(page.locator('button:has-text("创建组件")')).toBeVisible()

  let created = 0
  for (const tpl of missing) {
    const templateId = tpl.id
    const localId = uniqueLocalId(templateId, usedLocalIds)
    const kind = inferLocalKindFromTemplate(tpl)
    const name = (tpl.name && tpl.name.trim()) ? tpl.name.trim() : templateId
    const vendorId = String(tpl.vendor_id ?? '').trim()

    await createOneByUi(page, {
      localId,
      name,
      kind,
      templateId,
      vendorId,
    })
    usedLocalIds.add(localId)
    existingTemplateIds.add(templateId)
    created += 1
    console.log(`[create] ${localId} <= ${templateId} (${kind})`)
  }

  const localAfter = await fetchAllItems<LocalComponent>(request, '/api/components/local')
  const coveredTemplateIds = new Set(
    localAfter.map((c) => String(c.template_id ?? '').trim()).filter(Boolean),
  )
  const uncovered = serverTemplates
    .map((t) => t.id)
    .filter((id) => !coveredTemplateIds.has(id))
  const disabledTemplateIds = new Set(disabledTemplates.map((t) => t.id))
  const localBoundDisabledComps = localAfter.filter((c) =>
    disabledTemplateIds.has(String(c.template_id ?? '').trim()),
  )
  const localBoundDisabled = localBoundDisabledComps.map((c) => String(c.template_id ?? '').trim())
  const localBoundDisabledEnabled = localBoundDisabledComps.filter((c) => c.enabled !== false)
  const localBoundDisabledDisabled = localBoundDisabledComps.filter((c) => c.enabled === false)

  console.log(`created=${created}, local_after=${localAfter.length}, uncovered=${uncovered.length}`)
  console.log(
    `active_templates=${serverTemplates.length}, disabled_templates=${disabledTemplates.length}, local_bound_disabled_total=${localBoundDisabled.length}, local_bound_disabled_enabled=${localBoundDisabledEnabled.length}, local_bound_disabled_disabled=${localBoundDisabledDisabled.length}`,
  )
  if (localBoundDisabled.length > 0) {
    console.log(`local components still bound to disabled templates: ${Array.from(new Set(localBoundDisabled)).join(', ')}`)
  }
  // Keep local state safe-by-default: disabled server templates should not remain enabled locally.
  for (const comp of localBoundDisabledEnabled) {
    console.log(`[disable] local component ${comp.id} is enabled but bound to disabled template ${comp.template_id}`)
    const res = await request.put(`${CLIENT_BASE}/api/components/local/${encodeURIComponent(comp.id)}`, {
      data: { enabled: false },
    })
    expect(res.ok()).toBe(true)
    const json = await res.json().catch(() => null)
    expect(json?.success).toBe(true)
  }
  if (localBoundDisabledEnabled.length > 0) {
    const localRecheck = await fetchAllItems<LocalComponent>(request, '/api/components/local')
    const stillEnabled = localRecheck.filter(
      (c) => disabledTemplateIds.has(String(c.template_id ?? '').trim()) && c.enabled !== false,
    )
    expect(stillEnabled).toEqual([])
  }
  if (uncovered.length > 0) {
    console.log(`uncovered templates: ${uncovered.join(', ')}`)
  }
  expect(uncovered.length).toBe(0)
})
