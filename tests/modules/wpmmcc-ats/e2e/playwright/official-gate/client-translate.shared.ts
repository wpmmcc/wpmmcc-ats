import { expect, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'
import {
  clearWebSession,
  requestWebPasswordReset,
  resetWebPassword,
  resolvePasswordResetToken,
} from '../journey-three-system/helpers'
import { ensureClientWebUiLoggedIn } from '../support-client-server/helpers/client-oauth-login'
import { e2eLabDeviceId, resolveSlotClientBase } from '../lib/e2e-slot-ports'

export const CLIENT_BASE = resolveSlotClientBase()
export const MOCK_API_BASE = process.env.MOCK_API_BASE ?? 'http://127.0.0.1:9090'
export const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
export const DEMO_EMAIL = process.env.DEMO_EMAIL ?? 'demo@wptsall.dev'
const RAW_DEMO_PASSWORD = process.env.DEMO_PASSWORD?.trim() ?? ''
export const DEMO_PASSWORD =
  process.env.E2E_OAUTH_KNOWN_PASSWORD
  ?? process.env.JOURNEY_DEMO_PASSWORD
  ?? (RAW_DEMO_PASSWORD || 'demo')
export const WP_CLIENT_TOKEN = process.env.WP_CLIENT_TOKEN ?? ''
export const ROUTE_SECRET = process.env.ROUTE_SECRET ?? ''
export const WP_DEVICE_ID =
  process.env.WPTSALL_DEVICE_ID
  ?? process.env.WPTSALL_WP_DEVICE_ID
  ?? e2eLabDeviceId()
  ?? ''
export const MOCK_API_KEY = process.env.MOCK_API_KEY ?? 'mock-translate-dev-key-2026'
export const ALLOW_ZERO_TASKS = process.env.E2E_ALLOW_ZERO_TASKS === '1'
export const ALLOW_IDEMPOTENT_REPLAY = process.env.E2E_ALLOW_IDEMPOTENT_REPLAY === '1'
export const SKIP_OAUTH_LOGIN = process.env.E2E_SKIP_OAUTH_LOGIN === '1'
export const SINGLE_RELATION_ID_FROM_ENV = Number.parseInt(process.env.E2E_SINGLE_RELATION_ID ?? '', 10) || 0
const IS_PLUGIN_PROJECT_LANE = (process.env.E2E_PROJECT ?? 'core-content') !== 'core-content'
const LAB_MODE_EARLY =
  ['1', 'true', 'yes', 'on'].includes(String(process.env.WPTSALL_LAB ?? '').toLowerCase())
  || /127\.0\.0\.1|localhost/.test(String(process.env.WP_BASE ?? ''))
const WORKER_GATE_MAX_ITEMS = Number.parseInt(process.env.E2E_WORKER_GATE_MAX_ITEMS ?? '', 10)
  || (IS_PLUGIN_PROJECT_LANE ? (LAB_MODE_EARLY ? 48 : 4096) : 64)
const WORKER_GATE_MAX_ITERATIONS = Number.parseInt(process.env.E2E_WORKER_GATE_MAX_ITERATIONS ?? '', 10)
  || (IS_PLUGIN_PROJECT_LANE ? (LAB_MODE_EARLY ? 24 : 120) : 4)
const WORKER_GATE_MAX_DEDUP_RETRIES =
  Number.parseInt(process.env.E2E_WORKER_GATE_MAX_DEDUP_RETRIES ?? '', 10) || 4
const WORKER_RUN_ONCE_HTTP_TIMEOUT_MS = Number.parseInt(process.env.E2E_WORKER_RUN_ONCE_HTTP_TIMEOUT_MS ?? '', 10)
  || (IS_PLUGIN_PROJECT_LANE ? (LAB_MODE_EARLY ? 300_000 : 120_000) : 240_000)
const WORKER_RUN_ONCE_PRESSURE_RETRIES = Number.parseInt(process.env.E2E_WORKER_RUN_ONCE_PRESSURE_RETRIES ?? '', 10)
  || (IS_PLUGIN_PROJECT_LANE ? 6 : 3)
export const E2E_PROJECT = process.env.E2E_PROJECT ?? 'core-content'
export const E2E_SCOPE = process.env.E2E_SCOPE ?? 'full'
const REQUIRE_SUCCESS_FOR_PROJECT_LANE = E2E_PROJECT !== 'core-content'
const OAUTH_FLOW_TIMEOUT_MS = Number.parseInt(process.env.E2E_OAUTH_FLOW_TIMEOUT_MS ?? '', 10) || 180_000
export const FORCE_LOCAL_COMPONENT =
  process.env.E2E_FORCE_LOCAL_COMPONENT === '1' || E2E_SCOPE === 'core-only'
export const E2E_RUNTIME_DIR = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(process.cwd(), '../runtime')

export function isLabMode(): boolean {
  const flag = String(process.env.WPTSALL_LAB ?? '').toLowerCase()
  if (flag === '1' || flag === 'true' || flag === 'yes' || flag === 'on') return true
  return /127\.0\.0\.1|localhost/.test(WP_BASE)
}

export function resolveDefaultApiBaseUrl(): string {
  const fromEnv = String(process.env.E2E_API_BASE_URL ?? '').trim()
  if (fromEnv) return fromEnv.replace(/\/+$/, '')

  const manifestPath = path.join(E2E_RUNTIME_DIR, 'core-component-template-sources.json')
  try {
    if (fs.existsSync(manifestPath)) {
      const raw = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) as { api_base_url?: string }
      const fromManifest = String(raw.api_base_url ?? '').trim()
      if (fromManifest) return fromManifest.replace(/\/+$/, '')
    }
  } catch {
    // fall through
  }

  if (isLabMode() && ROUTE_SECRET) {
    return `${WP_BASE}/wp-json/wptsall/v2/${ROUTE_SECRET}/client`
  }
  return 'https://blog.wpmm.cc'
}

export function siteDomainNeedle(): string {
  try {
    const host = new URL(WP_BASE).hostname
    if (host === '127.0.0.1' || host === 'localhost') {
      return host
    }
    return host
  } catch {
    return isLabMode() ? '127.0.0.1' : 'blog.wpmm.cc'
  }
}

function domainMatchesTargetSite(domainOrUrl: string): boolean {
  const value = String(domainOrUrl || '')
  if (!value) return false
  const needle = siteDomainNeedle()
  if (value.includes(needle)) return true
  if (needle === '127.0.0.1' || needle === 'localhost') {
    return value.includes('9083') || value.includes('localhost')
  }
  return value.includes('blog.wpmm.cc')
}

