import { expect, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

export const WEB_BASE =
  process.env.WPTSALL_WEB_BASE
  ?? process.env.WPTSALL_SERVER_BASE
  // B 档净室 (2026-09-24): the lane server serves the SPA via
  // WPTSALL_STATIC_DIR at :8787 — the unowned vite :5173 leg is retired.
  ?? 'http://127.0.0.1:8787'
export const CLIENT_BASE = resolveSlotClientBase()
export const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
function resolvePgDsn(): string {
  if (process.env.WPTSALL_PG_DSN && process.env.WPTSALL_PG_DSN.trim()) {
    return process.env.WPTSALL_PG_DSN.trim()
  }

  const repoRoot = path.resolve(process.cwd(), '../../../../..')
  const envPath = path.join(repoRoot, 'web/source/server/.env')
  if (fs.existsSync(envPath)) {
    const content = fs.readFileSync(envPath, 'utf8')
    for (const rawLine of content.split('\n')) {
      const line = rawLine.trim()
      if (!line || line.startsWith('#')) continue
      const match = line.match(/^DATABASE_URL=(.+)$/)
      if (!match) continue
      const value = match[1].trim().replace(/^['"]|['"]$/g, '')
      if (value) return value
    }
  }

  return 'postgres://postgres:postgres@127.0.0.1:5432/wptsall'
}

export const PG_DSN = resolvePgDsn()
export const USERS_TABLE = process.env.WPTSALL_USERS_TABLE ?? 'wptsall_users'
export const WP_CLI_PATH = process.env.WP_CLI_PATH ?? '/var/www/wordpress'
// 批 O6 复栈: lab WP lives in a docker container (wptsall-wp-lab-wordpress-test-1,
// wp at /var/www/html). When set, execWpEval routes through docker exec.
export const WP_CONTAINER = process.env.WPTSALL_JOURNEY_WP_CONTAINER ?? ''
export const SERVER_HEALTH = process.env.WPTSALL_SERVER_HEALTH ?? 'http://127.0.0.1:8787/health'
const E2E_RUNTIME_DIR = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(process.cwd(), '../runtime')
const E2E_PHP_DIR = path.resolve(process.cwd(), '../php')
// 批 O6 复栈: the playwright dir sits FIVE levels below the repo root
// (root/tests/modules/wpmmcc-ats/e2e/playwright); the old '../../../' depth
// pointed at tests/modules and every REPO_ROOT-derived path was dead.
const REPO_ROOT = path.resolve(process.cwd(), '../../../../..')
const SERVER_BIN = process.env.WPTSALL_SERVER_BIN
  ? path.resolve(process.env.WPTSALL_SERVER_BIN)
  : path.join(REPO_ROOT, 'web/source/server/target/release/wptsall-server')
// 批 O6 复栈: slot-scoped client runtime (mirrors journey-client-controller.sh
// and config.sh e2e_client_db_path()/e2e_client_session_token_file() for
// E2E_SLOT=slot-v). The old source/runtime defaults pointed at the SHARED lab
// client state — stale paired sessions and stale cached site tables.
const E2E_SLOT_RUNTIME_DIR = path.join(REPO_ROOT, 'tests/modules/wpmmcc-ats/e2e/runtime/slot-v')
const CLIENT_DB = process.env.WPTSALL_CLIENT_DB
  ? path.resolve(process.env.WPTSALL_CLIENT_DB)
  : path.join(E2E_SLOT_RUNTIME_DIR, 'client-db/wptsall.db')
const CLIENT_SESSION_FILE = process.env.WPTSALL_CLIENT_SESSION_FILE
  ? path.resolve(process.env.WPTSALL_CLIENT_SESSION_FILE)
  : path.join(E2E_SLOT_RUNTIME_DIR, 'session-token.enc')
// 批 O6 复栈: lane-managed client controller (systemd unit is absent in the
// lab). When set, client restart/caps flows delegate to it instead of
// systemctl --user; the systemd path stays the default for the original host.
const CLIENT_CTRL = process.env.WPTSALL_JOURNEY_CLIENT_CTRL ?? ''
const PUBLIC_AUTH_RETRY_LIMIT = Math.max(1, Number(process.env.WPTSALL_JOURNEY_PUBLIC_AUTH_RETRY_LIMIT ?? '2'))
const TRANSIENT_PUBLIC_AUTH_ERROR_RE = /Too many attempts|Too many login attempts|Too many requests|Please try again later|temporarily unavailable|rate limit|操作过于频繁|请稍后再试/i
const TRANSIENT_OAUTH_ERROR_RE = /oauth|popup|callback|settle|timeout|Too many attempts|Please try again later|操作过于频繁/i

export type LiveWpClientCredentials = {
  apiBaseUrl: string
  wpClientToken: string
  routeSecret: string
  deviceId: string
}

export function uniqueJourneyEmail(prefix = 'journey'): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@wptsall.dev`
}

function loadPreferredRelationIds(): string[] {
  const relationFile = path.join(E2E_RUNTIME_DIR, 'relation-ids.json')
  if (!fs.existsSync(relationFile)) return []

  try {
    const parsed = JSON.parse(fs.readFileSync(relationFile, 'utf8')) as Record<string, unknown>
    const preferred = [parsed.virtual, parsed.wp, parsed.self]
      .map((value) => Number(value))
      .filter((value) => Number.isFinite(value) && value > 0)
      .map((value) => String(value))
    return Array.from(new Set(preferred))
  } catch {
    return []
  }
}

export async function bypassTurnstile(context: BrowserContext) {
  await context.route('**/challenges.cloudflare.com/turnstile/**', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'text/javascript',
      body: 'window.turnstile={render(el,opts){if(opts&&opts.callback)opts.callback("mock-turnstile-token");return "mock"},reset(){},remove(){},getResponse(){return "mock-turnstile-token"}}',
    })
  })

  await context.route('**/turnstile**', (route) => {
    if (route.request().resourceType() === 'script') {
      route.fulfill({ status: 200, contentType: 'text/javascript', body: '' })
    } else {
      route.continue()
    }
  })

  context.on('page', (popup) => {
    popup.addInitScript(() => {
      ;(window as any).turnstile = {
        render(_el: any, options: any) {
          if (options?.callback) options.callback('mock-turnstile-token')
          return 'mock'
        },
        reset() {},
        remove() {},
        getResponse() { return 'mock-turnstile-token' },
      }
    })
  })

  await context.addInitScript(() => {
    ;(window as any).turnstile = {
      render(_el: any, options: any) {
        if (options?.callback) options.callback('mock-turnstile-token')
        return 'mock'
      },
      reset() {},
      remove() {},
      getResponse() { return 'mock-turnstile-token' },
    }
  })
}

export async function clearWebSession(page: Page) {
  await page.goto(`${WEB_BASE}/login`)
  await page.evaluate(async () => {
    const token = localStorage.getItem('session_token')
    if (token) {
      await fetch('/api/v1/client/logout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Client-Session': token,
        },
      }).catch(() => {})
    }
    localStorage.clear()
    sessionStorage.clear()
  })
}

async function firstVisibleText(page: Page, selectors: string[]): Promise<string> {
  for (const selector of selectors) {
    const locator = page.locator(selector).first()
    if (await locator.isVisible().catch(() => false)) {
      const text = ((await locator.textContent().catch(() => '')) ?? '').trim()
      if (text) return text
    }
  }
  return ''
}

async function readPublicAuthErrorText(page: Page): Promise<string> {
  const directText = await firstVisibleText(page, [
    'p.text-sm.text-red-600',
    '.text-red-600',
    '#errorMsg',
    '.error',
    '[role="alert"]',
    '.text-amber-800',
  ])
  if (directText) return directText

  const bodyText = ((await page.locator('body').textContent().catch(() => '')) || '')
    .replace(/\s+/g, ' ')
    .trim()
  const match = bodyText.match(/(Too many attempts[^.]*\.|Too many login attempts[^.]*\.|Please try again later[^.]*\.|操作过于频繁[^。]*。|请稍后再试[^。]*。|Login Failed|登录失败)/i)
  return match?.[0]?.trim() ?? ''
}

function isTransientPublicAuthError(message: string): boolean {
  return TRANSIENT_PUBLIC_AUTH_ERROR_RE.test(message)
}

function isRetryableOAuthError(message: string): boolean {
  return isTransientPublicAuthError(message) || TRANSIENT_OAUTH_ERROR_RE.test(message)
}

async function waitForClientLoggedInState(page: Page, timeoutMs = 20_000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    const logoutVisible = await page.locator('button:has-text("退出登录")').first().isVisible().catch(() => false)
    if (logoutVisible) {
      return
    }

    try {
      const status = await page.evaluate(async () => {
        const response = await fetch('/api/status', { credentials: 'include' })
        return response.ok ? response.json() : null
      })
      if ((status as Record<string, any> | null)?.data?.logged_in) {
        return
      }
    } catch {
      // retry
    }

    await page.waitForTimeout(1000)
  }

  throw new Error(`client OAuth login did not settle into logged_in state within ${timeoutMs}ms`)
}

async function prepareClientOAuthAttempt(page: Page, context: BrowserContext) {
  await resetClientJourneySession()
  await context.clearCookies()
  // Journey specs assert the client UI in Chinese (概览/设置/任务管理/…). The
  // client picks its locale from localStorage `wptsall_locale.v1`, falling
  // back to navigator.language (Playwright default en-US → English UI, which
  // made every Chinese locator time out). Seed the persisted locale via an
  // init script so it re-applies on every CLIENT_BASE load, even after
  // localStorage.clear() in the logout path below.
  await page.addInitScript(() => {
    localStorage.setItem('wptsall_locale.v1', 'zh-CN')
  })
  await page.goto(CLIENT_BASE)
  await page.waitForLoadState('domcontentloaded')

  const logoutButton = page.locator('button:has-text("退出登录")').first()
  if (await logoutButton.isVisible().catch(() => false)) {
    await page.evaluate(async () => {
      try {
        await fetch('/api/logout', { method: 'POST', credentials: 'include' })
      } catch {}
      localStorage.clear()
      sessionStorage.clear()
    }).catch(() => {})

    if (await logoutButton.isVisible().catch(() => false)) {
      await logoutButton.click().catch(() => {})
    }
  }

  await page.evaluate(() => {
    localStorage.clear()
    sessionStorage.clear()
  }).catch(() => {})
  await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {})

  if (await logoutButton.isVisible().catch(() => false)) {
    await page.evaluate(async () => {
      try {
        await fetch('/api/logout', { method: 'POST', credentials: 'include' })
      } catch {}
      localStorage.clear()
      sessionStorage.clear()
    }).catch(() => {})
    await page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {})
  }
}

export async function registerUser(page: Page, args: {
  email: string
  password: string
  name?: string
}) {
  const successNotice = page.locator('text=/Account created|账户已创建/i').first()

  for (let attempt = 0; attempt < PUBLIC_AUTH_RETRY_LIMIT; attempt += 1) {
    await clearWebSession(page)
    await page.goto('/register')
    if (args.name) {
      await page.locator('input[type="text"]').fill(args.name)
    }
    await page.locator('input[type="email"]').fill(args.email)
    await page.locator('input[type="password"]').fill(args.password)
    await page.click('button[type="submit"]')

    try {
      await expect(successNotice).toBeVisible({ timeout: 15_000 })
      return
    } catch {
      const errorText = await readPublicAuthErrorText(page)
      if (attempt + 1 < PUBLIC_AUTH_RETRY_LIMIT && isTransientPublicAuthError(errorText)) {
        await restartJourneyServer()
        continue
      }
      throw new Error(`register user did not succeed: ${errorText || 'unknown state'}`)
    }
  }
}

function querySingleValue(sql: string): string {
  return execFileSync(
    'psql',
    [PG_DSN, '-At', '-c', sql],
    { encoding: 'utf8' },
  ).trim()
}

function execWpEval(code: string, wpPath = WP_CLI_PATH): string {
  // 批 O6 复栈: route through the lab WP docker container when configured;
  // host `wp` stays the default for the original full-stack host.
  if (WP_CONTAINER) {
    return execFileSync(
      'docker',
      ['exec', WP_CONTAINER, 'wp', 'eval', code, '--path=/var/www/html', '--allow-root'],
      { encoding: 'utf8' },
    ).trim()
  }
  return execFileSync(
    'wp',
    ['eval', code, `--path=${wpPath}`],
    { encoding: 'utf8' },
  ).trim()
}

function execSystemctlUser(args: string[]): string {
  return execFileSync(
    'systemctl',
    ['--user', ...args],
    { encoding: 'utf8' },
  ).trim()
}

// 批 O6 复栈: restart the journey client. Lab (CLIENT_CTRL set): the lane
// client controller script takes optional KEY=VAL env pairs and re-launches
// the lane-managed client with them. Original host: systemd user manager.
async function restartJourneyClient(extraEnv: string[] = []): Promise<void> {
  if (CLIENT_CTRL) {
    execFileSync(CLIENT_CTRL, ['restart', ...extraEnv], { encoding: 'utf8' })
    await waitForClientApiReady()
    return
  }
  if (extraEnv.length > 0) {
    execSystemctlUser(['set-environment', ...extraEnv])
  }
  execSystemctlUser(['restart', 'wptsall-client-webui.service'])
  await waitForClientApiReady()
}

async function unsetJourneyClientEnv(keys: string[]): Promise<void> {
  if (CLIENT_CTRL) {
    // The lane controller launches each client with a fresh env — unsetting
    // is just a plain restart.
    await restartJourneyClient()
    return
  }
  execSystemctlUser(['unset-environment', ...keys])
  execSystemctlUser(['restart', 'wptsall-client-webui.service'])
  await waitForClientApiReady()
}

async function waitForClientApiReady(timeoutMs = 30_000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${CLIENT_BASE}/api/status`)
      if (response.ok) {
        return
      }
    } catch {
      // retry
    }
    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error(`client api not ready within ${timeoutMs}ms`)
}

