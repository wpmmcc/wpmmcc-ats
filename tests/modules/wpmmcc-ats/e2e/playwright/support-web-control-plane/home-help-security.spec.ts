import { test, expect } from '../lib/page-errors'
import { clearSession, loginAsAdmin } from './helpers'

test.describe('Home / Help / Security', () => {
  test('public home page renders CTA cards and help entry', async ({ page }) => {
    await clearSession(page)
    await page.goto('/')

    const main = page.locator('main')
    await expect(main).toBeVisible()
    await expect(main.locator('a[href="/register"]').first()).toBeVisible()
    await expect(main.locator('a[href="/help"]').first()).toBeVisible()
    await expect(page.getByText(/client/i).first()).toBeVisible()
    await expect(page.getByText(/plugin/i).first()).toBeVisible()
  })

  test('authenticated home page shows dashboard entry instead of register CTA', async ({ page }) => {
    await loginAsAdmin(page)
    await page.goto('/')

    const main = page.locator('main')
    await expect(main.locator('a[href="/dashboard"]').first()).toBeVisible()
    await expect(main.locator('a[href="/register"]')).toHaveCount(0)
  })

  test('help center renders mocked list data for guest users', async ({ page }) => {
    await clearSession(page)

    await page.route('**/api/v1/help/docs**', async (route) => {
      const url = new URL(route.request().url())
      if (url.pathname.endsWith('/api/v1/help/docs')) {
        await route.fulfill({
          json: {
            success: true,
            data: {
              locale: 'en',
              categories: [
                { id: 1, slug: 'guides', name: 'Guides', sort_order: 1 },
              ],
              items: [
                {
                  slug: 'getting-started',
                  locale: 'en',
                  title: 'Getting Started',
                  summary: 'How to begin',
                  sort_order: 1,
                  updated_at: '2026-03-11T00:00:00Z',
                  category_id: 1,
                  category_slug: 'guides',
                  category_name: 'Guides',
                },
              ],
              count: 1,
            },
          },
        })
        return
      }

      await route.fulfill({ json: { success: false, error: { message: 'unexpected route' } } })
    })

    await page.goto('/help')

    const helpMain = page.locator('main')
    const sidebar = helpMain.locator('aside')

    await expect(helpMain.locator('h1')).toBeVisible()
    await expect(sidebar.locator('input[placeholder]')).toBeVisible()
    await expect(sidebar.locator('select')).toBeVisible()
    await expect(helpMain.locator('a[href="/login"]').first()).toBeVisible()
    await expect(page.getByText('Getting Started')).toBeVisible()
  })

  test('help detail route renders mocked document content', async ({ page }) => {
    await clearSession(page)

    await page.route('**/api/v1/help/docs**', async (route) => {
      const url = new URL(route.request().url())
      if (url.pathname.endsWith('/api/v1/help/docs/getting-started')) {
        await route.fulfill({
          json: {
            success: true,
            data: {
              id: 11,
              slug: 'getting-started',
              locale: 'en',
              title: 'Getting Started',
              summary: 'How to begin',
              content_markdown: 'Step 1\nStep 2',
              sort_order: 1,
              updated_at: '2026-03-11T00:00:00Z',
              available_locales: ['en'],
              category_id: 1,
              category_slug: 'guides',
              category_name: 'Guides',
            },
          },
        })
        return
      }

      if (url.pathname.endsWith('/api/v1/help/docs')) {
        await route.fulfill({
          json: {
            success: true,
            data: {
              locale: 'en',
              categories: [
                { id: 1, slug: 'guides', name: 'Guides', sort_order: 1 },
              ],
              items: [
                {
                  slug: 'getting-started',
                  locale: 'en',
                  title: 'Getting Started',
                  summary: 'How to begin',
                  sort_order: 1,
                  updated_at: '2026-03-11T00:00:00Z',
                  category_id: 1,
                  category_slug: 'guides',
                  category_name: 'Guides',
                },
              ],
              count: 1,
            },
          },
        })
        return
      }

      await route.fulfill({ json: { success: false, error: { message: 'unexpected route' } } })
    })

    await page.goto('/help/getting-started?category=guides')

    const detailSection = page.locator('section').filter({ has: page.locator('pre') })

    await expect(detailSection.locator('h2')).toContainText('Getting Started')
    await expect(detailSection.locator('pre')).toContainText('Step 1')
    await expect(detailSection.locator('span.text-blue-700')).toContainText('Guides')
  })

  test('security page renders mocked whitelist data and supports add flow', async ({ page }) => {
    await loginAsAdmin(page)

    let whitelist = ['127.0.0.1', '10.0.0.0/24']

    await page.route('**/api/v1/client/ip-whitelist', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          json: {
            success: true,
            data: {
              allowed_ips: whitelist,
              count: whitelist.length,
              max_entries: 20,
            },
          },
        })
        return
      }

      if (route.request().method() === 'PUT') {
        const payload = route.request().postDataJSON() as { allowed_ips?: string[] }
        whitelist = payload.allowed_ips || []
        await route.fulfill({
          json: {
            success: true,
            data: {
              allowed_ips: whitelist,
              count: whitelist.length,
            },
          },
        })
        return
      }

      await route.fulfill({ status: 405, json: { success: false } })
    })

    await page.route('**/api/v1/client/ip-whitelist/add', async (route) => {
      const payload = route.request().postDataJSON() as { ip?: string }
      const ip = payload.ip || ''
      whitelist = [...whitelist, ip]
      await route.fulfill({
        json: {
          success: true,
          data: {
            added: ip,
            count: whitelist.length,
          },
        },
      })
    })

    await page.route('**/api/v1/client/ip-whitelist/remove', async (route) => {
      const payload = route.request().postDataJSON() as { ip?: string }
      const ip = payload.ip || ''
      whitelist = whitelist.filter((entry) => entry !== ip)
      await route.fulfill({
        json: {
          success: true,
          data: {
            removed: ip,
            count: whitelist.length,
          },
        },
      })
    })

    await page.goto('/security')

    await expect(page.locator('h1')).toBeVisible()
    await expect(page.getByText('127.0.0.1')).toBeVisible()
    await expect(page.getByText('10.0.0.0/24')).toBeVisible()
    await expect(page.getByText('2 / 20')).toBeVisible()

    await page.fill('input[type="text"]', '192.168.1.10')
    await page.getByRole('button', { name: /add|添加/i }).click()

    await expect(page.getByText('192.168.1.10')).toBeVisible()
  })
})
