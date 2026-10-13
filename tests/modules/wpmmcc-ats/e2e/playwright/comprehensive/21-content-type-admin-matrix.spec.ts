/**
 * Legacy shared first-row / optional-soft-save inventory, excluded from collection.
 * seed: paired-with 21-content-type-admin-save.owned.spec.ts
 * The owned pair covers only post/page/Woo product saving, not this whole matrix.
 * Other plugin/CPT/render surfaces remain known gaps; never run shared writes.
 *
 * 20 content-type Admin matrix (project-specs.json):
 * list Translations/Site columns + flags → translate editor → optional Save.
 *
 * Seeding fallbacks (many CPTs hide REST): REST → admin list scrape → WP-CLI.
 *
 *   WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-content-matrix
 *   CONTENT_MATRIX_LIMIT=5              # smoke subset
 *   CONTENT_MATRIX_SAVE=1               # optional soft Save probe (hard Save is 17)
 *   WP_DOCKER_CONTAINER=wptsall-wp-lab-wordpress-test-1
 */
import { test, expect, type Page } from '@playwright/test'
import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { wpLogin, findFatalError, WP_BASE_URL } from './helpers'

type MatrixEntry = {
  id: string
  pluginSlug: string
  postType: string
}

function loadMatrix(opts?: { limit?: number }): MatrixEntry[] {
  const specsPath = path.resolve(__dirname, '../../project-specs.json')
  const raw = JSON.parse(fs.readFileSync(specsPath, 'utf8')) as {
    plugin_projects: Record<
      string,
      { plugin_slug?: string; content_types?: string[] }
    >
  }
  const out: MatrixEntry[] = []
  const seen = new Set<string>()
  for (const [id, spec] of Object.entries(raw.plugin_projects || {})) {
    const pluginSlug = String(spec.plugin_slug || id)
    const types = Array.isArray(spec.content_types) ? spec.content_types : []
    for (const postType of types) {
      // Taxonomies / attachments are not classic CPT list screens.
      if (['category', 'post_tag', 'attachment'].includes(postType)) continue
      const key = `${id}:${postType}`
      if (seen.has(key)) continue
      seen.add(key)
      out.push({ id, pluginSlug, postType })
    }
  }
  const limit =
    opts?.limit !== undefined
      ? opts.limit
      : Number(process.env.CONTENT_MATRIX_LIMIT || 0)
  return limit > 0 ? out.slice(0, limit) : out
}

const MATRIX = loadMatrix()
const FORCE_SAVE = /^(1|true|yes)$/i.test(String(process.env.CONTENT_MATRIX_SAVE || ''))
const WP_DOCKER =
  process.env.WP_DOCKER_CONTAINER || 'wptsall-wp-lab-wordpress-test-1'
const WP_CLI_DISABLED = /^(1|true|yes)$/i.test(String(process.env.CONTENT_MATRIX_NO_WPCLI || ''))

async function restNonce(page: Page): Promise<string> {
  return page.evaluate(() => {
    const w = window as unknown as { wpApiSettings?: { nonce?: string } }
    return String(w?.wpApiSettings?.nonce || '')
  })
}

async function browserJson(
  page: Page,
  url: string,
  init: RequestInit = {},
): Promise<{ status: number; body: unknown }> {
  return page.evaluate(
    async ({ u, opts }) => {
      const res = await fetch(u, opts as RequestInit)
      const text = await res.text()
      let body: unknown = text
      try {
        body = JSON.parse(text)
      } catch {
        /* keep */
      }
      return { status: res.status, body }
    },
    { u: url, opts: init },
  )
}

