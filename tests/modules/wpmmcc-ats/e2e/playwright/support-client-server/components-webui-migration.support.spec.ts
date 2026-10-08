import { test, expect, type Locator, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const CLIENT_BASE = resolveSlotClientBase()

const runtimeDir = path.resolve(process.cwd(), '../runtime')
const reportFile = path.join(runtimeDir, 'components-webui-migration.json')

type LocalComp = {
  id: string
  name: string
  kind: string
  template_id?: string
}

type ServerComp = {
  id: string
  type?: string
  owner_type?: string
  supported_types?: string[]
}

type RuleBinding = {
  scope?: string
  scope_key?: string
  slot_key?: string
  component_id?: string
}

type RuleDiscoveryField = {
  field_name: string
  content_format: string
  source_role: string
  storage: string
  required_slot_key: string
  suggested_task_type: string
}

type RuleDiscoveryItem = {
  relation_id: number
  plugin_slug?: string
  rule_id: number
  source_group: string
  routing_profile: string
  delivery_target: string
  fields: RuleDiscoveryField[]
}

type DomainTokenBinding = {
  api_base_url: string
  route_secret?: string
  route_secret_set?: boolean
  token_prefix?: string
  token_len?: number
}

async function ensureLoggedIn(page: Page, context: { addInitScript: Page['addInitScript'] }, request: any) {
  await context.addInitScript(() => {
    try {
      // P0-LF-05 versioned locale key (frontend/src/i18n/index.ts
      // LOCALE_STORAGE_KEY). Pin the UI language so the Chinese button
      // labels below match regardless of the browser locale.
      window.localStorage.setItem('wptsall_locale.v1', 'zh-CN')
      window.localStorage.setItem('wptsall_locale', 'zh-CN')
    } catch {}
  })
  // Local-first (AGENTS.md §0.1): the client is standalone and never logs
  // into a website. The former OAuth (PKCE against www.wpmm.cc / lab :8787)
  // login is gone; the UI is usable directly once a site binding exists.
  // Verify the shell renders and at least one site binding is configured.
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 })
  const bindings = await loadDomainTokenBindings(request)
  expect(
    bindings.length,
    'no site bindings configured; local-first client needs a device-scoped site binding'
  ).toBeGreaterThan(0)
}

async function loadLocalComponents(request: any): Promise<LocalComp[]> {
  const res = await request.get(`${CLIENT_BASE}/api/components/local?page=1&per_page=200`)
  expect(res.ok(), `local components HTTP ${res.status()}`).toBeTruthy()
  const json = await res.json()
  expect(json?.success).toBeTruthy()
  return (json?.data?.items ?? []) as LocalComp[]
}

async function loadServerComponents(request: any): Promise<ServerComp[]> {
  const res = await request.get(`${CLIENT_BASE}/api/components/server-search?page=1&per_page=200`)
  expect(res.ok(), `server components HTTP ${res.status()}`).toBeTruthy()
  const json = await res.json()
  expect(json?.success).toBeTruthy()
  return (json?.data?.items ?? []) as ServerComp[]
}

async function loadRuleDiscovery(request: any): Promise<RuleDiscoveryItem[]> {
  const res = await request.get(`${CLIENT_BASE}/api/rule-component-bindings/discovery`)
  expect(res.ok(), `rule discovery HTTP ${res.status()}`).toBeTruthy()
  const json = await res.json()
  expect(json?.success).toBeTruthy()
  return (json?.data?.items ?? []) as RuleDiscoveryItem[]
}

async function loadDomainTokenBindings(request: any): Promise<DomainTokenBinding[]> {
  const res = await request.get(`${CLIENT_BASE}/api/status`)
  expect(res.ok(), `status HTTP ${res.status()}`).toBeTruthy()
  const json = await res.json()
  expect(json?.success).toBeTruthy()
  return (json?.data?.domain_token_bindings ?? []) as DomainTokenBinding[]
}

