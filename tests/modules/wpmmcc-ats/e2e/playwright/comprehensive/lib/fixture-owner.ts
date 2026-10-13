/**
 * opus5 P-2 (doc 04 §4.3 / doc 05 Wave-2 P-2): fixture self-ownership helpers.
 *
 * The key comprehensive specs (17/18/19/20/23) used to probe whatever shared
 * lab state happened to exist and `test.skip()` when the fixture they wanted
 * was absent — fixtures were not self-owned, so coverage could shrink
 * silently behind a green run. These helpers let a spec OWN its fixtures:
 * create them through the same admin REST surface the product UI uses
 * (wpApiSettings nonce + logged-in browser session — the product surface,
 * not a test backdoor), tag everything with a per-run `p2fx-` prefix, and
 * delete it all in afterAll.
 *
 * Usage skeleton (specs are already `mode: 'serial'`):
 *
 *   test.beforeAll(async ({ browser }) => {
 *     const page = await browser.newPage()
 *     await wpLogin(page)
 *     ownedRelation = await seedOwnedRelation(page, { nameSuffix: '17-editor' })
 *     ownedPostId = await seedPost(page, { title: 'P2FX 17 source post' })
 *     await page.close()
 *   })
 *   test.afterAll(async ({ browser }) => {
 *     const page = await browser.newPage()
 *     await wpLogin(page)
 *     await cleanupOwned(page)
 *     await page.close()
 *   })
 */

import { expect, type Page } from '@playwright/test'
import { wpLogin, WP_BASE_URL } from '../helpers'

export interface AdminRestResult {
  status: number
  body: unknown
}

/** Admin REST call with the wpApiSettings nonce (product surface auth). */
export async function adminRest(
  page: Page,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  path: string,
  body?: unknown,
): Promise<AdminRestResult> {
  const nonce = await page.evaluate(() => {
    const w = window as unknown as {
      wpApiSettings?: { nonce?: string }
      wptsallSites?: { nonce?: string }
    }
    return String(w?.wpApiSettings?.nonce || w?.wptsallSites?.nonce || '')
  })
  expect(nonce, 'admin REST nonce (wpApiSettings) must be present after login').toBeTruthy()
  const res = await page.request.fetch(`${WP_BASE_URL}${path}`, {
    method,
    headers: {
      'X-WP-Nonce': nonce,
      ...(body != null ? { 'Content-Type': 'application/json' } : {}),
    },
    data: body != null ? JSON.stringify(body) : undefined,
    timeout: 60_000,
  })
  let parsed: unknown = null
  try {
    parsed = await res.json()
  } catch {
    parsed = await res.text().catch(() => null)
  }
  return { status: res.status(), body: parsed }
}

export interface OwnedRelation {
  relationId: number
  virtualSiteId: number
  pathPrefix: string
  targetLang: string
  /** Source language of the seeded relation (read from the Add Relation form). */
  sourceLang: string
}

interface OwnedFixtureState {
  relation?: OwnedRelation
  /** Standalone virtual sites (no relation stack) — spec 19's form target. */
  virtualSiteIds: number[]
  /** Self-seeded language codes (spec 18's TM form options). */
  languageCodes: string[]
  postIds: number[]
}

/** Per-worker registry so afterAll can clean exactly what this spec seeded. */
const owned: OwnedFixtureState = { virtualSiteIds: [], languageCodes: [], postIds: [] }

function runTag(): string {
  return `${Date.now().toString(36)}${Math.floor(Math.random() * 1e4).toString(36)}`
}

/**
 * Self-create the full relation stack: virtual-site target (unique
 * `p2fx-<tag>` path prefix so concurrent lanes never collide) + one site
 * relation onto it. `source_lang` and `template` are read from the Add
 * Relation form exactly like the real UI submit (spec 19's technique),
 * so whatever the lab's language matrix is, the seeded relation is valid.
 */
