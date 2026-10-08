/**
 * 25 — Admin Page Six-Scenario Matrix (Lane C, plan §6.3)
 *
 * Ten render-only admin pages × six scenarios:
 *   S1 menu visible (submenu entry for the current screen)
 *   S2 direct URL renders (no fatal, admin chrome, page marker)
 *   S3 capability matrix — anonymous → login redirect; editor → denied;
 *      wptsall_translator → allowed iff page cap is manage_wptsall_translations
 *      (custom-fields / field-discovery / models-backup / wizard require
 *      manage_wptsall_settings and are denied)
 *   S4 empty/primary state renders without fatal (slot-a carries prior e2e
 *      data, so the primary content section OR the empty marker is pinned)
 *   S5 main-action closure — submit → persist → reload consistent (per-page
 *      flows below; prerequisites seeded via wp-cli fixture + REST)
 *   S6 failure feedback — nonce-less POST to every admin-post action dies
 *      on the WP failure screen ("Are you sure…")
 *
 * Run (slot-a):
 *   WP_BASE=http://127.0.0.1:9181 \
 *   WP_ADMIN_USER=e2esmokeadmin WP_ADMIN_PASS='Wptsall-Smoke-Admin-2026!' \
 *   npx playwright test -c playwright.comprehensive.config.ts 25-admin-page-matrix
 *
 * Fixture (slot-a, idempotent): two languages (zh_CN default + en_US), one
 * active site relation (template zz-lane-c-relation), pretty permalinks,
 * wizard state reset. See lane-c seed script in the plan doc Phase 3 log.
 *
 * catalog: WP-CLASS-Custom_Fields_Page
 * oracle: L2
 * catalog: WP-CLASS-Field_Discovery_Page
 * oracle: L2
 * catalog: WP-CLASS-Media_Translation_Page
 * oracle: L2
 * catalog: WP-CLASS-Model_Backup_Handler
 * oracle: L2
 * catalog: WP-CLASS-Pending_Translations_Page
 * oracle: L2
 * catalog: WP-CLASS-Taxonomy_Translation_Page
 * oracle: L2
 * catalog: WP-CLASS-Theme_Plugin_Localization_Page
 * oracle: L2
 * catalog: WP-CLASS-Url_Discovery_Page
 * oracle: L2
 * catalog: WP-CLASS-User_Translation_Page
 * oracle: L2
 * catalog: WP-CLASS-Setup_Wizard
 * oracle: L2
 * catalog: WP-CLASS-Browse_As_Role_Page
 * oracle: L2
 */
import { test, expect, Page, BrowserContext } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

interface PageSpec {
  slug: string
  marker: string
  cap: 'manage_wptsall_settings' | 'manage_wptsall_translations' | 'manage_wptsall_security'
  /**
   * Whether the page has a rendered #adminmenu anchor.
   *
   * FINDING (UX): field-discovery / pending / tax-translations / url-discovery
   * register via add_submenu_page() with parent 'wptsall-manual' — itself a
   * submenu slug of the top-level 'wpmmcc-ats' menu. WordPress renders submenu
   * items only under a TOP-LEVEL parent, so these four pages never get a menu
   * anchor anywhere in #adminmenu; they are reachable only by direct URL (and
   * in-page links from the Manual Translation hub). The wizard is intentionally
   * hidden (add_submenu_page(null, ...)) but exposes a visible "Run Setup
   * Wizard" entry under 'wpmmcc-ats'.
   */
  menuVisible: boolean
  action?: string
  /** Menu anchor slug when it differs from the page slug (wizard redirect stub). */
  anchorSlug?: string
}

