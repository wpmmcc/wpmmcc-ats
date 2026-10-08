/**
 * 26 — WPMMCC plugin admin page (wpmmcc-sync) render + pairing-code journey.
 *
 * Coverage gap (audit G-10): the WPMMCC plugin registers exactly ONE admin
 * page — slug `wpmmcc-sync` (wpmmcc/source/includes/admin/class-wpmmcc-admin.php:38–47,
 * add_menu_page 'WPMMCC Sync', cap manage_options) — and it had ZERO UI tests.
 *
 * Journey (against the WPMMCC lab site, container wptsall-wp-lab-wordpress-wpmmcc-1):
 *   1. wp-login → WPMMCC Sync menu entry visible in #adminmenu
 *   2. Admin page renders: h1 + Site Identity card + peer/journal sections
 *   3. Pairing-code generation: submit the nonce form
 *      (action wpmmcc_generate_pairing_code, class-wpmmcc-admin.php:70–84)
 *      → redirect pairing_generated=1 → readonly input shows the 32-hex code
 *      (bin2hex(random_bytes(16)), class-wpmmcc-admin.php:81)
 *   4. Peers + journal tables render (table or documented empty state)
 *
 * Site routing note: the lab container's WP_HOME/WP_SITEURL constant is the
 * provisioning host `http://192.168.1.12:9082` (tests/docker-lab/docker-compose.yml
 * WORDPRESS_CONFIG_EXTRA), while the container is published on 127.0.0.1:9082.
 * Post-login redirects therefore point at 192.168.1.12, which is not routable
 * from this host. The spec browses the site at its canonical SITE origin and
 * transparently forwards every request to the local backend port via a tiny
 * HTTP proxy (Host header preserved), which is what the docker port mapping
 * actually serves. Override with:
 *   WPMMCC_LAB_BASE      (default http://192.168.1.12:9082)
 *   WPMMCC_LAB_BACKEND   (default http://127.0.0.1:9082)
 *   WPMMCC_LAB_ADMIN_USER / WPMMCC_LAB_ADMIN_PASS (lab creds per tests/docker-lab/STATUS.md)
 *
 * Run:
 *   npx playwright test -c comprehensive/playwright.comprehensive.config.ts --workers=1 26-wpmmcc-admin-page
 *
 * catalog: WP-CLASS-WPMMCC\Admin\Admin
 * oracle: L1
 */
import { test, expect, Browser, BrowserContext } from '@playwright/test'
import * as http from 'http'
import { AddressInfo } from 'net'
import { findFatalError } from './helpers'

const SITE = (process.env.WPMMCC_LAB_BASE ?? 'http://192.168.1.12:9082').replace(/\/+$/, '')
const BACKEND = (process.env.WPMMCC_LAB_BACKEND ?? 'http://127.0.0.1:9082').replace(/\/+$/, '')
const ADMIN_USER = process.env.WPMMCC_LAB_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.WPMMCC_LAB_ADMIN_PASS ?? 'admin123456'

const siteUrl = new URL(SITE)
const backendUrl = new URL(BACKEND)
const NEED_PROXY = siteUrl.host !== backendUrl.host

let proxy: http.Server | null = null
let proxyPort = 0