/** Create a `p2fx-<tag>` virtual site (shared by the seed helpers below). */
async function createVirtualSite(
  page: Page,
  opts: { lang: string; nameSuffix?: string },
): Promise<{ id: number; pathPrefix: string }> {
  const tag = runTag()
  const pathPrefix = `p2fx-${tag}`
  const vs = await adminRest(page, 'POST', '/wp-json/wptsall/v2/virtual-sites', {
    name: `P2FX ${opts.nameSuffix ?? 'fixture'} ${tag}`,
    path_prefix: pathPrefix,
    lang: opts.lang,
  })
  expect(vs.status, `virtual-site create must succeed (body=${JSON.stringify(vs.body)})`).toBe(200)
  const vsBody = vs.body as { success?: boolean; site_id?: number }
  expect(vsBody.success, 'virtual-site create response must be success').toBeTruthy()
  expect(vsBody.site_id, 'virtual-site create must return site_id').toBeTruthy()
  const id = Number(vsBody.site_id)
  expect(id, 'virtual-site create must return a numeric site_id').toBeGreaterThan(0)
  return { id, pathPrefix }
}

/**
 * Self-create a standalone virtual site (no relation stack) for specs that
 * need a guaranteed-available virtual-site target — e.g. spec 19 drives the
 * Add Relation UI form and must always find its own target in the select.
 */
export async function seedOwnedVirtualSite(
  page: Page,
  opts: { lang?: string; nameSuffix?: string } = {},
): Promise<{ id: number; pathPrefix: string }> {
  const vs = await createVirtualSite(page, {
    lang: opts.lang ?? 'en_US',
    nameSuffix: opts.nameSuffix,
  })
  owned.virtualSiteIds.push(vs.id)
  return vs
}

/**
 * Read the product's default language from the Settings admin page (the
 * same #default_language select the product renders).
 */
async function readDefaultLanguage(page: Page): Promise<string> {
  await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-settings`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  return page.evaluate(() => {
    const el = document.querySelector('#default_language') as HTMLSelectElement | null
    return el ? el.value : ''
  })
}

/**
 * Enumerate the lab's configured site languages from the Languages admin
 * list page (the languages table the product itself uses for relations,
 * string translation, and the language switcher) — a deterministic,
 * meaningful enumeration, unlike the WP.org translation catalog that
 * backs the raw form selects. Only the FIRST <code> cell per row is read
 * (the code column); the slug column also renders <code> and must not
 * leak into the enumeration (probed 2026-09-21: 'en-us' slug once
 * resolved as a "non-default" target and re-normalized back to en_US).
 */
async function readConfiguredLanguages(page: Page): Promise<string[]> {
  await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-languages`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })
  return page.evaluate(() => {
    const codes: string[] = []
    for (const tr of Array.from(document.querySelectorAll('table tr'))) {
      const el = tr.querySelector('td code')
      const code = (el?.textContent ?? '').trim()
      if (code && !codes.includes(code)) {
        codes.push(code)
      }
    }
    return codes
  })
}

/**
 * Self-create the full relation stack: virtual-site target (unique
 * `p2fx-<tag>` path prefix so concurrent lanes never collide) + one site
 * relation onto it. `source_lang` and `template` are read from the Add
 * Relation form exactly like the real UI submit (spec 19's technique),
 * so whatever the lab's language matrix is, the seeded relation is valid.
 *
 * Target lang resolution: the product OMITS the path prefix in generated
 * links for the default-language virtual site (Url_Converter::
 * should_omit_prefix_for_vs — WPML/Polylang "hide default language
 * directory" semantics), so specs asserting prefixed links need a
 * NON-default target. By default the seed resolves `targetLang` from the
 * form's language options against the Settings page's default language.
 * Pass `requireNonDefaultTarget: true` to hard-fail when the environment
 * offers no non-default language (the fixture cannot be self-owned then).
 */