const PAGES: PageSpec[] = [
  { slug: 'wptsall-custom-fields', marker: 'Custom Field Translations', cap: 'manage_wptsall_settings', menuVisible: true, action: 'wptsall_cf_save' },
  { slug: 'wptsall-field-discovery', marker: 'Field Discovery', cap: 'manage_wptsall_settings', menuVisible: false, action: 'wptsall_field_discovery_save' },
  { slug: 'wptsall-media', marker: 'Media Translations', cap: 'manage_wptsall_translations', menuVisible: true, action: 'wptsall_media_save' },
  // Markers match raw HTML: "&" renders escaped as "&amp;" in headings.
  { slug: 'wptsall-models-backup', marker: 'Models Backup &amp; Restore', cap: 'manage_wptsall_settings', menuVisible: true, action: 'wptsall_model_export' },
  { slug: 'wptsall-pending', marker: 'Pending Translations', cap: 'manage_wptsall_translations', menuVisible: false },
  { slug: 'wptsall-tax-translations', marker: 'Taxonomy Translations', cap: 'manage_wptsall_translations', menuVisible: false, action: 'wptsall_tax_link' },
  { slug: 'wptsall-theme-plugin-loc', marker: 'Theme &amp; Plugin Localization', cap: 'manage_wptsall_translations', menuVisible: true, action: 'wptsall_save_i18n_config' },
  { slug: 'wptsall-url-discovery', marker: 'URL Discovery', cap: 'manage_wptsall_translations', menuVisible: false, action: 'wptsall_url_discovery_run' },
  { slug: 'wptsall-users', marker: 'User Translations', cap: 'manage_wptsall_translations', menuVisible: true, action: 'wptsall_user_save' },
  // The wizard page itself is hidden (add_submenu_page(null, ...)); its
  // visible entry is the 'wptsall-wizard-redirect' stub that forwards to it.
  { slug: 'wptsall-wizard', marker: 'WPTSALL Setup Wizard', cap: 'manage_wptsall_settings', menuVisible: true, action: 'wptsall_wizard_step', anchorSlug: 'wptsall-wizard-redirect' },
  { slug: 'wptsall-browse-as-role', marker: 'Browse as Role', cap: 'manage_wptsall_security', menuVisible: true, action: 'wptsall_start_browse_session' },
]

const ADMIN_URL = (slug: string) => `${WP_BASE_URL}/wp-admin/admin.php?page=${slug}`

/** Parameterised form login (helpers.wpLogin uses env credentials). */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
  await page.context().clearCookies()
  await page.goto(
    `${WP_BASE_URL}/wp-login.php?redirect_to=${encodeURIComponent(WP_BASE_URL + '/wp-admin/')}&reauth=1`,
    { waitUntil: 'domcontentloaded', timeout: 60_000 },
  )
  await page.fill('#user_login', user)
  await page.fill('#user_pass', pass)
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => null),
    page.click('#wp-submit'),
  ])
  if (page.url().includes('confirm_admin_email')) {
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
  }
  const cookies = await page.context().cookies()
  expect(cookies.some((c) => c.name.startsWith('wordpress_logged_in_')), `loginAs(${user}) failed: ${page.url()}`).toBe(true)
}

async function scrapeNonce(page: Page, action: string): Promise<string> {
  const nonce = await page
    .locator(`form:has(input[name="action"][value="${action}"]) input[name="_wpnonce"]`)
    .first()
    .getAttribute('value')
  expect(nonce, `nonce for ${action} not found on ${page.url()}`).toBeTruthy()
  return nonce as string
}

async function restNonce(page: Page): Promise<string> {
  const nonce = await page.evaluate('window.wpApiSettings && window.wpApiSettings.nonce')
  expect(nonce, 'rest nonce unavailable').toBeTruthy()
  return nonce as string
}

async function ensureUser(page: Page, login: string, role: string): Promise<void> {
  const nonce = await restNonce(page)
  // context=edit is required for `username` to appear in the collection.
  const listUrl = `/wp-json/wp/v2/users?search=${login}&context=edit&per_page=100`
  const existing = await page.request.get(listUrl, { headers: { 'X-WP-Nonce': nonce } })
  const found = (await existing.json()) as Array<{ username: string }>
  if (found.some((u) => u.username === login)) return
  const created = await page.request.post('/wp-json/wp/v2/users', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      username: login,
      email: `${login}@lane-c.test`,
      // Must match the ROLE_USER_PASS constant used by loginAs below —
      // the old asterisk placeholder created accounts nobody could log
      // into, so the seed "passed" and every loginAs failed.
      password: 'Lane-C-Role-2026!',
      roles: [role],
    },
  })
  if (created.status() >= 400) {
    // "Already registered" on a retry: the user exists, treat as seeded.
    const recheck = await page.request.get(listUrl, { headers: { 'X-WP-Nonce': nonce } })
    const list = (await recheck.json()) as Array<{ username: string }>
    if (list.some((u) => u.username === login)) return
  }
  const createdBody = await created.text().catch(() => '(unreadable body)')
  expect(
    created.status(),
    `create user ${login} (${role}) — REST said: ${createdBody.slice(0, 300)}`,
  ).toBeLessThan(300)
}

let editorCtx: BrowserContext | null = null
let translatorCtx: BrowserContext | null = null

test.describe.configure({ mode: 'serial' })

// The matrix logs in and walks many admin screens per test; give each one
// room beyond the 30s default (wpLogin retries alone can take ~15s).
test.beforeEach(() => {
  test.setTimeout(120_000)
})

