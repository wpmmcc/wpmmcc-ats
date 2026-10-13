import { test, expect, type APIRequestContext, type Page } from '@playwright/test'
import { ensureClientWebUiLoggedIn } from './helpers/client-oauth-login'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const CLIENT_BASE = resolveSlotClientBase()

type ServerTemplate = {
  id: string
  name: string
  owner_type: string
  version: string
  kind: string
  status?: string
  supported_business_lines: string[]
  supported_content_formats: string[]
  supported_formats: string[]
  max_file_size_mb: number
  size_class: string
  template_group: string
  vendor_id: string
  signing_algorithm: string
}

const MOCK_TEMPLATES: ServerTemplate[] = [
  {
    id: 'official-elevenlabs-dubbing-v1',
    name: 'ElevenLabs Dubbing',
    owner_type: 'official',
    version: '1.0.0',
    kind: 'video_translation',
    supported_business_lines: ['post_content', 'custom_model'],
    supported_content_formats: ['media_ref'],
    supported_formats: ['mp4', 'mov', 'webm'],
    max_file_size_mb: 200,
    size_class: 'L',
    template_group: 'specialty',
    vendor_id: 'official-vendor-elevenlabs',
    signing_algorithm: 'none',
  },
  {
    id: 'official-google-text-v1',
    name: 'Google Text Translation',
    owner_type: 'official',
    version: '1.0.0',
    kind: 'text_translation',
    supported_business_lines: ['post_content', 'plugin_i18n'],
    supported_content_formats: ['plain_text', 'rich_html'],
    supported_formats: ['txt', 'html'],
    max_file_size_mb: 10,
    size_class: 'S',
    template_group: 'mainstream',
    vendor_id: 'official-vendor-google',
    signing_algorithm: 'none',
  },
]

function mockStatusPayload() {
  return {
    success: true,
    data: {
      logged_in: true,
      session_token_prefix: 'sess_mock',
      server_base: 'https://www.wpmm.cc',
      domains: [],
      components: MOCK_TEMPLATES,
      component_bindings: { components: {} },
      rule_component_bindings: [],
      worker_running: false,
      worker_runs: [],
      poll_seconds: 10,
      worker_loop_running: false,
      worker_loop_poll_seconds: 20,
      worker_recent_runs: [],
    },
  }
}

function urlContainsParams(rawUrl: string, expected: Record<string, string>): boolean {
  const url = new URL(rawUrl)
  return Object.entries(expected).every(([key, value]) => url.searchParams.get(key) === value)
}

async function ensureLoggedIn(page: Page, _context: unknown, request: APIRequestContext) {
  await ensureClientWebUiLoggedIn(page, request, { requireServerSearch: true })
}

async function fetchServerTemplates(
  request: APIRequestContext,
  params: Record<string, string> = {},
): Promise<{ ok: boolean; status: number; json: any; items: ServerTemplate[] }> {
  const query = new URLSearchParams({ page: '1', per_page: '200', ...params }).toString()
  const res = await request.get(`${CLIENT_BASE}/api/components/server-search?${query}`)
  const json = await res.json().catch(() => null)
  return {
    ok: res.ok(),
    status: res.status(),
    json,
    items: (json?.data?.items ?? []) as ServerTemplate[],
  }
}

async function waitServerSearchRequestAfter(
  capturedUrls: string[],
  action: () => Promise<void>,
  expected: Record<string, string>,
): Promise<URL> {
  const startIdx = capturedUrls.length
  await action()
  let matched = ''
  await expect
    .poll(() => {
      const slice = capturedUrls.slice(startIdx)
      matched = slice.find((raw) => urlContainsParams(raw, expected)) ?? ''
      return matched !== ''
    }, { timeout: 15_000 })
    .toBe(true)
  return new URL(matched)
}