async function waitForServerApiReady(timeoutMs = 30_000) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const response = await fetch(SERVER_HEALTH)
      if (response.ok) {
        return
      }
    } catch {
      // retry
    }
    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error(`server api not ready within ${timeoutMs}ms`)
}

export async function restartJourneyServer() {
  // 批 O6 复栈: with the lane-managed (nohup) server, killing is NOT enough —
  // nothing supervises it. Delegate to the server controller (same script the
  // runner uses) when available; the pgrep-kill fallback remains for the
  // systemd-managed world where the unit auto-restarts.
  const serverCtrl = process.env.WPTSALL_SERVER_CTRL
  if (serverCtrl) {
    execFileSync('bash', [serverCtrl, 'restart'])
    await waitForServerApiReady()
    return
  }

  let pids: number[] = []
  try {
    const stdout = execFileSync('pgrep', ['-f', SERVER_BIN], { encoding: 'utf8' }).trim()
    pids = stdout
      .split('\n')
      .map((value) => Number(value.trim()))
      .filter((value) => Number.isFinite(value) && value > 0)
  } catch {
    pids = []
  }

  for (const pid of pids) {
    try {
      process.kill(pid)
    } catch {
      // ignore missing process
    }
  }

  await waitForServerApiReady()
}

export async function configureClientE2ERunCaps(args?: {
  discoveryMaxItemsPerRun?: number
  runOnceMaxElapsedSecs?: number
}) {
  const discoveryMaxItemsPerRun = Math.max(1, args?.discoveryMaxItemsPerRun ?? 20)
  const runOnceMaxElapsedSecs = Math.max(30, args?.runOnceMaxElapsedSecs ?? 120)
  await restartJourneyClient([
    `WPTSALL_DISCOVERY_MAX_ITEMS_PER_RUN=${discoveryMaxItemsPerRun}`,
    `WPTSALL_RUN_ONCE_MAX_ELAPSED_SECS=${runOnceMaxElapsedSecs}`,
  ])
}