test.describe('25 Admin Page Matrix — roles seeded', () => {
  test('seed editor + wptsall_translator users', async ({ browser, page }) => {
    await wpLogin(page)
    // Usernames are lowercase-alnum only: WP 7.0 core rejects underscores
    // ("Usernames can only contain lowercase letters (a-z) and numbers") —
    // verified via REST and wp-cli on the lab; the old lane_c_* names
    // predate that WP upgrade.
    await ensureUser(page, 'laneceditor', 'editor')
    await ensureUser(page, 'lanectranslator', 'wptsall_translator')
    editorCtx = await browser.newContext()
    const editorPage = await editorCtx.newPage()
    await loginAs(editorPage, 'laneceditor', 'Lane-C-Role-2026!')
    await editorPage.close()
    translatorCtx = await browser.newContext()
    const translatorPage = await translatorCtx.newPage()
    await loginAs(translatorPage, 'lanectranslator', 'Lane-C-Role-2026!')
    await translatorPage.close()
  })
})

test.describe('25 S1+S2 menu visible + direct URL (admin)', () => {
  for (const p of PAGES) {
    test(`${p.slug}: menu entry + direct URL renders`, async ({ page }) => {
      await wpLogin(page)
      const response = await page.goto(ADMIN_URL(p.slug), { waitUntil: 'domcontentloaded' })
      const status = response?.status() ?? 0
      expect(status, `${p.slug} direct URL status`).toBeGreaterThanOrEqual(200)
      expect(status).toBeLessThan(400)

      const html = await page.content()
      expect(findFatalError(html), `fatal on ${p.slug}`).toBeNull()
      // S2: page-specific marker present.
      expect(html, `${p.slug} marker missing`).toContain(p.marker)
      // S1: pages parented directly under the top-level 'wpmmcc-ats' menu have
      // a visible submenu anchor; the four 'wptsall-manual'-orphaned pages
      // never render one (see PageSpec.menuVisible FINDING) — pin both.
      // href$= (ends-with) on the anchor slug: third-party menu items (e.g.
      // Elementor's Theme Builder) embed the CURRENT page URL unencoded inside
      // their own return_to= params, which satisfies href*= as a false
      // positive — those hrefs end with their own fragments/params, so
      // ends-with matches only our canonical admin.php?page=<anchor> links.
      const anchorSlug = p.anchorSlug ?? p.slug
      const menuAnchor = page.locator(`#adminmenu a[href$="page=${anchorSlug}"]`)
      if (p.menuVisible) {
        await expect(menuAnchor.first()).toBeVisible({ timeout: 10_000 })
      } else {
        await expect(menuAnchor, `${p.slug} is menu-orphaned (see FINDING) - anchor expected absent`).toHaveCount(0)
      }
    })
  }
})

test.describe('25 S3 capability matrix', () => {
  test('anonymous → login redirect for every page', async ({ browser }) => {
    const ctx = await browser.newContext()
    const page = await ctx.newPage()
    for (const p of PAGES) {
      await page.goto(ADMIN_URL(p.slug), { waitUntil: 'domcontentloaded' })
      expect(page.url(), `${p.slug} anonymous must hit wp-login`).toContain('wp-login.php')
    }
    await ctx.close()
  })

  test('editor → denied on every page (no marker, no fatal)', async () => {
    expect(editorCtx).toBeTruthy()
    const page = await (editorCtx as BrowserContext).newPage()
    for (const p of PAGES) {
      await page.goto(ADMIN_URL(p.slug), { waitUntil: 'domcontentloaded' })
      const html = await page.content()
      expect(findFatalError(html), `fatal for editor on ${p.slug}`).toBeNull()
      expect(html.includes(p.marker), `editor must NOT see ${p.slug} content`).toBe(false)
    }
    await page.close()
  })

  test('wptsall_translator → allowed only for translation-cap pages', async () => {
    expect(translatorCtx).toBeTruthy()
    const tpage = await (translatorCtx as BrowserContext).newPage()
    // FINDING (cross-plugin, probe-proven 2026-09-08): with WooCommerce
    // active (the e2e slot baseline set after a wp-unit shard reset),
    // WooCommerce's prevent_admin_access() redirects any user without
    // edit_posts / manage_woocommerce / view_admin_dashboard to the
    // front-end — the wptsall_translator role (read + manage_wptsall_*)
    // holds none of those, so it is locked out of ALL wp-admin pages,
    // including WPTSALL screens it has manage_wptsall_translations for.
    // Pin the actual behavior per environment flavor.
    const wc = await tpage.request.get('/wp-json/wc/v3')
    const wcActive = wc.status() !== 404
    for (const p of PAGES) {
      await tpage.goto(ADMIN_URL(p.slug), { waitUntil: 'domcontentloaded' })
      const html = await tpage.content()
      expect(findFatalError(html), `fatal for translator on ${p.slug}`).toBeNull()
      const allowed = p.cap === 'manage_wptsall_translations'
      if (wcActive && allowed) {
        // WooCommerce lockout: redirected to the front-end, no page marker.
        expect(html.includes(p.marker), `translator must NOT see ${p.slug} under the WooCommerce lockout`).toBe(false)
        expect(tpage.url(), `translator must be bounced off ${p.slug} by WooCommerce`).not.toContain(p.slug)
      } else {
        expect(
          html.includes(p.marker),
          `translator ${allowed ? 'must' : 'must NOT'} see ${p.slug}`,
        ).toBe(allowed)
      }
    }
    await tpage.close()
  })
})

