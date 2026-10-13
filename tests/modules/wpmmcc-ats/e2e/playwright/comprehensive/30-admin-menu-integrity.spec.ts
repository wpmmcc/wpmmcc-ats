/**
 * 30 — Admin menu integrity + virtual-site toggle verb labels.
 *
 * 3.8flash TEST-P1-01 blind spot repair: the comprehensive suite navigates
 * by direct URL (helpers.gotoAdminPage), so the sidebar DOM was never
 * asserted. Two real UI defects shipped green under that blind spot:
 *
 *   - ATS-P2-01: "Languages" was registered twice (central menu.php + the
 *     languages module's own add_menu_page hook) rendering two identical
 *     sidebar entries. Fixed by centralizing registration in menu.php; this
 *     spec is the regression gate that would have caught the duplicate.
 *   - ATS-P1-01: the virtual-site toggle button read its past-participle
 *     state labels ("Disabled"/"Enabled") as its action text, so an active
 *     row showed a "Disabled" button and clicking it disabled a live site.
 *     Fixed to the verbs "Disable"/"Enable"; this spec asserts the verbs.
 *
 *   WP_BASE=http://127.0.0.1:9083 npx playwright test -c
 *     comprehensive/playwright.comprehensive.config.ts --workers=1 30-admin-menu-integrity
 *
 * catalog: WP-SCREEN-wptsall-languages\Admin\WPTSALL
 * oracle: L2
 */
import { test, expect } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

test.describe.configure({ mode: 'serial' })

test.describe('30 Admin menu integrity', () => {
  test.setTimeout(120_000)

  test('sidebar submenu slugs are unique (no duplicate menu entries)', async ({ page }) => {
    await wpLogin(page)
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })

    // Defect detector (ATS-P2-01 class): two IDENTICAL entries inside the
    // SAME top-level menu's submenu — same page param AND same full query.
    // Scoped to OUR top-level menu (the one exposing wptsall/wpmmcc-ats
    // slugs): the lab runs a full third-party stack (WooCommerce/Elementor/
    // Tutor/…) with its own duplicate-shape menus that are not this repo's
    // defect surface. WordPress's top+first-submenu pattern repeats the top
    // slug once; only an exact-duplicate href pair inside OUR submenu is
    // the double-registration defect (the historical double-Languages case).
    const WPTSALL_SLUG_RE = /page=(wpmmcc-ats|wptsall-[a-z-]+)/
    const duplicatesByTop = await page.locator('#adminmenu > li').evaluateAll(
      (tops: HTMLLIElement[], slugReSource: string) => {
        const slugRe = new RegExp(slugReSource)
        return tops
          .filter((top) =>
            Array.from(top.querySelectorAll('.wp-submenu a[href]')).some((a) =>
              slugRe.test(a.getAttribute('href') || ''),
            ),
          )
          .flatMap((top, topIndex) => {
            const anchors = Array.from(top.querySelectorAll('.wp-submenu a[href*="page="]'))
            const hrefs = anchors.map((a) => {
              const href = a.getAttribute('href') || ''
              return href.replace(/^.*\.php\?/, '') // keep page=…&… query
            })
            return hrefs
              .filter((href, index) => hrefs.indexOf(href) !== index)
              .map((href) => `top#${topIndex}: ${href}`)
          })
      },
      WPTSALL_SLUG_RE.source,
    )
    expect(
      duplicatesByTop,
      `duplicate admin submenu entries detected: ${duplicatesByTop.join(' | ')}`,
    ).toEqual([])

    // ATS-P2-01 regression pin: the Languages screen must appear exactly
    // once under the WPTSALL top (its historical double registration is the
    // canonical case).
    const languagesAnchors = page.locator(
      '#adminmenu > li .wp-submenu a[href*="page=wptsall-languages"]',
    )
    expect(await languagesAnchors.count()).toBe(1)
  })

  test('virtual-site toggle buttons read action verbs, never state adjectives', async ({ page }) => {
    await wpLogin(page)
    const response = await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites`, {
      waitUntil: 'domcontentloaded',
    })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
    expect(response?.status(), 'sites page must render').toBe(200)

    const toggles = page.locator('.wptsall-toggle-virtual-site')
    const count = await toggles.count()
    expect(count, 'lab seed must expose at least one virtual site row').toBeGreaterThan(0)

    for (let i = 0; i < count; i++) {
      const button = toggles.nth(i)
      const text = (await button.textContent())?.trim() ?? ''
      // ATS-P1-01: the action text must be the verb. The state adjectives
      // ("Disabled"/"Enabled") read as the row's current status and invite
      // the opposite click.
      expect(
        ['Disable', 'Enable'],
        `toggle #${i} must read a verb, got ${JSON.stringify(text)}`,
      ).toContain(text)
      // Direction pin: an active row offers Disable; an inactive row Enable.
      const status = await button.getAttribute('data-status')
      expect(text).toBe(status === 'active' ? 'Disable' : 'Enable')
    }
  })
})
