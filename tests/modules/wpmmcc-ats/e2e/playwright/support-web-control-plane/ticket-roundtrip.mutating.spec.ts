import { test, expect } from '../lib/page-errors'
import { clearSession, loginAsAdmin, loginAsPaidUser } from './helpers'

const allowMutating = process.env.WPTSALL_ALLOW_MUTATING_WEBUI === '1'
const runId = process.env.WPTSALL_TEST_RUN_ID || ''
const fixtureMarker = process.env.WPTSALL_TEST_FIXTURE_MARKER || (runId ? `e2e:${runId}` : '')

test.describe('Website ticket roundtrip', () => {
  test.skip(!allowMutating, 'mutating WebUI tests require WPTSALL_ALLOW_MUTATING_WEBUI=1')
  test.skip(!runId, 'mutating WebUI tests require WPTSALL_TEST_RUN_ID')

  test.beforeEach(async ({ page }) => {
    await clearSession(page)
  })

  test('paid user creates ticket, admin replies, paid user sees reply', async ({ page }) => {
    const subject = `${fixtureMarker} ticket roundtrip`
    const body = `${fixtureMarker} user-visible support ticket body`
    const reply = `${fixtureMarker} admin reply`

    await loginAsPaidUser(page)
    await page.goto('/tickets')
    await page.getByRole('button', { name: /New Ticket|新建工单/i }).click()
    await page.locator('main select').first().selectOption('wptsall')
    await page.locator('input[type="text"]').fill(subject)
    await page.locator('textarea').fill(body)
    await page.getByRole('button', { name: /Submit|提交/i }).click()
    await expect(page.locator('main')).toContainText(subject, { timeout: 10_000 })

    await clearSession(page)
    await loginAsAdmin(page)
    await page.goto('/admin/tickets')
    await expect(page.locator('main')).toContainText(subject, { timeout: 10_000 })
    await page.getByText(subject).click()
    await page.locator('textarea').fill(reply)
    await page.locator('select').last().selectOption('resolved')
    await page.getByRole('button', { name: /Update Ticket|更新工单/i }).click()
    await expect(page.locator('main')).toContainText(reply, { timeout: 10_000 })

    await clearSession(page)
    await loginAsPaidUser(page)
    await page.goto('/tickets')
    await page.getByText(subject).click()
    await expect(page.locator('main')).toContainText(reply, { timeout: 10_000 })
  })
})
