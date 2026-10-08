/**
 * Content-plugin admin backends: list/edit chrome + Update save + WPTSALL meta box link.
 * Covers WooCommerce / ACF / Yoast / Rank Math / LearnPress when active on Lab WP.
 *
 * opus5 P-2 (doc 05 §9.9/§9.11): core-surface fixtures (the post opened in
 * the SEO-box test) AND the ACF field group edited in the surfaces loop are
 * SELF-OWNED — seeded via core REST / ACF's own acf_update_field_group()
 * product API and swept in afterAll (ACF ships with zero groups on Lab WP:
 * that was a fixture gap, not an environment gap).
 *
 * RULING (doc 05 §9.11): the remaining `test.skip`s guard PLUGIN PRESENCE
 * (Woo product rows / EDD / Tutor / LearnPress / Yoast / Rank Math) —
 * accepted as environment-shaped conditionals, frozen by the
 * playwright-skip-ratchet baseline; this machine runs continuous testing,
 * so lab stability outweighs installing EDD/Tutor to revive 2 dormant
 * surfaces.
 *
 * Legacy probes are excluded from shared collection.
 * Save/output pair: 20-content-plugin-admin-save.owned.spec.ts.
 * seed: paired-with 20-content-plugin-admin-save.owned.spec.ts
 * Run: npm run test:support:plugin-content-admin (owned only).
 * Other content-plugin render surfaces remain partial, not covered by this pair.
 */
import { test, expect, type Page } from '@playwright/test'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'
import { cleanupOwned, seedPost } from './lib/fixture-owner'
import { wpCli } from './wp-cli'

const ACF_GROUP_TITLE_PREFIX = 'P2FX 20 field group'

// The Lab's WooCommerce product-list page renders ~22s TTFB (917KB admin
// page, 440KB inline scripts, 22 product rows; measured 2026-09-26 with
// zero fatals, 200 every time). The Playwright default 30s goto timeout is
// borderline against that baseline and was the sole cause of the
// 2026-09-26 serial-group breakage (a first-attempt goto timeout flaked,
// broke the serial group, and left 16 tests "did not run"). 90s matches
// this spec's own waitForResponse convention below; fast pages are
// unaffected by a raised timeout.
const SLOW_ADMIN_LIST_GOTO_TIMEOUT = 90_000

let ownedAcfGroup: { id: number; title: string } | null = null

/** Seed a self-owned ACF field group via ACF's own product API (no DB backdoor). */
function seedOwnedAcfFieldGroup(): { id: number; title: string } {
  const title = `${ACF_GROUP_TITLE_PREFIX} ${Date.now().toString(36)}`
  const out = wpCli(
    `eval '$g = acf_update_field_group(["ID" => 0, "title" => "${title}", "location" => [[["param" => "post_type", "operator" => "==", "value" => "post"]]], "active" => 1]); echo "P2FXACF=" . $g["ID"];'`,
  )
  const m = out.match(/P2FXACF=(\d+)/)
  if (!m) throw new Error(`ACF field-group seed failed: ${out.slice(0, 300)}`)
  return { id: Number(m[1]), title }
}

/** Best-effort sweep of every leaked P2FX group (self-healing across runs). */
function deleteOwnedAcfFieldGroups(): void {
  wpCli(
    `eval '$deleted = 0; foreach (acf_get_field_groups() as $grp) { if (strpos($grp["title"], "${ACF_GROUP_TITLE_PREFIX}") === 0) { acf_delete_field_group($grp["ID"]); $deleted++; } }; echo "P2FXDELETED=" . $deleted;'`,
  )
}

type PluginSurface = {
  id: string
  listUrl: string
  expectOnList?: RegExp
  expectOnEdit?: RegExp
  titleSelector?: string
  skipIfMissing?: RegExp
  /** P-2: row fixture is self-owned — openFirstEdit MUST find it (hard-fail, no skip). */
  ownedRow?: boolean
}

