import { test, expect, request } from '@playwright/test'
import { WP_BASE_URL } from './helpers'

/**
 * Frontend SEO smoke (HTTP/HTML reachability).
 *
 * Paired source↔translated URL / body / Yoast SEO correspondence lives in
 * `22-source-translated-correspondence.spec.ts`
 * (`npm run test:support:plugin-correspondence`).
 */

test.describe('07 Frontend SEO', () => {
  test('Homepage returns 200 and is HTML', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/`)
    expect(res.status()).toBe(200)
    const body = await res.text()
    expect(body).toMatch(/<html/)
  })

  test('Homepage has no fatal error markers', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/`)
    const body = await res.text()
    const fatal = ['Fatal error:', 'Parse error:', 'There has been a critical error', 'Uncaught Error']
    for (const m of fatal) {
      expect(body, `Frontend has marker: ${m}`).not.toContain(m)
    }
  })

  test('404 page returns 404 status', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/__nonexistent_path_12345__/`)
    expect(res.status()).toBe(404)
  })

  test('robots.txt or feed endpoints respond', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/feed/`)
    expect([200, 304]).toContain(res.status())
  })
})