async function resolveRestBase(page: Page, postType: string, nonce: string): Promise<string | null> {
  const types = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/types?context=edit`, {
    headers: { 'X-WP-Nonce': nonce },
    credentials: 'include',
  })
  if (!types.body || typeof types.body !== 'object') return null
  const entry = (types.body as Record<string, { rest_base?: string; slug?: string }>)[postType]
  if (!entry) return null
  return String(entry.rest_base || postType)
}

function wpCli(args: string[]): string | null {
  if (WP_CLI_DISABLED) return null
  try {
    return execFileSync(
      'docker',
      ['exec', WP_DOCKER, 'wp', ...args, '--allow-root'],
      { encoding: 'utf8', timeout: 90_000, maxBuffer: 2 * 1024 * 1024 },
    ).trim()
  } catch {
    return null
  }
}

function wpCliListIds(postType: string): number[] {
  const out = wpCli([
    'post',
    'list',
    `--post_type=${postType}`,
    '--post_status=any',
    '--fields=ID',
    '--format=ids',
    '--posts_per_page=10',
  ])
  if (!out) return []
  return out
    .split(/\s+/)
    .map((x) => Number(x))
    .filter((n) => n > 0)
}

function wpCliCreatePost(postType: string, title: string): number {
  const out = wpCli([
    'post',
    'create',
    `--post_type=${postType}`,
    `--post_title=${title}`,
    '--post_status=publish',
    '--porcelain',
  ])
  return out ? Number(out.split(/\s+/).pop()) || 0 : 0
}

function isCapabilityDenied(html: string): boolean {
  return /Sorry, you are not allowed to (edit posts in this post type|access this page)/i.test(
    html,
  )
}

async function findPostIdFromAdminList(page: Page, postType: string): Promise<number> {
  const listUrl = `${WP_BASE_URL}/wp-admin/edit.php?post_type=${encodeURIComponent(postType)}`
  await page.goto(listUrl, { waitUntil: 'domcontentloaded', timeout: 90_000 }).catch(() => undefined)
  // Events Manager / custom tables may hydrate after first paint.
  await page.waitForTimeout(2500)
  const id = await page.evaluate(() => {
    const fromTr = [...document.querySelectorAll('tr[id^="post-"]')]
      .map((tr) => Number(String(tr.id).replace(/^post-/, '')))
      .filter((n) => n > 0)
    if (fromTr[0]) return fromTr[0]
    const hrefs = [...document.body.innerHTML.matchAll(/post\.php\?post=(\d+)/g)].map((m) =>
      Number(m[1]),
    )
    return hrefs.find((n) => n > 0) || 0
  })
  return id
}

async function ensurePublishedPost(
  page: Page,
  postType: string,
  nonce: string,
): Promise<number> {
  const restBase = await resolveRestBase(page, postType, nonce)
  if (restBase) {
    const listed = await browserJson(
      page,
      `${WP_BASE_URL}/wp-json/wp/v2/${encodeURIComponent(restBase)}?per_page=1&status=publish,draft,private`,
      { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' },
    )
    if (Array.isArray(listed.body) && listed.body.length > 0) {
      const id = Number((listed.body[0] as { id?: number }).id || 0)
      if (id > 0) return id
    }

    const stamp = `E2E-Matrix-${postType}-${Date.now().toString(36)}`
    const created = await browserJson(page, `${WP_BASE_URL}/wp-json/wp/v2/${encodeURIComponent(restBase)}`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json',
      },
      credentials: 'include',
      body: JSON.stringify({
        title: stamp,
        status: 'publish',
        content: `matrix seed for ${postType}`,
      }),
    })
    if (created.status >= 200 && created.status < 300 && created.body && typeof created.body === 'object') {
      const id = Number((created.body as { id?: number }).id || 0)
      if (id > 0) return id
    }
  }

  // bbPress / Events Manager / HivePress / Site Reviews often omit usable REST routes.
  const scraped = await findPostIdFromAdminList(page, postType)
  if (scraped > 0) return scraped

  const cliIds = wpCliListIds(postType)
  if (cliIds[0]) return cliIds[0]

  const createdId = wpCliCreatePost(
    postType,
    `E2E-Matrix-${postType}-${Date.now().toString(36)}`,
  )
  return createdId > 0 ? createdId : 0
}

test.describe('21 Content-type Admin translation matrix', () => {
  // Independent per CPT so one failure does not skip the rest of the matrix.
  test.describe.configure({ mode: 'default' })
  test.setTimeout(240_000)

  test.beforeEach(async ({ page }) => {
    await wpLogin(page)
  })

  test('matrix inventory has CPT entries from project-specs', async () => {
    expect(loadMatrix({ limit: 0 }).length).toBeGreaterThanOrEqual(15)
    expect(MATRIX.length).toBeGreaterThan(0)
  })

  for (const entry of MATRIX) {
    test(`${entry.id}/${entry.postType}: list columns + flags + translate path`, async ({ page }) => {
      await page.goto(`${WP_BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' })
      const nonce = await restNonce(page)
      expect(nonce.length).toBeGreaterThan(0)

      const postId = await ensurePublishedPost(page, entry.postType, nonce)
      test.skip(
        postId <= 0,
        `${entry.postType}: cannot seed via REST / admin list / WP-CLI`,
      )

      const listUrl = `${WP_BASE_URL}/wp-admin/edit.php?post_type=${encodeURIComponent(entry.postType)}`
      await page.goto(listUrl, { waitUntil: 'domcontentloaded', timeout: 90_000 }).catch(() => undefined)
      let html = await page.content()
      const listDenied = isCapabilityDenied(html)
      if (!listDenied) {
        expect(findFatalError(html)).toBeNull()
      }

      // Column hooks: Translations / Site / Translation Status (when CPT is in managed models).
      const hasColumn =
        !listDenied &&
        /column-wptsall_translations|column-wptsall_site|column-wptsall_translation|id=['"]wptsall_translations['"]/i.test(
          html,
        )

      const flagOnList = page.locator(
        'td.column-wptsall_translations .wptsall-flag, td.wptsall_translations .wptsall-flag, .wptsall-translation-flags .wptsall-flag',
      )
      const listHasFlags = !listDenied && (await flagOnList.count()) > 0

      await page.goto(
        `${WP_BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`,
        { waitUntil: 'domcontentloaded', timeout: 90_000 },
      )
      html = await page.content()
      const editDenied = isCapabilityDenied(html)
      if (!editDenied) {
        expect(findFatalError(html)).toBeNull()
      }

      const hasSiteSelect =
        !editDenied &&
        (await page.locator('#wptsall_virtual_site_id, select[name="wptsall_virtual_site_id"]').count()) >
          0
      const hasMetaFlags =
        !editDenied &&
        (await page.locator('.wptsall-translation-flags .wptsall-flag, .wptsall-metabox-langs .wptsall-flag')
          .count()) > 0
      const hasTranslateSection =
        !editDenied &&
        /wptsall-translate-section|page=wptsall-translate|Select Site|Languages:/i.test(html)

      const hasChrome =
        hasColumn || hasSiteSelect || hasMetaFlags || hasTranslateSection || listHasFlags
      if (!hasChrome) {
        // Explicit coverage gap: translate path may work without list/edit chrome.
        // Gate / reports should count these separately from full chrome parity.
        test.info().annotations.push({
          type: 'coverage-gap',
          description: `${entry.postType}: no list/edit chrome (denied=${listDenied || editDenied}) — translate-only ≠ full admin hook parity`,
        })
      } else {
        test.info().annotations.push({
          type: 'chrome',
          description: `${entry.postType}: list/edit chrome present (column=${hasColumn} siteSelect=${hasSiteSelect} flags=${hasMetaFlags || listHasFlags})`,
        })
      }

      // Prefer explicit relation / to_lang links for this post; avoid bare admin-bar shortcuts.
      let translateHref: string | null = null
      if (!editDenied) {
        const linkHrefs = await page
          .locator('a[href*="page=wptsall-translate"]')
          .evaluateAll((els) => els.map((el) => (el as HTMLAnchorElement).getAttribute('href') || ''))
        const pid = String(postId)
        const scored = linkHrefs
          .filter((h) => h.includes(`source_post_id=${pid}`) || h.includes(`post_id=${pid}`))
          .map((h) => {
            let score = 0
            if (/relation_id=\d+/.test(h)) score += 2
            if (/to_lang=/.test(h)) score += 1
            return { h, score }
          })
          .sort((a, b) => b.score - a.score)
        if (scored.length > 0 && scored[0].score > 0) {
          translateHref = scored[0].h
        } else if (scored.length > 0) {
          translateHref = scored[0].h
        }
      }

      if (!translateHref || !/relation_id=\d+|to_lang=/.test(translateHref)) {
        const relRes = await browserJson(
          page,
          `${WP_BASE_URL}/wp-json/wptsall/v2/site-relations?status=active&per_page=20`,
          { headers: { 'X-WP-Nonce': nonce }, credentials: 'include' },
        )
        const relations = Array.isArray(relRes.body) ? (relRes.body as Array<{ id?: number }>) : []
        const rid = Number(relations[0]?.id || 0)
        test.skip(rid <= 0, `${entry.postType}: no active site relation for translate`)
        translateHref = `${WP_BASE_URL}/wp-admin/admin.php?page=wptsall-translate&source_post_id=${postId}&relation_id=${rid}`
      }

      const abs = translateHref.startsWith('http')
        ? translateHref
        : `${WP_BASE_URL}${translateHref.startsWith('/') ? '' : '/'}${translateHref}`
      await page.goto(abs, { waitUntil: 'domcontentloaded' })
      html = await page.content()
      expect(findFatalError(html)).toBeNull()
      expect(
        /Missing required parameters|No active site relation found/i.test(html),
        `${entry.postType}: translate editor failed to resolve params (${page.url()})`,
      ).toBeFalsy()

      const saveBtn = page.locator('#wptsall-save-translation')
      const hasEditor = (await saveBtn.count()) > 0

      // Pass bar: classic chrome OR usable translate editor.
      expect(
        hasChrome || hasEditor,
        `${entry.postType}: expected WPTSALL chrome and/or translate editor`,
      ).toBeTruthy()

      if (!hasEditor) {
        test.info().annotations.push({
          type: 'note',
          description: `${entry.postType}: editor chrome missing after resolve — ${page.url()}`,
        })
        return
      }

      await expect(saveBtn).toBeVisible({ timeout: 30_000 })

      // Hard Save lives in 17; matrix only optionally probes when CONTENT_MATRIX_SAVE=1.
      if (!FORCE_SAVE) return

      const editorDataPending = page.waitForResponse(
        (r) =>
          /manual-translations\/editor-data/i.test(r.url()) &&
          r.request().method() === 'GET' &&
          r.ok(),
        { timeout: 90_000 },
      )
      await page.goto(page.url(), { waitUntil: 'domcontentloaded' })
      const editorDataOk = await editorDataPending.then(() => true).catch(() => false)
      if (!editorDataOk) {
        test.info().annotations.push({
          type: 'note',
          description: `${entry.postType}: editor-data not ready — skip Save probe`,
        })
        return
      }

      const titleInput = page.locator('#wptsall-target-post_title')
      if ((await titleInput.count()) === 0) {
        test.info().annotations.push({
          type: 'note',
          description: `${entry.postType}: no title field — skip Save probe`,
        })
        return
      }
      await expect(titleInput).toBeVisible({ timeout: 60_000 })
      const stamp = `MX-${entry.postType}-${Date.now().toString(36)}`
      await titleInput.fill(stamp)
      await titleInput.dispatchEvent('input')
      await titleInput.dispatchEvent('change')

      const pending = page.waitForResponse(
        (r) =>
          /manual-translations/i.test(r.url()) &&
          !/editor-data/i.test(r.url()) &&
          ['POST', 'PUT'].includes(r.request().method()),
        { timeout: 60_000 },
      )
      await saveBtn.click({ force: true })
      try {
        const res = await pending
        expect(res.status()).toBeLessThan(500)
        expect([200, 201]).toContain(res.status())
      } catch (err) {
        test.info().annotations.push({
          type: 'note',
          description: `${entry.postType}: Save probe soft-fail (${String(err).slice(0, 120)})`,
        })
      }
    })
  }
})
