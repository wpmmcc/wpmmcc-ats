// catalog: WEBUI-UI-Settings
// catalog: WEBUI-API-GET-api-worker-config
// catalog: WEBUI-API-POST-api-worker-config
// oracle: L2
import { expect, test, type Page } from '@playwright/test'

const base = process.env.WPTSALL_CLIENT_LOCAL_UI_BASE_URL

test.beforeAll(() => {
  expect(base, 'run through the owned client-local-ui lane, never a shared client').toBeTruthy()
})

async function openWorkerSettings(page: Page) {
  await page.getByRole('button', { name: /^(设置|Settings)$/ }).click()
  await page.getByTestId('settings-tab-worker').click()
  await expect(page.locator('#settings-callback-retry-max')).toBeVisible()
}

test('retry zero survives save, API readback and page reload', async ({ page }) => {
  await page.goto(`${base}/`)
  await openWorkerSettings(page)
  await page.locator('#settings-callback-retry-max').fill('0')
  await page.locator('#settings-fetch-retry-max').fill('0')
  const saved = page.waitForResponse(
    response => new URL(response.url()).pathname === '/api/worker/config'
      && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: /保存 Worker 配置|Save Worker Config/i }).click()
  const response = await saved
  expect(response.status()).toBe(200)
  const body = await response.json()
  expect(body.data.callback_retry_max).toBe(0)
  expect(body.data.fetch_retry_max).toBe(0)
  await expect(page.locator('#settings-callback-retry-max')).toHaveValue('0')
  await expect(page.locator('#settings-fetch-retry-max')).toHaveValue('0')

  const readback = await page.request.get(`${base}/api/worker/config`)
  expect(readback.ok()).toBeTruthy()
  const data = (await readback.json()).data
  expect(data.callback_retry_max).toBe(0)
  expect(data.fetch_retry_max).toBe(0)
  await page.reload()
  await openWorkerSettings(page)
  await expect(page.locator('#settings-callback-retry-max')).toHaveValue('0')
  await expect(page.locator('#settings-fetch-retry-max')).toHaveValue('0')
  // Saving an untouched form must not corrupt a persisted zero either.
  const savedAgain = page.waitForResponse(
    next => new URL(next.url()).pathname === '/api/worker/config'
      && next.request().method() === 'POST',
  )
  await page.getByRole('button', { name: /保存 Worker 配置|Save Worker Config/i }).click()
  expect((await (await savedAgain).json()).data).toEqual(data)
  const unchanged = (await (await page.request.get(`${base}/api/worker/config`)).json()).data
  expect(unchanged).toEqual(data)
})

test('retry upper bounds are clamped and shown from the saved readback', async ({ page }) => {
  await page.goto(`${base}/`)
  await openWorkerSettings(page)
  await page.locator('#settings-callback-retry-max').fill('11')
  await page.locator('#settings-fetch-retry-max').fill('11')
  const readback = page.waitForResponse(
    response => new URL(response.url()).pathname === '/api/worker/config'
      && response.request().method() === 'GET',
  )
  await page.getByRole('button', { name: /保存 Worker 配置|Save Worker Config/i }).click()
  expect((await readback).ok()).toBeTruthy()
  await expect(page.locator('#settings-callback-retry-max')).toHaveValue('10')
  await expect(page.locator('#settings-fetch-retry-max')).toHaveValue('10')
})

test('readback failure does not show a successful save toast', async ({ page }) => {
  await page.goto(`${base}/`)
  await openWorkerSettings(page)
  await page.route('**/api/worker/config', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({
        success: false,
        error: { code: 'TEST_READBACK_FAILED', message: 'test readback failed' },
      }),
    })
  })
  await page.getByRole('button', { name: /保存 Worker 配置|Save Worker Config/i }).click()
  await expect(page.getByText(/保存失败|Save failed/i)).toBeVisible()
  await expect(page.getByText('test readback failed')).toBeVisible()
  await expect(page.getByText(/Worker 配置已保存|Worker config saved/i)).toHaveCount(0)
})

test('invalid workflow rejects the whole save over the real HTTP boundary', async ({ request }) => {
  const before = (await (await request.get(`${base}/api/worker/config`)).json()).data
  const response = await request.post(`${base}/api/worker/config`, {
    data: {
      poll_seconds: before.poll_seconds === 99 ? 98 : 99,
      auto_start_worker: !before.auto_start_worker,
      workflow_policy: { schema_version: 'workflow-policy-invalid', default_mode: 'review' },
    },
  })
  expect(response.status()).toBe(400)
  expect((await response.json()).success).toBe(false)
  const after = (await (await request.get(`${base}/api/worker/config`)).json()).data
  expect(after).toEqual(before)
})
