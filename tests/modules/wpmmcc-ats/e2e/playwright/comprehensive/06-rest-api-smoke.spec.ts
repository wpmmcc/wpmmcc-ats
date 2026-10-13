import { test, expect, request } from '@playwright/test'
import { WP_BASE_URL } from './helpers'

/**
 * REST API SMOKE — read-only liveness checks (REST root / namespace /
 * client-ping / homepage no-fatal).
 *
 * opus5 T-07 (doc 04 §3.2): this spec is deliberately named "-smoke" —
 * it is NOT the REST e2e coverage. The real WP REST write-path e2e lives
 * in the PHP gate (tests/modules/wpmmcc-ats/e2e/php dataflow scripts +
 * integration/contracts), and admin write journeys are covered by the
 * comprehensive specs (17/18/19 + fixture-owner). If you need REST
 * read/write contract proof, run the PHP gate lanes, not this file.
 */

test.describe('06 REST API Endpoints (smoke)', () => {
  test('REST root is accessible', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/wp-json/`)
    expect(res.status()).toBe(200)
    const body = await res.json()
    expect(body).toHaveProperty('namespaces')
    expect(body.namespaces).toContain('wptsall/v2')
  })

  test('wptsall/v2 namespace is registered', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/wp-json/wptsall/v2/`)
    // Even an empty routes list or 401 is fine - namespace exists
    expect([200, 401, 403]).toContain(res.status())
  })

  test('Public REST endpoint client/ping responds', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/wp-json/wptsall/v2/public/ping`)
    expect([200, 404]).toContain(res.status())
    if (res.status() === 200) {
      const body = await res.json()
      expect(body).toHaveProperty('ok')
    }
  })

  test('Frontend homepage returns 200 with no fatal error', async ({ request }) => {
    const res = await request.get(`${WP_BASE_URL}/`)
    expect(res.status()).toBe(200)
    const body = await res.text()
    expect(body).not.toContain('There has been a critical error')
    expect(body).not.toContain('Fatal error:')
  })
})
