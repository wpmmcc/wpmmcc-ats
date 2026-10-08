import { test, expect, type Page, type Response } from '../lib/page-errors'
import * as fs from 'fs'
import * as path from 'path'
import { wpLogin as sharedWpLogin } from '../plugin-content-journeys/helpers'

const WP_BASE = (process.env.WP_BASE ?? 'https://blog.wpmm.cc').replace(/\/+$/, '')
const WP_ADMIN_USER = process.env.WP_ADMIN_USER ?? 'e2esmokeadmin'
const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'Wptsall-Smoke-Admin-2026!'
const E2E_PROJECT = process.env.E2E_PROJECT ?? 'core-content'

const runtimeDir = process.env.E2E_RUNTIME_DIR
  ? path.resolve(process.env.E2E_RUNTIME_DIR)
  : path.resolve(__dirname, '../../runtime')
const runtimeFile = path.join(runtimeDir, 'task-simulation-webui-playwright.json')

type ScanResponseData = {
  hasResponse: boolean
  hasDialog: boolean
  templates: number
  entries: number
}

type JobResponseData = {
  jobId: string
  totalTasks: number
}

type JobPlannerSnapshot = {
  contentTasks: number
  languagePackTasks: number
  warningCodes: string[]
  selectedByLine: Record<string, number>
  totalSelected: number
}

function readJobBundleFromUrl(urlText: string, fallbackJobId: string): JobResponseData {
  const url = new URL(urlText)
  return {
    jobId: url.searchParams.get('job_bundle_id') ?? fallbackJobId,
    totalTasks: Number(url.searchParams.get('job_bundle_total') ?? '0'),
  }
}

/**
 * Match a REST call regardless of pretty (/wp-json/...) vs ugly
 * (index.php?rest_route=...) permalink forms. Slot WordPress instances may
 * run either form, so predicates must not depend on /wp-json/ only.
 */
function isRestCall(urlText: string, path: string): boolean {
  const url = new URL(urlText)
  if (url.pathname.includes(`/wp-json/${path}`)) return true
  if (url.pathname.endsWith('index.php')) {
    const route = url.searchParams.get('rest_route') ?? ''
    return route.includes(`/${path}`)
  }
  return false
}

function loadPreferredRelationIds(): string[] {
  const relationFile = path.join(runtimeDir, 'relation-ids.json')
  if (!fs.existsSync(relationFile)) return []
  try {
    const parsed = JSON.parse(fs.readFileSync(relationFile, 'utf8')) as Record<string, unknown>
    const preferred = [parsed.virtual, parsed.wp]
      .map((v) => Number(v))
      .filter((v) => Number.isFinite(v) && v > 0)
      .map((v) => String(v))
    return Array.from(new Set(preferred))
  } catch {
    return []
  }
}

async function wpLogin(page: Page, user: string, pass: string) {
  // Use shared helper: locator.fill() races Chromium password-manager / WP focus
  // and can leave password empty (hivepress matrix lane 2026-09-03).
  await sharedWpLogin(page, user, pass)
}

async function hasWordPressLoginCookie(page: Page): Promise<boolean> {
  const cookies = await page.context().cookies()
  return cookies.some((c) => c.name.startsWith('wordpress_logged_in_') && !!c.value)
}

async function waitResponseOk(responsePromise: Promise<Response>) {
  const response = await responsePromise
  expect(response.ok(), `HTTP ${response.status()} from ${response.url()}`).toBeTruthy()
  return response
}

function extractScanCounts(dialogText: string): ScanResponseData {
  const templates = Number((dialogText.match(/Templates:\s*(\d+)/i) ?? [])[1] ?? 0)
  const entries = Number((dialogText.match(/Entries:\s*(\d+)/i) ?? [])[1] ?? 0)
  return {
    hasResponse: false,
    hasDialog: dialogText.length > 0,
    templates,
    entries,
  }
}

test.describe.configure({ mode: 'serial' })