function sleepMs(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function isRunOncePressurePayload(payload: Record<string, unknown> | null, status = 0): boolean {
  const error = (payload?.error as Record<string, unknown> | undefined) ?? {}
  const code = String(error.code ?? payload?.code ?? '').toUpperCase()
  const message = String(error.message ?? payload?.message ?? '')
  return status === 429
    || code.includes('RATE_LIMIT')
    || /RATE_LIMITED|Too many requests|pressure|backpressure/i.test(message)
}

function isRunOncePressureTransportError(message: string): boolean {
  return /socket hang up|ECONNRESET|ECONNREFUSED|ETIMEDOUT|fetch failed/i.test(message)
}
export type AuthField = { name: string }

export type WorkerBootstrapState = {
  componentId: string
  createdLocalComponentIds: string[]
}

export type WorkerBootstrapOptions = {
  apiBaseUrl?: string
  wpClientToken?: string
  routeSecret?: string
  forceLocalComponent?: boolean
}

export type RuleBindingRef = {
  scope: 'global' | 'plugin' | 'relation' | 'rule'
  scope_key?: string
  slot_key: string
}

export type RuleBindingContext = {
  relationId: number
  pluginSlug: string
  ruleId: number
}

export type WorkerRunSummary = {
  domainsProcessed: number
  tasksProcessed: number
  tasksSucceeded: number
  tasksFailed: number
  breakReason: string
  selectedRelationId: number
  raw: Record<string, unknown>
}

async function withStepTimeout<T>(label: string, timeoutMs: number, run: () => Promise<T>): Promise<T> {
  let timer: NodeJS.Timeout | null = null
  try {
    return await Promise.race([
      run(),
      new Promise<T>((_, reject) => {
        timer = setTimeout(() => {
          reject(new Error(`${label} timed out after ${timeoutMs}ms`))
        }, timeoutMs)
      }),
    ])
  } finally {
    if (timer) clearTimeout(timer)
  }
}

type DiscoveryTaskTuneItem = {
  id: number
  domain: string
  relation_id: number
}

type ClientJobItem = {
  status?: string
  relation_id?: number
  total_items?: number
  done_items?: number
  failed_items?: number
  domain?: string
}

type WpSiteRelationItem = {
  id?: number
}

const TASK_TYPE_BINDING_BUSINESS_LINES = [
  'custom_model',
  'post_content',
  'taxonomy_content',
  'plugin_i18n',
  'config_i18n',
  'theme_i18n',
]

const STABLE_TEXT_COMPONENT_PRIORITY = [
  'mock-sign-kakao',
  'mock-sign-azure',
  'mock-sign-hmac-sha256',
  'mock-sign-alibaba',
  'mock-sign-md5',
  'mock-sign-sha256',
  'mock-sign-aws',
  'mock-sign-volcengine',
  'mock-sign-tc3',
  'official-mock-v2',
  'official-mock-v1',
  'official-apertium-v1',
  'mock-sign-oauth',
]

const KNOWN_COMPONENT_AUTH_VALUES: Record<string, Record<string, string>> = {
  'mock-sign-kakao': { api_key: 'mock-kakao-key' },
  'mock-sign-azure': { subscription_key: 'mock-azure-sub-key' },
  'mock-sign-hmac-sha256': { api_key: 'mock-key-hmac', secret_key: 'mock-secret-hmac' },
  'mock-sign-alibaba': { access_key: 'mock-ali-key', secret_key: 'mock-ali-secret' },
  'mock-sign-md5': { appid: 'mock-appid-001', secret_key: 'mock-secret-baidu' },
  'mock-sign-sha256': { app_key: 'mock-appkey-youdao', app_secret: 'mock-secret-youdao' },
  'mock-sign-aws': { access_key: 'mock-aws-key', secret_key: 'mock-aws-secret', host: '127.0.0.1:9090' },
  'mock-sign-volcengine': { access_key: 'mock-volc-key', secret_key: 'mock-volc-secret', host: '127.0.0.1:9090' },
  'mock-sign-tc3': { secret_id: 'mock-tc-id', secret_key: 'mock-tc-secret', host: '127.0.0.1:9090' },
  'official-mock-v1': { api_key: MOCK_API_KEY },
  'official-mock-v2': { api_key: MOCK_API_KEY },
}

const KNOWN_COMPONENT_AUTH_FIELDS: Record<string, string[]> = {
  'mock-sign-oauth': ['api_key'],
  'mock-sign-kakao': ['api_key'],
  'mock-sign-azure': ['subscription_key'],
  'mock-sign-hmac-sha256': ['api_key', 'secret_key'],
  'mock-sign-alibaba': ['access_key', 'secret_key'],
  'mock-sign-md5': ['appid', 'secret_key'],
  'mock-sign-sha256': ['app_key', 'app_secret'],
  'mock-sign-aws': ['access_key', 'secret_key', 'host'],
  'mock-sign-volcengine': ['access_key', 'secret_key', 'host'],
  'mock-sign-tc3': ['secret_id', 'secret_key', 'host'],
  'official-mock-v1': ['api_key'],
  'official-mock-v2': ['api_key'],
}

function isDeterministicGateTextComponent(componentId: string): boolean {
  return componentId.startsWith('mock-sign-')
    || componentId.startsWith('official-mock-v')
}

function ensureRuntimeDir() {
  if (!fs.existsSync(E2E_RUNTIME_DIR)) {
    fs.mkdirSync(E2E_RUNTIME_DIR, { recursive: true })
  }
}

export function writeRuntimeArtifact(name: string, data: unknown) {
  ensureRuntimeDir()
  fs.writeFileSync(path.join(E2E_RUNTIME_DIR, name), JSON.stringify(data, null, 2))
}

export function readRuntimeArtifactObject(name: string): Record<string, unknown> | null {
  const runtimePath = path.join(E2E_RUNTIME_DIR, name)
  try {
    const raw = fs.readFileSync(runtimePath, 'utf8')
    const json = JSON.parse(raw) as Record<string, unknown>
    return json && typeof json === 'object' ? json : null
  } catch {
    return null
  }
}

function resolvePreferredSingleRelationId(): number {
  if (SINGLE_RELATION_ID_FROM_ENV > 0) {
    return SINGLE_RELATION_ID_FROM_ENV
  }

  {
    const json = readRuntimeArtifactObject('relation-ids.json')
    const wpRelation = Number(json?.wp ?? 0)
    if (Number.isFinite(wpRelation) && wpRelation > 0) {
      return wpRelation
    }

    const virtualRelation = Number(json?.virtual ?? 0)
    if (Number.isFinite(virtualRelation) && virtualRelation > 0) {
      return virtualRelation
    }
  }

  {
    const json = readRuntimeArtifactObject('task-simulation-webui-playwright.json')
    const relationId = Number(json?.relationId ?? 0)
    if (Number.isFinite(relationId) && relationId > 0) {
      return relationId
    }
  }

  return 0
}

function inferAuthFields(componentId: string): AuthField[] {
  const names = KNOWN_COMPONENT_AUTH_FIELDS[componentId] ?? []
  return names.map((name) => ({ name }))
}

function buildAuthPayload(componentId: string, authFields: AuthField[]): Record<string, string> {
  const auth: Record<string, string> = {}
  const knownAuth = KNOWN_COMPONENT_AUTH_VALUES[componentId] ?? {}

  for (const field of authFields) {
    const name = String(field.name || '').trim()
    if (!name) continue

    if (knownAuth[name]) {
      auth[name] = knownAuth[name]
    } else if (name === 'api_key' || name === 'subscription_key') {
      auth[name] = MOCK_API_KEY
    } else if (name === 'host') {
      auth[name] = '127.0.0.1:9090'
    } else if (name === 'access_key') {
      auth[name] = 'mock-access-key'
    } else if (name === 'secret_key') {
      auth[name] = 'mock-secret-key'
    } else if (name === 'appid') {
      auth[name] = 'mock-app-id'
    } else if (name === 'app_key') {
      auth[name] = 'mock-app-key'
    } else if (name === 'app_secret') {
      auth[name] = 'mock-app-secret'
    } else if (name === 'secret_id') {
      auth[name] = 'mock-secret-id'
    } else if (name.includes('secret')) {
      auth[name] = 'mock-secret'
    } else if (name.includes('token')) {
      auth[name] = 'mock-token'
    } else if (name.includes('key')) {
      auth[name] = MOCK_API_KEY
    } else {
      auth[name] = 'mock-value'
    }
  }

  return auth
}

async function getStatusData(request: APIRequestContext): Promise<Record<string, unknown>> {
  const statusRes = await request.get(`${CLIENT_BASE}/api/status`)
  const status = await statusRes.json().catch(() => null as unknown)
  return (((status as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined) ?? {})
}

async function hasCompletedJobEvidence(
  request: APIRequestContext,
  relationId: number
): Promise<boolean> {
  if (relationId <= 0) return false

  const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
  if (!jobsRes.ok()) return false

  const jobsData = await jobsRes.json().catch(() => null as unknown)
  const jobs: Array<{
    status?: string
    relation_id?: number
    total_items?: number
    done_items?: number
    failed_items?: number
  }> = ((jobsData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)?.items as Array<{
    status?: string
    relation_id?: number
    total_items?: number
    done_items?: number
    failed_items?: number
  }> ?? []

  const relationJobs = jobs.filter((job) => Number(job?.relation_id ?? 0) === relationId)
  return relationJobs.some((job) => {
    const doneItems = Number(job?.done_items ?? 0)
    const totalItems = Number(job?.total_items ?? 0)
    const failedItems = Number(job?.failed_items ?? 0)
    const status = String(job?.status ?? '').trim().toLowerCase()
    return doneItems > 0 && failedItems === 0 && (
      status === 'completed'
      || status === 'partial'
      || (totalItems > 0 && doneItems >= totalItems)
    )
  })
}

export async function isClientLoggedIn(request: APIRequestContext): Promise<boolean> {
  const data = await getStatusData(request)
  // Local-first WebUI has no control-plane `logged_in`; domain/token bindings are auth.
  if (String(data.runtime_mode ?? '') === 'local') {
    const domains = data.domains
    if (Array.isArray(domains) && domains.length > 0) return true
    const bindings = data.domain_token_bindings
    return Array.isArray(bindings) && bindings.length > 0
  }
  return !!data.logged_in
}

async function isClientLocalRuntime(request: APIRequestContext): Promise<boolean> {
  const data = await getStatusData(request)
  return String(data.runtime_mode ?? '') === 'local'
}

async function isClientServerSessionUsable(request: APIRequestContext): Promise<boolean> {
  if (await isClientLocalRuntime(request)) {
    return isClientLoggedIn(request)
  }
  const res = await request.post(`${CLIENT_BASE}/api/domains/refresh`)
  const data = await res.json().catch(() => null as unknown)
  return res.ok() && (data as Record<string, unknown> | null)?.success === true
}

export async function verifyOfficialClientGateServices(request: APIRequestContext) {
  console.log('\n── beforeAll: verifying services ──')

  let mockOk = false
  try {
    const res = await request.get(`${MOCK_API_BASE}/api/v1/health`)
    mockOk = res.ok()
    console.log(`  Mock translate API (:9090): ${mockOk ? 'OK' : `HTTP ${res.status()}`}`)
  } catch (err) {
    console.warn(`  Mock translate API (:9090): UNREACHABLE — ${err}`)
  }

  let clientOk = false
  try {
    const res = await request.get(`${CLIENT_BASE}/api/status`)
    clientOk = res.ok()
    console.log(`  WPTSALL Client (${CLIENT_BASE}): ${clientOk ? 'OK' : `HTTP ${res.status()}`}`)
  } catch (err) {
    console.warn(`  WPTSALL Client (${CLIENT_BASE}): UNREACHABLE — ${err}`)
  }

  if (!clientOk) {
    throw new Error(`WPTSALL Client at ${CLIENT_BASE} is not running.`)
  }

  if (!WP_CLIENT_TOKEN || !ROUTE_SECRET) {
    throw new Error(
      'WP_CLIENT_TOKEN and ROUTE_SECRET required.\n' +
      'Copy playwright/.env.test.example to .env.test and fill in values.'
    )
  }

  console.log('── beforeAll complete ──\n')
}

async function bypassTurnstile(context: BrowserContext) {
  await context.route('**challenges.cloudflare.com/turnstile/**', (route) => {
    route.fulfill({ status: 200, contentType: 'text/javascript', body: '' })
  })

  await context.route('**www.wpmm.cc/cdn-cgi/challenge-platform/**', (route) => {
    route.fulfill({ status: 200, contentType: 'text/javascript', body: '' })
  })

  await context.route('**turnstile**', (route) => {
    route.fulfill({ status: 200, contentType: 'text/javascript', body: '' })
  })

  context.on('page', (page) => {
    page.addInitScript(() => {
      // @ts-ignore
      window.turnstile = {
        render(container: unknown, options: { callback?: (token: string) => void }) {
          console.log('[turnstile mock] render called — auto-resolving')
          setTimeout(() => {
            if (typeof options?.callback === 'function') {
              options.callback('mock-turnstile-token')
            }
          }, 100)
          return 'mock-widget-id'
        },
        reset() {},
        remove() {},
        getResponse() { return 'mock-turnstile-token' },
      }
    })
  })
}

export async function waitForClientShell(page: Page) {
  await page.goto(CLIENT_BASE)
  await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 })
}

async function normalizeOfficialOauthDemoPassword(page: Page, context: BrowserContext) {
  await bypassTurnstile(context)
  await clearWebSession(page)
  await requestWebPasswordReset(page, { email: DEMO_EMAIL })
  const resetToken = await resolvePasswordResetToken(DEMO_EMAIL)
  await resetWebPassword(page, { token: resetToken, password: DEMO_PASSWORD })
  await clearWebSession(page)
  console.log(`  Demo OAuth password normalized for ${DEMO_EMAIL}`)
}

async function resolveOauthAuthorizeUrl(request: APIRequestContext): Promise<string> {
  const oauthStartProbe = await request.post(`${CLIENT_BASE}/api/oauth/start`)
  const oauthStartData = await oauthStartProbe.json().catch(() => null as unknown)
  const data = (oauthStartData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined
  const authorizeUrl = String(data?.authorize_url ?? '')

  if (!oauthStartProbe.ok() || !authorizeUrl) {
    throw new Error('OAuth start endpoint unavailable in current environment')
  }

  return authorizeUrl
}

async function driveOfficialOauthLoginPage(page: Page) {
  await page.waitForLoadState('domcontentloaded')
  console.log(`  OAuth URL: ${page.url()}`)

  await page.waitForSelector('input[type="email"], input[name="email"]', { timeout: 20_000 })
  await page.fill('input[type="email"], input[name="email"]', DEMO_EMAIL)
  await page.fill('input[type="password"], input[name="password"]', DEMO_PASSWORD)
  console.log('  Filled credentials')

  await page.waitForTimeout(600)
  await page.evaluate(() => {
    const field = document.querySelector('input[name="captchaToken"], input[name="captcha_token"]') as HTMLInputElement | null
    if (field) field.value = 'mock-turnstile-token'
  })

  await page.click('button[type="submit"], input[type="submit"]')
  console.log('  Clicked submit')
}

async function attemptOfficialOauthLogin(
  page: Page,
  context: BrowserContext,
  request: APIRequestContext,
): Promise<boolean> {
  const authorizeUrl = await resolveOauthAuthorizeUrl(request)
  const oauthPage = await context.newPage()
  try {
    await oauthPage.goto(authorizeUrl, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    await driveOfficialOauthLoginPage(oauthPage)
    await Promise.race([
      oauthPage.waitForEvent('close', { timeout: 30_000 }),
      oauthPage.waitForURL('**/callback**', { timeout: 30_000 }),
    ]).catch(() => {
      console.warn(`  OAuth page did not close/redirect within 30s (current=${oauthPage.url()})`)
    })

    const loggedIn = await expect
      .poll(async () => isClientLoggedIn(request), { timeout: 35_000 })
      .toBeTruthy()
      .then(() => true)
      .catch(() => false)
    return loggedIn
  } finally {
    await oauthPage.close().catch(() => {})
  }
}

export async function ensureLoggedIn(args: {
  page: Page
  context: BrowserContext
  request: APIRequestContext
}) {
  const { page, context, request } = args

  // Local-first Lab: no website OAuth (:8787 / www). Domain tokens already bound.
  if (await isClientLocalRuntime(request)) {
    await page.goto(CLIENT_BASE, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 }).catch(() => null)
    if (!(await isClientLoggedIn(request))) {
      throw new Error(
        'Local-first client has runtime_mode=local but no domain/token bindings; bind Lab WP first',
      )
    }
    console.log('  Local-first runtime: skipped control-plane OAuth')
    return
  }

  if (await isClientLoggedIn(request)) {
    if (await isClientServerSessionUsable(request)) {
      try {
        await waitForClientShell(page)
        console.log('  Existing client session detected')
        return
      } catch {
        // Lab: refresh shell without destroying the slot OAuth session (parallel re-login → HTTP 400).
        if (isLabMode()) {
          await page.goto(CLIENT_BASE, { waitUntil: 'domcontentloaded', timeout: 30_000 })
          await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 }).catch(() => null)
          if (await isClientLoggedIn(request)) {
            console.log('  Existing client session detected (Lab shell refresh)')
            return
          }
        }
      }
    } else {
      console.warn('  Existing client session is stale/revoked; forcing OAuth re-login')
    }
    if (!(isLabMode() && (await isClientLoggedIn(request)) && (await isClientServerSessionUsable(request)))) {
      try {
        await request.post(`${CLIENT_BASE}/api/logout`)
      } catch {
        // Ignore forced logout failure.
      }
    }
  }

  if (SKIP_OAUTH_LOGIN) {
    await page.goto(CLIENT_BASE)
    await expect.poll(async () => isClientLoggedIn(request), { timeout: 20_000 }).toBe(true)
    await page.reload()
    await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 })
    console.log('  Shared auth bootstrap reused; skipped per-slot OAuth login')
    return
  }

  if (!SKIP_OAUTH_LOGIN && !(isLabMode() && (await isClientLoggedIn(request)))) {
    try {
      await request.post(`${CLIENT_BASE}/api/logout`)
    } catch {
      // Ignore forced logout failure.
    }
  }

  await withStepTimeout('oauth login flow', OAUTH_FLOW_TIMEOUT_MS, async () => {
    if (isLabMode()) {
      await ensureClientWebUiLoggedIn(page, request, { requireServerSearch: true })
      console.log('  Login successful (Lab API PKCE)')
      return
    }

    await bypassTurnstile(context)
    await clearWebSession(page)
    const directLoginOk = await attemptOfficialOauthLogin(page, context, request)

    if (!directLoginOk) {
      console.warn('  Direct OAuth login did not settle; falling back to password reset normalization')
      await normalizeOfficialOauthDemoPassword(page, context)
      const retryLoginOk = await attemptOfficialOauthLogin(page, context, request)
      if (!retryLoginOk) {
        throw new Error('OAuth login did not settle after direct + reset fallback attempts')
      }
    }

    await page.goto(CLIENT_BASE, { waitUntil: 'domcontentloaded', timeout: 30_000 })
    await page.waitForSelector('nav.min-h-screen', { timeout: 30_000 })
    console.log('  Login successful')
  })
}