export async function restoreClientE2ERunCaps() {
  await unsetJourneyClientEnv([
    'WPTSALL_DISCOVERY_MAX_ITEMS_PER_RUN',
    'WPTSALL_RUN_ONCE_MAX_ELAPSED_SECS',
  ])
}

export async function resetClientJourneySession() {
  execFileSync(
    'python3',
    ['-c', `
import os
import sqlite3
from pathlib import Path

db_path = Path(${JSON.stringify(CLIENT_DB)})
if db_path.exists():
    conn = sqlite3.connect(db_path)
    conn.execute("INSERT OR REPLACE INTO system_config(key, value) VALUES('session_cache', '')")
    conn.commit()
    conn.close()

session_file = Path(${JSON.stringify(CLIENT_SESSION_FILE)})
if session_file.exists():
    session_file.unlink()
`],
    { encoding: 'utf8' },
  )

  await restartJourneyClient()
}

export function resetClientRelationBacklog(relationId: number) {
  const safeRelationId = Math.max(0, Math.trunc(relationId))
  execFileSync(
    'python3',
    ['-c', `
import sqlite3
conn = sqlite3.connect(${JSON.stringify(CLIENT_DB)})
cur = conn.cursor()
cur.execute("DELETE FROM pending_callbacks WHERE relation_id = ?", (${safeRelationId},))
cur.execute("DELETE FROM translation_in_progress WHERE relation_id = ?", (${safeRelationId},))
conn.commit()
conn.close()
`],
    {
      encoding: 'utf8',
      cwd: REPO_ROOT,
    },
  )
}

export function resetClientRelationRuntime(relationId: number) {
  const safeRelationId = Math.max(0, Math.trunc(relationId))
  execFileSync(
    'python3',
    ['-c', `
import sqlite3
conn = sqlite3.connect(${JSON.stringify(CLIENT_DB)})
cur = conn.cursor()
cur.execute("DELETE FROM pending_callbacks WHERE relation_id = ?", (${safeRelationId},))
cur.execute("DELETE FROM translation_in_progress WHERE relation_id = ?", (${safeRelationId},))
cur.execute("DELETE FROM translation_items WHERE relation_id = ?", (${safeRelationId},))
cur.execute("DELETE FROM translation_jobs WHERE relation_id = ?", (${safeRelationId},))
conn.commit()
conn.close()
`],
    {
      encoding: 'utf8',
      cwd: REPO_ROOT,
    },
  )
}