export async function seedOwnedRelation(
  page: Page,
  opts: { targetLang?: string; requireNonDefaultTarget?: boolean; nameSuffix?: string } = {},
): Promise<OwnedRelation> {
  await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-sites&tab=add`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  })

  // Read the form's first valid source_lang / template option (the exact
  // values the UI wizard would submit for this lab's language matrix).
  const defaults = await page.evaluate(() => {
    const firstValue = (sel: string): string => {
      const el = document.querySelector(sel) as HTMLSelectElement | null
      if (!el) return ''
      const opt = Array.from(el.options).find((o) => o.value)
      return opt ? opt.value : ''
    }
    const allValues = (sel: string): string[] => {
      const el = document.querySelector(sel) as HTMLSelectElement | null
      if (!el) return []
      return Array.from(el.options).map((o) => o.value).filter(Boolean)
    }
    return {
      sourceLang: firstValue('#source_lang'),
      template: firstValue('#template'),
      langOptions: allValues('#source_lang'),
    }
  })
  expect(defaults.sourceLang, 'Add Relation form must expose a source language').toBeTruthy()
  expect(defaults.template, 'Add Relation form must expose a template').toBeTruthy()

  // opus5 P-2: prefer a target that keeps its path prefix in links
  // (non-default) and differs from the source language.
  const defaultLang = await readDefaultLanguage(page)
  const langOptions = await readConfiguredLanguages(page)
  const targetLang =
    opts.targetLang ??
    langOptions.find((l) => l !== defaultLang && l !== defaults.sourceLang) ??
    langOptions.find((l) => l !== defaultLang) ??
    defaults.langOptions.find((l) => l !== defaultLang) ??
    defaults.sourceLang
  if (opts.requireNonDefaultTarget) {
    expect(
      targetLang !== defaultLang,
      `P-2 fixture: link-prefix specs need a non-default target language ` +
        `(default=${defaultLang}, source=${defaults.sourceLang}, configured=${langOptions.join(',')})`,
    ).toBeTruthy()
  }
  const { id: virtualSiteId, pathPrefix } = await createVirtualSite(page, {
    lang: targetLang,
    nameSuffix: opts.nameSuffix,
  })

  const rel = await adminRest(page, 'POST', '/wp-json/wptsall/v2/site-relations', {
    source_site_id: 1,
    source_lang: defaults.sourceLang,
    template: defaults.template,
    target_sites: [
      { id: String(virtualSiteId), type: 'virtual', lang: targetLang },
    ],
    media_handling: 'copy',
  })
  expect(rel.status, `relation create must succeed (body=${JSON.stringify(rel.body)})`).toBe(200)
  const relBody = rel.body as { success?: boolean; relation_ids?: number[] }
  expect(relBody.success, 'relation create response must be success').toBeTruthy()
  expect(relBody.relation_ids?.length, 'relation create must return relation_ids').toBeGreaterThan(0)
  const relationId = Number(relBody.relation_ids![0])

  owned.relation = { relationId, virtualSiteId, pathPrefix, targetLang, sourceLang: defaults.sourceLang }
  return owned.relation
}

/** Self-create a published post via core REST (own row, own cleanup). */
export async function seedPost(
  page: Page,
  opts: { title: string; content?: string },
): Promise<number> {
  const res = await adminRest(
    page,
    'POST',
    '/wp-json/wp/v2/posts',
    {
      title: opts.title,
      status: 'publish',
      content: opts.content ?? `<p>${opts.title} — p2fx self-owned fixture body.</p>`,
    },
  )
  expect(res.status, `post create must succeed (body=${JSON.stringify(res.body)})`).toBe(201)
  const id = Number((res.body as { id?: number }).id)
  expect(id, 'post create must return an id').toBeGreaterThan(0)
  owned.postIds.push(id)
  return id
}

/**
 * Submit a WP admin-post form (the same endpoint + `_wpnonce` the product's
 * UI forms use) with the page's logged-in session. Returns the followed
 * response's URL so callers can assert the product's redirect contract.
 */
export async function submitAdminPost(
  page: Page,
  fields: Record<string, string>,
): Promise<{ status: number; finalUrl: string }> {
  const res = await page.request.fetch(`${WP_BASE_URL}/wp-admin/admin-post.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    data: new URLSearchParams(fields).toString(),
    maxRedirects: 5,
    timeout: 60_000,
  })
  return { status: res.status(), finalUrl: res.url() }
}

/**
 * Self-create a language row through the Languages admin page's
 * admin-post handler (`wptsall_language_save`, nonce `wptsall_save_lang`)
 * — the product surface, not a DB backdoor. Codes are `p2fx_<tag>` so they
 * never collide with lab-configured languages (upsert is keyed by code).
 */
