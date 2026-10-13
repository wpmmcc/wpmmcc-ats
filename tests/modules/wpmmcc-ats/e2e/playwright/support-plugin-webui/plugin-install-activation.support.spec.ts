import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'

const runtimeDir = path.resolve(__dirname, '../../runtime')
const runtimeFile = path.join(runtimeDir, 'plugin-install-activation-playwright.json')

type PluginCheck = {
  label: string
  slug: string
  rowSlugs?: string[]
  menuUrls: string[]
}

type PluginResult = {
  slug: string
  label: string
  rowFound: boolean
  activationState: string
  actionTaken: string
  menuUrl: string
  menuReachable: boolean
  detail: string
}

const TARGET_PLUGINS: PluginCheck[] = [
  { label: 'WPTSALL', slug: 'wptsall', rowSlugs: ['wptsall', 'wpts-all', 'wpmmcc-ats', 'wpmmcc'], menuUrls: ['/wp-admin/admin.php?page=wptsall-tasks', '/wp-admin/admin.php?page=wptsall-settings'] },
  { label: 'WooCommerce', slug: 'woocommerce', menuUrls: ['/wp-admin/admin.php?page=wc-settings'] },
  { label: 'bbPress', slug: 'bbpress', menuUrls: ['/wp-admin/options-general.php?page=bbpress', '/wp-admin/edit.php?post_type=forum'] },
  { label: 'Tutor', slug: 'tutor', menuUrls: ['/wp-admin/admin.php?page=tutor'] },
  { label: 'LearnPress', slug: 'learnpress', menuUrls: ['/wp-admin/admin.php?page=learn-press-settings', '/wp-admin/admin.php?page=learn_press_settings'] },
  { label: 'The Events Calendar', slug: 'the-events-calendar', menuUrls: ['/wp-admin/edit.php?post_type=tribe_events&page=tec-events-settings', '/wp-admin/edit.php?post_type=tribe_events'] },
  { label: 'WP Job Manager', slug: 'wp-job-manager', menuUrls: ['/wp-admin/admin.php?page=job_manager_settings', '/wp-admin/edit.php?post_type=job_listing'] },
  { label: 'Elementor', slug: 'elementor', menuUrls: ['/wp-admin/admin.php?page=elementor', '/wp-admin/edit.php?post_type=elementor_library'] },
  { label: 'Yoast SEO', slug: 'wordpress-seo', menuUrls: ['/wp-admin/admin.php?page=wpseo_dashboard'] },
  { label: 'ACF', slug: 'advanced-custom-fields', menuUrls: ['/wp-admin/edit.php?post_type=acf-field-group'] },
]

function hasFatalSignals(html: string): string | null {
  const markers = [
    'There has been a critical error on this website',
    'WordPress database error',
    'Fatal error:',
    'Parse error:',
    'Uncaught Error',
  ]
  for (const marker of markers) {
    if (html.includes(marker)) {
      return marker
    }
  }
  return null
}

async function wpLogin(page: any, user: string, pass: string) {
  await page.goto(`${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(`${WP_BASE}/wp-admin/`)}&reauth=1`, { waitUntil: 'domcontentloaded' })
  await page.fill('#user_login', user)
  await page.fill('#user_pass', pass)
  await page.locator('#loginform input[name="redirect_to"]').evaluate((input: HTMLInputElement, redirectTo: string) => {
    input.value = String(redirectTo)
  }, `${WP_BASE}/wp-admin/`)
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('#wp-submit'),
  ])
  await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' })
}

async function ensurePluginRowReady(page: any, slug: string) {
  const rowSlugs = [slug]
  return ensurePluginRowReadyWithAliases(page, rowSlugs)
}

async function ensurePluginRowReadyWithAliases(page: any, rowSlugs: string[]) {
  const pluginScreens = ['/wp-admin/plugins.php', '/wp-admin/network/plugins.php']
  for (const screen of pluginScreens) {
    await page.goto(`${WP_BASE}${screen}`, { waitUntil: 'domcontentloaded' })
    const search = page.locator('#plugin-search-input')
    if (await search.count()) {
      await search.fill(rowSlugs[0] ?? '')
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        search.press('Enter'),
      ])
    }
    for (const rowSlug of rowSlugs) {
      const row = page.locator(`tr[data-slug="${rowSlug}"]`).first()
      if ((await row.count()) > 0) {
        return row
      }
    }
  }
  return page.locator(`tr[data-slug="${rowSlugs[0] ?? ''}"]`).first()
}