test.describe('25 S4 primary state renders', () => {
  for (const p of PAGES) {
    test(`${p.slug}: primary content section renders`, async ({ page }) => {
      await wpLogin(page)
      await page.goto(ADMIN_URL(p.slug), { waitUntil: 'domcontentloaded' })
      const html = await page.content()
      expect(findFatalError(html), `fatal on ${p.slug} state`).toBeNull()
      // Primary section: data table/section or the documented empty marker.
      const hasTable = await page.locator('.wp-list-table, form, .wptsall-wizard-steps').first().isVisible()
      expect(hasTable, `${p.slug} primary section missing`).toBe(true)
    })
  }
})

/** Relation id of the lane-C fixture, read from the theme-plugin-loc select. */
async function fixtureRelationId(page: Page): Promise<number> {
  await page.goto(ADMIN_URL('wptsall-theme-plugin-loc'), { waitUntil: 'domcontentloaded' })
  const option = page.locator('#relation_id option', { hasText: 'v_lanec_1' }).first()
  // Graceful absence (slot reset wiped the fixture): count instead of an
  // auto-waiting attribute read so callers can test.skip(0).
  if ((await option.count()) === 0) return 0
  const value = await option.getAttribute('value')
  if (!value) return 0
  return parseInt(value, 10)
}

