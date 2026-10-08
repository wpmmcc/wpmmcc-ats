import * as fs from 'node:fs'
import * as path from 'node:path'
import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { e2eLabDeviceId, resolveSlotClientBase } from '../../lib/e2e-slot-ports'
import { ensureClientUiChinese, primeClientUiLocale } from './client-ui-locale'

const CLIENT_BASE = resolveSlotClientBase()
const DEMO_EMAIL = process.env.DEMO_EMAIL ?? 'demo@wptsall.dev'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? 'demo'

function labServerBase(): string {
  return (
    process.env.WPTSALL_SERVER_BASE_LOCAL ??
    process.env.SERVER_URL ??
    'http://127.0.0.1:8787'
  ).replace(/\/$/, '')
}

function oauthLockPath(): string {
  const explicit = (process.env.WPTSALL_E2E_OAUTH_LOCK_FILE ?? '').trim()
  if (explicit) return explicit
  const runtime = (process.env.E2E_RUNTIME_DIR ?? '').trim()
  if (runtime && /\/slot-[^/]+$/.test(runtime)) {
    return path.join(path.dirname(runtime), 'oauth-login.lock')
  }
  return path.resolve(__dirname, '../../../runtime/oauth-login.lock')
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function isProcessAlive(pid: number): boolean {
  if (!Number.isFinite(pid) || pid <= 0) return false
  try {
    process.kill(pid, 0)
    return true
  } catch {
    return false
  }
}

/** Serialize Lab OAuth PKCE across parallel matrix lanes (shared server rate limit). */
async function withOAuthLoginLock<T>(fn: () => Promise<T>): Promise<T> {
  const lockPath = oauthLockPath()
  fs.mkdirSync(path.dirname(lockPath), { recursive: true })
  const maxWaitMs = Number(process.env.WPTSALL_E2E_OAUTH_LOCK_TIMEOUT_MS ?? 180_000)
  const start = Date.now()
  while (Date.now() - start < maxWaitMs) {
    try {
      const fd = fs.openSync(lockPath, 'wx')
      try {
        fs.writeFileSync(fd, `${process.pid}\n${CLIENT_BASE}\n`)
      } finally {
        fs.closeSync(fd)
      }
      try {
        return await fn()
      } finally {
        try {
          fs.unlinkSync(lockPath)
        } catch {
          // ignore stale lock cleanup errors
        }
      }
    } catch {
      try {
        const raw = fs.readFileSync(lockPath, 'utf8').trim().split('\n')
        const holderPid = Number.parseInt(raw[0] ?? '', 10)
        if (!isProcessAlive(holderPid)) {
          fs.unlinkSync(lockPath)
          continue
        }
      } catch {
        // another waiter may have removed the lock
      }
      await sleep(500)
    }
  }
  throw new Error(`oauth login lock timeout after ${maxWaitMs}ms (${lockPath})`)
}

function rewriteAuthorizeUrlForLab(authorizeUrl: string): string {
  const lab = ['1', 'true', 'yes', 'on'].includes(String(process.env.WPTSALL_LAB ?? '').toLowerCase())
  if (!lab) return authorizeUrl
  try {
    const url = new URL(authorizeUrl)
    const local = new URL(labServerBase())
    if (url.origin !== local.origin) {
      url.protocol = local.protocol
      url.host = local.host
      return url.toString()
    }
  } catch {
    // keep original
  }
  return authorizeUrl
}

async function openClientShell(page: Page): Promise<void> {
  await primeClientUiLocale(page)
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 })
  await ensureClientUiChinese(page)
}

/**
 * Lab/local WebUI login via PKCE form POST (same path as tests/infra/live-3sys/_client_oauth_login.py).
 * Browser popup OAuth is unreliable in headless Playwright (main page often stays on the login overlay).
 */
