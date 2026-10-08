import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { expectSchema } from '../lib/expect-api'

const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'

const runtimeDir = path.resolve(__dirname, '../../runtime')
const runtimeFile = path.join(runtimeDir, 'plugin-translate-editor-playwright.json')

type Relation = {
  id?: number
  relation_id?: number
  source_site_id?: number
  target_site_id?: number
  status?: string
  [key: string]: unknown
}

type WpPost = {
  id: number
  title?: { rendered?: string }
  type?: string
  status?: string
}

type FetchResult = { status: number; body: any }

function hasFatalSignals(html: string): string | null {
  const markers = [
    'There has been a critical error on this website',
    'WordPress database error',
    'Fatal error:',
    'Parse error:',
    'Uncaught Error',
  ]
  for (const marker of markers) {
    if (html.includes(marker)) return marker
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

/**
 * Run `fetch()` inside the page context (browser cookies + nonce header).
 * This works around Playwright's APIRequestContext not sharing WP cookie auth
 * without us having to extract/forward the nonce.
 */
async function browserFetch(page: any, url: string, init: any = {}): Promise<FetchResult> {
  return await page.evaluate(
    async ({ u, opts }: { u: string; opts: any }) => {
      const res = await fetch(u, opts)
      const text = await res.text()
      let body: any = text
      try { body = JSON.parse(text) } catch { /* keep text */ }
      return { status: res.status, body }
    },
    { u: url, opts: init }
  )
}

async function getRestNonce(page: any): Promise<string> {
  // WP injects window.wpApiSettings.nonce on every wp-admin page that loads
  // wp-api. We hit the admin index to grab it without depending on a specific
  // plugin page rendering speed.
  await page.goto(`${WP_BASE}/wp-admin/`, { waitUntil: 'domcontentloaded' })
  const nonce = await page.evaluate(() => {
    const w: any = window
    return w?.wpApiSettings?.nonce || ''
  })
  return String(nonce || '')
}

async function callEditorData(page: any, sourcePostId: number | string, relationId: number | string, nonce: string): Promise<FetchResult> {
  const url = `${WP_BASE}/wp-json/wptsall/v2/manual-translations/editor-data?source_post_id=${encodeURIComponent(String(sourcePostId))}&relation_id=${encodeURIComponent(String(relationId))}`
  return browserFetch(page, url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' })
}

async function listActiveRelations(page: any, nonce: string): Promise<Relation[]> {
  const url = `${WP_BASE}/wp-json/wptsall/v2/site-relations?status=active&per_page=10`
  const r = await browserFetch(page, url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' })
  if (r.status !== 200) return []
  return Array.isArray(r.body) ? (r.body as Relation[]) : []
}

async function listPublishedPost(page: any, nonce: string): Promise<WpPost | null> {
  for (const type of ['posts', 'pages']) {
    const url = `${WP_BASE}/wp-json/wp/v2/${type}?per_page=1&status=publish&orderby=date&order=desc`
    const r = await browserFetch(page, url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' })
    if (r.status !== 200) continue
    if (Array.isArray(r.body) && r.body.length > 0) return r.body[0] as WpPost
  }
  return null
}

test.describe.configure({ mode: 'serial' })

test('Editor page UI: invalid params render admin chrome without fatal', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  expect(await hasWordPressLoginCookie(page)).toBeTruthy()

  const url = `${WP_BASE}/wp-admin/admin.php?page=wptsall-translate&source_post_id=99999999&relation_id=99999999`
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded' })
  expect(resp?.status() ?? 0).toBeLessThan(500)

  const html = await page.content()
  expect(hasFatalSignals(html)).toBeNull()

  const hasAdminUi =
    (await page.locator('#wpadminbar').count()) > 0 &&
    (await page.locator('#adminmenu, #adminmenuwrap').count()) > 0
  expect(hasAdminUi).toBeTruthy()

  // Editor script localized `wptsallTranslationEditor` should be present
  // even when source/relation ids are bad (the script enqueues regardless).
  const hasEditorObj = await page.evaluate(() => !!(window as any).wptsallTranslationEditor)
  expect(hasEditorObj).toBeTruthy()
})

test('REST editor-data: rejects bad parameters with proper errors', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  const nonce = await getRestNonce(page)
  expect(nonce.length).toBeGreaterThan(0)

  // 1. Non-existent relation_id → 404 (permission guard uses rest_object_not_found)
  const r1 = await callEditorData(page, 1, 99999999, nonce)
  expect(r1.status).toBe(404)
  expectSchema(r1.body, 'wp-rest-error', 'editor-data relation missing')
  expect(String(r1.body?.code ?? '')).toBe('rest_object_not_found')

  // 2. Non-existent source_post_id with valid relation → 404 rest_object_not_found
  //    (existence checked in permission callback before service codes).
  const relationsForTest = await listActiveRelations(page, nonce)
  if (relationsForTest.length > 0) {
    const goodRid = (relationsForTest[0].id ?? relationsForTest[0].relation_id) as number
    const r2 = await callEditorData(page, 99999999, goodRid, nonce)
    expect(r2.status).toBe(404)
    expectSchema(r2.body, 'wp-rest-error', 'editor-data source missing')
    expect(String(r2.body?.code ?? '')).toBe('rest_object_not_found')
  } else {
    const r2 = await callEditorData(page, 99999999, 99999998, nonce)
    expect(r2.status).toBe(404)
    expectSchema(r2.body, 'wp-rest-error', 'editor-data missing fixtures')
    expect(String(r2.body?.code ?? '')).toBe('rest_object_not_found')
  }

  // 3. Missing required args → 400 (REST arg validation)
  const r3 = await browserFetch(page, `${WP_BASE}/wp-json/wptsall/v2/manual-translations/editor-data`, {
    headers: { 'X-WP-Nonce': nonce },
    credentials: 'include',
  })
  expect(r3.status).toBe(400)
  expectSchema(r3.body, 'wp-rest-error', 'editor-data missing args')
})

test('REST editor-data: happy path returns source/target/field_config/relation', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  const nonce = await getRestNonce(page)
  expect(nonce.length).toBeGreaterThan(0)

  // Diagnostic: probe posts URL right before listPublishedPost to check what fails
  const exactUrl = `${WP_BASE}/wp-json/wp/v2/posts?per_page=1&status=publish&orderby=date&order=desc`
  const postProbe = await browserFetch(page, exactUrl, {
    headers: { 'X-WP-Nonce': nonce }, credentials: 'include',
  })
  // Also try without orderby
  const postProbeNoOB = await browserFetch(page, `${WP_BASE}/wp-json/wp/v2/posts?per_page=1&status=publish`, {
    headers: { 'X-WP-Nonce': nonce }, credentials: 'include',
  })

  // Probe 2: use the same pattern as listPublishedPost (function-style)
  const postProbe2 = await listPublishedPost(page, nonce)

  const relations = await listActiveRelations(page, nonce)
  test.skip(relations.length === 0, 'no active site_relation fixture; skipping happy path')
  const relation = relations[0]

  const post = await listPublishedPost(page, nonce)
  if (!post) {
    test.skip(true, 'no published post fixture; skipping happy path')
    return
  }

  const relationId = (relation.id ?? relation.relation_id) as number
  const res = await callEditorData(page, post.id, relationId, nonce)
  expect(res.status).toBe(200)
  expectSchema(res.body, 'wp-editor-data.response', 'editor-data happy path')

  const body = res.body as Record<string, any>
  expect(body).toBeTruthy()
  expect(body.source).toBeTruthy()
  expect(body.source.ID).toBe(post.id)
  expect(String(body.source.post_title ?? '').length).toBeGreaterThan(0)
  expect(String(body.source.post_type ?? '').length).toBeGreaterThan(0)

  expect(body.field_config).toBeTruthy()
  expect(typeof body.field_config).toBe('object')
  // Field config has both `translate_fields` (canonical translatable list) and
  // `field_capabilities` (per-field type/direction). Assert both shapes.
  const tf = body.field_config.translate_fields
  expect(Array.isArray(tf)).toBeTruthy()
  if (Array.isArray(tf) && tf.length > 0) {
    expect(typeof tf[0]).toBe('string')
  }
  expect(typeof body.field_config.field_capabilities).toBe('object')

  expect(body.relation).toBeTruthy()
  const rid = (body.relation.id ?? body.relation.relation_id) as number
  expect(rid).toBe(relationId)

  expect(typeof body.target).toBe('object')
})

test('Editor page UI: happy path renders with real ids, no fatal', async ({ page }) => {
  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  const nonce = await getRestNonce(page)
  expect(nonce.length).toBeGreaterThan(0)

  const relations = await listActiveRelations(page, nonce)
  const post = await listPublishedPost(page, nonce)
  test.skip(relations.length === 0, 'no active site_relation fixture; skipping happy UI')
  test.skip(!post, 'no published post fixture; skipping happy UI')

  const relationId = (relations[0].id ?? relations[0].relation_id) as number
  const url = `${WP_BASE}/wp-admin/admin.php?page=wptsall-translate&source_post_id=${post!.id}&relation_id=${relationId}`
  const resp = await page.goto(url, { waitUntil: 'domcontentloaded' })
  expect(resp?.status() ?? 0).toBeLessThan(500)

  const html = await page.content()
  expect(hasFatalSignals(html)).toBeNull()

  const title = String((post!.title?.rendered ?? '')).replace(/<[^>]+>/g, '').trim()
  if (title.length >= 4) {
    const bodyText = await page.locator('body').innerText()
    expect(bodyText).toContain(title.slice(0, 12))
  }

  // Editor object should be present and have the ids we passed.
  // The page localizes ids as numbers (from absint), but our post.id from
  // REST is also numeric; relation.id from site-relations is a string ("712"),
  // so we compare via String() to be safe.
  const ids = await page.evaluate(() => {
    const w: any = window
    return {
      sourcePostId: w?.wptsallTranslationEditor?.sourcePostId ?? null,
      relationId: w?.wptsallTranslationEditor?.relationId ?? null,
    }
  })
  expect(String(ids.sourcePostId)).toBe(String(post!.id))
  expect(String(ids.relationId)).toBe(String(relationId))
})

test.afterAll(async () => {
  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  const payload = fs.existsSync(runtimeFile)
    ? JSON.parse(fs.readFileSync(runtimeFile, 'utf-8'))
    : {}
  payload.timestamp = new Date().toISOString()
  payload.wpBase = WP_BASE
  fs.writeFileSync(runtimeFile, JSON.stringify(payload, null, 2))
})
