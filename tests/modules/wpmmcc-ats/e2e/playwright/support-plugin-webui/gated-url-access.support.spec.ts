import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'

const runtimeDir = path.resolve(__dirname, '../../runtime')
const auditFile = path.join(runtimeDir, 'url-audit.json')
const reportFile = path.join(runtimeDir, 'gated-url-login-audit.json')

type RuleEntry = {
  id: number
  object_name: string
  classification: string
  requires_login: number
  urls: { canonical?: string; pretty?: string }
  http?: {
    canonical?: { code?: number }
    pretty?: { code?: number }
  }
  object_flags?: { publicly_queryable?: number; public?: number }
}

type ResultRow = {
  id: number
  objectName: string
  targetUrl: string
  status: number
  finalUrl: string
  publiclyQueryable: number
  pass: boolean
  note: string
}

function hasFatalSignals(html: string): string | null {
  const markers = [
    'There has been a critical error on this website',
    'WordPress database error',
    'Fatal error:',
    'Parse error:',
    'Uncaught Error',
  ]
  for (const marker of markers) {
    if (html.includes(marker)) {
      return marker
    }
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

function isHttpOk(code: number): boolean {
  return code >= 200 && code < 400
}

function isAuditNonRoutable(rule: RuleEntry): boolean {
  const canonicalCode = Number(rule.http?.canonical?.code ?? 0)
  const prettyCode = Number(rule.http?.pretty?.code ?? 0)
  const canonicalOk = isHttpOk(canonicalCode)
  const prettyOk = isHttpOk(prettyCode)
  return !canonicalOk && !prettyOk
}

function pickAuditTargetUrl(rule: RuleEntry): string {
  const pretty = withWpBase((rule.urls?.pretty ?? '').trim())
  const canonical = withWpBase((rule.urls?.canonical ?? '').trim())
  const prettyCode = Number(rule.http?.pretty?.code ?? 0)
  const canonicalCode = Number(rule.http?.canonical?.code ?? 0)

  if (pretty && isHttpOk(prettyCode)) return pretty
  if (canonical && isHttpOk(canonicalCode)) return canonical
  return pretty || canonical
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

function withWpBase(rawUrl: string): string {
  if (!rawUrl) return ''
  try {
    const parsed = new URL(rawUrl)
    const base = new URL(WP_BASE)
    parsed.protocol = base.protocol
    parsed.host = base.host
    return parsed.toString()
  } catch {
    return rawUrl.startsWith('/') ? `${WP_BASE}${rawUrl}` : rawUrl
  }
}

async function hasWordPressLoginCookie(page: any): Promise<boolean> {
  const cookies = await page.context().cookies()
  return cookies.some((c: any) => c.name.startsWith('wordpress_logged_in_') && !!c.value)
}

test('Gated URLs are reachable under admin session where queryable', async ({ page }) => {
  expect(fs.existsSync(auditFile), `missing audit file: ${auditFile}`).toBeTruthy()
  const payload = JSON.parse(fs.readFileSync(auditFile, 'utf-8'))
  const rules: RuleEntry[] = Array.isArray(payload?.rules) ? payload.rules : []

  const targets = rules.filter((r) => r.classification === 'expected_gated')
  expect(targets.length, 'expected at least one expected_gated rule').toBeGreaterThan(0)

  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  expect(await hasWordPressLoginCookie(page)).toBeTruthy()

  const results: ResultRow[] = []

  for (const rule of targets) {
    const targetUrl = pickAuditTargetUrl(rule)
    if (!targetUrl) {
      results.push({
        id: rule.id,
        objectName: rule.object_name,
        targetUrl: '',
        status: 0,
        finalUrl: '',
        publiclyQueryable: Number(rule.object_flags?.publicly_queryable ?? 0),
        pass: false,
        note: 'no target url',
      })
      continue
    }

    const response = await page.goto(targetUrl, { waitUntil: 'domcontentloaded' })
    const status = response?.status() ?? 0
    const finalUrl = page.url()
    const html = await page.content()
    const fatal = hasFatalSignals(html)
    const looks404 = is404Like(html)
    const publiclyQueryable = Number(rule.object_flags?.publicly_queryable ?? 0)

    let pass = false
    let note = ''
    if (fatal) {
      pass = false
      note = `fatal: ${fatal}`
    } else if (publiclyQueryable === 1) {
      const reachable = status < 400 && !looks404
      if (reachable) {
        pass = true
        note = 'queryable object reachable in admin session'
      } else if (isAuditNonRoutable(rule)) {
        // Some plugin objects are flagged publicly_queryable by registration,
        // but both canonical and pretty routes are non-routable in audit.
        pass = true
        note = `queryable flag set, but canonical+pretty non-routable (audit); kept tolerated (${status})`
      } else {
        pass = false
        note = `queryable object still blocked (${status})`
      }
    } else {
      pass = true
      note = status >= 400 ? 'internal non-queryable object still blocked (expected)' : 'internal object reachable'
    }

    results.push({
      id: rule.id,
      objectName: rule.object_name,
      targetUrl,
      status,
      finalUrl,
      publiclyQueryable,
      pass,
      note,
    })
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  fs.writeFileSync(
    reportFile,
    JSON.stringify(
      {
        timestamp: new Date().toISOString(),
        wpBase: WP_BASE,
        checked: results.length,
        results,
      },
      null,
      2
    )
  )

  const failed = results.filter((r) => !r.pass)
  if (failed.length > 0) {
    const detail = failed.map((r) => `#${r.id} ${r.objectName}: ${r.note}; status=${r.status}; url=${r.targetUrl}`).join('\n')
    throw new Error(`gated login audit failed:\n${detail}`)
  }
})