test.describe('25 S5 main-action closures', () => {
  test('custom-fields: no-op save round-trip for unknown row id', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-custom-fields'), { waitUntil: 'domcontentloaded' })
    const nonce = await scrapeNonce(page, 'wptsall_cf_save')
    const res = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
      form: {
        action: 'wptsall_cf_save',
        _wpnonce: nonce,
        'rows[999999][tr][en_US]': 'zz-lane-c-ignored',
      },
    })
    expect(res.url(), 'redirect back to page').toContain('page=wptsall-custom-fields')
    const html = await res.text()
    expect(findFatalError(html)).toBeNull()
    // Unknown row id is ignored gracefully: no row was created for it.
    expect(html.includes('zz-lane-c-ignored'), 'unknown row id must not be echoed as a row').toBe(false)
  })

  test('field-discovery: post-type filter closure', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-field-discovery'), { waitUntil: 'domcontentloaded' })
    await page.selectOption('select[name="post_type"]', 'page')
    await page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null)
    expect(page.url(), 'filter persists in URL').toContain('post_type=page')
    expect((await page.content())).toContain('Pages')
  })

  test('media: mapping save persists and lists after reload', async ({ page }) => {
    await wpLogin(page)
    // Find a real post id (the handler requires get_post() to succeed).
    const nonce = await restNonce(page)
    const posts = await page.request.get('/wp-json/wp/v2/posts?per_page=1', { headers: { 'X-WP-Nonce': nonce } })
    const [post] = (await posts.json()) as Array<{ id: number }>
    expect(post?.id, 'a post must exist for the media S5 flow').toBeTruthy()

    await page.goto(ADMIN_URL('wptsall-media'), { waitUntil: 'domcontentloaded' })
    const formNonce = await scrapeNonce(page, 'wptsall_media_save')
    const res = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
      form: {
        action: 'wptsall_media_save',
        _wpnonce: formNonce,
        source_id: String(post.id),
        target_id: String(post.id),
        target_lang: 'en_US',
        method: 'copy',
      },
    })
    expect(res.url(), 'redirect with updated flag').toContain('updated=1')

    await page.goto(ADMIN_URL('wptsall-media'), { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(html, 'mapping row visible after reload').toContain(`#${post.id}`)
  })

  test('models-backup: export downloads the models JSON payload', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-models-backup'), { waitUntil: 'domcontentloaded' })
    const nonce = await scrapeNonce(page, 'wptsall_model_export')
    const res = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
      form: { action: 'wptsall_model_export', _wpnonce: nonce },
    })
    const disposition = res.headers()['content-disposition'] ?? ''
    expect(disposition, 'export must be an attachment').toContain('attachment')
    const payload = (await res.json()) as { schema: string; count: number }
    expect(payload.schema).toBe('wptsall-models/1')
    expect(payload.count).toBeGreaterThanOrEqual(0)
  })

  test('pending: language filter closure', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-pending'), { waitUntil: 'domcontentloaded' })
    await page.selectOption('select[name="target_lang"]', 'en_US')
    await page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null)
    expect(page.url(), 'target_lang persists in URL').toContain('target_lang=en_US')
    expect(await page.content()).toContain('posts pending translation')
  })

  test('tax-translations: link term round-trip persists', async ({ page }) => {
    await wpLogin(page)
    // Self-seed a fresh category so the run never depends on leftover
    // unlinked terms (a previous run consumes the fixture by linking it).
    // The REST nonce is only available on screens that print wpApiSettings
    // (the post-login dashboard does; the tax page does not).
    const nonce = await restNonce(page)
    const stamp = Date.now()
    const created = await page.request.post('/wp-json/wp/v2/categories', {
      headers: { 'X-WP-Nonce': nonce },
      data: { name: `zz-lane-c-cat-${stamp}` },
    })
    const cat = (await created.json()) as { slug?: string }
    expect(created.status(), 'seed category for link').toBeLessThan(300)
    const slug = cat.slug ?? `zz-lane-c-cat-${stamp}`

    const relationId = await fixtureRelationId(page)
    test.skip(relationId === 0, 'seeded relation fixture missing on this slot')

    await page.goto(
      `${ADMIN_URL('wptsall-tax-translations')}&taxonomy=category&target_lang=en_US&relation_id=${relationId}`,
      { waitUntil: 'domcontentloaded' },
    )
    const row = page.locator(`tr:has(small code:text-is("${slug}"))`)
    await expect(row, 'seeded term must appear as an unlinked source row').toBeVisible()
    // Link the term to its own slug (terms are shared across the virtual
    // sites, so the target-language search resolves to the same term).
    await row.locator('input[name="target_term_search"]').fill(slug)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
      row.locator('form:has(input[value="wptsall_tax_link"]) button[type="submit"]').click(),
    ])
    // WP admin canonicalization strips wptsall_msg from the address bar (see
    // the other S5 notes), so pin the user-visible outcome instead: after the
    // link the term leaves the pending (unlinked) list.
    await page.goto(
      `${ADMIN_URL('wptsall-tax-translations')}&taxonomy=category&target_lang=en_US&relation_id=${relationId}`,
      { waitUntil: 'domcontentloaded' },
    )
    await expect(row, 'linked term must leave the pending list').toHaveCount(0)
  })

  test('theme-plugin-loc: i18n config save round-trip persists', async ({ page }) => {
    await wpLogin(page)
    const relationId = await fixtureRelationId(page)
    test.skip(relationId === 0, 'seeded relation fixture missing on this slot')

    await page.goto(`${ADMIN_URL('wptsall-theme-plugin-loc')}&relation_id=${relationId}`, {
      waitUntil: 'domcontentloaded',
    })
    const form = page.locator('form:has(input[value="wptsall_save_i18n_config"])')
    await expect(form).toBeVisible()
    await form.locator('input[name="translate_site_strings"]').check()
    await form.locator('#gettext_domain_whitelist').fill('zz-lane-c-whitelist')
    // WP core admin canonicalization (inline history.replaceState to the
    // <link rel=canonical> URL) strips the unregistered saved=1 arg from the
    // address bar before page.url() can observe it, so pin the server-side
    // redirect contract instead: the post-save navigation MUST hit the
    // saved=1 URL (200).
    const [savedResp] = await Promise.all([
      page.waitForResponse((res) => res.url().includes('saved=1') && res.status() === 200),
      form.locator('button[type="submit"], .button-primary').first().click(),
    ])
    expect(savedResp.url(), 'save redirect keeps relation context').toContain('saved=1')

    // Reload: the persisted config renders back.
    await page.goto(`${ADMIN_URL('wptsall-theme-plugin-loc')}&relation_id=${relationId}`, {
      waitUntil: 'domcontentloaded',
    })
    const html = await page.content()
    expect(html.includes('zz-lane-c-whitelist'), 'whitelist persisted after reload').toBe(true)
    const checked = await page
      .locator('form:has(input[value="wptsall_save_i18n_config"]) input[name="translate_site_strings"]')
      .isChecked()
    expect(checked, 'checkbox persisted after reload').toBe(true)
  })

  test('url-discovery: bulk run redirect loses JSON results (FINDING pin)', async ({ page }) => {
    await wpLogin(page)
    const nonce = await restNonce(page)
    const posts = await page.request.get('/wp-json/wp/v2/posts?per_page=1', { headers: { 'X-WP-Nonce': nonce } })
    const [post] = (await posts.json()) as Array<{ id: number; slug: string; link: string }>
    expect(post?.slug, 'a post must exist for url-discovery').toBeTruthy()

    // source_lang in the URL: the target <select> is rendered server-side
    // EXCLUDING the current source (class-url-discovery-page.php:90) and
    // there is no JS to re-render it after a client-side source change —
    // without this param the stored default (en_US) leaves no en_US
    // option in #target_lang and selectOption times out.
    await page.goto(`${ADMIN_URL('wptsall-url-discovery')}&source_lang=zh_CN`, {
      waitUntil: 'domcontentloaded',
    })
    await page.selectOption('#source_lang', 'zh_CN')
    await page.selectOption('#target_lang', 'en_US')
    await page.fill('#urls', `/${post.slug}/\n/zz-lane-c-no-such-${Date.now()}/`)
    // WP core admin canonicalization strips wptsall_results from the address
    // bar (see theme-plugin-loc S5 note), so pin the redirect request itself.
    const [resultsResp] = await Promise.all([
      page.waitForResponse((res) => res.url().includes('wptsall_results') && res.status() === 200),
      page.locator('form:has(input[value="wptsall_url_discovery_run"]) button[type="submit"]').click(),
    ])
    expect(resultsResp.url(), 'results carried through redirect').toContain('wptsall_results')

    // FINDING (functional defect, pinned as-is): handle_run() redirects with
    // wp_json_encode($results) in the query args, but wp_safe_redirect's
    // sanitize step strips JSON punctuation (double quotes and curly braces)
    // from the Location header. The round-tripped wptsall_results param is
    // therefore not valid JSON ("results:[url:...,...],found:1,..."), the
    // receiving page's json_decode() yields null, and the Results table never
    // renders after a bulk run. The service itself works (mapping is created;
    // see the REST/unit coverage); only the redirect-rendered report is lost.
    expect(resultsResp.url(), 'JSON braces must survive the redirect').not.toContain('%7B')
    const table = page.locator('table.wp-list-table')
    await expect(table, 'results table must NOT render while defect stands').toHaveCount(0)
  })

  test('users: mapping save persists and lists after reload', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-users'), { waitUntil: 'domcontentloaded' })
    const nonce = await scrapeNonce(page, 'wptsall_user_save')
    const res = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
      form: {
        action: 'wptsall_user_save',
        _wpnonce: nonce,
        source_id: '1',
        target_id: '1',
        target_lang: 'en_US',
      },
    })
    expect(res.url(), 'redirect with updated flag').toContain('updated=1')

    await page.goto(ADMIN_URL('wptsall-users'), { waitUntil: 'domcontentloaded' })
    const html = await page.content()
    expect(html, 'user mapping row visible after reload').toContain('#1')
  })

  test('wizard: step advance persists in wizard state', async ({ page }) => {
    await wpLogin(page)
    // Self-reset: render_page persists any ?step=N it is given, so visiting
    // with step=1 re-arms the wizard regardless of how far previous runs
    // advanced the stored state.
    await page.goto(`${ADMIN_URL('wptsall-wizard')}&step=1`, { waitUntil: 'domcontentloaded' })
    const active = await page.locator('.wptsall-wizard-steps li.active').first().textContent()
    const step = parseInt((active ?? '1').trim().charAt(0), 10) || 1
    expect(step, 'self-reset must land on step 1').toBe(1)
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
      page.locator('form:has(input[value="wptsall_wizard_step"]) button[type="submit"]').click(),
    ])
    const after = await page.locator('.wptsall-wizard-steps li.active').first().textContent()
    expect(parseInt((after ?? '').trim().charAt(0), 10), 'step advanced').toBe(step + 1)

    // Re-open without a step param: the persisted state drives the step.
    await page.goto(ADMIN_URL('wptsall-wizard'), { waitUntil: 'domcontentloaded' })
    const reopened = await page.locator('.wptsall-wizard-steps li.active').first().textContent()
    expect(parseInt((reopened ?? '').trim().charAt(0), 10), 'state persisted across reload').toBe(step + 1)
  })

  test('browse-as-role: start session round-trip', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-browse-as-role'), { waitUntil: 'domcontentloaded' })
    const startNonce = await scrapeNonce(page, 'wptsall_start_browse_session')

    // FINDING (performance, UI-28-04 — FIXED 2026-09-20 by the doc 28 §八
    // P2-1 rewrite): the old tracker did a get_transient + set_transient DB
    // round-trip PER STRING site-wide (quadratic; sessions grew to 570KB
    // options and armed renders took 10-18s, wedging workers). The rewrite
    // buffers per request and flushes once at shutdown with a MAX_HITS
    // ceiling, so armed renders are fast again. The flow below keeps the
    // defensive shape (maxRedirects: 0, Location pinned, ALWAYS disarm in
    // finally) because it also guards against any future re-degradation.
    let sessionEnded = false
    try {
      // Both admin-post requests run while the tracker is (or just got)
      // armed, so the server side of each takes 15s+ (shutdown-chain gettext
      // writes); allow 90s explicitly.
      const started = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
        form: { action: 'wptsall_start_browse_session', _wpnonce: startNonce, role: 'editor' },
        maxRedirects: 0,
        timeout: 90_000,
      })
      expect(started.status(), 'start must 302 back to the page').toBe(302)
      const location = started.headers()['location'] ?? ''
      expect(location, 'start redirect carries started=1').toContain('started=1')

      // The armed tracker captures the strings of ANY admin render, so the
      // browse page now shows the register form plus a live counter.
      // P2-1 rewrite (doc 28 §八): captures are buffered per request and
      // flushed ONCE at shutdown, so the FIRST armed render still reads a
      // zero counter — the count becomes visible on the NEXT request.
      // Load twice: first load records + flushes at its shutdown, second
      // load renders the accumulated count. Both loads must stay fast
      // (the old quadratic per-string writes made armed renders 15s+).
      await page.goto(ADMIN_URL('wptsall-browse-as-role'), {
        waitUntil: 'domcontentloaded',
        timeout: 90_000,
      })
      await page.goto(ADMIN_URL('wptsall-browse-as-role'), {
        waitUntil: 'domcontentloaded',
        timeout: 90_000,
      })
      const html = await page.content()
      const counter = html.match(/Captured strings this session:\s*(\d+)/)
      expect(counter, 'session counter renders while armed').toBeTruthy()
      expect(parseInt((counter ?? [])[1], 10), 'armed session must be recording').toBeGreaterThan(0)

      const registerNonce = await scrapeNonce(page, 'wptsall_register_browse_strings')
      const ended = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
        form: { action: 'wptsall_register_browse_strings', _wpnonce: registerNonce, relation_id: '0' },
        maxRedirects: 0,
        timeout: 90_000,
      })
      expect(ended.status(), 'register must 302 back to the page').toBe(302)
      expect(ended.headers()['location'] ?? '', 'session end redirect').toContain('registered=0')
      sessionEnded = true
    } finally {
      if (!sessionEnded) {
        // Best-effort disarm so a failing assertion never leaves the slow
        // session poisoning the slot for an hour.
        try {
          await page.goto(ADMIN_URL('wptsall-browse-as-role'), {
            waitUntil: 'domcontentloaded',
            timeout: 90_000,
          })
          const nonce = await page
            .locator('form:has(input[value="wptsall_register_browse_strings"]) input[name="_wpnonce"]')
            .first()
            .getAttribute('value')
          if (nonce) {
            await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
              form: { action: 'wptsall_register_browse_strings', _wpnonce: nonce, relation_id: '0' },
              maxRedirects: 0,
              timeout: 90_000,
            })
          }
        } catch {
          // Cleanup is best-effort; never mask the original failure.
        }
      }
    }
  })
})