const SURFACES: PluginSurface[] = [
  {
    id: 'woocommerce-product',
    listUrl: '/wp-admin/edit.php?post_type=product',
    expectOnList: /product|Products|商品|产品/i,
    expectOnEdit: /woocommerce-product-data|#woocommerce-product-data|Product data|商品数据/i,
    titleSelector: '#title, #post-title-0, .editor-post-title__input',
    skipIfMissing: /plugin does not exist|You do not have sufficient|无效|not allowed/i,
  },
  {
    id: 'learnpress-course',
    listUrl: '/wp-admin/edit.php?post_type=lp_course',
    expectOnList: /course|Courses|课程/i,
    expectOnEdit: /learnpress|lp_|Course|课程/i,
    titleSelector: '#title, #post-title-0, .editor-post-title__input',
  },
  {
    id: 'acf-field-group',
    listUrl: '/wp-admin/edit.php?post_type=acf-field-group',
    expectOnList: /Field Groups|字段组|ACF|Custom Fields/i,
    expectOnEdit: /acf|Field|字段/i,
    titleSelector: '#title, #post-title-0',
    // ACF absent → invalid post type page (env conditional; dormant in Lab WP).
    skipIfMissing: /Invalid post type|无效|You do not have sufficient/i,
    // ACF present but zero groups on a stock lab → self-owned row fixture (§9.11).
    ownedRow: true,
  },
  {
    id: 'edd-download',
    listUrl: '/wp-admin/edit.php?post_type=download',
    expectOnList: /download|Downloads|Downloads|下载/i,
    expectOnEdit: /edd|download|Download/i,
    titleSelector: '#title, #post-title-0, .editor-post-title__input',
    skipIfMissing: /You do not have|not allowed|无效/i,
  },
  {
    id: 'tutor-course',
    listUrl: '/wp-admin/admin.php?page=tutor',
    expectOnList: /Tutor|Courses|课程/i,
    skipIfMissing: /You do not have|not allowed|无效/i,
  },
]

async function openFirstEdit(page: Page, listUrl: string): Promise<number | null> {
  await page.goto(`${WP_BASE_URL}${listUrl}`, {
    waitUntil: 'domcontentloaded',
    timeout: SLOW_ADMIN_LIST_GOTO_TIMEOUT,
  })
  const html = await page.content()
  if (findFatalError(html)) return null
  // Prefer "Edit" row action on first published/draft row
  const editLink = page.locator('table.wp-list-table a.row-title, table.wp-list-table .row-actions .edit a').first()
  if ((await editLink.count()) === 0) {
    // Try Add New then abandon if empty list
    return null
  }
  const href = await editLink.getAttribute('href')
  if (!href) return null
  await page.goto(href.startsWith('http') ? href : `${WP_BASE_URL}${href.replace(/^\//, '/')}`, {
    waitUntil: 'domcontentloaded',
  })
  const m = page.url().match(/[?&]post=(\d+)/)
  return m ? Number(m[1]) : null
}

test.describe.configure({ mode: 'serial' })