// /api/status redacts secrets (it only reports route_secret_set). The
// plaintext route secrets live in the client bootstrap file that the CT
// lane's sync_client_bootstrap_auth_from_manifest step refreshes before the
// Playwright tests run, so read the secret from there.
function loadBootstrapRouteSecrets(): Record<string, string> {
  const candidateFiles = [
    path.resolve(__dirname, '../../../../../..', 'client-wpplugin/source/config/domain-token-bindings.json'),
    path.resolve(__dirname, '../../runtime/core-component-template-sources.json'),
    path.resolve(__dirname, '../../../../..', 'client-wpplugin/source/config/domain-token-bindings.json'),
  ]
  const out: Record<string, string> = {}
  for (const file of candidateFiles) {
    if (!fs.existsSync(file)) continue
    try {
      const parsed = JSON.parse(fs.readFileSync(file, 'utf8')) as Record<string, any>
      if (parsed.domains && typeof parsed.domains === 'object') {
        for (const [key, entry] of Object.entries(parsed.domains as Record<string, { route_secret?: string }>)) {
          if (entry?.route_secret) {
            out[key] = String(entry.route_secret)
          }
        }
      }
      if (parsed.route_secret) {
        const labBase = process.env.WPTSALL_WP_BASE ?? 'http://127.0.0.1:9083'
        out[labBase] = String(parsed.route_secret)
      }
    } catch {
      // ignore parse error and try next
    }
  }
  return out
}

function sourceGroupLabel(value: string): string {
  if (value === 'content_object') return '内容对象'
  if (value === 'config_object') return '配置对象'
  if (value === 'message_template') return '消息模板'
  if (value === 'plugin_i18n') return '插件语言包'
  if (value === 'theme_i18n') return '主题语言包'
  return value
}

function routingProfileLabel(value: string): string {
  if (value === 'post_content_default') return '文章内容默认路由'
  if (value === 'taxonomy_default') return '分类术语默认路由'
  if (value === 'config_i18n') return '配置国际化路由'
  if (value === 'notification_email') return '通知邮件路由'
  if (value === 'notification_message') return '消息通知路由'
  if (value === 'plugin_i18n_default') return '插件语言包路由'
  if (value === 'theme_i18n_default') return '主题语言包路由'
  return value
}

function deliveryTargetLabel(value: string): string {
  if (value === 'object_writeback') return '对象写回'
  if (value === 'option_writeback') return '配置写回'
  if (value === 'message_template_writeback') return '模板写回'
  if (value === 'i18n_bundle_writeback') return '语言包写回'
  return value
}

async function expectRuleCardLabel(ruleCard: Locator, text: string) {
  const normalized = text.trim()
  if (!normalized || normalized === '-') return
  await expect(ruleCard.getByText(normalized, { exact: true }).first()).toBeVisible()
}

function supportsLocalKind(item: ServerComp, kind: string): boolean {
  const bySupportedTypes = (item.supported_types ?? []).map((v) => v.trim()).filter(Boolean)
  if (bySupportedTypes.includes(kind)) return true

  const t = (item.type ?? '').trim()
  if (kind === 'text') return t === 'text_translation'
  if (kind === 'image') return t === 'image_translation'
  if (kind === 'video') return t === 'video_translation'
  if (kind === 'audio') return t === 'audio_translation'
  if (kind === 'document') return t === 'document_translation'
  return false
}

function pickTemplateByKind(items: ServerComp[], kind: string): string {
  const byKind = items.filter((it) => supportsLocalKind(it, kind))
  if (byKind.length === 0) return ''

  const preferred = byKind
    .filter((it) => (it.owner_type ?? '').toLowerCase() === 'official' || it.id.startsWith('official-'))
    .sort((a, b) => a.id.localeCompare(b.id))
  if (preferred.length > 0) return preferred[0].id
  return [...byKind].sort((a, b) => a.id.localeCompare(b.id))[0].id
}

async function gotoComponents(page: Page) {
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 20_000 })
  await page.getByRole('button', { name: '翻译组件' }).click()
  await expect(page.getByRole('heading', { name: '翻译组件' })).toBeVisible()
}