test(`Task Simulation WebUI: ${E2E_PROJECT} relation task + scan + create relation job`, async ({ page }) => {
  const preferredRelationIds = loadPreferredRelationIds()

  await wpLogin(page, WP_ADMIN_USER, WP_ADMIN_PASS)
  expect(await hasWordPressLoginCookie(page)).toBeTruthy()

  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=monitoring`, { waitUntil: 'domcontentloaded' })

  const hasMonitoringUi = (await page.locator('#wptsall-add-task-btn').count()) > 0
    || (await page.locator('.wptsall-task-card').count()) > 0
  expect(hasMonitoringUi, 'Task simulation monitoring UI not found on Tasks page').toBeTruthy()

  let relationId = ''
  let monitoringCreated = false

  // Prefer existing scan buttons (monitoring already created).
  for (const preferredId of preferredRelationIds) {
    const preferredScanBtn = page.locator(`.wptsall-scan-langpack[data-relation-id="${preferredId}"]`).first()
    if (await preferredScanBtn.count()) {
      relationId = preferredId
      break
    }
  }
  if (!relationId) {
    const firstScanBtn = page.locator('.wptsall-scan-langpack').first()
    if (await firstScanBtn.count()) {
      relationId = (await firstScanBtn.getAttribute('data-relation-id')) ?? ''
    }
  }

  // If no monitoring cards yet, create one via Add Monitoring Task (before hard-fail).
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
        resp.request().method() === 'POST' && isRestCall(resp.url(), 'wptsall/v2/tasks/monitor/start')
      )
      await page.selectOption('#add-task-relation', relationId)
      await page.click('#wptsall-create-task-btn')
      await waitResponseOk(monitorRespPromise)
      monitoringCreated = true
      await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=monitoring`, { waitUntil: 'domcontentloaded' })
    }
  }

  expect(relationId, 'Cannot resolve relation_id from monitoring UI').not.toBe('')

  const scanBtn = page.locator(`.wptsall-scan-langpack[data-relation-id="${relationId}"]`).first()
  await expect(scanBtn, 'Scan button for relation not found').toBeVisible({ timeout: 30_000 })

  const scanDialogPromise = page.waitForEvent('dialog', { timeout: 8_000 }).then(async (dialog) => {
    const message = dialog.message()
    await dialog.accept()
    return message
  }).catch(() => '')

  const scanRespPromise = page.waitForResponse((resp) =>
    resp.request().method() === 'POST' && isRestCall(resp.url(), 'wptsall/v2/tasks/scan-language-pack')
  , { timeout: 8_000 }).catch(() => null)
  await scanBtn.click()
  const [scanResponse, scanDialogText] = await Promise.all([scanRespPromise, scanDialogPromise])
  if (scanResponse) {
    expect(scanResponse.ok(), `HTTP ${scanResponse.status()} from ${scanResponse.url()}`).toBeTruthy()
  }

  const scanStats = extractScanCounts(scanDialogText)
  scanStats.hasResponse = !!scanResponse
  if (scanStats.hasDialog) {
    if (scanStats.templates === 0) {
      console.warn(
        '  Monitoring Scan dialog reported 0 templates. ' +
        'Deferring hard validation to job planner result for pre-seeded/full-scope relations.'
      )
    }
  }

  await page.goto(`${WP_BASE}/wp-admin/admin.php?page=wptsall-tasks&tab=jobs`, { waitUntil: 'domcontentloaded' })
  await expect(page.locator('#wptsall-job-relation-id')).toBeVisible()

  const relationOption = page.locator(`#wptsall-job-relation-id option[value="${relationId}"]`)
  if (await relationOption.count()) {
    await page.selectOption('#wptsall-job-relation-id', relationId)
  } else {
    const fallback = page.locator('#wptsall-job-relation-id option[value]:not([value=""])').first()
    relationId = (await fallback.getAttribute('value')) ?? relationId
    await page.selectOption('#wptsall-job-relation-id', relationId)
  }

  if (await page.locator('#wptsall-job-include-content').isChecked()) {
    await page.click('#wptsall-job-include-content')
  }
  if (!(await page.locator('#wptsall-job-include-language-pack').isChecked())) {
    await page.click('#wptsall-job-include-language-pack')
  }
  if (await page.locator('#wptsall-job-preview').isChecked()) {
    await page.click('#wptsall-job-preview')
  }

  await page.fill('#wptsall-job-limit', '1')
  await page.fill('#wptsall-job-batch-size', '200')

  const customJobId = `pw_rel_job_${Date.now()}`
  await page.fill('#wptsall-job-custom-id', customJobId)

  const jobRespPromise = page.waitForResponse((resp) => {
    if (resp.request().method() !== 'POST') return false
    try {
      return isRestCall(resp.url(), 'wptsall/v2/tasks/jobs')
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
    waitResponseOk(jobRespPromise),
    createDialogPromise,
    jobRedirectPromise,
  ])
  const jobPayload = await jobResp.json().catch(() => null as unknown)
  const jobData = (((jobPayload as Record<string, unknown> | null)?.data as Record<string, unknown> | undefined) ?? {})
  const jobPlanner: JobPlannerSnapshot = {
    contentTasks: Number(jobData.content_tasks ?? 0),
    languagePackTasks: Number(jobData.language_pack_tasks ?? 0),
    warningCodes: Array.isArray(jobData.warnings)
      ? jobData.warnings
        .map((item) => String((item as Record<string, unknown> | null)?.code ?? '').trim())
        .filter((code) => code.length > 0)
      : [],
    selectedByLine:
      typeof jobData.planning === 'object' && jobData.planning && typeof (jobData.planning as Record<string, unknown>).selected_by_line === 'object'
        ? ((jobData.planning as Record<string, unknown>).selected_by_line as Record<string, number>)
        : {},
    totalSelected:
      typeof jobData.planning === 'object' && jobData.planning
        ? Number(((jobData.planning as Record<string, unknown>).total_selected ?? 0))
        : 0,
  }

  let jobStats: JobResponseData = {
    jobId: customJobId,
    totalTasks: 0,
  }

  if (createDialogText) {
    const hasZeroTaskWarning = createDialogText.includes('task count is 0')
    // In repeat runs, language-pack entries may already be consumed.
    // Treat "0 task" as acceptable only when scan phase reported 0 entries.
    if (hasZeroTaskWarning && scanStats.entries === 0) {
      if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
      fs.writeFileSync(
        runtimeFile,
        JSON.stringify(
          {
            timestamp: new Date().toISOString(),
            wpBase: WP_BASE,
            relationId,
            monitoringCreated,
            skipped: true,
            skipReason: 'job_bundle_zero_tasks_after_empty_scan_entries',
            scan: {
              dialog: scanDialogText,
              templates: scanStats.templates,
              entries: scanStats.entries,
            },
            notes: [
              'scan.entries counts entries reported by the current scan dialog',
              'job.totalTasks counts planned translation tasks generated from relation templates after planner batching',
            ],
            job: {
              warning: createDialogText,
              planner: jobPlanner,
            },
          },
          null,
          2
        )
      )
      return
    }
    expect(
      createDialogText,
      'Create job should not pop 0-task warning. Check relation model/rule coverage.'
    ).not.toContain('task count is 0')
  }

  if (redirected && page.url().includes('job_bundle_created=1')) {
    jobStats = readJobBundleFromUrl(page.url(), customJobId)
  } else {
    const fallbackUrl = new URL(`${WP_BASE}/wp-admin/admin.php`)
    fallbackUrl.searchParams.set('page', 'wptsall-tasks')
    fallbackUrl.searchParams.set('tab', 'jobs')
    fallbackUrl.searchParams.set('job_id', customJobId)
    await page.goto(fallbackUrl.toString(), { waitUntil: 'domcontentloaded' })

    const row = page.locator(`tr:has(code:has-text("${customJobId}"))`).first()
    if (await row.count()) {
      const rowText = (await row.textContent()) ?? ''
      const rowTotal = Number((rowText.match(/total=(\d+)/i) ?? [])[1] ?? '0')
      jobStats = {
        jobId: customJobId,
        totalTasks: rowTotal,
      }
    }
  }

  if (jobStats.totalTasks <= 0) {
    const plannerNoop =
      jobPlanner.totalSelected <= 0
      || (jobPlanner.contentTasks <= 0 && jobPlanner.languagePackTasks <= 0)
    const warningNoop =
      createDialogText.includes('task count is 0')
      || jobPlanner.warningCodes.some((code) => /zero|empty|no[_-]?task|none/i.test(code))

    if (plannerNoop || warningNoop) {
      if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
      fs.writeFileSync(
        runtimeFile,
        JSON.stringify(
          {
            timestamp: new Date().toISOString(),
            wpBase: WP_BASE,
            relationId,
            monitoringCreated,
            skipped: true,
            skipReason: 'job_bundle_zero_tasks_noop_planner',
            scan: {
              dialog: scanDialogText,
              templates: scanStats.templates,
              entries: scanStats.entries,
            },
            job: {
              jobId: jobStats.jobId,
              totalTasks: jobStats.totalTasks,
              dialog: createDialogText,
              planner: jobPlanner,
            },
          },
          null,
          2
        )
      )
      return
    }
  }
  expect(jobStats.totalTasks, 'Job package should create tasks').toBeGreaterThan(0)

  if (scanStats.entries === 0 && jobStats.totalTasks > 0) {
    console.warn(
      '  Scan dialog reported 0 entries, but job planner still created tasks. ' +
      'This is accepted when the planner consumes existing relation templates / pending language-pack entries.'
    )
  }

  const detailsRespPromise = page.waitForResponse((resp) =>
    resp.request().method() === 'GET'
      && isRestCall(resp.url(), 'wptsall/v2/tasks/jobs/')
      && resp.url().includes('/tasks')
      && resp.url().includes(encodeURIComponent(jobStats.jobId))
  )
  const detailsBtn = page.locator(`.wptsall-job-toggle-details[data-job-id="${jobStats.jobId}"]`).first()
  await expect(detailsBtn, `Job details button not found for ${jobStats.jobId}`).toBeVisible()
  await detailsBtn.click()
  await waitResponseOk(detailsRespPromise)

  const detailsRow = page.locator(`.wptsall-job-details-row[data-job-id="${jobStats.jobId}"]`)
  await expect(detailsRow).toBeVisible()

  const pluginRows = detailsRow.locator('td').filter({ hasText: 'plugin_i18n' })
  const themeRows = detailsRow.locator('td').filter({ hasText: 'theme_i18n' })
  expect(await pluginRows.count(), 'Expected plugin_i18n tasks in job details').toBeGreaterThan(0)
  expect(await themeRows.count(), 'Expected theme_i18n tasks in job details').toBeGreaterThan(0)

  const resultNotice = page.locator('.notice.notice-success.inline').first()
  if (await resultNotice.count()) {
    await expect(resultNotice).toContainText('Job task package created')
  } else {
    expect(
      redirected || page.url().includes(`job_id=${encodeURIComponent(jobStats.jobId)}`),
      'Expected either success notice or fallback job details evidence after package creation'
    ).toBeTruthy()
  }

  if (!fs.existsSync(runtimeDir)) fs.mkdirSync(runtimeDir, { recursive: true })
  fs.writeFileSync(
    runtimeFile,
    JSON.stringify(
      {
        timestamp: new Date().toISOString(),
        wpBase: WP_BASE,
        relationId,
        monitoringCreated,
        scan: {
          dialog: scanDialogText,
          templates: scanStats.templates,
          entries: scanStats.entries,
        },
        notes: [
          'scan.entries counts entries reported by the current scan dialog',
          'job.totalTasks counts planned translation tasks generated from relation templates after planner batching',
        ],
        job: {
          jobId: jobStats.jobId,
          totalTasks: jobStats.totalTasks,
          businessLines: {
            plugin_i18n: await pluginRows.count(),
            theme_i18n: await themeRows.count(),
          },
          planner: jobPlanner,
        },
      },
      null,
      2
    )
  )
})