/** Start a transparent forwarding proxy: browse at SITE, serve from BACKEND. */
async function startProxy(): Promise<void> {
  proxy = http.createServer((req, res) => {
    // Proxied browsers send the absolute-form request target.
    const target = new URL(req.url ?? '/')
    const upstream = http.request(
      {
        host: backendUrl.hostname,
        port: Number(backendUrl.port || 80),
        method: req.method,
        path: `${target.pathname}${target.search}`,
        // Preserve the original Host header so WP canonical URLs stay on SITE.
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

async function newSession(browser: Browser): Promise<{ context: BrowserContext; page: import('@playwright/test').Page }> {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    ...(NEED_PROXY ? { proxy: { server: `http://127.0.0.1:${proxyPort}` } } : {}),
  })
  const page = await context.newPage()
  return { context, page }
}

/** Sign in to the WPMMCC lab site's wp-admin (form login, admin-post redirect-safe). */
async function wpmmccLogin(page: import('@playwright/test').Page): Promise<void> {
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

test.describe('26 WPMMCC admin page (wpmmcc-sync)', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async () => {
    if (NEED_PROXY) await startProxy()
  })

  test.afterAll(async () => {
    await proxy?.close()
    proxy = null
  })

  test('wpmmcc-sync: menu entry + page render (h1, identity card)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)

      // Top-level menu registered by Admin::register_menu()
      // (class-wpmmcc-admin.php:38–47) — visible in the admin sidebar.
      const menuAnchor = page.locator('#adminmenu a[href*="page=wpmmcc-sync"]')
      await expect(menuAnchor.first()).toBeVisible({ timeout: 10_000 })
      await menuAnchor.first().click()
      await page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null)

      const html = await page.content()
      expect(page.url(), 'menu entry must navigate to the wpmmcc-sync screen').toContain('page=wpmmcc-sync')
      // render_admin_page() markup (class-wpmmcc-admin.php:245 ff.):
      expect(html).toContain('WPMMCC - Cross-Site Synchronization Engine')
      expect(findFatalError(html), 'fatal on wpmmcc-sync').toBeNull()
      // Site Identity & Credentials card (first .card, class-wpmmcc-admin.php:270).
      await expect(page.locator('div.card', { hasText: 'Site Identity & Credentials' }).first()).toBeVisible()
      await expect(page.getByRole('heading', { name: 'Site Identity & Credentials' })).toBeVisible()
      // Identity rows (source render, mirrored byte-exact to the lab via
      // wpmmcc/scripts/sync-to-docker-lab.sh — plugin-mirror-sync gate
      // keeps the deployment from ever going stale).
      await expect(page.getByText('Plugin Identity')).toBeVisible()
      await expect(page.getByText('Origin Site UUID')).toBeVisible()
      await expect(page.getByText('Install Signature (ADR-5)')).toBeVisible()
      await expect(page.getByText('REST Base Endpoint')).toBeVisible()
      await expect(page.getByText('Route Secret (for shared client binding)')).toBeVisible()
      // (The connect-peer-site form and the per-peer Disconnect control are
      // exercised end-to-end by spec 31 — peer lifecycle.)
    } finally {
      await context.close()
    }
  })

  test('wpmmcc-sync: pairing-code generation → 32-hex code renders', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      // Nonce form registered on admin_post_wpmmcc_generate_pairing_code
      // (class-wpmmcc-admin.php:70–84): hidden action + _wpnonce + button.
      const pairingForm = page.locator('form:has(input[name="action"][value="wpmmcc_generate_pairing_code"])')
      await expect(pairingForm).toBeVisible()
      expect(await pairingForm.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      const generateBtn = pairingForm.getByRole('button', { name: 'Generate New Pairing Code' })
      await expect(generateBtn).toBeVisible()

      // Submit → handle_generate_pairing_code() redirects with pairing_generated=1.
      const [redirectResp] = await Promise.all([
        page.waitForResponse((res) => res.url().includes('pairing_generated=1') && res.status() === 200, {
          timeout: 30_000,
        }),
        generateBtn.click(),
      ])
      expect(redirectResp.url(), 'post-generate redirect lands back on wpmmcc-sync').toContain('page=wpmmcc-sync')

      // The "Active Pairing Code" row now renders a readonly input with the
      // 32-hex code (bin2hex(random_bytes(16)), class-wpmmcc-admin.php:81) —
      // markup: <th>Active Pairing Code</th><td><input readonly value=CODE>
      const pairingRow = page.locator('tr:has(th:text-is("Active Pairing Code"))')
      await expect(pairingRow).toBeVisible()
      const codeInput = pairingRow.locator('input[readonly]')
      await expect(codeInput).toBeVisible()
      const code = await codeInput.inputValue()
      expect(code, `pairing code must be 32-hex, got: ${code}`).toMatch(/^[a-f0-9]{32}$/)
      await expect(pairingRow.getByText('Valid for 10 minutes (single-use).')).toBeVisible()

      // The Client Token control (class-wpmmcc-admin.php:308–320) must still be
      // present — either the generated token input or the open-discovery notice.
      const tokenRow = page.locator('tr:has(th:text-is("Client Token (Identity Contract v1 §2)"))')
      await expect(tokenRow).toBeVisible()
    } finally {
      await context.close()
    }
  })

  test('wpmmcc-sync: peers + journal sections render (table or empty state)', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      // Connected Peer Sites card (last-20 query, class-wpmmcc-admin.php:226–232):
      const peersCard = page.locator('div.card', { hasText: 'Connected Peer Sites' }).first()
      await expect(peersCard).toBeVisible()
      const peersEmpty = peersCard.getByText('No peers paired yet')
      const peersTable = peersCard.locator('table.widefat')
      await expect(peersEmpty.or(peersTable).first()).toBeVisible()
      if (await peersTable.isVisible()) {
        // 'Languages' pinned since UI-28-05 (doc 28): the peers table used to
        // show no language pair while every row was hardwired en_US→zh_CN.
        // The headers are <th> cells of the header row: role columnheader, not cell.
        for (const header of ['Peer Name', 'UUID', 'Direction', 'Sync Mode', 'Conflict Strategy', 'Languages', 'Status']) {
          await expect(peersTable.getByRole('columnheader', { name: header, exact: true }).first()).toBeVisible()
        }
      }

      // Recent Sync Journal card (last-20 query, class-wpmmcc-admin.php:244–250):
      const journalCard = page.locator('div.card', { hasText: 'Recent Sync Journal' }).first()
      await expect(journalCard).toBeVisible()
      const journalEmpty = journalCard.getByText('No journal entries yet')
      const journalTable = journalCard.locator('table.widefat')
      await expect(journalEmpty.or(journalTable).first()).toBeVisible()
      if (await journalTable.isVisible()) {
        for (const header of ['Time', 'Direction', 'Event', 'Entity UUID', 'Outcome']) {
          await expect(journalTable.getByRole('columnheader', { name: header, exact: true }).first()).toBeVisible()
        }
      }

      expect(findFatalError(await page.content()), 'fatal on wpmmcc-sync sections').toBeNull()
    } finally {
      await context.close()
    }
  })

  test('wpmmcc-sync: client-token control + no fatal across sections', async ({ browser }) => {
    const { context, page } = await newSession(browser)
    try {
      await wpmmccLogin(page)
      await page.goto(`${SITE}/wp-admin/admin.php?page=wpmmcc-sync`, { waitUntil: 'domcontentloaded' })

      // Client Token row (deployed class-wpmmcc-admin.php:168–182): either a
      // readonly generated-token input or the open-discovery em notice, plus
      // the wpmmcc_generate_client_token nonce form.
      const tokenRow = page.locator('tr:has(th:text-is("Client Token (Identity Contract v1 §2)"))')
      await expect(tokenRow).toBeVisible()
      const tokenForm = page.locator('form:has(input[name="action"][value="wpmmcc_generate_client_token"])')
      await expect(tokenForm).toBeVisible()
      expect(await tokenForm.locator('input[name="_wpnonce"]').getAttribute('value')).toBeTruthy()
      // Render-only: the token is NEVER regenerated here (it would invalidate
      // the active client token in the shared lab).
      await expect(
        tokenRow
          .locator('input[readonly]')
          .or(tokenRow.getByText('No client token configured'))
          .first(),
      ).toBeVisible()
      expect(findFatalError(await page.content()), 'fatal on wpmmcc-sync client-token row').toBeNull()
    } finally {
      await context.close()
    }
  })
})