export function resolveLiveWpClientCredentials(args?: {
  wpPath?: string
  apiBaseUrl?: string
}): LiveWpClientCredentials {
  const wpPath = args?.wpPath ?? WP_CLI_PATH
  const apiBaseUrl = String(args?.apiBaseUrl ?? WP_BASE).trim().replace(/\/+$/, '')
  const raw = execWpEval(
    'if(function_exists("wptsall_issue_client_device_token")){ $d=wptsall_issue_client_device_token("pw-journey","playwright"); echo wp_json_encode($d); }',
    wpPath,
  )
  let wpClientToken = ''
  let deviceId = ''
  try {
    const parsed = JSON.parse(raw || '{}') as { token?: string; device_id?: string }
    wpClientToken = String(parsed.token ?? '').trim()
    deviceId = String(parsed.device_id ?? '').trim()
  } catch {
    wpClientToken = ''
    deviceId = ''
  }
  const routeSecret = execWpEval(
    'echo function_exists("wptsall_get_client_route_secret") ? (string) wptsall_get_client_route_secret() : "";',
    wpPath,
  )

  if (!wpClientToken || !routeSecret || !deviceId) {
    throw new Error(`failed to resolve live WP device credentials from ${wpPath}`)
  }

  return {
    apiBaseUrl,
    wpClientToken,
    routeSecret,
    deviceId,
  }
}

export function ensureWpClientApiRuntime(args?: {
  wpPath?: string
  scriptPath?: string
}) {
  const wpPath = args?.wpPath ?? WP_CLI_PATH
  const scriptPath = args?.scriptPath ?? path.join(E2E_PHP_DIR, 'ensure-client-api-runtime.php')
  // 批 O6 复栈: docker route — feed the host script via stdin (wp eval-file -).
  if (WP_CONTAINER) {
    return execFileSync(
      'docker',
      ['exec', '-i', WP_CONTAINER, 'wp', 'eval-file', '-', '--path=/var/www/html', '--allow-root'],
      { encoding: 'utf8', input: fs.readFileSync(scriptPath, 'utf8') },
    )
  }
  return execFileSync(
    'wp',
    ['eval-file', scriptPath, `--path=${wpPath}`],
    { encoding: 'utf8' },
  ).trim()
}

