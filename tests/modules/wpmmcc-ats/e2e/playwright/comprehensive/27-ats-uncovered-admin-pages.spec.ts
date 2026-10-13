/**
 * 27 — ATS plugin: previously-uncovered admin pages (audit G-11).
 *
 * Three admin slugs the coverage audit flagged with zero Playwright UI tests:
 *   - wptsall-logs      (log/admin/class-logs-page.php:31–48, submenu of wpmmcc-ats,
 *                       cap manage_wptsall_settings; ops + audit nav tabs,
 *                       audit clear button admin_post wptsall_clear_audit_logs)
 *   - wptsall-conflicts (sites/admin/class-conflict-page.php:33) — FINDING:
 *                       the standalone page is NOT registered anywhere anymore
 *                       (deprecated since 0.9.1, render_page() is dead code);
 *                       conflicts live as the `tab=conflicts` view of
 *                       wptsall-sites (render_content() embedded by
 *                       class-sites-page.php). The spec pins both realities.
 *   - wptsall-init      (admin/class-initialization-page.php:121, hidden page via
 *                       add_submenu_page(null, ...) — reachable only by direct URL)
 *
 * Target: the ATS lab site, container wptsall-wp-lab-wordpress-blog-1.
 *
 * Site routing note: this container is a MULTISITE install whose DB siteurl
 * is its single source of truth for the canonical origin (it has already
 * drifted with the provisioning host's LAN IP: 192.168.1.14 at install
 * time, 192.168.1.12 as of 2026-09-27). Any request whose Host is not the
 * canonical origin gets 302-bounced there by WordPress multisite — dropping
 * the wp-login.php path, so the login form never arrives (that is exactly
 * how the stale 192.168.1.14 default failed, 2026-09-27: bare `#user_login`
 * fill timeouts on both login legs). The spec therefore resolves the
 * canonical origin from the site's own DB at load time (see
 * detectLabSiteUrl()) and, when that origin is not directly reachable as
 * the local 127.0.0.1 backend, browses it through a transparent forwarding
 * proxy (Host header preserved — verified to hit
 * wptsall-wp-lab-wordpress-blog-1 in its docker logs). Override with:
 *   WPMMCC_ATS_BASE     (default: the site's own siteurl, e.g.
 *                        http://192.168.1.12:9081 as of 2026-09-27)
 *   WPMMCC_ATS_BACKEND  (default http://127.0.0.1:9081)
 *   WPMMCC_LAB_ADMIN_USER / WPMMCC_LAB_ADMIN_PASS (lab creds per tests/docker-lab/STATUS.md)
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 27-ats-uncovered-admin-pages
 *
 * catalog: WP-CLASS-WPTSALL\Log\Admin\Logs_Page
 * oracle: L1
 * catalog: WP-CLASS-WPTSALL\Sites\Admin\Conflict_Page
 * oracle: L1
 * catalog: WP-CLASS-WPTSALL\Admin\Initialization_Page
 * oracle: L1
 */
import { test, expect, Browser, BrowserContext, Page } from '@playwright/test'
import * as http from 'http'
import { AddressInfo } from 'net'
import { execSync } from 'child_process'
import { findFatalError } from './helpers'

/**
 * Resolve the blog container's canonical site origin straight from its own
 * DB. This is a MULTISITE install that 302-bounces every non-canonical Host
 * to its siteurl origin (dropping the wp-login.php path, so the login form
 * never arrives) — the spec must browse exactly that origin. The origin has
 * already drifted twice with the provisioning host's LAN IP (192.168.1.14 at
 * install time, 192.168.1.12 as of 2026-09-27, when the stale hardcoded
 * default broke both login legs with bare `#user_login` fill timeouts);
 * asking the site itself is drift-proof. The WPMMCC_ATS_BASE env var still
 * pins an explicit origin for hostless environments.
 */
function detectLabSiteUrl(): string | undefined {
  try {
    const out = execSync(
      'docker exec wptsall-wp-lab-wordpress-blog-1 wp option get siteurl --allow-root --path=/var/www/html',
      { encoding: 'utf-8', timeout: 20_000, stdio: ['ignore', 'pipe', 'pipe'] },
    ).trim()
    if (/^https?:\/\/.+/.test(out)) {
      return out.replace(/\/+$/, '')
    }
  } catch {
    /* fall through to the env default below */
  }
  return undefined
}

const SITE = (
  process.env.WPMMCC_ATS_BASE ??
  detectLabSiteUrl() ??
  'http://192.168.1.12:9081' // historical install-time origin (last resort)
).replace(/\/+$/, '')
const BACKEND = (process.env.WPMMCC_ATS_BACKEND ?? 'http://127.0.0.1:9081').replace(/\/+$/, '')
const ADMIN_USER = process.env.WPMMCC_LAB_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.WPMMCC_LAB_ADMIN_PASS ?? 'admin123456'

