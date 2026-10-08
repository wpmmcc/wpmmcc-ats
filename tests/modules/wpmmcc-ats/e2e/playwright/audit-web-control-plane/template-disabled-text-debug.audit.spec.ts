import { test, expect } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

async function fetchComponentDetailTemplateJson(
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

test.describe('Template Disabled Text Debug', () => {
  test('dump key fields for disabled official text templates', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')

    const ids = [
      'official-fptai-v1',
      'official-ibm-watson-text-v1',
      'official-kakao-v1',
      'official-pangeanic-v1',
      'official-tilde-v1',
    ]

    for (const id of ids) {
      const tj = await fetchComponentDetailTemplateJson(page, id)
      expect(tj, `${id} template_json should be visible to admin`).toBeTruthy()
      const status = String((tj as any)?.status ?? '')
      const supported = (tj as any)?.constraints?.supported_content_formats ?? []
      const modes = (tj as any)?.translation_modes ?? []
      const translatedTextPath = String((tj as any)?.response?.translated_text_path ?? '')
      const requestUrl = String((tj as any)?.request?.url ?? '')
      const signAlgorithm = String((tj as any)?.sign?.algorithm ?? '')

      console.log(
        `[DEBUG] ${id}`,
        JSON.stringify(
          {
            status,
            signAlgorithm,
            requestUrl: requestUrl.slice(0, 180),
            translatedTextPath,
            supported_content_formats: supported,
            translation_modes_count: Array.isArray(modes) ? modes.length : -1,
            translation_modes_preview:
              Array.isArray(modes)
                ? (modes as any[]).slice(0, 3).map((m) => ({
                    id: m?.id,
                    formats: m?.supported_content_formats,
                  }))
                : null,
          },
          null,
          2,
        ),
      )
    }
  })
})