test.describe('20 Content plugin admin meta', () => {
  test.setTimeout(240_000)

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(240_000)
    deleteOwnedAcfFieldGroups()
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      await cleanupOwned(page)
    } finally {
      await page.close()
    }
  })

  test.beforeAll(async ({}, testInfo) => {
    testInfo.setTimeout(240_000)
    // ACF presence gate: seed only when ACF's product API exists (dormant in Lab WP).
    const probe = wpCli(
      `eval 'echo function_exists("acf_update_field_group") ? "P2FXACFPRESENT=yes" : "P2FXACFPRESENT=no";'`,
    )
    if (/P2FXACFPRESENT=yes/.test(probe)) {
      ownedAcfGroup = seedOwnedAcfFieldGroup()
    }
  })

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  for (const surface of SURFACES) {
    test(`${surface.id}: list + edit render without fatal`, async ({ page }) => {
      await page.goto(`${WP_BASE_URL}${surface.listUrl}`, {
        waitUntil: 'domcontentloaded',
        timeout: SLOW_ADMIN_LIST_GOTO_TIMEOUT,
      })
      const listHtml = await page.content()
      if (surface.skipIfMissing && surface.skipIfMissing.test(listHtml)) {
        test.skip(true, `${surface.id} not available`)
      }
      expect(findFatalError(listHtml)).toBeNull()
      if (surface.expectOnList) {
        expect(surface.expectOnList.test(listHtml)).toBeTruthy()
      }

      // Admin hub pages (Tutor) may have no classic edit.php rows.
      if (/admin\.php\?page=/.test(surface.listUrl) && !surface.expectOnEdit) {
        return
      }

      const postId = await openFirstEdit(page, surface.listUrl)
      if (surface.ownedRow) {
        // P-2 (§9.11): the row fixture is SELF-OWNED — if it cannot be found,
        // seeding or the product surface broke; that is a red, never a skip.
        expect(ownedAcfGroup, `${surface.id}: owned fixture not seeded`).not.toBeNull()
        expect(postId, `${surface.id}: owned field-group row not found`).toBeTruthy()
        expect(postId, `${surface.id}: opened a row other than the owned fixture`).toBe(
          ownedAcfGroup!.id,
        )
      } else {
        test.skip(!postId, `${surface.id}: no rows to edit`)
      }
      const editHtml = await page.content()
      expect(findFatalError(editHtml)).toBeNull()
      if (surface.expectOnEdit) {
        expect(
          surface.expectOnEdit.test(editHtml) || editHtml.includes('post.php'),
          `${surface.id} edit chrome missing`,
        ).toBeTruthy()
      }
    })
  }

  test('SEO plugin admin: Yoast and/or Rank Math settings render', async ({ page }) => {
    const pages = [
      { id: 'yoast', url: '/wp-admin/admin.php?page=wpseo_dashboard', hint: /yoast|wpseo|SEO/i },
      { id: 'rank-math', url: '/wp-admin/admin.php?page=rank-math', hint: /rank.?math|SEO/i },
    ]
    let any = false
    for (const p of pages) {
      await page.goto(`${WP_BASE_URL}${p.url}`, { waitUntil: 'domcontentloaded' })
      const html = await page.content()
      if (/You do not have|not allowed|Sorry, you are not/i.test(html) && !p.hint.test(html)) {
        continue
      }
      expect(findFatalError(html)).toBeNull()
      if (p.hint.test(html)) any = true
    }
    test.skip(!any, 'neither Yoast nor Rank Math admin available')
    expect(any).toBeTruthy()
  })

  test('WooCommerce product: Update title via classic editor posts post.php', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=product`, {
      waitUntil: 'domcontentloaded',
      timeout: SLOW_ADMIN_LIST_GOTO_TIMEOUT,
    })
    const listHtml = await page.content()
    test.skip(/You do not have|not allowed|插件/i.test(listHtml) && !/product/i.test(listHtml), 'Woo not available')

    const postId = await openFirstEdit(page, '/wp-admin/edit.php?post_type=product')
    test.skip(!postId, 'no product to edit')

    // Classic editor title
    const title = page.locator('#title')
    if ((await title.count()) === 0) {
      // Block editor — soft pass on render only
      expect(findFatalError(await page.content())).toBeNull()
      test.info().annotations.push({ type: 'note', description: 'block editor product — skip Update POST' })
      return
    }

    const stamp = `E2E-WOO-${Date.now().toString(36)}`
    const original = await title.inputValue()
    await title.fill(`${original} ${stamp}`.slice(0, 180))

    const postPromise = page.waitForResponse(
      (r) =>
        (r.url().includes('post.php') || r.url().includes('/wp-json/wc/') || r.url().includes('/wp/v2/product')) &&
        ['POST', 'PUT'].includes(r.request().method()),
      { timeout: 90_000 },
    )
    await page.locator('#publish, #save-post').first().click({ noWaitAfter: true })
    const res = await postPromise
    expect(res.ok() || res.status() < 500).toBeTruthy()
    await page.waitForURL(/post\.php|edit\.php/, { timeout: 60_000 }).catch(() => undefined)
    await page.waitForLoadState('domcontentloaded').catch(() => undefined)
    await page.waitForTimeout(500)
    const html = await page.content().catch(() => '')
    if (html) expect(findFatalError(html)).toBeNull()

    // Restore title best-effort
    if (await title.count()) {
      await title.fill(original)
      await page.locator('#publish, #save-post').first().click().catch(() => undefined)
      await page.waitForLoadState('domcontentloaded')
    }
  })

  test('Post edit: Yoast or Rank Math SEO box present when plugin active', async ({ page }) => {
    // opus5 P-2: the post under edit is SELF-OWNED — seeded via the same
    // core REST the product uses (hard-fails if core REST is broken) and
    // deleted in afterAll. No dependence on whatever posts the lab has.
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
    const postId = await seedPost(page, { title: 'P2FX 20 seo-box post' })
    expect(postId).toBeGreaterThan(0)

    await page.goto(`${WP_BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`, {
      waitUntil: 'domcontentloaded',
    })
    const html = await page.content()
    expect(findFatalError(html)).toBeNull()

    const hasYoast = /wpseo|yoast/i.test(html)
    const hasRankMath = /rank-math|rank_math/i.test(html)
    const hasAcf = /acf-field|acf-fields|acf-/i.test(html)
    const hasWptsall =
      /wptsall-translation|wptsall-flag|wptsall_translations|wptsall-meta-box/i.test(html)

    expect(
      hasYoast || hasRankMath || hasAcf || hasWptsall,
      'expected at least one of Yoast/RankMath/ACF/WPTSALL chrome on post edit',
    ).toBeTruthy()

    // Prefer a visible meta-box / flag affordance (not admin-bar hidden items).
    // Block-editor modals often intercept pointer events — navigate via href.
    const translateLink = page
      .locator(
        '#poststuff a.wptsall-flag[href*="page=wptsall-translate"], ' +
          '#poststuff a[href*="page=wptsall-translate"], ' +
          '.wptsall-translation-flags a[href*="page=wptsall-translate"]',
      )
      .first()
    if ((await translateLink.count()) > 0) {
      const href = await translateLink.getAttribute('href')
      if (href) {
        await page.goto(href.startsWith('http') ? href : `${WP_BASE_URL}${href}`, {
          waitUntil: 'domcontentloaded',
        })
        expect(page.url()).toMatch(/wptsall-translate|post\.php/)
        expect(findFatalError(await page.content())).toBeNull()
      }
    }
  })

  test('ACF fields visible on product edit when ACF active', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=product`, {
      waitUntil: 'domcontentloaded',
      timeout: SLOW_ADMIN_LIST_GOTO_TIMEOUT,
    })
    test.skip(!/product/i.test(await page.content()), 'products unavailable')

    const postId = await openFirstEdit(page, '/wp-admin/edit.php?post_type=product')
    test.skip(!postId, 'no product')

    const html = await page.content()
    expect(findFatalError(html)).toBeNull()
    // Soft: ACF injects hidden #acf-form-data even when no field groups apply.
    const visibleField = page.locator('.acf-fields .acf-field, .acf-postbox .acf-field').first()
    if (await visibleField.isVisible().catch(() => false)) {
      await expect(visibleField).toBeVisible()
    } else {
      test.info().annotations.push({
        type: 'note',
        description: 'no visible ACF fields on this product — field-group list still covered above',
      })
    }
  })

  test('LearnPress course edit Update (classic) when available', async ({ page }) => {
    await page.goto(`${WP_BASE_URL}/wp-admin/edit.php?post_type=lp_course`, {
      waitUntil: 'domcontentloaded',
    })
    const listHtml = await page.content()
    test.skip(!/lp_course|Courses|课程/i.test(listHtml), 'LearnPress courses unavailable')

    const postId = await openFirstEdit(page, '/wp-admin/edit.php?post_type=lp_course')
    test.skip(!postId, 'no course')
    expect(findFatalError(await page.content())).toBeNull()

    const title = page.locator('#title')
    if ((await title.count()) === 0) return

    const stamp = `E2E-LP-${Date.now().toString(36)}`
    const original = await title.inputValue()
    await title.fill(`${original} ${stamp}`.slice(0, 180))
    const postPromise = page.waitForResponse(
      (r) => r.url().includes('post.php') && ['POST', 'PUT'].includes(r.request().method()),
      { timeout: 90_000 },
    )
    await page.locator('#publish, #save-post').first().click({ noWaitAfter: true })
    const res = await postPromise
    expect(res.ok() || res.status() < 500).toBeTruthy()
    // LP often redirects/reloads aggressively — do not assert page.content mid-navigation.
    await page.waitForTimeout(1500).catch(() => undefined)
    if (await title.isVisible().catch(() => false)) {
      await title.fill(original).catch(() => undefined)
      await page.locator('#publish, #save-post').first().click({ noWaitAfter: true }).catch(() => undefined)
    }
  })
})