export async function ensureClientWebUiLoggedIn(
  page: Page,
  request: APIRequestContext,
  opts: { requireServerSearch?: boolean; email?: string; password?: string } = {},
): Promise<void> {
  const email = opts.email ?? DEMO_EMAIL
  const password = opts.password ?? DEMO_PASSWORD
  const probeServerSearch = async (): Promise<boolean> => {
    const probe = await request.get(`${CLIENT_BASE}/api/components/server-search?page=1&per_page=1`)
    const probeJson = await probe.json().catch(() => null)
    return !!probe.ok() && !!probeJson?.success
  }

  const readLoggedIn = async (): Promise<boolean> => {
    const res = await request.get(`${CLIENT_BASE}/api/status`)
    expect(res.ok(), `client status HTTP ${res.status()}`).toBeTruthy()
    const json = await res.json().catch(() => null)
    return !!json?.data?.logged_in
  }

  let loggedIn = await readLoggedIn()
  if (loggedIn) {
    const searchOk = !opts.requireServerSearch || (await probeServerSearch())
    if (searchOk) {
      await openClientShell(page)
      return
    }
    console.warn('  Lab OAuth: server-search probe failed; re-auth once')
    await request.post(`${CLIENT_BASE}/api/logout`).catch(() => null)
    loggedIn = false
  }

  if (!loggedIn) {
    await withOAuthLoginLock(async () => {
      // Another lane may have logged this client in while we waited for the lock.
      loggedIn = await readLoggedIn()
      if (loggedIn) return

      const startRes = await request.post(`${CLIENT_BASE}/api/oauth/start`, { data: {} })
      expect(startRes.ok(), `oauth/start HTTP ${startRes.status()}`).toBeTruthy()
      const startJson = await startRes.json()
      let authorizeUrl = String(startJson?.data?.authorize_url ?? '')
      authorizeUrl = rewriteAuthorizeUrlForLab(authorizeUrl)
      expect(authorizeUrl, 'oauth/start missing authorize_url').not.toBe('')

      const authorize = new URL(authorizeUrl)
      const deviceId =
        authorize.searchParams.get('device_id')?.trim() ||
        e2eLabDeviceId() ||
        ''
      const form: Record<string, string> = {
        email,
        password,
        redirect_uri: authorize.searchParams.get('redirect_uri') ?? '',
        code_challenge: authorize.searchParams.get('code_challenge') ?? '',
        code_challenge_method: authorize.searchParams.get('code_challenge_method') ?? '',
        state: authorize.searchParams.get('state') ?? '',
        client_id: authorize.searchParams.get('client_id') ?? '',
        locale: 'en',
      }
      if (deviceId) {
        form.device_id = deviceId
      }
      for (const [key, value] of Object.entries(form)) {
        expect(value, `oauth authorize form missing ${key}`).not.toBe('')
      }

      const authorizeEndpoint = `${authorize.origin}/oauth/authorize`
      const maxAttempts = Number(process.env.WPTSALL_E2E_OAUTH_MAX_ATTEMPTS ?? 8)
      let authRes: Awaited<ReturnType<APIRequestContext['post']>> | null = null
      for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        // Follow 303 → client /oauth/callback so the WebUI completes token exchange.
        authRes = await request.post(authorizeEndpoint, {
          form,
          maxRedirects: 10,
          failOnStatusCode: false,
        })
        if (authRes.ok() || authRes.status() === 200) break

        const body = await authRes.text()
        const rateLimited =
          authRes.status() === 400 &&
          /too many login attempts/i.test(body)
        if (rateLimited && attempt < maxAttempts) {
          const waitMs = Math.min(30_000, 3_000 * attempt)
          console.warn(
            `  oauth authorize rate limited (attempt ${attempt}/${maxAttempts}); retry in ${waitMs}ms`,
          )
          await sleep(waitMs)
          continue
        }

        expect(
          authRes.ok() || authRes.status() === 200,
          `oauth authorize/callback HTTP ${authRes.status()} body=${body.slice(0, 200)}`,
        ).toBeTruthy()
      }

      loggedIn = await readLoggedIn()
      expect(loggedIn, 'OAuth login did not complete via API PKCE').toBeTruthy()
    })
  }

  if (opts.requireServerSearch) {
    expect(await probeServerSearch(), 'server-search unavailable after login').toBeTruthy()
  }

  await openClientShell(page)
}