test('Components Server 模板筛选会透传到 server-search 查询参数', async ({ page }) => {
  const capturedServerSearchUrls: string[] = []

  await page.route('**/api/stats/overview', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { by_domain: [], by_status: [], daily: [] } }),
    })
  })

  await page.route('**/api/status', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockStatusPayload()),
    })
  })

  await page.route('**/api/components/server-search**', async (route) => {
    capturedServerSearchUrls.push(route.request().url())
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          items: MOCK_TEMPLATES,
          page: 1,
          per_page: 20,
          total: MOCK_TEMPLATES.length,
          total_pages: 1,
        },
      }),
    })
  })

  await page.goto(CLIENT_BASE)
  await page.getByRole('button', { name: '翻译组件' }).click()
  await page.getByRole('button', { name: 'Server 模板' }).click()

  const searchInput = page.getByPlaceholder('搜索模板名称、ID、Vendor...')
  await expect(searchInput).toBeVisible()

  await expect.poll(() => capturedServerSearchUrls.length > 0, { timeout: 15_000 }).toBe(true)
  const initialUrl = new URL(capturedServerSearchUrls[capturedServerSearchUrls.length - 1])
  expect(initialUrl.searchParams.get('page')).toBe('1')
  expect(initialUrl.searchParams.get('per_page')).toBe('20')

  const filterPanel = page.locator('div').filter({ has: searchInput }).first()
  const kindSelect = filterPanel.locator('select:has(option:has-text("全部类型"))').first()
  const groupSelect = filterPanel.locator('select:has(option:has-text("全部分组"))').first()
  const contentFormatSelect = filterPanel.locator('select:has(option:has-text("全部内容格式"))').first()
  const sizeClassSelect = filterPanel.locator('select:has(option:has-text("全部大小分级"))').first()
  const businessLineSelect = filterPanel.locator('select:has(option:has-text("全部业务线"))').first()
  await expect(kindSelect).toBeVisible()
  await expect(groupSelect).toBeVisible()
  await expect(contentFormatSelect).toBeVisible()
  await expect(sizeClassSelect).toBeVisible()
  await expect(businessLineSelect).toBeVisible()

  const kindUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await kindSelect.selectOption('video_translation')
  }, { kind: 'video_translation' })
  expect(kindUrl.searchParams.get('kind')).toBe('video_translation')

  const groupUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await groupSelect.selectOption('specialty')
  }, { kind: 'video_translation', group: 'specialty' })
  expect(groupUrl.searchParams.get('group')).toBe('specialty')
  expect(groupUrl.searchParams.get('kind')).toBe('video_translation')

  const fmtUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await contentFormatSelect.selectOption('media_ref')
  }, { kind: 'video_translation', group: 'specialty', content_format: 'media_ref' })
  expect(fmtUrl.searchParams.get('content_format')).toBe('media_ref')
  expect(fmtUrl.searchParams.get('group')).toBe('specialty')

  const sizeUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await sizeClassSelect.selectOption('L')
  }, { kind: 'video_translation', group: 'specialty', content_format: 'media_ref', size_class: 'L' })
  expect(sizeUrl.searchParams.get('size_class')).toBe('L')

  const lineUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await businessLineSelect.selectOption('post_content')
  }, {
    kind: 'video_translation',
    group: 'specialty',
    content_format: 'media_ref',
    size_class: 'L',
    business_line: 'post_content',
  })
  expect(lineUrl.searchParams.get('business_line')).toBe('post_content')
  expect(lineUrl.searchParams.get('size_class')).toBe('L')

  const queryUrl = await waitServerSearchRequestAfter(capturedServerSearchUrls, async () => {
    await searchInput.fill('elevenlabs')
  }, {
    q: 'elevenlabs',
    kind: 'video_translation',
    group: 'specialty',
    content_format: 'media_ref',
    size_class: 'L',
    business_line: 'post_content',
  })
  expect(queryUrl.searchParams.get('q')).toBe('elevenlabs')
  expect(queryUrl.searchParams.get('kind')).toBe('video_translation')
  expect(queryUrl.searchParams.get('group')).toBe('specialty')
  expect(queryUrl.searchParams.get('content_format')).toBe('media_ref')
  expect(queryUrl.searchParams.get('size_class')).toBe('L')
  expect(queryUrl.searchParams.get('business_line')).toBe('post_content')
})

test('Server-search 默认只返回 active，status=disabled 可查询停用模板', async ({ page, context, request }) => {
  await ensureLoggedIn(page, context, request)
  await request.post(`${CLIENT_BASE}/api/components/refresh`, { data: {} })

  const invalidStatusProbe = await request.get(
    `${CLIENT_BASE}/api/components/server-search?page=1&per_page=1&status=__invalid__`,
  )
  const invalidStatusJson = await invalidStatusProbe.json().catch(() => null)
  const supportsStatusFilter =
    !invalidStatusProbe.ok() &&
    invalidStatusJson?.success === false &&
    invalidStatusJson?.error?.code === 'INVALID_COMPONENT_STATUS'
  if (!supportsStatusFilter) {
    test.skip(true, 'server-search status filter not supported in current runtime')
  }

  const defaultResp = await fetchServerTemplates(request)
  expect(defaultResp.ok, `live server-search unavailable (default), HTTP=${defaultResp.status}`).toBe(true)
  expect(defaultResp.json?.success).toBe(true)
  const defaultItems = defaultResp.items
  expect(defaultItems.length).toBeGreaterThan(0)
  expect(
    defaultItems.every((item) => (item.status ?? 'active') === 'active'),
    'default query must not include disabled templates',
  ).toBe(true)

  const disabledResp = await fetchServerTemplates(request, { status: 'disabled' })
  expect(disabledResp.ok, `live server-search unavailable (status=disabled), HTTP=${disabledResp.status}`).toBe(true)
  expect(disabledResp.json?.success).toBe(true)
  const disabledItems = disabledResp.items
  const hasMissingStatusField = disabledItems.some((item) => typeof item.status !== 'string')
  if (hasMissingStatusField) {
    test.skip(true, 'server-search response does not expose item.status in current runtime')
  }
  expect(
    disabledItems.every((item) => (item.status ?? '').toLowerCase() === 'disabled'),
    'status=disabled should only return disabled templates',
  ).toBe(true)

  const defaultIds = new Set(defaultItems.map((item) => item.id))
  for (const item of disabledItems) {
    expect(defaultIds.has(item.id), `disabled template leaked into default list: ${item.id}`).toBe(false)
  }
})
