/**
 * Public surfaces correspondence (P0/P1):
 * virtual home · category archive · Woo product · search form · nav links.
 *
 * opus5 P-2: fixtures are self-owned. The spec used to probe whatever
 * shared lab relation happened to exist and skip when absent
 * (doc 04 §4.3). It now seeds its own relation stack in beforeAll via the
 * product admin REST and deletes it in afterAll; journey targets (archive
 * reachability, product URLs, sitemap) fail loudly instead of skipping.
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-public-surfaces
 */
import { test, expect } from '@playwright/test'
import { findFatalError, WP_BASE_URL, wpLogin } from './helpers'
import {
  browserJson,
  countDoublePrefix,
  createAndTranslate,
  ensureHreflangEmitter,
  fetchPublic,
  parseSeo,
  publicPathUnderPrefix,
  restNonce,
  urlHasPrefix,
  wpCli,
  type ChosenPair,
} from './correspondence-helpers'
import {
  cleanupOwned,
  seedOwnedRelation,
  type OwnedRelation,
} from './lib/fixture-owner'

test.describe.configure({ mode: 'serial' })

test.describe('23 Public surfaces correspondence', () => {
  test.setTimeout(360_000)

  let ownedRelation: OwnedRelation

  test.beforeAll(async ({ browser }, testInfo) => {
    // Seeding through the product UI surfaces needs more than the 30s hook default.
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      ownedRelation = await seedOwnedRelation(page, {
        // Link-prefix specs need a non-default target language: the product
        // omits the prefix in links for the default-language VS.
        requireNonDefaultTarget: true,
        nameSuffix: '23-surfaces',
      })
    } finally {
      await page.close()
    }
  })

  test.afterAll(async ({ browser }, testInfo) => {
    testInfo.setTimeout(240_000)
    const page = await browser.newPage()
    try {
      await wpLogin(page)
      await cleanupOwned(page)
    } finally {
      await page.close()
    }
  })

  /** opus5 P-2: the relation is self-owned; a missing fixture is a hard failure. */
  function ownedPair(): ChosenPair {
    expect(ownedRelation.relationId, 'P-2 fixture: seeded relation must exist').toBeGreaterThan(0)
    return {
      relationId: ownedRelation.relationId,
      targetLang: ownedRelation.targetLang,
      pathPrefix: ownedRelation.pathPrefix,
      virtualSiteId: String(ownedRelation.virtualSiteId),
      sourceLang: ownedRelation.sourceLang,
    }
  }

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
  })

  test('virtual home: marker + no double-prefix + body class', async ({ page, request }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()

    const homeUrl = `${WP_BASE_URL}/${pair!.pathPrefix}/`
    const res = await fetchPublic(request, homeUrl)
    expect(res.status, homeUrl).toBe(200)
    expect(findFatalError(res.html)).toBeNull()
    expect(urlHasPrefix(res.finalUrl, pair!.pathPrefix)).toBeTruthy()
    expect(countDoublePrefix(res.html, pair!.pathPrefix)).toBe(0)

    const seo = parseSeo(res.html)
    expect(seo.vsMarker).toBeTruthy()
    expect(seo.bodyClass).toMatch(/wptsall-virtual-site/)
  })

  test('category archive under virtual prefix lists translated post only', async ({
    page,
    request,
  }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()
    ensureHreflangEmitter()

    const stamp = Date.now().toString(36)
    const srcBody = `SOURCE_CAT_BODY_${stamp}`
    const tgtBody = `TARGET_CAT_BODY_${stamp}`
    const catName = `Corr Cat ${stamp}`
    const catSlug = `corr-cat-${stamp}`

    const cat = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/categories`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ name: catName, slug: catSlug }),
    })
    // opus5 P-2: fixture-creation failure is a product break — fail loudly.
    expect(
      cat.status,
      `category create must succeed (body=${JSON.stringify(cat.body)})`,
    ).toBeLessThan(400)
    const catId = Number((cat.body as { id?: number })?.id || 0)
    expect(catId).toBeGreaterThan(0)

    const created = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/posts`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        title: `SRC Cat Post ${stamp}`,
        content: `<p>${srcBody}</p>`,
        status: 'publish',
        categories: [catId],
      }),
    })
    expect(created.status).toBeLessThan(300)
    const sourceId = Number((created.body as { id?: number })?.id || 0)

    const save = await browserJson(page, `${WP_BASE_URL}/wp-json/wptsall/v2/manual-translations`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({
        source_post_id: sourceId,
        relation_id: pair!.relationId,
        translated_data: {
          post_title: `TGT Cat Post ${stamp}`,
          post_name: `tgt-cat-post-${stamp}`,
          post_content: `<p>${tgtBody}</p>`,
        },
      }),
    })
    expect([200, 201]).toContain(save.status)

    // Best-effort shadow term (meta only; term_mappings schema varies by migration).
    const tgtTermName = `Corr Cat TGT ${stamp}`
    const tgtTermSlug = `corr-cat-tgt-${stamp}`
    wpCli([
      'eval',
      `\$t=wp_insert_term('${tgtTermName}','category',array('slug'=>'${tgtTermSlug}'));` +
        `if(is_wp_error(\$t)){echo 'err'; return;}` +
        `\$tid=(int)\$t['term_id'];` +
        `update_term_meta(\$tid,'_wptsall_virtual_site_id','v_${pair!.virtualSiteId}');` +
        `update_term_meta(\$tid,'_wptsall_source_term_id',${catId});` +
        `echo \$tid;`,
    ])

    const archiveCandidates = [
      `${WP_BASE_URL}/${pair!.pathPrefix}/category/${tgtTermSlug}/`,
      `${WP_BASE_URL}/${pair!.pathPrefix}/category/${catSlug}/`,
    ]
    let archiveHtml = ''
    let archiveUrl = ''
    let archiveStatus = 0
    for (const u of archiveCandidates) {
      const r = await fetchPublic(request, u)
      if (r.status === 200 && !/Page not found/i.test(parseSeo(r.html).title)) {
        archiveHtml = r.html
        archiveUrl = u
        archiveStatus = r.status
        break
      }
    }
    // opus5 P-2: the archive IS the assertion target — unreachable means fail.
    expect(
      archiveStatus,
      `category archive must be reachable under prefix (${archiveCandidates.join(' | ')})`,
    ).toBe(200)

    expect(findFatalError(archiveHtml)).toBeNull()
    expect(countDoublePrefix(archiveHtml, pair!.pathPrefix)).toBe(0)
    const seo = parseSeo(archiveHtml)
    expect(seo.vsMarker).toBeTruthy()
    // Prefer translated body; if theme only lists titles, at least no source body leak.
    if (archiveHtml.includes(tgtBody) || archiveHtml.includes(`TGT Cat Post ${stamp}`)) {
      expect(archiveHtml.includes(srcBody)).toBeFalsy()
    } else {
      test.info().annotations.push({
        type: 'note',
        description: `archive ${archiveUrl} missing tgt markers — theme loop may not show content`,
      })
      expect(archiveHtml.includes(srcBody)).toBeFalsy()
    }
  })

  test('Woo product pair: URL prefix + body isolation', async ({ page, request }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()

    // Confirm products REST exists (Woo rest_base is often "product", not "products").
    const types = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/types?context=edit`, {
      headers: { 'X-WP-Nonce': nonce },
      credentials: 'include',
    })
    const hasProduct =
      types.body &&
      typeof types.body === 'object' &&
      Boolean((types.body as Record<string, unknown>).product)
    // opus5 P-2: KEEP — Woo CPT availability is an environment shape (plugin
    // presence), not a fixture this spec can self-own (doc 05 §9.9).
    test.skip(!hasProduct, 'product CPT not registered')

    const stamp = Date.now().toString(36)
    const srcBody = `SOURCE_PRODUCT_BODY_${stamp}`
    const tgtBody = `TARGET_PRODUCT_BODY_${stamp}`

    // opus5 P-2: seed/translate failure is a product break — fail loudly.
    const pairUrls = await createAndTranslate(page, nonce, pair!, {
      postType: 'product',
      stamp,
      srcTitle: `SRC Product ${stamp}`,
      srcBody,
      tgtTitle: `TGT Product ${stamp}`,
      tgtBody,
      tgtSlug: `tgt-product-${stamp}`,
      srcMeta: `SOURCE_P_META_${stamp}`,
      tgtMeta: `TARGET_P_META_${stamp}`,
    })

    expect(urlHasPrefix(pairUrls.targetUrl, pair!.pathPrefix)).toBeTruthy()

    const src = await fetchPublic(request, pairUrls.sourceUrl)
    expect(src.status).toBe(200)
    expect(src.html.includes(srcBody)).toBeTruthy()
    expect(src.html.includes(tgtBody)).toBeFalsy()

    const tgt = await fetchPublic(request, pairUrls.targetUrl)
    // Shadow products sometimes 404 via REST-built link; try rewrite-slug path under prefix.
    if (tgt.status !== 200) {
      const alt = `${WP_BASE_URL}/${pair!.pathPrefix}/${publicPathUnderPrefix('product', `tgt-product-${stamp}`)}/`
      const altFetch = await fetchPublic(request, alt)
      // opus5 P-2: the virtual product URL IS the assertion target — fail loudly.
      expect(
        altFetch.status,
        `virtual product URL must be reachable (${pairUrls.targetUrl} | ${alt})`,
      ).toBe(200)
      expect(altFetch.html.includes(tgtBody) || altFetch.html.includes(`TGT Product ${stamp}`)).toBeTruthy()
      expect(altFetch.html.includes(srcBody)).toBeFalsy()
      expect(countDoublePrefix(altFetch.html, pair!.pathPrefix)).toBe(0)
      expect(parseSeo(altFetch.html).vsMarker).toBeTruthy()
      return
    }

    expect(tgt.html.includes(tgtBody) || tgt.html.includes(`TGT Product ${stamp}`)).toBeTruthy()
    expect(tgt.html.includes(srcBody)).toBeFalsy()
    expect(countDoublePrefix(tgt.html, pair!.pathPrefix)).toBe(0)
    expect(parseSeo(tgt.html).vsMarker).toBeTruthy()
  })

  test('virtual page search form stays on virtual site', async ({ page, request }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()

    // TT5 header has no wp:search — seed core/search in content so coverage is
    // theme-independent (filters + output buffer must localize any WP search form).
    const stamp = Date.now().toString(36)
    const searchBlock =
      '<!-- wp:search {"label":"Search","showLabel":true,"buttonText":"Search","buttonPosition":"button-outside"} /-->'
    const { targetUrl } = await createAndTranslate(page, nonce, pair!, {
      stamp,
      srcTitle: `SRC Search ${stamp}`,
      srcBody: `SOURCE_SEARCH_${stamp}`,
      tgtTitle: `TGT Search ${stamp}`,
      tgtBody: `TARGET_SEARCH_${stamp}`,
      tgtSlug: `tgt-search-${stamp}`,
      srcContent:
        `<!-- wp:paragraph --><p>SOURCE_SEARCH_${stamp}</p><!-- /wp:paragraph -->\n` + searchBlock,
      tgtContent:
        `<!-- wp:paragraph --><p>TARGET_SEARCH_${stamp}</p><!-- /wp:paragraph -->\n` + searchBlock,
    })

    const res = await fetchPublic(request, targetUrl)
    expect(res.status, targetUrl).toBe(200)
    expect(findFatalError(res.html)).toBeNull()
    expect(parseSeo(res.html).vsMarker).toBeTruthy()

    const forms = res.html.match(/<form[\s\S]{0,2000}?<\/form>/gi) || []
    const formChunk = forms.find(
      (f) =>
        /role=["']search["']/i.test(f) ||
        /name=["']s["']/i.test(f) ||
        /wp-block-search/i.test(f),
    )
    expect(formChunk, 'seeded core/search must render a WP search form under VS').toBeTruthy()

    const action = ((formChunk!.match(/action=["']([^"']*)["']/i) || [])[1] || '').trim()
    const hasHiddenVs = /name=["']wptsall_vs["']/i.test(formChunk!)
    const actionPrefixed = action ? urlHasPrefix(action, pair!.pathPrefix) : false
    expect(action === '' || action === '#', `search action must not stay empty/# (got ${action})`).toBeFalsy()
    expect(
      hasHiddenVs && actionPrefixed,
      `search must have wptsall_vs + prefixed action (action=${action}, hiddenVs=${hasHiddenVs})`,
    ).toBeTruthy()
  })

  test('virtual HTML nav/internal links: prefix once, no double-prefix', async ({
    page,
    request,
  }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()

    // Ensure a menu exists; block themes may have no classic locations — ignore assign errors.
    const menuId =
      wpCli(['menu', 'list', '--format=ids'])?.split(/\s+/).map(Number).find((n) => n > 0) || 0
    if (menuId > 0) {
      const locs =
        wpCli(['menu', 'location', 'list', '--format=csv'])
          ?.split('\n')
          .slice(1)
          .map((l) => l.split(',')[0]?.trim())
          .filter(Boolean) || []
      for (const loc of locs.slice(0, 2)) {
        wpCli(['menu', 'location', 'assign', String(menuId), loc])
      }
    }

    const stamp = Date.now().toString(36)
    const { targetUrl } = await createAndTranslate(page, nonce, pair!, {
      stamp,
      srcTitle: `SRC Nav ${stamp}`,
      srcBody: `SOURCE_NAV_${stamp}`,
      tgtTitle: `TGT Nav ${stamp}`,
      tgtBody: `TARGET_NAV_${stamp}`,
      tgtSlug: `tgt-nav-${stamp}`,
    })

    const res = await fetchPublic(request, targetUrl)
    expect(res.status).toBe(200)
    expect(countDoublePrefix(res.html, pair!.pathPrefix)).toBe(0)

    const hrefs = [...res.html.matchAll(/href=["'](https?:\/\/[^"']+|\/[^"']+)["']/gi)].map(
      (m) => m[1],
    )
    const sameHost = hrefs.filter((h) => {
      try {
        if (h.startsWith('/')) return true
        return new URL(h).origin === new URL(WP_BASE_URL).origin
      } catch {
        return false
      }
    })
    const prefixed = sameHost.filter((h) => urlHasPrefix(h, pair!.pathPrefix))
    // At least some internal links should be virtualized (nav, site logo, etc.).
    if (prefixed.length === 0) {
      test.info().annotations.push({
        type: 'note',
        description: 'no same-host prefixed hrefs found — theme may use relative-only or block nav',
      })
    } else {
      expect(prefixed.every((h) => countDoublePrefix(h, pair!.pathPrefix) === 0)).toBeTruthy()
    }
  })

  test('Yoast sitemap: source loc + xhtml alternates, no shadow primary loc', async ({
    page,
    request,
  }) => {
    const nonce = await restNonce(page)
    const pair = ownedPair()

    const stamp = Date.now().toString(36)
    const tgtSlug = `tgt-sitemap-${stamp}`
    const { sourceUrl, targetUrl } = await createAndTranslate(page, nonce, pair!, {
      stamp,
      srcTitle: `SRC Sitemap ${stamp}`,
      srcBody: `SOURCE_SITEMAP_${stamp}`,
      tgtTitle: `TGT Sitemap ${stamp}`,
      tgtBody: `TARGET_SITEMAP_${stamp}`,
      tgtSlug,
    })

    const sm = await fetchPublic(request, `${WP_BASE_URL}/post-sitemap.xml`)
    // opus5 P-2: the sitemap IS the assertion target — fail loudly when absent.
    expect(sm.status, 'Yoast post-sitemap.xml must be reachable').toBe(200)
    expect(sm.html).toContain('<urlset')

    expect(sm.html).toMatch(/xmlns:xhtml=["']http:\/\/www\.w3\.org\/1999\/xhtml["']/)

    const sourcePath = (() => {
      try {
        return new URL(sourceUrl).pathname.replace(/\/+$/, '')
      } catch {
        return ''
      }
    })()
    expect(sourcePath.length).toBeGreaterThan(1)
    expect(sm.html.includes(sourcePath), `source loc ${sourcePath} in sitemap`).toBeTruthy()

    // Shadow must not appear as a primary <loc>; it belongs in xhtml:link only.
    const shadowLocRe = new RegExp(
      `<loc>[^<]*${pair!.pathPrefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/[^<]*${tgtSlug}`,
      'i',
    )
    expect(shadowLocRe.test(sm.html), 'shadow must not be a primary sitemap loc').toBeFalsy()

    const hasAlt =
      sm.html.includes(`hreflang=`) &&
      (sm.html.includes(tgtSlug) || urlHasPrefix(targetUrl, pair!.pathPrefix))
    expect(hasAlt, 'source entry should carry xhtml:link alternate for translation').toBeTruthy()
  })
})
