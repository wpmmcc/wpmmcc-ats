import { test, expect } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

type ComponentItem = {
  id: string
  status?: string
  type?: string
  signing_algorithm?: string
}

async function fetchAllComponents(page: import('@playwright/test').Page): Promise<ComponentItem[]> {
  const perPage = 200
  let pageNo = 1
  const merged: ComponentItem[] = []

  while (true) {
    const chunk = await page.evaluate(async ({ pageNo, perPage }) => {
      const token = localStorage.getItem('session_token') || ''
      const resp = await fetch(`/api/v1/components?page=${pageNo}&per_page=${perPage}`, {
        headers: token ? { 'X-Client-Session': token } : {},
      })
      if (!resp.ok) {
        return {
          items: [] as ComponentItem[],
          totalPages: 1,
          status: resp.status,
        }
      }
      const json = await resp.json()
      return {
        items: (json?.data?.items || []) as ComponentItem[],
        totalPages: Number(json?.data?.total_pages || 1),
        status: resp.status,
      }
    }, { pageNo, perPage })

    merged.push(...chunk.items)
    if (pageNo >= chunk.totalPages) {
      break
    }
    pageNo += 1
  }

  return merged
}

async function fetchComponentDetail(
  page: import('@playwright/test').Page,
  componentId: string,
): Promise<Record<string, unknown> | null> {
  return page.evaluate(async (componentId) => {
    const token = localStorage.getItem('session_token') || ''
    const resp = await fetch(`/api/v1/components/${encodeURIComponent(componentId)}`, {
      headers: token ? { 'X-Client-Session': token } : {},
    })
    if (!resp.ok) return null
    const json = await resp.json()
    return (json?.data?.component?.template_json || null) as Record<string, unknown> | null
  }, componentId)
}

test.describe('Template Status Audit', () => {
  test('conflict templates are disabled and active auth-migration templates stay active', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')

    const all = await fetchAllComponents(page)
    expect(all.length).toBeGreaterThan(0)
    const byId = new Map(all.map((item) => [item.id, item]))
    console.log(`[AUDIT] components fetched=${all.length}`)
    console.log(`[AUDIT] first_ids=${all.slice(0, 12).map((item) => item.id).join(', ')}`)

    const mustBeDisabled = [
      'official-azure-vision-v1',
      'official-azure-video-v1',
      'official-systran-file-v1',
    ]
    const mustBeActive = [
      'official-azure-v1',
      'official-azure-doc-v1',
      'official-yandex-v2',
      'official-systran-text-v1',
    ]

    for (const id of mustBeDisabled) {
      const item = byId.get(id)
      if (!item) {
        console.log(`[AUDIT] ${id} not present in current template library`)
        continue
      }
      console.log(`[AUDIT] ${id} status=${item.status ?? 'N/A'}`)
      expect((item.status || '').toLowerCase(), `status mismatch for ${id}`).toBe('disabled')
    }

    for (const id of mustBeActive) {
      const item = byId.get(id)
      if (!item) {
        console.log(`[AUDIT] ${id} not present in current template library`)
        continue
      }
      console.log(`[AUDIT] ${id} status=${item.status ?? 'N/A'}`)
      expect((item.status || '').toLowerCase(), `status mismatch for ${id}`).toBe('active')
    }

    const azureOpenai = byId.get('official-azure-openai-text-v1')
    expect(azureOpenai, 'official-azure-openai-text-v1 should exist after create flow').toBeDefined()
    const azureOpenaiTemplate = await fetchComponentDetail(page, 'official-azure-openai-text-v1')
    expect(azureOpenaiTemplate).toBeTruthy()
    const headers = (azureOpenaiTemplate?.request as { headers?: Record<string, string> } | undefined)?.headers || {}
    expect(headers['api-key']).toBe('{{auth.api_key}}')
    console.log(
      `[AUDIT] official-azure-openai-text-v1 exists=${Boolean(azureOpenai)} status=${azureOpenai?.status ?? 'N/A'}`,
    )
  })
})
