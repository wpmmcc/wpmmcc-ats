import { expect, type Page } from '@playwright/test'

export const WP_BASE = (process.env.WP_BASE ?? 'http://127.0.0.1:9083').replace(/\/+$/, '')
export const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
export const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
export const WP_MEMBER_USER = process.env.WP_MEMBER_USER ?? WP_ADMIN_USER
export const WP_MEMBER_PASS = process.env.WP_MEMBER_PASS ?? WP_ADMIN_PASS

const FATAL_MARKERS = [
  'There has been a critical error on this website',
  'WordPress database error',
  'Fatal error:',
  'Parse error:',
  'Uncaught Error',
  'Call to undefined function',
]

export function findFatal(html: string): string | null {
  for (const m of FATAL_MARKERS) {
    if (html.includes(m)) return m
  }
  return null
}

export function is404Like(html: string): boolean {
  const low = html.toLowerCase()
  return (
    low.includes('error404') ||
    low.includes('404 not found') ||
    low.includes('page not found') ||
    low.includes('抱歉，找不到页面')
  )
}

async function hasLoggedInCookie(page: Page): Promise<boolean> {
  const cookies = await page.context().cookies()
  return cookies.some((c) => c.name.startsWith('wordpress_logged_in_') && !!c.value)
}

export async function wpLogin(page: Page, user = WP_ADMIN_USER, pass = WP_ADMIN_PASS) {
  // Parallel Lab lanes can flake a single login POST. Require wordpress_logged_in_*.
  // Fail fast on "not registered" / bad password so we do not burn the 180s test budget.
  //
  // Do NOT use locator.fill() + click('#wp-submit'): Chromium password-manager /
  // WP wp_attempt_focus can put the password into #user_login and leave #user_pass
  // empty. HTML5 "Please fill out this field" then blocks submit while
  // waitForLoadState hangs until the test timeout (retry5 ACF #39, 180s).
  const redirectTo = `${WP_BASE}/wp-admin/`
  let lastDetail = ''
  await page.context().clearCookies()
  for (let attempt = 1; attempt <= 3; attempt++) {
    if (page.isClosed()) {
      throw new Error('wpLogin failed: page closed')
    }
    await page.goto(`${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(redirectTo)}`, {
      waitUntil: 'domcontentloaded',
      timeout: 20_000,
    })
    await page.locator('#loginform').waitFor({ state: 'visible', timeout: 15_000 })
    await page.locator('#loginform').evaluate((form, creds) => {
      form.setAttribute('novalidate', 'novalidate')
      const userInput = form.querySelector('#user_login')
      const passInput = form.querySelector('#user_pass')
      if (!(userInput instanceof HTMLInputElement) || !(passInput instanceof HTMLInputElement)) {
        throw new Error('login form fields missing')
      }
      userInput.value = creds.user
      passInput.value = creds.pass
      userInput.dispatchEvent(new Event('input', { bubbles: true }))
      passInput.dispatchEvent(new Event('input', { bubbles: true }))
    }, { user, pass })

    const loginVal = await page.locator('#user_login').inputValue()
    const passVal = await page.locator('#user_pass').inputValue()
    if (loginVal !== user || passVal !== pass) {
      lastDetail = `login fields mismatch (user=${JSON.stringify(loginVal)} passLen=${passVal.length})`
      continue
    }

    const navigated = page
      .waitForURL((url) => !url.pathname.includes('wp-login.php'), { timeout: 15_000 })
      .then(() => true)
      .catch(() => false)
    await page.locator('#loginform').evaluate((form) => {
      ;(form as HTMLFormElement).submit()
    })
    await navigated

    const deadline = Date.now() + 8_000
    while (Date.now() < deadline) {
      if (page.isClosed()) {
        lastDetail = 'page closed during login wait'
        break
      }
      if (await hasLoggedInCookie(page)) {
        if (page.url().includes('wp-login.php')) {
          await page.goto(redirectTo, { waitUntil: 'domcontentloaded', timeout: 20_000 })
        }
        await page.waitForSelector('#wpadminbar, #wpbody-content', { timeout: 15_000 }).catch(() => {})
        const onLogin =
          page.url().includes('wp-login.php') || (await page.locator('#loginform').count()) > 0
        if (onLogin) {
          lastDetail = 'wordpress_logged_in cookie present but wp-admin still shows login'
          break
        }
        return
      }
      const errNow = await page.locator('#login_error').innerText().catch(() => '')
      if (errNow) {
        lastDetail = errNow
        break
      }
      await page.waitForTimeout(200)
    }
    if (!lastDetail) {
      lastDetail = await page.locator('#login_error').innerText().catch(() => page.url())
    }
    if (/not registered|incorrect password|未知/i.test(lastDetail)) {
      break
    }
  }
  const fallback = 'e2esmokeadmin'
  if (user !== fallback && /not registered/i.test(lastDetail)) {
    await wpLogin(page, fallback, WP_ADMIN_PASS)
    return
  }
  throw new Error(`wpLogin failed for ${user}: ${lastDetail || 'no wordpress_logged_in cookie'}`)
}

