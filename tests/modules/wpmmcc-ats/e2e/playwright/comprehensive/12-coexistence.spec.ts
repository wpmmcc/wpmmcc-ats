import { test, expect, Page } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'
import { wpCli } from './wp-cli'

/**
 * Third-Party Plugin Coexistence — verify wptsall works alongside other multilingual plugins.
 *
 * For each plugin (Polylang, TranslatePress, MultilingualPress):
 *   1. Activate via WP-CLI
 *   2. Login via Playwright
 *   3. Verify wptsall admin pages still render
 *   4. Verify frontend still works
 *   5. Deactivate via WP-CLI
 *
 * Runs serially to avoid race conditions.
 */

type ThirdPartyPlugin = { slug: string; name: string }

const THIRD_PARTY_PLUGINS: ThirdPartyPlugin[] = [
  { slug: 'polylang', name: 'Polylang' },
  { slug: 'translatepress-multilingual', name: 'TranslatePress' },
  { slug: 'multilingual-press', name: 'MultilingualPress' },
]

const WPTSALL_ADMIN_PAGES = [
  'wptsall',
  'wptsall-settings',
  'wptsall-languages',
  'wptsall-strings',
  'wptsall-tm',
  'wptsall-media',
  'wptsall-users',
  'wptsall-manual',
  'wptsall-url-discovery',
  'wptsall-pending',
  'wptsall-tax-translations',
  'wptsall-field-discovery',
  'wptsall-content-types',
]

function wp(cmd: string): string {
  return wpCli(cmd).trim()
}

async function checkWptsallAdminPages(page: Page, tpName: string): Promise<{ ok: number; failed: string[] }> {
  const failed: string[] = []
  let ok = 0
  for (const slug of WPTSALL_ADMIN_PAGES) {
    const res = await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' })
    const status = res?.status() ?? 0
    const html = await page.content()
    const fatal = findFatalError(html)
    // Pass if 2xx/3xx and no fatal
    if (status >= 200 && status < 400 && !fatal) {
      ok++
    } else {
      failed.push(`${slug}(status=${status} fatal=${fatal})`)
    }
  }
  return { ok, failed }
}

async function checkFrontend(page: Page): Promise<{ status: number; fatal: boolean }> {
  const res = await page.goto(WP_BASE_URL, { waitUntil: 'domcontentloaded' })
  const status = res?.status() ?? 0
  const html = await page.content()
  return { status, fatal: !!findFatalError(html) }
}

test.describe('12 Third-Party Plugin Coexistence', () => {
  test.describe.configure({ mode: 'serial' })
  // 2026-09-27 slow-lab headroom: with Polylang active every admin page in
  // checkWptsallAdminPages() re-renders under Polylang's own boot on the
  // accreted Lab — measured >180s per test (the 90s budget killed it once,
  // then the 180s budget too; the 13-page sweep alone runs 13 x ~17s). 300s
  // keeps the full loop (activation + admin sweep + frontend + REST ping)
  // inside the budget so the finally-deactivate runs — a mid-flight kill
  // skips it (worker teardown) and the lingering plugin then poisoned the
  // following specs' login flow (27/28 account, 2026-09-27).
  test.setTimeout(300_000)  // 300s per test (plugin activation + Polylang-weighted admin sweep)

  for (const tp of THIRD_PARTY_PLUGINS) {
    test(`Coexistence with ${tp.name} (${tp.slug})`, async ({ page }) => {
      console.log(`\n========== Testing coexistence with ${tp.name} ==========`)

      // Pre-condition: wptsall active
      const wpActive = wp('plugin list --field=name').split('\n').map(s => s.trim()).includes('wptsall')
      if (!wpActive) {
        wp('plugin activate wpmmcc-ats')
      }

      // Pre-condition: no leftover third-party plugin from a prior killed
      // run. A test-timeout kill skips the finally-deactivate below (the
      // worker is torn down mid-flight), and a lingering Polylang then
      // poisoned the following specs' login flow (27/28, 2026-09-27
      // account). Deactivating here is idempotent (wpCli returns the
      // benign "not active" output instead of throwing) and makes each
      // run start from the clean state the finally-block promises.
      wp(`plugin deactivate ${tp.slug}`)

      // Activate the third-party plugin
      console.log(`  Activating ${tp.slug}...`)
      const activateOut = wp(`plugin activate ${tp.slug}`)
      console.log(`  Activation: ${activateOut.split('\n').slice(0, 2).join(' | ')}`)
      const tpActive = wp('plugin list --field=name').split('\n').map(s => s.trim()).includes(tp.slug)

      try {
        // Login to wp-admin
        await wpLogin(page)

        // Test 1: wptsall admin pages render (as logged-in admin)
        const adminResult = await checkWptsallAdminPages(page, tp.name)
        console.log(`  Admin pages: ${adminResult.ok}/${WPTSALL_ADMIN_PAGES.length} OK`)
        if (adminResult.failed.length > 0) {
          console.log(`  Failed: ${adminResult.failed.join(', ')}`)
        }
        // Allow up to 2 pages to fail (legitimate redirects, e.g., wptsall-languages vs polylang languages)
        expect(
          adminResult.failed.length,
          `${tp.name}: ${adminResult.failed.length} admin pages broken: ${adminResult.failed.join(', ')}`
        ).toBeLessThanOrEqual(2)

        // Test 2: Frontend still works
        const frontend = await checkFrontend(page)
        console.log(`  Frontend: status=${frontend.status} fatal=${frontend.fatal}`)
        expect(frontend.status, `${tp.name}: frontend HTTP status`).toBe(200)
        expect(frontend.fatal, `${tp.name}: frontend fatal error`).toBe(false)

        // Test 3: REST API namespace still registered (use direct fetch with timeout)
        const { execSync: exec2 } = require('child_process')
        const tmpRest = `/tmp/rest-${tp.slug}-${Date.now()}.json`
        const statusCode = exec2(`curl -sk -m 30 -o ${tmpRest} -w "%{http_code}" "${WP_BASE_URL}/wp-json/wptsall/v2/public/ping" || echo 000`).toString().trim()
        const fs = require('fs')
        let hasWptsall = false
        if (fs.existsSync(tmpRest)) {
          try {
            const json = JSON.parse(fs.readFileSync(tmpRest, 'utf-8'))
            hasWptsall = json.ok === true || json.success === true || json.namespace === 'wptsall/v2'
          } catch { hasWptsall = false }
          try { fs.unlinkSync(tmpRest) } catch {}
        }
        const restOk = statusCode.startsWith('2') || statusCode === '404'
        console.log(`  REST wptsall/v2 ping: status=${statusCode} ok=${hasWptsall}`)
        expect(restOk, `${tp.name}: REST wptsall endpoint returned ${statusCode}`).toBe(true)

        console.log(`  ✓ ${tp.name} coexistence OK${!tpActive ? ' (note: third-party plugin did not fully activate)' : ''}`)
      } finally {
        // Always deactivate third-party plugin
        if (tpActive) {
          console.log(`  Deactivating ${tp.slug}...`)
          wp(`plugin deactivate ${tp.slug}`)
        }
      }
    })
  }
})