const siteUrl = new URL(SITE)
const backendUrl = new URL(BACKEND)
const NEED_PROXY = siteUrl.host !== backendUrl.host

const ADMIN_URL = (slug: string) => `${SITE}/wp-admin/admin.php?page=${slug}`

let proxy: http.Server | null = null
let proxyPort = 0

/** Start a transparent forwarding proxy: browse at SITE, serve from BACKEND. */
async function startProxy(): Promise<void> {
  proxy = http.createServer((req, res) => {
    const target = new URL(req.url ?? '/')
    const upstream = http.request(
      {
        host: backendUrl.hostname,
        port: Number(backendUrl.port || 80),
        method: req.method,
        path: `${target.pathname}${target.search}`,
        headers: { ...req.headers, host: target.host },
      },
      (upstreamRes) => {
        res.writeHead(upstreamRes.statusCode ?? 502, upstreamRes.headers)
        upstreamRes.pipe(res)
      },
    )
    upstream.on('error', () => res.destroy())
    req.pipe(upstream)
  })
  proxy.listen(0, '127.0.0.1')
  if (!proxy.listening) {
    await new Promise<void>((resolve) => proxy?.once('listening', resolve))
  }
  proxyPort = (proxy.address() as AddressInfo).port
}

async function newSession(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    ...(NEED_PROXY ? { proxy: { server: `http://127.0.0.1:${proxyPort}` } } : {}),
  })
  const page = await context.newPage()
  return { context, page }
}

/** Sign in to the ATS lab site's wp-admin (form login). */
async function atsLogin(page: Page): Promise<void> {
  await page.goto(`${SITE}/wp-login.php?redirect_to=${encodeURIComponent(`${SITE}/wp-admin/`)}&reauth=1`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  await page.fill('#user_login', ADMIN_USER)
  await page.fill('#user_pass', ADMIN_PASS)
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => null),
    page.click('#wp-submit'),
  ])
  const cookies = await page.context().cookies()
  expect(
    cookies.some((c) => c.name.startsWith('wordpress_logged_in_')),
    `login failed (url=${page.url()})`,
  ).toBe(true)
}

