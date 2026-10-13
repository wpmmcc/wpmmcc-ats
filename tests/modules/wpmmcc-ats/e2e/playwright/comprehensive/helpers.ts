import { Page, expect } from '@playwright/test'

const WP_BASE = (process.env.WP_BASE ?? 'http://127.0.0.1:9083').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'

export const WP_BASE_URL = WP_BASE

/**
 * Goto headroom for slow admin pages, following the 20-spec precedent
 * (SLOW_ADMIN_LIST_GOTO_TIMEOUT, 2026-09-26): the accreted Lab + external
 * load spikes pushed heavy admin pages past the 30s goto default — the
 * 2026-09-27 full-run second pass lost 5 early legs (01-dashboard, 01b x2,
 * 02-settings, 03-manual-hub, all ~30.7-32.1s goto timeouts) that all
 * re-passed on quieter windows. 90s absorbs the spikes without masking
 * real hangs (20-spec product pages run 22-49s within this budget).
 */
export const SLOW_ADMIN_GOTO_TIMEOUT = 90_000

/**
 * Sign in to wp-admin via login form.
 * Handles the WP 6.x "confirm_admin_email" interstitial by following redirect manually.
 */
export async function wpLogin(page: Page): Promise<void> {
  const maxAttempts = 3
  let lastError: Error | null = null
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      await page.context().clearCookies()
      await page.goto(
        `${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(WP_BASE + '/wp-admin/')}&reauth=1`,
        { waitUntil: 'domcontentloaded', timeout: 60_000 },
      )
      // 2026-09-27 login-form arrival insurance: the 15s default action
      // timeout proved too tight for the login form on a Lab degraded by
      // a lingering third-party plugin (27/28 account, 2026-09-27); an
      // explicit 30s arrival wait fails with a clearer diagnostic than a
      // bare fill timeout and absorbs slow-but-healthy arrivals.
      await expect(page.locator('#user_login')).toBeVisible({ timeout: 30_000 })
      await page.fill('#user_login', '')
      await page.fill('#user_login', WP_ADMIN_USER)
      await page.fill('#user_pass', '')
      await page.fill('#user_pass', WP_ADMIN_PASS)
      await page.locator('#loginform input[name="redirect_to"]').evaluate(
        (input: HTMLInputElement, redirectTo: string) => {
          input.value = String(redirectTo)
        },
        `${WP_BASE}/wp-admin/`,
      )
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => null),
        page.click('#wp-submit'),
      ])
      if (page.url().includes('confirm_admin_email')) {
        await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' }).catch(() => undefined)
      }
      if (page.url().includes('wp-login.php')) {
        await page.waitForTimeout(1500 * attempt)
        await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' }).catch(() => undefined)
      }
      // Prefer landing in admin by force-nav if needed
      if (!/wp-admin/.test(page.url())) {
        await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' }).catch(() => undefined)
      }
      let cookies = await page.context().cookies()
      let hasAuth = cookies.some((c) => c.name.startsWith('wordpress_logged_in_'))
      if (!hasAuth) {
        await page.waitForTimeout(2000)
        await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' }).catch(() => undefined)
        cookies = await page.context().cookies()
        hasAuth = cookies.some((c) => c.name.startsWith('wordpress_logged_in_'))
      }
      if (!hasAuth) {
        throw new Error(`wpLogin failed: no wordpress_logged_in cookie (url=${page.url()})`)
      }
      return
    } catch (err) {
      lastError = err instanceof Error ? err : new Error(String(err))
      await page.waitForTimeout(2500 * attempt)
    }
  }
  throw lastError || new Error('wpLogin failed')
}

/**
 * Navigate to a wp-admin page and return its content + status.
 */
export async function gotoAdminPage(page: Page, pageSlug: string): Promise<{ html: string; status: number }> {
  const url = `${WP_BASE}/wp-admin/admin.php?page=${pageSlug}`
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: SLOW_ADMIN_GOTO_TIMEOUT })
  const status = response?.status() ?? 0
  const html = await page.content()
  return { html, status }
}

const FATAL_MARKERS = [
  'There has been a critical error on this website',
  'WordPress database error',
  'Fatal error:',
  'Parse error:',
  'Uncaught Error',
  'Call to undefined function',
  'Cannot redeclare',
]

export function findFatalError(html: string): string | null {
  for (const m of FATAL_MARKERS) {
    if (html.includes(m)) return m
  }
  return null
}

export function isAdminPageRendered(html: string): boolean {
  return html.includes('wpcontent') || html.includes('wp-admin')
}