export async function seedOwnedLanguage(
  page: Page,
  opts: { nameSuffix?: string } = {},
): Promise<string> {
  await page.goto(
    `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-languages&action=add`,
    { waitUntil: 'domcontentloaded', timeout: 60_000 },
  )
  const nonce = await page
    .locator('form[action*="admin-post.php"] input[name="_wpnonce"]')
    .first()
    .inputValue()
  expect(nonce, 'Languages add form must expose its nonce').toBeTruthy()

  const tag = runTag()
  const code = `p2fx_${tag}`
  const r = await submitAdminPost(page, {
    _wpnonce: nonce,
    action: 'wptsall_language_save',
    id: '0',
    code,
    slug: `p2fx-${tag}`,
    name: `P2FX ${opts.nameSuffix ?? 'language'} ${tag}`,
    native_name: `P2FX ${tag}`,
    locale: code,
    direction: 'ltr',
    sort_order: '900',
    status: 'active',
  })
  expect(r.status, `language save must not 5xx (url=${r.finalUrl})`).toBeLessThan(500)
  expect(
    /page=wptsall-languages&updated=1/.test(r.finalUrl),
    `language save must land on updated=1 (got ${r.finalUrl})`,
  ).toBeTruthy()

  owned.languageCodes.push(code)
  return code
}

/** Best-effort afterAll cleanup: relations → virtual sites → languages → posts. */
export async function cleanupOwned(page: Page): Promise<void> {
  if (owned.relation) {
    const r = await adminRest(
      page,
      'DELETE',
      `/wp-json/wptsall/v2/site-relations/${owned.relation.relationId}`,
    )
    if (r.status !== 200) {
      console.warn(`[fixture-owner] relation ${owned.relation.relationId} delete -> ${r.status}`)
    }
    const v = await adminRest(
      page,
      'DELETE',
      `/wp-json/wptsall/v2/virtual-sites/${owned.relation.virtualSiteId}`,
    )
    if (v.status !== 200) {
      console.warn(`[fixture-owner] virtual-site ${owned.relation.virtualSiteId} delete -> ${v.status}`)
    }
    owned.relation = undefined
  }
  for (const vsId of owned.virtualSiteIds) {
    const v = await adminRest(page, 'DELETE', `/wp-json/wptsall/v2/virtual-sites/${vsId}`)
    if (v.status !== 200) {
      console.warn(`[fixture-owner] virtual-site ${vsId} delete -> ${v.status}`)
    }
  }
  owned.virtualSiteIds = []
  for (const code of owned.languageCodes) {
    try {
      await page.goto(`${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-languages`, {
        waitUntil: 'domcontentloaded',
        timeout: 60_000,
      })
      const row = page.locator('table tr', { has: page.locator(`code:has-text("${code}")`) }).first()
      if (!(await row.count())) {
        console.warn(`[fixture-owner] language ${code} not found on list page (already gone?)`)
        continue
      }
      const delForm = row.locator(
        'form:has(input[name="action"][value="wptsall_language_delete"])',
      )
      if (!(await delForm.count())) {
        console.warn(`[fixture-owner] language ${code} has no delete form (default?) — leaving it`)
        continue
      }
      const delNonce = await delForm.locator('input[name="_wpnonce"]').first().inputValue()
      const delId = await delForm.locator('input[name="id"]').first().inputValue()
      const r = await submitAdminPost(page, {
        _wpnonce: delNonce,
        action: 'wptsall_language_delete',
        id: delId,
      })
      if (!/page=wptsall-languages&deleted=1/.test(r.finalUrl)) {
        console.warn(`[fixture-owner] language ${code} delete landed on ${r.finalUrl}`)
      }
    } catch (e) {
      console.warn(`[fixture-owner] language ${code} cleanup failed: ${String(e).slice(0, 160)}`)
    }
  }
  owned.languageCodes = []
  for (const postId of owned.postIds) {
    const p = await adminRest(page, 'DELETE', `/wp-json/wp/v2/posts/${postId}?force=true`)
    if (p.status !== 200) {
      console.warn(`[fixture-owner] post ${postId} delete -> ${p.status}`)
    }
  }
  owned.postIds = []
}

export { wpLogin }