export type JourneyTarget = {
  id: string
  role: 'guest' | 'member' | 'admin'
  topology: 'source' | 'virtual' | 'subsite'
  url: string
  optional?: boolean
  min_body_chars?: number
  expect_title_fragment?: string
  expect_translation_marker?: boolean
}

export type JourneyPayload = {
  project: string
  journeys: JourneyTarget[]
}

export async function assertPageComplete(page: Page, target: JourneyTarget) {
  const response = await page.goto(target.url, { waitUntil: 'domcontentloaded', timeout: 45_000 })
  const status = response?.status() ?? 0
  const html = await page.content()
  const bodyText = await page.locator('body').innerText().catch(() => '')

  const fatal = findFatal(html)
  if (fatal) {
    throw new Error(`${target.id}: fatal on page — ${fatal}`)
  }

  if (status >= 500) {
    throw new Error(`${target.id}: HTTP ${status} for ${target.url}`)
  }

  // Prefer HTTP status for 404; HTML heuristics only for soft-404 shells (200 + error title).
  // Optional journeys (plugin pages not created in Lab) skip via the gate catch — still throw
  // so the report records `optional skip: …` rather than a hard fail.
  if (target.role !== 'admin' && status === 404) {
    throw new Error(`${target.id}: HTTP 404 for ${target.url}`)
  }
  if (target.role !== 'admin' && status >= 200 && status < 400 && is404Like(html)) {
    throw new Error(`${target.id}: 404-like content at ${target.url}`)
  }

  // Member centers (LMS dashboards, account shells, login gates) are often valid but short.
  // Defaults mirror plugin-journeys.yaml; keep member below guest so shells like Tutor /dashboard/ (~500+) pass.
  const minChars =
    target.min_body_chars ??
    (target.role === 'admin' ? 1200 : target.role === 'member' ? 500 : 800)
  expect(bodyText.length, `${target.id}: body too short (${bodyText.length})`).toBeGreaterThan(minChars)

  if (target.expect_title_fragment) {
    const fragment = target.expect_title_fragment.toLowerCase().slice(0, 24)
    if (!bodyText.toLowerCase().includes(fragment)) {
      // Source permalinks may 301 to virtual URLs; accept translation markers instead.
      const hasMarker = /【[a-z]{2}_[A-Z]{2}】/.test(bodyText) || /【[a-z]{2}_[A-Z]{2}】/.test(html)
      if (!hasMarker) {
        throw new Error(`${target.id}: expected title fragment "${fragment}" or translation marker`)
      }
    }
  }

  if (target.expect_translation_marker) {
    const hasMarker = /【[a-z]{2}_[A-Z]{2}】/.test(bodyText) || /【[a-z]{2}_[A-Z]{2}】/.test(html)
    if (!hasMarker) {
      console.warn(`${target.id}: translation marker not found (warning only)`)
    }
  }

  if (target.role === 'admin') {
    if (page.url().includes('wp-login.php') || html.includes('id="loginform"')) {
      throw new Error(`${target.id}: redirected to login for ${target.url}`)
    }
    expect(html.includes('wp-admin') || html.includes('wpcontent')).toBeTruthy()
  }
}