async function gotoSites(page: Page) {
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 20_000 })
  await page.getByRole('button', { name: '站点' }).click()
  await expect(page.getByRole('heading', { name: 'WP 站点' })).toBeVisible()
}

async function openMyComponentsTab(page: Page) {
  await page.getByRole('button', { name: '我的组件' }).click()
  await expect(page.getByText('本地组件实例')).toBeVisible()
}

async function openRuleBindingsTab(page: Page) {
  await page.getByRole('button', { name: '规则绑定' }).click()
  await expect(page.getByRole('heading', { name: '路由配置' })).toBeVisible()
}

async function updateTemplateForComponent(page: Page, compId: string, templateId: string) {
  await openMyComponentsTab(page)
  const searchInput = page.getByPlaceholder('搜索组件名称、ID、Vendor...')
  await searchInput.fill(compId)
  await page.waitForTimeout(450)

  const row = page.locator('div.flex.items-center.px-4.py-3').filter({ hasText: compId }).first()
  await expect(row, `component row missing: ${compId}`).toBeVisible()
  await row.getByRole('button', { name: '编辑' }).click()

  const heading = page.getByRole('heading', { name: '编辑组件' }).first()
  await expect(heading).toBeVisible()
  const modal = heading.locator('xpath=ancestor::div[contains(@class,"bg-white rounded-xl")][1]')
  const templateInput = modal.getByPlaceholder('Server 模板 ID（必填）')
  await expect(templateInput).toBeVisible()
  await templateInput.fill(templateId)

  await modal.getByRole('button', { name: '保存' }).click()
  await expect(heading).toBeHidden({ timeout: 15_000 })
}

async function createComponentViaUi(page: Page, id: string, name: string, kind: string, templateId: string) {
  await openMyComponentsTab(page)
  await page.getByRole('button', { name: '+ 创建组件' }).click()

  const heading = page.getByRole('heading', { name: '创建组件' }).first()
  await expect(heading).toBeVisible()
  const modal = heading.locator('xpath=ancestor::div[contains(@class,"bg-white rounded-xl")][1]')

  await modal.getByPlaceholder('组件 ID（必填，唯一）').fill(id)
  await modal.getByPlaceholder('显示名称（必填）').fill(name)
  await modal.locator('select').first().selectOption(kind)
  await modal.getByPlaceholder('Server 模板 ID（必填）').fill(templateId)
  await modal.getByRole('button', { name: '保存' }).click()

  await expect(heading).toBeHidden({ timeout: 15_000 })
}

async function upsertGlobalRuleBinding(page: Page, slotKey: string, componentId: string) {
  await openRuleBindingsTab(page)
  const scopeSelect = page.locator('label:has-text("范围") + select').first()
  const slotSelect = page.locator('label:has-text("Slot Key") + select').first()
  const scopeKeyInput = page.locator('label:has-text("scope_key") + input').first()
  const componentInput = page.locator('label:has-text("组件 ID") + input').first()
  const saveBtn = page.getByRole('button', { name: '保存绑定' }).first()
  const table = page
    .locator('thead tr th:has-text("scope_key")')
    .locator('xpath=ancestor::table[1]')
    .first()

  await scopeSelect.selectOption('global')
  await expect(scopeKeyInput).toBeDisabled()
  await slotSelect.selectOption(slotKey)
  await componentInput.fill(componentId)
  await saveBtn.click()

  const row = table.locator('tbody tr').filter({ hasText: slotKey }).filter({ hasText: componentId })
  await expect(row.first(), `binding row missing: ${slotKey} -> ${componentId}`).toBeVisible()
}

test.describe.configure({ mode: 'serial' })

