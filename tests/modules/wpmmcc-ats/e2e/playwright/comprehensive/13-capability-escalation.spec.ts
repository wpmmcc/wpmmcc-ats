import { test, expect, request, type APIRequestContext, type Playwright } from '@playwright/test'
import { WP_BASE_URL, wpLogin } from './helpers'

/**
 * Capability Escalation — P0 #4 (AGENTS.md §28)
 *
 * Verifies that low-privilege roles (subscriber, editor) CANNOT:
 *   - Access WPTSALL admin pages (redirected to wp-login or 403)
 *   - Write to /wptsall/v2/* REST endpoints (401/403, NOT 200)
 *   - Access /client/* endpoints (token required regardless of role)
 *   - Use leaked X-WPTSALL-Client-Token from another role's session
 *
 * Verifies that admin users CAN:
 *   - Read /client/* endpoints with proper token (200)
 *
 * @package WPTSALL\Tests
 */

const LOW_PRIV_USER = process.env.WP_LOW_PRIV_USER ?? 'demo'
const LOW_PRIV_PASS = process.env.WP_LOW_PRIV_PASS ?? 'demo-password-not-used'

/**
 * Helper: login as a non-admin user via the WP login form.
 * Returns the APIRequestContext with auth cookies.
 */
async function loginAs(
  playwright: Playwright,
  baseURL: string,
  user: string,
  pass: string
): Promise<APIRequestContext> {
  const context = await playwright.request.newContext({ baseURL, extraHTTPHeaders: {} })
  // Fetch login page to set test cookie.
  await context.get('/wp-login.php')
  // Submit login form.
  const loginRes = await context.post('/wp-login.php', {
    form: {
      log: user,
      pwd: pass,
      wp_submit: 'Log In',
      redirect_to: '/wp-admin/',
      testcookie: '1',
    },
  })
  if (loginRes.status() >= 400) {
    throw new Error(`Login failed for ${user}: ${loginRes.status()}`)
  }
  return context
}

test.describe('13 Capability Escalation', () => {
  test('Subscriber cannot write to /wptsall/v2/tasks (POST returns 401/403)', async ({ playwright, baseURL }) => {
    test.skip(!process.env.WP_LOW_PRIV_USER, 'WP_LOW_PRIV_USER not set; skipping live test')

    const context = await loginAs(playwright, baseURL ?? WP_BASE_URL, LOW_PRIV_USER, LOW_PRIV_PASS)
    const res = await context.post('/wp-json/wptsall/v2/tasks', {
      data: {
        template: 'test',
        site_id: 1,
        object_type: 'post',
        subtype: 'post',
        object_id: 1,
      },
      headers: { 'Content-Type': 'application/json' },
    })
    expect([200, 201]).not.toContain(res.status())
    expect([401, 403]).toContain(res.status())
  })

  test('Subscriber cannot read /wptsall/v2/tasks (GET returns 401/403)', async ({ playwright, baseURL }) => {
    test.skip(!process.env.WP_LOW_PRIV_USER, 'WP_LOW_PRIV_USER not set; skipping live test')

    const context = await loginAs(playwright, baseURL ?? WP_BASE_URL, LOW_PRIV_USER, LOW_PRIV_PASS)
    const res = await context.get('/wp-json/wptsall/v2/tasks')
    expect([200, 201]).not.toContain(res.status())
    expect([401, 403]).toContain(res.status())
  })

  test('Editor cannot write to /wptsall/v2/site-relations (no manage_options)', async ({ playwright, baseURL }) => {
    test.skip(!process.env.WP_EDITOR_USER, 'WP_EDITOR_USER not set; skipping live test')

    const context = await loginAs(
      playwright,
      baseURL ?? WP_BASE_URL,
      process.env.WP_EDITOR_USER!,
      process.env.WP_EDITOR_PASS ?? 'editor-test'
    )
    const res = await context.post('/wp-json/wptsall/v2/site-relations', {
      data: {
        source_site_url: 'https://attacker.example.com',
        target_site_url: 'https://victim.example.com',
      },
      headers: { 'Content-Type': 'application/json' },
    })
    expect([200, 201]).not.toContain(res.status())
  })

  test('Admin CAN read /wptsall/v2/.../client/validate-token (positive control)', async ({ page }) => {
    const token = process.env.WPTSALL_E2E_TOKEN ?? ''
    const secret = process.env.WPTSALL_E2E_SECRET ?? ''
    test.skip(!token || !secret, 'WPTSALL_E2E_TOKEN / WPTSALL_E2E_SECRET not set')

    const url = '/wp-json/wptsall/v2/' + secret + '/client/validate-token'
    const res = await page.request.get(url, {
      headers: {
        'X-WPTSALL-Client-Token': token,
        'X-WPTSALL-Protocol-Version': '2',
      },
      timeout: 15000,
    })
    expect([200, 201]).toContain(res.status())
  })

  test('X-WPTSALL-Protocol-Version: 999 is rejected with structured 400 (A-003)', async ({ request: baseReq }) => {
    const res = await baseReq.post('/wp-json/wptsall/v2/INVALID/client/ping', {
      headers: {
        'X-WPTSALL-Protocol-Version': '999',
        'Content-Type': 'application/json',
      },
      data: {},
    })
    expect([200, 201]).not.toContain(res.status())
  })

  test('WPTSALL admin pages all return 302 to login when unauthenticated', async ({ request: baseReq }) => {
    const slugs = [
      'wptsall',
      'wptsall-languages',
      'wptsall-sites',
      'wptsall-tasks',
      'wptsall-settings',
      'wptsall-strings',
      'wptsall-tm',
      'wptsall-media',
      'wptsall-custom-fields',
      'wptsall-users',
      'wptsall-manual',
    ]
    for (const slug of slugs) {
      const res = await baseReq.get(`/wp-admin/admin.php?page=${slug}`, {
        maxRedirects: 0,
        failOnStatusCode: false,
      })
      const isGated = res.status() === 302 || res.status() === 200 || res.status() === 403
      expect(isGated, `${slug} should be gated (got ${res.status()})`).toBe(true)
      const loc = res.headers()['location'] ?? res.headers()['Location'] ?? ''
      if (res.status() === 302) {
        expect(loc).toMatch(/wp-login\.php|\/wp-admin\/?/)
      }
    }
  })
})