test.describe('27 ATS uncovered admin pages', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async () => {
    if (NEED_PROXY) await startProxy()
  })

  test.afterAll(async () => {
    await proxy?.close()
    proxy = null
  })

  test('wptsall-logs renders with ops/audit nav tabs (default ops tab)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await atsLogin(page)
      const response = await page.goto(ADMIN_URL('wptsall-logs'), { waitUntil: 'domcontentloaded' })
      expect(response?.status() ?? 0, 'wptsall-logs must be reachable').toBeLessThan(400)

      const html = await page.content()
      expect(findFatalError(html), 'fatal on wptsall-logs').toBeNull()
      // Page header via Admin_Page_Helper::render_header (class-logs-page.php:91–95).
      await expect(page.locator('h1.wp-heading-inline', { hasText: 'Logs' })).toBeVisible()
      // Nav tabs: "Ops files" + "Audit trail" (class-logs-page.php:100–110).
      await expect(page.locator('.nav-tab-wrapper a.nav-tab', { hasText: 'Ops files' })).toBeVisible()
      await expect(page.locator('.nav-tab-wrapper a.nav-tab', { hasText: 'Audit trail' })).toBeVisible()
      // Ops tab is the default (tab=ops) — either logging state notice renders
      // (class-logs-page.php:131–140), plus the file/lines filter form.
      await expect(
        page
          .getByText('Ops file logging is currently disabled')
          .or(page.getByText('Ops file logging is enabled'))
          .first(),
      ).toBeVisible()
      await expect(page.locator('#wptsall-log-file')).toBeVisible()
      await expect(page.locator('#wptsall-log-lines')).toBeVisible()
      // Submenu anchor registered under the top-level wpmmcc-ats menu.
      await expect(page.locator('#adminmenu a[href*="page=wptsall-logs"]').first()).toBeVisible({ timeout: 10_000 })
    } finally {
      await context.close()
    }
  })

  test('wptsall-logs audit tab: clear-audit nonce form + audit table', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await atsLogin(page)
      // Tab link (class-logs-page.php:105–108) carries tab=audit.
      await page.goto(`${ADMIN_URL('wptsall-logs')}&tab=audit`, { waitUntil: 'domcontentloaded' })

      const html = await page.content()
      expect(findFatalError(html), 'fatal on wptsall-logs audit tab').toBeNull()
      await expect(page.locator('.nav-tab-wrapper a.nav-tab-active', { hasText: 'Audit trail' })).toBeVisible()
      // Clear-audit button posts to admin-post.php with
      // wptsall_clear_audit_logs + nonce (class-logs-page.php:57–69).
      const clearForm = page.locator('form:has(input[name="action"][value="wptsall_clear_audit_logs"])')
      await expect(clearForm).toBeVisible()
      expect(await clearForm.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      // Audit table headers (class-logs-page.php:188–195).
      for (const header of ['Time', 'User', 'Action', 'Data']) {
        await expect(page.locator('table.widefat th', { hasText: header }).first()).toBeVisible()
      }
      expect(await page.locator('table.widefat tbody tr').count(), 'audit table renders rows or empty state').toBeGreaterThan(0)
    } finally {
      await context.close()
    }
  })

  test('wptsall-conflicts: standalone page is DEREGISTERED (403) — replaced by Sites conflicts tab', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await atsLogin(page)
      // FINDING (pinned): no add_menu_page/add_submenu_page registers the
      // wptsall-conflicts slug anywhere in the plugin (render_page() is
      // @deprecated dead code, sites/admin/class-conflict-page.php:112–122);
      // WP answers the direct URL with the 403 error screen, and there is no
      // menu anchor. Since 0.9.1 conflicts are the wptsall-sites
      // `tab=conflicts` view (class-sites-page.php embeds render_content()).
      const response = await page.goto(ADMIN_URL('wptsall-conflicts'), { waitUntil: 'domcontentloaded' })
      expect(response?.status() ?? 0, 'deregistered page must 403').toBe(403)
      await expect(page.locator('body#error-page')).toBeVisible()
      expect(findFatalError(await page.content()), 'the 403 must be a wp_die screen, not a fatal').toBeNull()
      await expect(page.locator('#adminmenu a[href*="page=wptsall-conflicts"]')).toHaveCount(0)

      // The real conflicts surface: wptsall-sites conflicts tab
      // (class-conflict-page.php render_content(), sites page embed).
      await page.goto(`${ADMIN_URL('wptsall-sites')}&tab=conflicts`, { waitUntil: 'domcontentloaded' })
      const tabHtml = await page.content()
      expect(findFatalError(tabHtml), 'fatal on sites conflicts tab').toBeNull()
      await expect(page.locator('#conflict-stats')).toBeVisible()
      const toolbar = page.locator('.wptsall-conflict-toolbar')
      await expect(toolbar).toBeVisible()
      // <option> elements are never "visible" per Playwright; pin the text.
      await expect(page.locator('#conflict-status-filter option[value=""]')).toHaveText('All Statuses')
      await expect(page.locator('#btn-auto-resolve', { hasText: 'Auto-resolve All' })).toBeVisible()
      await expect(page.locator('#conflict-list')).toBeVisible()
      // wp_localize_script payload (class-conflict-page.php:71–99) exposes the
      // REST wiring for the conflict manager JS.
      const conflicts = await page.evaluate('window.wptsallConflicts')
      expect(conflicts, 'wptsallConflicts localized config present').toBeTruthy()
      expect((conflicts as { restUrl?: string }).restUrl).toContain('/wp-json/wptsall/v2')
    } finally {
      await context.close()
    }
  })

  test('wptsall-init (hidden page): direct URL renders, no menu anchor', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await atsLogin(page)
      // Hidden page: add_submenu_page(null, ...) (class-initialization-page.php:124–134)
      // — only reachable by direct URL; registered with manage_wptsall_settings.
      const response = await page.goto(ADMIN_URL('wptsall-init'), { waitUntil: 'domcontentloaded' })
      expect(response?.status() ?? 0, 'wptsall-init must be reachable for an admin').toBeLessThan(400)

      const html = await page.content()
      expect(findFatalError(html), 'fatal on wptsall-init').toBeNull()
      await expect(page.locator('h1.wp-heading-inline', { hasText: 'WPTSALL Initialization' })).toBeVisible()
      // Hidden page must not appear in #adminmenu.
      await expect(page.locator('#adminmenu a[href*="page=wptsall-init"]')).toHaveCount(0)
      // render_page() (class-initialization-page.php:286 ff.) pins the
      // REST-availability branch first, then the initialized / consent / scan
      // states. On this lab the plugin is initialized, so the success notice
      // renders; tolerate the other documented branches so the spec tracks the
      // page marker rather than lab state.
      const content = page.locator('.wptsall-page-content')
      await expect(content).toBeVisible()
      await expect(
        content
          .getByText('Plugin initialization is complete.')
          .or(content.getByText('REST API Unavailable'))
          .or(content.locator('.wptsall-v4-scan-section'))
          .first(),
        'init page must render one of its documented states',
      ).toBeVisible()
    } finally {
      await context.close()
    }
  })
})
