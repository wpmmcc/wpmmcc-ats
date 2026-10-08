// catalog: WEBUI-API-GET-api-vendor-keys
// catalog: WEBUI-API-POST-api-vendor-keys
// catalog: WEBUI-API-PREFIX-api-vendor-keys
// oracle: L2
import { expect, test } from '@playwright/test'

test('browser same-origin PUT and DELETE persist through the Origin gate', async ({ page, baseURL }) => {
  expect(baseURL, 'use the owned client-local-ui lane').toBeTruthy()
  await page.goto('/')
  const created = await page.evaluate(async () => {
    const response = await fetch('/api/vendor-keys', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: 'cli04-browser',
        vendor_id: 'cli04-mock',
        label: 'original',
        auth_values: { api_key: 'cli04-browser-fake-key' },
      }),
    })
    return { status: response.status, body: await response.json() }
  })
  expect(created.status).toBe(200)
  expect(created.body.data.id).toBe('cli04-browser')

  const put = page.waitForRequest(
    request => new URL(request.url()).pathname === '/api/vendor-keys/cli04-browser'
      && request.method() === 'PUT',
  )
  const updated = await page.evaluate(async () => {
    const response = await fetch('/api/vendor-keys/cli04-browser', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ label: 'browser-updated' }),
    })
    return { status: response.status, body: await response.json() }
  })
  expect((await (await put).allHeaders()).origin).toBe(baseURL)
  expect(updated.status).toBe(200)
  expect(updated.body.success).toBe(true)
  let readback = await page.request.get('/api/vendor-keys')
  expect(readback.status()).toBe(200)
  expect((await readback.json()).data.items.find(
    (item: { id: string }) => item.id === 'cli04-browser',
  ).label).toBe('browser-updated')

  const remove = page.waitForRequest(
    request => new URL(request.url()).pathname === '/api/vendor-keys/cli04-browser'
      && request.method() === 'DELETE',
  )
  const deleted = await page.evaluate(async () => {
    const response = await fetch('/api/vendor-keys/cli04-browser', { method: 'DELETE' })
    return { status: response.status, body: await response.json() }
  })
  expect((await (await remove).allHeaders()).origin).toBe(baseURL)
  expect(deleted.status).toBe(200)
  expect(deleted.body.data.deleted).toBe(true)
  readback = await page.request.get('/api/vendor-keys')
  expect((await readback.json()).data.items.filter(
    (item: { id: string }) => item.id === 'cli04-browser',
  )).toHaveLength(0)
})