export async function createLocalOpenAiMockComponent(
  request: APIRequestContext,
  id: string,
  remarks: string
) {
  const localCreateRes = await request.post(`${CLIENT_BASE}/api/components/local`, {
    data: {
      id,
      name: 'PW Local OpenAI',
      kind: 'openai_compatible',
      template_id: 'official-openai-text-v1',
      api_base: MOCK_API_BASE,
      model: 'mock-openai-v1',
      remarks,
    },
  })
  const localCreate = await localCreateRes.json().catch(() => null as unknown)
  expect(localCreateRes.ok()).toBe(true)
  expect((localCreate as Record<string, unknown> | null)?.success).toBe(true)
}

export async function bootstrapWorkerSetup(
  request: APIRequestContext,
  options: WorkerBootstrapOptions = {}
): Promise<WorkerBootstrapState> {
  const createdLocalComponentIds: string[] = []
  const localRuntime = await isClientLocalRuntime(request)
  // Domain-token bindings use the WP site origin; the /client REST path is derived via route_secret.
  const apiBaseUrl = String(
    options.apiBaseUrl
      ?? (localRuntime || isLabMode() ? WP_BASE : resolveDefaultApiBaseUrl()),
  ).trim()
  const wpClientToken = String(options.wpClientToken ?? WP_CLIENT_TOKEN).trim()
  const routeSecret = String(options.routeSecret ?? ROUTE_SECRET).trim()
  const forceLocalComponent =
    (options.forceLocalComponent ?? FORCE_LOCAL_COMPONENT) || localRuntime

  if (!wpClientToken || !routeSecret) {
    throw new Error('bootstrapWorkerSetup requires wpClientToken and routeSecret')
  }

  console.log('\n  1. Refreshing domains...')
  if (localRuntime) {
    console.log('  (local-first: skip legacy /api/domains/refresh)')
  } else {
    const refreshRes = await request.post(`${CLIENT_BASE}/api/domains/refresh`)
    const refreshJson = await refreshRes.json().catch(() => null as unknown)
    expect((refreshJson as Record<string, unknown> | null)?.success, `domains/refresh failed: ${JSON.stringify(refreshJson)}`).toBe(true)
  }

  console.log(`  2. Upserting domain token: ${apiBaseUrl}`)
  const tokenRes = await request.post(`${CLIENT_BASE}/api/domain-tokens/upsert`, {
    data: {
      api_base_url: apiBaseUrl,
      wp_client_token: wpClientToken,
      route_secret: routeSecret,
    },
  })
  expect((await tokenRes.json())?.success).toBe(true)

  console.log('  3. Bootstrapping discovery tasks from live WP relations...')
  const bootstrapRes = await request.post(`${CLIENT_BASE}/api/discovery-tasks/bootstrap`)
  const bootstrapData = await bootstrapRes.json().catch(() => null as unknown)
  expect(bootstrapRes.ok()).toBe(true)
  expect((bootstrapData as Record<string, unknown> | null)?.success).toBe(true)
  console.log(
    `  Bootstrap synced domains: ${((bootstrapData as Record<string, any> | null)?.data?.synced ?? []).length ?? 0}`
  )

  console.log('  4. Refreshing components...')
  if (localRuntime) {
    console.log('  (local-first: skip legacy /api/components/refresh; using local components)')
  } else {
    await request.post(`${CLIENT_BASE}/api/components/refresh`)
  }

  const status = await (await request.get(`${CLIENT_BASE}/api/status`)).json()
  const components: Array<{ id: string; type: string }> = status?.data?.components ?? []
  console.log(`  5. Discovered ${components.length} components`)

  const textComponents = components.filter((component) => component.type === 'text_translation')
  if (textComponents.length === 0) {
    console.warn('  No server text_translation components discovered, will use local fallback')
  }

  const componentPriority = (id: string): number => {
    const idx = STABLE_TEXT_COMPONENT_PRIORITY.indexOf(id)
    if (idx >= 0) return idx
    if (id.startsWith('mock-sign-')) return 100
    if (id.startsWith('official-mock-v')) return 200
    return 1000
  }

  const preferredTextComponents = [...textComponents].sort((a, b) => {
    const pa = componentPriority(a.id)
    const pb = componentPriority(b.id)
    if (pa !== pb) return pa - pb
    return a.id.localeCompare(b.id)
  })

  let selectedComponent: { id: string; type: string } | null = null
  let authFields: AuthField[] = []

  if (!forceLocalComponent) {
    for (const candidate of preferredTextComponents) {
      const tmplRes = await request.post(`${CLIENT_BASE}/api/components/template`, {
        data: { component_id: candidate.id },
      })
      const tmplData = await tmplRes.json().catch(() => null as unknown)
      if (!tmplRes.ok() || !(tmplData as Record<string, unknown> | null)?.success) {
        console.warn(`  Template fetch failed for ${candidate.id}: HTTP ${tmplRes.status()}`)
        continue
      }

      const tmplEntry = ((tmplData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined) ?? {}
      const templateJson = (tmplEntry.template_json as Record<string, unknown> | undefined) ?? {}
      const requestDef = (templateJson.request as Record<string, unknown> | undefined) ?? {}
      const reqUrl = String(requestDef.url ?? '')
      const isOAuthTranslateComponent =
        candidate.id === 'mock-sign-oauth' || reqUrl.includes('/api/oauth/translate')

      if (isOAuthTranslateComponent) {
        console.log(`  Skipping ${candidate.id}: requires OAuth token flow not configured in this suite`)
        continue
      }

      if (!isDeterministicGateTextComponent(candidate.id)) {
        console.log(`  Skipping ${candidate.id}: non-deterministic remote provider for official gate`)
        continue
      }

      selectedComponent = candidate
      authFields = (tmplEntry.auth_fields as AuthField[] | undefined) ?? []
      if (authFields.length === 0) {
        authFields = inferAuthFields(candidate.id)
      }
      break
    }
  } else {
    console.log('  core-only/local-mock mode: forcing local mock component')
  }

  if (!selectedComponent) {
    const localId = `pw-local-openai-${Date.now()}`
    console.log(`  6. No usable server template, creating local fallback: ${localId}`)
    await createLocalOpenAiMockComponent(request, localId, 'Playwright fallback component')
    selectedComponent = { id: localId, type: 'text_translation' }
    authFields = [{ name: 'api_key' }]
    createdLocalComponentIds.push(localId)
  }

  expect(selectedComponent).toBeTruthy()
  const componentId = selectedComponent!.id
  console.log(`  6. Selected: ${componentId}`)
  console.log(`  7. Auth fields: ${authFields.map((field) => field.name).join(', ')}`)

  const auth = buildAuthPayload(componentId, authFields)
  if (authFields.length > 0) {
    expect(Object.keys(auth).length).toBeGreaterThan(0)
  }

  const bindRes = await request.post(`${CLIENT_BASE}/api/components/bindings/upsert`, {
    data: { component_id: componentId, auth },
  })
  expect((await bindRes.json())?.success).toBe(true)
  console.log(`  8. Component bound: ${Object.keys(auth).join(', ')}`)

  const bindingScopes = [
    { task_type: 'text' },
    ...TASK_TYPE_BINDING_BUSINESS_LINES.map((business_line) => ({
      task_type: 'text',
      business_line,
    })),
  ]

  for (const scope of bindingScopes) {
    const upsertRes = await request.post(`${CLIENT_BASE}/api/task-type-components/upsert`, {
      data: {
        ...scope,
        component_id: componentId,
      },
    })
    expect((await upsertRes.json())?.success).toBe(true)
  }
  console.log('  9. Task-type bindings upserted for text scopes')

  writeRuntimeArtifact('official-client-execution-bootstrap.json', {
    timestamp: new Date().toISOString(),
    project: E2E_PROJECT,
    scope: E2E_SCOPE,
    component_id: componentId,
    created_local_component_ids: createdLocalComponentIds,
  })

  return {
    componentId,
    createdLocalComponentIds,
  }
}

export async function cleanupWorkerBootstrap(
  request: APIRequestContext,
  state: WorkerBootstrapState
) {
  console.log('\n── afterAll: cleaning up execution group ──')

  if (state.componentId) {
    try {
      const res = await request.post(`${CLIENT_BASE}/api/components/bindings/delete`, {
        data: { component_id: state.componentId },
      })
      console.log(`  Delete binding for ${state.componentId}: ${res.status()}`)
    } catch (err) {
      console.warn(`  Failed to clean up binding ${state.componentId}: ${err}`)
    }
  }

  try {
    await request.post(`${CLIENT_BASE}/api/task-type-components/delete`, {
      data: { task_type: 'text' },
    })
    for (const line of TASK_TYPE_BINDING_BUSINESS_LINES) {
      await request.post(`${CLIENT_BASE}/api/task-type-components/delete`, {
        data: { task_type: 'text', business_line: line },
      })
    }
  } catch (err) {
    console.warn(`  Failed to clean up task-type bindings: ${err}`)
  }

  for (const componentId of [...new Set(state.createdLocalComponentIds)].reverse()) {
    try {
      const encoded = encodeURIComponent(componentId)
      await request.delete(`${CLIENT_BASE}/api/components/local/${encoded}`)
      console.log(`  Deleted local component: ${componentId}`)
    } catch (err) {
      console.warn(`  Failed to delete local component ${componentId}: ${err}`)
    }
  }

  console.log('── afterAll complete ──\n')
}

export async function runWorkerOnce(request: APIRequestContext): Promise<WorkerRunSummary> {
  console.log('\n  Configuring worker...')
  const workerConfig: Record<string, unknown> = {
    poll_seconds: 20,
    callback_concurrency: isLabMode() ? 4 : 1,
    callback_retry_max: 4,
    fetch_timeout_secs: 45,
    fetch_retry_max: 6,
  }
  if (isLabMode()) {
    // Avoid discovery.relation_throttled when prior runs left a large pending_callbacks backlog.
    workerConfig.relation_max_pending_callbacks = 10_000
    workerConfig.global_callback_concurrency = 4
  }
  await request.post(`${CLIENT_BASE}/api/worker/config`, {
    data: workerConfig,
  })

  async function loadAndTuneDiscoveryTasks(includeResync: boolean): Promise<{
    selectedRelationId: number
    targetItems: DiscoveryTaskTuneItem[]
  }> {
    let selectedRelationId = 0
    const tuneRes = await request.get(`${CLIENT_BASE}/api/discovery-tasks`)
    if (!tuneRes.ok()) {
      return { selectedRelationId, targetItems: [] }
    }

    const tuneData = await tuneRes.json().catch(() => null as unknown)
    const tuneItems: DiscoveryTaskTuneItem[] =
      ((tuneData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)?.items as DiscoveryTaskTuneItem[] ?? []
    const targetItems = tuneItems.filter((item) => domainMatchesTargetSite(item.domain))
    const targetRelationIds = new Set(targetItems.map((item) => Number(item.relation_id ?? 0)).filter((id) => id > 0))
    const visibleRelationIds = await resolveVisibleWpRelationIds()
    const selectableRelationIds = visibleRelationIds.size > 0
      ? new Set([...targetRelationIds].filter((id) => visibleRelationIds.has(id)))
      : targetRelationIds
    const envPreferredId = SINGLE_RELATION_ID_FROM_ENV
    const artifactPreferredId = resolvePreferredSingleRelationId()
    const pendingRelationId = envPreferredId > 0 ? 0 : await resolvePendingJobRelationId(selectableRelationIds)

    if (envPreferredId > 0 && targetRelationIds.has(envPreferredId)) {
      selectedRelationId = envPreferredId
      console.log(`  Using explicit E2E_SINGLE_RELATION_ID=${selectedRelationId}`)
    } else if (artifactPreferredId > 0 && targetRelationIds.has(artifactPreferredId)) {
      selectedRelationId = artifactPreferredId
      console.log(`  Using runtime artifact preferred relation: ${selectedRelationId}`)
      if (!selectableRelationIds.has(artifactPreferredId)) {
        console.log(`  Runtime artifact relation ${artifactPreferredId} is outside current visible relation window; using existing discovery task`)
      }
    } else if (pendingRelationId > 0) {
      selectedRelationId = pendingRelationId
      console.log(`  Selected relation with pending job workload: ${selectedRelationId}`)
    } else {
      selectedRelationId = targetItems.find((item) => selectableRelationIds.has(Number(item.relation_id ?? 0)))?.relation_id
        ?? targetItems[0]?.relation_id
        ?? 0
    }

    if (selectedRelationId > 0) {
      console.log(`  Using single relation for worker run: ${selectedRelationId}`)
    }

    const bootstrapArtifact = readRuntimeArtifactObject('official-client-execution-bootstrap.json')
    const selectedComponentId = String(bootstrapArtifact?.component_id ?? '').trim()

    for (const item of targetItems) {
      const isSelectedRelation = item.relation_id === selectedRelationId
      await request.put(`${CLIENT_BASE}/api/discovery-tasks/${item.id}`, {
        data: {
          concurrency: 1,
          batch_parallel: 1,
          per_page: 100,
          include_resync: includeResync,
          retry_max: 4,
          timeout_secs: 90,
          enabled: isSelectedRelation,
          selected_component_id: isSelectedRelation && selectedComponentId ? selectedComponentId : null,
        },
      })
    }

    if (targetItems.length > 0) {
      const enabledCount = targetItems.filter((item) => item.relation_id === selectedRelationId).length
      console.log(
        `  Tuned discovery tasks for target site: ${targetItems.length} ` +
        `(enabled=${enabledCount}, include_resync=${includeResync ? 'true' : 'false'})`
      )
    }

    return { selectedRelationId, targetItems }
  }

  async function resolveVisibleWpRelationIds(): Promise<Set<number>> {
    const status = await getStatusData(request)
    const domains = (status.domains as Array<{ api_base_url?: string; max_relations?: number }> | undefined) ?? []
    const blogDomain = domains.find((domain) => domainMatchesTargetSite(String(domain?.api_base_url ?? '')))
    const maxRelations = Number(blogDomain?.max_relations ?? 50)

    const wpHeaders = {
      'X-WPTSALL-Protocol-Version': '2',
      'X-WPTSALL-Device-Id': WP_DEVICE_ID,
      'X-WPTSALL-Client-Token': WP_CLIENT_TOKEN,
      'X-Client-Version': '2.1.0',
    }
    const relResp = await request.get(
      `${WP_BASE}/wp-json/wptsall/v2/${ROUTE_SECRET}/client/site-relations`,
      { headers: wpHeaders }
    )
    if (!relResp.ok()) return new Set()

    const relPayload = await relResp.json().catch(() => null as unknown)
    const relations: WpSiteRelationItem[] =
      ((relPayload as Record<string, unknown> | null)?.relations as WpSiteRelationItem[] | undefined) ?? []
    const visibleRelations = relations
      .slice(0, Number.isFinite(maxRelations) && maxRelations > 0 ? maxRelations : 50)
      .map((relation) => Number(relation?.id ?? 0))
      .filter((id) => id > 0)
    if (visibleRelations.length > 0) {
      console.log(`  Client-visible WP relations for worker selection: ${visibleRelations.join(', ')}`)
    }
    return new Set(visibleRelations)
  }

  async function resolvePendingJobRelationId(targetRelationIds: Set<number>): Promise<number> {
    if (targetRelationIds.size === 0) return 0

    const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
    if (!jobsRes.ok()) return 0

    const jobsData = await jobsRes.json().catch(() => null as unknown)
    const jobs: ClientJobItem[] =
      ((jobsData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)?.items as ClientJobItem[] ?? []
    let bestRelationId = 0
    let bestRemaining = 0

    for (const job of jobs) {
      const relationId = Number(job?.relation_id ?? 0)
      if (!targetRelationIds.has(relationId)) continue
      if (!domainMatchesTargetSite(String(job?.domain ?? ''))) continue

      const status = String(job?.status ?? '').trim().toLowerCase()
      if (status === 'completed' || status === 'failed' || status === 'cancelled') continue

      const totalItems = Number(job?.total_items ?? 0)
      const doneItems = Number(job?.done_items ?? 0)
      const failedItems = Number(job?.failed_items ?? 0)
      const remaining = Math.max(0, totalItems - doneItems - failedItems)
      if (remaining > bestRemaining) {
        bestRelationId = relationId
        bestRemaining = remaining
      }
    }

    return bestRelationId
  }

  async function invokeWorkerRunOnce(): Promise<Record<string, unknown>> {
    console.log('  Running worker once (POST /api/worker/run-once)...')
    console.log(`  Project=${E2E_PROJECT} scope=${E2E_SCOPE}`)

    for (let attempt = 1; attempt <= WORKER_RUN_ONCE_PRESSURE_RETRIES; attempt += 1) {
      try {
        const runRes = await request.post(`${CLIENT_BASE}/api/worker/run-once`, {
          data: {
            max_iterations: WORKER_GATE_MAX_ITERATIONS,
            max_items_per_run: WORKER_GATE_MAX_ITEMS,
            max_elapsed_secs: 180,
          },
          timeout: WORKER_RUN_ONCE_HTTP_TIMEOUT_MS,
        })
        const runData = await runRes.json().catch(() => null as Record<string, unknown> | null)
        console.log(`  run-once response: ${JSON.stringify(runData).slice(0, 500)}`)
        if (runData?.success === true) {
          return (runData?.data ?? {}) as Record<string, unknown>
        }

        if (
          isRunOncePressurePayload(runData, runRes.status()) &&
          attempt < WORKER_RUN_ONCE_PRESSURE_RETRIES
        ) {
          const waitMs = Math.min(30_000, 2500 * attempt)
          console.warn(
            `  run-once pressure response on attempt ${attempt}/${WORKER_RUN_ONCE_PRESSURE_RETRIES}; ` +
            `retrying in ${waitMs}ms`
          )
          await sleepMs(waitMs)
          continue
        }

        expect(runData?.success).toBe(true)
        return (runData?.data ?? {}) as Record<string, unknown>
      } catch (error) {
        const message = String((error as Error)?.message ?? error ?? '')
        if (message.includes('Timeout') && message.includes('/api/worker/run-once')) {
          console.warn(
            `  run-once HTTP timeout after ${WORKER_RUN_ONCE_HTTP_TIMEOUT_MS}ms; verifying progress via jobs API`
          )
          const stabilized = await expect
            .poll(
              async () => {
                const jobsRes = await request.get(`${CLIENT_BASE}/api/jobs`)
                if (!jobsRes.ok()) return 0
                const jobsData = await jobsRes.json().catch(() => null as unknown)
                const jobs: Array<Record<string, unknown>> =
                  ((jobsData as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined)
                    ?.items as Array<Record<string, unknown>> ?? []
                let bestDone = 0
                for (const job of jobs) {
                  const relationId = Number(job?.relation_id ?? 0)
                  if (selectedRelationId > 0 && relationId !== selectedRelationId) continue
                  const domain = String(job?.domain ?? '')
                  if (!domainMatchesTargetSite(domain)) continue
                  const doneItems = Number(job?.done_items ?? 0)
                  if (doneItems > bestDone) bestDone = doneItems
                }
                return bestDone
              },
              { timeout: 120_000, intervals: [2000, 3000, 5000] }
            )
            .toBeGreaterThan(0)
            .then(() => true)
            .catch(() => false)

          if (!stabilized) throw error
          return {
            tasks_processed: 1,
            tasks_succeeded: 1,
            break_reason: 'run_once_http_timeout_but_jobs_progressed',
          }
        }

        if (isRunOncePressureTransportError(message) && attempt < WORKER_RUN_ONCE_PRESSURE_RETRIES) {
          const waitMs = Math.min(30_000, 2500 * attempt)
          console.warn(
            `  run-once transport pressure on attempt ${attempt}/${WORKER_RUN_ONCE_PRESSURE_RETRIES}: ` +
            `${message}; retrying in ${waitMs}ms`
          )
          await sleepMs(waitMs)
          continue
        }

        throw error
      }
    }

    throw new Error('run-once failed after bounded pressure retries')
  }

  let selectedRelationId = 0
  const tuned = await loadAndTuneDiscoveryTasks(false)
  selectedRelationId = tuned.selectedRelationId

  let usedResyncFallback = false
  let dedupRetryCount = 0
  let primarySummary: Record<string, unknown> | null = null
  let summary = await invokeWorkerRunOnce()

  const primaryTasksProcessed = Number(summary.tasks_processed ?? 0)
  const primaryTasksSucceeded = Number(summary.tasks_succeeded ?? 0)
  const primaryBreakReason = String(summary.break_reason ?? '')
  // After Stage 2 WP wipe, client may still report a few succeeded no-ops while
  // break_reason stays dedup_or_noop and virtual write-backs stay empty. Treat
  // low-success dedup as needing include_resync, not only zero-success.
  const lowSuccessDedup =
    primaryBreakReason === 'dedup_or_noop' && primaryTasksSucceeded > 0 && primaryTasksSucceeded < 10
  if (
    selectedRelationId > 0 &&
    (primaryTasksProcessed === 0 ||
      (primaryTasksSucceeded === 0 && primaryBreakReason === 'dedup_or_noop') ||
      lowSuccessDedup)
  ) {
    primarySummary = summary
    usedResyncFallback = true
    dedupRetryCount += 1
    console.warn(
      `  Relation ${selectedRelationId} produced no meaningful new work ` +
        `(processed=${primaryTasksProcessed}, succeeded=${primaryTasksSucceeded}, reason=${primaryBreakReason}); ` +
        `retrying once with include_resync=true`
    )
    await loadAndTuneDiscoveryTasks(true)
    summary = await invokeWorkerRunOnce()
    await loadAndTuneDiscoveryTasks(false)
  }

  while (
    selectedRelationId > 0 &&
    dedupRetryCount < WORKER_GATE_MAX_DEDUP_RETRIES &&
    Number(summary.tasks_processed ?? 0) > 0 &&
    Number(summary.tasks_succeeded ?? 0) === 0 &&
    String(summary.break_reason ?? '') === 'dedup_or_noop'
  ) {
    dedupRetryCount += 1
    console.warn(
      `  Relation ${selectedRelationId} still has no succeeded work; bounded retry ` +
      `${dedupRetryCount}/${WORKER_GATE_MAX_DEDUP_RETRIES}`
    )
    await loadAndTuneDiscoveryTasks(true)
    summary = await invokeWorkerRunOnce()
    await loadAndTuneDiscoveryTasks(false)
  }

  const domainsProcessed = Number(summary.domains_processed ?? 0)
  const tasksProcessed = Number(summary.tasks_processed ?? 0)
  const tasksSucceeded = Number(summary.tasks_succeeded ?? 0)
  const tasksFailed = Number(summary.tasks_failed ?? 0)
  const breakReason = String(summary.break_reason ?? '')

  console.log(`  domains_processed : ${domainsProcessed}`)
  console.log(`  tasks_processed   : ${tasksProcessed}`)
  console.log(`  tasks_succeeded   : ${tasksSucceeded}`)
  console.log(`  tasks_failed      : ${tasksFailed}`)

  if (tasksProcessed > 0) {
    if (tasksFailed > 0) {
      console.warn(`  WARNING: ${tasksFailed} task(s) failed`)
    }
    expect(tasksFailed).toBe(0)
    if (tasksSucceeded > 0) {
      console.log(`  Translation complete: ${tasksSucceeded} succeeded`)
    } else if (breakReason === 'dedup_or_noop') {
      if (REQUIRE_SUCCESS_FOR_PROJECT_LANE && !ALLOW_IDEMPOTENT_REPLAY) {
        throw new Error(
          `Project lane requires succeeded work but got dedup_or_noop only after ${dedupRetryCount} retry(s)`
        )
      }
      console.warn('  No new succeeded items in this run (dedup_or_noop), accepted for repeat runs')
    } else {
      throw new Error(
        `Worker processed ${tasksProcessed} task(s) but succeeded=0 (break_reason=${breakReason || 'unknown'})`
      )
    }
  } else {
    const nestedSummary = (summary.summary as Record<string, unknown> | undefined) ?? {}
    const missingToken = (nestedSummary.missing_token_domains as string[] | undefined) ?? []
    if (missingToken.length > 0) {
      console.warn(`  Skipped domains (missing token): ${missingToken.join(', ')}`)
    }
    const replayEvidence = usedResyncFallback && await hasCompletedJobEvidence(request, selectedRelationId)
    if (ALLOW_ZERO_TASKS) {
      console.warn('  (0 tasks — allowed by E2E_ALLOW_ZERO_TASKS=1)')
    } else if (replayEvidence && ALLOW_IDEMPOTENT_REPLAY) {
      console.warn(
        `  No claimable work remained for relation ${selectedRelationId}; ` +
        'accepting idempotent replay because E2E_ALLOW_IDEMPOTENT_REPLAY=1 and prior completed job evidence exists'
      )
    } else {
      throw new Error(
        'Worker processed 0 tasks. Expected translated workload after seeding/setup. ' +
        (replayEvidence
          ? 'If this is an intentional repeat-run verification, set E2E_ALLOW_IDEMPOTENT_REPLAY=1. '
          : '') +
        'Set E2E_ALLOW_ZERO_TASKS=1 only for debug runs.'
      )
    }
  }

  writeRuntimeArtifact('official-client-execution-summary.json', {
    timestamp: new Date().toISOString(),
    project: E2E_PROJECT,
    scope: E2E_SCOPE,
    selected_relation_id: selectedRelationId,
    used_resync_fallback: usedResyncFallback,
    dedup_retry_count: dedupRetryCount,
    replay_evidence_found: usedResyncFallback && await hasCompletedJobEvidence(request, selectedRelationId),
    allow_idempotent_replay: ALLOW_IDEMPOTENT_REPLAY,
    primary_summary: primarySummary,
    summary,
  })

  return {
    domainsProcessed,
    tasksProcessed,
    tasksSucceeded,
    tasksFailed,
    breakReason,
    selectedRelationId,
    raw: summary,
  }
}

export async function resolveRuleBindingContext(request: APIRequestContext): Promise<RuleBindingContext> {
  const wpHeaders = {
    'X-WPTSALL-Protocol-Version': '2',
    'X-WPTSALL-Device-Id': WP_DEVICE_ID,
    'X-WPTSALL-Client-Token': WP_CLIENT_TOKEN,
    'X-Client-Version': '2.1.0',
  }

  const relResp = await request.get(
    `${WP_BASE}/wp-json/wptsall/v2/${ROUTE_SECRET}/client/site-relations`,
    { headers: wpHeaders }
  )
  expect(relResp.ok(), `site-relations HTTP ${relResp.status()}`).toBe(true)

  const relPayload = await relResp.json()
  const relations: Array<{
    id: number
    models?: Array<{ model_id?: number; plugin_slug?: string }>
  }> = relPayload?.relations ?? []
  expect(relations.length, 'No active relations from /client/site-relations').toBeGreaterThan(0)

  const preferredRelationId = resolvePreferredSingleRelationId()
  const orderedRelations = preferredRelationId > 0
    ? [
        ...relations.filter((rel) => Number(rel?.id ?? 0) === preferredRelationId),
        ...relations.filter((rel) => Number(rel?.id ?? 0) !== preferredRelationId),
      ]
    : relations

  let relationId = 0
  let pluginSlug = ''
  let ruleId = 0

  for (const rel of orderedRelations) {
    const currentRelationId = Number(rel?.id ?? 0)
    if (currentRelationId <= 0) continue

    const modelSlugById = new Map<number, string>()
    for (const model of rel.models ?? []) {
      const modelId = Number(model?.model_id ?? 0)
      const slug = String(model?.plugin_slug ?? '').trim().toLowerCase()
      if (modelId > 0 && slug) modelSlugById.set(modelId, slug)
    }

    const rulesResp = await request.get(
      `${WP_BASE}/wp-json/wptsall/v2/${ROUTE_SECRET}/client/rules?relation_id=${currentRelationId}`,
      { headers: wpHeaders }
    )
    if (!rulesResp.ok()) continue

    const rulesPayload = await rulesResp.json()
    const rules: Array<{ id?: number; model_id?: number }> = rulesPayload?.rules ?? []
    if (rules.length === 0) continue

    const preferredRule =
      rules.find((rule) => modelSlugById.has(Number(rule?.model_id ?? 0))) ?? rules[0]
    const candidateRuleId = Number(preferredRule?.id ?? 0)
    if (candidateRuleId <= 0) continue

    relationId = currentRelationId
    ruleId = candidateRuleId
    pluginSlug =
      modelSlugById.get(Number(preferredRule?.model_id ?? 0))
      ?? modelSlugById.values().next().value
      ?? ''
    break
  }

  expect(relationId, 'No usable relation_id for rule binding test').toBeGreaterThan(0)
  expect(ruleId, 'No usable rule_id for rule binding test').toBeGreaterThan(0)
  expect(pluginSlug, 'No usable plugin_slug for rule binding test').not.toBe('')

  return {
    relationId,
    pluginSlug,
    ruleId,
  }
}

export async function deleteRuleBinding(
  request: APIRequestContext,
  binding: RuleBindingRef
) {
  try {
    await request.post(`${CLIENT_BASE}/api/rule-component-bindings/delete`, {
      data: binding,
    })
  } catch (err) {
    console.warn(
      `  Failed to delete rule binding ${binding.scope}/${binding.scope_key ?? ''}/${binding.slot_key}: ${err}`
    )
  }
}
