import { expect, test, type Page } from '@playwright/test'

/**
 * P1-E: Provider setup wizard happy path against mocked local Client APIs.
 * Does not require website control plane or live provider credentials.
 */

const catalogItem = {
  entry_id: 'openai-compatible',
  template_id: 'openai-compatible-chat-completions-v1',
  name: 'OpenAI-compatible Chat Completions',
  vendor_id: 'openai',
  family: 'openai_compatible',
  kind: 'text',
  supported_content_formats: ['plain_text'],
  source: 'builtin',
  verified: true,
  requires_local_credentials: true,
  template: { auth: { fields: [{ name: 'api_key', required: true }] } },
}

async function mockWizardApis(page: Page) {
  // Playwright: last registered matching route wins. Register generics first,
  // then specific paths so install / quick-test / versions are not stolen.
  await page.route('**/api/components/local/*', async (route) => {
    if (route.request().method() === 'PUT') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { id: 'openai-openai-compatible-chat-completions-v1' },
        }),
      })
      return
    }
    await route.fallback()
  })
  await page.route('**/api/status**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { authenticated: true, legacy_mode: false },
      }),
    })
  })
  await page.route('**/api/provider-catalog**', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            catalog_version: 'builtin-3',
            template_count: 1,
            source: 'builtin',
            verified: true,
            offline: true,
          },
        }),
      })
      return
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          schema: 'wptsall-provider-catalog-manifest.v1',
          catalog_version: 'builtin-3',
          offline: true,
          items: [catalogItem],
        },
      }),
    })
  })
  await page.route('**/api/components/local/install-from-catalog**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          id: 'openai-openai-compatible-chat-completions-v1',
          template_id: catalogItem.template_id,
          catalog_entry_id: catalogItem.entry_id,
          enabled: false,
          overwrite: false,
        },
      }),
    })
  })
  await page.route('**/api/vendor-keys**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { id: 'wizard-key-1' } }),
    })
  })
  await page.route('**/api/components/local/*/versions**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { component_id: 'openai-openai-compatible-chat-completions-v1', version: 'v1' },
      }),
    })
  })
  await page.route('**/api/components/local/*/quick-test**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { translated_text: '你好世界', elapsed_ms: 8 },
      }),
    })
  })
  await page.route('**/api/rule-component-bindings/upsert**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          scope: 'global',
          slot_key: 'plain_text',
          component_id: 'openai-openai-compatible-chat-completions-v1',
        },
      }),
    })
  })
}

test.describe('P1-E provider setup wizard', () => {
  test('walks install → key → quick-test → enable → route with mocks', async ({ page }) => {
    await mockWizardApis(page)

    // Client WebUI uses in-app navigation (currentPage), not hash routes.
    const base = process.env.WPTSALL_CLIENT_UI_BASE || process.env.CLIENT_BASE || 'http://127.0.0.1:8977'
    await page.goto(base)
    await page.getByRole('button', { name: /API Keys|API 密钥|密钥/ }).click()
    await expect(page.getByRole('heading', { name: /API Keys|API 密钥|密钥/ })).toBeVisible({
      timeout: 10000,
    })

    // Default tab is Vendor Catalog; click explicitly if another tab is active.
    const catalogTab = page.locator('button:has-text("Provider"), button:has-text("Vendors"), button:has-text("目录"), button:has-text("厂商")').first()
    if (await catalogTab.count()) {
      await catalogTab.click()
    }

    await expect(page.getByText('OpenAI-compatible Chat Completions').first()).toBeVisible({
      timeout: 10000,
    })
    await page.getByTestId('open-provider-wizard').first().click()
    await expect(page.getByTestId('provider-setup-wizard')).toBeVisible()

    await expect(page.getByTestId('wizard-install-next')).toBeEnabled()
    await page.getByTestId('wizard-install-next').click()
    await expect(page.getByTestId('wizard-api-key')).toBeVisible({ timeout: 10000 })
    await page.getByTestId('wizard-api-key').fill('sk-mock-wizard')
    await page.getByTestId('wizard-key-next').click()
    await expect(page.getByTestId('wizard-test-next')).toBeVisible({ timeout: 10000 })
    await page.getByTestId('wizard-test-next').click()
    await expect(page.getByTestId('wizard-enable-next')).toBeVisible({ timeout: 10000 })
    await page.getByTestId('wizard-enable-next').click()
    await expect(page.getByTestId('wizard-route-next')).toBeVisible({ timeout: 10000 })
    await page.getByTestId('wizard-route-next').click()
    await expect(page.getByTestId('wizard-done')).toBeVisible({ timeout: 10000 })
  })
})