test.describe('25 S6 nonce rejection on every admin-post action', () => {
  test('nonce-less POST dies on the WP failure screen', async ({ page }) => {
    await wpLogin(page)
    const actions = PAGES.filter((p) => p.action).map((p) => p.action as string)
    for (const action of actions) {
      const res = await page.request.post(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
        form: { action },
      })
      const body = await res.text()
      // WP 6.7 rejection screens: 403 wp_die "Something went wrong." /
      // "The link you followed has expired." (nonce) or "Forbidden" (cap).
      expect(res.status(), `nonce-less ${action} must be rejected (4xx)`).toBeGreaterThanOrEqual(400)
      expect(
        body.includes('Are you sure') ||
          body.includes('has expired') ||
          body.includes('Forbidden') ||
          body.includes('Something went wrong'),
        `nonce-less ${action} must die on a failure screen`,
      ).toBe(true)
      expect(findFatalError(body), `nonce-less ${action} must not fatal`).toBeNull()
    }
  })
})

test.describe('25 B4 pagination bar (ATS-P2-05 / 3.8flash: silent unreachable data)', () => {
  // The four list screens hardcoded a single fetch window (200/500 rows)
  // with no pager and no range indicator, while their service layers always
  // took limit/offset — rows past the window were silently unreachable.
  // The shared Admin_Page_Helper::render_pagination() bar must now render
  // on every one of them.
  const LIST_PAGES: Array<{ slug: string; marker: string }> = [
    { slug: 'wptsall-tm', marker: 'Translation Memory' },
    { slug: 'wptsall-media', marker: 'Media Translations' },
    { slug: 'wptsall-pending', marker: 'Pending Translations' },
    { slug: 'wptsall-tax-translations', marker: 'Taxonomy Translations' },
  ]

  test('pagination bar renders on the four list pages (counter + per-page + links)', async ({ page }) => {
    await wpLogin(page)
    for (const { slug, marker } of LIST_PAGES) {
      await page.goto(ADMIN_URL(slug), { waitUntil: 'domcontentloaded', timeout: 90_000 })
      const html = await page.content()
      expect(html, `${slug} renders its marker`).toContain(marker)
      expect(findFatalError(html), `${slug} must not fatal`).toBeNull()

      const bar = page.locator('.wptsall-pagination').first()
      await expect(bar, `${slug} renders the shared pagination bar`).toBeVisible()

      // The range indicator is the core affordance: N is finally visible.
      const counter = bar.locator('.displaying-num')
      await expect(counter, `${slug} range indicator`).toBeVisible()
      const m = ((await counter.textContent()) ?? '').match(/Displaying\s+[\d,.]+[–-][\d,.]+\s+of\s+([\d,.]+)/)
      expect(m, `${slug} counter shape: "Displaying X–Y of N"`).toBeTruthy()

      // Per-page selector with the 50/100/200 options.
      const perPage = bar.locator('select[name="per_page"]')
      await expect(perPage, `${slug} per-page selector`).toBeVisible()
      for (const v of ['50', '100', '200']) {
        await expect(perPage.locator(`option[value="${v}"]`), `${slug} per-page option ${v}`).toHaveCount(1)
      }

      // Page links appear only when the total exceeds the window; when they
      // do, following page 2 must really navigate the window.
      const total = parseInt((m as RegExpMatchArray)[1].replace(/[,.]/g, ''), 10)
      const currentPerPage = parseInt((await perPage.inputValue()) ?? '50', 10)
      if (total > currentPerPage) {
        const links = bar.locator('.pagination-links')
        await expect(links, `${slug} page links when total > window`).toBeVisible()
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
          links.locator('a', { hasText: '2' }).first().click(),
        ])
        expect(page.url(), `${slug} page-2 link navigates with paged=2`).toContain('paged=2')
      }
    }
  })

  test('per-page selector submits GET and resets to page 1', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wptsall-tm'), { waitUntil: 'domcontentloaded' })
    const bar = page.locator('.wptsall-pagination').first()
    await expect(bar).toBeVisible()
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => null),
      bar.locator('select[name="per_page"]').selectOption('100'),
    ])
    expect(page.url(), 'per_page form submits as GET').toContain('per_page=100')
    // The per-page form deliberately omits `paged` — changing the window size
    // resets to page 1 (no orphaned deep offsets past the new window end).
    expect(page.url(), 'per-page change resets paged').not.toContain('paged=')
    expect(findFatalError(await page.content()), 're-render after per_page change must not fatal').toBeNull()
  })
})