test('WebUI 组件迁移：对齐新模板规范并重建全局 slot 绑定', async ({ page, context, request }) => {
  // Legacy website-catalog scenario: this test sources component templates
  // from the website control plane (/api/components/server-search requires a
  // website session). Per the local-first boundary the template source moves
  // to a local catalog (P1-CAT); skip until that lands.
  test.skip(true, 'depends on legacy website component catalog; re-enable after P1-CAT local catalog')
  await ensureLoggedIn(page, context, request)

  // Keep server-side components snapshot fresh before UI migration.
  await request.post(`${CLIENT_BASE}/api/components/refresh`, { data: {} })

  let localBefore = await loadLocalComponents(request)
  const serverItems = await loadServerComponents(request)
  expect(serverItems.length, 'No server templates loaded').toBeGreaterThan(0)

  const requiredKinds = ['text', 'image', 'video', 'audio', 'document']
  const templateByKind = new Map<string, string>()
  for (const kind of requiredKinds) {
    const tid = pickTemplateByKind(serverItems, kind)
    expect(tid, `No server template available for kind=${kind}`).not.toBe('')
    templateByKind.set(kind, tid)
  }

  await gotoComponents(page)

  const updatedComponents: Array<{ id: string; kind: string; from: string; to: string }> = []
  for (const comp of localBefore) {
    if (comp.kind === 'openai_compatible') continue
    const targetTemplate = templateByKind.get(comp.kind) ?? ''
    if (!targetTemplate) continue
    const fromTemplate = (comp.template_id ?? '').trim()
    if (fromTemplate === targetTemplate) continue
    await updateTemplateForComponent(page, comp.id, targetTemplate)
    updatedComponents.push({ id: comp.id, kind: comp.kind, from: fromTemplate, to: targetTemplate })
  }

  localBefore = await loadLocalComponents(request)

  const createdComponents: Array<{ id: string; kind: string; template_id: string }> = []
  const textCompBefore =
    localBefore.find((c) => c.kind === 'text')
    ?? localBefore.find((c) => c.kind === 'openai_compatible')
  if (!textCompBefore) {
    const templateId = templateByKind.get('text') ?? ''
    const id = 'std-text-component'
    const existsById = localBefore.find((c) => c.id === id)
    if (!existsById) {
      await createComponentViaUi(page, id, 'Standard text', 'text', templateId)
      createdComponents.push({ id, kind: 'text', template_id: templateId })
      localBefore = await loadLocalComponents(request)
    }
  }

  for (const mediaKind of ['image', 'video', 'audio', 'document']) {
    const existing = localBefore.find((c) => c.kind === mediaKind)
    if (existing) continue
    const templateId = templateByKind.get(mediaKind) ?? ''
    if (!templateId) continue
    const id = `std-${mediaKind}-component`
    const existsById = localBefore.find((c) => c.id === id)
    if (existsById) continue
    await createComponentViaUi(page, id, `Standard ${mediaKind}`, mediaKind, templateId)
    createdComponents.push({ id, kind: mediaKind, template_id: templateId })
    localBefore = await loadLocalComponents(request)
  }

  const localAfter = await loadLocalComponents(request)
  const textComp =
    localAfter.find((c) => c.kind === 'text')
    ?? localAfter.find((c) => c.kind === 'openai_compatible')
  expect(textComp, 'No text-capable component found for slot bindings').toBeTruthy()

  const byKind = (kind: string) => localAfter.find((c) => c.kind === kind)?.id ?? ''
  const imageCompId = byKind('image')
  const videoCompId = byKind('video')
  const audioCompId = byKind('audio')
  const documentCompId = byKind('document')
  const genericMediaCompId = imageCompId || videoCompId || audioCompId || documentCompId

  const bindingPlan: Array<{ slot: string; component_id: string }> = [
    { slot: 'plain_text', component_id: textComp!.id },
    { slot: 'rich_html', component_id: textComp!.id },
    { slot: 'json_structured', component_id: textComp!.id },
    { slot: 'serialized_php', component_id: textComp!.id },
    { slot: 'slug', component_id: textComp!.id },
    { slot: 'code', component_id: textComp!.id },
  ]
  if (genericMediaCompId) bindingPlan.push({ slot: 'media_ref', component_id: genericMediaCompId })
  if (imageCompId) bindingPlan.push({ slot: 'media_ref:image', component_id: imageCompId })
  if (videoCompId) bindingPlan.push({ slot: 'media_ref:video', component_id: videoCompId })
  if (audioCompId) bindingPlan.push({ slot: 'media_ref:audio', component_id: audioCompId })
  if (documentCompId) bindingPlan.push({ slot: 'media_ref:document', component_id: documentCompId })

  for (const item of bindingPlan) {
    await upsertGlobalRuleBinding(page, item.slot, item.component_id)
  }

  const statusRes = await request.get(`${CLIENT_BASE}/api/status`)
  expect(statusRes.ok()).toBeTruthy()
  const status = await statusRes.json()
  const bindings = ((status?.data?.rule_component_bindings ?? []) as RuleBinding[])
    .filter((b) => (b.scope ?? '') === 'global')

  for (const item of bindingPlan) {
    const exists = bindings.some((b) =>
      (b.scope_key ?? '') === ''
      && (b.slot_key ?? '') === item.slot
      && (b.component_id ?? '') === item.component_id
    )
    expect(exists, `missing status binding ${item.slot} -> ${item.component_id}`).toBeTruthy()
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  fs.writeFileSync(
    reportFile,
    JSON.stringify(
      {
        timestamp: new Date().toISOString(),
        clientBase: CLIENT_BASE,
        templatesByKind: Object.fromEntries(templateByKind.entries()),
        updatedComponents,
        createdComponents,
        bindingPlan,
      },
      null,
      2
    )
  )
})

test('规则绑定页：展示 live rule discovery 并支持一键填充', async ({ page, context, request }) => {
  await ensureLoggedIn(page, context, request)

  const discoveryItems = await loadRuleDiscovery(request)
  test.skip(discoveryItems.length === 0, '当前环境没有可展示的 rule discovery 数据')

  await gotoComponents(page)
  await openRuleBindingsTab(page)

  await expect(page.getByText('当前站点发现的规则语义')).toBeVisible()
  await expect(page.getByRole('button', { name: '刷新规则' })).toBeVisible()

  // UI may truncate discovery for responsiveness; drive assertions from a visible card.
  const ruleCard = page
    .locator('div.px-3.py-3')
    .filter({ has: page.getByRole('button', { name: '使用字段' }) })
    .first()
  await expect(ruleCard, 'no visible rule card with fillable fields').toBeVisible()

  const scopeSelect = page.locator('label:has-text("范围") + select').first()
  const scopeKeyInput = page.locator('label:has-text("scope_key") + input').first()
  const slotSelect = page.locator('label:has-text("Slot Key") + select').first()

  await ruleCard.getByRole('button', { name: '请填写 Rule 范围' }).click()
  await expect(scopeSelect).toHaveValue('rule')
  const filledRuleId = (await scopeKeyInput.inputValue()).trim()
  expect(Number(filledRuleId), 'fill rule scope should write a positive rule id').toBeGreaterThan(0)

  const matched = discoveryItems.find((item) => String(item.rule_id) === filledRuleId)
  if (matched) {
    await expectRuleCardLabel(ruleCard, sourceGroupLabel(matched.source_group))
    await expectRuleCardLabel(ruleCard, routingProfileLabel(matched.routing_profile))
    await expectRuleCardLabel(ruleCard, deliveryTargetLabel(matched.delivery_target))
  }

  const fieldCard = ruleCard
    .locator('div.rounded-lg.border.border-gray-200')
    .filter({ has: page.getByRole('button', { name: '使用字段' }) })
    .first()
  await expect(fieldCard).toBeVisible()
  await fieldCard.getByRole('button', { name: '使用字段' }).click()
  await expect(scopeSelect).toHaveValue('rule')
  await expect(scopeKeyInput).toHaveValue(filledRuleId)
  const filledSlot = (await slotSelect.inputValue()).trim()
  expect(filledSlot, 'use field should fill a slot key').not.toBe('')
  if (matched?.fields?.length) {
    const expectedSlots = new Set(matched.fields.map((f) => f.required_slot_key))
    expect(expectedSlots.has(filledSlot), `slot ${filledSlot} not in discovery fields`).toBeTruthy()
  }
})

test('站点页：手工保存绑定后立即恢复连通测试与规则发现', async ({ page, context, request }) => {
  await ensureLoggedIn(page, context, request)

  const bindingsBefore = await loadDomainTokenBindings(request)
  const labWpBase = process.env.WPTSALL_WP_BASE ?? 'http://127.0.0.1:9083'
  const bootstrapSecrets = loadBootstrapRouteSecrets()
  const targetBinding =
    bindingsBefore.find((item) => item.api_base_url === labWpBase)
    ?? bindingsBefore.find((item) => item.api_base_url === 'https://blog.wpmm.cc')
    ?? bindingsBefore[0]
  expect(targetBinding, 'No domain token binding available for live site manual test').toBeTruthy()
  expect(
    targetBinding?.route_secret_set ?? false,
    'route_secret not set on selected binding',
  ).toBeTruthy()
  // Prefer plaintext from bootstrap JSON / env for a real credential round-trip.
  // Local-first clients often keep secrets only in SQLite (status reports
  // route_secret_set=true but the JSON file is empty) — empty inputs mean
  // "keep current value", which still exercises save → test → discovery.
  const currentSecret = (
    bootstrapSecrets[targetBinding!.api_base_url]
    ?? process.env.ROUTE_SECRET
    ?? process.env.WPTSALL_ROUTE_SECRET
    ?? ''
  ).trim()

  await gotoSites(page)

  const siteRow = page.locator('tbody tr').filter({ hasText: targetBinding!.api_base_url }).first()
  await expect(siteRow).toBeVisible()
  await siteRow.getByRole('button', { name: '编辑' }).click()

  const modal = page.locator('div.bg-white.rounded-xl.shadow-xl').filter({ hasText: '编辑站点' }).first()
  await expect(modal).toBeVisible()

  // The edit modal fields carry stable data-testid anchors (Sites.svelte:
  // sites-modal-url / sites-modal-token / sites-modal-route-secret). Match
  // those instead of the translated placeholder copy — the placeholders are
  // i18n-driven and drifted once already (placeholder_token_edit and
  // placeholder_route_secret rewrites broke placeholder-substring locators).
  const urlInput = modal.locator('[data-testid="sites-modal-url"]')
  const tokenInput = modal.locator('[data-testid="sites-modal-token"]')
  const routeSecretInput = modal.locator('[data-testid="sites-modal-route-secret"]')

  await expect(urlInput).toHaveValue(targetBinding!.api_base_url)
  // The edit dialog never echoes stored secrets; empty input means "keep
  // the current value".
  await expect(tokenInput).toHaveValue('')
  await expect(routeSecretInput).toHaveValue('')

  if (currentSecret) {
    // Re-save the same secret (trailing spaces exercise server-side trimming)
    await routeSecretInput.fill(`${currentSecret}   `)
  }
  await modal.getByRole('button', { name: '保存' }).click()
  await expect(modal).toBeHidden({ timeout: 15_000 })
  await expect(page.getByText('Token 已保存')).toBeVisible()

  const testResponsePromise = page.waitForResponse((response) =>
    response.url().includes('/api/domain-tokens/test') && response.request().method() === 'POST'
  )
  await siteRow.getByRole('button', { name: '测试' }).click()
  const testResponse = await testResponsePromise
  expect(testResponse.ok(), `domain token test HTTP ${testResponse.status()}`).toBeTruthy()
  const testPayload = await testResponse.json()
  expect(testPayload?.success).toBeTruthy()

  const bindingsAfter = await loadDomainTokenBindings(request)
  const updatedBinding = bindingsAfter.find((item) => item.api_base_url === targetBinding!.api_base_url)
  expect(updatedBinding, 'Updated domain binding missing after manual save').toBeTruthy()
  expect(
    updatedBinding?.route_secret_set ?? false,
    'route_secret should remain set after manual save',
  ).toBeTruthy()
  expect(Number(updatedBinding?.token_len ?? 0)).toBeGreaterThan(0)

  const discoveryItems = await loadRuleDiscovery(request)
  expect(discoveryItems.length, 'rule discovery should remain available after manual site save').toBeGreaterThan(0)
})
