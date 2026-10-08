import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
const E2E_USER = process.env.E2E_USER_LOGIN ?? 'e2e_customer_shop'
const E2E_USER_PASS = process.env.E2E_USER_PASS ?? 'Wptsall-E2E-User-2026!'

type CheckResult = {
  name: string
  ok: boolean
  url?: string
  status?: number
  detail?: string
}

const runtimeDir = path.resolve(__dirname, '../../runtime')
const runtimeFile = path.join(runtimeDir, 'plugin-usage-smoke-playwright.json')

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

async function hasWordPressLoginCookie(page: any): Promise<boolean> {
  const cookies = await page.context().cookies()
  return cookies.some((c: any) => c.name.startsWith('wordpress_logged_in_') && !!c.value)
}

test.describe.configure({ mode: 'serial' })

test('Admin Plugin Pages: open + click smoke', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  expect(await hasWordPressLoginCookie(page)).toBeTruthy()

  const targets: Array<{ name: string; urls: string[] }> = [
    { name: 'WooCommerce', urls: ['/wp-admin/admin.php?page=wc-settings'] },
    { name: 'EDD', urls: ['/wp-admin/edit.php?post_type=download&page=edd-settings', '/wp-admin/admin.php?page=edd-settings'] },
    { name: 'bbPress', urls: ['/wp-admin/options-general.php?page=bbpress', '/wp-admin/edit.php?post_type=forum'] },
    { name: 'Tutor', urls: ['/wp-admin/admin.php?page=tutor'] },
    { name: 'LearnPress', urls: ['/wp-admin/admin.php?page=learn-press-settings', '/wp-admin/admin.php?page=learn_press_settings'] },
    { name: 'The Events Calendar', urls: ['/wp-admin/edit.php?post_type=tribe_events&page=tec-events-settings', '/wp-admin/edit.php?post_type=tribe_events'] },
    { name: 'WP Job Manager', urls: ['/wp-admin/admin.php?page=job_manager_settings', '/wp-admin/edit.php?post_type=job_listing'] },
    { name: 'Envira', urls: ['/wp-admin/edit.php?post_type=envira'] },
    { name: 'Seriously Simple Podcasting', urls: ['/wp-admin/edit.php?post_type=podcast'] },
    { name: 'WP Recipe Maker', urls: ['/wp-admin/admin.php?page=wprm_settings', '/wp-admin/edit.php?post_type=wprm_recipe'] },
    { name: 'Elementor', urls: ['/wp-admin/admin.php?page=elementor', '/wp-admin/edit.php?post_type=elementor_library'] },
    { name: 'Yoast SEO', urls: ['/wp-admin/admin.php?page=wpseo_dashboard'] },
    { name: 'ACF', urls: ['/wp-admin/edit.php?post_type=acf-field-group'] },
    { name: 'Site Reviews', urls: ['/wp-admin/admin.php?page=site-reviews', '/wp-admin/edit.php?post_type=site-review'] },
    { name: 'WPTSALL', urls: ['/wp-admin/admin.php?page=wptsall', '/wp-admin/admin.php?page=wptsall-settings', '/wp-admin/admin.php?page=wptsall-model-editor'] },
  ]

  const results: CheckResult[] = []

  for (const target of targets) {
    let passed = false
    let lastDetail = 'no candidate URL succeeded'

    for (const relUrl of target.urls) {
      const url = `${WP_BASE}${relUrl}`
      const response = await page.goto(url, { waitUntil: 'domcontentloaded' })
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
      if (isPermissionErrorPage || !hasAdminUi) {
        lastDetail = isPermissionErrorPage ? 'permission denied (error-page)' : 'admin shell missing'
        continue
      }

      // Safe interaction: click a tab or "Screen Options" when present.
      try {
        const navTabs = page.locator('a.nav-tab')
        if ((await navTabs.count()) > 1) {
          await navTabs.nth(1).click({ timeout: 3000 })
        } else {
          const screenOpt = page.locator('#show-settings-link, button:has-text("Screen Options")')
          if ((await screenOpt.count()) > 0) {
            await screenOpt.first().click({ timeout: 3000 })
          }
        }
      } catch {
        // Non-blocking: interaction is best-effort.
      }

      results.push({ name: target.name, ok: true, url: finalUrl, status, detail: 'opened' })
      passed = true
      break
    }

    if (!passed) {
      results.push({ name: target.name, ok: false, detail: lastDetail })
    }
  }

  const failed = results.filter(r => !r.ok)
  if (failed.length > 0) {
    const detail = failed.map(f => `${f.name}: ${f.detail}`).join('\n')
    throw new Error(`Admin plugin smoke failed:\n${detail}`)
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  const payload = fs.existsSync(runtimeFile) ? JSON.parse(fs.readFileSync(runtimeFile, 'utf-8')) : {}
  payload.admin = results
  fs.writeFileSync(runtimeFile, JSON.stringify(payload, null, 2))
})

