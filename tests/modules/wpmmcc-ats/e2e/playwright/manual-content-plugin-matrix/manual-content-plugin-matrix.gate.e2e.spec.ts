import { test, expect, type Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { wpLogin } from '../plugin-content-journeys/helpers'

type AdminPage = {
  slug: string
  label: string
  url: string
}

type FrontendUrl = {
  id: string
  kind: 'target' | 'bridge'
  url: string
  required: boolean
  expect_title?: string
  expect_content_marker?: string
  expect_hreflang?: string
}

type ProjectTarget = {
  project: string
  plugin_slug: string
  ok: boolean
  post_type: string
  post_type_routable: boolean
  source_post_id: number
  target_post_id: number
  source_title: string
  target_title: string
  frontend_urls: FrontendUrl[]
  admin: {
    native_admin_url: string
    native_admin_path: string
    post_edit_required?: boolean
    source_edit_url: string
    target_edit_url: string
    manual_editor_url: string
  }
}

type Payload = {
  ok: boolean
  mode: string
  wp_base: string
  prefix: string
  relation_id: number
  target_lang: string
  manual_admin_pages: AdminPage[]
  projects: ProjectTarget[]
}

type BrowserResult = {
  id: string
  project?: string
  surface: 'wptsall-admin' | 'plugin-admin' | 'post-edit' | 'manual-editor' | 'frontend'
  url: string
  required: boolean
  pass: boolean
  status?: number
  note: string
}

const runtimeDir = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(__dirname, '../../runtime')
const targetsFile = path.join(runtimeDir, 'manual-content-plugin-matrix-targets.json')
const reportFile = path.join(runtimeDir, 'manual-content-plugin-matrix-playwright-report.json')

// Parallel mode (PW_WORKERS > 1): each worker process owns a private
// `results` array, so a single shared report file would be overwritten by
// whichever worker finishes last. Each worker therefore writes its own
// fragment `<report>.w<workerIndex>`; the shell wrapper merges the
// fragments back into `<report>` via merge-playwright-worker-reports.sh.
// Single-worker mode (default) keeps the legacy single-file behaviour.
const parallelWorkers = (() => {
  const raw = process.env.PW_WORKERS
  if (!raw) return 1
  const parsed = Number.parseInt(raw, 10)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 1
})()

function reportFileForWorker(workerIndex: number): string {
  return parallelWorkers > 1 ? `${reportFile}.w${workerIndex}` : reportFile
}

const FATAL_MARKERS = [
  'There has been a critical error on this website',
  'WordPress database error',
  'Fatal error:',
  'Parse error:',
  'Uncaught Error',
  'Call to undefined function',
  'Cannot redeclare',
]

function loadPayload(): Payload {
  expect(fs.existsSync(targetsFile), `missing ${targetsFile} — run PHP manual matrix prep first`).toBeTruthy()
  return JSON.parse(fs.readFileSync(targetsFile, 'utf-8')) as Payload
}

function findFatal(html: string): string | null {
  for (const marker of FATAL_MARKERS) {
    if (html.includes(marker)) return marker
  }
  return null
}

function is404Like(html: string): boolean {
  const low = html.toLowerCase()
  return (
    low.includes('error404') ||
    low.includes('404 not found') ||
    low.includes('page not found') ||
    low.includes('抱歉，找不到页面')
  )
}

// 90s: the Lab runs WP with debug mode, so script concatenation is disabled
// and admin pages fire hundreds of individual script requests; page load can
// legitimately exceed 45s on plugin-heavy posts. The test budget is 180s.
async function gotoAndInspect(page: Page, url: string, timeout = 90_000) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout })
  await page.waitForSelector('#wpbody-content, #wpcontent', { timeout: 20_000 }).catch(() => {})
  await page.waitForLoadState('load', { timeout: 30_000 }).catch(() => {})
  // Lab WP runs with debug mode (no script concat); domcontentloaded fires before
  // admin chrome text is populated. Poll briefly so body-length gates are stable.
  let bodyText = ''
  const bodyDeadline = Date.now() + 20_000
  while (Date.now() < bodyDeadline) {
    bodyText = await page.locator('body').innerText().catch(() => '')
    if (bodyText.length > 300) {
      break
    }
    await page.waitForTimeout(400)
  }
  const status = response?.status() ?? 0
  const html = await page.content()
  if (!bodyText) {
    bodyText = await page.locator('body').innerText().catch(() => '')
  }
  return { status, html, bodyText }
}

function record(
  results: BrowserResult[],
  row: Omit<BrowserResult, 'pass' | 'note'>,
  pass: boolean,
  note: string,
) {
  results.push({ ...row, pass, note })
}