export function readUserPasswordHash(email: string): string {
  const safeEmail = email.replace(/'/g, "''")
  return querySingleValue(
    `SELECT COALESCE(password, '') FROM ${USERS_TABLE} WHERE email='${safeEmail}' LIMIT 1;`,
  )
}

export function restoreUserPasswordHash(email: string, passwordHash: string) {
  const safeEmail = email.replace(/'/g, "''")
  const safeHash = passwordHash.replace(/'/g, "''")
  querySingleValue(
    `UPDATE ${USERS_TABLE}
     SET password='${safeHash}',
         password_reset_token=NULL,
         password_reset_token_expires_at=NULL,
         updated_at=NOW()
     WHERE email='${safeEmail}';
     SELECT 'ok';`,
  )
}

export async function resolveVerificationToken(email: string, timeoutMs = 15_000): Promise<string> {
  const deadline = Date.now() + timeoutMs
  const safeEmail = email.replace(/'/g, "''")
  const sql = `SELECT COALESCE(verification_token, '') FROM ${USERS_TABLE} WHERE email='${safeEmail}' LIMIT 1;`

  while (Date.now() < deadline) {
    try {
      const token = querySingleValue(sql)
      if (token) return token
    } catch {
      // retry
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }

  throw new Error(`verification token not found for ${email}`)
}

export async function resolvePasswordResetToken(email: string, timeoutMs = 15_000): Promise<string> {
  const deadline = Date.now() + timeoutMs
  const safeEmail = email.replace(/'/g, "''")
  const sql = `SELECT COALESCE(password_reset_token, '') FROM ${USERS_TABLE} WHERE email='${safeEmail}' LIMIT 1;`

  while (Date.now() < deadline) {
    try {
      const token = querySingleValue(sql)
      if (token) return token
    } catch {
      // retry
    }
    await new Promise((resolve) => setTimeout(resolve, 500))
  }

  throw new Error(`password reset token not found for ${email}`)
}

export async function verifyUserEmail(request: APIRequestContext, token: string) {
  const response = await request.post(`${WEB_BASE}/api/v1/verify-email`, {
    data: { token },
  })
  expect(response.ok(), `verify-email HTTP ${response.status()}`).toBe(true)
  const payload = await response.json()
  expect(payload?.success).toBe(true)
}

export async function loginWebUser(page: Page, args: {
  email: string
  password: string
}) {
  for (let attempt = 0; attempt < PUBLIC_AUTH_RETRY_LIMIT; attempt += 1) {
    await clearWebSession(page)
    await page.goto(`${WEB_BASE}/login`)
    await page.locator('input[type="email"]').fill(args.email)
    await page.locator('input[type="password"]').fill(args.password)
    await page.click('button[type="submit"]')

    try {
      await page.waitForURL('**/dashboard', { timeout: 15_000 })
      return
    } catch {
      const errorText = await readPublicAuthErrorText(page)
      if (attempt + 1 < PUBLIC_AUTH_RETRY_LIMIT && isTransientPublicAuthError(errorText)) {
        await restartJourneyServer()
        continue
      }
      throw new Error(`web login did not succeed: ${errorText || 'unknown state'}`)
    }
  }
}

export async function requestWebPasswordReset(page: Page, args: {
  email: string
}) {
  const successNotice = page.locator('text=/reset link sent|重置链接已发送/i')

  for (let attempt = 0; attempt < PUBLIC_AUTH_RETRY_LIMIT; attempt += 1) {
    await page.goto(`${WEB_BASE}/forgot-password`)
    await page.locator('input[type="email"]').fill(args.email)
    await page.click('button[type="submit"]')

    try {
      await expect(successNotice).toBeVisible({ timeout: 15_000 })
      return
    } catch {
      const errorText = await readPublicAuthErrorText(page)
      if (attempt + 1 < PUBLIC_AUTH_RETRY_LIMIT && isTransientPublicAuthError(errorText)) {
        await restartJourneyServer()
        continue
      }
      throw new Error(`password reset request did not succeed: ${errorText || 'unknown state'}`)
    }
  }
}

export async function resetWebPassword(page: Page, args: {
  token: string
  password: string
}) {
  await page.goto(`${WEB_BASE}/reset-password?token=${encodeURIComponent(args.token)}`)
  const passwordFields = page.locator('input[type="password"]')
  await passwordFields.nth(0).fill(args.password)
  await passwordFields.nth(1).fill(args.password)
  await page.click('button[type="submit"]')
  await expect(
    page.locator('button, a').filter({ hasText: /Go to Login|前往登录|Back to Login|返回登录/i }).first(),
  ).toBeVisible({ timeout: 15_000 })
}

export async function loginClientViaOAuth(page: Page, context: BrowserContext, args: {
  email: string
  password: string
}) {
  for (let attempt = 0; attempt < PUBLIC_AUTH_RETRY_LIMIT; attempt += 1) {
    try {
      await prepareClientOAuthAttempt(page, context)

      // 批 O6 复栈: the client web UI no longer renders a login/OAuth button
      // (batch G redesign — the local control plane boots without a web
      // session; "官网 OAuth 不再是运行时前置条件" in App.svelte). The pairing
      // flow lives in the client's HTTP API instead: POST /api/oauth/start
      // returns the server authorize URL, which we open as a popup. With the
      // context's cookies cleared (prepareClientOAuthAttempt), the popup
      // lands on the web login form; after submitting, the server redirects
      // to the client's /oauth/callback and the client exchanges the code.
      const startResult = await page.evaluate(async () => {
        const response = await fetch('/api/oauth/start', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json' },
          body: '{}',
        })
        return response.ok ? response.json() : null
      })
      const authorizeUrl = (startResult as Record<string, any> | null)?.data?.authorize_url
      if (!authorizeUrl) {
        throw new Error(`client /api/oauth/start returned no authorize_url: ${JSON.stringify(startResult)}`)
      }

      const [popup] = await Promise.all([
        page.waitForEvent('popup', { timeout: 30_000 }),
        page.evaluate((url: string) => {
          window.open(url, '_blank')
        }, authorizeUrl),
      ])

      await popup.waitForLoadState('domcontentloaded')

      // The popup may show the web login form (cookies were cleared) or, if
      // a session survived, go straight to the authorize/callback redirect.
      const loginForm = popup.locator('input[type="email"], input[name="email"]')
      const formVisible = await loginForm.first().isVisible({ timeout: 10_000 }).catch(() => false)
      if (formVisible) {
        await popup.fill('input[type="email"], input[name="email"]', args.email)
        await popup.fill('input[type="password"], input[name="password"]', args.password)
        await popup.waitForTimeout(600)
        await popup.evaluate(() => {
          const field = document.querySelector('input[name="captchaToken"], input[name="captcha_token"]') as HTMLInputElement | null
          if (field) field.value = 'mock-turnstile-token'
        })
        await popup.click('button[type="submit"], input[type="submit"]')
      }

      await Promise.race([
        popup.waitForEvent('close', { timeout: 30_000 }),
        popup.waitForURL('**/callback**', { timeout: 30_000 }),
      ]).catch(() => {})

      const popupErrorText = await readPublicAuthErrorText(popup).catch(() => '')
      if (popupErrorText) {
        throw new Error(`oauth authorize page returned: ${popupErrorText}`)
      }

      await page.waitForTimeout(1000)
      await waitForClientLoggedInState(page)
      return
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error)
      if (attempt + 1 < PUBLIC_AUTH_RETRY_LIMIT && isRetryableOAuthError(message)) {
        await restartJourneyServer()
        continue
      }
      throw error
    }
  }
}

export async function expectClientLoggedIn(request: APIRequestContext) {
  const statusRes = await request.get(`${CLIENT_BASE}/api/status`)
  expect(statusRes.ok(), `client status HTTP ${statusRes.status()}`).toBe(true)
  const status = await statusRes.json()
  expect(status?.data?.logged_in).toBe(true)
}

export async function runClientWorkerOnce(request: APIRequestContext): Promise<Record<string, any>> {
  const preflightRes = await request.post(`${CLIENT_BASE}/api/worker/start-check`)
  expect(preflightRes.ok(), `worker start-check HTTP ${preflightRes.status()}`).toBe(true)
  const preflightJson = await preflightRes.json().catch(() => null as unknown)
  expect((preflightJson as Record<string, any> | null)?.success, JSON.stringify(preflightJson)).toBe(true)
  const preflightData = ((preflightJson as Record<string, any> | null)?.data ?? {}) as Record<string, any>
  const blockingMissing = Number(preflightData?.summary?.blocking_missing_components ?? 0)
  expect(blockingMissing, `worker start-check has blocking missing components: ${JSON.stringify(preflightJson)}`).toBe(0)

  const runOnceRes = await request.post(`${CLIENT_BASE}/api/worker/run-once`, { data: {} })
  expect(runOnceRes.ok(), `worker run-once HTTP ${runOnceRes.status()}`).toBe(true)
  const runOnceJson = await runOnceRes.json().catch(() => null as unknown)
  expect((runOnceJson as Record<string, any> | null)?.success, JSON.stringify(runOnceJson)).toBe(true)
  return ((runOnceJson as Record<string, any> | null)?.data ?? {}) as Record<string, any>
}

export async function wpLogin(page: Page, args: {
  user: string
  password: string
}) {
  const safeUser = args.user.replace(/'/g, "\\'")
  const safePassword = args.password.replace(/'/g, "\\'")
  execWpEval(`
    $user = get_user_by('login', '${safeUser}');
    if ($user) {
      wp_set_password('${safePassword}', $user->ID);
      $user->set_role('administrator');
    }
  `)
  await page.context().clearCookies()
  const targetAdminUrl = `${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=authorization`
  await page.goto(
    `${WP_BASE}/wp-login.php?redirect_to=${encodeURIComponent(targetAdminUrl)}`,
    { waitUntil: 'domcontentloaded' },
  )
  await page.locator('#user_login').fill(args.user)
  await page.locator('#user_pass').fill(args.password)
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('#wp-submit').click(),
  ])
  await expect
    .poll(async () => {
      const cookies = await page.context().cookies()
      return cookies.some((cookie) => cookie.name.startsWith('wordpress_logged_in_') && !!cookie.value)
    }, { timeout: 15_000 })
    .toBe(true)

  await page.goto(targetAdminUrl, { waitUntil: 'domcontentloaded' })
  await expect(page).toHaveURL(/\/wp-admin\/admin\.php\?page=wptsall-tasks&tab=authorization/)
  await expect(page.locator('body')).not.toContainText(/Sorry, you are not allowed/i)
}

export async function generateFreshWpVerificationUrl(page: Page): Promise<string> {
  const raw = execWpEval(`
    $result = \\WPTSALL\\Core\\Site_Verification::generate_verification_nonce();
    if (is_wp_error($result)) {
      echo wp_json_encode(array(
        'success' => false,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
      ));
      return;
    }
    echo wp_json_encode(array('success' => true, 'data' => $result));
  `)
  const payload = JSON.parse(raw) as Record<string, any>
  expect(payload?.success, `WP verification URL generation failed: ${raw}`).toBe(true)
  const verificationUrl = String(payload?.data?.verification_url ?? '')
  if (!verificationUrl) {
    throw new Error(`verification URL missing in WP response: ${raw}`)
  }
  return verificationUrl
}

function createWpJobBundleViaCli(args: {
  relationId: string
  jobId: string
  includeContent: boolean
  includeLanguagePack: boolean
  limit: number
  batchSize: number
}): { relationId: string; jobId: string; totalTasks: number } {
  const relationId = Number(args.relationId)
  if (!Number.isFinite(relationId) || relationId <= 0) {
    throw new Error(`invalid relation id for WP job bundle: ${args.relationId}`)
  }
  const safeJobId = args.jobId.replace(/[^A-Za-z0-9_.-]/g, '_')
  const raw = execWpEval(`
    $relation_id = ${relationId};
    $job_id = '${safeJobId.replace(/'/g, "\\'")}';
    $plan = \\WPTSALL\\Tasks\\Services\\Task_Job_Planner::plan_relation_job(
      $relation_id,
      array(
        'job_id' => $job_id,
        'include_content' => ${args.includeContent ? 'true' : 'false'},
        'include_language_pack' => ${args.includeLanguagePack ? 'true' : 'false'},
        'limit' => ${args.limit},
        'batch_size' => ${args.batchSize},
      )
    );
    if (is_wp_error($plan)) {
      echo wp_json_encode(array(
        'success' => false,
        'code' => $plan->get_error_code(),
        'message' => $plan->get_error_message(),
      ));
      return;
    }
    $tasks = is_array($plan['tasks'] ?? null) ? $plan['tasks'] : array();
    if (!empty($tasks)) {
      wptsall_insert_tasks($tasks);
    }
    echo wp_json_encode(array(
      'success' => true,
      'data' => array(
        'relation_id' => $relation_id,
        'job_id' => $job_id,
        'total_tasks' => count($tasks),
        'content_tasks' => (int) ($plan['content_tasks'] ?? 0),
        'language_pack_tasks' => (int) ($plan['language_pack_tasks'] ?? 0),
      ),
    ));
  `)
  const payload = JSON.parse(raw) as Record<string, any>
  expect(payload?.success, `WP job bundle creation failed: ${raw}`).toBe(true)
  const totalTasks = Number(payload?.data?.total_tasks ?? 0)
  expect(totalTasks, `WP job bundle should create tasks: ${raw}`).toBeGreaterThan(0)
  return {
    relationId: String(payload.data.relation_id),
    jobId: String(payload.data.job_id),
    totalTasks,
  }
}

export async function configureClientDiscoveryTasksForRelation(
  request: APIRequestContext,
  args: {
    routeSecret: string
    relationId: number
    componentId: string
  },
) {
  const discoveryTasksRes = await request.get(`${CLIENT_BASE}/api/discovery-tasks`)
  expect(discoveryTasksRes.ok(), `discovery tasks HTTP ${discoveryTasksRes.status()}`).toBe(true)
  const discoveryTasksJson = await discoveryTasksRes.json().catch(() => null as unknown)
  const discoveryTasks: Array<Record<string, any>> =
    (((discoveryTasksJson as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)?.items as Array<Record<string, any>> | undefined) ?? []
  const currentDomainNeedle = `/wptsall/v2/${args.routeSecret}/client`
  const sameDomainTasks = discoveryTasks.filter((item) =>
    String(item?.domain ?? '').includes(currentDomainNeedle)
  )
  const activeTask = discoveryTasks.find((item) =>
    String(item?.domain ?? '').includes(currentDomainNeedle)
    && Number(item?.relation_id ?? 0) === args.relationId
  )
  expect(activeTask, `missing discovery task for relation ${args.relationId}`).toBeTruthy()

  for (const task of sameDomainTasks) {
    const relationId = Number(task?.relation_id ?? 0)
    const isTargetTask = relationId === args.relationId
    const updateRes = await request.put(`${CLIENT_BASE}/api/discovery-tasks/${task.id}`, {
      data: {
        concurrency: 1,
        batch_parallel: 1,
        per_page: 200,
        retry_max: 4,
        timeout_secs: 90,
        enabled: isTargetTask,
        include_resync: isTargetTask,
        selected_component_id: isTargetTask ? args.componentId : null,
      },
    })
    expect(updateRes.ok(), `update discovery task ${task.id} HTTP ${updateRes.status()}`).toBe(true)
    expect((await updateRes.json())?.success).toBe(true)
  }
}

export async function findPendingReviewItemForRelation(
  request: APIRequestContext,
  relationId: number,
): Promise<Record<string, any>> {
  const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
  expect(jobsRes.ok(), `jobs HTTP ${jobsRes.status()}`).toBe(true)
  const jobsJson = await jobsRes.json().catch(() => null as unknown)
  const jobsData = ((jobsJson as Record<string, any> | null)?.data ?? {}) as Record<string, any>
  const jobs = (jobsData.items ?? []) as Array<Record<string, any>>
  const targetJob = jobs.find((job) => Number(job?.relation_id ?? 0) === relationId)
  expect(targetJob, `missing client translation job for relation ${relationId}`).toBeTruthy()

  const itemsRes = await request.get(`${CLIENT_BASE}/api/jobs/${targetJob?.id}/items?status=pending_review`)
  expect(itemsRes.ok(), `job items HTTP ${itemsRes.status()}`).toBe(true)
  const itemsJson = await itemsRes.json().catch(() => null as unknown)
  const itemsData = ((itemsJson as Record<string, any> | null)?.data ?? {}) as Record<string, any>
  const items = (itemsData.items ?? []) as Array<Record<string, any>>
  const relationItems = items.filter((item) => Number(item?.relation_id ?? 0) === relationId)
  // 批 O6 复栈: prefer a WELL-FORMED i18n item (language-pack family). The
  // slot fixture also queues config_i18n tasks whose payloads are post
  // field-maps (object_type post_type): their translated files carry
  // field_results, while the config_i18n business line routes the approve
  // writeback down the entries-expecting i18n path — a fixture/pipeline
  // shape mismatch (ledgered) that 400s the approve. The language-pack
  // family (raw = real .po-style entries) round-trips cleanly.
  const i18nFamily = relationItems.filter((item) => {
    const objectType = String(item?.object_type ?? '').toLowerCase()
    const businessLine = String(item?.business_line ?? '').toLowerCase()
    return objectType === 'language_pack'
      || objectType === 'plugin'
      || objectType === 'theme'
      || businessLine === 'plugin_i18n'
      || businessLine === 'theme_i18n'
      || businessLine === 'language_pack_i18n'
  })
  const pendingItem = i18nFamily[0] ?? relationItems[0]
  expect(pendingItem, `missing pending_review item for relation ${relationId}`).toBeTruthy()
  return pendingItem as Record<string, any>
}

export async function createFreshWpJobBundle(page: Page, options?: {
  includeContent?: boolean
  includeLanguagePack?: boolean
  limit?: number
  batchSize?: number
}): Promise<{
  relationId: string
  jobId: string
  totalTasks: number
}> {
  const includeContent = options?.includeContent ?? true
  const includeLanguagePack = options?.includeLanguagePack ?? true
  const limit = Math.max(1, options?.limit ?? 1)
  const batchSize = Math.max(1, options?.batchSize ?? 200)
  const preferredRelationIds = loadPreferredRelationIds()
  const runtimeRelationId = preferredRelationIds[0] ?? ''
  if (runtimeRelationId) {
    return createWpJobBundleViaCli({
      relationId: runtimeRelationId,
      jobId: `journey_rel_job_${Date.now()}`,
      includeContent,
      includeLanguagePack,
      limit,
      batchSize,
    })
  }

  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=monitoring`, {
    waitUntil: 'domcontentloaded',
  })

  let relationId = runtimeRelationId
  const addBtn = page.locator('#wptsall-add-task-btn')

  if (!relationId && await addBtn.count()) {
    await addBtn.first().click()
    await expect(page.locator('#wptsall-add-task-modal')).toBeVisible()

    const relationOptions = page.locator('#add-task-relation option[value]:not([value=""])')
    const optionCount = await relationOptions.count()
    if (optionCount > 0) {
      for (const preferredId of preferredRelationIds) {
        const exists = await page.locator(`#add-task-relation option[value="${preferredId}"]`).count()
        if (exists > 0) {
          relationId = preferredId
          break
        }
      }
      if (!relationId) {
        relationId = (await relationOptions.first().getAttribute('value')) ?? ''
      }
      expect(relationId).not.toBe('')

      const monitorRespPromise = page.waitForResponse((resp) =>
        resp.request().method() === 'POST'
          && resp.url().includes('/wp-json/wptsall/v2/tasks/monitor/start'),
      )
      await page.selectOption('#add-task-relation', relationId)
      await page.click('#wptsall-create-task-btn')
      const monitorResp = await monitorRespPromise
      expect(monitorResp.ok(), `HTTP ${monitorResp.status()} from ${monitorResp.url()}`).toBeTruthy()
      await page.waitForLoadState('domcontentloaded')
    }
  }

  if (!relationId) {
    const firstScanBtn = page.locator('.wptsall-scan-langpack').first()
    await expect(firstScanBtn, 'No monitoring task relation available for scan').toBeVisible()
    relationId = (await firstScanBtn.getAttribute('data-relation-id')) ?? ''
  }

  expect(relationId, 'Cannot resolve relation_id from monitoring UI').not.toBe('')

  let scanDialogText = ''
  if (includeLanguagePack) {
    const scanBtn = page.locator(`.wptsall-scan-langpack[data-relation-id="${relationId}"]`).first()
    await expect(scanBtn, 'Scan button for relation not found').toBeVisible()

    const scanDialogPromise = page.waitForEvent('dialog', { timeout: 8_000 }).then(async (dialog) => {
      const message = dialog.message()
      await dialog.accept()
      return message
    }).catch(() => '')

    const scanRespPromise = page.waitForResponse((resp) =>
      resp.request().method() === 'POST'
        && resp.url().includes('/wp-json/wptsall/v2/tasks/scan-language-pack'),
      { timeout: 8_000 },
    ).catch(() => null)

    await scanBtn.click()
    const [scanResp, dialogText] = await Promise.all([scanRespPromise, scanDialogPromise])
    scanDialogText = dialogText
    if (scanResp) {
      expect(scanResp.ok(), `HTTP ${scanResp.status()} from ${scanResp.url()}`).toBeTruthy()
    }
  }

  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=jobs`, {
    waitUntil: 'domcontentloaded',
  })
  await expect(page.locator('#wptsall-job-relation-id')).toBeVisible()

  let jobRelationId = relationId
  const currentRelationExists = relationId
    ? await page.locator(`#wptsall-job-relation-id option[value="${relationId}"]`).count()
    : 0
  if (!currentRelationExists) {
    for (const preferredId of preferredRelationIds) {
      const exists = await page.locator(`#wptsall-job-relation-id option[value="${preferredId}"]`).count()
      if (exists > 0) {
        jobRelationId = preferredId
        break
      }
    }
  }
  if (!jobRelationId) {
    const fallback = page.locator('#wptsall-job-relation-id option[value]:not([value=""])').first()
    jobRelationId = (await fallback.getAttribute('value')) ?? relationId
  }
  relationId = jobRelationId
  await page.selectOption('#wptsall-job-relation-id', relationId)

  if (includeContent && !(await page.locator('#wptsall-job-include-content').isChecked())) {
    await page.click('#wptsall-job-include-content')
  }
  if (!includeContent && (await page.locator('#wptsall-job-include-content').isChecked())) {
    await page.click('#wptsall-job-include-content')
  }
  if (includeLanguagePack && !(await page.locator('#wptsall-job-include-language-pack').isChecked())) {
    await page.click('#wptsall-job-include-language-pack')
  }
  if (!includeLanguagePack && (await page.locator('#wptsall-job-include-language-pack').isChecked())) {
    await page.click('#wptsall-job-include-language-pack')
  }
  if (await page.locator('#wptsall-job-preview').isChecked()) {
    await page.click('#wptsall-job-preview')
  }

  const customJobId = `journey_rel_job_${Date.now()}`
  const createJobPayload = {
    relation_id: Number(relationId),
    include_content: await page.locator('#wptsall-job-include-content').isChecked(),
    include_language_pack: await page.locator('#wptsall-job-include-language-pack').isChecked(),
    preview: false,
    allow_existing_job: false,
    limit,
    batch_size: batchSize,
    job_id: customJobId,
  }
  await page.fill('#wptsall-job-limit', String(limit))
  await page.fill('#wptsall-job-batch-size', String(batchSize))
  await page.fill('#wptsall-job-custom-id', customJobId)

  const jobRespPromise = page.waitForResponse((resp) => {
    if (resp.request().method() !== 'POST') return false
    try {
      const url = new URL(resp.url())
      return url.pathname.endsWith('/wp-json/wptsall/v2/tasks/jobs')
    } catch {
      return false
    }
  })
  const createDialogPromise = page.waitForEvent('dialog', { timeout: 10_000 }).then(async (dialog) => {
    const message = dialog.message()
    await dialog.accept()
    return message
  }).catch(() => '')
  const jobRedirectPromise = page.waitForURL(/job_bundle_created=1/, { timeout: 12_000 })
    .then(() => true)
    .catch(() => false)

  await page.click('#wptsall-create-job-bundle-btn')
  const [jobResp, createDialogText, redirected] = await Promise.all([
    jobRespPromise,
    createDialogPromise,
    jobRedirectPromise,
  ])

  let responseData: Record<string, any> | null = null

  if (jobResp.ok()) {
    responseData = await jobResp.json().catch(() => null as unknown as Record<string, any>)
  } else {
    const fallback = await page.evaluate(async (payload) => {
      const nonce = (window as any)?.wpApiSettings?.nonce
      const response = await fetch('/wp-json/wptsall/v2/tasks/jobs', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        credentials: 'include',
        body: JSON.stringify(payload),
      })
      const text = await response.text()
      let json = null
      try {
        json = JSON.parse(text)
      } catch {
        json = null
      }
      return {
        ok: response.ok,
        status: response.status,
        text,
        json,
      }
    }, createJobPayload)

    expect(
      fallback.ok,
      `HTTP ${fallback.status} from direct fetch /wp-json/wptsall/v2/tasks/jobs :: ${fallback.text}`,
    ).toBe(true)

    responseData = fallback.json?.data ? fallback.json : null
  }

  if (createDialogText) {
    expect(
      createDialogText,
      'Create job should not pop 0-task warning. Check relation model/rule coverage.',
    ).not.toContain('task count is 0')
  }

  let totalTasks = 0
  let jobId = customJobId

  if (redirected && page.url().includes('job_bundle_created=1')) {
    const url = new URL(page.url())
    jobId = url.searchParams.get('job_bundle_id') ?? customJobId
    totalTasks = Number(url.searchParams.get('job_bundle_total') ?? '0')
  } else if (responseData?.data) {
    jobId = String(responseData.data.job_id ?? customJobId)
    totalTasks = Number(responseData.data.total_tasks ?? 0)
  } else {
    const fallbackUrl = new URL(`${WP_BASE}/wp-admin/admin.php`)
    fallbackUrl.searchParams.set('page', 'wptsall-tasks')
    fallbackUrl.searchParams.set('tab', 'jobs')
    fallbackUrl.searchParams.set('job_id', customJobId)
    await page.goto(fallbackUrl.toString(), { waitUntil: 'domcontentloaded' })

    const row = page.locator(`tr:has(code:has-text("${customJobId}"))`).first()
    if (await row.count()) {
      const rowText = (await row.textContent()) ?? ''
      totalTasks = Number((rowText.match(/total=(\d+)/i) ?? [])[1] ?? '0')
    }
  }

  expect(totalTasks, 'Job package should create tasks').toBeGreaterThan(0)

  return {
    relationId,
    jobId,
    totalTasks,
  }
}

export async function createFreshWpLanguagePackJob(page: Page): Promise<{
  relationId: string
  jobId: string
  totalTasks: number
}> {
  return createFreshWpJobBundle(page, {
    includeContent: true,
    includeLanguagePack: true,
    limit: 1,
    batchSize: 200,
  })
}