test('Frontend Routes + Member Flow smoke', async ({ page }) => {
  const frontTargets: Array<{ name: string; url: string; allow302?: boolean }> = [
    { name: 'home', url: `${WP_BASE}/` },
    { name: 'shop', url: `${WP_BASE}/shop/` },
    { name: 'my-account', url: `${WP_BASE}/my-account/` },
    { name: 'cart', url: `${WP_BASE}/cart/` },
    { name: 'checkout', url: `${WP_BASE}/checkout/`, allow302: true },
    { name: 'discussion-hub', url: `${WP_BASE}/discussion-hub/` },
    { name: 'discussion-view', url: `${WP_BASE}/discussion-view/` },
    { name: 'events', url: `${WP_BASE}/events/` },
    { name: 'jobs', url: `${WP_BASE}/jobs/` },
    { name: 'recipes', url: `${WP_BASE}/recipes/` },
    { name: 'galleries', url: `${WP_BASE}/galleries/` },
    { name: 'reviews', url: `${WP_BASE}/reviews/` },
    { name: 'baseline-post', url: `${WP_BASE}/blog/wptsall-e2e-baseline-fixture/` },
    { name: 'product', url: `${WP_BASE}/product/bamboo-cutting-board-set-3-piece-4/` },
    { name: 'tutor-course', url: `${WP_BASE}/tutor-course/the-complete-javascript-course-2019-build-real-projects/` },
    { name: 'lp-course', url: `${WP_BASE}/lp-course/learnpress-content-localization-review/` },
    { name: 'job', url: `${WP_BASE}/job/sales-development-representative-2/` },
    { name: 'podcast', url: `${WP_BASE}/blog/podcast/episode-4-building-a-strong-company-culture-2/` },
    { name: 'wptsall-component-page', url: `${WP_BASE}/wptsall-core-component-page/` },
  ]

  const frontResults: CheckResult[] = []

  for (const target of frontTargets) {
    const resp = await page.goto(target.url, { waitUntil: 'domcontentloaded' })
    const status = resp?.status() ?? 0
    const html = await page.content()
    const fatal = hasFatalSignals(html)

    const okStatus = status === 200 || (target.allow302 && status === 302)
    const okLength = html.length > 500 || target.allow302

    if (!okStatus) {
      frontResults.push({ name: target.name, ok: false, url: target.url, status, detail: `unexpected status ${status}` })
      continue
    }
    if (!okLength) {
      frontResults.push({ name: target.name, ok: false, url: target.url, status, detail: 'empty/minimal body' })
      continue
    }
    if (fatal) {
      frontResults.push({ name: target.name, ok: false, url: target.url, status, detail: `fatal marker: ${fatal}` })
      continue
    }

    frontResults.push({ name: target.name, ok: true, url: target.url, status, detail: 'opened' })
  }

  // Member flow: login as fixture user and interact with product/cart + comment form.
  await wpLogin(page, E2E_USER, E2E_USER_PASS)
  expect(await hasWordPressLoginCookie(page)).toBeTruthy()

  await page.goto(`${WP_BASE}/my-account/`, { waitUntil: 'domcontentloaded' })
  const accountHtml = await page.content()
  expect(accountHtml.length).toBeGreaterThan(800)
  expect(hasFatalSignals(accountHtml)).toBeNull()

  await page.goto(`${WP_BASE}/product/bamboo-cutting-board-set-3-piece-4/`, { waitUntil: 'domcontentloaded' })
  const addToCart = page.locator('button.single_add_to_cart_button, button[name="add-to-cart"], .single_add_to_cart_button')
  if (await addToCart.count()) {
    await addToCart.first().click({ timeout: 8000 })
    await page.waitForTimeout(1500)
  }
  await page.goto(`${WP_BASE}/cart/`, { waitUntil: 'domcontentloaded' })
  const cartHtml = await page.content()
  expect(cartHtml.length).toBeGreaterThan(1000)
  expect(hasFatalSignals(cartHtml)).toBeNull()

  await page.goto(`${WP_BASE}/blog/wptsall-e2e-baseline-fixture/`, { waitUntil: 'domcontentloaded' })
  const commentField = page.locator('#comment, textarea[name="comment"]')
  if (await commentField.count()) {
    await commentField.first().fill('Playwright smoke comment from fixture user.')
    // Do not submit to avoid noisy duplication.
  }

  const frontFailed = frontResults.filter(r => !r.ok)
  if (frontFailed.length > 0) {
    const detail = frontFailed.map(f => `${f.name}: ${f.detail} (${f.status})`).join('\n')
    throw new Error(`Frontend smoke failed:\n${detail}`)
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  const payload = fs.existsSync(runtimeFile) ? JSON.parse(fs.readFileSync(runtimeFile, 'utf-8')) : {}
  payload.frontend = frontResults
  payload.memberFlow = { ok: true, user: E2E_USER, accountUrl: `${WP_BASE}/my-account/` }
  fs.writeFileSync(runtimeFile, JSON.stringify(payload, null, 2))
})
