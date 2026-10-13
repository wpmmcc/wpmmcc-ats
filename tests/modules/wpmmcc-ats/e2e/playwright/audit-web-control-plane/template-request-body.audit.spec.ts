import { test, expect } from '@playwright/test'
import { loginAsAdmin } from '../support-web-control-plane/helpers'

async function fetchComponentTemplateJson(
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

test.describe('Template Request Body Audit', () => {
  test('azure openai request body keeps nested messages', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/templates')

    for (const id of ['official-azure-openai-text-v1', 'official-openai-text-v1', 'official-deepl-v1', 'official-google-v2', 'official-baidu-v1']) {
      const tj = await fetchComponentTemplateJson(page, id)
      expect(tj, `${id} should exist`).toBeTruthy()

      const hasDefaultValues = Boolean((tj as any)?.default_values && typeof (tj as any).default_values === 'object')
      const hasTranslationModes = Array.isArray((tj as any)?.translation_modes) && (tj as any).translation_modes.length > 0
      console.log(`[AUDIT] ${id} has default_values=`, hasDefaultValues, 'has translation_modes=', hasTranslationModes)
      if (hasTranslationModes) {
        const modes = (tj as any).translation_modes as any[]
        console.log(`[AUDIT] ${id} translation_modes count=`, modes.length)
        const first = modes[0] || null
        if (first) {
          console.log(
            `[AUDIT] ${id} translation_modes[0] preview=`,
            JSON.stringify({
              id: first.id,
              supported_content_formats: first.supported_content_formats,
              request_overrides_keys: first.request_overrides ? Object.keys(first.request_overrides) : [],
              request_overrides_body_keys:
                first.request_overrides?.body && typeof first.request_overrides.body === 'object' && !Array.isArray(first.request_overrides.body)
                  ? Object.keys(first.request_overrides.body)
                  : [],
            }),
          )
        }
      }

      const request = (tj?.request as Record<string, unknown> | undefined) || {}
      console.log(`[AUDIT] ${id} request.method/url/body_type=`, (request as any).method, (request as any).url, (request as any).body_type)
      const body = request.body
      console.log(`[AUDIT] ${id} request.body type=`, Array.isArray(body) ? 'array' : typeof body)
      console.log(
        `[AUDIT] ${id} request.body keys=`,
        body && typeof body === 'object' && !Array.isArray(body) ? Object.keys(body as Record<string, unknown>) : [],
      )
      console.log(`[AUDIT] ${id} request.body preview=`, JSON.stringify(body).slice(0, 420))
    }
  })
})