test.describe('25 C2 import entry consolidation (ATS-P2-02 / 3.8flash)', () => {
  // The Model Editor topbar carried TWO import entries ('Import Model' v3 +
  // legacy 'Import' paste/file modal) with no hint which to use; the whole-table
  // restore on Models Backup made three same-purpose doors. C2 removes the
  // legacy entry and cross-references the surviving two.
  test('Model Editor has exactly one import entry + cross-referenced copy', async ({ page }) => {
    await wpLogin(page)
    await page.goto(ADMIN_URL('wpmmcc-ats'), { waitUntil: 'domcontentloaded', timeout: 90_000 })
    expect(findFatalError(await page.content()), 'model editor renders').toBeNull()

    // Legacy entry and its modal are gone; the v3 entry survives.
    await expect(page.locator('#wptsall-import-model-v3-btn'), 'v3 Import Model entry').toBeVisible()
    await expect(page.locator('#wptsall-import-btn'), 'legacy Import entry removed').toHaveCount(0)
    await expect(page.locator('#wptsall-import-modal'), 'legacy import modal removed').toHaveCount(0)

    // Exactly ONE import-labeled action in the title bar.
    const importActions = page.locator('.page-title-action', { hasText: /import/i })
    await expect(importActions, 'exactly one import entry in the topbar').toHaveCount(1)

    // Editor-side cross-reference to the whole-table surface.
    await expect(page.getByText('Whole-table backup / restore lives on the Models Backup page.')).toBeVisible()

    // Backup-side reverse cross-reference to the single-model surface.
    await page.goto(ADMIN_URL('wptsall-models-backup'), { waitUntil: 'domcontentloaded', timeout: 90_000 })
    expect(findFatalError(await page.content()), 'models backup renders').toBeNull()
    await expect(
      page.getByText('to import a single model go back to Model Management and use its "Import Model" button.'),
    ).toBeVisible()
  })
})