async function activationStateForRow(row: any): Promise<string> {
  const classes = String((await row.getAttribute('class')) ?? '')
  if (classes.includes('active')) return 'active'
  if (classes.includes('inactive')) return 'inactive'
  if (classes.includes('network-active')) return 'active-network'
  const text = ((await row.textContent()) ?? '').toLowerCase()
  if (text.includes('network active')) return 'active-network'
  if (text.includes('active')) return 'active'
  if (text.includes('inactive')) return 'inactive'
  return 'unknown'
}

async function ensurePluginEnabled(page: any, row: any): Promise<{ state: string; actionTaken: string }> {
  let state = await activationStateForRow(row)
  if (state !== 'inactive') {
    return { state, actionTaken: 'none' }
  }

  const activateLink = row.locator('a').filter({ hasText: /activate|启用/i }).first()
  if ((await activateLink.count()) === 0) {
    return { state, actionTaken: 'inactive-without-activate-link' }
  }

  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    activateLink.click(),
  ])
  const refreshedRow = page.locator(`tr[data-slug="${await row.getAttribute('data-slug')}"]`).first()
  await expect(refreshedRow).toBeVisible({ timeout: 20_000 })
  state = await activationStateForRow(refreshedRow)
  return { state, actionTaken: 'activated-via-ui' }
}

async function verifyAdminMenuPage(page: any, menuUrls: string[]): Promise<{ ok: boolean; detail: string; url: string }> {
  let lastDetail = 'no menu url tried'
  let lastUrl = menuUrls[0] ?? ''

  for (const menuUrl of menuUrls) {
    lastUrl = menuUrl
    const response = await page.goto(`${WP_BASE}${menuUrl}`, { waitUntil: 'domcontentloaded' })
    const status = response?.status() ?? 0
    const finalUrl = page.url()
    const html = await page.content()
    const fatal = hasFatalSignals(html)
    const hasAdminUi = (await page.locator('#wpadminbar').count()) > 0 && (await page.locator('#adminmenu, #adminmenuwrap').count()) > 0
    const isPermissionErrorPage =
      /<body[^>]*error-page/i.test(html) &&
      /(Sorry, you are not allowed|You do not have permission|You do not have sufficient permissions|抱歉，您不能访问此页面)/i.test(html)

    if (status >= 400) {
      lastDetail = `HTTP ${status}`
      continue
    }
    if (!/\/wp-admin\/?/i.test(finalUrl)) {
      lastDetail = `redirected to non-admin URL: ${finalUrl}`
      continue
    }
    if (fatal) {
      lastDetail = `fatal marker: ${fatal}`
      continue
    }
    if (isPermissionErrorPage) {
      lastDetail = 'permission denied (error-page)'
      continue
    }
    if (!hasAdminUi) {
      lastDetail = 'admin shell missing'
      continue
    }

    return { ok: true, detail: 'opened', url: menuUrl }
  }

  return { ok: false, detail: lastDetail, url: lastUrl }
}

test.describe.configure({ mode: 'serial' })

test('Plugin install + activation + menu access smoke', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  await expect(page.locator('#wpadminbar')).toBeVisible()

  const results: PluginResult[] = []

  for (const plugin of TARGET_PLUGINS) {
    const row = await ensurePluginRowReadyWithAliases(page, plugin.rowSlugs ?? [plugin.slug])
    const rowFound = (await row.count()) > 0
    if (!rowFound) {
      results.push({
        slug: plugin.slug,
        label: plugin.label,
        rowFound: false,
        activationState: 'missing',
        actionTaken: 'none',
        menuUrl: plugin.menuUrls[0] ?? '',
        menuReachable: false,
        detail: 'plugin row missing in plugins.php',
      })
      continue
    }

    await expect(row).toBeVisible({ timeout: 20_000 })
    const enabled = await ensurePluginEnabled(page, row)
    const menuCheck = await verifyAdminMenuPage(page, plugin.menuUrls)
    results.push({
      slug: plugin.slug,
      label: plugin.label,
      rowFound: true,
      activationState: enabled.state,
      actionTaken: enabled.actionTaken,
      menuUrl: menuCheck.url,
      menuReachable: menuCheck.ok,
      detail: menuCheck.detail,
    })
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  fs.writeFileSync(
    runtimeFile,
    JSON.stringify(
      {
        timestamp: new Date().toISOString(),
        wpBase: WP_BASE,
        checked: results.length,
        results,
      },
      null,
      2,
    ),
  )

  const failed = results.filter((item) => !item.rowFound || !item.menuReachable || item.activationState === 'inactive')
  if (failed.length > 0) {
    const detail = failed
      .map((item) => `${item.label} (${item.slug}): state=${item.activationState}; action=${item.actionTaken}; detail=${item.detail}`)
      .join('\n')
    throw new Error(`plugin install/activation smoke failed:\n${detail}`)
  }
})
