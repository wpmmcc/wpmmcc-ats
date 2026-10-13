/**
 * Source ↔ translated public correspondence (P0 singular post):
 * URL · body isolation · Yoast SEO · canonical · locale · hreflang · double-prefix · body class.
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-correspondence
 */
import { test, expect } from '@playwright/test'
import { findFatalError, WP_BASE_URL, wpLogin } from './helpers'
import {
  chooseActiveVirtualRelation,
  countDoublePrefix,
  createAndTranslate,
  ensureHreflangEmitter,
  fetchPublic,
  langToken,
  normalizeUrl,
  parseSeo,
  restNonce,
  urlHasPrefix,
} from './correspondence-helpers'

test.describe('22 Source ↔ translated URL/content/SEO correspondence', () => {
  test.setTimeout(300_000)

  test('paired public pages: URL + body + SEO + isolation (P0)', async ({ page, request }) => {
    await wpLogin(page)
    await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
    const nonce = await restNonce(page)
    expect(nonce.length).toBeGreaterThan(0)

    const pair = await chooseActiveVirtualRelation(page, nonce)
    test.skip(!pair, 'no active virtual-site relation with path_prefix')

    ensureHreflangEmitter()

    const stamp = Date.now().toString(36)
    const srcBody = `SOURCE_BODY_MARKER_${stamp}`
    const tgtBody = `TARGET_BODY_MARKER_${stamp}`
    const srcMeta = `SOURCE_META_${stamp}`
    const tgtMeta = `TARGET_META_${stamp}`

    const { sourceUrl, targetUrl, sourceId } = await createAndTranslate(page, nonce, pair!, {
      stamp,
      srcTitle: `SRC Correspondence EN ${stamp}`,
      srcBody,
      tgtTitle: `TGT Correspondence ${pair!.targetLang} ${stamp}`,
      tgtBody,
      tgtSlug: `tgt-corr-${stamp}`,
      srcMeta,
      tgtMeta,
    })
    expect(sourceId).toBeGreaterThan(0)
    expect(sourceUrl).toContain(WP_BASE_URL)
    expect(targetUrl).toContain(`/${pair!.pathPrefix}/`)

    // --- Source ---
    const srcFetch = await fetchPublic(request, sourceUrl)
    expect(srcFetch.status, sourceUrl).toBe(200)
    expect(findFatalError(srcFetch.html)).toBeNull()
    expect(srcFetch.html.includes(srcBody)).toBeTruthy()
    expect(srcFetch.html.includes(tgtBody)).toBeFalsy()
    expect(urlHasPrefix(sourceUrl, pair!.pathPrefix)).toBeFalsy()
    expect(countDoublePrefix(srcFetch.html, pair!.pathPrefix)).toBe(0)

    const srcSeo = parseSeo(srcFetch.html)
    expect(srcSeo.vsMarker).toBeFalsy()
    expect(srcSeo.bodyClass).not.toMatch(/wptsall-virtual-site/)
    expect(srcSeo.title).toMatch(new RegExp(stamp, 'i'))
    expect(srcSeo.title).not.toMatch(/Yoast TGT/i)
    if (srcSeo.canonical) {
      expect(urlHasPrefix(srcSeo.canonical, pair!.pathPrefix)).toBeFalsy()
      expect(normalizeUrl(srcSeo.canonical)).toBe(normalizeUrl(sourceUrl))
    }
    if (srcSeo.metaDescription) expect(srcSeo.metaDescription).toContain(srcMeta)
    if (srcSeo.ogTitle) {
      expect(srcSeo.ogTitle).toMatch(/OG SRC|Yoast SRC|SRC Correspondence/i)
      expect(srcSeo.ogTitle).not.toMatch(/OG TGT/i)
    }
    if (srcSeo.ogUrl) {
      expect(urlHasPrefix(srcSeo.ogUrl, pair!.pathPrefix)).toBeFalsy()
      expect(normalizeUrl(srcSeo.ogUrl)).toBe(normalizeUrl(sourceUrl))
    }

    // --- Virtual ---
    const tgtFetch = await fetchPublic(request, targetUrl)
    expect(tgtFetch.status, targetUrl).toBe(200)
    expect(urlHasPrefix(tgtFetch.finalUrl, pair!.pathPrefix)).toBeTruthy()
    expect(findFatalError(tgtFetch.html)).toBeNull()
    expect(tgtFetch.html.includes(tgtBody)).toBeTruthy()
    expect(tgtFetch.html.includes(srcBody)).toBeFalsy()
    expect(countDoublePrefix(tgtFetch.html, pair!.pathPrefix)).toBe(0)

    const tgtSeo = parseSeo(tgtFetch.html)
    expect(tgtSeo.vsMarker).toBeTruthy()
    expect(tgtSeo.bodyClass).toMatch(/wptsall-virtual-site/)
    expect(tgtSeo.bodyClass).toMatch(/wptsall-site-/i)
    expect(tgtSeo.title).toMatch(/Yoast TGT|TGT Correspondence/i)
    expect(tgtSeo.title).not.toMatch(/Yoast SRC/i)
    expect(tgtSeo.canonical).toBeTruthy()
    expect(urlHasPrefix(tgtSeo.canonical, pair!.pathPrefix)).toBeTruthy()
    expect(normalizeUrl(tgtSeo.canonical)).toBe(normalizeUrl(targetUrl))
    if (tgtSeo.metaDescription) {
      expect(tgtSeo.metaDescription).toContain(tgtMeta)
      expect(tgtSeo.metaDescription).not.toContain(srcMeta)
    }
    if (tgtSeo.ogTitle) {
      expect(tgtSeo.ogTitle).toMatch(/OG TGT|Yoast TGT|TGT Correspondence/i)
      expect(tgtSeo.ogTitle).not.toMatch(/OG SRC/i)
    }
    if (tgtSeo.ogUrl) {
      expect(urlHasPrefix(tgtSeo.ogUrl, pair!.pathPrefix)).toBeTruthy()
      expect(normalizeUrl(tgtSeo.ogUrl)).toBe(normalizeUrl(targetUrl))
    }

    // Locale / language attributes on virtual (when theme/SEO emits them).
    const tgtLangTok = langToken(pair!.targetLang)
    if (tgtSeo.htmlLang) {
      expect(tgtSeo.htmlLang.toLowerCase()).toContain(tgtLangTok)
    }
    if (tgtSeo.ogLocale) {
      expect(tgtSeo.ogLocale.toLowerCase().replace(/_/g, '-')).toContain(tgtLangTok)
    }
    if (tgtSeo.bodyClass && /lang-/i.test(tgtSeo.bodyClass)) {
      expect(tgtSeo.bodyClass.toLowerCase()).toMatch(new RegExp(`lang-.*${tgtLangTok}`, 'i'))
    }

    expect(normalizeUrl(sourceUrl)).not.toBe(normalizeUrl(targetUrl))
    expect(srcSeo.title).not.toBe(tgtSeo.title)

    // Source home must not leak our target marker / VS chrome.
    const home = await fetchPublic(request, `${WP_BASE_URL}/`)
    expect(home.status).toBe(200)
    expect(home.html.includes(tgtBody)).toBeFalsy()
    expect(/<!--\s*wptsall virtual site/i.test(home.html)).toBeFalsy()

    // Hreflang: virtual page must emit after we force head emitter.
    expect(tgtSeo.hreflangs.length, 'virtual singular must emit head hreflang').toBeGreaterThan(0)
    const langs = tgtSeo.hreflangs.map((a) => a.lang.toLowerCase())
    expect(langs.some((l) => l === 'x-default')).toBeTruthy()
    const hasPrefixed = tgtSeo.hreflangs.some((a) => urlHasPrefix(a.href, pair!.pathPrefix))
    const hasUnprefixed = tgtSeo.hreflangs.some((a) => !urlHasPrefix(a.href, pair!.pathPrefix))
    expect(hasPrefixed, 'hreflang should include virtual URL').toBeTruthy()
    expect(hasUnprefixed, 'hreflang should include source (unprefixed) URL').toBeTruthy()
    // No duplicate lang codes.
    expect(new Set(langs).size).toBe(langs.length)

    // Source singular must emit reciprocal hreflang (W1-6).
    expect(srcSeo.hreflangs.length, 'source singular must emit head hreflang').toBeGreaterThan(0)
    const prefixedOnSource = srcSeo.hreflangs.filter((a) =>
      urlHasPrefix(a.href, pair!.pathPrefix),
    )
    expect(prefixedOnSource.length, 'source hreflang must include virtual prefixed URL').toBeGreaterThan(0)
    const srcLangs = srcSeo.hreflangs.map((a) => a.lang.toLowerCase())
    expect(srcLangs.some((l) => l === 'x-default'), 'source hreflang must include x-default').toBeTruthy()
  })
})