// Serial mode is scoped per project below: the three tests of one project
// keep their legacy in-order execution, while different projects may run on
// different workers (all tests are read-only page renders). The single
// WPTSALL-admin test has no project affinity and runs on any worker.
test.describe('Manual content plugin matrix — WP plugin only, no client, no auto translation', () => {
  const payload = loadPayload()
  const results: BrowserResult[] = []

  // P0-EV-01 §3.7: browser network requests must never target the Client,
  // mock provider, or control-plane infrastructure during manual gates.
  const FORBIDDEN_PORTS = new Set(['8977', '9090', '8787'])
  const LOOPBACK_HOSTS = new Set(['127.0.0.1', 'localhost', '::1'])
  const networkForbidden: { url: string; resourceType?: string; failure?: string | null }[] = []
  let networkTotal = 0

  function isForbiddenTarget(url: string): boolean {
    try {
      const u = new URL(url)
      if (u.protocol !== 'http:' && u.protocol !== 'https:') return false
      const port = u.port || (u.protocol === 'https:' ? '443' : '80')
      return LOOPBACK_HOSTS.has(u.hostname) && FORBIDDEN_PORTS.has(port)
    } catch {
      return false
    }
  }

  test.beforeEach(async ({ page }) => {
    page.on('request', (request) => {
      networkTotal += 1
      if (isForbiddenTarget(request.url())) {
        networkForbidden.push({ url: request.url(), resourceType: request.resourceType(), failure: null })
      }
    })
    page.on('requestfailed', (request) => {
      if (isForbiddenTarget(request.url())) {
        networkForbidden.push({
          url: request.url(),
          resourceType: request.resourceType(),
          failure: request.failure()?.errorText ?? null,
        })
      }
    })
  })

  test.afterAll(async () => {
    fs.mkdirSync(runtimeDir, { recursive: true })
    fs.writeFileSync(
      reportFileForWorker(test.info().workerIndex),
      JSON.stringify(
        {
          timestamp: new Date().toISOString(),
          targetsFile,
          mode: payload.mode,
          results,
          network: {
            total_requests: networkTotal,
            forbidden_requests: networkForbidden,
          },
        },
        null,
        2,
      ),
    )
  })

  test('WPTSALL manual-related admin pages render', async ({ page }) => {
    await wpLogin(page)
    expect(payload.manual_admin_pages.length, 'no manual admin pages declared').toBeGreaterThan(5)

    for (const adminPage of payload.manual_admin_pages) {
      const detail = await gotoAndInspect(page, adminPage.url)
      const fatal = findFatal(detail.html)
      const pass =
        detail.status >= 200 &&
        detail.status < 400 &&
        !fatal &&
        !detail.html.includes('id="loginform"') &&
        (detail.html.includes('wpcontent') || detail.html.includes('WPTSALL') || detail.html.includes('wptsall')) &&
        detail.bodyText.length > 300
      record(
        results,
        {
          id: adminPage.slug,
          surface: 'wptsall-admin',
          url: adminPage.url,
          required: true,
          status: detail.status,
        },
        pass,
        fatal || `body=${detail.bodyText.length}`,
      )
      expect(pass, `${adminPage.slug} (${adminPage.label}) failed: status=${detail.status} fatal=${fatal}`).toBeTruthy()
    }
  })

  for (const project of payload.projects) {
    test.describe(`${project.project}`, () => {
      test.describe.configure({ mode: 'serial' })
      test(`${project.project}: native content plugin admin/list screen renders`, async ({ page }) => {
        await wpLogin(page)
        expect(project.ok, `${project.project} PHP prep assertions failed`).toBeTruthy()
        expect(project.admin.native_admin_url, `${project.project} missing native admin URL`).toBeTruthy()

        const detail = await gotoAndInspect(page, project.admin.native_admin_url)
        const fatal = findFatal(detail.html)
        const pass =
          detail.status >= 200 &&
          detail.status < 400 &&
          !fatal &&
          !detail.html.includes('id="loginform"') &&
          (detail.html.includes('wpcontent') || detail.html.includes('wp-list-table') || detail.bodyText.length > 800)
        record(
          results,
          {
            id: `${project.project}:native-admin`,
            project: project.project,
            surface: 'plugin-admin',
            url: project.admin.native_admin_url,
            required: true,
            status: detail.status,
          },
          pass,
          fatal || `body=${detail.bodyText.length} path=${project.admin.native_admin_path}`,
        )
        expect(pass, `${project.project} native admin failed: status=${detail.status} fatal=${fatal}`).toBeTruthy()
      })

      test(`${project.project}: source/target WP edit screens and WPTSALL manual editor render`, async ({ page }) => {
        await wpLogin(page)
        const postEditRequired = project.admin.post_edit_required !== false
        for (const item of [
          { id: `${project.project}:source-edit`, url: project.admin.source_edit_url, surface: 'post-edit' as const, required: postEditRequired },
          { id: `${project.project}:target-edit`, url: project.admin.target_edit_url, surface: 'post-edit' as const, required: postEditRequired },
          { id: `${project.project}:manual-editor`, url: project.admin.manual_editor_url, surface: 'manual-editor' as const, required: true },
        ]) {
          expect(item.url, `${item.id} missing URL`).toBeTruthy()
          const detail = await gotoAndInspect(page, item.url)
          const fatal = findFatal(detail.html)
          let pass =
            detail.status >= 200 &&
            detail.status < 400 &&
            !fatal &&
            !detail.html.includes('id="loginform"') &&
            (detail.html.includes('wpcontent') || detail.html.includes('wp-admin'))
          if (item.surface === 'manual-editor') {
            pass =
              pass &&
              detail.html.includes('id="wptsall-translation-editor"') &&
              detail.html.includes('wptsall-save-translation') &&
              detail.bodyText.includes('Source Content') &&
              detail.bodyText.includes('Target Content') &&
              detail.bodyText.includes('Translation Mode')
          }
          record(
            results,
            {
              id: item.id,
              project: project.project,
              surface: item.surface,
              url: item.url,
              required: item.required,
              status: detail.status,
            },
            pass || !item.required,
            fatal || `${item.required ? '' : 'optional core post.php check; '}body=${detail.bodyText.length}`,
          )
          if (item.required) {
            expect(pass, `${item.id} failed: status=${detail.status} fatal=${fatal}`).toBeTruthy()
          }
        }
      })

      test(`${project.project}: virtual multilingual frontend pages render translated manual content`, async ({ page, context }) => {
        await context.clearCookies()
        expect(project.frontend_urls.length, `${project.project} has no frontend URLs`).toBeGreaterThan(0)

        for (const target of project.frontend_urls) {
          let detail
          let fatal: string | null = null
          let pass = false
          let note = 'not-run'
          try {
            detail = await gotoAndInspect(page, target.url, 60_000)
            fatal = findFatal(detail.html)
            const hasTitle = target.expect_title ? detail.bodyText.includes(target.expect_title) || detail.html.includes(target.expect_title) : true
            const hasMarker = target.expect_content_marker
              ? detail.bodyText.includes(target.expect_content_marker) || detail.html.includes(target.expect_content_marker)
              : true
            const hasHreflang = target.expect_hreflang
              ? new RegExp(`hreflang=["']${target.expect_hreflang.replace('-', '[-_]')}["']`, 'i').test(detail.html)
              : true
            // P1/P3 SEO family: x-default must stay unprefixed on virtual pages.
            let xDefaultOk = true
            if (target.kind === 'target' || target.kind === 'bridge') {
              const xMatch = detail.html.match(
                /<link[^>]*rel=["']alternate["'][^>]*hreflang=["']x-default["'][^>]*href=["']([^"']+)["']/i,
              ) || detail.html.match(
                /<link[^>]*hreflang=["']x-default["'][^>]*href=["']([^"']+)["']/i,
              ) || detail.html.match(
                /<link[^>]*href=["']([^"']+)["'][^>]*hreflang=["']x-default["']/i,
              )
              if (xMatch && payload.prefix) {
                const href = xMatch[1]
                xDefaultOk = !href.toLowerCase().includes(`/${payload.prefix.toLowerCase()}/`)
              }
            }
            pass =
              detail.status === 200 &&
              !fatal &&
              !is404Like(detail.html) &&
              detail.bodyText.length > 250 &&
              (target.kind === 'bridge' ? hasTitle && hasMarker && hasHreflang && xDefaultOk : hasTitle && hasMarker && xDefaultOk)
            note = fatal || `status=${detail.status} body=${detail.bodyText.length} title=${hasTitle} marker=${hasMarker} hreflang=${hasHreflang} xDefaultOk=${xDefaultOk}`
            record(
              results,
              {
                id: target.id,
                project: project.project,
                surface: 'frontend',
                url: target.url,
                required: target.required,
                status: detail.status,
              },
              pass || !target.required,
              target.required ? note : pass ? note : `optional skip: ${note}`,
            )
          } catch (err) {
            note = err instanceof Error ? err.message : String(err)
            record(
              results,
              {
                id: target.id,
                project: project.project,
                surface: 'frontend',
                url: target.url,
                required: target.required,
              },
              !target.required,
              target.required ? note : `optional skip: ${note}`,
            )
          }

          if (target.required) {
            expect(pass, `${target.id} frontend failed: ${note}`).toBeTruthy()
          }
        }
      })
    })
  }
})
